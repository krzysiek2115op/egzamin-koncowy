<?php
/**
 * Grupa B po audycie 31.07.2026 — kontrakt zdarzenia.
 *
 * Uruchamianie: wp eval-file tests/naprawy/grupa-b.php
 *
 * B1 (Dzial 5): `status.change` BEZ pola `to_status` przechodzil galezia
 *     „zdarzenie nie rusza statusu" — z `allowed = true`. Status sie nie
 *     zmienial, a wywolujacy dostawal sukces. Ta galaz nalezy sie wylacznie
 *     zdarzeniom `task.due` i `dashboard.view`.
 * B2 (pochodzenie): `actor.user_id` bralo sie z koperty i NIGDY nie bylo
 *     porownywane z `get_current_user_id()`. Uprawnienia sprawdza
 *     `current_user_can()`, wiec eskalacji nie bylo — ale dziennik moglby
 *     przypisac czyjas zmiane statusu komus innemu. Decyzja klienta z
 *     31.07.2026: TWARDA ZGODNOSC dla zdarzen recznych.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_gb'] = array(
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
function gb_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_gb']['pass'];
		$GLOBALS['mp_gb']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_gb']['fail'];
	$GLOBALS['mp_gb']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function gb_dump() {
	if ( empty( $GLOBALS['mp_gb']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_gb'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-p3-grupa-b.txt' : '/tmp/mp-p3-grupa-b.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_gb']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'gb_dump' );

/**
 * Logowanie z przeladowaniem uprawnien.
 *
 * `wp_set_current_user()` z tym samym id nie przeladowuje obiektu uzytkownika,
 * wiec zmiane roli trzeba wymusic przejsciem przez goscia.
 *
 * @param int $id Identyfikator uzytkownika.
 * @return void
 */
function gb_login( $id ) {
	wp_set_current_user( 0 );
	wp_set_current_user( (int) $id );
}

global $wpdb;

$flow_t = MP_Sales_Workflow_DB::flow_table();
$act_t  = MP_Sales_Workflow_DB::activity_table();
$evt_t  = MP_Sales_Workflow_DB::events_table();
$noti_t = MP_Sales_Workflow_DB::notifications_table();

$seria = (int) substr( (string) time(), -6 );
$lead  = 700000 + $seria;

$GLOBALS['mp_gb']['lines'][] = '=== PRZYGOTOWANIE: proces z przypisanym handlowcem ===';

$sal_a = (int) wp_insert_user(
	array(
		'user_login' => 'gb_handlowiec_a_' . $seria,
		'user_pass'  => wp_generate_password( 20 ),
		'user_email' => 'gb_a_' . $seria . '@example.test',
		'role'       => MP_SW_Roles::ROLE_SALESMAN,
	)
);
$sal_b = (int) wp_insert_user(
	array(
		'user_login' => 'gb_handlowiec_b_' . $seria,
		'user_pass'  => wp_generate_password( 20 ),
		'user_email' => 'gb_b_' . $seria . '@example.test',
		'role'       => MP_SW_Roles::ROLE_SALESMAN,
	)
);

foreach ( array( $sal_a, $sal_b ) as $uid ) {
	update_user_meta( $uid, MP_SW_D2_Reader::META_COUNTRY, 'PL' );
	update_user_meta( $uid, MP_SW_D2_Reader::META_LANGS, 'pl,en' );
	update_user_meta( $uid, MP_SW_D2_Reader::META_TEAM, 'zespol-gb' );
}

gb_ok( $sal_a > 0 && $sal_b > 0, 'dwa konta handlowcow utworzone' );

do_action(
	'mp_lead_created',
	$lead,
	array(
		'lead_id'      => $lead,
		'company_name' => 'Grupa B Sp. z o.o.',
		'email'        => 'klient_gb_' . $seria . '@example.test',
		'country'      => 'PL',
		'segment'      => 'B2B',
	)
);

$proces  = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE lead_id = %d", $lead ), ARRAY_A ); // phpcs:ignore
$flow_id = isset( $proces['id'] ) ? (int) $proces['id'] : 0;
$wlasc   = isset( $proces['assigned_user_id'] ) ? (int) $proces['assigned_user_id'] : 0;

gb_ok( $flow_id > 0, 'proces sprzedazowy powstal', 'flow_id=' . $flow_id );
gb_ok( $wlasc > 0, 'proces ma przypisanego handlowca', 'owner=' . $wlasc );

/**
 * Migawka stanu bazy dla tego procesu.
 *
 * Identyfikator idzie PARAMETREM, nie przez `global`: `wp eval-file` wykonuje
 * plik w zasiegu funkcji, wiec zmienne z jego szczytu NIE sa globalne.
 * Migawka czytajaca `global $flow_id` dostawala zero i wszystkie porownania
 * „nic sie nie zmienilo" przechodzily na pustych wynikach.
 *
 * @param int $flow_id Identyfikator procesu.
 * @return array
 */
function gb_migawka( $flow_id ) {
	global $wpdb;

	$flow_t = MP_Sales_Workflow_DB::flow_table();
	$act_t  = MP_Sales_Workflow_DB::activity_table();
	$evt_t  = MP_Sales_Workflow_DB::events_table();
	$noti_t = MP_Sales_Workflow_DB::notifications_table();

	$wiersz = (array) $wpdb->get_row( // phpcs:ignore
		$wpdb->prepare( "SELECT status, lock_version FROM {$flow_t} WHERE id = %d", (int) $flow_id ), // phpcs:ignore
		ARRAY_A
	);

	return array(
		'istnieje'    => ! empty( $wiersz ),
		'status'      => isset( $wiersz['status'] ) ? (string) $wiersz['status'] : '',
		'lock'        => isset( $wiersz['lock_version'] ) ? (int) $wiersz['lock_version'] : -1,
		'dziennik'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$act_t} WHERE flow_id = %d", (int) $flow_id ) ), // phpcs:ignore
		'zdarzenia'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$evt_t}" ), // phpcs:ignore
		'powiadomien' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$noti_t} WHERE flow_id = %d", (int) $flow_id ) ), // phpcs:ignore
	);
}

$GLOBALS['mp_gb']['lines'][] = '';
$GLOBALS['mp_gb']['lines'][] = '=== B1 — status.change bez statusu docelowego ===';

gb_login( $wlasc );

$przed = gb_migawka( $flow_id );
gb_ok( $przed['istnieje'] && '' !== $przed['status'], 'migawka widzi wiersz procesu (inaczej porownania nic nie znacza)', wp_json_encode( $przed ) );

$odp = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'   => array( 'lead_id' => $lead ),
		'actor'    => array( 'user_id' => get_current_user_id() ),
		'nonce'    => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
		'event_id' => wp_generate_uuid4(),
		// Brak `to_status` — to jest caly sedno tego przypadku.
	)
);
$wynik = $odp['result'];
$dane  = $wynik->get_data();
$http  = isset( $dane['http_status'] ) ? (int) $dane['http_status'] : 0;
// Publiczny kod bledu wychodzi przez `payload()` — to jest to, co naprawde
// dostaje wywolujacy. `get_data()['code']` nie istnieje na tej sciezce.
$odpowiedz = MP_SW_Events::payload( $wynik, $odp['context'] );
$kod       = isset( $odpowiedz['code'] ) ? (string) $odpowiedz['code'] : '';

gb_ok( ! $wynik->is_ok(), 'zdarzenie BEZ to_status zostaje odrzucone, nie potwierdzone' );
gb_ok( 400 === $http, 'odmowa z kodem HTTP 400 (zle zadanie, nie awaria)', 'http=' . $http );
gb_ok( MP_SW_Errors::E_INCOMPLETE === $kod, 'publiczny kod bledu mowi o niekompletnym zdarzeniu', 'kod=' . $kod );

$po = gb_migawka( $flow_id );

gb_ok( $przed['status'] === $po['status'], 'status procesu NIE zostal ruszony', $przed['status'] . ' -> ' . $po['status'] );
gb_ok( $przed['lock'] === $po['lock'], 'token blokady nie drgnal (czyli nie bylo zapisu)', $przed['lock'] . ' -> ' . $po['lock'] );
gb_ok( $przed['dziennik'] === $po['dziennik'], 'zaden wpis nie trafil do dziennika' );
gb_ok( $przed['zdarzenia'] === $po['zdarzenia'], 'zaden wiersz nie trafil do rejestru zdarzen' );

// Galaz „zdarzenie nie rusza statusu" ma dalej dzialac tam, gdzie nalezy.
$widok = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW,
	array(
		'entity'   => array( 'lead_id' => $lead ),
		'actor'    => array( 'user_id' => get_current_user_id() ),
		'nonce'    => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
		'event_id' => wp_generate_uuid4(),
	)
);

gb_ok( $widok['result']->is_ok(), 'dashboard.view NADAL przechodzi bez statusu docelowego' );

$po_widoku = gb_migawka( $flow_id );
gb_ok( $przed['status'] === $po_widoku['status'], 'podglad pulpitu nie zmienil statusu procesu' );

// Ten sam brak w samym agencie 5.1 — bez posrednictwa reszty pipeline'u.
$ctx_pusty = new MP_SW_Context(
	array(
		'event' => array( 'type' => MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE ),
		'flow'  => array( 'row' => array( 'status' => MP_Sales_Workflow_DB::STATUS_ASSIGNED ) ),
	)
);
$agent5 = new MP_SW_D5_Agent_Transition( '5.1', 'przejscie', 'test Grupy B' );
$res5   = $agent5->run( $ctx_pusty );

gb_ok( ! $res5->is_ok(), 'agent 5.1 sam z siebie odmawia przy braku to_status' );

$ctx_widok = new MP_SW_Context(
	array(
		'event' => array( 'type' => MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW ),
		'flow'  => array( 'row' => array( 'status' => MP_Sales_Workflow_DB::STATUS_ASSIGNED ) ),
	)
);
$res5w = $agent5->run( $ctx_widok );
$tr    = (array) $res5w->get_data();

gb_ok(
	$res5w->is_ok() && ! empty( $tr['transition']['allowed'] ) && empty( $tr['transition']['changes_status'] ),
	'agent 5.1 przepuszcza dashboard.view jako zdarzenie bez zmiany statusu'
);

$GLOBALS['mp_gb']['lines'][] = '';
$GLOBALS['mp_gb']['lines'][] = '=== B2 — aktor koperty musi byc zalogowanym uzytkownikiem ===';

gb_login( $wlasc );
$obcy  = $wlasc === $sal_a ? $sal_b : $sal_a;
$przed = gb_migawka( $flow_id );

$podszycie = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => $lead ),
		// Aktor CUDZY — zalogowany jest kto inny.
		'actor'     => array( 'user_id' => $obcy ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_LOST,
		'nonce'     => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
		'event_id'  => wp_generate_uuid4(),
	)
);
$wynik = $podszycie['result'];
$dane  = $wynik->get_data();
$http  = isset( $dane['http_status'] ) ? (int) $dane['http_status'] : 0;

$odpowiedz = MP_SW_Events::payload( $wynik, $podszycie['context'] );
$kod       = isset( $odpowiedz['code'] ) ? (string) $odpowiedz['code'] : '';

gb_ok( ! $wynik->is_ok(), 'zdarzenie z CUDZYM aktorem zostaje odrzucone' );
gb_ok( 403 === $http, 'odmowa z kodem HTTP 403', 'http=' . $http );
// Kod podany wprost, nie przez stala klasy: przed naprawa `E_ACTOR` jeszcze nie
// istnieje, a test ma FAIL-owac czytelnie, nie wywracac sie bledem krytycznym.
gb_ok( 'MP3-E102' === $kod, 'osobny kod bledu dla niezgodnego aktora, nie kod kanalu', 'kod=' . $kod );
gb_ok(
	false === strpos( (string) $odpowiedz['message'], 'kanału' ),
	'komunikat NIE mowi o zlym kanale — powod jest inny',
	(string) $odpowiedz['message']
);

$po = gb_migawka( $flow_id );

gb_ok( $przed['status'] === $po['status'], 'ZERO zmian: status procesu bez zmian', $przed['status'] . ' -> ' . $po['status'] );
gb_ok( $przed['lock'] === $po['lock'], 'ZERO zmian: token blokady bez zmian' );
gb_ok( $przed['dziennik'] === $po['dziennik'], 'ZERO zmian: dziennik bez nowych wpisow' );
gb_ok( $przed['zdarzenia'] === $po['zdarzenia'], 'ZERO zmian: rejestr zdarzen bez nowych wierszy' );
gb_ok( $przed['powiadomien'] === $po['powiadomien'], 'ZERO zmian: kolejka powiadomien bez nowych wierszy' );

// Aktor zgodny z zalogowanym — zdarzenie ma przejsc normalnie.
$wlasne = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => $lead ),
		'actor'     => array( 'user_id' => get_current_user_id() ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_LOST,
		'nonce'     => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
		'event_id'  => wp_generate_uuid4(),
	)
);

gb_ok( $wlasne['result']->is_ok(), 'wlasny aktor przechodzi normalnie' );

$po_wlasnym = gb_migawka( $flow_id );
gb_ok(
	MP_Sales_Workflow_DB::STATUS_LOST === $po_wlasnym['status'],
	'status faktycznie przeszedl na sprzedaz przegrana',
	$po_wlasnym['status']
);

// Aktor systemowy (`user_id = 0`) zostaje bez zmian — hak wtyczki 2 dziala dalej.
$przed = gb_migawka( $flow_id );

$sprawdzenie_systemu = MP_SW_Origin::check(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	MP_SW_D1::SOURCE_SYSTEM,
	array(
		'to_status' => MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT,
		'entity'    => array(
			'lead_id'  => $lead,
			'offer_id' => 1,
		),
		'actor'     => array( 'user_id' => 0 ),
	)
);

gb_ok(
	! empty( $sprawdzenie_systemu['ok'] ),
	'zrodlo SYSTEMOWE z aktorem 0 nadal przechodzi kontrole pochodzenia',
	isset( $sprawdzenie_systemu['reason'] ) ? (string) $sprawdzenie_systemu['reason'] : ''
);

// ... a reczne zdarzenie podszywajace sie pod system (aktor 0 przy zalogowanym
// czlowieku) juz nie. To ten sam blad widziany z drugiej strony.
$sprawdzenie_reczne = MP_SW_Origin::check(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	MP_SW_D1::SOURCE_MANUAL,
	array(
		'to_status' => MP_Sales_Workflow_DB::STATUS_WON,
		'entity'    => array( 'lead_id' => $lead ),
		'actor'     => array( 'user_id' => 0 ),
	)
);

gb_ok(
	empty( $sprawdzenie_reczne['ok'] ),
	'reczne zdarzenie z aktorem systemowym (0) zostaje odrzucone'
);

// Brak pola `actor` w kopercie recznej to ten sam przypadek co aktor cudzy.
$sprawdzenie_bez_aktora = MP_SW_Origin::check(
	MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW,
	MP_SW_D1::SOURCE_MANUAL,
	array( 'entity' => array( 'lead_id' => $lead ) )
);

gb_ok(
	empty( $sprawdzenie_bez_aktora['ok'] ),
	'koperta reczna BEZ pola actor zostaje odrzucona'
);

$po = gb_migawka( $flow_id );
gb_ok( $przed['dziennik'] === $po['dziennik'], 'same kontrole pochodzenia niczego nie zapisaly' );

// Sprzatanie: uzytkownicy i proces tego przebiegu.
gb_login( 0 );
$wpdb->delete( $noti_t, array( 'flow_id' => $flow_id ) ); // phpcs:ignore
$wpdb->delete( $act_t, array( 'flow_id' => $flow_id ) ); // phpcs:ignore
$wpdb->delete( $flow_t, array( 'id' => $flow_id ) ); // phpcs:ignore
wp_delete_user( $sal_a );
wp_delete_user( $sal_b );
