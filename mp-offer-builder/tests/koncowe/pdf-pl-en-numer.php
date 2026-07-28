<?php
/**
 * Test na ZYWYM WordPressie: kryterium odbioru 5.3 — PDF po polsku i po angielsku,
 * z wlasciwymi cenami ORAZ numerem oferty NA DOKUMENCIE.
 *
 * Uruchamianie: wp eval-file tests/koncowe/pdf-pl-en-numer.php
 * Srodowisko: WordPress + WooCommerce (kraj PL, waluta PLN, stawka VAT 23%),
 * co najmniej dwa opublikowane produkty proste.
 *
 * Dlaczego ten test istnieje: numer oferty trafial WYLACZNIE do metadanych PDF
 * (`addInfo('Title', ...)`) i do nazwy pliku. Kontrola w Dziale 9 sprawdzala
 * wlasnie metadane, wiec luka przechodzila niezauwazona, a klient otwieral
 * oferte bez numeru na stronie. Ten test patrzy na WIDOCZNA tresc.
 *
 * Sciezki wygenerowanych plikow ladują do /scr/mp-p2-pdf-sciezki.txt — tresc
 * weryfikuje `pdftotext` po stronie hosta (w kontenerze go nie ma).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_p'] = array(
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
function mp_pdf_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_p']['pass'];
		$GLOBALS['mp_p']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_p']['fail'];
	$GLOBALS['mp_p']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function mp_pdf_dump() {
	if ( empty( $GLOBALS['mp_p']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_p'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-p2-pdf.txt' : '/tmp/mp-p2-pdf.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_p']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'mp_pdf_dump' );

global $wpdb;

$GLOBALS['mp_p']['lines'][] = '=== PRZYGOTOWANIE ===';

// Szablony musza byc w wersji niosacej znacznik numeru. `install()` jest
// idempotentny i przy okazji domyka ewentualna zmiane schematu.
MP_Offer_Builder_DB::install();

$tpl_t = MP_Offer_Builder_DB::templates_table();
foreach ( array( 'pl', 'en' ) as $lang ) {
	$tpl = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tpl_t} WHERE lang = %s LIMIT 1", $lang ), ARRAY_A ); // phpcs:ignore
	mp_pdf_ok( is_array( $tpl ), 'szablon ' . strtoupper( $lang ) . ' istnieje' );
	mp_pdf_ok( is_array( $tpl ) && false !== strpos( (string) $tpl['content'], '{{offer_number}}' ), 'szablon ' . strtoupper( $lang ) . ' ma znacznik numeru oferty', is_array( $tpl ) ? 'wersja ' . $tpl['version'] : '' );
}

$produkty = function_exists( 'wc_get_products' ) ? wc_get_products(
	array(
		'limit'  => 2,
		'status' => 'publish',
		'return' => 'ids',
	)
) : array();
mp_pdf_ok( count( $produkty ) >= 2, 'sa co najmniej dwa produkty WooCommerce', 'jest: ' . count( $produkty ) );

if ( count( $produkty ) < 2 ) {
	mp_pdf_dump();
	return;
}

$GLOBALS['mp_p']['lines'][] = '';
$GLOBALS['mp_p']['lines'][] = '=== FORMAT KWOT (konwencja jezyka) ===';

/*
 * Osobna sekcja, bo blad byl podstepny: rozszerzenie intl BYLO zaladowane, ale
 * ICU nie mialo danych dla polskiego, wiec NumberFormatter po cichu uzywal
 * en_US. Polska oferta wychodzila z kwota „2,099.98 zl". Sprawdzamy WYNIK,
 * nie dostepnosc rozszerzenia.
 */
$pl_kwota = MP_OB_D7_Agent_Merge::format_money( 209998, 'pl', 'PLN' );
$en_kwota = MP_OB_D7_Agent_Merge::format_money( 209998, 'en', 'PLN' );

mp_pdf_ok( false !== strpos( $pl_kwota, ',98' ), 'PL: przecinek jako separator dziesietny', $pl_kwota );
mp_pdf_ok( false === strpos( $pl_kwota, ',099' ), 'PL: przecinek NIE jest separatorem tysiecy', $pl_kwota );
mp_pdf_ok( false !== strpos( $pl_kwota, 'zł' ), 'PL: symbol waluty to zl', $pl_kwota );
mp_pdf_ok( '2,099.98 PLN' === $en_kwota, 'EN: kropka dziesietna, przecinek tysiecy, kod PLN', $en_kwota );

$handlowiec = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" );
wp_set_current_user( $handlowiec );
$user = new WP_User( $handlowiec );
$user->add_cap( MP_OB_D1_Agent_Permission::CAPABILITY );

$sciezki = array();

foreach ( array( 'pl', 'en' ) as $lang ) {
	$GLOBALS['mp_p']['lines'][] = '';
	$GLOBALS['mp_p']['lines'][] = '=== OFERTA ' . strtoupper( $lang ) . ' ===';

	$context = new MP_OB_Context(
		array(
			'offer_id'   => null,
			'client'     => array(
				'name'    => 'Klient Testowy ' . strtoupper( $lang ) . ' Sp. z o.o.',
				'email'   => 'pdf-' . $lang . '-' . time() . '@example.test',
				'nip'     => '5252248481',
				'country' => 'PL',
			),
			'items'      => array(
				array(
					'product_id' => (int) $produkty[0],
					'qty'        => 2,
				),
				array(
					'product_id' => (int) $produkty[1],
					'qty'        => 1,
				),
			),
			'wariant'    => 'standard',
			'lang'       => $lang,
			'request_id' => wp_generate_uuid4(),
		)
	);

	$wynik = MP_OB_Pipeline_Factory::make()->run( $context );

	$bledy = '';
	if ( ! $wynik->is_ok() ) {
		$bledy = $wynik->get_code() . ': ' . wp_json_encode( $wynik->get_errors() );
	}
	mp_pdf_ok( $wynik->is_ok(), 'pipeline zbudowal oferte ' . strtoupper( $lang ), $bledy );

	if ( ! $wynik->is_ok() ) {
		continue;
	}

	$numer = (string) $context->get( 'offer_number', '' );
	mp_pdf_ok( '' !== $numer, 'oferta dostala numer', $numer );
	mp_pdf_ok( 1 === preg_match( '#^OF/\d{4}/\d{6}$#', $numer ), 'numer w formacie OF/RRRR/NNNNNN', $numer );

	$oferta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . MP_Offer_Builder_DB::offers_table() . " WHERE offer_number = %s", $numer ), ARRAY_A ); // phpcs:ignore
	mp_pdf_ok( is_array( $oferta ), 'oferta zapisana w BD-2' );
	mp_pdf_ok( is_array( $oferta ) && $lang === (string) $oferta['lang'], 'jezyk oferty zapisany jako ' . $lang );
	mp_pdf_ok( is_array( $oferta ) && '' !== (string) $oferta['pdf_path'], 'sciezka PDF zapisana' );

	// Kwoty: 2 x pierwszy produkt + 1 x drugi, VAT 23% od netto.
	$netto  = (int) $oferta['net_grosze'];
	$vat    = (int) $oferta['vat_grosze'];
	$brutto = (int) $oferta['gross_grosze'];
	mp_pdf_ok( $netto > 0, 'netto wieksze od zera', (string) $netto );
	mp_pdf_ok( $brutto === $netto + $vat, 'brutto = netto + VAT', $netto . ' + ' . $vat . ' = ' . $brutto );
	mp_pdf_ok( (int) round( $netto * 0.23 ) === $vat, 'VAT to 23% netto', 'oczekiwano ' . (int) round( $netto * 0.23 ) . ', jest ' . $vat );

	$plik = MP_Offer_Builder_Storage::private_dir() . '/' . basename( (string) $oferta['pdf_path'] );
	if ( ! file_exists( $plik ) ) {
		// Sciezka w bazie bywa wzgledna wobec katalogu prywatnego.
		$plik = trailingslashit( dirname( MP_Offer_Builder_Storage::private_dir() ) ) . ltrim( (string) $oferta['pdf_path'], '/' );
	}
	mp_pdf_ok( file_exists( $plik ), 'plik PDF istnieje na dysku', $plik );
	mp_pdf_ok( file_exists( $plik ) && filesize( $plik ) > 1000, 'plik PDF nie jest pusty', file_exists( $plik ) ? filesize( $plik ) . ' B' : '' );

	if ( file_exists( $plik ) ) {
		$sciezki[] = $lang . '|' . $numer . '|' . number_format( $brutto / 100, 2, ',', ' ' ) . '|' . $plik;
	}
}

$out = is_dir( '/scr' ) ? '/scr/mp-p2-pdf-sciezki.txt' : '/tmp/mp-p2-pdf-sciezki.txt';
file_put_contents( $out, implode( "\n", $sciezki ) . "\n" ); // phpcs:ignore

mp_pdf_dump();
