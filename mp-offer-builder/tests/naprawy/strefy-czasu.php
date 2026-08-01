<?php
/**
 * P2-G16 — dwie strefy czasu w jednej kolumnie i rok numeru liczony w UTC.
 *
 * Uruchamianie: wp eval-file tests/naprawy/strefy-czasu.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G16  updated_at zapisywane w dwoch strefach, rok numeru w UTC
 *
 * Kolumne `updated_at` zapisywaly DWA miejsca w DWOCH roznych strefach:
 * Dzial 10 przez `gmdate()` (UTC), a zatwierdzenie oferty przez
 * `current_time( 'mysql' )` (strefa witryny). Lista ofert sortuje domyslnie
 * wlasnie po tej kolumnie i wyswietla ja bez przeliczania, wiec w strefie innej
 * niz UTC oferta zatwierdzona pozniej potrafila wyladowac na liscie WYZEJ albo
 * NIZEJ, niz wynika z kolejnosci zdarzen — a pokazana godzina byla raz lokalna,
 * raz uniwersalna.
 *
 * Rozstrzygniecie: w bazie trzymamy UTC (jak `gmdate` i jak wtyczka 3), a na
 * ekran przeliczamy do strefy witryny. To dwie rozne rzeczy i musza byc
 * rozdzielone: wartosc maszynowa ma byc porownywalna, a czlowiek ma widziec
 * swoja godzine.
 *
 * Osobna sprawa jest ROK w numerze oferty. Numeracja jest wartoscia
 * KALENDARZOWA firmy, nie znacznikiem maszynowym: Dzialy 2 i 8 licza rok przez
 * `current_time( 'Y' )`, a sciezka ponowienia po kolizji w Dziale 10 liczyla go
 * przez `gmdate( 'Y' )`. 1 stycznia miedzy polnoca a 1:00 (czas polski)
 * ponowienie budowalo numer z ROKU POPRZEDNIEGO — czyli numer spoza biezacej
 * serii na dokumencie handlowym.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_sc'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegol.
 * @return bool
 */
function sc_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_sc']['pass'];
		$GLOBALS['mp_sc']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_sc']['fail'];
	$GLOBALS['mp_sc']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

$offers_t    = MP_Offer_Builder_DB::offers_table();
$stara_tz    = get_option( 'timezone_string' );
$stary_offset = get_option( 'gmt_offset' );
$seria       = (int) substr( (string) time(), -6 );
$handlowiec  = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$plik        = MP_Offer_Builder_Storage::private_dir() . '/mp-test-sc-' . $seria . '.pdf';
$posprzatac  = array();

file_put_contents( $plik, '%PDF-1.4 test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$GLOBALS['mp_sc_tz']     = $stara_tz;
$GLOBALS['mp_sc_offset'] = $stary_offset;

/*
 * Strefa wraca na miejsce TAKZE po bledzie krytycznym. Test podmienia globalne
 * ustawienie witryny — zostawione po fatalu zmienia wyniki wszystkich kolejnych
 * testow i wyglada potem jak regresja, ktorej nie ma.
 */
register_shutdown_function(
	function () {
		update_option( 'timezone_string', $GLOBALS['mp_sc_tz'] );
		update_option( 'gmt_offset', $GLOBALS['mp_sc_offset'] );
	}
);

/*
 * Strefa z niezerowym przesunieciem — w UTC roznica miedzy zapisami jest
 * NIEWIDOCZNA i test przechodzilby takze na kodzie z bledem.
 */
update_option( 'timezone_string', 'Europe/Warsaw' );

$przesuniecie = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

sc_ok(
	0 !== $przesuniecie,
	'warunek scenariusza: witryna jest w strefie innej niz UTC',
	'przesuniecie=' . $przesuniecie . 's'
);

$GLOBALS['mp_sc']['lines'][] = '=== A. jedna kolumna, jedna strefa ===';

$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$offers_t,
	array(
		'lead_id'      => 0,
		'offer_number' => 'OF/TEST/' . $seria . '/SC',
		'version'      => 1,
		'status'       => MP_Offer_Builder_DB::STATUS_DRAFT,
		'client_email' => 'sc@example.test',
		'client_name'  => 'Firma Strefowa',
		'gross_grosze' => 123000,
		'currency'     => 'PLN',
		'pdf_path'     => $plik,
		'lock_version' => 1,
		'created_by'   => $handlowiec,
		'created_at'   => current_time( 'mysql', true ),
		'updated_at'   => current_time( 'mysql', true ),
	)
);
$id_oferty    = (int) $wpdb->insert_id;
$posprzatac[] = $id_oferty;

MP_Offer_Builder_Approval::approve( $id_oferty, $handlowiec );

$zapisane = (string) $wpdb->get_var( $wpdb->prepare( "SELECT updated_at FROM {$offers_t} WHERE id = %d", $id_oferty ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$roznica_od_utc   = abs( strtotime( $zapisane . ' UTC' ) - time() );
$roznica_od_lokal = abs( strtotime( $zapisane . ' UTC' ) - ( time() + $przesuniecie ) );

sc_ok(
	$roznica_od_utc < 120,
	'zatwierdzenie zapisuje updated_at w UTC — tak samo jak Dzial 10',
	'zapisane=' . $zapisane . ' roznica_od_UTC=' . $roznica_od_utc . 's'
);
sc_ok(
	$roznica_od_lokal > 600,
	'i NIE w czasie lokalnym, ktory rozjezdzalby sortowanie listy',
	'zapisane=' . $zapisane . ' roznica_od_lokalnego=' . $roznica_od_lokal . 's'
);

$GLOBALS['mp_sc']['lines'][] = '';
$GLOBALS['mp_sc']['lines'][] = '=== B. czlowiek widzi SWOJA godzine ===';

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if ( ! class_exists( 'MP_Offer_Builder_List_Table' ) ) {
	require_once dirname( __DIR__ ) . '/../includes/admin/class-mp-offer-builder-list-table.php';
}

$tabela = new MP_Offer_Builder_List_Table();
$wiersz = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE id = %d", $id_oferty ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$wyswietlone = method_exists( $tabela, 'column_default' ) ? (string) $tabela->column_default( $wiersz, 'updated_at' ) : '';
$oczekiwana  = get_date_from_gmt( $zapisane, 'Y-m-d H:i' );

sc_ok(
	'' !== trim( $wyswietlone ),
	'kolumna „Zaktualizowano" cos pokazuje'
);
sc_ok(
	false !== strpos( $wyswietlone, $oczekiwana ),
	'i jest to godzina LOKALNA, nie surowy UTC z bazy',
	'pokazane=' . $wyswietlone . ' oczekiwane=' . $oczekiwana . ' w bazie=' . $zapisane
);
sc_ok(
	false === strpos( $wyswietlone, substr( $zapisane, 11, 5 ) ) || $oczekiwana === substr( $zapisane, 0, 16 ),
	'surowa godzina UTC nie trafia na ekran',
	'pokazane=' . $wyswietlone . ' w bazie=' . $zapisane
);

$GLOBALS['mp_sc']['lines'][] = '';
$GLOBALS['mp_sc']['lines'][] = '=== C. rok numeru oferty liczony po kalendarzu firmy ===';

/*
 * Numeracja jest wartoscia kalendarzowa, nie znacznikiem maszynowym: numer
 * „OF/2027/000001" wystawiony 1 stycznia o 00:30 czasu polskiego ma nalezec do
 * roku 2027, a nie 2026. Sprawdzamy ZRODLO, bo przelom roku zdarza sie raz na
 * rok i behawioralnie nie da sie go wywolac.
 */
/**
 * Zwraca SAM KOD pliku, bez komentarzy.
 *
 * Wyszukiwanie po surowej tresci pliku dawaloby falszywy alarm na komentarzu,
 * ktory o bledzie tylko OPOWIADA — a opis naprawy zwykle cytuje to, co bylo zle.
 * Tokenizer odsiewa komentarze i docbloki, wiec asercja mowi o kodzie.
 *
 * @param string $sciezka Sciezka pliku.
 * @return string
 */
function sc_kod( $sciezka ) {
	$tresc = (string) file_get_contents( $sciezka ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$kod   = '';

	foreach ( token_get_all( $tresc ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$kod .= is_array( $token ) ? $token[1] : $token;
	}

	return $kod;
}

$plik_d10 = dirname( __DIR__ ) . '/../includes/pipeline/departments/class-mp-ob-department-10.php';
$zrodlo   = sc_kod( $plik_d10 );

sc_ok(
	'' !== $zrodlo,
	'zrodlo Dzialu 10 odczytane',
	'plik=' . $plik_d10
);
sc_ok(
	false === strpos( $zrodlo, "gmdate( 'Y' )" ),
	'sciezka ponowienia NIE liczy roku numeru w UTC',
	'znaleziono gmdate( \'Y\' ) w Dziale 10'
);
sc_ok(
	false !== strpos( $zrodlo, "current_time( 'Y' )" ),
	'liczy go tak samo jak Dzialy 2 i 8 — czasem witryny'
);

$zrodlo_d2 = sc_kod( dirname( __DIR__ ) . '/../includes/pipeline/departments/class-mp-ob-department-02.php' );
$zrodlo_d8 = sc_kod( dirname( __DIR__ ) . '/../includes/pipeline/departments/class-mp-ob-department-08.php' );

sc_ok(
	false !== strpos( $zrodlo_d2, "current_time( 'Y' )" ) && false !== strpos( $zrodlo_d8, "current_time( 'Y' )" ),
	'KONTR-ASERCJA: Dzialy 2 i 8 nadal licza rok czasem witryny (nie odwrocilismy zaleznosci)'
);

// Sprzatanie: strefa i wiersze testowe.
update_option( 'timezone_string', $stara_tz );
update_option( 'gmt_offset', $stary_offset );

foreach ( $posprzatac as $id ) {
	$wpdb->delete( $offers_t, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( MP_Offer_Builder_DB::activity_log_table(), array( 'offer_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if ( file_exists( $plik ) ) {
	wp_delete_file( $plik );
}

echo implode( "\n", $GLOBALS['mp_sc']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_sc']['pass'], $GLOBALS['mp_sc']['fail'] );
echo ( 0 === $GLOBALS['mp_sc']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
