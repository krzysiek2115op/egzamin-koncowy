<?php
/**
 * Audyt koncowy 1.3.8 — dwa sposoby, na ktore snapshot cen dalej zgadywal.
 *
 * Uruchamianie: wp eval-file tests/naprawy/promocja-i-podatek-sklepu.php
 *
 * A. „PROMOCJA" ROWNA CENIE REGULARNEJ. Wydanie 1.3.8 dolozylo straznika
 *    `$on_sale && ! $ma_cene`, ktory lapie wylacznie rozjazd meta z PUSTYM
 *    `_price`. Rozjazd, w ktorym `_price` ma wartosc rowna cenie regularnej
 *    (albo wyzsza), przechodzil caly dzial bez bledu — a wlasnie ten przypadek
 *    opisuje komentarz przy strazniku: „snapshot deklarowal promocje, ktorej
 *    wartosc nie jest cena promocyjna, a dokument obiecywal rabat rowny zeru".
 *    Naprawiono polowe przypadku, o ktorym mowil wlasny komentarz.
 *
 * B. CENNIK BRUTTO PRZY WYLACZONYCH PODATKACH. `wc_prices_include_tax()` to
 *    w WooCommerce koniunkcja: `wc_tax_enabled() && opcja === 'yes'`. Sklep,
 *    ktory ma ceny wprowadzone z podatkiem, ale podatki wylaczone, dostawal
 *    wiec falsz — czyli „cennik jest netto" — i ceny BRUTTO wchodzily do
 *    snapshotu jako netto. Dzial 6 doliczal do nich VAT drugi raz.
 *    To ten sam wypadek, ktory 1.3.8 zamknelo dla BRAKU funkcji odczytu:
 *    jednej wartosci nie wolno zgadywac, bo przesuwa cala oferte o stawke VAT.
 *
 * Sekcja C pilnuje tego, czego ruszac nie wolno.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_pp'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

$GLOBALS['mp_pp_produkty'] = array();

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function pp_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_pp']['pass'];
		$GLOBALS['mp_pp']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_pp']['fail'];
	$GLOBALS['mp_pp']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik i sprzata, takze po bledzie krytycznym.
 *
 * @return void
 */
function pp_koniec() {
	if ( empty( $GLOBALS['mp_pp']['lines'] ) ) {
		return;
	}

	foreach ( $GLOBALS['mp_pp_produkty'] as $id ) {
		wp_delete_post( (int) $id, true );
	}

	if ( isset( $GLOBALS['mp_pp_opcje'] ) ) {
		foreach ( $GLOBALS['mp_pp_opcje'] as $klucz => $wartosc ) {
			update_option( $klucz, $wartosc );
		}
	}

	$r    = $GLOBALS['mp_pp'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_pp']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'pp_koniec' );

/**
 * Produkt z ROZJECHANYMI meta cenowymi.
 *
 * `_sale_price` nizsze od `_regular_price` sprawia, ze `is_on_sale()` jest
 * prawda; `_price` podmieniamy niezaleznie — dokladnie tak rozjezdzaja sie te
 * pola po imporcie cennika bez synchronizacji.
 *
 * @param string $regular `_regular_price`.
 * @param string $sale    `_sale_price`.
 * @param string $price   `_price`.
 * @return int
 */
function pp_produkt( $regular, $sale, $price ) {
	$produkt = new WC_Product_Simple();
	$produkt->set_name( 'PP ' . $regular . '/' . $sale . '/' . $price );
	$produkt->set_regular_price( '1' );
	$produkt->set_status( 'publish' );
	$produkt->set_tax_status( 'taxable' );
	$produkt->set_tax_class( '' );
	$id = (int) $produkt->save();

	update_post_meta( $id, '_regular_price', $regular );
	update_post_meta( $id, '_sale_price', $sale );
	update_post_meta( $id, '_price', $price );
	wp_cache_flush();

	$GLOBALS['mp_pp_produkty'][] = $id;

	return $id;
}

/**
 * Uruchamia Agenta 2.2 na jednej pozycji.
 *
 * @param int $product_id Produkt.
 * @return MP_OB_Result
 */
function pp_ceny( $product_id ) {
	$agent = new MP_OB_D2_Agent_Prices();

	return $agent->run(
		new MP_OB_Context(
			array(
				'items' => array(
					array(
						'product_id'   => $product_id,
						'variation_id' => null,
						'qty'          => 1,
					),
				),
			)
		)
	);
}

/**
 * Czy wynik niesie blad we wskazanym polu.
 *
 * @param MP_OB_Result $wynik Wynik.
 * @param string       $pole  Nazwa pola.
 * @return bool
 */
function pp_ma_blad( $wynik, $pole ) {
	$dane = $wynik->get_data();

	foreach ( isset( $dane['errors'] ) ? $dane['errors'] : array() as $blad ) {
		if ( isset( $blad['field'] ) && $pole === $blad['field'] ) {
			return true;
		}
	}

	return false;
}

$GLOBALS['mp_pp_opcje'] = array(
	'woocommerce_calc_taxes'           => get_option( 'woocommerce_calc_taxes' ),
	'woocommerce_prices_include_tax'   => get_option( 'woocommerce_prices_include_tax' ),
);

/* ==================================================================== A */

$GLOBALS['mp_pp']['lines'][] = '=== A. „promocja" nie nizsza od ceny regularnej ===';

$rowna = pp_produkt( '100.00', '80.00', '100.00' );
$a1    = pp_ceny( $rowna );

pp_ok(
	pp_ma_blad( $a1, 'items.0.sale_price' ),
	'cena aktywna ROWNA regularnej przy on_sale to blad pozycji',
	'kod=' . ( $a1->is_ok() ? 'OK' : (string) $a1->get_code() )
);

$wyzsza = pp_produkt( '100.00', '80.00', '120.00' );
$a2     = pp_ceny( $wyzsza );

pp_ok(
	pp_ma_blad( $a2, 'items.0.sale_price' ),
	'cena aktywna WYZSZA od regularnej przy on_sale to blad pozycji',
	'kod=' . ( $a2->is_ok() ? 'OK' : (string) $a2->get_code() )
);

/* ==================================================================== B */

$GLOBALS['mp_pp']['lines'][] = '';
$GLOBALS['mp_pp']['lines'][] = '=== B. cennik brutto przy wylaczonych podatkach ===';

update_option( 'woocommerce_prices_include_tax', 'yes' );
update_option( 'woocommerce_calc_taxes', 'no' );

$zwykly = pp_produkt( '100.00', '', '100.00' );
$b      = pp_ceny( $zwykly );

pp_ok(
	! $b->is_ok() && 'tax_setting_ambiguous' === (string) $b->get_code(),
	'sklep „ceny z podatkiem + podatki wylaczone" konczy sie odmowa, nie domyslem',
	'kod=' . ( $b->is_ok() ? 'OK (przeszlo jako netto!)' : (string) $b->get_code() )
);

/* ==================================================================== C */

$GLOBALS['mp_pp']['lines'][] = '';
$GLOBALS['mp_pp']['lines'][] = '=== C. KONTR-ASERCJE ===';

update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );

$prawdziwa = pp_produkt( '100.00', '80.00', '80.00' );
$c1        = pp_ceny( $prawdziwa );
$dane_c1   = $c1->get_data();

pp_ok( $c1->is_ok(), 'prawdziwa promocja (80 < 100) przechodzi', 'kod=' . ( $c1->is_ok() ? '' : (string) $c1->get_code() ) );
pp_ok(
	isset( $dane_c1['prices'][0]['sale_price'] ) && 80.0 === (float) $dane_c1['prices'][0]['sale_price'],
	'i niesie cene promocyjna 80,00',
	'sale_price=' . var_export( isset( $dane_c1['prices'][0]['sale_price'] ) ? $dane_c1['prices'][0]['sale_price'] : 'BRAK', true )
);

$bez_promo = pp_produkt( '100.00', '', '100.00' );
$c2        = pp_ceny( $bez_promo );
$dane_c2   = $c2->get_data();

pp_ok( $c2->is_ok(), 'produkt BEZ promocji z _price = regular przechodzi', 'kod=' . ( $c2->is_ok() ? '' : (string) $c2->get_code() ) );
pp_ok(
	isset( $dane_c2['prices'][0] ) && array_key_exists( 'sale_price', $dane_c2['prices'][0] ) && null === $dane_c2['prices'][0]['sale_price'],
	'i nie udaje promocji (sale_price = null)'
);

update_option( 'woocommerce_prices_include_tax', 'yes' );

$brutto  = pp_produkt( '123.00', '', '123.00' );
$c3      = pp_ceny( $brutto );
$dane_c3 = $c3->get_data();

pp_ok(
	$c3->is_ok() && ! empty( $dane_c3['prices'][0]['from_gross'] ),
	'sklep z cennikiem brutto I wlaczonymi podatkami dziala jak dotad',
	'kod=' . ( $c3->is_ok() ? 'OK' : (string) $c3->get_code() )
);

update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_prices_include_tax', 'no' );

$netto   = pp_produkt( '150.00', '', '150.00' );
$c4      = pp_ceny( $netto );
$dane_c4 = $c4->get_data();

pp_ok(
	$c4->is_ok() && empty( $dane_c4['prices'][0]['from_gross'] ),
	'sklep NETTO z wylaczonymi podatkami dalej przechodzi — odmowa dotyczy tylko sprzecznosci',
	'kod=' . ( $c4->is_ok() ? 'OK' : (string) $c4->get_code() )
);
