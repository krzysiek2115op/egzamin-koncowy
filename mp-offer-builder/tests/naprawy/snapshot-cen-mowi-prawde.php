<?php
/**
 * Dzial 2, Agent 2.2 — snapshot cen deklarowal rzeczy, ktorych w katalogu nie ma.
 *
 * Uruchamianie: wp eval-file tests/naprawy/snapshot-cen-mowi-prawde.php
 *
 * Cztery ustalenia z audytu glebokiego (para 1.25):
 *
 * 1. Gdy produkt ma AKTYWNA promocje, ale `get_price()` oddaje wartosc pusta lub
 *    nieliczbowa (rozjechane meta `_price` i `_sale_price` po imporcie), cena
 *    promocyjna byla po cichu zastepowana regularna i zapisywana do snapshotu
 *    jako `sale_price` przy `on_sale = true`. Snapshot deklarowal promocje,
 *    ktorej wartosc nie jest cena promocyjna — a dokument dla klienta obiecywal
 *    rabat rowny zeru.
 *
 * 2. Drugie sprawdzenie ceny ujemnej (za konwersja na netto) bylo martwe: po
 *    pierwszym sprawdzeniu obie wartosci sa nieujemne, a konwersja — wedle
 *    komentarza w tym samym pliku — wartosci ujemnej nie produkuje.
 *
 * 3. Klucz `prices_include_tax` opisywal ustawienie SKLEPU, a nie zawartosc
 *    snapshotu, do ktorego byl dolaczony: gdy mial wartosc prawdziwa, ceny w tym
 *    samym wyniku byly juz przeliczone na NETTO, czyli podatku nie zawieraly.
 *
 * 4. Nieudane wykrycie ustawienia „ceny z podatkiem" konczylo sie cichym
 *    przyjeciem, ze cennik jest netto — czyli zalozeniem, ktore siostrzana galaz
 *    tego samego kodu uznaje za na tyle grozne, ze przerywa dzial twardym FAIL-em.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_sc'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

$GLOBALS['mp_sc_produkty'] = array();

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function sc_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_sc']['pass'];
		$GLOBALS['mp_sc']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_sc']['fail'];
	$GLOBALS['mp_sc']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Sprzatanie i werdykt.
 *
 * @return void
 */
function sc_koniec() {
	foreach ( $GLOBALS['mp_sc_produkty'] as $id ) {
		wp_delete_post( (int) $id, true );
	}

	$GLOBALS['mp_sc_produkty'] = array();

	echo implode( "\n", $GLOBALS['mp_sc']['lines'] ) . "\n";
	echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_sc']['pass'], $GLOBALS['mp_sc']['fail'] );
	echo ( 0 === $GLOBALS['mp_sc']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
}

if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Simple' ) ) {
	echo "WooCommerce niedostepne — test nie ma na czym pracowac.\nVERDICT_HAS_FAILURES\n";
	return;
}

/**
 * Produkt z zadanymi metami cenowymi.
 *
 * `_sale_price` ustawiamy meta, zeby `is_on_sale()` bylo prawdziwe, a `_price`
 * podmieniamy niezaleznie — dokladnie tak rozjezdzaja sie te pola po imporcie
 * cennika bez synchronizacji.
 *
 * @param string $nazwa   Nazwa.
 * @param string $regular `_regular_price`.
 * @param string $sale    `_sale_price`.
 * @param string $price   `_price`.
 * @return int
 */
function sc_produkt( $nazwa, $regular, $sale, $price ) {
	$produkt = new WC_Product_Simple();
	$produkt->set_name( $nazwa );
	$produkt->set_regular_price( '1' );
	$produkt->set_status( 'publish' );
	$produkt->set_tax_status( 'taxable' );
	$produkt->set_tax_class( '' );
	$id = (int) $produkt->save();

	update_post_meta( $id, '_regular_price', $regular );
	update_post_meta( $id, '_sale_price', $sale );
	update_post_meta( $id, '_price', $price );
	wp_cache_flush();

	$GLOBALS['mp_sc_produkty'][] = $id;

	return $id;
}

/**
 * Uruchamia Agenta 2.2 na jednej pozycji.
 *
 * @param int $product_id Produkt.
 * @return MP_OB_Result
 */
function sc_ceny( $product_id ) {
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

$GLOBALS['mp_sc']['lines'][] = '=== A. „promocja" bez ceny promocyjnej to nie promocja ===';

// Promocja aktywna (sale < regular), ale `_price` puste — meta rozjechane.
$sc_id_puste = sc_produkt( 'MP test 2.2 — promocja bez ceny', '200', '150', '' );
$sc_produkt  = wc_get_product( $sc_id_puste );

sc_ok(
	$sc_produkt instanceof WC_Product && $sc_produkt->is_on_sale(),
	'A0: (zalozenie testu) katalog zglasza aktywna promocje',
	'on_sale=' . ( $sc_produkt instanceof WC_Product ? var_export( $sc_produkt->is_on_sale(), true ) : 'brak produktu' )
);
sc_ok(
	$sc_produkt instanceof WC_Product && '' === (string) $sc_produkt->get_price(),
	'A1: (zalozenie testu) a ceny aktywnej nie ma',
	'get_price=' . ( $sc_produkt instanceof WC_Product ? var_export( $sc_produkt->get_price(), true ) : '?' )
);

$sc_wynik_puste = sc_ceny( $sc_id_puste );
$sc_dane_puste  = (array) $sc_wynik_puste->get_data();
$sc_wiersz      = isset( $sc_dane_puste['prices'][0] ) ? (array) $sc_dane_puste['prices'][0] : array();

sc_ok(
	! $sc_wynik_puste->is_ok(),
	'A2: snapshot nie powstaje z promocja rowna cenie regularnej',
	'wynik=' . ( $sc_wynik_puste->is_ok() ? 'OK' : 'odmowa' ) . ' wiersz=' . wp_json_encode( $sc_wiersz )
);
sc_ok(
	false !== strpos( wp_json_encode( $sc_dane_puste ), 'sale_price' ),
	'A3: odmowa wskazuje pole, ktore sie nie zgadza',
	'dane=' . wp_json_encode( $sc_dane_puste )
);

// KONTR-ASERCJA: prawdziwa promocja ma isc do snapshotu jak dotad.
$sc_id_promo   = sc_produkt( 'MP test 2.2 — promocja prawdziwa', '200', '150', '150' );
$sc_wynik_promo = sc_ceny( $sc_id_promo );
$sc_dane_promo  = (array) $sc_wynik_promo->get_data();
$sc_wiersz_promo = isset( $sc_dane_promo['prices'][0] ) ? (array) $sc_dane_promo['prices'][0] : array();

sc_ok(
	$sc_wynik_promo->is_ok()
		&& true === ( $sc_wiersz_promo['on_sale'] ?? false )
		&& 150.0 === (float) ( $sc_wiersz_promo['sale_price'] ?? 0 )
		&& 200.0 === (float) ( $sc_wiersz_promo['regular_price'] ?? 0 ),
	'A4: KONTR-ASERCJA — prawdziwa promocja trafia do snapshotu bez zmian',
	'wiersz=' . wp_json_encode( $sc_wiersz_promo )
);

// KONTR-ASERCJA: produkt BEZ promocji z pustym `_price` to nadal zwykly produkt.
$sc_id_bez_promo    = sc_produkt( 'MP test 2.2 — bez promocji', '200', '', '' );
$sc_wynik_bez_promo = sc_ceny( $sc_id_bez_promo );
$sc_dane_bez        = (array) $sc_wynik_bez_promo->get_data();
$sc_wiersz_bez      = isset( $sc_dane_bez['prices'][0] ) ? (array) $sc_dane_bez['prices'][0] : array();

sc_ok(
	$sc_wynik_bez_promo->is_ok()
		&& false === ( $sc_wiersz_bez['on_sale'] ?? true )
		&& 200.0 === (float) ( $sc_wiersz_bez['regular_price'] ?? 0 ),
	'A5: KONTR-ASERCJA — bez promocji cena regularna wystarcza',
	'wiersz=' . wp_json_encode( $sc_wiersz_bez )
);

$GLOBALS['mp_sc']['lines'][] = '';
$GLOBALS['mp_sc']['lines'][] = '=== B. klucz mowi o zawartosci, nie o ustawieniu sklepu ===';

/*
 * `prices_include_tax = true` znaczylo „sklep ma cennik brutto" — a ceny w tym
 * samym wyniku byly juz przeliczone na netto, czyli podatku NIE zawieraly.
 * Czytelnik snapshotu dowiadywal sie czegos dokladnie odwrotnego niz stan
 * rzeczy. Pole per pozycja (`from_gross`) nazywalo to poprawnie od poczatku.
 */
sc_ok(
	! array_key_exists( 'prices_include_tax', $sc_dane_promo ),
	'B1: mylacego klucza juz nie ma',
	'klucze=' . implode( ', ', array_keys( $sc_dane_promo ) )
);
sc_ok(
	array_key_exists( 'prices_from_gross', $sc_dane_promo ),
	'B2: jest za to klucz mowiacy, skad ceny pochodza',
	'klucze=' . implode( ', ', array_keys( $sc_dane_promo ) )
);
sc_ok(
	array_key_exists( 'from_gross', $sc_wiersz_promo )
		&& (bool) $sc_dane_promo['prices_from_gross'] === (bool) $sc_wiersz_promo['from_gross'],
	'B3: KONTR-ASERCJA — oba slady mowia to samo',
	'wynik=' . var_export( $sc_dane_promo['prices_from_gross'] ?? null, true ) . ' wiersz=' . var_export( $sc_wiersz_promo['from_gross'] ?? null, true )
);

$GLOBALS['mp_sc']['lines'][] = '';
$GLOBALS['mp_sc']['lines'][] = '=== C. martwe sprawdzenie ceny ujemnej ===';

/*
 * Asercja na kodzie: oba sprawdzenia byly identyczne co do zakresu (obie ceny,
 * warunek `< 0`), a drugie stalo ZA konwersja, po ktorej — wedle komentarza
 * w tym samym pliku — wartosci ujemnej juz nie ma. Nie istnialo wejscie, ktore
 * przeszloby pierwsze i wpadlo w drugie.
 */
$sc_zrodlo = (string) file_get_contents( dirname( __DIR__ ) . '/../includes/pipeline/departments/class-mp-ob-department-02.php' );
$sc_ile    = substr_count( $sc_zrodlo, 'Cena pozycji jest ujemna — nie można zbudować oferty.' );

sc_ok(
	1 === $sc_ile,
	'C1: sprawdzenie ceny ujemnej jest jedno, nie dwa',
	'wystapien=' . $sc_ile
);

// KONTR-ASERCJA: to, ktore zostalo, ma nadal dzialac — i to na obu polach.
$sc_id_ujemny = sc_produkt( 'MP test 2.2 — cena regularna ujemna', '-100', '', '50' );
$sc_ujemny    = sc_ceny( $sc_id_ujemny );

sc_ok(
	! $sc_ujemny->is_ok(),
	'C2: KONTR-ASERCJA — ujemna cena regularna przy dodatniej efektywnej nadal odrzucana',
	'kod=' . $sc_ujemny->get_code()
);

$GLOBALS['mp_sc']['lines'][] = '';
$GLOBALS['mp_sc']['lines'][] = '=== D. nieustalone ustawienie to nie jest „cennik netto" ===';

/*
 * Asercja na kodzie z tego samego powodu, co wyzej: `wc_prices_include_tax()`
 * w tym srodowisku ISTNIEJE, wiec galezi bez niej nie da sie wywolac. A wlasnie
 * ona byla niesymetryczna: brak funkcji do PRZELICZENIA konczyl sie twardym
 * FAIL-em („milczace doliczenie VAT-u to blad na dokumencie"), a brak funkcji do
 * ODCZYTU ustawienia — cichym przyjeciem, ze cennik jest netto. To to samo
 * ryzyko, tylko wpisane w wartosc domyslna.
 */
$sc_od     = strpos( $sc_zrodlo, 'class MP_OB_D2_Agent_Prices' );
$sc_cialo  = false === $sc_od ? '' : substr( $sc_zrodlo, $sc_od, 4000 );

sc_ok(
	'' !== $sc_cialo,
	'D0: (zalozenie testu) cialo Agenta 2.2 znalezione'
);
sc_ok(
	false === strpos( $sc_cialo, "function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax()" ),
	'D1: brak funkcji nie jest juz cichym „to netto"',
	'kod nadal ma domyslke w koniunkcji'
);
sc_ok(
	false !== strpos( $sc_cialo, 'tax_setting_unavailable' ),
	'D2: zamiast tego jest odmowa z wlasnym kodem',
	'brak kodu tax_setting_unavailable'
);

sc_koniec();
