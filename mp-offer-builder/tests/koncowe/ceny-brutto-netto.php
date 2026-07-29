<?php
/**
 * Test na ZYWYM WordPressie: sklep z cenami BRUTTO nie zawyza oferty o VAT.
 *
 * Uruchamianie: wp eval-file tests/koncowe/ceny-brutto-netto.php
 *
 * Audyt koncowy wykryl, ze Agent 2.2 bral z WooCommerce cene SUROWA
 * (`get_regular_price()` / `get_price()`), a Dzial 6 traktowal ja bezwarunkowo
 * jako netto i dolicza VAT. Znaczenie tej liczby zalezy jednak od ustawienia
 * sklepu „Ceny wprowadzone z podatkiem" (`woocommerce_prices_include_tax`),
 * wlaczonego w wiekszosci polskich sklepow. Skutek: produkt 123,00 brutto
 * trafial do oferty jako netto 123,00 + VAT 28,29 = 151,29 zl.
 *
 * Blad byl CICHY — wszystkie bramki jakosci przechodzily, bo arytmetyka jest
 * wewnetrznie spojna. Testy tez przechodzily, bo srodowisko testowe ma ceny
 * netto. Dlatego ten test PRZESTAWIA ustawienie sklepu i sprawdza obie
 * konfiguracje, a na koncu przywraca stan poczatkowy.
 *
 * Sprawdzana wlasnosc jest prosta i niezalezna od stawki VAT: gdy sklep podaje
 * ceny brutto, kwota BRUTTO na ofercie musi sie zgadzac z cena ze sklepu.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_c'] = array(
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
function mc_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_c']['pass'];
		$GLOBALS['mp_c']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_c']['fail'];
	$GLOBALS['mp_c']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik i PRZYWRACA ustawienie sklepu takze po bledzie krytycznym.
 *
 * Test, ktory przestawia konfiguracje WooCommerce, nie ma prawa zostawic jej
 * zmienionej — kolejne zestawy liczylyby wtedy na innym sklepie niz zakladaja.
 *
 * @return void
 */
function mc_dump() {
	if ( isset( $GLOBALS['mp_c_stan_poczatkowy'] ) ) {
		update_option( 'woocommerce_prices_include_tax', $GLOBALS['mp_c_stan_poczatkowy'] );
		unset( $GLOBALS['mp_c_stan_poczatkowy'] );
	}

	if ( empty( $GLOBALS['mp_c']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_c'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	if ( is_dir( '/scr' ) ) {
		file_put_contents( '/scr/mp-ceny-brutto.txt', $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	$GLOBALS['mp_c']['lines'] = array();
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
register_shutdown_function( 'mc_dump' );

global $wpdb;

if ( ! function_exists( 'wc_get_product' ) ) {
	mc_ok( false, 'WooCommerce aktywne (test bez niego nie ma sensu)' );
	mc_dump();
	return;
}

$GLOBALS['mp_c_stan_poczatkowy'] = get_option( 'woocommerce_prices_include_tax' );

$handlowiec = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_set_current_user( $handlowiec );
$konto = new WP_User( $handlowiec );
$konto->add_cap( MP_OB_D1_Agent_Permission::CAPABILITY );

$produkty = wc_get_products(
	array(
		'limit'  => 1,
		'status' => 'publish',
		'return' => 'ids',
	)
);

if ( empty( $produkty ) ) {
	mc_ok( false, 'w sklepie jest przynajmniej jeden produkt' );
	mc_dump();
	return;
}

$produkt_id = (int) $produkty[0];
$produkt    = wc_get_product( $produkt_id );
$cena_sklep = (float) $produkt->get_price();

mc_ok( $cena_sklep > 0, 'produkt testowy ma cene', $produkt->get_name() . ' = ' . $cena_sklep );

/**
 * Buduje oferte na jedna sztuke produktu i zwraca jej wiersz z BD-2.
 *
 * @param int $produkt_id Identyfikator produktu.
 * @return array|null
 */
function mc_oferta( $produkt_id ) {
	global $wpdb;

	$ctx = new MP_OB_Context(
		array(
			'offer_id'   => null,
			'client'     => array(
				'name'    => 'Test Cen Brutto Sp. z o.o.',
				'email'   => 'ceny' . wp_generate_password( 6, false ) . '@example.test',
				'nip'     => '5252248481',
				'country' => 'PL',
			),
			'items'      => array(
				array(
					'product_id' => $produkt_id,
					'qty'        => 1,
				),
			),
			'wariant'    => 'standard',
			'lang'       => 'pl',
			'request_id' => wp_generate_uuid4(),
		)
	);

	$wynik = MP_OB_Pipeline_Factory::make()->run( $ctx );

	if ( ! $wynik->is_ok() ) {
		return array( 'blad' => $wynik->get_code() . ': ' . implode( '; ', (array) $wynik->get_errors() ) );
	}

	$id = (int) $ctx->get( 'offer_id', 0 );

	return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( 'SELECT id, net_grosze, vat_grosze, gross_grosze FROM ' . MP_Offer_Builder_DB::offers_table() . ' WHERE id = %d', $id ),
		ARRAY_A
	);
}

$GLOBALS['mp_c']['lines'][] = '=== A. SKLEP Z CENAMI NETTO (konfiguracja wyjsciowa) ===';

update_option( 'woocommerce_prices_include_tax', 'no' );
mc_ok( ! wc_prices_include_tax(), 'sklep zglasza ceny NETTO' );

$netto = mc_oferta( $produkt_id );

if ( isset( $netto['blad'] ) ) {
	mc_ok( false, 'A: oferta powstala', (string) $netto['blad'] );
} else {
	$a_net   = (int) $netto['net_grosze'];
	$a_gross = (int) $netto['gross_grosze'];
	$GLOBALS['mp_c']['lines'][] = sprintf( '    netto %d gr, VAT %d gr, brutto %d gr', $a_net, (int) $netto['vat_grosze'], $a_gross );

	mc_ok(
		abs( $a_net - (int) round( $cena_sklep * 100 ) ) <= 1,
		'A1: cena ze sklepu trafila do oferty jako NETTO',
		$a_net . ' vs ' . (int) round( $cena_sklep * 100 )
	);
	mc_ok( $a_gross > $a_net, 'A2: brutto wieksze od netto (VAT doliczony)', $a_gross . ' > ' . $a_net );
}

$GLOBALS['mp_c']['lines'][] = '';
$GLOBALS['mp_c']['lines'][] = '=== B. SKLEP Z CENAMI BRUTTO (typowa konfiguracja PL) ===';

update_option( 'woocommerce_prices_include_tax', 'yes' );
mc_ok( wc_prices_include_tax(), 'sklep zglasza ceny BRUTTO' );

$brutto = mc_oferta( $produkt_id );

if ( isset( $brutto['blad'] ) ) {
	mc_ok( false, 'B: oferta powstala', (string) $brutto['blad'] );
} else {
	$b_net   = (int) $brutto['net_grosze'];
	$b_vat   = (int) $brutto['vat_grosze'];
	$b_gross = (int) $brutto['gross_grosze'];
	$oczek   = (int) round( $cena_sklep * 100 );
	$GLOBALS['mp_c']['lines'][] = sprintf( '    netto %d gr, VAT %d gr, brutto %d gr (cena sklepowa %d gr)', $b_net, $b_vat, $b_gross, $oczek );

	// To jest sedno: klient placi tyle, ile widzi w sklepie.
	mc_ok(
		abs( $b_gross - $oczek ) <= 1,
		'B1: BRUTTO na ofercie zgadza sie z cena ze sklepu (bez drugiego VAT-u)',
		$b_gross . ' vs ' . $oczek
	);
	mc_ok( $b_net < $oczek, 'B2: netto jest MNIEJSZE od ceny brutto ze sklepu', $b_net . ' < ' . $oczek );
	mc_ok( $b_net + $b_vat === $b_gross, 'B3: netto + VAT = brutto (arytmetyka groszowa spojna)', $b_net . ' + ' . $b_vat . ' = ' . $b_gross );

	// Kontrola wprost na dawnym bledzie: 123,00 brutto liczone jak netto dawalo
	// 151,29 zl. Tu: brutto NIE MOZE byc rowne cenie sklepowej powiekszonej o VAT.
	mc_ok(
		$b_gross < $oczek + $b_vat,
		'B4: nie doliczono VAT-u po raz drugi',
		'brutto ' . $b_gross . ', bledny wynik bylby >= ' . ( $oczek + $b_vat )
	);
}

$GLOBALS['mp_c']['lines'][] = '';
$GLOBALS['mp_c']['lines'][] = '=== C. PRZYWROCENIE USTAWIENIA ===';

update_option( 'woocommerce_prices_include_tax', $GLOBALS['mp_c_stan_poczatkowy'] );
mc_ok(
	get_option( 'woocommerce_prices_include_tax' ) === $GLOBALS['mp_c_stan_poczatkowy'],
	'C1: ustawienie sklepu przywrocone',
	var_export( get_option( 'woocommerce_prices_include_tax' ), true )
);

mc_dump();
