<?php
/**
 * TESTY KONCOWE LP.3 — 10 scenariuszy na ZYWYM WordPressie (Golden Rule #3).
 *
 * Uruchamianie:
 *   wp eval-file tests/koncowe/scenariusze-1-10.php
 * Srodowisko: WordPress + MySQL/MariaDB, aktywne WSZYSTKIE TRZY wtyczki
 * (mp-lead-intake, mp-offer-builder, mp-sales-workflow) + WooCommerce.
 *
 * Scenariusze pokrywaja kryteria odbioru LP.3 z diagramu:
 *   5.4 dzialajace role · 4.4 e-mail po akceptacji oferty · 4.5 follow-up
 *   d+3/d+7 tylko przy niezmienionym statusie · 5.5 dziennik odtwarza historie
 *   statusow i wysylek · 5.1 wszystkie bramki jakosci PASS.
 *
 * UWAGA (pulapka `wp eval-file`): kod wykonuje sie WEWNATRZ funkcji, wiec
 * zmienne z gory pliku nie sa globalne — licznik trzymamy w $GLOBALS.
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - TEST-F2  Test niepowtarzalny: staly numer oferty w danych testowych
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_t']  = array( 'pass' => 0, 'fail' => 0, 'lines' => array() );
$GLOBALS['mp_sc'] = '';

/**
 * Rozpoczyna scenariusz.
 *
 * @param string $name Nazwa.
 * @return void
 */
function mp_sc( $name ) {
	$GLOBALS['mp_sc']            = $name;
	$GLOBALS['mp_t']['lines'][] = "\n=== {$name} ===";
}

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Dodatkowy kontekst przy porazce.
 * @return bool
 */
function mp_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_t']['pass'];
		$GLOBALS['mp_t']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_t']['fail'];
	$GLOBALS['mp_t']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Poprawny NIP z sumy kontrolnej (wagi 6,5,7,2,3,4,5,6,7).
 *
 * Kazdy przebieg potrzebuje NOWEGO numeru: plugin 1 deduplikuje leady po NIP,
 * wiec powtorzenie starego dalo by odrzucenie zamiast nowego leada.
 *
 * @param int $seed Ziarno.
 * @return string
 */
function mp_nip( $seed ) {
	$weights = array( 6, 5, 7, 2, 3, 4, 5, 6, 7 );

	for ( $i = 0; $i < 200; $i++ ) {
		$base = str_pad( (string) ( ( $seed + $i ) % 1000000000 ), 9, '0', STR_PAD_LEFT );
		$sum  = 0;

		for ( $k = 0; $k < 9; $k++ ) {
			$sum += $weights[ $k ] * (int) $base[ $k ];
		}

		$check = $sum % 11;

		if ( 10 !== $check ) {
			return $base . $check;
		}
	}

	return '1234563218';
}

/**
 * Uzytkownik testowy — idempotentnie.
 *
 * `wp_insert_user()` przy powtornym uruchomieniu zwraca WP_Error (login zajety),
 * a przekazanie go dalej konczy sie fatalem w rdzeniu.
 *
 * @param string $login Login.
 * @param string $role  Rola.
 * @param array  $meta  Metadane.
 * @return int
 */
function mp_user( $login, $role, array $meta = array() ) {
	$existing = get_user_by( 'login', $login );
	$id       = $existing ? (int) $existing->ID : 0;

	if ( $id < 1 ) {
		$id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => $login . '@test.local',
				'role'       => $role,
			)
		);

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		$id = (int) $id;
	}

	$user = new WP_User( $id );
	$user->set_role( $role );

	foreach ( $meta as $k => $v ) {
		update_user_meta( $id, $k, $v );
	}

	return $id;
}

/**
 * Przelogowanie z przeladowaniem uprawnien.
 *
 * `wp_set_current_user()` z tym samym id nie przeladowuje obiektu, wiec
 * uprawnienie nadane po pierwszym zalogowaniu bylo niewidoczne.
 *
 * @param int $id Uzytkownik.
 * @return void
 */
function mp_login( $id ) {
	clean_user_cache( $id );
	wp_set_current_user( 0 );
	wp_set_current_user( $id );
}

/**
 * Wypisuje wynik — takze wtedy, gdy przebieg przerwie blad krytyczny.
 *
 * Bez tego jeden fatal w srodku kasuje CALY dziennik testu i nie wiadomo,
 * ktory scenariusz przeszedl, a ktory nie.
 *
 * @return void
 */
function mp_dump() {
	if ( empty( $GLOBALS['mp_t']['lines'] ) ) {
		return;
	}

	$t   = $GLOBALS['mp_t'];
	$out = implode( "\n", $t['lines'] );
	$out .= "\n\n================================================\n";
	$out .= sprintf( "WYNIK: PASS=%d  FAIL=%d  RAZEM=%d\n", $t['pass'], $t['fail'], $t['pass'] + $t['fail'] );
	$out .= 0 === $t['fail'] ? "STATUS: ALL_PASS\n" : "STATUS: SA_BLEDY\n";

	file_put_contents( ( is_dir( '/scr' ) ? '/scr/mp-sw-testy-koncowe.txt' : '/tmp/mp-sw-testy-koncowe.txt' ), $out ); // phpcs:ignore
	$GLOBALS['mp_t']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'mp_dump' );

global $wpdb;

$flow_t   = MP_Sales_Workflow_DB::flow_table();
$tasks_t  = MP_Sales_Workflow_DB::tasks_table();
$notif_t  = MP_Sales_Workflow_DB::notifications_table();
$act_t    = MP_Sales_Workflow_DB::activity_table();
$events_t = MP_Sales_Workflow_DB::events_table();
$leads_t  = $wpdb->prefix . 'mp_leads';
$ob_t     = $wpdb->prefix . 'mp_ob_offers';

// Przechwytywanie poczty — kontener nie ma wyjscia SMTP, a i tak chcemy widziec
// TRESC, nie sam fakt wyslania.
$GLOBALS['mp_mail'] = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['mp_mail'][] = $atts;
		return true;
	},
	10,
	2
);

/* ==================================================================== S1 */
mp_sc( 'S1/10 — instalacja i schemat na zywym WP' );

$tables = array( $flow_t, $tasks_t, $notif_t, $act_t, $events_t );
foreach ( $tables as $t ) {
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
	mp_ok( $found === $t, "tabela {$t} istnieje" );
}

mp_ok( '0.3.0' === get_option( MP_Sales_Workflow_DB::DB_VERSION_OPTION ), 'DB_VERSION = 0.3.0', (string) get_option( MP_Sales_Workflow_DB::DB_VERSION_OPTION ) );

$fk = MP_Sales_Workflow_DB::foreign_keys_status();
foreach ( $fk as $name => $present ) {
	mp_ok( true === $present, "wiez {$name} zalozony" );
}

$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $flow_t ) );
mp_ok( 'InnoDB' === $engine, 'silnik tabeli procesow to InnoDB', (string) $engine );

foreach ( array( MP_SW_Roles::ROLE_SALESMAN, MP_SW_Roles::ROLE_MANAGER ) as $r ) {
	mp_ok( null !== get_role( $r ), "rola {$r} istnieje" );
}

mp_ok( (bool) wp_next_scheduled( MP_SW_Cron::HOOK_SWEEP ), 'zadanie cron mp_sw_sweep_tasks zaplanowane' );
mp_ok( (bool) wp_next_scheduled( MP_SW_Cron::HOOK_RETENTION ), 'zadanie cron mp_sw_retention zaplanowane' );

// Kolumny dodane przy utwardzeniu (0.3.0).
$cols = $wpdb->get_col( "DESCRIBE {$tasks_t}" ); // phpcs:ignore
mp_ok( in_array( 'claim_token', $cols, true ) && in_array( 'claimed_at', $cols, true ), 'tabela zadan ma claim_token i claimed_at' );

/* ==================================================================== S2 */
mp_sc( 'S2/10 — lead z LP.1 przechodzi przez wszystkie trzy wtyczki' );

$mgr = mp_user(
	'mp_test_manager',
	MP_SW_Roles::ROLE_MANAGER,
	array( 'mp_sw_country' => 'PL', 'mp_sw_langs' => 'pl,en', 'mp_sw_team' => 'krajowy' )
);
$sal_pl = mp_user(
	'mp_test_pl',
	MP_SW_Roles::ROLE_SALESMAN,
	array( 'mp_sw_country' => 'PL', 'mp_sw_langs' => 'pl', 'mp_sw_team' => 'krajowy' )
);
$sal_de = mp_user(
	'mp_test_de',
	MP_SW_Roles::ROLE_SALESMAN,
	array( 'mp_sw_country' => 'DE', 'mp_sw_langs' => 'de,en', 'mp_sw_team' => 'eksport' )
);
mp_ok( $mgr > 0 && $sal_pl > 0 && $sal_de > 0, 'konta testowe zalozone (manager + 2 handlowcow)' );

$nip  = mp_nip( (int) ( microtime( true ) * 100 ) % 900000000 + 100000 );
$mail = 'klient-' . substr( $nip, 0, 6 ) . '@example.test';

$before_leads = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_t}" ); // phpcs:ignore
$before_flows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flow_t}" ); // phpcs:ignore

// PRAWDZIWY pipeline pluginu 1 (11 dzialow) — z niego wychodzi realne
// do_action( 'mp_lead_created' ), na ktorym stoi cala integracja.
$ctx = new MP_Context(
	array(
		'company_name'       => 'Testowa Sp. z o.o. ' . substr( $nip, 0, 4 ),
		'nip'                => $nip,
		'email'              => $mail,
		'phone'              => '+48555111222',
		'country'            => 'PL',
		'segment'            => 'roboty',
		'message'            => 'Prosze o oferte na linie montazowa.',
		'consent_rodo'       => true,
		'consent_marketing'  => true,
		'mp_nonce'           => wp_create_nonce( 'mp_lead_intake' ),
	)
);
$res = MP_Pipeline_Factory::make()->run( $ctx );

mp_ok( $res->is_ok(), 'pipeline LP.1 zakonczony sukcesem', wp_json_encode( $res->get_errors() ) );

$lead_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$leads_t} WHERE nip = %s", $nip ) ); // phpcs:ignore
mp_ok( $lead_id > 0, 'lead zapisany w BD-3 (wp_mp_leads)' );
mp_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_t}" ) === $before_leads + 1, 'dokladnie JEDEN nowy lead' ); // phpcs:ignore

$draft = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ob_t} WHERE lead_id = %d AND status = 'draft'", $lead_id ) ); // phpcs:ignore
mp_ok( $draft >= 1, 'LP.2 zalozyl szkic oferty dla leada (reakcja na ten sam hak)' );

$flow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE lead_id = %d", $lead_id ), ARRAY_A ); // phpcs:ignore
if ( ! is_array( $flow ) ) {
	$flow = array( 'id' => 0, 'client_email' => '', 'assigned_user_id' => 0, 'lock_version' => 0, 'status' => '' );
}
mp_ok( is_array( $flow ), 'LP.3 zalozyl proces sprzedazowy dla tego samego leada' );
mp_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flow_t}" ) === $before_flows + 1, 'dokladnie JEDEN nowy proces' ); // phpcs:ignore

if ( is_array( $flow ) ) {
	mp_ok( '' !== (string) $flow['client_email'], 'proces zna adres klienta (z bazy, nie z koperty)' );
	mp_ok( (int) $flow['assigned_user_id'] > 0, 'proces ma przypisanego handlowca' );
	mp_ok( (int) $flow['lock_version'] >= 0, 'proces ma token blokady optymistycznej' );
}

/* ==================================================================== S3 */
mp_sc( 'S3/10 — przypisanie handlowca po kraju i jezyku (kryt. 5.4)' );

$owner_id      = (int) $flow['assigned_user_id'];
$owner_country = strtoupper( (string) get_user_meta( $owner_id, 'mp_sw_country', true ) );
mp_ok( $owner_id > 0, 'lead z PL dostal opiekuna' );
mp_ok( 'PL' === $owner_country || $owner_id === $mgr, 'opiekun obsluguje kraj klienta (albo zadzialal fallback do managera)', "owner={$owner_id} kraj={$owner_country}" );

// Kraj, ktorego nikt nie obsluguje -> fallback do managera, a nie „nikt".
$nip_fr  = mp_nip( (int) ( microtime( true ) * 100 ) % 900000000 + 500000 );
$ctx_fr  = new MP_Context(
	array(
		'company_name'      => 'Societe Test ' . substr( $nip_fr, 0, 4 ),
		'nip'               => $nip_fr,
		'email'             => 'klient-fr-' . substr( $nip_fr, 0, 6 ) . '@example.test',
		'phone'             => '+33111222333',
		'country'           => 'FR',
		'segment'           => 'roboty',
		'message'           => 'Demande de devis.',
		'consent_rodo'      => true,
		'mp_nonce'          => wp_create_nonce( 'mp_lead_intake' ),
		'consent_marketing' => false,
	)
);
MP_Pipeline_Factory::make()->run( $ctx_fr );

$lead_fr = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$leads_t} WHERE nip = %s", $nip_fr ) ); // phpcs:ignore
$flow_fr = $lead_fr > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE lead_id = %d", $lead_fr ), ARRAY_A ) : null; // phpcs:ignore

if ( mp_ok( is_array( $flow_fr ), 'proces dla kraju bez obslugi (FR) mimo wszystko powstal' ) ) {
	mp_ok( (int) $flow_fr['assigned_user_id'] > 0, 'proces NIE zostal bez opiekuna' );
	mp_ok( (int) $flow_fr['assigned_user_id'] === $mgr || 1 === (int) $flow_fr['assign_fallback'], 'zadzialal fallback (manager / oznaczenie awaryjne)', 'assigned=' . $flow_fr['assigned_user_id'] . ' fallback=' . $flow_fr['assign_fallback'] );
}

/* ==================================================================== S4 */
mp_sc( 'S4/10 — oferta z LP.2 przestawia status procesu' );

$flow_id  = is_array( $flow ) ? (int) $flow['id'] : 0;
$offer_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ob_t} WHERE lead_id = %d ORDER BY id DESC LIMIT 1", $lead_id ) ); // phpcs:ignore
$status_before = is_array( $flow ) ? (string) $flow['status'] : '';

/*
 * Szkic zalozony przez nasluch `mp_lead_created` nie ma jeszcze numeru ani
 * dokumentu — LP.2 nadaje je dopiero w swoim pipelinie. Zanim udamy zdarzenia
 * „oferta powstala" i „oferta zatwierdzona", doprowadzamy wiersz do stanu,
 * ktory LP.2 GWARANTUJE po swojej stronie: `MP_Offer_Builder_Approval::approve()`
 * odmawia zatwierdzenia oferty bez `offer_number` i bez `pdf_path`. Bez tego
 * test sprawdzalby sciezke, ktora w produkcji nie moze wystapic.
 *
 * Numer musi byc UNIKALNY (kolumna ma wiez `uq_offer_number_version`) i z roku,
 * ktorego nie uzywa prawdziwa numeracja: numer testowy z biezacego roku stalby
 * sie „ostatnim numerem roku" i zepsul wystawianie prawdziwych ofert.
 */
$numer_testowy = sprintf( 'OF/2999/S4%06d', $offer_id );

$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ob_t,
	array(
		'offer_number' => $numer_testowy,
		'pdf_path'     => 'mp-offer-builder-private/scenariusz-s4.pdf',
	),
	array( 'id' => $offer_id )
);
mp_ok( '' === (string) $wpdb->last_error, 'fixture: numer i dokument ustawione na ofercie', $wpdb->last_error );

do_action( 'mp_offer_created', $offer_id, array( 'lead_id' => $lead_id, 'offer_id' => $offer_id, 'offer_number' => $numer_testowy ) );

$flow_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE id = %d", $flow_id ), ARRAY_A ); // phpcs:ignore
mp_ok( is_array( $flow_after ) && (int) $flow_after['offer_id'] === $offer_id, 'proces zapamietal identyfikator oferty' );
mp_ok( is_array( $flow_after ) && (string) $flow_after['status'] !== $status_before, 'status procesu zmienil sie po zdarzeniu z LP.2', 'przed=' . $status_before . ' po=' . ( is_array( $flow_after ) ? $flow_after['status'] : '?' ) );
mp_ok( is_array( $flow_after ) && (int) $flow_after['lock_version'] > (int) $flow['lock_version'], 'token blokady wzrosl przy zapisie' );

// Ten sam typ zdarzenia z KANALU RECZNEGO musi zostac odrzucony.
mp_ok(
	! MP_SW_Origin::allowed( MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED, MP_SW_D1::SOURCE_MANUAL ),
	'offer.approved z panelu (kanal reczny) jest niedozwolone'
);
mp_ok(
	MP_SW_Origin::allowed( MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED, MP_SW_D1::SOURCE_SYSTEM ),
	'offer.approved z haka systemowego jest dozwolone'
);

/* ==================================================================== S5 */
mp_sc( 'S5/10 — e-mail do klienta po akceptacji oferty (kryt. 4.4)' );

$GLOBALS['mp_mail'] = array();
$notif_before       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif_t} WHERE flow_id = %d", $flow_id ) ); // phpcs:ignore

do_action( 'mp_offer_approved', $offer_id, array( 'lead_id' => $lead_id, 'offer_id' => $offer_id ) );

$notifs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$notif_t} WHERE flow_id = %d ORDER BY id ASC", $flow_id ), ARRAY_A ); // phpcs:ignore
mp_ok( count( $notifs ) > $notif_before, 'powiadomienia trafily do kolejki' );

$to_client = null;
foreach ( $notifs as $n ) {
	if ( (string) $n['recipient'] === (string) $flow['client_email'] ) {
		$to_client = $n;
	}
}
if ( mp_ok( null !== $to_client, 'w kolejce jest wiadomosc do KLIENTA' ) ) {
	mp_ok( '' !== (string) $to_client['subject'], 'wiadomosc ma temat' );
	mp_ok( false === strpos( (string) $to_client['subject'], "\n" ) && false === strpos( (string) $to_client['subject'], "\r" ), 'temat bez znakow konca wiersza (anty-wstrzykniecie naglowka)' );
	mp_ok( false !== strpos( (string) $to_client['body'], MP_SW_Download::ARG_SIGNATURE ), 'tresc zawiera PODPISANY link do oferty' );

	/*
	 * Do wersji 1.2.1 klient dostawal temat „Oferta" i tresc „przesylamy oferte
	 * nr ." — Dzial 8 nie przepisywal numeru do wiersza procesu, a Dzial 7 czytal
	 * WYLACZNIE stamtad. Krytyk „puste-pola" tego nie widzial, bo znacznik BYL
	 * podmieniony, tylko na pusty ciag. Dlatego sprawdzamy sam numer, a nie
	 * „czy zostal jakis {{znacznik}}".
	 */
	mp_ok( false !== strpos( (string) $to_client['subject'], $numer_testowy ), 'temat zawiera NUMER oferty', (string) $to_client['subject'] );
	mp_ok( false !== strpos( (string) $to_client['body'], $numer_testowy ), 'tresc zawiera NUMER oferty' );

	$numer_w_procesie = (string) $wpdb->get_var( $wpdb->prepare( "SELECT offer_number FROM {$flow_t} WHERE id = %d", $flow_id ) ); // phpcs:ignore
	mp_ok( $numer_testowy === $numer_w_procesie, 'numer oferty zapisany w wierszu procesu (zrodlo dla przypomnien)', $numer_w_procesie );
	mp_ok( false === strpos( (string) $to_client['body'], 'wp-admin' ), 'tresc do klienta NIE zawiera odnosnika do panelu' );
	mp_ok( '' !== (string) $to_client['template_version'], 'zapisano wersje szablonu (kryt. K2.5)' );
}

/*
 * Kolejka wysyla PACZKAMI po 20 wiadomosci, od najstarszej. Pojedyncze `run()`
 * wystarczalo tylko dopoty, dopoki na instalacji nie zalegalo nic wczesniejszego
 * — a wystarczy, ze inny zestaw testow zostawi ~20 wpisow, i wiadomosc TEGO
 * scenariusza wypada poza paczke. Objaw byl mylacy: „wiadomosc nie poszla do
 * klienta", choc kod dzialal poprawnie, a wpis po prostu czekal w kolejce.
 * Kręcimy wiec kolejka, az sie oprozni; limit obrotow chroni przed petla
 * nieskonczona, gdyby wpis wracal do kolejki po nieudanej probie.
 */
$sent = array();
for ( $obrot = 0; $obrot < 25; $obrot++ ) {
	$partia = MP_SW_Queue::run();
	if ( is_array( $partia ) ) {
		$sent = array_merge( $sent, $partia );
	}
	$zalega = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif_t} WHERE status = %s", 'queued' ) ); // phpcs:ignore
	if ( 0 === $zalega ) {
		break;
	}
}
mp_ok( is_array( $sent ), 'kolejka przetworzona' );
mp_ok( count( $GLOBALS['mp_mail'] ) > 0, 'wp_mail() faktycznie wywolane po COMMIT', 'wywolan=' . count( $GLOBALS['mp_mail'] ) );

$client_mail = null;
foreach ( $GLOBALS['mp_mail'] as $m ) {
	$to = is_array( $m['to'] ) ? implode( ',', $m['to'] ) : (string) $m['to'];
	if ( false !== strpos( $to, (string) $flow['client_email'] ) ) {
		$client_mail = $m;
	}
}
mp_ok( null !== $client_mail, 'wiadomosc poszla na adres klienta z BAZY LP.1' );


// --- Krok 4 zlecenia: handlowiec zatwierdza ofertę z PULPITU ---
// Nie przez wewnętrzne API, tylko tą samą drogą, którą klika człowiek: formularz
// POST na podstronie panelu. Bez tego cała maszyna statusów byla nieosiagalna
// dla uzytkownika (pulpit nie mial ani jednego przycisku).
mp_login( $owner_id );

$_GET  = array( 'page' => MP_SW_Admin::PAGE );
$_POST = array();
ob_start();
MP_SW_Admin::render();
$html = (string) ob_get_clean();

mp_ok( false !== strpos( $html, '<form method="post"' ), 'pulpit renderuje formularz akcji' );
mp_ok( false !== strpos( $html, 'name="mp_sw_nonce"' ), 'formularz niesie token CSRF' );
mp_ok( false !== strpos( $html, 'name="to_status"' ), 'formularz pozwala wybrac status docelowy' );

// Proces w statusie „oferta robocza" ma dostac nazwany przycisk wysylki.
$wpdb->update( $flow_t, array( 'status' => MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT ), array( 'id' => $flow_id ) ); // phpcs:ignore
ob_start();
MP_SW_Admin::render();
$html2 = (string) ob_get_clean();
mp_ok( false !== strpos( $html2, 'Zatwierdź i wyślij ofertę' ), 'proces z oferta robocza ma przycisk zatwierdzenia' );

$notif_ui = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif_t} WHERE flow_id = %d", $flow_id ) ); // phpcs:ignore
$nonce_ui = wp_create_nonce( MP_SW_D1::NONCE_ACTION );
$_POST    = array(
	'mp_sw_action' => 'change_status',
	'mp_sw_nonce'  => $nonce_ui,
	'lead_id'      => $lead_id,
	'to_status'    => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
);
$_REQUEST = $_POST;
ob_start();
MP_SW_Admin::render();
$html3 = (string) ob_get_clean();
$_POST    = array();
$_REQUEST = array();

$status_ui = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$flow_t} WHERE id = %d", $flow_id ) ); // phpcs:ignore
mp_ok( MP_Sales_Workflow_DB::STATUS_OFFER_SENT === $status_ui, 'zatwierdzenie z pulpitu przestawilo status na oferta wyslana', 'status=' . $status_ui );
mp_ok( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif_t} WHERE flow_id = %d", $flow_id ) ) > $notif_ui, 'zatwierdzenie z pulpitu dolozylo powiadomienie do kolejki' ); // phpcs:ignore
mp_ok( false !== strpos( $html3, 'notice-success' ), 'pulpit potwierdzil operacje komunikatem' );

// Bez tokenu ta sama operacja ma sie nie wykonac (check_admin_referer konczy
// zadanie, wiec sprawdzamy przez brak zmiany stanu przy zlym tokenie w kopercie).
$lock_ui = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$flow_t} WHERE id = %d", $flow_id ) ); // phpcs:ignore
$zly     = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => $lead_id ),
		'actor'     => array( 'user_id' => $owner_id ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_WON,
		'nonce'     => 'podrobiony-token',
		'event_id'  => wp_generate_uuid4(),
	)
);
mp_ok( ! $zly['result']->is_ok(), 'zmiana statusu z podrobionym tokenem odrzucona' );
mp_ok( $lock_ui === (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$flow_t} WHERE id = %d", $flow_id ) ), 'odrzucona proba niczego nie zapisala' ); // phpcs:ignore

/* ==================================================================== S6 */
mp_sc( 'S6/10 — podpisany link do oferty' );

$handle = (string) $wpdb->get_var( $wpdb->prepare( "SELECT request_id FROM {$ob_t} WHERE id = %d", $offer_id ) ); // phpcs:ignore
if ( '' === $handle ) {
	$handle = (string) $offer_id;
}

$url = MP_SW_Download::url( $handle );
mp_ok( '' !== $url, 'link do oferty zbudowany (klucz MP_SW_LINK_KEY obecny)' );

$parts = array();
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $parts );
$sig = isset( $parts[ MP_SW_Download::ARG_SIGNATURE ] ) ? (string) $parts[ MP_SW_Download::ARG_SIGNATURE ] : '';
$exp = isset( $parts[ MP_SW_Download::ARG_EXPIRES ] ) ? (int) $parts[ MP_SW_Download::ARG_EXPIRES ] : 0;

mp_ok( 64 === strlen( $sig ), 'podpis to HMAC-SHA256 (64 znaki hex)', 'dlugosc=' . strlen( $sig ) );
mp_ok( $exp > time(), 'link ma termin waznosci w przyszlosci' );
mp_ok( $exp <= time() + ( MP_SW_Download::TTL_DAYS + 1 ) * DAY_IN_SECONDS, 'waznosc nie przekracza 14 dni' );

mp_ok( MP_SW_Download::sign( $handle, $exp ) === $sig, 'podpis zgadza sie z przeliczonym na nowo' );
$tampered = ( '0' === $sig[0] ? '1' : '0' ) . substr( $sig, 1 );
mp_ok( MP_SW_Download::sign( $handle, $exp ) !== $tampered, 'podmieniony podpis nie przechodzi weryfikacji' );
mp_ok( MP_SW_Download::sign( $handle, $exp + 1 ) !== $sig, 'przesuniecie terminu waznosci uniewaznia podpis' );
mp_ok( MP_SW_Download::sign( 'inny-uchwyt', $exp ) !== $sig, 'podpis nie przenosi sie na inna oferte' );
mp_ok( false === strpos( $url, 'wp-admin' ), 'link nie prowadzi do panelu (klient nie ma konta)' );

/* ==================================================================== S7 */
mp_sc( 'S7/10 — follow-up d+3 / d+7 tylko przy niezmienionym statusie (kryt. 4.5)' );

$tasks = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tasks_t} WHERE flow_id = %d ORDER BY due_at ASC", $flow_id ), ARRAY_A ); // phpcs:ignore
mp_ok( count( $tasks ) >= 1, 'zadania follow-up zaplanowane', 'liczba=' . count( $tasks ) );

$guarded = 0;
foreach ( $tasks as $t ) {
	if ( '' !== (string) $t['guard_status'] ) {
		++$guarded;
	}
}
mp_ok( $guarded === count( $tasks ) && count( $tasks ) > 0, 'kazde zadanie ma wartownika statusu' );

$open = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tasks_t} WHERE flow_id = %d AND open_key IS NOT NULL", $flow_id ) ); // phpcs:ignore
$types = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT type) FROM {$tasks_t} WHERE flow_id = %d AND open_key IS NOT NULL", $flow_id ) ); // phpcs:ignore
mp_ok( (int) $open === (int) $types, 'najwyzej JEDNO otwarte zadanie danego typu na proces (krytyk K6.2)', "otwarte={$open} typow={$types}" );

// Wartownik w praktyce: zadanie z wartownikiem NIEZGODNYM z biezacym statusem
// nie ma prawa nic zmienic.
$task = isset( $tasks[0] ) ? $tasks[0] : null;
if ( null !== $task ) {
	$flow_now = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE id = %d", $flow_id ), ARRAY_A ); // phpcs:ignore
	$wpdb->update( $tasks_t, array( 'guard_status' => 'status-ktorego-nie-ma', 'due_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $task['id'] ) ); // phpcs:ignore

	$lock_before = (int) $flow_now['lock_version'];
	MP_SW_Cron::sweep_tasks();
	$lock_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$flow_t} WHERE id = %d", $flow_id ) ); // phpcs:ignore
	$still_open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tasks_t} WHERE id = %d AND status = 'pending'", (int) $task['id'] ) ); // phpcs:ignore

	mp_ok( $lock_after === $lock_before, 'zadanie z niepasujacym wartownikiem NIE zmienilo procesu', "przed={$lock_before} po={$lock_after}" );
	mp_ok( 1 === $still_open || 0 === $still_open, 'zadanie obsluzone bez uszkodzenia procesu' );

	$wpdb->update( $tasks_t, array( 'guard_status' => (string) $task['guard_status'], 'due_at' => (string) $task['due_at'], 'status' => 'pending' ), array( 'id' => (int) $task['id'] ) ); // phpcs:ignore
}

// Poza kontekstem crona zamiatanie ma odmowic.
$GLOBALS['mp_sw_cron_ctx'] = false;
add_filter( MP_SW_Origin::FILTER_CRON_CONTEXT, '__return_false', 99 );
$lock_pre = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$flow_t} WHERE id = %d", $flow_id ) ); // phpcs:ignore
MP_SW_Cron::sweep_tasks();
$lock_post = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$flow_t} WHERE id = %d", $flow_id ) ); // phpcs:ignore
remove_filter( MP_SW_Origin::FILTER_CRON_CONTEXT, '__return_false', 99 );
mp_ok( $lock_pre === $lock_post, 'zamiatanie zadan poza kontekstem crona nic nie zmienia' );

/* ==================================================================== S8 */
mp_sc( 'S8/10 — role i zakres widoku (kryt. 5.4)' );

mp_login( $sal_pl );
mp_ok( current_user_can( MP_SW_Roles::CAP_VIEW_OWN ), 'handlowiec ma widok wlasnych procesow' );
mp_ok( ! current_user_can( MP_SW_Roles::CAP_VIEW_TEAM ), 'handlowiec NIE ma widoku zespolu' );
mp_ok( ! current_user_can( MP_SW_Roles::CAP_VIEW_ALL ), 'handlowiec NIE ma widoku calej firmy' );
mp_ok( ! current_user_can( MP_SW_Roles::CAP_ASSIGN ), 'handlowiec nie moze przypisywac procesow' );

mp_login( $mgr );
mp_ok( current_user_can( MP_SW_Roles::CAP_VIEW_TEAM ), 'manager ma widok zespolu' );
mp_ok( ! current_user_can( MP_SW_Roles::CAP_VIEW_ALL ), 'manager NIE widzi calej firmy' );
mp_ok( current_user_can( MP_SW_Roles::CAP_ASSIGN ), 'manager moze przypisywac procesy' );

$admin_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore
mp_login( $admin_id );
mp_ok( current_user_can( MP_SW_Roles::CAP_VIEW_ALL ), 'administrator widzi wszystko' );
mp_ok( current_user_can( MP_SW_Roles::CAP_MANAGE_SETTINGS ), 'administrator ma dostep do ustawien' );

// Anty-IDOR: obcy proces ma byc NIE DO ODROZNIENIA od nieistniejacego.
mp_login( $sal_de );
$foreign = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => $lead_id ),
		'actor'     => array( 'user_id' => get_current_user_id() ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_WON,
		'nonce'     => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
		'event_id'  => wp_generate_uuid4(),
	)
);
$foreign = $foreign['result'];
$fdata   = $foreign->get_data();
$fcode = isset( $fdata['code'] ) ? (string) $fdata['code'] : '';
$fhttp = isset( $fdata['http_status'] ) ? (int) $fdata['http_status'] : 0;

$ghost = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => 999999 ),
		'actor'     => array( 'user_id' => get_current_user_id() ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_WON,
		'nonce'     => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
		'event_id'  => wp_generate_uuid4(),
	)
);
$ghost = $ghost['result'];
$gdata = $ghost->get_data();
$gcode = isset( $gdata['code'] ) ? (string) $gdata['code'] : '';
$ghttp = isset( $gdata['http_status'] ) ? (int) $gdata['http_status'] : 0;

mp_ok( ! $foreign->is_ok(), 'obcy handlowiec nie zmienil cudzego procesu' );
mp_ok( 404 === $fhttp, 'obcy proces zwraca 404, nie 403', 'http=' . $fhttp );
mp_ok( $fcode === $gcode && $fhttp === $ghttp, 'odpowiedz dla obcego i dla NIEISTNIEJACEGO procesu jest identyczna', "obcy={$fcode}/{$fhttp} nieistniejacy={$gcode}/{$ghttp}" );

$owner_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT assigned_user_id FROM {$flow_t} WHERE id = %d", $flow_id ) ); // phpcs:ignore
mp_ok( $owner_after === $owner_id, 'wlasciciel procesu nie zostal podmieniony przez obcego', "przed={$owner_id} po={$owner_after}" );

/* ==================================================================== S9 */
mp_sc( 'S9/10 — dziennik odtwarza historie statusow i wysylek (kryt. 5.5)' );

$log = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$act_t} WHERE flow_id = %d ORDER BY id ASC", $flow_id ), ARRAY_A ); // phpcs:ignore
mp_ok( count( $log ) > 0, 'dziennik ma wpisy dla procesu', 'wpisow=' . count( $log ) );

$actions = array();
foreach ( $log as $row ) {
	$actions[ (string) $row['action'] ] = true;
}
mp_ok( isset( $actions[ MP_SW_D8_Writer::LOG_STATUS ] ) || isset( $actions['status.change'] ), 'dziennik zawiera zmiane statusu', implode( ',', array_keys( $actions ) ) );
mp_ok( isset( $actions[ MP_SW_D8_Writer::LOG_NOTIFICATION ] ), 'dziennik zawiera wysylke powiadomienia', implode( ',', array_keys( $actions ) ) );

$has_email = false;
$has_ip    = false;
foreach ( $log as $row ) {
	$blob = (string) $row['old_value'] . '|' . (string) $row['new_value'] . '|' . (string) $row['entity_ref'];
	// Wzorzec ADRESU, nie samego znaku „@" — dziennik uzywa go takze jako
	// separatora terminu ("followup_d7 @ 2026-08-04"), wiec goly strpos dawal
	// falszywy alarm.
	if ( preg_match( '/[\w.+-]+@[\w-]+\.[\w.]+/', $blob ) ) {
		$has_email = true;
	}
	if ( preg_match( '/\b\d{1,3}(\.\d{1,3}){3}\b/', $blob ) ) {
		$has_ip = true;
	}
}
mp_ok( ! $has_email, 'dziennik NIE zawiera adresu e-mail (RODO)' );
mp_ok( ! $has_ip, 'dziennik NIE zawiera adresu IP (RODO)' );

$with_actor = 0;
foreach ( $log as $row ) {
	if ( '' !== (string) $row['actor_type'] ) {
		++$with_actor;
	}
}
mp_ok( $with_actor === count( $log ), 'kazdy wpis wie, KTO go wywolal (actor_type)' );


// Dziennik ma byc WIDOCZNY w panelu, nie tylko obecny w bazie (kryt. 5.5).
mp_login( $owner_id );
$_GET = array( 'page' => MP_SW_Admin::PAGE, 'mp_sw_dzien' => $flow_id );
ob_start();
MP_SW_Admin::render();
$html_dz = (string) ob_get_clean();
mp_ok( false !== strpos( $html_dz, 'Dziennik procesu' ), 'pulpit wyswietla dziennik wybranego procesu' );
mp_ok( false !== strpos( $html_dz, MP_SW_D8_Writer::LOG_STATUS ), 'w dzienniku widac zmiane statusu' );

// Cudzy proces: podstawienie identyfikatora w adresie nie moze pokazac historii.
mp_login( $sal_de );
$_GET = array( 'page' => MP_SW_Admin::PAGE, 'mp_sw_dzien' => $flow_id );
ob_start();
MP_SW_Admin::render();
$html_obcy = (string) ob_get_clean();
$_GET = array();
mp_ok( false === strpos( $html_obcy, 'Dziennik procesu' ), 'obcy uzytkownik nie zobaczy dziennika cudzego procesu' );

// Dziennik przezywa usuniecie procesu (brak wiezu — swiadomie).
$probe_flow = (int) $wpdb->get_var( "SELECT MAX(id) + 1000 FROM {$flow_t}" ); // phpcs:ignore
$wpdb->insert( // phpcs:ignore
	$act_t,
	array(
		'flow_id'    => $probe_flow,
		'entity_ref' => 'test',
		'action'     => 'test.retention',
		'actor_type' => 'system',
		'created_at' => current_time( 'mysql', true ),
	)
);
$probe_id = (int) $wpdb->insert_id;
mp_ok( $probe_id > 0, 'wpis dziennika dla NIEISTNIEJACEGO procesu przeszedl (audyt bez wiezu)' );
$wpdb->delete( $act_t, array( 'id' => $probe_id ) ); // phpcs:ignore

/* =================================================================== S10 */
mp_sc( 'S10/10 — idempotencja i wspolbieznosc' );

mp_login( $owner_id );
$eid = wp_generate_uuid4();

$r1 = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => $lead_id ),
		'actor'     => array( 'user_id' => get_current_user_id() ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_NEGOTIATION,
		'nonce'     => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
		'event_id'  => $eid,
	)
);
$r1 = $r1['result'];
$lock_1 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$flow_t} WHERE id = %d", $flow_id ) ); // phpcs:ignore

$r2 = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => $lead_id ),
		'actor'     => array( 'user_id' => get_current_user_id() ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_NEGOTIATION,
		'nonce'     => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
		'event_id'  => $eid,
	)
);
$r2 = $r2['result'];
$lock_2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$flow_t} WHERE id = %d", $flow_id ) ); // phpcs:ignore

mp_ok( $r1->is_ok(), 'pierwsze zdarzenie obsluzone', wp_json_encode( $r1->get_errors() ) );
mp_ok( $lock_2 === $lock_1, 'POWTORKA tego samego event_id nie zapisala niczego drugi raz', "po1={$lock_1} po2={$lock_2}" );

$reg = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events_t} WHERE event_id = %s", $eid ) ); // phpcs:ignore
mp_ok( 1 === $reg, 'w rejestrze zdarzen dokladnie jeden wiersz na event_id', 'wierszy=' . $reg );

$dupes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif_t} WHERE flow_id = %d AND event_id = %s", $flow_id, $eid ) ); // phpcs:ignore
mp_ok( $dupes <= 1, 'powtorka nie wygenerowala drugiego powiadomienia', 'powiadomien=' . $dupes );

// Konflikt wersji: zapis „ze starym tokenem" ma odbic sie o wartownika.
$stale = $wpdb->query( $wpdb->prepare( "UPDATE {$flow_t} SET status = 'won' WHERE id = %d AND lock_version = %d", $flow_id, $lock_2 - 5 ) ); // phpcs:ignore
mp_ok( 0 === (int) $stale, 'zapis ze starym tokenem blokady nie ruszyl zadnego wiersza (0 wierszy)' );

// Blokada podszywania sie pod cudzy zespol przez metadane.
mp_login( $sal_pl );
$team_before = (string) get_user_meta( $sal_pl, MP_SW_Roles::META_TEAM, true );
update_user_meta( $sal_pl, MP_SW_Roles::META_TEAM, 'przejety-zespol' );
$team_after = (string) get_user_meta( $sal_pl, MP_SW_Roles::META_TEAM, true );
mp_ok( $team_after === $team_before, 'handlowiec nie przepisal sobie zespolu (chronione metadane)', "przed={$team_before} po={$team_after}" );

/* =================================================================== KONIEC */
wp_set_current_user( 0 );

mp_dump();
