<?php
/**
 * Grupa A po audycie 31.07.2026 — „sukces, ktory nie jest sukcesem".
 *
 * Uruchamianie: wp eval-file tests/naprawy/grupa-a.php
 *
 * Wspolna zasada obu przypadkow: BRAK POTWIERDZENIA TO NIE JEST POTWIERDZENIE.
 * Kod pytal „czy mam dowod porazki?" zamiast „czy mam dowod sukcesu?", wiec
 * trzeci stan — ani sukces, ani znana porazka — byl raportowany jako sukces.
 *
 * A1 (Dzial 8): zadanie follow-up domykane jako WYKONANE takze wtedy, gdy
 *     powiadomienia nie bylo ani w kolejce, ani na liscie pominietych.
 * A2 (Dzial 9): schedule_queue() zwracalo `false` i przy „termin juz stoi",
 *     i przy nieudanym wp_schedule_single_event(). Drugi przypadek oznacza
 *     kolejke, ktora NIGDY nie ruszy — a wywolujacy widzial to samo `false`,
 *     co przy sytuacji calkowicie normalnej.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_ga'] = array(
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
function ga_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_ga']['pass'];
		$GLOBALS['mp_ga']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_ga']['fail'];
	$GLOBALS['mp_ga']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function ga_dump() {
	if ( empty( $GLOBALS['mp_ga']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_ga'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-p3-grupa-a.txt' : '/tmp/mp-p3-grupa-a.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_ga']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'ga_dump' );

/**
 * Kontekst dla agenta A8.1 z jednym zadaniem follow-up do domkniecia.
 *
 * Agent planu nie dotyka bazy — buduje wylacznie liste operacji — wiec da sie
 * go uruchomic na samym kontekscie i sprawdzic sam wybor statusu.
 *
 * @param array $notifications Kolejka powiadomien tego przebiegu.
 * @param array $skipped       Powiadomienia pominiete przez Agenta 7.2.
 * @return array Plan zapisu.
 */
function ga_plan_for( array $notifications, array $skipped ) {
	$context = new MP_SW_Context(
		array(
			MP_SW_D2_Reader::SNAPSHOT_KEY => array(
				'flow' => array(
					'exists'       => true,
					'lock_version' => 3,
					'row'          => array(
						'id'               => 777,
						'status'           => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
						'assigned_user_id' => 5,
						'client_name'      => 'Testowy Klient',
						'client_email'     => 'klient@example.com',
					),
				),
			),
			'tasks_plan'                  => array(
				'create'     => array(),
				'close'      => array(),
				'fire'       => array(
					array(
						'task_id'      => 4242,
						'type'         => MP_SW_D6_Scheduler::TYPE_D3,
						'guard_status' => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
					),
				),
				'skip'       => array(),
				'duplicates' => array(),
			),
			'notifications'               => $notifications,
			'skipped_notifications'       => $skipped,
			'event'                       => array(
				'type'   => MP_SW_Pipeline_Factory::EVENT_TASK_DUE,
				'entity' => array( 'lead_id' => 11 ),
				'actor'  => array( 'user_id' => 0 ),
			),
			'event_id'                    => 'grupa-a-' . wp_generate_uuid4(),
			'type'                        => MP_SW_Pipeline_Factory::EVENT_TASK_DUE,
		)
	);

	$agent  = new MP_SW_D8_Agent_Plan( '8.1', 'plan', 'test Grupy A' );
	$result = $agent->run( $context );
	$data   = $result->get_data();

	return isset( $data['write_plan'] ) ? (array) $data['write_plan'] : array();
}

/**
 * Status, z jakim plan domyka zadanie o podanym identyfikatorze.
 *
 * @param array $plan    Plan zapisu.
 * @param int   $task_id Identyfikator zadania.
 * @return string
 */
function ga_close_status( array $plan, $task_id ) {
	foreach ( (array) $plan['tasks_close'] as $task ) {
		if ( (int) $task['task_id'] === (int) $task_id ) {
			return (string) $task['status'];
		}
	}

	return '';
}

$GLOBALS['mp_ga']['lines'][] = '=== A1 — domkniecie zadania follow-up ===';

// Przypadek 1: powiadomienie trafilo do kolejki. Zadanie WYKONANE.
$plan = ga_plan_for(
	array(
		array(
			'template'  => MP_SW_Templates::TPL_FOLLOWUP_DUE,
			'recipient' => 'handlowiec@example.com',
		),
	),
	array()
);
ga_ok(
	MP_SW_D6_Scheduler::STATUS_DONE === ga_close_status( $plan, 4242 ),
	'powiadomienie w kolejce -> zadanie domkniete jako WYKONANE',
	ga_close_status( $plan, 4242 )
);

// Przypadek 2: Agent 7.2 pominal powiadomienie (zly adres handlowca).
$plan = ga_plan_for(
	array(),
	array(
		array(
			'template' => MP_SW_Templates::TPL_FOLLOWUP_DUE,
			'cause'    => 'brak_adresu',
		),
	)
);
ga_ok(
	MP_SW_D6_Scheduler::STATUS_UNDELIVERED === ga_close_status( $plan, 4242 ),
	'powiadomienie pominiete -> zadanie NIEDOSTARCZONE',
	ga_close_status( $plan, 4242 )
);

/*
 * Przypadek 3 — ten, o ktory chodzi. Powiadomienia nie ma w ZADNEJ z list.
 * Do wersji 1.3.0 warunek brzmial `(!$poszlo && $odpadlo) ? undelivered : done`,
 * wiec brak powiadomienia w obu listach dawal „wykonane" — handlowiec nic nie
 * dostal, a pulpit pokazywal zadanie jako zrobione i nikt do niego nie wracal.
 */
$plan = ga_plan_for( array(), array() );
ga_ok(
	MP_SW_D6_Scheduler::STATUS_UNDELIVERED === ga_close_status( $plan, 4242 ),
	'BRAK powiadomienia w obu listach -> zadanie NIEDOSTARCZONE, nie wykonane',
	ga_close_status( $plan, 4242 )
);

// Powiadomienie innego rodzaju nie jest dowodem na wyslany follow-up.
$plan = ga_plan_for(
	array(
		array(
			'template'  => MP_SW_Templates::TPL_OFFER_SENT,
			'recipient' => 'klient@example.com',
		),
	),
	array()
);
ga_ok(
	MP_SW_D6_Scheduler::STATUS_UNDELIVERED === ga_close_status( $plan, 4242 ),
	'powiadomienie INNEGO typu nie zalicza follow-upu jako wykonanego',
	ga_close_status( $plan, 4242 )
);

$GLOBALS['mp_ga']['lines'][] = '';
$GLOBALS['mp_ga']['lines'][] = '=== A2 — trzy stany zlecenia przebiegu kolejki ===';

// Stale podane wprost (nie przez klase): przed naprawa jeszcze nie istnieja,
// a test ma FAIL-owac czytelnie, nie wywracac sie bledem krytycznym.
$stan_nowy   = 'zaplanowano';
$stan_stary  = 'juz_bylo';
$stan_bledny = 'blad';

wp_clear_scheduled_hook( MP_SW_D9_Emitter::CRON_QUEUE );

$wynik = MP_SW_D9_Emitter::schedule_queue();
ga_ok(
	$stan_nowy === $wynik,
	'pusty harmonogram -> "zaplanowano"',
	var_export( $wynik, true ) // phpcs:ignore
);

$wynik = MP_SW_D9_Emitter::schedule_queue();
ga_ok(
	$stan_stary === $wynik,
	'termin juz stoi -> "juz_bylo" (to jest sytuacja normalna)',
	var_export( $wynik, true ) // phpcs:ignore
);

/*
 * Nieudane zaplanowanie. WordPress pozwala odmowic przez `pre_schedule_event`
 * — tak robi kazda wtyczka przejmujaca cron (Action Scheduler, wylaczony
 * WP-Cron, blokada zapisu opcji). Do wersji 1.3.0 wracalo stad to samo `false`
 * co wyzej, wiec „kolejka nigdy nie ruszy" bylo nieodroznialne od „wszystko
 * w porzadku".
 */
wp_clear_scheduled_hook( MP_SW_D9_Emitter::CRON_QUEUE );
add_filter( 'pre_schedule_event', '__return_false', 99 );
$wynik = MP_SW_D9_Emitter::schedule_queue();
remove_filter( 'pre_schedule_event', '__return_false', 99 );

ga_ok(
	$stan_bledny === $wynik,
	'nieudane zaplanowanie -> "blad", NIE ten sam wynik co "juz_bylo"',
	var_export( $wynik, true ) // phpcs:ignore
);
ga_ok(
	false === wp_next_scheduled( MP_SW_D9_Emitter::CRON_QUEUE ),
	'po nieudanym zaplanowaniu w harmonogramie faktycznie nic nie stoi'
);

$GLOBALS['mp_ga']['lines'][] = '';
$GLOBALS['mp_ga']['lines'][] = '=== A2 — reakcja Dzialu 9 i siec bezpieczenstwa ===';

global $wpdb;

$flow_t = MP_Sales_Workflow_DB::flow_table();
$akt_t  = MP_Sales_Workflow_DB::activity_table();
$now    = current_time( 'mysql', true );

$wpdb->insert( // phpcs:ignore
	$flow_t,
	array(
		'lead_id'    => 990001,
		'status'     => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
		'created_at' => $now,
		'updated_at' => $now,
	)
);
$flow_id = (int) $wpdb->insert_id;

// Dokladnie 36 znakow — kolumna `event_id` w dzienniku to char(36), a MariaDB
// w trybie scislym odrzuca dluzsza wartosc zamiast ja przyciac.
$event_id = wp_generate_uuid4();
$context  = new MP_SW_Context(
	array(
		'event'         => array(
			'type'   => MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
			'entity' => array( 'lead_id' => 990001 ),
			'actor'  => array( 'user_id' => 0 ),
		),
		'event_id'      => $event_id,
		'committed'     => true,
		'flow_id'       => $flow_id,
		'transition'    => array( 'to' => MP_Sales_Workflow_DB::STATUS_OFFER_SENT ),
		'notifications' => array(
			array(
				'template'  => MP_SW_Templates::TPL_FOLLOWUP_DUE,
				'recipient' => 'handlowiec@example.com',
			),
		),
	)
);
$context->count_db_write();

wp_clear_scheduled_hook( MP_SW_D9_Emitter::CRON_QUEUE );
MP_SW_D9_Emitter::reset();

$przed = (int) $wpdb->get_var( // phpcs:ignore
	$wpdb->prepare( "SELECT COUNT(*) FROM {$akt_t} WHERE flow_id = %d", $flow_id ) // phpcs:ignore
);

add_filter( 'pre_schedule_event', '__return_false', 99 );
$agent  = new MP_SW_D9_Agent_Events( '9.1', 'zdarzenia', 'test Grupy A' );
$result = $agent->run( $context );
remove_filter( 'pre_schedule_event', '__return_false', 99 );

$dane = $result->get_data();

ga_ok(
	isset( $dane['queue_scheduled'] ) && $stan_bledny === $dane['queue_scheduled'],
	'Dzial 9 przekazuje dalej stan "blad", a nie samo false',
	isset( $dane['queue_scheduled'] ) ? var_export( $dane['queue_scheduled'], true ) : 'brak klucza' // phpcs:ignore
);

$po = (int) $wpdb->get_var( // phpcs:ignore
	$wpdb->prepare( "SELECT COUNT(*) FROM {$akt_t} WHERE flow_id = %d", $flow_id ) // phpcs:ignore
);

ga_ok(
	$po === $przed + 1,
	'nieudane zlecenie kolejki zostaje ODNOTOWANE w dzienniku (cisza = najgorszy wariant)',
	'przed=' . $przed . ' po=' . $po
);

$wpis = (array) $wpdb->get_row( // phpcs:ignore
	$wpdb->prepare( "SELECT * FROM {$akt_t} WHERE flow_id = %d ORDER BY id DESC LIMIT 1", $flow_id ), // phpcs:ignore
	ARRAY_A
);

ga_ok(
	isset( $wpis['action'] ) && 'queue.schedule_failed' === (string) $wpis['action'],
	'wpis dziennika nazywa rzecz po imieniu: queue.schedule_failed',
	isset( $wpis['action'] ) ? (string) $wpis['action'] : 'brak wpisu'
);

$blob = strtolower( wp_json_encode( $wpis ) );
ga_ok(
	false === strpos( $blob, 'handlowiec@example.com' ),
	'wpis dziennika bez adresu e-mail (RODO)'
);

/*
 * Siec bezpieczenstwa: przegladajac zadania co 5 minut, cron widzi zalegla
 * kolejke i zamawia jej przebieg. Bez tego jedno nieudane zaplanowanie
 * zostawialoby wiadomosci w bazie do nastepnego zdarzenia w systemie —
 * czyli byc moze na zawsze.
 */
$noti_t = MP_Sales_Workflow_DB::notifications_table();
$wpdb->insert( // phpcs:ignore
	$noti_t,
	array(
		'flow_id'          => $flow_id,
		'event_id'         => $event_id,
		'template'         => MP_SW_Templates::TPL_FOLLOWUP_DUE,
		'template_version' => '1',
		'lang'             => 'pl',
		'recipient'        => 'handlowiec@example.com',
		'subject'          => 'Test Grupy A',
		'body'             => 'Test Grupy A',
		'status'           => MP_SW_D7_Notifier::STATUS_QUEUED,
		'attempts'         => 0,
		'created_at'       => $now,
		'updated_at'       => $now,
	)
);
$noti_id = (int) $wpdb->insert_id;

wp_clear_scheduled_hook( MP_SW_D9_Emitter::CRON_QUEUE );

if ( ! defined( 'DOING_CRON' ) ) {
	define( 'DOING_CRON', true );
}

MP_SW_Cron::sweep_tasks();

ga_ok(
	false !== wp_next_scheduled( MP_SW_D9_Emitter::CRON_QUEUE ),
	'przeglad crona widzi zalegla kolejke i zamawia jej przebieg',
	'brak terminu w harmonogramie'
);

// Sprzatanie.
$wpdb->delete( $noti_t, array( 'id' => $noti_id ) ); // phpcs:ignore
$wpdb->delete( $akt_t, array( 'flow_id' => $flow_id ) ); // phpcs:ignore
$wpdb->delete( $flow_t, array( 'id' => $flow_id ) ); // phpcs:ignore
wp_clear_scheduled_hook( MP_SW_D9_Emitter::CRON_QUEUE );
