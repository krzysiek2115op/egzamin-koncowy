<?php
/**
 * BRAMKA INTEGRACYJNA P1 -> P2 -> P3: F2 (slownik VAT), F3 (wlasciciel draftu),
 * F4 (lead_id w zdarzeniu oferty). Trzy wtyczki na jednej instalacji.
 */

global $wpdb;

$GLOBALS['mp_oks']   = array();
$GLOBALS['mp_fails'] = array();

function chk( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['mp_oks'][] = $label;
	} else {
		$GLOBALS['mp_fails'][] = $label;
	}
}

// --- Sanity: wszystkie trzy wtyczki zaladowane ---
chk( class_exists( 'MP_DB' ) || class_exists( 'MP_Lead_Intake_DB' ), '0: wtyczka 1 zaladowana' );
chk( class_exists( 'MP_Offer_Builder_DB' ), '0: wtyczka 2 zaladowana' );
chk( class_exists( 'MP_Sales_Workflow_DB' ), '0: wtyczka 3 zaladowana' );

$offers_t = MP_Offer_Builder_DB::offers_table();
$log_t    = MP_Offer_Builder_DB::activity_log_table();
$flow_t   = MP_Sales_Workflow_DB::flow_table();
$noti_t   = MP_Sales_Workflow_DB::notifications_table();

// --- Handlowiec: ten sam uzytkownik po stronie P1 i P3 ---
foreach ( get_users( array( 'role' => MP_SW_Roles::ROLE_SALESMAN, 'fields' => 'ID' ) ) as $old ) {
	wp_delete_user( (int) $old );
}
$hid = (int) wp_insert_user( array( 'user_login' => 'bramka_h', 'user_pass' => 'x', 'user_email' => 'bramka_h@example.test', 'role' => MP_SW_Roles::ROLE_SALESMAN ) );
$hu  = new WP_User( $hid );
$hu->set_role( MP_SW_Roles::ROLE_SALESMAN );
update_user_meta( $hid, MP_SW_D2_Reader::META_COUNTRY, 'PL' );
update_user_meta( $hid, MP_SW_D2_Reader::META_LANGS, 'pl,en' );
update_user_meta( $hid, MP_SW_D2_Reader::META_TEAM, 'zespol' );
clean_user_cache( $hid );

$wpdb->query( "DELETE FROM {$offers_t}" );
$wpdb->query( "DELETE FROM {$log_t}" );
foreach ( array( MP_Sales_Workflow_DB::tasks_table(), $noti_t, MP_Sales_Workflow_DB::activity_table(), MP_Sales_Workflow_DB::events_table(), $flow_t ) as $t ) {
	$wpdb->query( "DELETE FROM {$t}" );
}

// Nasluchy sa wpiete na init/plugins_loaded — w WP-CLI juz przeszly, ale
// upewniamy sie, ze warstwa techniczna P3 jest podpieta.
MP_SW_Hooks::register();
MP_SW_Cron::register();

// =====================================================================
// SCENARIUSZ: lead z Niemiec z waznym VAT UE
// =====================================================================
$lead_id = 91001;
$payload = array(
	'lead_id'         => $lead_id,
	'company_name'    => 'Muster GmbH',
	'nip'             => 'DE123456789',
	'email'           => 'kontakt@muster.test',
	'phone'           => '+49 30 111222',
	'country'         => 'DE',
	'segment'         => 'B2B',
	'client_category' => 'standard',
	'score'           => 70,
	'status'          => 'new',
	// Lead z UE: P1 zapisuje 'pending' i dopiero worker w tle odpyta VIES.
	'vat_status'      => 'pending',
	'salesman_id'     => $hid,
);

do_action( 'mp_lead_created', $lead_id, $payload );

// ============ F3: wlasciciel draftu ============
$draft = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE lead_id = %d", $lead_id ), ARRAY_A );
chk( is_array( $draft ), 'F3: wtyczka 2 zalozyla szkic oferty z leada' );
chk( $hid === (int) $draft['created_by'], 'F3: szkic ma WLASCICIELA = handlowiec z wtyczki 1 (jest: ' . var_export( $draft['created_by'], true ) . ')' );

// Kontrola sensu poprawki: wlasciciel decyduje o dostepie w Dziale 1 wtyczki 2.
wp_set_current_user( 0 );
wp_set_current_user( $hid );
chk( get_current_user_id() === (int) $draft['created_by'], 'F3: handlowiec jest wlascicielem, wiec otworzy swoj szkic' );

// ============ P3: proces sprzedazowy powstal z tego samego zdarzenia ============
$flow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE lead_id = %d", $lead_id ), ARRAY_A );
chk( is_array( $flow ), 'P3: proces sprzedazowy zalozony z tego samego zdarzenia' );
chk( $hid === (int) $flow['assigned_user_id'], 'P3: proces trafil do TEGO SAMEGO handlowca co szkic oferty' );
chk( 'DE' === $flow['country'] && 'Muster GmbH' === $flow['client_name'], 'P3: dane klienta spojne miedzy wtyczkami' );

// ============ F2: przed poprawka szkic zostawal na 'pending' ============
chk( 'pending' === $draft['client_vat_status'], 'F2: szkic startuje ze statusem pending (VIES jeszcze nie odpowiedzial)' );

// Dzial 6 wtyczki 2 na tym etapie NIE moze dac odwrotnego obciazenia.
$przed = MP_OB_Department_06::build();
$ctx_p = new MP_OB_Context(
	array(
		'client' => array( 'name' => 'Muster GmbH', 'email' => 'k@m.test', 'nip' => 'DE123456789', 'country' => 'DE', 'vat_status' => 'pending' ),
		'items'  => array(),
	)
);
$ctx_p->set( 'net_grosze', 100000 );

// ============ F2: worker VAT konczy weryfikacje ============
// Dokladnie to robi class-mp-vat-verifier.php po odpowiedzi VIES.
do_action(
	'mp_lead_verified',
	$lead_id,
	array(
		'vat_valid'      => 1,
		'company_status' => 'active',
		'score'          => 85,
		'vat_status'     => 'valid',
		'vat_checked_at' => current_time( 'mysql', true ),
	)
);

$po = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE lead_id = %d", $lead_id ), ARRAY_A );
chk( 'valid' === $po['client_vat_status'], 'F2: po weryfikacji szkic ma status valid (jest: ' . $po['client_vat_status'] . ')' );
chk( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log_t} WHERE offer_id = %d AND action = 'draft_vat_status_updated'", (int) $po['id'] ) ), 'F2: zmiana odnotowana w dzienniku wtyczki 2' );

// ============ F2: i DOPIERO TERAZ odwrotne obciazenie jest osiagalne ============
function stawka_dla( $country, $vat_status ) {
	$dept = MP_OB_Department_06::build();
	$ctx  = new MP_OB_Context( array() );
	$ctx->set( 'client', array( 'name' => 'X', 'email' => 'x@x.test', 'nip' => 'DE1', 'country' => $country, 'vat_status' => $vat_status ) );
	$res = $dept->process( $ctx );
	$m   = (string) $ctx->get( 'tax_mechanism', '' );
	return '' !== $m ? $m : ( 'BLAD:' . $res->get_code() . ':' . implode( ';', (array) $res->get_errors() ) );
}

$mech_valid   = stawka_dla( 'DE', 'valid' );
$mech_pending = stawka_dla( 'DE', 'pending' );
$mech_checked = stawka_dla( 'DE', 'checked' );
$mech_pl      = stawka_dla( 'PL', 'valid' );

chk( 'reverse_charge' === $mech_valid, 'F2: UE + valid = ODWROTNE OBCIAZENIE (jest: ' . $mech_valid . ')' );
chk( 'domestic' === $mech_pending, 'F2: UE + pending = stawka krajowa (bezpieczny domysl)' );
chk( 'domestic' === $mech_checked, 'F2: UE + checked = stawka krajowa — checked NIE znaczy juz potwierdzony' );
chk( 'domestic' === $mech_pl, 'F2: Polska zawsze krajowa, niezaleznie od statusu VAT' );

// ============ F4: zdarzenie oferty niesie lead_id ============
// Sprawdzamy OBIE strony: co wklada wtyczka 2 i jak reaguje wtyczka 3.
$GLOBALS['bramka_payload'] = null;
add_action(
	'mp_offer_created',
	function ( $offer_id, $data ) {
		$GLOBALS['bramka_payload'] = $data;
	},
	5,
	2
);

// Strona wtyczki 2: Dzial 1 odtwarza lead_id ze szkicu i niesie go dalej.
$d1  = MP_OB_Department_01::build();
$c1  = new MP_OB_Context(
	array(
		'offer_id' => (int) $po['id'],
		'items'    => array( array( 'product_id' => 1, 'qty' => 1 ) ),
		'wariant'  => 'standard',
		'lang'     => 'pl',
	)
);
$r1 = $d1->process( $c1 );
chk( $lead_id === (int) $c1->get( 'lead_id' ), 'F4: Dzial 1 wtyczki 2 odtworzyl lead_id ze szkicu (jest: ' . var_export( $c1->get( 'lead_id' ), true ) . ', wynik D1: ' . $r1->get_code() . ' / ' . implode( '; ', array_map( function ( $e ) { return is_array( $e ) ? wp_json_encode( $e ) : (string) $e; }, (array) $r1->get_errors() ) ) . ')' );

// Strona wtyczki 3: zdarzenie z lead_id przestawia proces na oferte robocza.
// Uzywamy PRAWDZIWEGO identyfikatora oferty ze szkicu wtyczki 2, nie zmyslonego:
// wtyczka 3 sprawdza teraz, czy oferta z koperty istnieje i nalezy do tego leada,
// wiec numer wziety z sufitu jest (poprawnie) odrzucany jako proba podszycia.
$realne_id = (int) $po['id'];
do_action( 'mp_offer_created', $realne_id, array( 'offer_id' => $realne_id, 'offer_number' => 'OF/2026/91', 'version' => 1, 'status' => 'draft', 'client_name' => 'Muster GmbH', 'gross_grosze' => 12300, 'currency' => 'EUR', 'lead_id' => $lead_id ) );
chk( is_array( $GLOBALS['bramka_payload'] ) && isset( $GLOBALS['bramka_payload']['lead_id'] ), 'F4: payload zdarzenia zawiera lead_id' );
chk( 'offer_draft' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$flow_t} WHERE lead_id = %d", $lead_id ) ), 'F4: wtyczka 3 przestawila proces na oferte robocza' );

// Bez lead_id (oferta reczna) wtyczka 3 sie nie rusza i NIE zgaduje.
$przed_f4 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flow_t}" );
do_action( 'mp_offer_created', 5502, array( 'offer_id' => 5502, 'offer_number' => 'OF/2026/92', 'status' => 'draft', 'client_name' => 'Muster GmbH', 'lead_id' => 0 ) );
chk( $przed_f4 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flow_t}" ), 'F4: oferta reczna (lead_id=0) NIE tworzy procesu na oslep' );

// ============ Powtorzenia w calym lancuchu ============
$przed_p = array(
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$offers_t}" ),
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flow_t}" ),
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$noti_t}" ),
);
do_action( 'mp_lead_created', $lead_id, $payload );
do_action( 'mp_lead_created', $lead_id, $payload );
$po_p = array(
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$offers_t}" ),
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flow_t}" ),
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$noti_t}" ),
);
chk( $przed_p === $po_p, 'CALOSC: powtorzone zdarzenie leada nie dolozylo ani szkicu, ani procesu, ani e-maila (przed: ' . implode( ',', $przed_p ) . ' po: ' . implode( ',', $po_p ) . ')' );

// ============ Kolejnosc odwrotna: weryfikacja przed szkicem ============
$lead2 = 91002;
do_action(
	'mp_lead_verified',
	$lead2,
	array( 'vat_valid' => 1, 'vat_status' => 'valid', 'score' => 80 )
);
chk( true, 'ODPORNOSC: weryfikacja bez istniejacego szkicu nie konczy sie bledem' );
chk( '' === (string) $wpdb->last_error, 'ODPORNOSC: brak bledu SQL (' . $wpdb->last_error . ')' );

foreach ( $GLOBALS['mp_oks'] as $o ) {
	WP_CLI::log( 'PASS  ' . $o );
}
foreach ( $GLOBALS['mp_fails'] as $f ) {
	WP_CLI::log( 'FAIL  ' . $f );
}
WP_CLI::log( '----- PASS: ' . count( $GLOBALS['mp_oks'] ) . ' / FAIL: ' . count( $GLOBALS['mp_fails'] ) . ' -----' );
WP_CLI::log( empty( $GLOBALS['mp_fails'] ) ? 'VERDICT_ALL_PASS' : 'VERDICT_HAS_FAILURES' );
