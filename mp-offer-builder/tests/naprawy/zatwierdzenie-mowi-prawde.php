<?php
/**
 * P2-G14 — zatwierdzenie oferty wysylalo stare dane i obiecywalo cudza prace.
 *
 * Uruchamianie: wp eval-file tests/naprawy/zatwierdzenie-mowi-prawde.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G14  Payload zdarzenia budowany z odczytu SPRZED zapisu
 *   - P2-G15  Komunikat sukcesu twierdzil, ze modul sprzedazowy przejmuje wysylke
 *
 * P2-G14. `approve()` czytalo wiersz oferty na poczatku, a payload zdarzenia
 * `mp_offer_approved` budowalo z tego samego, STAREGO odczytu — juz po UPDATE.
 * Warunek `WHERE status = 'draft'` pilnuje statusu, ale nie pilnuje reszty
 * wiersza: rownolegla korekta z Dzialu 10 mogla w tym czasie zmienic kwote,
 * numer oferty, wersje albo nazwe klienta i zostawic status na `draft`.
 * Zatwierdzenie konczylo sie wtedy powodzeniem, a do wtyczki 3 i wtyczki 1 szly
 * wartosci nieaktualne — proces sprzedazowy wysylal klientowi oferte opisana
 * liczbami, ktorych nie ma juz w bazie.
 *
 * P2-G15. Komunikat „Oferta zatwierdzona. Modul sprzedazowy przejmuje wysylke
 * do klienta" byl twierdzeniem o CUDZEJ wtyczce, ktorego kod nie sprawdzal.
 * Wtyczka 2 potrafi zbudowac oferte sama (ze zdarzenia `mp_lead_created`), wiec
 * wtyczka 3 bywa wylaczona albo w ogole niezainstalowana. `do_action()` trafial
 * wtedy w pustke, pracownik czytal zielony komunikat i uznawal sprawe za
 * zamknieta, a oferta nigdy nie wychodzila do klienta — i nie byla juz `draft`,
 * wiec znikala tez z edycji.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_zm'] = array(
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
function zm_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_zm']['pass'];
		$GLOBALS['mp_zm']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_zm']['fail'];
	$GLOBALS['mp_zm']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

$offers_t   = MP_Offer_Builder_DB::offers_table();
$seria      = (int) substr( (string) time(), -6 );
$handlowiec = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$plik       = MP_Offer_Builder_Storage::private_dir() . '/mp-test-zm-' . $seria . '.pdf';
$posprzatac = array();

file_put_contents( $plik, '%PDF-1.4 test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

/**
 * Zaklada szkic gotowy do zatwierdzenia.
 *
 * @param string $numer Numer oferty.
 * @param string $plik  Sciezka dokumentu.
 * @param int    $kto   Wlasciciel.
 * @return int
 */
function zm_szkic( $numer, $plik, $kto ) {
	global $wpdb;

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		MP_Offer_Builder_DB::offers_table(),
		array(
			'lead_id'      => 0,
			'offer_number' => $numer,
			'version'      => 1,
			'status'       => MP_Offer_Builder_DB::STATUS_DRAFT,
			'client_email' => 'zm@example.test',
			'client_name'  => 'Firma Przed Korekta',
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

$GLOBALS['mp_zm']['lines'][] = '=== A. rownolegla korekta miedzy odczytem a zapisem ===';

$id_korekta   = zm_szkic( 'OF/TEST/' . $seria . '/ZM1', $plik, $handlowiec );
$posprzatac[] = $id_korekta;

$GLOBALS['mp_zm_payload'] = null;
$przechwyt                = function ( $offer_id, $payload ) {
	$GLOBALS['mp_zm_payload'] = (array) $payload;
};
add_action( MP_Offer_Builder_Approval::HOOK, $przechwyt, 10, 2 );

/*
 * Korekta TUZ PRZED UPDATE-em zatwierdzenia — dokladnie to robi rownolegly
 * zapis Dzialu 10, tylko bez losowosci. Status zostaje `draft`, wiec UPDATE
 * zatwierdzenia nadal trafia.
 */
$GLOBALS['mp_zm_zrobione'] = false;
$korekta                   = function ( $zapytanie ) use ( $offers_t, $id_korekta ) {
	if (
		! $GLOBALS['mp_zm_zrobione']
		&& false !== strpos( $zapytanie, 'UPDATE' )
		&& false !== strpos( $zapytanie, $offers_t )
		&& false !== strpos( $zapytanie, "'draft'" )
		&& false !== strpos( $zapytanie, (string) $id_korekta )
	) {
		$GLOBALS['mp_zm_zrobione'] = true;

		$GLOBALS['wpdb']->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$GLOBALS['wpdb']->prepare(
				"UPDATE {$offers_t} SET gross_grosze = %d, client_name = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				999000,
				'Firma Po Korekcie',
				(int) $id_korekta
			)
		);
	}

	return $zapytanie;
};

add_filter( 'query', $korekta );
$wynik_korekta = MP_Offer_Builder_Approval::approve( $id_korekta, $handlowiec );
remove_filter( 'query', $korekta );
remove_action( MP_Offer_Builder_Approval::HOOK, $przechwyt, 10 );

$payload = is_array( $GLOBALS['mp_zm_payload'] ) ? $GLOBALS['mp_zm_payload'] : array();

zm_ok( $GLOBALS['mp_zm_zrobione'], 'warunek scenariusza: korekta weszla przed zapisem' );
zm_ok( true === $wynik_korekta, 'zatwierdzenie sie powiodlo', 'wynik=' . ( is_wp_error( $wynik_korekta ) ? $wynik_korekta->get_error_code() : var_export( $wynik_korekta, true ) ) );
zm_ok( ! empty( $payload ), 'zdarzenie mp_offer_approved zostalo wystawione' );
zm_ok(
	! empty( $payload ) && 999000 === (int) $payload['gross_grosze'],
	'payload niesie kwote PO korekcie, nie sprzed niej',
	'gross_grosze=' . ( isset( $payload['gross_grosze'] ) ? $payload['gross_grosze'] : '?' )
);
zm_ok(
	! empty( $payload ) && 'Firma Po Korekcie' === (string) $payload['client_name'],
	'payload niesie nazwe klienta PO korekcie',
	'client_name=' . ( isset( $payload['client_name'] ) ? $payload['client_name'] : '?' )
);

$GLOBALS['mp_zm']['lines'][] = '';
$GLOBALS['mp_zm']['lines'][] = '=== B. KONTR-ASERCJA: bez korekty payload zostaje bez zmian ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na wysylaniu czegokolwiek innego niz
 * wiersz oferty. Zwykle zatwierdzenie musi dawac dokladnie te dane, ktore sa
 * w bazie.
 */
$id_zwykly    = zm_szkic( 'OF/TEST/' . $seria . '/ZM2', $plik, $handlowiec );
$posprzatac[] = $id_zwykly;

$GLOBALS['mp_zm_payload'] = null;
add_action( MP_Offer_Builder_Approval::HOOK, $przechwyt, 10, 2 );
MP_Offer_Builder_Approval::approve( $id_zwykly, $handlowiec );
remove_action( MP_Offer_Builder_Approval::HOOK, $przechwyt, 10 );

$payload_zwykly = is_array( $GLOBALS['mp_zm_payload'] ) ? $GLOBALS['mp_zm_payload'] : array();

zm_ok(
	! empty( $payload_zwykly ) && 123000 === (int) $payload_zwykly['gross_grosze'],
	'kwota z bazy idzie do zdarzenia bez zmian',
	'gross_grosze=' . ( isset( $payload_zwykly['gross_grosze'] ) ? $payload_zwykly['gross_grosze'] : '?' )
);
zm_ok(
	! empty( $payload_zwykly ) && 'Firma Przed Korekta' === (string) $payload_zwykly['client_name'],
	'nazwa klienta z bazy idzie do zdarzenia bez zmian'
);
zm_ok(
	! empty( $payload_zwykly ) && MP_Offer_Builder_DB::STATUS_APPROVED === (string) $payload_zwykly['status'],
	'status w payloadzie to approved'
);

$GLOBALS['mp_zm']['lines'][] = '';
$GLOBALS['mp_zm']['lines'][] = '=== C. komunikat sukcesu nie obiecuje cudzej pracy ===';

/**
 * Zwraca HTML komunikatu dla podanego kodu.
 *
 * @param string $kod Kod w parametrze GET.
 * @return string
 */
function zm_komunikat( $kod ) {
	// Od P2-G17 wynik akcji nie jedzie w adresie, tylko siedzi w transiencie
	// zwiazanym z uzytkownikiem — inaczej sam link z zakladki pokazywalby
	// „Oferta zatwierdzona" bez zadnej operacji. Tresci komunikatow, ktorych
	// pilnuje ten test, nie zmienily sie: zmienil sie sposob ich dostarczenia.
	// Komunikat nalezy do konkretnego uzytkownika, wiec ktos musi byc zalogowany.
	// Ten test nie loguje nikogo — bierzemy pierwszego administratora witryny.
	$uzytkownik = get_current_user_id();

	if ( $uzytkownik <= 0 ) {
		$admini     = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		$uzytkownik = empty( $admini ) ? 0 : (int) $admini[0];
		wp_set_current_user( $uzytkownik );
	}

	MP_Offer_Builder_Approval::remember_notice( $kod, '', $uzytkownik );

	ob_start();
	MP_Offer_Builder_Approval::notice();
	$html = (string) ob_get_clean();

	MP_Offer_Builder_Approval::forget_notice( $uzytkownik );

	return $html;
}

// Zapamietujemy sluchaczy, zeby oddac je z powrotem — inne testy w tym samym
// przebiegu na nich polegaja.
global $wp_filter;
$sluchacze = isset( $wp_filter[ MP_Offer_Builder_Approval::HOOK ] ) ? $wp_filter[ MP_Offer_Builder_Approval::HOOK ] : null;

remove_all_actions( MP_Offer_Builder_Approval::HOOK );
$html_bez_odbiorcy = zm_komunikat( 'ok' );

zm_ok(
	'' !== trim( $html_bez_odbiorcy ),
	'komunikat sukcesu nadal sie pokazuje'
);
zm_ok(
	false === mb_stripos( $html_bez_odbiorcy, 'przejmuje wysyłkę' ),
	'bez zadnego odbiorcy zdarzenia NIE obiecuje, ze modul sprzedazowy przejmuje wysylke',
	'html=' . wp_strip_all_tags( $html_bez_odbiorcy )
);
zm_ok(
	false !== mb_stripos( $html_bez_odbiorcy, 'ręcznie' ) || false !== mb_stripos( $html_bez_odbiorcy, 'nasłuchuje' ),
	'zamiast tego mowi pracownikowi, co ma zrobic',
	'html=' . wp_strip_all_tags( $html_bez_odbiorcy )
);

/*
 * KONTR-ASERCJA. Gdy odbiorca JEST, komunikat ma brzmiec jak dawniej — inaczej
 * „naprawa" polegalaby na straszeniu pracownika przy poprawnie dzialajacym
 * wdrozeniu z trzema wtyczkami.
 */
add_action( MP_Offer_Builder_Approval::HOOK, '__return_true' );
$html_z_odbiorca = zm_komunikat( 'ok' );

zm_ok(
	false !== mb_stripos( $html_z_odbiorca, 'przejmuje wysyłkę' ),
	'z podpietym modulem komunikat brzmi jak dawniej',
	'html=' . wp_strip_all_tags( $html_z_odbiorca )
);

remove_all_actions( MP_Offer_Builder_Approval::HOOK );

if ( null !== $sluchacze ) {
	$wp_filter[ MP_Offer_Builder_Approval::HOOK ] = $sluchacze;
}

foreach ( $posprzatac as $id ) {
	$wpdb->delete( $offers_t, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( MP_Offer_Builder_DB::activity_log_table(), array( 'offer_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if ( file_exists( $plik ) ) {
	wp_delete_file( $plik );
}

echo implode( "\n", $GLOBALS['mp_zm']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_zm']['pass'], $GLOBALS['mp_zm']['fail'] );
echo ( 0 === $GLOBALS['mp_zm']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
