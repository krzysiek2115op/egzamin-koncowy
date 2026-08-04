<?php
/**
 * Jedno ustalenie audytu z pluginu 1 i jeden niezmiennik, który audyt wziął
 * za ustalenie.
 *
 * Uruchamianie: wp eval-file tests/naprawy/nazwa-z-vies-i-miejsce-awarii.php
 *
 * A. NAZWA-ZAŚLEPKA — BŁĄD, NAPRAWIONY. Rejestr VIES nie zawsze ujawnia nazwę
 *    firmy: państwa członkowskie mają do tego prawo i wtedy usługa oddaje
 *    w polu `name` łańcuch „---". Kod brał go dosłownie — zapisywał do
 *    kontekstu jako `vat_name` i do pamięci podręcznej na dobę. „---" nie jest
 *    nazwą firmy, tylko zapisem braku danych, a docblock tuż obok obiecuje
 *    wprost, że przy braku nazwy zostaje `null`, czyli „nie wiemy", zamiast
 *    wartości zmyślonej. Obietnica była dotrzymywana dla pola NIEOBECNEGO,
 *    a łamana dla pola obecnego i pustego w treści.
 *
 * B. MIEJSCE AWARII — NIE BŁĄD. Audyt zgłosił, że `department_label()` dokleja
 *    plik i linię wyłącznie przy nieustalonym dziale, powołując się na to, że
 *    wyciszanie alarmów liczy się z pliku i linii. Kod mówi co innego:
 *    `log_exception()` bierze klucz ogranicznika z NUMERU DZIAŁU, gdy dział
 *    jest znany, i z pliku z linią dopiero wtedy, gdy nie jest. Opis o większej
 *    szczegółowości niż kubełek byłby więc szkodliwy — dwie różne treści
 *    trafiałyby do jednego kubełka i druga wiadomość znikałaby bez śladu,
 *    a czytelnik miałby podstawy sądzić, że przyszła.
 *
 *    Zmiana została wprowadzona, po czym padła na kontr-asercji I6 w
 *    `alarm-mowi-prawde.php`, która tego niezmiennika pilnowała od poprzedniej
 *    rundy. Cofnięta; sekcja D pilnuje teraz równowagi w obie strony, a powód
 *    jest zapisany w kodzie, żeby następny audyt nie zgłosił tego trzeci raz.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_nv'] = array(
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
function nv_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_nv']['pass'];
		$GLOBALS['mp_nv']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_nv']['fail'];
	$GLOBALS['mp_nv']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik i sprząta transienty.
 *
 * @return void
 */
function nv_koniec() {
	if ( empty( $GLOBALS['mp_nv']['lines'] ) ) {
		return;
	}

	foreach ( (array) ( $GLOBALS['mp_nv_klucze'] ?? array() ) as $k ) {
		delete_transient( $k );
	}

	$r    = $GLOBALS['mp_nv'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_nv']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'nv_koniec' );

$GLOBALS['mp_nv_klucze'] = array();

/* ==================================================================== A */

$GLOBALS['mp_nv']['lines'][] = '=== A. „---" z VIES to brak nazwy, nie nazwa ===';

nv_ok(
	method_exists( 'MP_D3_Agent_Vat', 'nazwa_firmy' ),
	'jest jedno miejsce sprowadzajace nazwe z VIES do postaci uzytecznej'
);

if ( ! method_exists( 'MP_D3_Agent_Vat', 'nazwa_firmy' ) ) {
	return;
}

$nv_zaslepki = array( '---', ' --- ', '', '   ', '-', '--' );

foreach ( $nv_zaslepki as $nv_z ) {
	nv_ok(
		null === MP_D3_Agent_Vat::nazwa_firmy( $nv_z ),
		'„' . $nv_z . '" nie jest nazwa firmy',
		var_export( MP_D3_Agent_Vat::nazwa_firmy( $nv_z ), true )
	);
}

nv_ok(
	null === MP_D3_Agent_Vat::nazwa_firmy( null ),
	'brak pola tez daje „nie wiemy"'
);

/* ==================================================================== B */

$GLOBALS['mp_nv']['lines'][] = '';
$GLOBALS['mp_nv']['lines'][] = '=== B. kontr-asercje: prawdziwa nazwa przechodzi bez zmian ===';

nv_ok(
	'FIRMA TESTOWA SP. Z O.O.' === MP_D3_Agent_Vat::nazwa_firmy( 'FIRMA TESTOWA SP. Z O.O.' ),
	'nazwa firmy zostaje nazwa firmy'
);
nv_ok(
	'Firma A' === MP_D3_Agent_Vat::nazwa_firmy( '  Firma A  ' ),
	'i traci tylko biale znaki z brzegow'
);
nv_ok(
	'A---B' === MP_D3_Agent_Vat::nazwa_firmy( 'A---B' ),
	'myslniki W SRODKU nazwy nie robia z niej zaslepki'
);

/* ==================================================================== C */

$GLOBALS['mp_nv']['lines'][] = '';
$GLOBALS['mp_nv']['lines'][] = '=== C. zaslepka nie dociera z cache do wyniku dzialu ===';

$nv_klucz = MP_D3_Agent_Vat::vies_cache_key( 'DE', '811111111' );
$GLOBALS['mp_nv_klucze'][] = $nv_klucz;

set_transient( $nv_klucz, array( 'valid' => 1, 'name' => '---' ), HOUR_IN_SECONDS );

$nv_agent = new MP_D3_Agent_Vat();
$nv_wynik = $nv_agent->run(
	new MP_Context(
		array(
			'lead_id'    => 4245,
			'request_id' => 'req-nazwa-vies',
			'country'    => 'DE',
			'nip'        => '811111111',
		)
	)
);
$nv_dane = is_object( $nv_wynik ) && method_exists( $nv_wynik, 'get_data' ) ? (array) $nv_wynik->get_data() : array();

nv_ok(
	! array_key_exists( 'vat_name', $nv_dane ) || null === $nv_dane['vat_name'],
	'wynik dzialu nie niesie „---" jako nazwy firmy',
	'vat_name=' . var_export( $nv_dane['vat_name'] ?? null, true )
);
nv_ok(
	true === ( $nv_dane['vat_valid'] ?? null ),
	'kontr-asercja: sam werdykt o waznosci numeru dziala jak dotad'
);

/* ==================================================================== D */

$GLOBALS['mp_nv']['lines'][] = '';
$GLOBALS['mp_nv']['lines'][] = '=== D. opis miejsca ma ziarnistosc kubelka wyciszania ===';

/*
 * Audyt zglosil jako blad, ze `department_label()` dokleja plik i linie WYLACZNIE
 * przy nieustalonym dziale. Sprawdzenie kodu pokazalo, ze to nie jest blad, tylko
 * niezmiennik: `log_exception()` liczy klucz ogranicznika jako
 * `mp_notify_exception_<numer dzialu>`, gdy dzial jest znany, i
 * `mp_notify_exception_<plik:linia>`, gdy nie jest. Opis MUSI byc tak samo
 * szczegolowy jak kubelek — inaczej dwa rozne opisy trafiaja do jednego kubelka
 * i drugi alarm znika bez sladu, a czytelnik ma podstawy sadzic, ze przyszedl.
 *
 * Ta sekcja pilnuje wlasnie tej rownowagi, w obie strony.
 */
$nv_klasa  = new ReflectionClass( 'MP_Pipeline_Logger' );
$nv_metoda = $nv_klasa->getMethod( 'department_label' );
$nv_metoda->setAccessible( true );
$nv_logger = new MP_Pipeline_Logger();

$nv_znany_a = (string) $nv_metoda->invoke( $nv_logger, 7, 'class-mp-department-07.php:123' );
$nv_znany_b = (string) $nv_metoda->invoke( $nv_logger, 7, 'class-mp-department-07.php:456' );
$nv_nieznany_a = (string) $nv_metoda->invoke( $nv_logger, 0, 'class-mp-ajax.php:45' );
$nv_nieznany_b = (string) $nv_metoda->invoke( $nv_logger, 0, 'class-mp-form.php:12' );

nv_ok(
	$nv_znany_a === $nv_znany_b,
	'znany dzial: dwa pochodzenia daja TEN SAM opis, bo kubelek jest jeden',
	$nv_znany_a . ' vs ' . $nv_znany_b
);
nv_ok(
	false !== strpos( $nv_znany_a, '7' ) && false === strpos( $nv_znany_a, 'class-mp-department-07.php' ),
	'i opis konczy sie na numerze dzialu',
	$nv_znany_a
);
nv_ok(
	$nv_nieznany_a !== $nv_nieznany_b,
	'nieustalony dzial: dwa pochodzenia daja ROZNE opisy, bo kubelki sa dwa',
	$nv_nieznany_a . ' vs ' . $nv_nieznany_b
);
nv_ok(
	false !== strpos( $nv_nieznany_a, 'class-mp-ajax.php:45' ) && false === strpos( $nv_nieznany_a, 'dziale 0' ),
	'kontr-asercja: przy nieustalonym dziale nadal nie ma „dzialu 0"',
	$nv_nieznany_a
);
nv_ok(
	'' !== (string) $nv_metoda->invoke( $nv_logger, 0, '' ),
	'kontr-asercja: bez dzialu i bez pochodzenia opis nadal cos mowi',
	(string) $nv_metoda->invoke( $nv_logger, 0, '' )
);
