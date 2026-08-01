<?php
/**
 * P2-G9 — ujemna cena regularna wchodzila do oferty bez sprzeciwu.
 *
 * Uruchamianie: wp eval-file tests/naprawy/cena-ujemna.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G9  Kontrola ceny ujemnej pomijala regular_price
 *
 * Agent 2.2 sprawdzal znak wylacznie ceny EFEKTYWNEJ (`$effective`, czyli
 * `get_price()`), a `regular_price` trafialo do snapshotu po samym tescie
 * „niepuste i is_numeric". `is_numeric('-100')` zwraca true, wiec cena ujemna
 * przechodzila. Komentarz przy tym warunku deklarowal ochrone przed ujemna cena
 * w ofercie — kod realizowal z tego polowe.
 *
 * Stan jest osiagalny, bo `_price` i `_regular_price` to DWA osobne pola meta
 * i potrafia sie rozjechac: import cennika bez synchronizacji, zmiana ceny
 * zapytaniem SQL, wtyczka do promocji. Przy `_regular_price = -100`
 * i `_price = 50` cena efektywna jest dodatnia, wiec kontrola nie lapie.
 *
 * Dalej jest juz tylko arytmetyka. Produkt bez aktywnej promocji ma
 * `on_sale = false`, wiec Agent 4.1 bierze wprost `regular_price` — ujemne
 * `unit_grosze`, ujemne `line_grosze`, ujemna wartosc pozycji na dokumencie
 * handlowym i ujemny wklad do sumy oferty. Wszystkie bramki przechodza, bo
 * arytmetyka jest wewnetrznie spojna; rozjazd widac dopiero u klienta.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_cu'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

$GLOBALS['mp_cu_produkty'] = array();

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegol.
 * @return bool
 */
function cu_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_cu']['pass'];
		$GLOBALS['mp_cu']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_cu']['fail'];
	$GLOBALS['mp_cu']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Sprzata produkty testowe i wypisuje wynik.
 *
 * W `register_shutdown_function`, a nie na koncu pliku: blad krytyczny w srodku
 * zostawilby produkty „MP test" w bazie, a wtedy `ceny-brutto-netto.php`
 * i `pdf-pl-en-numer.php` padaja na VAT-cie. To zanieczyszczenie srodowiska
 * wyglada potem jak regresja, ktorej nie ma.
 *
 * @return void
 */
function cu_koniec() {
	foreach ( $GLOBALS['mp_cu_produkty'] as $id ) {
		if ( $id > 0 ) {
			wp_delete_post( (int) $id, true );
		}
	}

	$GLOBALS['mp_cu_produkty'] = array();

	if ( empty( $GLOBALS['mp_cu']['lines'] ) ) {
		return;
	}

	$r = $GLOBALS['mp_cu'];
	echo implode( "\n", $r['lines'] ) . "\n"; // phpcs:ignore
	echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $r['pass'], $r['fail'] ); // phpcs:ignore
	echo 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n"; // phpcs:ignore
	$GLOBALS['mp_cu']['lines'] = array();
}
register_shutdown_function( 'cu_koniec' );

if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Simple' ) ) {
	$GLOBALS['mp_cu']['lines'][] = '  [POMINIETO] WooCommerce niedostepne';
	return;
}

/**
 * Zaklada produkt testowy o zadanych cenach meta.
 *
 * Ceny ustawiamy przez `update_post_meta` PO zapisie, bo o to wlasnie chodzi:
 * odtwarzamy rozjazd `_price` i `_regular_price`, ktory powstaje przy imporcie
 * cennika albo zapisie z pominieciem API produktu. Setter WooCommerce trzyma
 * te pola spojnie i takiego stanu by nie zbudowal.
 *
 * @param string $nazwa   Nazwa produktu.
 * @param string $regular Wartosc `_regular_price`.
 * @param string $price   Wartosc `_price`.
 * @return int
 */
function cu_produkt( $nazwa, $regular, $price ) {
	$produkt = new WC_Product_Simple();
	$produkt->set_name( $nazwa );
	$produkt->set_regular_price( '1' );
	$produkt->set_status( 'publish' );
	$produkt->set_tax_status( 'taxable' );
	$produkt->set_tax_class( '' );
	$id = (int) $produkt->save();

	update_post_meta( $id, '_regular_price', $regular );
	update_post_meta( $id, '_price', $price );
	wp_cache_flush();

	$GLOBALS['mp_cu_produkty'][] = $id;

	return $id;
}

/**
 * Uruchamia Agenta 2.2 na jednej pozycji.
 *
 * @param int $product_id Produkt.
 * @return MP_OB_Result
 */
function cu_ceny( $product_id ) {
	$kontekst = new MP_OB_Context(
		array(
			'items' => array(
				array(
					'product_id'   => $product_id,
					'variation_id' => null,
					'qty'          => 1,
				),
			),
		)
	);

	$agent = new MP_OB_D2_Agent_Prices();

	return $agent->run( $kontekst );
}

$GLOBALS['mp_cu']['lines'][] = '=== A. rozjazd meta: cena regularna ujemna, efektywna dodatnia ===';

$id_ujemny = cu_produkt( 'MP test P2-G9 — cena regularna ujemna', '-100', '50' );
$produkt   = wc_get_product( $id_ujemny );

cu_ok( $id_ujemny > 0, 'produkt testowy zalozony' );
cu_ok(
	-100.0 === (float) $produkt->get_regular_price() && 50.0 === (float) $produkt->get_price(),
	'warunek scenariusza: _regular_price=-100, _price=50',
	'regular=' . var_export( $produkt->get_regular_price(), true ) . ' price=' . var_export( $produkt->get_price(), true )
);
cu_ok(
	! $produkt->is_on_sale(),
	'produkt NIE jest w promocji, wiec Dzial 4 wezmie cene regularna',
	'on_sale=' . var_export( $produkt->is_on_sale(), true )
);

$wynik_ujemny = cu_ceny( $id_ujemny );

cu_ok(
	! $wynik_ujemny->is_ok(),
	'Agent 2.2 odrzuca pozycje z ujemna cena regularna',
	'ok=' . var_export( $wynik_ujemny->is_ok(), true ) . ' snapshot=' . wp_json_encode( $wynik_ujemny->get_data() )
);
cu_ok(
	! $wynik_ujemny->is_ok() && 'incomplete_prices' === $wynik_ujemny->get_code(),
	'odmowa idzie tym samym kodem co inne bledy cen',
	'kod=' . $wynik_ujemny->get_code()
);

/*
 * Dowod na wage bledu: to, co przechodzilo przez Dzial 2, szlo dalej do Dzialu 4
 * bez zadnej kontroli znaku i konczylo sie ujemna wartoscia pozycji na
 * dokumencie handlowym.
 */
$dane_ujemne = (array) $wynik_ujemny->get_data();
$snapshot    = isset( $dane_ujemne['prices'][0] ) ? $dane_ujemne['prices'][0] : array();

cu_ok(
	! isset( $snapshot['regular_price'] ) || (float) $snapshot['regular_price'] >= 0.0,
	'ujemna cena nie trafia do snapshotu, z ktorego liczy Dzial 4',
	'snapshot=' . wp_json_encode( $snapshot )
);

$GLOBALS['mp_cu']['lines'][] = '';
$GLOBALS['mp_cu']['lines'][] = '=== B. KONTR-ASERCJE: poprawne ceny nadal przechodza ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na odrzucaniu wszystkiego, co ma
 * rozjechane meta, albo na odrzucaniu ceny zero. Cena 0 jest LEGALNA (pozycja
 * gratis: 0 netto, 0 VAT, arytmetyka spojna) i tak jest opisana w kodzie.
 */
$id_zwykly     = cu_produkt( 'MP test P2-G9 — cena zwykla', '100', '100' );
$wynik_zwykly  = cu_ceny( $id_zwykly );
$dane_zwykle   = (array) $wynik_zwykly->get_data();

cu_ok(
	$wynik_zwykly->is_ok(),
	'produkt z cena dodatnia przechodzi',
	'kod=' . $wynik_zwykly->get_code()
);
cu_ok(
	isset( $dane_zwykle['prices'][0]['regular_price'] ) && 100.0 === (float) $dane_zwykle['prices'][0]['regular_price'],
	'cena regularna trafia do snapshotu bez zmian',
	'snapshot=' . wp_json_encode( isset( $dane_zwykle['prices'][0] ) ? $dane_zwykle['prices'][0] : array() )
);

$id_gratis    = cu_produkt( 'MP test P2-G9 — pozycja gratis', '0', '0' );
$wynik_gratis = cu_ceny( $id_gratis );

cu_ok(
	$wynik_gratis->is_ok(),
	'cena ZERO nadal jest dopuszczona (legalna pozycja gratis)',
	'kod=' . $wynik_gratis->get_code()
);

$id_promo    = cu_produkt( 'MP test P2-G9 — promocja', '200', '150' );
$wynik_promo = cu_ceny( $id_promo );

cu_ok(
	$wynik_promo->is_ok(),
	'produkt z cena efektywna nizsza od regularnej przechodzi',
	'kod=' . $wynik_promo->get_code()
);
