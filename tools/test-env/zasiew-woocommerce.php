<?php
/**
 * Zasiew sklepu dla testów: ustawienia, stawka VAT i produkty.
 *
 * Uruchamianie: wp eval-file tools/test-env/zasiew-woocommerce.php
 *
 * Testy wtyczki 2 liczą ceny i podatki, więc potrzebują sklepu, który ma co
 * liczyć: kraj, walutę, włączone podatki, stawkę krajową i kilka produktów.
 * Środowisko lokalne dostało to raz, ręcznie, przy stawianiu — i przez to
 * CZTERY z pięciu testów cenowych wyglądały na przechodzące wyłącznie u autora.
 * Na czystej instalacji (CI) padały na „w sklepie jest przynajmniej jeden
 * produkt", bo nikt nigdy nie zapisał, czego one właściwie wymagają.
 *
 * Skrypt jest idempotentny: powtórne uruchomienie nie dubluje ani stawki, ani
 * produktów. Te same dane co w zasiewie strony pokazowej — jeden opis sklepu
 * na projekt, nie dwa rozjeżdżające się.
 *
 * @package MP_Test_Env
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

if ( ! class_exists( 'WC_Tax' ) ) {
	echo "BLAD: WooCommerce nie jest aktywne — nie ma czego zasiewac.\n";
	return;
}

$raport = array();

/* ------------------------------------------------------------------ Sklep */

update_option( 'woocommerce_default_country', 'PL:PL-MZ' );
update_option( 'woocommerce_currency', 'PLN' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_based_on', 'base' );
update_option( 'woocommerce_price_decimal_sep', ',' );
update_option( 'woocommerce_price_thousand_sep', ' ' );

$raport[] = 'sklep: kraj PL, waluta PLN, podatki wlaczone, ceny netto';

/* ---------------------------------------------------- Stawka krajowa 23% */

$stawki = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = 'PL'" ); // phpcs:ignore WordPress.DB

if ( 0 === $stawki ) {
	WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => 'PL',
			'tax_rate_state'    => '',
			'tax_rate'          => '23.0000',
			'tax_rate_name'     => 'VAT 23%',
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => '',
		)
	);

	$raport[] = 'stawka VAT PL 23% zalozona (klasa standardowa)';
} else {
	$raport[] = 'stawka VAT PL juz byla (' . $stawki . ')';
}

/* --------------------------------------------------------------- Produkty */

$katalog = array(
	array( 'Filtr przemyslowy FP-100', '100.00', 'Filtr kasetowy do instalacji odpylajacych, klasa F9.' ),
	array( 'Filtr przemyslowy FP-250', '250.50', 'Filtr workowy o zwiekszonej powierzchni czynnej, klasa M6.' ),
	array( 'Stacja filtracyjna SF-900', '999.99', 'Kompletna stacja filtracyjna z wentylatorem i sterowaniem.' ),
);

$utworzone = 0;

foreach ( $katalog as $poz ) {
	list( $nazwa, $cena, $opis ) = $poz;

	$istnieje = get_posts(
		array(
			'post_type'   => 'product',
			'post_status' => 'any',
			'title'       => $nazwa,
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);

	if ( ! empty( $istnieje ) ) {
		continue;
	}

	$produkt = new WC_Product_Simple();
	$produkt->set_name( $nazwa );
	$produkt->set_status( 'publish' );
	$produkt->set_catalog_visibility( 'visible' );
	$produkt->set_regular_price( $cena );
	$produkt->set_description( $opis );
	$produkt->set_short_description( $opis );
	$produkt->set_tax_status( 'taxable' );
	$produkt->set_tax_class( '' );
	$produkt->set_manage_stock( false );
	$produkt->set_stock_status( 'instock' );
	$produkt->save();

	++$utworzone;
}

$raport[] = 'produkty: utworzono ' . $utworzone . ' z ' . count( $katalog );

echo "=== ZASIEW SKLEPU ===\n";

foreach ( $raport as $wiersz ) {
	echo '  ' . $wiersz . "\n";
}
