<?php
/**
 * Ustalenie audytu: komunikat o stanie oferty przeczył sam sobie.
 *
 * Uruchamianie: wp eval-file tests/naprawy/komunikat-stanu-oferty.php
 *
 * `wrong_status_message()` wraca — zgodnie z własnym docblokiem — w DWÓCH
 * sytuacjach: gdy oferta ma stan, z którego nie wolno jej zatwierdzić, ORAZ
 * po nieudanym UPDATE, gdy w wierszu nadal stoi `draft`. Dla tego drugiego
 * przypadku nie było gałęzi, więc powstawało zdanie wewnętrznie sprzeczne:
 *
 *   „Oferta jest w stanie „draft", z którego nie da się jej zatwierdzić —
 *    zatwierdzać można wyłącznie szkice."
 *
 * Czytający dostawał informację, która sama sobie przeczy, i żadnej wskazówki.
 * A stan faktyczny był zupełnie inny niż opisywany: zapis się nie udał, bo ktoś
 * pisał do tego wiersza w tym samym czasie.
 *
 * Gałąź dla `approved` (wyścig zatwierdzeń) powstała rundę wcześniej i ten test
 * pilnuje obu naraz — razem z kontr-asercją, że stan spoza słownika nadal
 * dostaje swoje zdanie o zgłoszeniu administratorowi.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_ks'] = array(
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
function kso_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_ks']['pass'];
		$GLOBALS['mp_ks']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_ks']['fail'];
	$GLOBALS['mp_ks']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function kso_koniec() {
	if ( empty( $GLOBALS['mp_ks']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_ks'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_ks']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'kso_koniec' );

/**
 * Komunikat dla podanego stanu.
 *
 * @param string $stan Stan zapisany przy ofercie.
 * @return string
 */
function kso_komunikat( $stan ) {
	$m = new ReflectionMethod( 'MP_Offer_Builder_Approval', 'wrong_status_message' );
	$m->setAccessible( true );

	return (string) $m->invoke( null, $stan );
}

/* ==================================================================== A */

$GLOBALS['mp_ks']['lines'][] = '=== A. „szkic" nie jest opisywany jako zly stan ===';

$kso_draft = kso_komunikat( MP_Offer_Builder_DB::STATUS_DRAFT );

kso_ok(
	false === mb_stripos( $kso_draft, 'wyłącznie szkice' ),
	'A1: komunikat NIE mowi, ze zatwierdzac mozna wylacznie szkice',
	'komunikat=' . $kso_draft
);
kso_ok(
	false === mb_strpos( $kso_draft, '„draft"' ),
	'A2: i nie cytuje stanu jako powodu odmowy'
);
kso_ok(
	false !== mb_stripos( $kso_draft, 'nie doszło do skutku' ) || false !== mb_stripos( $kso_draft, 'nadal jest szkicem' ),
	'A3: mowi, co sie NAPRAWDE stalo — zapis sie nie udal',
	'komunikat=' . $kso_draft
);
kso_ok(
	false !== mb_stripos( $kso_draft, 'spróbuj ponownie' ) || false !== mb_stripos( $kso_draft, 'odśwież' ),
	'A4: i podaje rade, ktora da sie wykonac',
	'komunikat=' . $kso_draft
);
kso_ok(
	false === mb_stripos( $kso_draft, 'administrator' ),
	'A5: nie odsyla do administratora — to nie jest awaria'
);

/* ==================================================================== B */

$GLOBALS['mp_ks']['lines'][] = '';
$GLOBALS['mp_ks']['lines'][] = '=== B. kontr-asercje: pozostale stany bez zmian ===';

$kso_appr = kso_komunikat( MP_Offer_Builder_DB::STATUS_APPROVED );

kso_ok(
	false !== mb_stripos( $kso_appr, 'została już zatwierdzona' ),
	'B1: stan „zatwierdzona" nadal opisuje wyscig, a nie awarie slownika',
	'komunikat=' . $kso_appr
);
kso_ok(
	false === mb_stripos( $kso_appr, 'administrator' ),
	'B2: i tez nie odsyla do administratora'
);

$kso_obcy = kso_komunikat( 'wysłana_kurierem' );

kso_ok(
	false !== mb_stripos( $kso_obcy, 'administrator' ),
	'B3: stan SPOZA slownika nadal kieruje do administratora',
	'komunikat=' . $kso_obcy
);
kso_ok(
	false !== mb_strpos( $kso_obcy, 'wysłana_kurierem' ),
	'B4: i cytuje stan, bo to jedyna wskazowka, jaka administrator dostanie'
);

$kso_pusty = kso_komunikat( '' );

kso_ok(
	'' !== trim( $kso_pusty ) && false !== mb_stripos( $kso_pusty, 'niekompletny' ),
	'B5: pusty stan nadal opisuje niekompletny wiersz w bazie',
	'komunikat=' . $kso_pusty
);

$kso_wszystkie = array( MP_Offer_Builder_DB::STATUS_DRAFT, MP_Offer_Builder_DB::STATUS_APPROVED, 'wysłana_kurierem', '' );
$kso_rozne     = array_unique( array_map( 'kso_komunikat', $kso_wszystkie ) );

kso_ok(
	count( $kso_rozne ) === count( $kso_wszystkie ),
	'B6: kazdy z czterech przypadkow ma WLASNE zdanie',
	'roznych=' . count( $kso_rozne ) . ' z ' . count( $kso_wszystkie )
);
