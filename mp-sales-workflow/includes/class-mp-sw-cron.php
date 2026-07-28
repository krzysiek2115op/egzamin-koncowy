<?php
/**
 * Harmonogram: przeglad zadan follow-up i przebieg kolejki powiadomien.
 *
 * Dokumentacja dzialu 6 ostrzega wprost, ze WP-Cron "does not run constantly as
 * the system cron does; it is only triggered on page load", wiec termin d+3 na
 * malo odwiedzanej witrynie potrafi sie opoznic. Dlatego przeglad NIE zaklada,
 * ze uruchamia sie co do minuty: bierze WSZYSTKIE zadania, ktorych termin juz
 * minal, a nie te "przypadajace teraz".
 *
 * Zrodlo (Golden Rule #2): docs/dzial-06/zadania-follow-up.md
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zadania cykliczne wtyczki.
 */
class MP_SW_Cron {

	/** Hak przeglądu zadań follow-up. */
	const HOOK_SWEEP = 'mp_sw_sweep_tasks';

	/** Odstęp przeglądu. */
	const SCHEDULE_SWEEP = 'hourly';

	/** Ile zadań obsługujemy w jednym przeglądzie. */
	const SWEEP_LIMIT = 50;

	/**
	 * Wpina haki harmonogramu.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK_SWEEP, array( __CLASS__, 'sweep_tasks' ) );
		add_action( MP_SW_D9_Emitter::CRON_QUEUE, array( __CLASS__, 'run_queue' ) );
	}

	/**
	 * Zakłada zadania cykliczne (aktywacja wtyczki).
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_SWEEP ) ) {
			wp_schedule_event( time() + 300, self::SCHEDULE_SWEEP, self::HOOK_SWEEP );
		}
	}

	/**
	 * Usuwa zadania cykliczne (dezaktywacja wtyczki).
	 *
	 * Bez tego wyłączona wtyczka zostawia w `wp_options` terminy, które
	 * WordPress próbuje wywoływać przy każdym załadowaniu strony.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_SWEEP );
		wp_clear_scheduled_hook( MP_SW_D9_Emitter::CRON_QUEUE );
	}

	/**
	 * Przegląd zadań, których termin minął.
	 *
	 * Każde zadanie idzie przez PEŁNY pipeline jako zdarzenie `task.due` —
	 * to Dział 6 rozstrzyga wartownikiem, czy zadanie nadal ma sens, a Dział 8
	 * domyka je jedną transakcją. Cron niczego nie zapisuje sam.
	 *
	 * @return array{processed:int,fired:int,skipped:int}
	 */
	public static function sweep_tasks() {
		global $wpdb;

		$tasks = MP_Sales_Workflow_DB::tasks_table();
		$flow  = MP_Sales_Workflow_DB::flow_table();
		$now   = current_time( 'mysql', true );

		/*
		 * `lead_id` dociągamy złączeniem, bo koperta `task.due` musi go nieść —
		 * inaczej Dział 2 nie znalazłby procesu jednym odczytem.
		 */
		$sql = "SELECT t.id, t.event_id, f.lead_id, f.offer_id
			FROM {$tasks} t
			INNER JOIN {$flow} f ON f.id = t.flow_id
			WHERE t.status = %s AND t.due_at <= %s
			ORDER BY t.due_at ASC, t.id ASC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				MP_SW_D6_Scheduler::STATUS_PENDING,
				$now,
				self::SWEEP_LIMIT
			),
			ARRAY_A
		);

		$summary = array(
			'processed' => 0,
			'fired'     => 0,
			'skipped'   => 0,
		);

		foreach ( (array) $rows as $row ) {
			$entity = array(
				'lead_id' => (int) $row['lead_id'],
				'task_id' => (int) $row['id'],
			);

			if ( ! empty( $row['offer_id'] ) ) {
				$entity['offer_id'] = (int) $row['offer_id'];
			}

			$dispatched = MP_SW_Events::dispatch(
				MP_SW_Pipeline_Factory::EVENT_TASK_DUE,
				array(
					'entity'   => $entity,
					'actor'    => array( 'user_id' => 0 ),

					/*
					 * Klucz idempotencji zadania, nadany przy PLANOWANIU, plus
					 * identyfikator zadania. Dzięki temu powtórzony przegląd tego
					 * samego zadania odbija się o UNIQUE w rejestrze zdarzeń,
					 * zamiast wysyłać przypomnienie drugi raz.
					 */
					'event_id' => self::task_event_id( (int) $row['id'], (string) $row['event_id'] ),
				),
				MP_SW_D1::SOURCE_CRON
			);

			++$summary['processed'];

			$plan = (array) $dispatched['context']->get( 'tasks_plan', MP_SW_D6_Scheduler::empty_plan() );

			$summary['fired']   += count( (array) $plan['fire'] );
			$summary['skipped'] += count( (array) $plan['skip'] );
		}

		return $summary;
	}

	/**
	 * Klucz idempotencji przebiegu zadania.
	 *
	 * Powstaje deterministycznie z identyfikatora zadania i klucza zdarzenia,
	 * które je zaplanowało — dwa przeglądy tego samego zadania dają ten sam
	 * UUID, więc drugi zostaje odrzucony jako powtórka.
	 *
	 * @param int    $task_id  Identyfikator zadania.
	 * @param string $event_id Klucz zdarzenia planującego.
	 * @return string
	 */
	public static function task_event_id( $task_id, $event_id ) {
		$hash = md5( 'mp_sw_task_due:' . $task_id . ':' . $event_id );

		return sprintf(
			'%s-%s-4%s-%s%s-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			substr( $hash, 13, 3 ),
			dechex( hexdec( substr( $hash, 16, 1 ) ) % 4 + 8 ),
			substr( $hash, 17, 3 ),
			substr( $hash, 20, 12 )
		);
	}

	/**
	 * Przebieg kolejki powiadomień.
	 *
	 * Gdy po paczce zostaje jeszcze coś w kolejce, zamawiamy kolejny przebieg —
	 * inaczej reszta czekałaby do następnego zdarzenia w systemie.
	 *
	 * @return array
	 */
	public static function run_queue() {
		$summary = MP_SW_Queue::run();

		if ( MP_SW_Queue::pending() > 0 && ! wp_next_scheduled( MP_SW_D9_Emitter::CRON_QUEUE ) ) {
			wp_schedule_single_event( time() + 60, MP_SW_D9_Emitter::CRON_QUEUE );
		}

		return $summary;
	}
}
