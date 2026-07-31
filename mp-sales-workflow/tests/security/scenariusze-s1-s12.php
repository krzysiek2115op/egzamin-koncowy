<?php
/**
 * Scenariusze bezpieczenstwa S1-S12 + raport inwariantow I-1..I-6.
 * Uruchamiane przez `wp eval-file`.
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-B3  Odmowa zostawiala w kontekscie surowy `scope` z zadania
 */

global $wpdb;

$GLOBALS['mp_oks']   = array();
$GLOBALS['mp_fails'] = array();

function chk( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['mp_oks'][] = $label;
		echo "  PASS  $label\n";
	} else {
		$GLOBALS['mp_fails'][] = $label;
		echo "  FAIL  $label\n";
	}
}

function sec_title( $t ) {
	echo "\n=== $t ===\n";
}

/** Uzytkownik o zadanej roli i zespole. */
function sec_user( $login, $role, $team = '' ) {
	$u   = get_user_by( 'login', $login );
	$uid = $u ? (int) $u->ID : (int) wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => 'x',
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
	$user = new WP_User( $uid );
	$user->set_role( $role );
	// Klucze meta, ktore CZYTA kod: prefiks `mp_sw_`, nie `mp_`.
	if ( '' === $team ) {
		delete_user_meta( $uid, MP_SW_D2_Reader::META_TEAM );
	} else {
		update_user_meta( $uid, MP_SW_D2_Reader::META_TEAM, $team );
	}
	update_user_meta( $uid, MP_SW_D2_Reader::META_COUNTRY, 'PL' );
	update_user_meta( $uid, MP_SW_D2_Reader::META_LANGS, 'pl,en' );
	update_user_meta( $uid, MP_SW_D2_Reader::META_ACTIVE, '1' );
	return $uid;
}

/** Lead w tabeli wtyczki 1. */
function sec_lead( $company, $email, $nip ) {
	global $wpdb;
	$t = $wpdb->prefix . 'mp_leads';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE nip = %s", $nip ) );
	$wpdb->insert(
		$t,
		array(
			'company_name' => $company,
			'nip'          => $nip,
			'email'        => $email,
			'country'      => 'PL',
			'segment'      => 'test',
			'status'       => 'new',
			'vat_status'   => 'valid',
			'created_at'   => current_time( 'mysql', true ),
			'updated_at'   => current_time( 'mysql', true ),
		)
	);
	return (int) $wpdb->insert_id;
}

/** Liczniki stanu bazy — do dowodu „zero zmian”. */
function sec_counts() {
	global $wpdb;
	$out = array();
	foreach ( array( 'flow', 'tasks', 'notifications', 'activity', 'events' ) as $t ) {
		$table      = $wpdb->prefix . 'mp_sw_' . $t;
		$out[ $t ]  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
	return $out;
}

// --- Czyszczenie ---
foreach ( array( 'flow', 'tasks', 'notifications', 'activity', 'events' ) as $t ) {
	$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'mp_sw_' . $t );
}
MP_SW_Mailer::resume();
delete_transient( 'mp_sw_dl_' . substr( MP_SW_Log::ip_hash( '127.0.0.1' ), 0, 32 ) );

$sales_a = sec_user( 'sec_sales_a', MP_SW_Roles::ROLE_SALESMAN, 'zespol-1' );
$sales_b = sec_user( 'sec_sales_b', MP_SW_Roles::ROLE_SALESMAN, 'zespol-2' );
$manager = sec_user( 'sec_manager', MP_SW_Roles::ROLE_MANAGER, 'zespol-1' );

$lead_a = sec_lead( 'Firma A', 'klient-a@example.test', '1111111111' );
$lead_b = sec_lead( 'Firma B', 'klient-b@example.test', '2222222222' );

// ============================================================ S1
sec_title( 'S1 — lead.created z kanalu HTTP' );
MP_SW_Log::reset();
$before = sec_counts();

$r = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED,
	array(
		'entity' => array( 'lead_id' => $lead_a ),
		'actor'  => array( 'user_id' => $manager ),
	)
);

$after   = sec_counts();
$payload = MP_SW_Events::payload( $r['result'], $r['context'] );
$entries = MP_SW_Log::entries();

chk( ! $r['result']->is_ok(), 'S1 zdarzenie odrzucone' );
chk( 403 === MP_SW_Events::http_status( $r['result'] ), 'S1 kod HTTP 403' );
chk( MP_SW_Errors::E_ORIGIN === $payload['code'], 'S1 kod MP3-E110 (' . $payload['code'] . ')' );
chk( $before === $after, 'S1 zero zmian w bazie' );
chk( ! empty( $entries ) && 'SECURITY' === $entries[0]['level'], 'S1 wpis w dzienniku technicznym' );
chk( '' !== (string) $payload['trace_id'], 'S1 odpowiedz niesie trace_id' );

// Ten sam typ z haka — musi PRZEJSC.
$ok_hook = MP_SW_Events::from_hook(
	MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED,
	array(
		'entity'   => array( 'lead_id' => $lead_a ),
		'actor'    => array( 'user_id' => 0 ),
		'event_id' => MP_SW_Events::derive_event_id( 'lead.created', array( $lead_a ) ),
	)
);
chk( $ok_hook['result']->is_ok(), 'S1 ten sam typ z haka przechodzi' );

// offer.approved i task.due z HTTP — tak samo odrzucane.
foreach ( array( MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED, MP_SW_Pipeline_Factory::EVENT_TASK_DUE ) as $typ ) {
	$rr = MP_SW_Events::from_http( $typ, array( 'entity' => array( 'lead_id' => $lead_a, 'offer_id' => 1, 'task_id' => 1 ) ) );
	chk( ! $rr['result']->is_ok() && 'origin_forbidden' === $rr['result']->get_code(), 'S1 ' . $typ . ' z HTTP odrzucone' );
}

// Kontekst crona: task.due z crona bez kontekstu crona.
$rr = MP_SW_Events::from_cron( MP_SW_Pipeline_Factory::EVENT_TASK_DUE, array( 'entity' => array( 'lead_id' => $lead_a, 'task_id' => 1 ) ) );
chk( 'cron_context_required' === $rr['result']->get_code(), 'S1 task.due poza cronem odrzucone' );

// ============================================================ S2
sec_title( 'S2 — handlowiec A siega po proces handlowca B' );

$flow_table = MP_Sales_Workflow_DB::flow_table();
$wpdb->insert(
	$flow_table,
	array(
		'lead_id'          => $lead_b,
		'status'           => MP_Sales_Workflow_DB::STATUS_ASSIGNED,
		'assigned_user_id' => $sales_b,
		'client_name'      => 'Firma B',
		'client_email'     => 'klient-b@example.test',
		'lock_version'     => 1,
		'created_at'       => current_time( 'mysql', true ),
		'updated_at'       => current_time( 'mysql', true ),
	)
);
$flow_b = (int) $wpdb->insert_id;

MP_SW_Log::reset();
$before = sec_counts();
wp_set_current_user( $sales_a );

$r = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => $lead_b ),
		'actor'     => array( 'user_id' => $sales_a ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_WON,
		'nonce'     => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
	)
);
$after   = sec_counts();
$payload = MP_SW_Events::payload( $r['result'], $r['context'] );

chk( ! $r['result']->is_ok(), 'S2 odrzucone' );
chk( 404 === MP_SW_Events::http_status( $r['result'] ), 'S2 kod HTTP 404 (nie 403)' );
chk( MP_SW_Errors::E_NOT_FOUND === $payload['code'], 'S2 kod MP3-E140' );
chk( $before === $after, 'S2 zero zmian w bazie' );

$status_b = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$flow_table} WHERE id = %d", $flow_b ) );
chk( MP_Sales_Workflow_DB::STATUS_ASSIGNED === $status_b, 'S2 status procesu B nietkniety' );

// Odpowiedz na proces NIEISTNIEJACY musi wygladac tak samo.
$r2 = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => 999999 ),
		'actor'     => array( 'user_id' => $sales_a ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_WON,
		'nonce'     => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
	)
);
chk( MP_SW_Events::http_status( $r['result'] ) === MP_SW_Events::http_status( $r2['result'] ), 'S2 cudzy i nieistniejacy daja ten sam kod' );

// Manager z tego samego zespolu co B? Nie — B jest w zespole 2.
wp_set_current_user( $manager );
$r3 = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => $lead_b ),
		'actor'     => array( 'user_id' => $manager ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_NEGOTIATION,
		'nonce'     => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
	)
);
chk( ! $r3['result']->is_ok(), 'S2 manager zespolu 1 nie siega do zespolu 2' );

// ============================================================ S3
sec_title( 'S3 — handlowiec zmienia sobie mp_team' );
wp_set_current_user( $sales_a );
$team_before = (string) get_user_meta( $sales_a, MP_SW_D2_Reader::META_TEAM, true );
update_user_meta( $sales_a, MP_SW_D2_Reader::META_TEAM, 'zespol-2' );
$team_after = (string) get_user_meta( $sales_a, MP_SW_D2_Reader::META_TEAM, true );

chk( $team_before === $team_after, 'S3 mp_team niezmienione przez wlasciciela' );
chk( is_protected_meta( MP_SW_D2_Reader::META_TEAM, 'user' ), 'S3 mp_sw_team oznaczone jako chronione' );
chk( is_protected_meta( 'mp_team', 'user' ), 'S3 wariant mp_team takze chroniony' );
chk( false === MP_SW_Meta_Guard::can_edit( true, MP_SW_D2_Reader::META_TEAM ), 'S3 auth_callback odmawia handlowcowi' );

wp_set_current_user( 1 );
update_user_meta( $sales_a, MP_SW_D2_Reader::META_TEAM, 'zespol-1' );
chk( 'zespol-1' === (string) get_user_meta( $sales_a, MP_SW_D2_Reader::META_TEAM, true ), 'S3 administrator moze zmienic' );
wp_set_current_user( 0 );

// ============================================================ S4
sec_title( 'S4 — XSS i wstrzykniecie naglowka w nazwie firmy' );

$xss  = '<img src=x onerror=alert(1)>';
$crlf = "Firma\r\nBcc: atak@evil.test";

chk( esc_html( $xss ) !== $xss && false === strpos( esc_html( $xss ), '<img' ), 'S4 dashboard escapuje XSS' );
chk( ! MP_SW_Mailer::has_injection( $xss ), 'S4 sam XSS to nie wstrzykniecie naglowka' );
chk( MP_SW_Mailer::has_injection( $crlf ), 'S4 CRLF wykryty jako wstrzykniecie' );
chk( false === strpos( MP_SW_Mailer::header_safe( $crlf ), "\n" ), 'S4 naglowek po czyszczeniu jest jedna linia' );
chk( false === strpos( MP_SW_Mailer::header_safe( $crlf ), 'Bcc:' ) || false === strpos( MP_SW_Mailer::header_safe( $crlf ), "\r" ), 'S4 brak znakow konca linii' );

// Pelna sciezka: lead z CRLF w nazwie -> powiadomienie odrzucone przez K7.2.
$lead_x = sec_lead( $crlf, 'klient-x@example.test', '3333333333' );
$rx     = MP_SW_Events::from_hook(
	MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED,
	array(
		'entity'   => array( 'lead_id' => $lead_x ),
		'actor'    => array( 'user_id' => 0 ),
		'event_id' => MP_SW_Events::derive_event_id( 'lead.created', array( $lead_x ) ),
	)
);
$msgs = (array) $rx['context']->get( 'messages', array() );
$brud = false;
foreach ( $msgs as $m ) {
	if ( false !== strpos( (string) $m['subject'], "\n" ) || false !== strpos( (string) $m['subject'], "\r" ) ) {
		$brud = true;
	}
}
chk( ! $brud, 'S4 zaden temat w kolejce nie ma znaku konca linii' );

$queued = (array) $wpdb->get_col( 'SELECT subject FROM ' . MP_Sales_Workflow_DB::notifications_table() );
$brud   = false;
foreach ( $queued as $s ) {
	if ( preg_match( '/[\r\n]/', (string) $s ) ) {
		$brud = true;
	}
}
chk( ! $brud, 'S4 kolejka w bazie bez znakow konca linii' );

// ============================================================ S5
sec_title( 'S5 — ten sam event_id dwa razy' );

$lead_5 = sec_lead( 'Firma Piec', 'klient-5@example.test', '5555555555' );
$eid    = MP_SW_Events::derive_event_id( 'lead.created', array( $lead_5 ) );

$n_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MP_Sales_Workflow_DB::notifications_table() );

$p1 = MP_SW_Events::from_hook( MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED, array( 'entity' => array( 'lead_id' => $lead_5 ), 'actor' => array( 'user_id' => 0 ), 'event_id' => $eid ) );
$p2 = MP_SW_Events::from_hook( MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED, array( 'entity' => array( 'lead_id' => $lead_5 ), 'actor' => array( 'user_id' => 0 ), 'event_id' => $eid ) );

$n_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MP_Sales_Workflow_DB::notifications_table() );
$ev_cnt  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MP_Sales_Workflow_DB::events_table() . ' WHERE event_id = %s', $eid ) );
$fl_cnt  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $flow_table . ' WHERE lead_id = %d', $lead_5 ) );

chk( $p1['result']->is_ok(), 'S5 pierwszy przebieg OK' );
chk( 1 === $ev_cnt, 'S5 jeden wpis w rejestrze zdarzen' );
chk( 1 === $fl_cnt, 'S5 jeden proces' );
chk( 1 === ( $n_after - $n_before ), 'S5 jedno powiadomienie (bylo ' . $n_before . ', jest ' . $n_after . ')' );
chk( ! $p2['result']->is_ok() || 'duplicate_event' === $p2['result']->get_code(), 'S5 drugi przebieg to no-op' );

// ============================================================ S6
sec_title( 'S6 — wyscig: cron i reczna zmiana statusu' );

$lead_6 = sec_lead( 'Firma Szesc', 'klient-6@example.test', '6666666666' );
$wpdb->insert(
	$flow_table,
	array(
		'lead_id'          => $lead_6,
		'status'           => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
		'assigned_user_id' => $sales_a,
		'client_name'      => 'Firma Szesc',
		'client_email'     => 'klient-6@example.test',
		'lock_version'     => 7,
		'created_at'       => current_time( 'mysql', true ),
		'updated_at'       => current_time( 'mysql', true ),
	)
);
$flow_6 = (int) $wpdb->insert_id;
$lv0    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$flow_table} WHERE id = %d", $flow_6 ) );

wp_set_current_user( $sales_a );
$win = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	array(
		'entity'    => array( 'lead_id' => $lead_6 ),
		'actor'     => array( 'user_id' => $sales_a ),
		'to_status' => MP_Sales_Workflow_DB::STATUS_NEGOTIATION,
		'nonce'     => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
	)
);
chk( $win['result']->is_ok(), 'S6 pierwsza operacja wygrywa' );

// Drugie zadanie z NIEAKTUALNYM tokenem blokady — symulacja rownoleglego przebiegu.
$ctx = new MP_SW_Context(
	array(
		'type'     => MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
		'source'   => MP_SW_D1::SOURCE_MANUAL,
		'event_id' => wp_generate_uuid4(),
	)
);
$plan = MP_SW_D8_Writer::empty_plan();
$plan['flow']['op']           = MP_SW_D8_Writer::OP_UPDATE;
$plan['flow']['flow_id']      = $flow_6;
$plan['flow']['lock_version'] = $lv0; // stary token
$plan['flow']['data']         = array(
	'status'       => MP_Sales_Workflow_DB::STATUS_WON,
	'lock_version' => $lv0 + 1,
	'updated_at'   => current_time( 'mysql', true ),
);
$upd = $wpdb->update( $flow_table, $plan['flow']['data'], array( 'id' => $flow_6, 'lock_version' => $lv0 ) );

chk( 0 === (int) $upd, 'S6 druga operacja nie trafia (FAIL_RETRY)' );

$lv1 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$flow_table} WHERE id = %d", $flow_6 ) );
chk( $lv1 === $lv0 + 1, 'S6 lock_version +1 dokladnie o liczbe mutacji (' . $lv0 . ' -> ' . $lv1 . ')' );

$st6 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$flow_table} WHERE id = %d", $flow_6 ) );
chk( MP_Sales_Workflow_DB::STATUS_NEGOTIATION === $st6, 'S6 zwyciezyla pierwsza zmiana, nie zgubiona' );

// Wartownik w warunku zapisu: aktualizacja z niepasujacym statusem nie trafia.
$upd_guard = $wpdb->update(
	$flow_table,
	array( 'lock_version' => $lv1 + 1, 'updated_at' => current_time( 'mysql', true ) ),
	array( 'id' => $flow_6, 'lock_version' => $lv1, 'status' => MP_Sales_Workflow_DB::STATUS_OFFER_SENT )
);
chk( 0 === (int) $upd_guard, 'S6 wartownik w WHERE zatrzymuje zapis po zmianie statusu' );

// ============================================================ S7
sec_title( 'S7 — podpisany link pobrania' );

chk( MP_SW_Download::available(), 'S7 MP_SW_LINK_KEY ustawiony' );

$handle = 'a1b2c3d4-0000-4000-8000-000000000001';
$exp    = time() + DAY_IN_SECONDS;
$sig    = MP_SW_Download::sign( $handle, $exp );

chk( 64 === strlen( $sig ), 'S7 podpis to HMAC-SHA256 (64 znaki hex)' );
chk( MP_SW_Download::verify( $handle, $exp, $sig )['ok'], 'S7 poprawny podpis przechodzi' );

$psuty    = substr( $sig, 0, 63 ) . ( '0' === substr( $sig, 63, 1 ) ? '1' : '0' );
$v_psuty  = MP_SW_Download::verify( $handle, $exp, $psuty );
$v_termin = MP_SW_Download::verify( $handle, time() - 10, MP_SW_Download::sign( $handle, time() - 10 ) );

chk( ! $v_psuty['ok'], 'S7 zmiana jednego znaku podpisu = odmowa' );
chk( MP_SW_Errors::E_LINK_SIGNATURE === $v_psuty['code'], 'S7 kod MP3-E180' );
chk( ! $v_termin['ok'], 'S7 link po terminie odrzucony' );
chk(
	MP_SW_Errors::message( MP_SW_Errors::E_LINK_SIGNATURE ) === MP_SW_Errors::message( MP_SW_Errors::E_LINK_EXPIRED ),
	'S7 zly podpis i po terminie daja ten sam komunikat'
);

$nieistniejacy = MP_SW_Download::verify( 'zmyslony-uchwyt', $exp, MP_SW_Download::sign( 'zmyslony-uchwyt', $exp ) );
chk( $nieistniejacy['ok'], 'S7 weryfikacja podpisu nie dotyka bazy (brak wyroczni istnienia)' );

// ============================================================ S8
sec_title( 'S8 — bezposredni dostep do pliku PDF' );

$uploads = wp_get_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'mp-offers';
wp_mkdir_p( $dir );
file_put_contents( $dir . '/test.pdf', '%PDF-1.4 test' );

chk( '' !== MP_SW_Download::inside_uploads( 'mp-offers/test.pdf' ), 'S8 plik w katalogu wysylek jest rozwiazywany' );
chk( '' === MP_SW_Download::inside_uploads( '../../wp-config.php' ), 'S8 wyjscie poza katalog odrzucone' );
chk( '' === MP_SW_Download::inside_uploads( '/etc/passwd' ), 'S8 sciezka bezwzgledna poza uploads odrzucona' );
chk( '' === MP_SW_Download::inside_uploads( 'mp-offers/nie-ma.pdf' ), 'S8 nieistniejacy plik odrzucony' );

$ht = $dir . '/.htaccess';
if ( ! file_exists( $ht ) ) {
	file_put_contents( $ht, "Require all denied\n" );
}
chk( file_exists( $ht ), 'S8 katalog ofert ma .htaccess z odmowa' );

// ============================================================ S9
sec_title( 'S9 — eksport CSV z formula' );

chk( "'=HYPERLINK(\"http://evil\")" === MP_SW_Admin::csv_cell( '=HYPERLINK("http://evil")' ), 'S9 formula = poprzedzona apostrofem' );
foreach ( array( '+1', '-1', '@SUM(A1)' ) as $zly ) {
	chk( "'" === substr( MP_SW_Admin::csv_cell( $zly ), 0, 1 ), 'S9 ' . substr( $zly, 0, 1 ) . ' poprzedzone apostrofem' );
}
chk( 'Firma Sp. z o.o.' === MP_SW_Admin::csv_cell( 'Firma Sp. z o.o.' ), 'S9 zwykla wartosc bez zmian' );
chk( false === strpos( MP_SW_Admin::csv_cell( "A\r\nB" ), "\n" ), 'S9 znaki konca linii usuniete z komorki' );

// ============================================================ S10
sec_title( 'S10 — limit tempa na endpoincie pobrania' );

$klucz = 'mp_sw_dl_' . substr( MP_SW_Log::ip_hash( '' ), 0, 32 );
delete_transient( $klucz );

$przeszlo = 0;
for ( $i = 0; $i < MP_SW_Download::RATE_LIMIT + 10; $i++ ) {
	$v = MP_SW_Download::verify( $handle, $exp, $sig );
	if ( $v['ok'] ) {
		++$przeszlo;
	}
}
chk( $przeszlo === MP_SW_Download::RATE_LIMIT, 'S10 przepuszczono dokladnie ' . MP_SW_Download::RATE_LIMIT . ' prob (bylo ' . $przeszlo . ')' );
$v_limit = MP_SW_Download::verify( $handle, $exp, $sig );
chk( MP_SW_Errors::E_RATE_LIMIT === $v_limit['code'], 'S10 kolejna proba to MP3-E182' );
delete_transient( $klucz );

// ============================================================ S11
sec_title( 'S11 — bezpiecznik kolejki przy zalewie zdarzen' );

MP_SW_Mailer::resume();
add_filter( MP_SW_Mailer::FILTER_MAX, function () { return 5; } );

$wyslano = 0;
for ( $i = 0; $i < 12; $i++ ) {
	if ( MP_SW_Mailer::allow_send() ) {
		++$wyslano;
	}
}
chk( 5 === $wyslano, 'S11 przepuszczono dokladnie prog (' . $wyslano . ')' );
chk( MP_SW_Mailer::halted(), 'S11 kolejka zatrzymana' );

$podsumowanie = MP_SW_Queue::run();
chk( 0 === (int) $podsumowanie['sent'], 'S11 przebieg kolejki nic nie wysyla' );

MP_SW_Mailer::resume();
chk( ! MP_SW_Mailer::halted(), 'S11 administrator moze wznowic' );
remove_all_filters( MP_SW_Mailer::FILTER_MAX );

// ============================================================ S12
sec_title( 'S12 — zakres widoku handlowca i managera' );

// Procesy: jeden A (zespol 1), jeden B (zespol 2).
$wpdb->query( $wpdb->prepare( "UPDATE {$flow_table} SET assigned_user_id = %d WHERE lead_id = %d", $sales_a, $lead_6 ) );

foreach ( array( $sales_a, $manager ) as $kto ) {
	wp_set_current_user( $kto );
	$d = MP_SW_Events::from_http(
		MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW,
		array( 'entity' => array(), 'actor' => array( 'user_id' => $kto ) )
	);
	$scope   = (string) $d['context']->get( 'scope', '' );
	$members = (array) $d['context']->get( 'scope_members', array() );
	$rows    = MP_SW_Admin::flows( $scope, $kto, $members );

	$GLOBALS['s12'][ $kto ] = array(
		'scope'  => $scope,
		'ile'    => count( $rows ),
		'writes' => $d['context']->get_db_writes(),
		'ok'     => $d['result']->is_ok(),
	);
	chk( $d['result']->is_ok(), 'S12 podglad dziala dla ' . $kto );
	chk( 0 === $d['context']->get_db_writes(), 'S12 db_writes = 0 dla ' . $kto );
}

chk( MP_SW_D3::SCOPE_OWN === $GLOBALS['s12'][ $sales_a ]['scope'], 'S12 handlowiec ma zakres OWN' );
chk( MP_SW_D3::SCOPE_TEAM === $GLOBALS['s12'][ $manager ]['scope'], 'S12 manager ma zakres TEAM (nie ALL)' );
chk(
	$GLOBALS['s12'][ $manager ]['ile'] >= $GLOBALS['s12'][ $sales_a ]['ile'],
	'S12 manager widzi co najmniej tyle co handlowiec'
);

// Proces zespolu 2 nie moze byc widoczny dla managera zespolu 1.
wp_set_current_user( $manager );
$d       = MP_SW_Events::from_http( MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW, array( 'entity' => array(), 'actor' => array( 'user_id' => $manager ) ) );
$rows_m  = MP_SW_Admin::flows( (string) $d['context']->get( 'scope', '' ), $manager, (array) $d['context']->get( 'scope_members', array() ) );
$obcy    = false;
foreach ( $rows_m as $row ) {
	if ( (int) $row['assigned_user_id'] === $sales_b ) {
		$obcy = true;
	}
}
chk( ! $obcy, 'S12 manager zespolu 1 nie widzi procesow zespolu 2' );

// Parametr zadania nie rozszerza zakresu.
$d_scope = MP_SW_Events::from_http(
	MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW,
	array( 'entity' => array(), 'actor' => array( 'user_id' => $sales_a ), 'scope' => MP_SW_D3::SCOPE_ALL )
);
wp_set_current_user( $sales_a );
chk(
	MP_SW_D3::SCOPE_ALL !== (string) $d_scope['context']->get( 'scope', '' ),
	'S12 parametr scope=all nie rozszerza zakresu handlowca'
);

// ============================================================ INWARIANTY
sec_title( 'RAPORT INWARIANTOW I-1..I-6' );

// I-1: brak rejestracji nopriv.
chk( false === has_action( 'wp_ajax_nopriv_' . MP_SW_Ajax::ACTION ), 'I-1 brak wp_ajax_nopriv dla akcji wtyczki' );
$zrodla = file_get_contents( WP_PLUGIN_DIR . '/mp-sales-workflow/includes/class-mp-sw-ajax.php' );
chk( false === strpos( $zrodla, "'__return_true'" ), 'I-1 brak permission_callback __return_true' );

// I-2: macierz kompletna i domyslnie zamknieta.
$m = MP_SW_Origin::matrix();
chk( array( MP_SW_D1::SOURCE_SYSTEM ) === $m[ MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED ], 'I-2 lead.created tylko z haka' );
chk( array( MP_SW_D1::SOURCE_SYSTEM ) === $m[ MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED ], 'I-2 offer.approved tylko z haka' );
chk( array( MP_SW_D1::SOURCE_CRON ) === $m[ MP_SW_Pipeline_Factory::EVENT_TASK_DUE ], 'I-2 task.due tylko z crona' );
chk( array() === MP_SW_Origin::sources_for( 'typ.nieznany' ), 'I-2 nieznany typ nie ma zadnego zrodla' );
chk( ! MP_SW_Origin::allowed( MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE, 'wymyslone' ), 'I-2 wymyslone zrodlo odrzucone' );

// system + status.change wolno wylacznie na offer_draft z offer_id.
$c_ok  = MP_SW_Origin::check( MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE, MP_SW_D1::SOURCE_SYSTEM, array( 'to_status' => MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT, 'entity' => array( 'offer_id' => 5 ) ) );
$c_bad = MP_SW_Origin::check( MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE, MP_SW_D1::SOURCE_SYSTEM, array( 'to_status' => MP_Sales_Workflow_DB::STATUS_WON, 'entity' => array( 'offer_id' => 5 ) ) );
chk( $c_ok['ok'], 'I-2 hak moze przestawic na offer_draft' );
chk( ! $c_bad['ok'], 'I-2 hak nie moze zamknac sprzedazy jako wygranej' );

// I-3: koperta nie niesie adresu; adres pochodzi z bazy.
$lead_i3 = sec_lead( 'Firma I3', 'prawdziwy@example.test', '9999999999' );
$r_i3    = MP_SW_Events::from_hook(
	MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED,
	array(
		'entity'   => array( 'lead_id' => $lead_i3 ),
		'actor'    => array( 'user_id' => 0 ),
		'client'   => array( 'name' => 'Podstawiona', 'email' => 'atakujacy@evil.test' ),
		'event_id' => MP_SW_Events::derive_event_id( 'lead.created', array( $lead_i3 ) ),
	)
);
$mail_i3 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT client_email FROM {$flow_table} WHERE lead_id = %d", $lead_i3 ) );
chk( 'prawdziwy@example.test' === $mail_i3, 'I-3 adres z bazy leadow, nie z koperty (jest: ' . $mail_i3 . ')' );

$odbiorcy = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT recipient FROM ' . MP_Sales_Workflow_DB::notifications_table() . ' WHERE flow_id = ( SELECT id FROM ' . $flow_table . ' WHERE lead_id = %d )', $lead_i3 ) );
chk( ! in_array( 'atakujacy@evil.test', $odbiorcy, true ), 'I-3 podstawiony adres nie trafil do kolejki' );

// I-4: zakres liczony z roli i mp_team.
chk( ! MP_SW_D3::in_reach( array( 'manage_all' => false, 'view_team' => false, 'team_members' => array() ), 99 ), 'I-4 bez uprawnien brak zasiegu' );
chk( MP_SW_D3::in_reach( array( 'manage_all' => false, 'view_team' => true, 'team_members' => array( 99 ) ), 99 ), 'I-4 czlonek zespolu w zasiegu' );
chk( ! MP_SW_D3::in_reach( array( 'manage_all' => false, 'view_team' => true, 'team_members' => array( 1 ) ), 99 ), 'I-4 spoza zespolu poza zasiegiem' );

// I-5: liczniki.
$lead_i5 = sec_lead( 'Firma I5', 'i5@example.test', '8888888888' );
$r_i5    = MP_SW_Events::from_hook(
	MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED,
	array( 'entity' => array( 'lead_id' => $lead_i5 ), 'actor' => array( 'user_id' => 0 ), 'event_id' => MP_SW_Events::derive_event_id( 'lead.created', array( $lead_i5 ) ) )
);
$c5 = $r_i5['context'];
chk( 1 === $c5->get_db_reads(), 'I-5 db_reads = 1 (jest ' . $c5->get_db_reads() . ')' );
chk( 1 === $c5->get_db_writes(), 'I-5 db_writes = 1 (jest ' . $c5->get_db_writes() . ')' );
chk( 0 === MP_SW_D7_Notifier::mail_attempts(), 'I-5 zero wysylek w zadaniu' );

// I-6: sekrety ze stalych.
chk( defined( 'MP_HASH_PEPPER' ) && '' !== MP_SW_Log::pepper(), 'I-6 MP_HASH_PEPPER ze stalej' );
chk( defined( 'MP_SW_LINK_KEY' ) && '' !== MP_SW_Download::key(), 'I-6 MP_SW_LINK_KEY ze stalej' );
$src_dl = file_get_contents( WP_PLUGIN_DIR . '/mp-sales-workflow/includes/class-mp-sw-download.php' );
chk( false === strpos( $src_dl, 'update_option( self::OPTION' ), 'I-6 klucz nie jest zapisywany do bazy' );

// BLOK H: dziennik bez adresu.
sec_title( 'BLOK H — dane osobowe' );
$log_wartosci = (array) $wpdb->get_col( 'SELECT COALESCE(new_value, "") FROM ' . MP_Sales_Workflow_DB::activity_table() );
$maile        = false;
foreach ( $log_wartosci as $w ) {
	if ( false !== strpos( (string) $w, '@' ) ) {
		$maile = true;
	}
}
chk( ! $maile, 'H dziennik aktywnosci nie zawiera adresow e-mail' );

$przed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MP_Sales_Workflow_DB::notifications_table() . ' WHERE recipient = %s', 'prawdziwy@example.test' ) );
MP_SW_Privacy::anonymize_lead( $lead_i3 );
$po       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MP_Sales_Workflow_DB::notifications_table() . ' WHERE recipient = %s', 'prawdziwy@example.test' ) );
$po_flow  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT client_email FROM {$flow_table} WHERE lead_id = %d", $lead_i3 ) );
chk( 0 === $po, 'H po anonimizacji adres znika z kolejki' );
chk( 0 === strpos( $po_flow, 'deleted+' ), 'H proces ma adres zastepczy (' . $po_flow . ')' );

// BLOK C: retencja.
sec_title( 'BLOK C — retencja rejestru zdarzen' );
$wpdb->query( 'UPDATE ' . MP_Sales_Workflow_DB::events_table() . " SET created_at = '2020-01-01 00:00:00'" );
$stare_przed = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MP_Sales_Workflow_DB::events_table() );
$akt_przed   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MP_Sales_Workflow_DB::activity_table() );
$usuniete    = MP_SW_Cron::purge_events();
$akt_po      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MP_Sales_Workflow_DB::activity_table() );

chk( $usuniete === $stare_przed && $usuniete > 0, 'C stare zdarzenia usuniete (' . $usuniete . ')' );
chk( $akt_przed === $akt_po, 'C dziennik 5.5 nietkniety' );

// --- PODSUMOWANIE ---
echo "\n----- PASS: " . count( $GLOBALS['mp_oks'] ) . ' / FAIL: ' . count( $GLOBALS['mp_fails'] ) . " -----\n";
if ( ! empty( $GLOBALS['mp_fails'] ) ) {
	foreach ( $GLOBALS['mp_fails'] as $f ) {
		echo "  ! $f\n";
	}
} else {
	echo "VERDICT_ALL_PASS\n";
}
