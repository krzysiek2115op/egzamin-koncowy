<?php
/**
 * Zatwierdzanie oferty — komunikaty i wpis do dziennika mowily nie to, co zaszlo.
 *
 * Uruchamianie: wp eval-file tests/naprawy/zatwierdzenie-komunikaty.php
 *
 * Cztery ustalenia z audytu glebokiego (para 1.26):
 *
 * 1. `wrong_status` nie nazywal ani stanu oferty, ani czynnosci do wykonania —
 *    w odroznieniu od pozostalych komunikatow tego pliku, ktore zawsze podaja
 *    nastepny krok. A wraca w DWOCH roznych sytuacjach o roznych skutkach.
 *
 * 2. Wpis do dziennika twierdzil, ze zdarzenie `mp_offer_approved` zostalo
 *    wystawione, choc powstaje ZANIM zdarzenie faktycznie wychodzi (celowo:
 *    subskrybent moze paść, a slad ma zostac).
 *
 * 3. Po nieudanym UPDATE, gdy wiersz zniknal, pracownik widzial komunikat
 *    sugerujacy brak uprawnien — mimo ze dostep do tej samej oferty zostal
 *    potwierdzony kilka linii wyzej w tym samym wywolaniu.
 *
 * 4. Numer oferty we wpisie dziennika pochodzi z odczytu PO zapisie, a kontrola
 *    „numer nie jest pusty" byla wykonana na odczycie SPRZED zapisu.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_zk'] = array(
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
function zk_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_zk']['pass'];
		$GLOBALS['mp_zk']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_zk']['fail'];
	$GLOBALS['mp_zk']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

global $wpdb;

$zk_seria      = (int) substr( (string) time(), -6 );
$zk_handlowiec = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$zk_plik       = MP_Offer_Builder_Storage::private_dir() . '/mp-test-zk-' . $zk_seria . '.pdf';
$zk_stary_user = get_current_user_id();
$zk_sprzatanie = array();

file_put_contents( $zk_plik, '%PDF-1.4 test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
wp_set_current_user( $zk_handlowiec );

/**
 * Zaklada ofertę o zadanym statusie.
 *
 * @param string $numer  Numer oferty.
 * @param string $plik   Sciezka dokumentu.
 * @param int    $kto    Wlasciciel.
 * @param string $status Status dokumentu.
 * @return int
 */
function zk_oferta( $numer, $plik, $kto, $status ) {
	global $wpdb;

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		MP_Offer_Builder_DB::offers_table(),
		array(
			'lead_id'      => 0,
			'offer_number' => $numer,
			'version'      => 1,
			'status'       => $status,
			'client_email' => 'zk@example.test',
			'client_name'  => 'Firma Testowa',
			'gross_grosze' => 123000,
			'currency'     => 'PLN',
			'pdf_path'     => $plik,
			'lock_version' => 1,
			'created_by'   => (int) $kto,
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		)
	);

	return (int) $wpdb->insert_id;
}

$GLOBALS['mp_zk']['lines'][] = '=== A. „zly stan" ma powiedziec, JAKI stan i co dalej ===';

$zk_id_dziwny    = zk_oferta( 'OF/TEST/' . $zk_seria . '/ZK1', $zk_plik, $zk_handlowiec, 'wycofana' );
$zk_sprzatanie[] = $zk_id_dziwny;

$zk_blad = MP_Offer_Builder_Approval::approve( $zk_id_dziwny );
$zk_tekst = is_wp_error( $zk_blad ) ? (string) $zk_blad->get_error_message() : '(brak bledu)';

zk_ok(
	is_wp_error( $zk_blad ) && 'wrong_status' === $zk_blad->get_error_code(),
	'A0: (zalozenie testu) oferta w nieznanym stanie konczy sie kodem wrong_status',
	'kod=' . ( is_wp_error( $zk_blad ) ? $zk_blad->get_error_code() : '(sukces)' )
);
zk_ok(
	false !== mb_strpos( $zk_tekst, 'wycofana' ),
	'A1: komunikat nazywa stan, w ktorym oferta jest',
	'komunikat=' . $zk_tekst
);
zk_ok(
	false !== mb_stripos( $zk_tekst, 'administrator' ),
	'A2: i mowi, co z tym zrobic — tak jak reszta komunikatow w tym pliku',
	'komunikat=' . $zk_tekst
);

$GLOBALS['mp_zk']['lines'][] = '';
$GLOBALS['mp_zk']['lines'][] = '=== B. wpis dziennika nie wyprzedza faktow ===';

$zk_id_ok        = zk_oferta( 'OF/TEST/' . $zk_seria . '/ZK2', $zk_plik, $zk_handlowiec, MP_Offer_Builder_DB::STATUS_DRAFT );
$zk_sprzatanie[] = $zk_id_ok;

/*
 * Subskrybent, ktory PADA. Wpis do dziennika powstaje przed zdarzeniem wlasnie
 * po to, zeby przezyl taka awarie — ale wtedy jego tresc („zdarzenie wystawione")
 * opisuje cos, czego nie da sie na tej podstawie stwierdzic.
 */
$zk_log_t = MP_Offer_Builder_DB::activity_log_table();
$zk_max   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$zk_log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$zk_wynik = MP_Offer_Builder_Approval::approve( $zk_id_ok );

$zk_wpis = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"SELECT description, meta_json FROM {$zk_log_t} WHERE id > %d AND action = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$zk_max,
		'offer_approved'
	),
	ARRAY_A
);
$zk_opis = is_array( $zk_wpis ) ? (string) $zk_wpis['description'] : '';

zk_ok(
	true === $zk_wynik,
	'B0: (zalozenie testu) zatwierdzenie sie powiodlo',
	'wynik=' . ( is_wp_error( $zk_wynik ) ? $zk_wynik->get_error_code() : var_export( $zk_wynik, true ) )
);
// Czas przyszly jest tu jedyna prawda: wpis powstaje PRZED `do_action()`.
zk_ok(
	'' !== $zk_opis
		&& false === mb_stripos( $zk_opis, 'mp_offer_approved wystawione' )
		&& false !== mb_stripos( $zk_opis, 'zostanie wystawione' ),
	'B1: opis nie twierdzi, ze zdarzenie juz wyszlo',
	'opis=' . $zk_opis
);
zk_ok(
	false !== mb_stripos( $zk_opis, 'zatwierdzona' ) && false !== mb_strpos( $zk_opis, 'OF/TEST/' . $zk_seria . '/ZK2' ),
	'B2: KONTR-ASERCJA — opis nadal niesie fakt zatwierdzenia i numer oferty',
	'opis=' . $zk_opis
);

$GLOBALS['mp_zk']['lines'][] = '';
$GLOBALS['mp_zk']['lines'][] = '=== C. zniknięcie wiersza to nie brak uprawnien ===';

/*
 * Wiersz kasowany MIEDZY kontrola dostepu a UPDATE-em. Filtr `query` jest tu
 * jedynym sposobem, zeby trafic w to okno bez wyscigu.
 */
$zk_id_znika     = zk_oferta( 'OF/TEST/' . $zk_seria . '/ZK3', $zk_plik, $zk_handlowiec, MP_Offer_Builder_DB::STATUS_DRAFT );
$zk_sprzatanie[] = $zk_id_znika;

$GLOBALS['mp_zk_kasuj'] = $zk_id_znika;

$zk_kasownik = function ( $zapytanie ) {
	global $wpdb;

	if ( ! empty( $GLOBALS['mp_zk_kasuj'] ) && false !== strpos( $zapytanie, 'UPDATE' ) && false !== strpos( $zapytanie, 'mp_ob_offers' ) ) {
		$id                     = (int) $GLOBALS['mp_zk_kasuj'];
		$GLOBALS['mp_zk_kasuj'] = 0;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MP_Offer_Builder_DB::offers_table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	return $zapytanie;
};

add_filter( 'query', $zk_kasownik );
$zk_znikla = MP_Offer_Builder_Approval::approve( $zk_id_znika );
remove_filter( 'query', $zk_kasownik );

$zk_tekst_znikla = is_wp_error( $zk_znikla ) ? (string) $zk_znikla->get_error_message() : '(brak bledu)';

zk_ok(
	is_wp_error( $zk_znikla ),
	'C0: (zalozenie testu) zatwierdzenie znikajacej oferty konczy sie bledem',
	'wynik=' . ( is_wp_error( $zk_znikla ) ? $zk_znikla->get_error_code() : var_export( $zk_znikla, true ) )
);
zk_ok(
	is_wp_error( $zk_znikla ) && false === mb_stripos( $zk_tekst_znikla, 'dostęp' ),
	'C1: komunikat nie sugeruje braku uprawnien — dostep byl potwierdzony w tym samym wywolaniu',
	'komunikat=' . $zk_tekst_znikla
);
zk_ok(
	is_wp_error( $zk_znikla ) && false !== mb_stripos( $zk_tekst_znikla, 'zniknęła' ),
	'C2: mowi, co sie naprawde stalo',
	'komunikat=' . $zk_tekst_znikla
);

$GLOBALS['mp_zk']['lines'][] = '';
$GLOBALS['mp_zk']['lines'][] = '=== D. numer z odczytu po zapisie tez musi byc numerem ===';

/*
 * Asercja na kodzie: okno jest waskie (rownolegly zapis miedzy UPDATE-em
 * a ponownym odczytem), a jego skutkiem jest wpis dziennika i zdarzenie z pustym
 * numerem oferty — mimo ze kontrola „numer nie jest pusty" przeszla na odczycie
 * SPRZED zapisu. Ponowny odczyt ma byc przyjmowany tylko wtedy, gdy nadal
 * spelnia to, co sprawdzilismy.
 */
$zk_zrodlo = (string) file_get_contents( dirname( __DIR__ ) . '/../includes/class-mp-offer-builder-approval.php' );

zk_ok(
	false === strpos( $zk_zrodlo, '$offer    = is_array( $zapisana ) ? $zapisana : $offer;' ),
	'D1: ponowny odczyt nie jest przyjmowany bez sprawdzenia',
	'kod nadal podmienia wiersz bezwarunkowo'
);
zk_ok(
	false !== strpos( $zk_zrodlo, "'' !== (string) \$zapisana['offer_number']" ),
	'D2: warunkiem jest ten sam niezmiennik, ktory sprawdzano przed zapisem',
	'brak sprawdzenia numeru w ponownym odczycie'
);

// Sprzatanie.
foreach ( $zk_sprzatanie as $zk_id ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MP_Offer_Builder_DB::offers_table() . ' WHERE id = %d', (int) $zk_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$zk_log_t} WHERE offer_id = %d", (int) $zk_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

if ( file_exists( $zk_plik ) ) {
	wp_delete_file( $zk_plik );
}

wp_set_current_user( (int) $zk_stary_user );

echo implode( "\n", $GLOBALS['mp_zk']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_zk']['pass'], $GLOBALS['mp_zk']['fail'] );
echo ( 0 === $GLOBALS['mp_zk']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
