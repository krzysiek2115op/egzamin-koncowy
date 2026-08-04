<?php
/**
 * Wpis w starym kształcie cache VIES udawał rozstrzygnięty werdykt.
 *
 * Uruchamianie: wp eval-file tests/naprawy/stary-cache-vies-nie-rozstrzyga.php
 *
 * Wcześniejsza wersja zapisywała do transienta SKALAR: `1` albo `0`. Zero szło
 * tam nie tylko dla numeru naprawdę nieważnego, ale też dla odpowiedzi HTTP 200
 * bez pola `isValid` — czyli dla „nie dało się ustalić". Komentarz przy
 * `resolve_vies()` mówi to wprost, dlatego ścieżka HTTP dostała strażnika
 * `is_bool( $body['isValid'] )`.
 *
 * Odczyt cache tej poprawki nie dostał: `z_cache_vies()` zamieniał skalarne `0`
 * na `vat_valid = false` z `vat_checked = true`, a Krytyk 3.2 robi STOP
 * dokładnie na warunku `false === $data['vat_valid']`. Przez dobę po aktualizacji
 * (transienty żyją 24 h) legalne zgłoszenia były więc odrzucane jako
 * `vat_invalid` — dokładnie ten skutek, przed którym broniła nowa straż.
 *
 * Reguła po naprawie: wpis w starym kształcie NIE ROZSTRZYGA. Pipeline zachowuje
 * się tak, jakby cache było puste — pyta VIES jeszcze raz.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_cv'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegół przy porażce.
 * @return bool
 */
function cv_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_cv']['pass'];
		$GLOBALS['mp_cv']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_cv']['fail'];
	$GLOBALS['mp_cv']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik i sprząta transienty.
 *
 * @return void
 */
function cv_koniec() {
	if ( empty( $GLOBALS['mp_cv']['lines'] ) ) {
		return;
	}

	foreach ( (array) ( $GLOBALS['mp_cv_klucze'] ?? array() ) as $k ) {
		delete_transient( $k );
	}

	$r    = $GLOBALS['mp_cv'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_cv']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'cv_koniec' );

$GLOBALS['mp_cv_klucze'] = array();

/**
 * Uruchamia Dział 3 dla NIP-u z podłożonym wpisem w cache.
 *
 * Ścieżka asynchroniczna (domyślna) czyta cache i nie wychodzi do sieci —
 * dokładnie tam siedział błąd.
 *
 * @param string $nip  NIP.
 * @param mixed  $wpis Co podłożyć do transienta.
 * @return array Dane wyniku Działu 3.
 */
function cv_przebieg( $nip, $wpis ) {
	$klucz = MP_D3_Agent_Vat::vies_cache_key( 'PL', $nip );
	$GLOBALS['mp_cv_klucze'][] = $klucz;

	set_transient( $klucz, $wpis, HOUR_IN_SECONDS );

	$agent  = new MP_D3_Agent_Vat();
	$wynik  = $agent->run(
		new MP_Context(
			array(
				'lead_id'    => 4244,
				'request_id' => 'req-cache-vies',
				'country'    => 'PL',
				'nip'        => $nip,
			)
		)
	);

	return is_object( $wynik ) && method_exists( $wynik, 'get_data' ) ? (array) $wynik->get_data() : array();
}

/* ==================================================================== A */

$GLOBALS['mp_cv']['lines'][] = '=== A. stary ksztalt (skalar) NIE rozstrzyga ===';

$dane = cv_przebieg( '5252248481', 0 );

cv_ok(
	true !== ( $dane['vat_checked'] ?? null ) || false !== ( $dane['vat_valid'] ?? null ),
	'skalarne 0 nie daje werdyktu „nieważny"',
	'vat_valid=' . var_export( $dane['vat_valid'] ?? null, true ) . ' vat_checked=' . var_export( $dane['vat_checked'] ?? null, true )
);

$dane1 = cv_przebieg( '5252248482', 1 );

cv_ok(
	true !== ( $dane1['vat_checked'] ?? null ) || true !== ( $dane1['vat_valid'] ?? null ),
	'skalarne 1 tez nie rozstrzyga (ten sam ksztalt, to samo zaufanie)',
	'vat_valid=' . var_export( $dane1['vat_valid'] ?? null, true ) . ' vat_checked=' . var_export( $dane1['vat_checked'] ?? null, true )
);

/* ==================================================================== B */

$GLOBALS['mp_cv']['lines'][] = '';
$GLOBALS['mp_cv']['lines'][] = '=== B. kontr-asercje: nowy ksztalt dziala jak dotad ===';

$zly = cv_przebieg( '5252248483', array( 'valid' => 0 ) );

cv_ok(
	false === ( $zly['vat_valid'] ?? null ) && true === ( $zly['vat_checked'] ?? null ),
	'nowy ksztalt z valid=0 NADAL daje twardy werdykt „niewazny"',
	'vat_valid=' . var_export( $zly['vat_valid'] ?? null, true ) . ' vat_checked=' . var_export( $zly['vat_checked'] ?? null, true )
);

$dobry = cv_przebieg( '5252248484', array( 'valid' => 1, 'name' => 'Firma Testowa' ) );

cv_ok(
	true === ( $dobry['vat_valid'] ?? null ) && true === ( $dobry['vat_checked'] ?? null ),
	'nowy ksztalt z valid=1 nadal potwierdza numer'
);
cv_ok(
	'Firma Testowa' === ( $dobry['vat_name'] ?? null ),
	'i nadal oddaje nazwe firmy z cache'
);
cv_ok(
	'cache' === ( $dobry['vat_source'] ?? null ),
	'ze zrodlem „cache", zeby bylo wiadomo, skad werdykt'
);
