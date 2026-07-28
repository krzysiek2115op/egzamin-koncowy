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

	/** Hak czyszczenia rejestru zdarzeń. */
	const HOOK_RETENTION = 'mp_sw_retention';

	/** Odstęp czyszczenia. */
	const SCHEDULE_RETENTION = 'daily';

	/** Po ilu dniach kasujemy wpis z rejestru zdarzeń. */
	const RETENTION_DAYS = 90;

	/** Ile wierszy kasujemy w jednym przebiegu. */
	const RETENTION_LIMIT = 1000;

	/**
	 * Wpina haki harmonogramu.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK_SWEEP, array( __CLASS__, 'sweep_tasks' ) );
		add_action( self::HOOK_RETENTION, array( __CLASS__, 'purge_events' ) );
		add_action( MP_SW_D9_Emitter::CRON_QUEUE, array( __CLASS__, 'run_queue' ) );

		/*
		 * Samonaprawa harmonogramu. Do tej pory `schedule()` wolala WYLACZNIE
		 * aktywacja wtyczki, wiec raz skasowane zadanie NIE WRACALO nigdy — a
		 * skasowac je potrafi wtyczka do zarzadzania cronem („wyczysc wszystkie"),
		 * `wp cron event delete`, albo odtworzenie bazy z kopii sprzed aktywacji.
		 * Objawu nie widac: nie ma zadnego bledu, po prostu przestaja powstawac
		 * zadania follow-up d+3/d+7 i nie dziala retencja. Cisza zamiast awarii
		 * to najgorszy mozliwy wariant dla automatyzacji, ktora ma dzialac sama.
		 *
		 * Ta sama filozofia co `MP_Sales_Workflow_DB::maybe_upgrade()` na
		 * `plugins_loaded`: nie zdajemy sie na hak aktywacji, bo on nie odpala
		 * sie przy zwyklej podmianie plikow wtyczki. Koszt to dwa
		 * `wp_next_scheduled()` — czytaja opcje `cron`, ktora WordPress i tak
		 * trzyma wczytana (autoload), wiec bez dodatkowego zapytania do bazy.
		 */
		self::schedule();
	}

	/**
	 * Czyści rejestr zdarzeń po okresie retencji.
	 *
	 * Rejestr `mp_sw_events` służy WYŁĄCZNIE idempotencji: trzyma klucze, żeby
	 * powtórzone zdarzenie odbiło się o UNIQUE. Po trzech miesiącach nikt tego
	 * samego zdarzenia już nie powtórzy, a tabela rośnie z każdym kliknięciem w
	 * panelu. Dziennik z kryterium 5.5 (`mp_sw_activity`) NIE jest czyszczony —
	 * to on odtwarza historię i jest przedmiotem odbioru.
	 *
	 * @return int Liczba usuniętych wierszy.
	 */
	public static function purge_events() {
		global $wpdb;

		$table  = MP_Sales_Workflow_DB::events_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff,
				self::RETENTION_LIMIT
			)
		);

		return max( 0, (int) $deleted );
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

		if ( ! wp_next_scheduled( self::HOOK_RETENTION ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::SCHEDULE_RETENTION, self::HOOK_RETENTION );
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
		wp_clear_scheduled_hook( self::HOOK_RETENTION );
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

		$summary = array(
			'processed' => 0,
			'fired'     => 0,
			'skipped'   => 0,
			'claimed'   => 0,
		);

		/*
		 * Przegląd wolno uruchomić WYŁĄCZNIE harmonogramowi. Bez tej kontroli
		 * `do_action( 'mp_sw_sweep_tasks' )` z dowolnego miejsca w procesie —
		 * także z żądania HTTP obsłużonego przez inną wtyczkę — wypychałby
		 * przypomnienia poza terminem, z pominięciem całej macierzy pochodzenia.
		 */
		if ( ! MP_SW_Origin::is_cron_context() ) {
			MP_SW_Log::security(
				MP_SW_Errors::E_CRON_CONTEXT,
				array(
					'code'    => MP_SW_Errors::E_CRON_CONTEXT,
					'type'    => MP_SW_Pipeline_Factory::EVENT_TASK_DUE,
					'source'  => MP_SW_D1::SOURCE_CRON,
					'reason'  => 'sweep_outside_cron',
					'user_id' => get_current_user_id(),
					'ip_hash' => MP_SW_Log::ip_hash(),
				)
			);

			return $summary;
		}

		$tasks = MP_Sales_Workflow_DB::tasks_table();
		$flow  = MP_Sales_Workflow_DB::flow_table();
		$now   = current_time( 'mysql', true );
		$claim = self::claim( $now );

		$summary['claimed'] = $claim['count'];

		if ( $claim['count'] < 1 ) {
			return $summary;
		}

		/*
		 * `lead_id` dociągamy złączeniem, bo koperta `task.due` musi go nieść —
		 * inaczej Dział 2 nie znalazłby procesu jednym odczytem.
		 */
		$sql = "SELECT t.id, t.event_id, f.lead_id, f.offer_id
			FROM {$tasks} t
			INNER JOIN {$flow} f ON f.id = t.flow_id
			WHERE t.status = %s AND t.claim_token = %s
			ORDER BY t.due_at ASC, t.id ASC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				MP_SW_D6_Scheduler::STATUS_PENDING,
				$claim['token'],
				self::SWEEP_LIMIT
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$entity = array(
				'lead_id' => (int) $row['lead_id'],
				'task_id' => (int) $row['id'],
			);

			if ( ! empty( $row['offer_id'] ) ) {
				$entity['offer_id'] = (int) $row['offer_id'];
			}

			$dispatched = MP_SW_Events::from_cron(
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
				)
			);

			++$summary['processed'];

			$plan = (array) $dispatched['context']->get( 'tasks_plan', MP_SW_D6_Scheduler::empty_plan() );

			$summary['fired']   += count( (array) $plan['fire'] );
			$summary['skipped'] += count( (array) $plan['skip'] );
		}

		return $summary;
	}

	/**
	 * Klamruje zadania na wyłączność tego przebiegu.
	 *
	 * Jedna instrukcja UPDATE zamiast „wybierz, potem oznacz". Różnica jest
	 * praktyczna: WP-Cron nie ma pojedynczego procesu i przy ruchliwej witrynie
	 * dwa przebiegi potrafią nałożyć się w tej samej sekundzie. Wybór, po którym
	 * następuje oznaczenie, dałby obu przebiegom TĘ SAMĄ listę zadań; UPDATE
	 * bierze zamek na wiersze, więc drugi przebieg zastanie je już zajęte i
	 * dostanie pustą paczkę.
	 *
	 * Znacznik ma termin ważności: przebieg przerwany limitem czasu PHP nie może
	 * zamrozić zadania na zawsze, więc po `CLAIM_TTL_MINUTES` wraca ono do puli.
	 *
	 * @param string $now Czas UTC w formacie MySQL.
	 * @return array{count:int,token:string}
	 */
	private static function claim( $now ) {
		global $wpdb;

		$tasks = MP_Sales_Workflow_DB::tasks_table();
		$token = wp_generate_uuid4();
		$stale = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) - ( MP_Sales_Workflow_DB::CLAIM_TTL_MINUTES * MINUTE_IN_SECONDS ) );

		$sql = "UPDATE {$tasks}
			SET claim_token = %s, claimed_at = %s
			WHERE status = %s AND due_at <= %s AND ( claimed_at IS NULL OR claimed_at < %s )
			ORDER BY due_at ASC, id ASC
			LIMIT %d";

		$count = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$token,
				$now,
				MP_SW_D6_Scheduler::STATUS_PENDING,
				$now,
				$stale,
				self::SWEEP_LIMIT
			)
		);

		return array(
			'count' => max( 0, (int) $count ),
			'token' => $token,
		);
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
		return MP_SW_Events::derive_event_id( 'task.due', array( $task_id, $event_id ) );
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
