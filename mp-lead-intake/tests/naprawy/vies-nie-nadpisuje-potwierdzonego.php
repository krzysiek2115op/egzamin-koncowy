<?php
/**
 * P1-G4 — weryfikator w tle kasowal POTWIERDZONY status VAT, gdy VIES milczal.
 *
 * Uruchamianie: wp eval-file tests/naprawy/vies-nie-nadpisuje-potwierdzonego.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-G4  Brak fallbacku dla VIES w weryfikatorze w tle
 *
 * Dla Bialej listy fallback istnial (i ma wlasny komentarz o „cichej, trwalej
 * utracie +20 pkt"), dla VIES nie — mimo ze problem jest identyczny. Gdy
 * zgloszenie trafilo na cache-hit VIES, lead szedl do bazy z `vat_valid = 1`
 * i +30 pkt. Zadanie w tle wykonywalo sie pozniej, gdy transientu juz nie bylo,
 * a VIES akurat nie odpowiadal — i `null` nadpisywal ta jedynke.
 *
 * Skutek: scoring spada o 30, `vat_checked_at` na NULL, po 5 probach status
 * `unknown`. Wtyczka 2 nigdy nie zobaczy `valid`, wiec odwrotne obciazenie
 * (dyrektywa 2006/112/WE art. 196) staje sie nieosiagalne — dokladnie ta
 * szkoda, przed ktora mial chronic mechanizm F2.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_vn'] = array( 'pass' => 0, 'fail' => 0, 'lines' => array() );

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegol.
 * @return bool
 */
function vn_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_vn']['pass'];
		$GLOBALS['mp_vn']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}
	++$GLOBALS['mp_vn']['fail'];
	$GLOBALS['mp_vn']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

$GLOBALS['mp_vn']['lines'][] = '=== A. VIES nie odpowiedzial ===';

vn_ok( method_exists( 'MP_Lead_Intake_Vat_Verifier', 'scal_vat_valid' ), 'istnieje scalanie wyniku VIES' );

if ( method_exists( 'MP_Lead_Intake_Vat_Verifier', 'scal_vat_valid' ) ) {
	$brak = array( 'vat_valid' => null, 'vat_checked' => false );

	// SEDNO: potwierdzona jedynka ma przezyc nieudana probe.
	vn_ok( true === MP_Lead_Intake_Vat_Verifier::scal_vat_valid( $brak, '1' ), 'potwierdzone TAK przezywa brak odpowiedzi' );
	vn_ok( false === MP_Lead_Intake_Vat_Verifier::scal_vat_valid( $brak, '0' ), 'ustalone NIE tez zostaje' );
	vn_ok( null === MP_Lead_Intake_Vat_Verifier::scal_vat_valid( $brak, null ), 'gdy nic nie bylo wiadomo, zostaje niewiadoma' );

	$GLOBALS['mp_vn']['lines'][] = '';
	$GLOBALS['mp_vn']['lines'][] = '=== B. VIES odpowiedzial (kontr-asercje) ===';

	/*
	 * Bez tych asercji „naprawa" mogla by polegac na trzymaniu starej wartosci
	 * ZAWSZE — a wtedy weryfikacja w tle przestalaby cokolwiek zmieniac i numer
	 * uniewazniony przez VIES zostalby na zawsze wazny. To blad powazniejszy.
	 */
	vn_ok( false === MP_Lead_Intake_Vat_Verifier::scal_vat_valid( array( 'vat_valid' => false, 'vat_checked' => true ), '1' ), 'swieze NIE nadpisuje stare TAK' );
	vn_ok( true === MP_Lead_Intake_Vat_Verifier::scal_vat_valid( array( 'vat_valid' => true, 'vat_checked' => true ), '0' ), 'swieze TAK nadpisuje stare NIE' );
	vn_ok( true === MP_Lead_Intake_Vat_Verifier::scal_vat_valid( array( 'vat_valid' => true, 'vat_checked' => true ), null ), 'swiezy wynik dziala przy pustym ledzie' );

	$GLOBALS['mp_vn']['lines'][] = '';
	$GLOBALS['mp_vn']['lines'][] = '=== C. typ zgodny ze scoringiem ===';

	// Scoring porownuje SCISLE `true === $vat_valid`, wiec '1' z bazy nie moze
	// wyciec dalej jako string — inaczej +30 pkt przepada mimo poprawnej wartosci.
	$w = MP_Lead_Intake_Vat_Verifier::scal_vat_valid( $brak, '1' );
	vn_ok( is_bool( $w ), 'wynik jest typu bool, nie stringiem z bazy', gettype( $w ) );
}

$GLOBALS['mp_vn']['lines'][] = '';
$GLOBALS['mp_vn']['lines'][] = '=== D. weryfikator faktycznie tego uzywa ===';

$zrodlo = file_get_contents( dirname( dirname( __DIR__ ) ) . '/includes/class-mp-vat-verifier.php' );
vn_ok( is_string( $zrodlo ) && false !== strpos( $zrodlo, 'self::scal_vat_valid(' ), 'weryfikator wola scalanie zamiast czytac wprost z odpowiedzi' );

echo implode( "\n", $GLOBALS['mp_vn']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_vn']['pass'], $GLOBALS['mp_vn']['fail'] );
echo ( 0 === $GLOBALS['mp_vn']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
