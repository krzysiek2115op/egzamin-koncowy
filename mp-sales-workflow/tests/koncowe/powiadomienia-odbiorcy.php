<?php
/**
 * Test na ZYWYM WordPressie: niedostarczalny adres WEWNETRZNY nie blokuje procesu.
 *
 * Uruchamianie: wp eval-file tests/koncowe/powiadomienia-odbiorcy.php
 *
 * Do wersji 1.1.1 krytyk 7.2 odrzucal CALA koperte, gdy ktorykolwiek odbiorca
 * mial niedostarczalny adres — takze wtedy, gdy chodzilo o handlowca. Skutek byl
 * nieproporcjonalny: JEDNO konto bez e-maila blokowalo przyjmowanie leadow.
 * Klient wypelnial formularz, po jego stronie wszystko bylo poprawne, a lead nie
 * powstawal, bo ktos w firmie mial niedokonczony profil.
 *
 * Od 1.2.0 rozroznienie idzie po tym, CZYJ kontakt zawodzi:
 *  - klient bez adresu  -> odmowa (zdarzenie „wyslij oferte" traci sens),
 *  - pracownik bez adresu -> pominiecie tego powiadomienia + wpis w dzienniku.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_o'] = array(
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
function mo_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_o']['pass'];
		$GLOBALS['mp_o']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_o']['fail'];
	$GLOBALS['mp_o']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function mo_dump() {
	if ( empty( $GLOBALS['mp_o']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_o'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-p3-odbiorcy.txt' : '/tmp/mp-p3-odbiorcy.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_o']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'mo_dump' );

global $wpdb;

$flow_t = MP_Sales_Workflow_DB::flow_table();
$noti_t = MP_Sales_Workflow_DB::notifications_table();
$act_t  = MP_Sales_Workflow_DB::activity_table();

$seria = (int) substr( (string) time(), -6 );
$lead1 = 800000 + $seria * 2;
$lead2 = $lead1 + 1;

$GLOBALS['mp_o']['lines'][] = '=== PRZYGOTOWANIE: jedyny handlowiec BEZ adresu e-mail ===';

// Zostawiamy dokladnie jednego handlowca, zeby Dzial 4 nie mial wyboru.
foreach ( get_users(
	array(
		'role'   => MP_SW_Roles::ROLE_SALESMAN,
		'fields' => 'ID',
	)
) as $stary ) {
	wp_delete_user( (int) $stary );
}

$uid = (int) wp_insert_user(
	array(
		'user_login' => 'bez_adresu_' . $seria,
		'user_pass'  => wp_generate_password( 20 ),
		'user_email' => 'tymczasowy' . $seria . '@example.test',
		'role'       => MP_SW_Roles::ROLE_SALESMAN,
	)
);
mo_ok( $uid > 0, 'konto handlowca utworzone', is_wp_error( $uid ) ? $uid->get_error_message() : (string) $uid );

update_user_meta( $uid, MP_SW_D2_Reader::META_COUNTRY, 'PL' );
update_user_meta( $uid, MP_SW_D2_Reader::META_LANGS, 'pl,en' );
update_user_meta( $uid, MP_SW_D2_Reader::META_TEAM, 'zespol' );

// Pusty adres WYLACZNIE przez baze: wp_insert_user/wp_update_user go nie przyjmie,
// a taki wlasnie stan spotyka sie na produkcji (konto zalozone importem albo
// przez wtyczke, ktora adresu nie wymagala).
$wpdb->update( $wpdb->users, array( 'user_email' => '' ), array( 'ID' => $uid ) ); // phpcs:ignore
clean_user_cache( $uid );

$sprawdz = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_email FROM {$wpdb->users} WHERE ID = %d", $uid ) ); // phpcs:ignore
mo_ok( '' === $sprawdz, 'handlowiec NIE ma adresu e-mail (stan jak po imporcie kont)', '[' . $sprawdz . ']' );

$GLOBALS['mp_o']['lines'][] = '';
$GLOBALS['mp_o']['lines'][] = '=== A: lead mimo to zostaje przyjety ===';

$przed_powiadomien = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$noti_t}" ); // phpcs:ignore

do_action(
	'mp_lead_created',
	$lead1,
	array(
		'lead_id'      => $lead1,
		'company_name' => 'Firma Bez Handlowca Sp. z o.o.',
		'email'        => 'klient' . $seria . '@example.test',
		'country'      => 'PL',
		'segment'      => 'B2B',
	)
);

$proces = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE lead_id = %d", $lead1 ), ARRAY_A ); // phpcs:ignore
mo_ok( is_array( $proces ), 'A1: proces sprzedazowy POWSTAL mimo handlowca bez adresu' );
mo_ok( is_array( $proces ) && $uid === (int) $proces['assigned_user_id'], 'A2: lead zostal przypisany temu handlowcowi' );

$flow_id = is_array( $proces ) ? (int) $proces['id'] : 0;

mo_ok(
	$przed_powiadomien === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$noti_t}" ), // phpcs:ignore
	'A3: NIC nie trafilo do kolejki na niedostarczalny adres'
);

$wpis = $wpdb->get_row( // phpcs:ignore
	$wpdb->prepare( "SELECT * FROM {$act_t} WHERE flow_id = %d AND action = %s", $flow_id, MP_SW_D8_Writer::LOG_NOTIFICATION_SKIPPED ),
	ARRAY_A
);
mo_ok( is_array( $wpis ), 'A4: pominiecie ODNOTOWANE w dzienniku (kryt. 5.5)' );
mo_ok( is_array( $wpis ) && false !== strpos( (string) $wpis['new_value'], 'brak_adresu' ), 'A5: dziennik podaje POWOD pominiecia', is_array( $wpis ) ? (string) $wpis['new_value'] : '' );
mo_ok( is_array( $wpis ) && false !== strpos( (string) $wpis['new_value'], (string) $uid ), 'A6: dziennik wskazuje, KTO nie dostal wiadomosci' );

// RODO: dziennik nie moze niesc adresu — takze w tym nowym wpisie.
$blob = is_array( $wpis ) ? wp_json_encode( $wpis ) : '';
mo_ok( 0 === preg_match( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', (string) $blob ), 'A7: wpis dziennika NIE zawiera adresu e-mail (RODO)', (string) $blob );

$GLOBALS['mp_o']['lines'][] = '';
$GLOBALS['mp_o']['lines'][] = '=== B: brak adresu KLIENTA nadal blokuje wysylke ===';

/*
 * Celujemy wprost w krytyka 7.2, a nie w pelny przebieg, bo lead z bledna poczta
 * klienta NIE DOJDZIE do Dzialu 7 — odrzuca go wczesniej kontrakt koperty w
 * Dziale 1 (`invalid_event`, pole client.email). Adres klienta uzyty do wysylki
 * i tak czytany jest z BAZY, nie z koperty, wiec to wlasnie ten wariant trzeba
 * sprawdzic: wiadomosc juz zbudowana, z niedostarczalnym adresem odbiorcy.
 */
$k72 = new MP_SW_D7_Critic_Empty_Fields( 'K7.2', 'puste-pola', 'test' );

$wiadomosc_klienta = array(
	'audience'         => MP_SW_D7_Notifier::AUDIENCE_CLIENT,
	'template'         => MP_SW_Templates::TPL_OFFER_SENT,
	'template_version' => '1.0.0',
	'lang'             => 'pl',
	'recipient'        => 'to-nie-jest-adres',
	'recipient_name'   => 'Klient',
	'user_id'          => 0,
	'subject'          => 'Oferta',
	'body'             => 'Tresc oferty.',
	// Adres dokumentu MUSI byc niepusty, inaczej krytyk odrzuci wiadomosc
	// wczesniej (offer_document_missing) i nie dojdziemy do kontroli odbiorcy.
	'link'             => 'https://example.test/oferta?' . MP_SW_Download::ARG_SIGNATURE . '=abc',
	'attachments'      => array(),
	'header_attempt'   => false,
	'reason'           => MP_SW_D5_Machine::EFFECT_NOTIFY_CLIENT,
);

$ocena_klient = $k72->review( MP_SW_Result::ok( array( 'messages' => array( $wiadomosc_klienta ) ) ), new MP_SW_Context( array() ) );
mo_ok( ! $ocena_klient->is_ok(), 'B0a: wiadomosc do KLIENTA z niedostarczalnym adresem = odmowa', $ocena_klient->is_ok() ? 'przeszla' : $ocena_klient->get_code() );
mo_ok( 'invalid_recipient' === $ocena_klient->get_code(), 'B0b: odmowa ma kod invalid_recipient', $ocena_klient->get_code() );

// Ta sama wiadomosc, ale do HANDLOWCA — krytyk nie ma prawa jej odrzucic, bo
// takie przypadki odsiewa juz Agent 7.2 i nigdy tu nie docieraja. Gdyby ktos
// kiedys usunal ten filtr, ta asercja zacznie failowac razem z A3.
$wiadomosc_handlowca                = $wiadomosc_klienta;
$wiadomosc_handlowca['audience']    = MP_SW_D7_Notifier::AUDIENCE_SALESMAN;
$wiadomosc_handlowca['template']    = MP_SW_Templates::TPL_FOLLOWUP_DUE;
$wiadomosc_handlowca['recipient']   = 'handlowiec' . $seria . '@example.test';
$wiadomosc_handlowca['body']        = 'Przypomnienie o kontakcie.';
$wiadomosc_handlowca['reason']      = 'followup_due';

$ocena_handlowca = $k72->review( MP_SW_Result::ok( array( 'messages' => array( $wiadomosc_handlowca ) ) ), new MP_SW_Context( array() ) );
mo_ok( $ocena_handlowca->is_ok(), 'B0c: poprawna wiadomosc do handlowca przechodzi bez zastrzezen', $ocena_handlowca->is_ok() ? '' : $ocena_handlowca->get_code() );

// Klient z bledna poczta nie wchodzi do procesu W OGOLE — kontrakt koperty
// (Dzial 1) odrzuca go przed jakimkolwiek zapisem. Kontakt do klienta pozostaje
// wiec wymagany na kazdym poziomie, mimo poluzowania po stronie wewnetrznej.
$wpdb->update( $wpdb->users, array( 'user_email' => 'handlowiec' . $seria . '@example.test' ), array( 'ID' => $uid ) ); // phpcs:ignore
clean_user_cache( $uid );

$przed_procesow = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flow_t}" ); // phpcs:ignore

do_action(
	'mp_lead_created',
	$lead2,
	array(
		'lead_id'      => $lead2,
		'company_name' => 'Firma Z Blednym Adresem Sp. z o.o.',
		'email'        => 'to-nie-jest-adres',
		'country'      => 'PL',
		'segment'      => 'B2B',
	)
);

mo_ok(
	$przed_procesow === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flow_t}" ), // phpcs:ignore
	'B1: lead z niepoprawnym adresem KLIENTA nie zaklada procesu (kontrakt Dzialu 1)'
);
mo_ok(
	null === $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$flow_t} WHERE lead_id = %d", $lead2 ), ARRAY_A ), // phpcs:ignore
	'B2: dla tego leada nie powstal zaden wiersz procesu'
);

mo_dump();
