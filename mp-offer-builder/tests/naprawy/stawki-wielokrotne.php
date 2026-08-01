<?php
/**
 * P2-G11 — druga stawka VAT klasy przepadala bez sladu.
 *
 * Uruchamianie: wp eval-file tests/naprawy/stawki-wielokrotne.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G11  Agent 2.3 bral pierwsza stawke z tablicy i konczyl statusem OK
 *
 * `WC_Tax::get_base_tax_rates()` zwraca TABLICE stawek pasujacych do bazy
 * sklepu — dokumentacja WooCommerce mowi wprost o „array of matching rates".
 * Agent 2.3 robil `reset( $found )`, bral pierwszy wiersz, a reszte po cichu
 * wyrzucal i konczyl dzial statusem OK.
 *
 * Sklep ze skonfigurowanymi dwiema stawkami dla jednej klasy (stawka podstawowa
 * plus doplata) dostawal na dokumencie handlowym stawke NIZSZA niz w sklepie,
 * a wiec zanizony VAT i zanizona kwote brutto. Bez bledu, bez sladu w dzienniku
 * i przy przechodzacej bramce QA2 — rozjazd widac dopiero przy rozliczeniu.
 *
 * Naprawa sumuje stawki zwykle (WooCommerce nalicza kazda od tej samej podstawy
 * netto) i ODMAWIA przy stawce zlozonej (compound), ktorej nie da sie przedstawic
 * jedna liczba procentowa, bo liczy sie ja od kwoty juz opodatkowanej.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_sw'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

$GLOBALS['mp_sw_klasy']  = array();
$GLOBALS['mp_sw_stawki'] = array();

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegol.
 * @return bool
 */
function sw_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_sw']['pass'];
		$GLOBALS['mp_sw']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_sw']['fail'];
	$GLOBALS['mp_sw']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Sprzata klasy i stawki testowe, potem wypisuje wynik.
 *
 * W `register_shutdown_function`: stawka testowa zostawiona w bazie zmienia
 * VAT liczony przez inne testy i wyglada potem jak regresja, ktorej nie ma.
 *
 * @return void
 */
function sw_koniec() {
	foreach ( $GLOBALS['mp_sw_stawki'] as $id ) {
		WC_Tax::_delete_tax_rate( (int) $id );
	}

	foreach ( $GLOBALS['mp_sw_klasy'] as $slug ) {
		if ( in_array( $slug, WC_Tax::get_tax_class_slugs(), true ) ) {
			WC_Tax::delete_tax_class_by( 'slug', $slug );
		}
	}

	$GLOBALS['mp_sw_stawki'] = array();
	$GLOBALS['mp_sw_klasy']  = array();

	if ( class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	}

	if ( empty( $GLOBALS['mp_sw']['lines'] ) ) {
		return;
	}

	$r = $GLOBALS['mp_sw'];
	echo implode( "\n", $r['lines'] ) . "\n"; // phpcs:ignore
	echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $r['pass'], $r['fail'] ); // phpcs:ignore
	echo 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n"; // phpcs:ignore
	$GLOBALS['mp_sw']['lines'] = array();
}
register_shutdown_function( 'sw_koniec' );

if ( ! class_exists( 'WC_Tax' ) ) {
	$GLOBALS['mp_sw']['lines'][] = '  [POMINIETO] WooCommerce niedostepne';
	return;
}

/**
 * Zaklada klase podatkowa z podanymi stawkami.
 *
 * @param string $slug    Slug klasy.
 * @param string $nazwa   Nazwa klasy.
 * @param array  $stawki  Lista array( procent, nazwa, compound ).
 * @return string
 */
function sw_klasa( $slug, $nazwa, array $stawki ) {
	if ( ! in_array( $slug, WC_Tax::get_tax_class_slugs(), true ) ) {
		WC_Tax::create_tax_class( $nazwa );
	}

	$GLOBALS['mp_sw_klasy'][] = $slug;
	$kolejnosc                = 0;

	foreach ( $stawki as $stawka ) {
		$GLOBALS['mp_sw_stawki'][] = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'PL',
				'tax_rate'          => number_format( (float) $stawka[0], 4, '.', '' ),
				'tax_rate_name'     => (string) $stawka[1],
				'tax_rate_priority' => $kolejnosc + 1,
				'tax_rate_order'    => $kolejnosc,
				'tax_rate_class'    => $slug,
				'tax_rate_compound' => empty( $stawka[2] ) ? 0 : 1,
			)
		);
		++$kolejnosc;
	}

	WC_Cache_Helper::invalidate_cache_group( 'taxes' );

	return $slug;
}

/**
 * Uruchamia Agenta 2.3 dla jednej klasy podatkowej.
 *
 * @param string $klasa Slug klasy.
 * @return MP_OB_Result
 */
function sw_stawki( $klasa ) {
	$kontekst = new MP_OB_Context(
		array(
			'products' => array(
				array(
					'tax_class'  => $klasa,
					'tax_status' => 'taxable',
				),
			),
		)
	);

	$agent = new MP_OB_D2_Agent_Tax();

	return $agent->run( $kontekst );
}

$GLOBALS['mp_sw']['lines'][] = '=== A. klasa z dwiema stawkami zwyklymi ===';

$dwie = sw_klasa(
	'mp-test-dwie-stawki',
	'MP Test Dwie Stawki',
	array(
		array( 20.0, 'VAT podstawowy', false ),
		array( 3.0, 'Doplata lokalna', false ),
	)
);

sw_ok(
	2 === count( WC_Tax::get_base_tax_rates( $dwie ) ),
	'warunek scenariusza: WooCommerce zwraca DWIE stawki dla klasy',
	'zwrocono=' . count( WC_Tax::get_base_tax_rates( $dwie ) )
);

$wynik_dwie = sw_stawki( $dwie );
$dane_dwie  = (array) $wynik_dwie->get_data();
$stawka     = isset( $dane_dwie['tax_rates'][ $dwie ]['rate'] ) ? (float) $dane_dwie['tax_rates'][ $dwie ]['rate'] : null;

sw_ok( $wynik_dwie->is_ok(), 'dzial liczy oferte', 'kod=' . $wynik_dwie->get_code() );
sw_ok(
	23.0 === $stawka,
	'stawka oferty to SUMA stawek klasy (20% + 3%), nie pierwsza z nich',
	'stawka=' . var_export( $stawka, true )
);

$etykieta = isset( $dane_dwie['tax_rates'][ $dwie ]['label'] ) ? (string) $dane_dwie['tax_rates'][ $dwie ]['label'] : '';

sw_ok(
	false !== strpos( $etykieta, 'VAT podstawowy' ) && false !== strpos( $etykieta, 'Doplata lokalna' ),
	'etykieta wymienia obie skladowe — czlowiek widzi, z czego wyszla stawka',
	'etykieta=' . $etykieta
);

$GLOBALS['mp_sw']['lines'][] = '';
$GLOBALS['mp_sw']['lines'][] = '=== B. stawka zlozona (compound) — odmowa zamiast zgadywania ===';

$compound = sw_klasa(
	'mp-test-compound',
	'MP Test Compound',
	array(
		array( 20.0, 'VAT podstawowy', false ),
		array( 5.0, 'Podatek zlozony', true ),
	)
);

$wynik_compound = sw_stawki( $compound );

sw_ok(
	! $wynik_compound->is_ok(),
	'dzial odmawia zamiast wystawic dokument z wymyslona stawka',
	'ok=' . var_export( $wynik_compound->is_ok(), true ) . ' dane=' . wp_json_encode( $wynik_compound->get_data() )
);
sw_ok(
	'compound_tax_rate' === $wynik_compound->get_code(),
	'odmowa nazywa przyczyne',
	'kod=' . $wynik_compound->get_code()
);

$GLOBALS['mp_sw']['lines'][] = '';
$GLOBALS['mp_sw']['lines'][] = '=== C. KONTR-ASERCJE: jedna stawka i brak stawki dzialaja jak dotad ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na odrzucaniu kazdej klasy z wiecej niz
 * jedna stawka albo na zmianie zachowania dla sklepow z jedna stawka — czyli dla
 * wszystkich normalnych instalacji.
 */
$jedna       = sw_klasa( 'mp-test-jedna-stawka', 'MP Test Jedna Stawka', array( array( 23.0, 'VAT 23%', false ) ) );
$wynik_jedna = sw_stawki( $jedna );
$dane_jedna  = (array) $wynik_jedna->get_data();

sw_ok(
	$wynik_jedna->is_ok() && 23.0 === (float) $dane_jedna['tax_rates'][ $jedna ]['rate'],
	'klasa z jedna stawka zwraca dokladnie ta stawke',
	'kod=' . $wynik_jedna->get_code() . ' stawka=' . var_export( isset( $dane_jedna['tax_rates'][ $jedna ]['rate'] ) ? $dane_jedna['tax_rates'][ $jedna ]['rate'] : null, true )
);
sw_ok(
	'VAT 23%' === (string) $dane_jedna['tax_rates'][ $jedna ]['label'],
	'etykieta jednej stawki zostaje bez zmian',
	'etykieta=' . (string) $dane_jedna['tax_rates'][ $jedna ]['label']
);

$pusta       = sw_klasa( 'mp-test-bez-stawek', 'MP Test Bez Stawek', array() );
$wynik_pusta = sw_stawki( $pusta );

sw_ok(
	! $wynik_pusta->is_ok() && 'missing_tax_rate' === $wynik_pusta->get_code(),
	'klasa bez stawek nadal konczy sie missing_tax_rate, NIE domyslnym 23%',
	'kod=' . $wynik_pusta->get_code()
);
