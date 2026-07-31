<?php
/**
 * Ustalenie 1.25 — zabezpieczenie przed cichym 0% VAT nie moze byc warunkowe.
 *
 * Uruchamianie: wp eval-file tests/naprawy/kod-kraju-lista-iso.php
 *
 * Agent 6.1 sprawdzal, czy kod kraju istnieje, tak:
 *
 *   if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->countries )
 *        && method_exists( WC()->countries, 'get_countries' ) ) { ...kontrola... }
 *
 * Cala kontrola „nieznanego kodu kraju" (S4-02) siedziala w tym warunku.
 * Gdy warunek nie byl spelniony, literowka o poprawnym ksztalcie („DR" zamiast
 * „DE") przechodzila regex `^[A-Z]{2}$`, nie trafiala na liste UE i wpadala do
 * galezi „poza UE": tax_mechanism = out_of_scope, VAT 0%, podstawa prawna
 * „Kraj spoza UE". QA dzialu to przepuszczalo, bo netto + 0 = brutto.
 *
 * Drugi sedzia audytu argumentowal, ze Dzial 2 pada wczesniej bledem
 * 'woocommerce_unavailable', wiec do Dzialu 6 bez WooCommerce sie nie dochodzi.
 * To nie jest ten sam warunek: Dzial 2 sprawdza `class_exists('WC_Tax')`
 * i `function_exists('wc_get_product')`, a straznik w Dziale 6 wymagal
 * `WC()->countries`, ktore zapelnia sie dopiero na haku `init`. Przejscie
 * Dzialu 2 nie gwarantowalo warunku, od ktorego zalezal Dzial 6.
 *
 * Naprawa zdejmuje warunek w calosci: lista ISO 3166-1 alpha-2 jest wbudowana
 * w dzial, wiec kontrola dziala zawsze i tak samo.
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G4  Kontrola nieznanego kodu kraju zalezna od zaladowania WooCommerce
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_kk'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function kk_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_kk']['pass'];
		$GLOBALS['mp_kk']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_kk']['fail'];
	$GLOBALS['mp_kk']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function kk_dump() {
	if ( empty( $GLOBALS['mp_kk']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_kk'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_kk']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'kk_dump' );

/**
 * Uruchamia Agenta 6.1 dla podanego kraju.
 *
 * @param string $kraj Kod kraju.
 * @return MP_OB_Result
 */
function kk_mechanizm( $kraj ) {
	$kontekst = new MP_OB_Context(
		array(
			'client'    => array(
				'country'    => $kraj,
				'vat_status' => 'valid',
			),
			'tax_rates' => array( '' => array( 'rate' => 23.0 ) ),
			'tax_rate'  => 23.0,
		)
	);

	return ( new MP_OB_D6_Agent_Mechanism() )->run( $kontekst );
}

/* ==================================================================== A */

$GLOBALS['mp_kk']['lines'][] = '=== A. lista ISO jest wbudowana w dzial ===';

kk_ok( method_exists( 'MP_OB_D6_Agent_Mechanism', 'iso_countries' ), 'Dzial 6 ma wlasna liste krajow ISO' );

if ( ! method_exists( 'MP_OB_D6_Agent_Mechanism', 'iso_countries' ) ) {
	return;
}

$iso = MP_OB_D6_Agent_Mechanism::iso_countries();

kk_ok( count( $iso ) > 200, 'lista ma pelen zakres ISO 3166-1 alpha-2', 'kodow=' . count( $iso ) );
kk_ok( in_array( 'DE', $iso, true ), 'zna DE (UE)' );
kk_ok( in_array( 'US', $iso, true ), 'zna US (poza UE)' );
kk_ok( in_array( 'NO', $iso, true ), 'zna NO (poza UE, Europa)' );
kk_ok( ! in_array( 'DR', $iso, true ), 'NIE zna „DR" — to literowka, nie kraj' );

/*
 * Kontrola spojnosci: kazdy kraj UE z listy dzialu musi byc na liscie ISO.
 * Gdyby ktos dopisal do EU_COUNTRIES kod spoza ISO, kontrola odrzucalaby
 * kraj czlonkowski — czyli naprawa zamienilaby jeden blad na drugi.
 */
$brakujace = array_diff( MP_OB_D6_Agent_Mechanism::EU_COUNTRIES, $iso );

kk_ok( empty( $brakujace ), 'wszystkie kraje UE sa na liscie ISO', 'brakuje: ' . implode( ',', $brakujace ) );

/* ==================================================================== B */

$GLOBALS['mp_kk']['lines'][] = '';
$GLOBALS['mp_kk']['lines'][] = '=== B. kontrola nie zalezy od WooCommerce ===';

$zrodlo = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/departments/class-mp-ob-department-06.php' );

kk_ok(
	! preg_match( "/function_exists\(\s*'WC'\s*\)/", $zrodlo ),
	'kontrola kodu kraju nie jest juz owinieta w test obecnosci WC()'
);

/* ==================================================================== C */

$GLOBALS['mp_kk']['lines'][] = '';
$GLOBALS['mp_kk']['lines'][] = '=== C. zachowanie dla trzech rodzajow kodu ===';

$dr = kk_mechanizm( 'DR' );

kk_ok( ! $dr->is_ok(), 'literowka „DR" konczy sie bledem, nie cichym 0%', 'kod=' . $dr->get_code() );
kk_ok( 'unknown_country' === $dr->get_code(), 'i to bledem „unknown_country"', 'kod=' . $dr->get_code() );

/*
 * Kontrola przeciwna i najwazniejsza: PRAWDZIWY kraj spoza UE ma nadal
 * przechodzic do galezi „poza zakresem". Bez tej asercji naprawa mogla
 * polegac na odrzucaniu wszystkiego, co nie jest w UE.
 */
$us = kk_mechanizm( 'US' );

kk_ok( $us->is_ok(), 'prawdziwy kraj spoza UE (US) nadal przechodzi', $us->is_ok() ? '' : 'kod=' . $us->get_code() );

if ( $us->is_ok() ) {
	$dane = $us->get_data();
	kk_ok( 'out_of_scope' === $dane['tax_mechanism'], 'US dostaje mechanizm „poza zakresem"', 'mechanizm=' . $dane['tax_mechanism'] );
}

$de = kk_mechanizm( 'DE' );

kk_ok( $de->is_ok(), 'kraj UE (DE) przechodzi', $de->is_ok() ? '' : 'kod=' . $de->get_code() );

if ( $de->is_ok() ) {
	$dane = $de->get_data();
	kk_ok( 'out_of_scope' !== $dane['tax_mechanism'], 'DE NIE jest traktowany jak kraj spoza UE', 'mechanizm=' . $dane['tax_mechanism'] );
}

$pl = kk_mechanizm( 'PL' );

if ( $pl->is_ok() ) {
	$dane = $pl->get_data();
	kk_ok( 'domestic' === $dane['tax_mechanism'], 'PL nadal jest stawka krajowa', 'mechanizm=' . $dane['tax_mechanism'] );
}

$puste = kk_mechanizm( 'X' );

kk_ok( ! $puste->is_ok(), 'kod o zlym ksztalcie nadal odrzucany', 'kod=' . $puste->get_code() );
