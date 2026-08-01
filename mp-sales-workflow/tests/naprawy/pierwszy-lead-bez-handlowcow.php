<?php
/**
 * P3-U18 — pierwszy lead po instalacji ginął, gdy nie było jeszcze handlowców.
 *
 * Uruchamianie: wp eval-file tests/naprawy/pierwszy-lead-bez-handlowcow.php
 *
 * Krytyk K4.2 („pokrycie") kończył pipeline PORAŻKĄ, gdy nikt nie mógł zostać
 * właścicielem procesu — a jedną z takich sytuacji jest stan całkowicie normalny:
 * świeża instalacja, w której administrator nie zdążył jeszcze założyć kont
 * handlowców. Skutek: zgłoszenie z formularza zapisywało się w BD-3, wtyczka 2
 * robiła szkic oferty, a wtyczka 3 nie zakładała procesu W OGÓLE. Ani wiersza
 * w `wp_mp_sw_flow`, ani wpisu w dzienniku aktywności — jedyny ślad szedł do
 * error_log PHP i tylko przy włączonym WP_DEBUG.
 *
 * Nie było tego widać, bo test świeżej instalacji uruchamiano na bazie, w której
 * po WCZEŚNIEJSZYCH przebiegach zostawały konta handlowców z innych testów.
 * Po ich skasowaniu sonda od razu pokazała: „proces sprzedażowy powstał" FAIL,
 * „dziennik aktywności zapisany od pierwszego zdarzenia (kryt. 5.5)" = 0 wpisów.
 *
 * Reguła po naprawie: **proces powstaje zawsze**, a brak właściciela jest stanem
 * widocznym (kolumna `assigned_user_id` pusta, powód w `assign_reason`, wpis
 * w dzienniku), nie powodem do wyrzucenia zgłoszenia. Panel managera ma już
 * licznik „bez właściciela" — czyli produkt tę sytuację przewidywał wszędzie
 * poza miejscem, które o niej decydowało.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_pl'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegół.
 * @return bool
 */
function pl_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_pl']['pass'];
		$GLOBALS['mp_pl']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_pl']['fail'];
	$GLOBALS['mp_pl']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Zakłada lead w BD-3 i puszcza zdarzenie, na które reaguje wtyczka 3.
 *
 * @param int    $lead_id Identyfikator leada.
 * @param string $firma   Nazwa firmy.
 * @return void
 */
function pl_zglos_leada( $lead_id, $firma ) {
	global $wpdb;

	if ( class_exists( 'MP_Lead_Intake_DB' ) ) {
		$wpdb->insert( // phpcs:ignore WordPress.DB
			MP_Lead_Intake_DB::leads_table(),
			array(
				'id'           => $lead_id,
				'company_name' => $firma,
				'nip'          => '888' . str_pad( (string) ( $lead_id % 10000000 ), 7, '0', STR_PAD_LEFT ),
				'email'        => 'bezhandlowca+' . $lead_id . '@example.test',
				'country'      => 'PL',
				'status'       => 'new',
			)
		);
	}

	do_action(
		'mp_lead_created',
		$lead_id,
		array(
			'lead_id'      => $lead_id,
			'company_name' => $firma,
			'email'        => 'bezhandlowca+' . $lead_id . '@example.test',
			'country'      => 'PL',
			'lang'         => 'pl',
		)
	);
}

/**
 * Sprząta po leadzie: proces, dziennik i wiersz w BD-3.
 *
 * @param int $lead_id Identyfikator leada.
 * @return void
 */
function pl_posprzataj( $lead_id ) {
	global $wpdb;

	$flow_t = MP_Sales_Workflow_DB::flow_table();
	$akt_t  = MP_Sales_Workflow_DB::activity_table();

	$flow_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$flow_t} WHERE lead_id = %d", $lead_id ) ); // phpcs:ignore WordPress.DB

	if ( $flow_id > 0 ) {
		$wpdb->delete( $akt_t, array( 'flow_id' => $flow_id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( $flow_t, array( 'id' => $flow_id ) ); // phpcs:ignore WordPress.DB
	}

	if ( class_exists( 'MP_Lead_Intake_DB' ) ) {
		$wpdb->delete( MP_Lead_Intake_DB::leads_table(), array( 'id' => $lead_id ) ); // phpcs:ignore WordPress.DB
	}
}

$flow_t = MP_Sales_Workflow_DB::flow_table();
$akt_t  = MP_Sales_Workflow_DB::activity_table();
$seria  = (int) substr( (string) time(), -6 );

/*
 * Stan „świeża instalacja" odtwarzamy przez ODEBRANIE roli, nie kasowanie kont:
 * konta mogą należeć do innych testów w tym samym przebiegu, a rola wraca na
 * końcu pliku. Kasowanie użytkownika zabrałoby też jego procesy.
 */
$pl_odebrane = array();
$pl_query    = new WP_User_Query(
	array(
		'role'   => MP_SW_Roles::ROLE_SALESMAN,
		'fields' => array( 'ID' ),
		'number' => -1,
	)
);

foreach ( (array) $pl_query->get_results() as $pl_user ) {
	$pl_konto = new WP_User( (int) $pl_user->ID );
	$pl_konto->remove_role( MP_SW_Roles::ROLE_SALESMAN );
	$pl_odebrane[] = (int) $pl_user->ID;
}

$GLOBALS['mp_pl']['lines'][] = '=== A. zgłoszenie trafia do firmy, która nie ma jeszcze ani jednego handlowca ===';

$lead_a = 970000 + ( $seria % 9000 );
pl_posprzataj( $lead_a );
pl_zglos_leada( $lead_a, 'Pierwszy Klient Sp. z o.o.' );

$proces_a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE lead_id = %d", $lead_a ), ARRAY_A ); // phpcs:ignore WordPress.DB

pl_ok(
	is_array( $proces_a ),
	'A1: proces w BD-1 POWSTAJE, choć nie ma komu go przypisać',
	'brak wiersza dla lead_id=' . $lead_a
);
pl_ok(
	is_array( $proces_a ) && (string) MP_Sales_Workflow_DB::STATUS_NEW === (string) $proces_a['status'],
	'A2: proces stoi w statusie początkowym',
	is_array( $proces_a ) ? (string) $proces_a['status'] : 'brak wiersza'
);
pl_ok(
	is_array( $proces_a ) && 0 === (int) $proces_a['assigned_user_id'],
	'A3: właściciel jest PUSTY — brak handlowca to stan widoczny, nie zmyślony właściciel',
	is_array( $proces_a ) ? (string) $proces_a['assigned_user_id'] : 'brak wiersza'
);

$wpisy_a = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$akt_t} WHERE flow_id = %d",
		is_array( $proces_a ) ? (int) $proces_a['id'] : 0
	)
);

pl_ok(
	$wpisy_a > 0,
	'A4: dziennik aktywności ma wpis od pierwszego zdarzenia (kryterium 5.5)',
	'wpisów: ' . $wpisy_a
);

pl_posprzataj( $lead_a );

$GLOBALS['mp_pl']['lines'][] = '';
$GLOBALS['mp_pl']['lines'][] = '=== B. KONTR-ASERCJA: gdy handlowiec jest, nadal dostaje proces ===';

$handlowiec = wp_insert_user(
	array(
		'user_login' => 'pl_handlowiec_' . $seria,
		'user_email' => 'pl_handlowiec+' . $seria . '@example.test',
		'user_pass'  => wp_generate_password( 24, true ),
		'role'       => MP_SW_Roles::ROLE_SALESMAN,
	)
);
$handlowiec = is_wp_error( $handlowiec ) ? 0 : (int) $handlowiec;

if ( $handlowiec > 0 ) {
	update_user_meta( $handlowiec, MP_SW_D2_Reader::META_COUNTRY, 'PL' );
	update_user_meta( $handlowiec, MP_SW_D2_Reader::META_LANGS, 'pl' );
	update_user_meta( $handlowiec, MP_SW_D2_Reader::META_TEAM, 'zespol-testowy' );
	update_user_meta( $handlowiec, MP_SW_D2_Reader::META_ACTIVE, '1' );
}

$lead_b = $lead_a + 1;
pl_posprzataj( $lead_b );
pl_zglos_leada( $lead_b, 'Drugi Klient Sp. z o.o.' );

$proces_b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE lead_id = %d", $lead_b ), ARRAY_A ); // phpcs:ignore WordPress.DB

pl_ok(
	is_array( $proces_b ) && $handlowiec === (int) $proces_b['assigned_user_id'],
	'B1: przypisanie DZIAŁA jak dotąd — naprawa nie wyłączyła doboru handlowca',
	is_array( $proces_b ) ? 'assigned=' . $proces_b['assigned_user_id'] . ', oczekiwano ' . $handlowiec : 'brak wiersza'
);
pl_ok(
	is_array( $proces_b ) && '' !== trim( (string) $proces_b['assign_reason'] ),
	'B2: i nadal zapisuje uzasadnienie wyboru',
	is_array( $proces_b ) ? (string) $proces_b['assign_reason'] : 'brak wiersza'
);

pl_posprzataj( $lead_b );

$GLOBALS['mp_pl']['lines'][] = '';
$GLOBALS['mp_pl']['lines'][] = '=== C. KONTR-ASERCJA: właściciel bez podstawy się nie pojawia ===';

// Handlowiec wyłączony z rotacji. Proces nadal ma powstać, ale przypisanie go do
// kogoś nieaktywnego byłoby gorsze niż brak przypisania — nikt by na nie nie czekał.
if ( $handlowiec > 0 ) {
	update_user_meta( $handlowiec, MP_SW_D2_Reader::META_ACTIVE, '0' );
}

$lead_c = $lead_a + 2;
pl_posprzataj( $lead_c );
pl_zglos_leada( $lead_c, 'Trzeci Klient Sp. z o.o.' );

$proces_c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE lead_id = %d", $lead_c ), ARRAY_A ); // phpcs:ignore WordPress.DB

pl_ok(
	is_array( $proces_c ),
	'C1: proces powstaje także wtedy, gdy jedyny handlowiec jest wyłączony z rotacji',
	'brak wiersza dla lead_id=' . $lead_c
);
pl_ok(
	is_array( $proces_c ) && 0 === (int) $proces_c['assigned_user_id'],
	'C2: i NIE dostaje właściciela, który nie przyjmuje procesów',
	is_array( $proces_c ) ? (string) $proces_c['assigned_user_id'] : 'brak wiersza'
);

pl_posprzataj( $lead_c );

/* --------------------------------------------------------------- Sprzątanie */

if ( $handlowiec > 0 ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $handlowiec );
}

foreach ( $pl_odebrane as $pl_id ) {
	$pl_konto = new WP_User( $pl_id );
	$pl_konto->add_role( MP_SW_Roles::ROLE_SALESMAN );
}

pl_ok(
	count( $pl_odebrane ) === (int) ( new WP_User_Query(
		array(
			'role'   => MP_SW_Roles::ROLE_SALESMAN,
			'fields' => array( 'ID' ),
			'number' => -1,
		)
	) )->get_total(),
	'Z: role handlowców oddane co do jednego — test nie zostawia po sobie zmienionego świata',
	'odebrano ' . count( $pl_odebrane )
);

echo implode( "\n", $GLOBALS['mp_pl']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_pl']['pass'], $GLOBALS['mp_pl']['fail'] );
echo ( 0 === $GLOBALS['mp_pl']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
