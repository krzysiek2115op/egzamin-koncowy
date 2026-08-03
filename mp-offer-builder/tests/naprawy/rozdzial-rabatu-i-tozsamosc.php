<?php
/**
 * Audyt koncowy 1.3.8 — Dzial 6: bezpiecznik zakresu i kontrola, ktora milczala.
 *
 * Uruchamianie: wp eval-file tests/naprawy/rozdzial-rabatu-i-tozsamosc.php
 *
 * A. KONTROLA TOZSAMOSCI WYLACZALA SIE PO CICHU. Porownanie identyfikatora ze
 *    snapshotu z identyfikatorem pozycji wykonuje sie tylko wtedy, gdy OBA sa
 *    dodatnie. Kontekst bez klucza `items` (albo z pozycja, ktorej
 *    `lookup_id()` nie rozpozna) dawal zero po stronie pozycji — i kontrola
 *    pilnujaca, zeby pozycja nie dostala CUDZEJ klasy podatkowej, przestawala
 *    dzialac bez slowa. Zostaje udokumentowane zawezenie: snapshot BEZ `id`
 *    (starsze konteksty, testy podajace sam `tax_class`) nie ma czego
 *    porownywac i dalej przechodzi.
 *
 * B. ROZDZIAL RABATU MOGL PRZEPELNIC MNOZENIE. `prorate()` liczy
 *    `amount * part / total`; sciezka zapasowa (bez BCMath) mnozy dwie liczby
 *    calkowite przed dzieleniem, a po przekroczeniu PHP_INT_MAX wynik staje sie
 *    floatem, ktorego `intdiv()` nie przyjmie — blad krytyczny zamiast oferty.
 *
 *    Bezpiecznik stoi PRZED wyborem sciezki, wiec dziala tak samo z BCMath
 *    i bez niego. Inaczej ta sama oferta konczylaby sie sukcesem na jednej
 *    maszynie, a bledem krytycznym na drugiej — a to gorsze niz sama wada.
 *
 * Sekcja D dokumentuje ustalenie ODRZUCONE (status `shipping`).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_rr'] = array(
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
function rr_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_rr']['pass'];
		$GLOBALS['mp_rr']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_rr']['fail'];
	$GLOBALS['mp_rr']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function rr_dump() {
	if ( empty( $GLOBALS['mp_rr']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_rr'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_rr']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'rr_dump' );

/**
 * Uruchamia Agenta 6.2.
 *
 * @param array $dane Kontekst.
 * @return MP_OB_Result
 */
function rr_licz( array $dane ) {
	$agent = new MP_OB_D6_Agent_Rounding();

	return $agent->run( new MP_OB_Context( $dane ) );
}

/**
 * Kod odmowy albo pusty ciag przy powodzeniu.
 *
 * @param MP_OB_Result $wynik Wynik.
 * @return string
 */
function rr_kod( $wynik ) {
	return $wynik->is_ok() ? '' : (string) $wynik->get_code();
}

/**
 * Kontekst krajowy: dwie pozycje po 100,00 zl, jedna klasa podatkowa.
 *
 * @param array $zmiany Nadpisania.
 * @return array
 */
function rr_kontekst( array $zmiany = array() ) {
	return array_merge(
		array(
			'tax_mechanism'   => 'domestic',
			'tax_rates'       => array( '' => array( 'rate' => 23.0, 'label' => 'VAT 23%' ) ),
			'items'           => array(
				array( 'product_id' => 11 ),
				array( 'product_id' => 22 ),
			),
			'products'        => array(
				array( 'id' => 11, 'tax_class' => '', 'tax_status' => 'taxable' ),
				array( 'id' => 22, 'tax_class' => '', 'tax_status' => 'taxable' ),
			),
			'lines'           => array(
				array( 'unit_grosze' => 10000, 'line_grosze' => 10000 ),
				array( 'unit_grosze' => 10000, 'line_grosze' => 10000 ),
			),
			'discount_total'  => 0,
			'subtotal_grosze' => 20000,
		),
		$zmiany
	);
}

/* ==================================================================== A */

$GLOBALS['mp_rr']['lines'][] = '=== A. kontrola tozsamosci nie moze sie wylaczac po cichu ===';

$bez_items = rr_licz( rr_kontekst( array( 'items' => array() ) ) );

rr_ok(
	'line_identity_unverifiable' === rr_kod( $bez_items ),
	'brak klucza `items` przy snapshocie z `id` to odmowa, nie milczenie',
	'kod=' . ( $bez_items->is_ok() ? 'OK (przeszlo!)' : rr_kod( $bez_items ) )
);

$nierozpoznana = rr_licz(
	rr_kontekst(
		array(
			'items' => array(
				array( 'qty' => 1 ),
				array( 'product_id' => 22 ),
			),
		)
	)
);

rr_ok(
	'line_identity_unverifiable' === rr_kod( $nierozpoznana ),
	'pozycja, ktorej lookup_id() nie rozpozna, tez konczy sie odmowa',
	'kod=' . ( $nierozpoznana->is_ok() ? 'OK (przeszlo!)' : rr_kod( $nierozpoznana ) )
);

/* ==================================================================== B */

$GLOBALS['mp_rr']['lines'][] = '';
$GLOBALS['mp_rr']['lines'][] = '=== B. rozdzial rabatu poza zakresem liczb calkowitych ===';

$ogromna = 1 << 61;

$przepelnienie = rr_licz(
	rr_kontekst(
		array(
			'lines'           => array(
				array( 'unit_grosze' => $ogromna, 'line_grosze' => $ogromna ),
				array( 'unit_grosze' => $ogromna, 'line_grosze' => $ogromna ),
			),
			'subtotal_grosze' => $ogromna * 2,
			'discount_total'  => 8,
		)
	)
);

rr_ok(
	'prorate_out_of_range' === rr_kod( $przepelnienie ),
	'kwoty, przy ktorych mnozenie wyszloby poza int, konczy odmowa',
	'kod=' . ( $przepelnienie->is_ok() ? 'OK (policzone mimo przepelnienia)' : rr_kod( $przepelnienie ) )
);

/* ==================================================================== C */

$GLOBALS['mp_rr']['lines'][] = '';
$GLOBALS['mp_rr']['lines'][] = '=== C. KONTR-ASERCJE ===';

$zwykly = rr_licz( rr_kontekst( array( 'discount_total' => 2000 ) ) );

rr_ok( $zwykly->is_ok(), 'zwykly rabat 20,00 zl dalej przechodzi', 'kod=' . rr_kod( $zwykly ) );

$dane_zwykly = $zwykly->get_data();

rr_ok(
	isset( $dane_zwykly['net_grosze'] ) && 18000 === (int) $dane_zwykly['net_grosze'],
	'netto po rabacie to 180,00 zl',
	'net=' . var_export( isset( $dane_zwykly['net_grosze'] ) ? $dane_zwykly['net_grosze'] : 'BRAK', true )
);

$bez_id = rr_licz(
	rr_kontekst(
		array(
			'items'    => array(),
			'products' => array(
				array( 'tax_class' => '', 'tax_status' => 'taxable' ),
				array( 'tax_class' => '', 'tax_status' => 'taxable' ),
			),
		)
	)
);

rr_ok(
	$bez_id->is_ok(),
	'snapshot BEZ `id` dalej przechodzi — udokumentowane zawezenie zostaje',
	'kod=' . rr_kod( $bez_id )
);

$rozjazd = rr_licz(
	rr_kontekst(
		array(
			'items' => array(
				array( 'product_id' => 99 ),
				array( 'product_id' => 22 ),
			),
		)
	)
);

rr_ok(
	'line_product_mismatch' === rr_kod( $rozjazd ),
	'pozycja sparowana z innym produktem dalej konczy sie wlasnym kodem',
	'kod=' . rr_kod( $rozjazd )
);

$bez_rabatu = rr_licz(
	rr_kontekst(
		array(
			'lines'           => array(
				array( 'unit_grosze' => $ogromna, 'line_grosze' => $ogromna ),
				array( 'unit_grosze' => $ogromna, 'line_grosze' => $ogromna ),
			),
			'subtotal_grosze' => $ogromna * 2,
			'discount_total'  => 0,
		)
	)
);

rr_ok(
	'prorate_out_of_range' !== rr_kod( $bez_rabatu ),
	'te same ogromne kwoty BEZ rabatu nie sa odrzucane — bezpiecznik dotyczy mnozenia, nie kwot',
	'kod=' . rr_kod( $bez_rabatu )
);

/* ==================================================================== D */

$GLOBALS['mp_rr']['lines'][] = '';
$GLOBALS['mp_rr']['lines'][] = '=== D. USTALENIE ODRZUCONE: „zwolnienie obejmuje wylacznie none" ===';

/*
 * Audyt zglosil, ze pozycja o statusie `shipping` („podatek dotyczy tylko
 * wysylki") dostaje VAT, bo zwolnienie obejmuje wylacznie `none` — przy czym
 * Dzial 2 podatku z takiej ceny nie zdejmuje, wiec wychodzilby podatek od ceny
 * brutto.
 *
 * To NIEPRAWDA i ponizsze asercje przechodzily PRZED jakakolwiek naprawa.
 * Rozstrzyga `MP_OB_Products::zwolniona_z_vat()`, ktore od wczesniejszej rundy
 * oddaje prawde dla OBU statusow — ma nawet docblock o tym, ze `shipping`
 * „wpadala w szczeline miedzy nimi". Para modelowa przeczytala komentarz przy
 * wywolaniu (mowiacy tylko o `none`) i orzekla o predykacie, ktorego nie
 * otworzyla.
 *
 * Asercje zostaja jako straz: gdyby ktos kiedys zawezil ten predykat do samego
 * `none`, ma pasc TEN test, a nie dokument handlowy klienta.
 */

$shipping = rr_licz(
	rr_kontekst(
		array(
			'products'  => array(
				array( 'id' => 11, 'tax_class' => 'reduced', 'tax_status' => 'shipping' ),
				array( 'id' => 22, 'tax_class' => 'reduced', 'tax_status' => 'shipping' ),
			),
			'tax_rates' => array( '' => array( 'rate' => 23.0, 'label' => 'VAT 23%' ) ),
		)
	)
);

rr_ok(
	$shipping->is_ok(),
	'pozycja `shipping` nie wymaga stawki dla swojej klasy — czyli jest zwolniona',
	'kod=' . rr_kod( $shipping )
);

$dane_shipping = $shipping->get_data();

rr_ok(
	isset( $dane_shipping['vat_grosze'] ) && 0 === (int) $dane_shipping['vat_grosze'],
	'i nie dostaje VAT-u',
	'vat=' . var_export( isset( $dane_shipping['vat_grosze'] ) ? $dane_shipping['vat_grosze'] : 'BRAK', true )
);

rr_ok(
	MP_OB_Products::zwolniona_z_vat( array( 'tax_status' => 'shipping' ) )
		&& MP_OB_Products::zwolniona_z_vat( array( 'tax_status' => 'none' ) )
		&& ! MP_OB_Products::zwolniona_z_vat( array( 'tax_status' => 'taxable' ) ),
	'predykat zwolnienia obejmuje `none` I `shipping`, a nie obejmuje `taxable`'
);
