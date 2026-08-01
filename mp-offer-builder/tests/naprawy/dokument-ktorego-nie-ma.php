<?php
/**
 * P2-G8 — oferte bez istniejacego pliku PDF dalo sie zatwierdzic i wyslac.
 *
 * Uruchamianie: wp eval-file tests/naprawy/dokument-ktorego-nie-ma.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G8  pdf_path zostawal w bazie po skasowaniu pliku (RODO)
 *
 * Bramka `no_document` w `approve()` sprawdzala, czy KOLUMNA `pdf_path` jest
 * niepusta. Nie sprawdzala, czy plik pod tym adresem istnieje — a kolumna
 * i dysk potrafia sie rozjechac.
 *
 * Rozjazd nie jest hipotetyczny: `MP_Offer_Builder_Privacy::erase()` kasuje plik
 * PDF na zadanie usuniecia danych (RODO), ale zostawia `pdf_path` bez zmian.
 * Wiersz oferty zostaje — i tak ma byc, to dokument handlowy — tyle ze twierdzi
 * odtad, ze ma plik, ktorego nie ma.
 *
 * Skutki byly dwa i oba widzial czlowiek. Na liscie ofert dalej swiecil sie
 * przycisk pobrania dokumentu, ktory konczyl sie odmowa. A szkic po anonimizacji
 * dawalo sie ZATWIERDZIC: bramka przepuszczala, `mp_offer_approved` szlo do
 * wtyczki 3, ta dostawala polecenie „wyslij oferte klientowi" bez dokumentu
 * i odbijala je kodem MP3-E190 — czyli blad wychodzil o dwa moduly za pozno,
 * a oferta nie byla juz szkicem i znikala z edycji.
 *
 * Naprawa idzie z dwoch stron: `erase()` czysci kolumne razem z plikiem (to
 * przyczyna), a `approve()` sprawdza plik na dysku (to ostatnia linia obrony —
 * plik moze zniknac takze bez udzialu wtyczki).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_dk'] = array(
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
function dk_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_dk']['pass'];
		$GLOBALS['mp_dk']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_dk']['fail'];
	$GLOBALS['mp_dk']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

$offers_t   = MP_Offer_Builder_DB::offers_table();
$seria      = (int) substr( (string) time(), -6 );
$handlowiec = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$katalog    = MP_Offer_Builder_Storage::private_dir();
$posprzatac = array();

/**
 * Zaklada szkic oferty z podanym `pdf_path`.
 *
 * @param string $numer    Numer oferty.
 * @param string $pdf_path Sciezka do dokumentu.
 * @param string $email    Adres klienta.
 * @return int
 */
function dk_szkic( $numer, $pdf_path, $email ) {
	global $wpdb;

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		MP_Offer_Builder_DB::offers_table(),
		array(
			'lead_id'      => 0,
			'offer_number' => $numer,
			'version'      => 1,
			'status'       => MP_Offer_Builder_DB::STATUS_DRAFT,
			'client_email' => $email,
			'client_name'  => 'Firma Testowa PDF',
			'pdf_path'     => $pdf_path,
			'lock_version' => 1,
			'created_by'   => (int) $GLOBALS['mp_dk_handlowiec'],
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		)
	);

	return (int) $wpdb->insert_id;
}

$GLOBALS['mp_dk_handlowiec'] = $handlowiec;

// Licznik zdarzen: bramka ma zatrzymac nie tylko zapis, ale i powiadomienie
// wtyczki 3 — to ono uruchamia wysylke do klienta.
$GLOBALS['mp_dk_zdarzenia'] = 0;
add_action(
	'mp_offer_approved',
	function () {
		++$GLOBALS['mp_dk_zdarzenia'];
	}
);

$GLOBALS['mp_dk']['lines'][] = '=== A. szkic ze sciezka do nieistniejacego pliku ===';

$brakujacy = $katalog . '/mp-test-nie-ma-' . $seria . '.pdf';
$id_bez    = dk_szkic( 'OF/TEST/' . $seria . '/BEZ', $brakujacy, 'bezpliku+' . $seria . '@example.test' );
$posprzatac[] = $id_bez;

dk_ok( $id_bez > 0, 'szkic testowy zalozony', $wpdb->last_error );
dk_ok( ! file_exists( $brakujacy ), 'plik pod sciezka z bazy naprawde nie istnieje' );

$GLOBALS['mp_dk_zdarzenia'] = 0;
$wynik_bez = MP_Offer_Builder_Approval::approve( $id_bez, $handlowiec );

dk_ok(
	is_wp_error( $wynik_bez ),
	'zatwierdzenie odrzucone — nie ma czego wyslac',
	'wynik=' . ( is_wp_error( $wynik_bez ) ? $wynik_bez->get_error_code() : var_export( $wynik_bez, true ) )
);
dk_ok(
	is_wp_error( $wynik_bez ) && 'no_document' === $wynik_bez->get_error_code(),
	'odmowa ma kod no_document',
	'kod=' . ( is_wp_error( $wynik_bez ) ? $wynik_bez->get_error_code() : '-' )
);
dk_ok(
	0 === $GLOBALS['mp_dk_zdarzenia'],
	'zdarzenie mp_offer_approved NIE poszlo do wtyczki 3',
	'wystawien=' . $GLOBALS['mp_dk_zdarzenia']
);

$status_bez = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$offers_t} WHERE id = %d", $id_bez ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

dk_ok(
	MP_Offer_Builder_DB::STATUS_DRAFT === $status_bez,
	'oferta zostaje szkicem, wiec da sie ja dokonczyc',
	'status=' . $status_bez
);

$GLOBALS['mp_dk']['lines'][] = '';
$GLOBALS['mp_dk']['lines'][] = '=== B. KONTR-ASERCJA: oferta z prawdziwym plikiem przechodzi ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na odrzucaniu wszystkiego. Sekcja A
 * przeszlaby, a modul ofertowy przestalby zatwierdzac cokolwiek — czyli
 * wtyczka 3 nigdy nie dostalaby polecenia wysylki.
 */
$istniejacy = $katalog . '/mp-test-jest-' . $seria . '.pdf';
file_put_contents( $istniejacy, '%PDF-1.4 test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$id_z         = dk_szkic( 'OF/TEST/' . $seria . '/Z', $istniejacy, 'zplikiem+' . $seria . '@example.test' );
$posprzatac[] = $id_z;

$GLOBALS['mp_dk_zdarzenia'] = 0;
$wynik_z = MP_Offer_Builder_Approval::approve( $id_z, $handlowiec );

dk_ok(
	true === $wynik_z,
	'oferta z istniejacym dokumentem zatwierdza sie normalnie',
	'wynik=' . ( is_wp_error( $wynik_z ) ? $wynik_z->get_error_code() . ': ' . $wynik_z->get_error_message() : var_export( $wynik_z, true ) )
);
dk_ok(
	1 === $GLOBALS['mp_dk_zdarzenia'],
	'zdarzenie mp_offer_approved poszlo dokladnie raz',
	'wystawien=' . $GLOBALS['mp_dk_zdarzenia']
);

$GLOBALS['mp_dk']['lines'][] = '';
$GLOBALS['mp_dk']['lines'][] = '=== C. anonimizacja RODO czysci kolumne razem z plikiem ===';

$email_rodo = 'rodo-pdf+' . $seria . '@example.test';
$plik_rodo  = $katalog . '/mp-test-rodo-' . $seria . '.pdf';
file_put_contents( $plik_rodo, '%PDF-1.4 rodo' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$id_rodo      = dk_szkic( 'OF/TEST/' . $seria . '/RODO', $plik_rodo, $email_rodo );
$posprzatac[] = $id_rodo;

MP_Offer_Builder_Privacy::erase( $email_rodo, 1 );

$po_rodo = $wpdb->get_row( $wpdb->prepare( "SELECT status, pdf_path FROM {$offers_t} WHERE id = %d", $id_rodo ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

dk_ok(
	! file_exists( $plik_rodo ),
	'plik PDF skasowany na zadanie usuniecia danych'
);
dk_ok(
	is_array( $po_rodo ) && '' === (string) $po_rodo['pdf_path'],
	'kolumna pdf_path wyczyszczona — baza nie twierdzi, ze plik jest',
	'pdf_path=' . ( is_array( $po_rodo ) ? var_export( $po_rodo['pdf_path'], true ) : 'brak wiersza' )
);

/*
 * KONTR-ASERCJA do anonimizacji: wiersz oferty ZOSTAJE. Skasowanie go byloby
 * najprostszym sposobem na przejscie asercji wyzej, a jednoczesnie zniszczeniem
 * dokumentu handlowego, ktory firma musi przechowywac.
 */
dk_ok(
	is_array( $po_rodo ),
	'wiersz oferty zostaje jako dokument handlowy'
);

$GLOBALS['mp_dk_zdarzenia'] = 0;
$wynik_rodo = MP_Offer_Builder_Approval::approve( $id_rodo, $handlowiec );

dk_ok(
	is_wp_error( $wynik_rodo ) && 0 === $GLOBALS['mp_dk_zdarzenia'],
	'oferty po anonimizacji nie da sie zatwierdzic ani wyslac',
	'wynik=' . ( is_wp_error( $wynik_rodo ) ? $wynik_rodo->get_error_code() : var_export( $wynik_rodo, true ) )
		. ' wystawien=' . $GLOBALS['mp_dk_zdarzenia']
);

// Sprzatanie: wiersze i pliki testowe.
foreach ( $posprzatac as $id ) {
	$wpdb->delete( $offers_t, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( MP_Offer_Builder_DB::activity_log_table(), array( 'offer_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

foreach ( array( $istniejacy, $plik_rodo ) as $plik ) {
	if ( file_exists( $plik ) ) {
		wp_delete_file( $plik );
	}
}

echo implode( "\n", $GLOBALS['mp_dk']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_dk']['pass'], $GLOBALS['mp_dk']['fail'] );
echo ( 0 === $GLOBALS['mp_dk']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
