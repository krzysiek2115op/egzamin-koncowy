<?php
/**
 * Ustalenie 1.16 — liczba odczytow nie moze rosnac z liczba pozycji oferty.
 *
 * Uruchamianie: wp eval-file tests/naprawy/produkty-jedna-partia.php
 *
 * Dzial 2 sprawdzal kazda pozycje osobnym `wc_get_product()`. Oferta na 40
 * pozycji — normalne zamowienie hurtowe — robila 40 odczytow tam, gdzie
 * wystarcza staly komplet. Przy generowaniu PDF-a w tle konczy sie to
 * przekroczeniem limitu czasu, a handlowiec widzi tylko oferte, ktora „sie
 * nie wygenerowala".
 *
 * Test mierzy KSZTALT, nie czas: ile zapytan przybywa przy 2 pozycjach,
 * a ile przy 12. Jesli rosnie liniowo, naprawy nie ma.
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
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function pp_dump() {
	if ( empty( $GLOBALS['mp_pp']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_pp'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-p2-produkty.txt' : '/tmp/mp-p2-produkty.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_pp']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'pp_dump' );

if ( ! function_exists( 'wc_get_products' ) ) {
	pp_ok( false, 'WooCommerce jest dostepne' );
	return;
}

/*
 * Produkty testowe zakladamy SAMI i kazdy jest inny. To jest sedno pomiaru:
 * przy jednym produkcie powtorzonym 12 razy pamiec podreczna WooCommerce
 * obsluzy 11 odczytow z pamieci i N+1 sie nie pokaze — test przechodzilby
 * takze na kodzie sprzed naprawy. Rozne identyfikatory to wymuszaja.
 */
$utworzone = array();

for ( $i = 0; $i < 12; $i++ ) {
	$produkt = new WC_Product_Simple();
	$produkt->set_name( 'MP test 1.16 nr ' . $i );
	$produkt->set_regular_price( '100' );
	$produkt->set_status( 'publish' );
	$utworzone[] = (int) $produkt->save();
}

$utworzone = array_values( array_filter( $utworzone ) );

pp_ok( 12 === count( $utworzone ), 'dwanascie ROZNYCH produktow testowych utworzonych', 'utworzono=' . count( $utworzone ) );

if ( 12 !== count( $utworzone ) ) {
	foreach ( $utworzone as $id ) {
		wp_delete_post( $id, true );
	}
	return;
}

$produkt_id = $utworzone[0];

/**
 * Ile zapytan do bazy kosztuje sprawdzenie oferty o podanej liczbie pozycji.
 *
 * Kazda pozycja to INNY produkt — inaczej pamiec podreczna WooCommerce
 * obsluzylaby powtorki i narzut liniowy w ogole by sie nie pokazal.
 * Pamiec czyszczona przed pomiarem, zeby drugi przebieg nie mierzyl cache.
 *
 * @param int   $ile Liczba pozycji oferty.
 * @param int[] $ids Identyfikatory ROZNYCH produktow.
 * @return int Liczba zapytan.
 */
function pp_zapytania( $ile, $ids ) {
	global $wpdb;

	$items = array();

	for ( $i = 0; $i < $ile; $i++ ) {
		$items[] = array(
			'product_id'   => (int) $ids[ $i ],
			'variation_id' => null,
			'qty'          => 1,
		);
	}

	wp_cache_flush();

	$przed = (int) $wpdb->num_queries;

	$agent = new MP_OB_D2_Agent_Products();
	$agent->run( new MP_OB_Context( array( 'items' => $items ) ) );

	return (int) $wpdb->num_queries - $przed;
}

$male  = pp_zapytania( 2, $utworzone );
$duze  = pp_zapytania( 12, $utworzone );
$wzrost = $duze - $male;

$GLOBALS['mp_pp']['lines'][] = '=== 1.16 — sprawdzenie pozycji oferty ===';
$GLOBALS['mp_pp']['lines'][] = '  (2 pozycje: ' . $male . ' zapytan, 12 pozycji: ' . $duze . ' zapytan)';

/*
 * Prog: dziesiec pozycji wiecej ma kosztowac MNIEJ niz piec dodatkowych
 * zapytan. Przy odczycie na pozycje wzrost wynosi okolo dziesieciu, przy
 * pobraniu kompletem — zero. Prog jest posrodku, zeby test nie lamal sie
 * na jednym zapytaniu wiecej z powodu wewnetrznych szczegolow WooCommerce.
 */
pp_ok(
	$wzrost < 5,
	'dziesiec pozycji wiecej nie dodaje dziesieciu zapytan',
	'wzrost=' . $wzrost . ' zapytan'
);

pp_ok(
	$duze < 2 * $male + 5,
	'koszt sprawdzenia nie skaluje sie z liczba pozycji',
	'male=' . $male . ' duze=' . $duze
);

$GLOBALS['mp_pp']['lines'][] = '';
$GLOBALS['mp_pp']['lines'][] = '=== zachowanie bez zmian ===';

// Ten sam produkt, ta sama odpowiedz co wczesniej: pozycje poprawne.
$agent  = new MP_OB_D2_Agent_Products();
$wynik  = $agent->run(
	new MP_OB_Context(
		array(
			'items' => array(
				array(
					'product_id'   => $produkt_id,
					'variation_id' => null,
					'qty'          => 2,
				),
			),
		)
	)
);

pp_ok( $wynik->is_ok(), 'pozycja z istniejacym produktem nadal przechodzi' );

$dane = (array) $wynik->get_data();
pp_ok(
	isset( $dane['products'] ) && ! empty( $dane['products'] ),
	'agent zwraca dane produktu do dalszych dzialow'
);

// Produkt, ktorego nie ma, musi byc nadal odrzucony — pobranie kompletem nie
// moze zamienic braku w cisze.
$brak = new MP_OB_D2_Agent_Products();
$odp  = $brak->run(
	new MP_OB_Context(
		array(
			'items' => array(
				array(
					'product_id'   => 999999999,
					'variation_id' => null,
					'qty'          => 1,
				),
			),
		)
	)
);

pp_ok( ! $odp->is_ok(), 'nieistniejacy produkt nadal konczy sie odmowa' );

// Sprzatanie: produkty tego przebiegu.
foreach ( $utworzone as $id ) {
	wp_delete_post( $id, true );
}
