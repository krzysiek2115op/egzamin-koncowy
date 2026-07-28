<?php
/**
 * Dzial 6 pipeline LP.3 — ZADANIA FOLLOW-UP.
 *
 * Zakres wg diagramu: pkt 2: zadania follow-up · aut. 4.5
 *
 * Operacje dzialu:
 *  - d+3 i d+7 z wartownikiem statusu
 *  - Zmiana statusu anuluje oczekujące
 *  - Wykonanie: cron → zdarzenie task.due
 *
 * Zrodlo (Golden Rule #2): docs/dzial-06/zadania-follow-up.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: WP-Cron → systemowy harmonogram (Plugin Handbook)
 *
 * Dzial NICZEGO nie zapisuje ani nie planuje w WP-Cronie. To wazne rozroznienie:
 * wp_schedule_single_event() zapisuje do `wp_options`, a wiec do BAZY — wywolane
 * tutaj podbiloby licznik zapisow i zlamalo zasade "1 transakcja" (Dzial 8).
 * Gorzej: termin trafilby do crona nawet wtedy, gdy transakcja skonczy sie
 * wycofaniem, i zostalby po niej termin-widmo. Dzial 6 wylicza wiec PLAN zadan,
 * Dzial 8 zapisuje go w tabeli `mp_sw_tasks` ta sama transakcja, a rejestracja w
 * harmonogramie nastepuje po COMMIT (Dzial 9).
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reguly harmonogramu follow-up.
 */
class MP_SW_D6_Scheduler {

	/** Typ zadania: kontakt po trzech dniach od wysłania oferty. */
	const TYPE_D3 = 'followup_d3';

	/** Typ zadania: kontakt po siedmiu dniach od wysłania oferty. */
	const TYPE_D7 = 'followup_d7';

	/** Zadanie czeka na swój termin. */
	const STATUS_PENDING = 'pending';

	/** Zadanie wykonane — wartownik przepuścił je w terminie. */
	const STATUS_DONE = 'done';

	/** Zadanie nieaktualne: status procesu zmienił się przed terminem. */
	const STATUS_CANCELLED = 'cancelled';

	/** Hak crona, pod którym wypada zadanie (rejestrowany po COMMIT). */
	const CRON_HOOK = 'mp_sw_task_due';

	/** Powód pominięcia: wartownik nie przepuścił zadania. */
	const SKIP_GUARD = 'guard_mismatch';

	/** Powód pominięcia: zadanie nie jest już otwarte (powtórka crona). */
	const SKIP_NOT_OPEN = 'not_open';

	/**
	 * Typy zadań wraz z przesunięciem w dniach.
	 *
	 * @return array<string,int>
	 */
	public static function plan_types() {
		return array(
			self::TYPE_D3 => 3,
			self::TYPE_D7 => 7,
		);
	}

	/**
	 * Termin zadania liczony od jednego źródła czasu w GMT.
	 *
	 * @param int    $days Przesunięcie w dniach.
	 * @param string $from Punkt odniesienia (datetime GMT).
	 * @return string
	 */
	public static function due_at( $days, $from ) {
		return gmdate( 'Y-m-d H:i:s', strtotime( $from . ' +' . (int) $days . ' days' ) );
	}

	/**
	 * Klucz otwartego zadania — nośnik zasady „jedno otwarte zadanie typu".
	 *
	 * Kolumna `open_key` ma UNIQUE, więc duplikat odbija się o bazę nawet przy
	 * dwóch równoległych wywołaniach crona. Gdy proces jeszcze nie ma wiersza
	 * (identyfikator powstanie dopiero przy zapisie), klucz uzupełnia Dział 8.
	 *
	 * @param int    $flow_id Identyfikator procesu (0 = jeszcze nieznany).
	 * @param string $type    Typ zadania.
	 * @return string|null
	 */
	public static function open_key( $flow_id, $type ) {
		return $flow_id > 0 ? $flow_id . ':' . $type : null;
	}

	/**
	 * Pusty plan — jedyny kształt, jaki dział przekazuje dalej.
	 *
	 * @return array
	 */
	public static function empty_plan() {
		return array(
			'create'     => array(),
			'close'      => array(),
			'fire'       => array(),
			'skip'       => array(),
			'duplicates' => array(),
		);
	}
}

/**
 * A6.1 „harmonogram" — plan d+3 / d+7 i rozstrzygnięcie wartownika.
 */
class MP_SW_D6_Agent_Schedule extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot   = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$flow       = isset( $snapshot['flow'] ) ? (array) $snapshot['flow'] : array();
		$effects    = (array) $context->get( 'effects', array() );
		$transition = (array) $context->get( 'transition', array() );
		$event      = (array) $context->get( 'event', array() );

		$now      = current_time( 'mysql', true );
		$schedule = array();

		/*
		 * Wartownik to status, W KTÓRY proces właśnie wchodzi — a nie ten, w
		 * którym był. Zadanie ma się wykonać tylko wtedy, gdy do dnia terminu
		 * nic się nie zmieniło, więc porównywanym punktem odniesienia jest stan
		 * docelowy przejścia.
		 */
		$guard = isset( $transition['to'] ) ? (string) $transition['to'] : '';

		if ( in_array( MP_SW_D5_Machine::EFFECT_SCHEDULE_FOLLOWUPS, $effects, true ) ) {
			foreach ( MP_SW_D6_Scheduler::plan_types() as $type => $days ) {
				$schedule[] = array(
					'type'         => $type,
					'due_at'       => MP_SW_D6_Scheduler::due_at( $days, $now ),
					'guard_status' => $guard,
					'offset_days'  => (int) $days,
				);
			}
		}

		$fire = array();
		$skip = array();

		if ( MP_SW_Pipeline_Factory::EVENT_TASK_DUE === ( isset( $event['type'] ) ? $event['type'] : '' ) ) {
			$entity  = isset( $event['entity'] ) ? (array) $event['entity'] : array();
			$task_id = isset( $entity['task_id'] ) ? (int) $entity['task_id'] : 0;
			$current = isset( $flow['row']['status'] ) ? (string) $flow['row']['status'] : '';
			$task    = self::find_open_task( $flow, $task_id );

			if ( null === $task ) {
				/*
				 * Zadania nie ma już wśród otwartych. To nie błąd: cron potrafi
				 * odpalić ten sam termin drugi raz, a zadanie zdążyło się już
				 * zamknąć. Odpowiedź ma być spokojna, nie 500.
				 */
				$skip[] = array(
					'task_id'        => $task_id,
					'type'           => '',
					'reason'         => MP_SW_D6_Scheduler::SKIP_NOT_OPEN,
					'guard_status'   => '',
					'current_status' => $current,
				);
			} elseif ( $task['guard_status'] === $current ) {
				$fire[] = array(
					'task_id'      => (int) $task['id'],
					'type'         => (string) $task['type'],
					'guard_status' => (string) $task['guard_status'],
				);
			} else {
				// Kryterium 4.5 wprost: status się zmienił, więc zadanie jest
				// nieaktualne i wygasa zamiast się wykonać.
				$skip[] = array(
					'task_id'        => (int) $task['id'],
					'type'           => (string) $task['type'],
					'reason'         => MP_SW_D6_Scheduler::SKIP_GUARD,
					'guard_status'   => (string) $task['guard_status'],
					'current_status' => $current,
				);
			}
		}

		return MP_SW_Result::ok(
			array(
				'followup' => array(
					'schedule'   => $schedule,
					'fire'       => $fire,
					'skip'       => $skip,
					'guard_base' => $guard,
					'planned_at' => $now,
				),
			)
		);
	}

	/**
	 * Odnajduje zadanie wśród otwartych w snapshocie.
	 *
	 * @param array $flow    Sekcja procesu ze snapshotu.
	 * @param int   $task_id Identyfikator zadania.
	 * @return array|null
	 */
	private static function find_open_task( array $flow, $task_id ) {
		$open = isset( $flow['open_tasks'] ) ? (array) $flow['open_tasks'] : array();

		foreach ( $open as $task ) {
			if ( (int) $task['id'] === (int) $task_id ) {
				return $task;
			}
		}

		return null;
	}
}

/**
 * K6.1 „warunek-4.5" — zadanie aktywuje sie tylko przy niezmienionym statusie.
 */
class MP_SW_D6_Critic_Guard extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data     = $agent_result->get_data();
		$followup = isset( $data['followup'] ) ? (array) $data['followup'] : array();
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$flow     = isset( $snapshot['flow'] ) ? (array) $snapshot['flow'] : array();
		$current  = isset( $flow['row']['status'] ) ? (string) $flow['row']['status'] : '';

		foreach ( (array) $followup['schedule'] as $task ) {
			if ( '' === (string) $task['guard_status'] ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: typ zadania. */
						__( 'Zadanie %s zaplanowane bez wartownika statusu.', 'mp-sales-workflow' ),
						$task['type']
					),
					array( 'errors' => array( 'guard_status' ) ),
					'task_without_guard'
				);
			}

			if ( ! in_array( (string) $task['guard_status'], MP_Sales_Workflow_DB::statuses(), true ) ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: wartownik spoza słownika. */
						__( 'Wartownik %s spoza słownika statusów.', 'mp-sales-workflow' ),
						$task['guard_status']
					),
					array( 'errors' => array( 'guard_status' ) ),
					'unknown_guard_status'
				);
			}

			if ( strtotime( (string) $task['due_at'] ) <= strtotime( (string) $followup['planned_at'] ) ) {
				// Termin w przeszłości oznacza zadanie, które wypada natychmiast —
				// czyli follow-up wysłany w tej samej chwili co oferta.
				return MP_SW_Result::fail(
					sprintf(
						/* translators: 1: typ zadania, 2: wyliczony termin. */
						__( 'Zadanie %1$s ma termin nie w przyszłości (%2$s).', 'mp-sales-workflow' ),
						$task['type'],
						$task['due_at']
					),
					array( 'errors' => array( 'due_at' ) ),
					'due_at_not_future'
				);
			}
		}

		/*
		 * Sprawdzenie niezależne od agenta: krytyk sam porównuje wartownika ze
		 * stanem procesu. Gdyby agent kiedykolwiek przepuścił zadanie „na
		 * wyrost", handlowiec dostałby przypomnienie o ofercie, która dawno
		 * została rozstrzygnięta.
		 */
		foreach ( (array) $followup['fire'] as $task ) {
			if ( (string) $task['guard_status'] !== $current ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: 1: wartownik zadania, 2: bieżący status procesu. */
						__( 'Zadanie przepuszczone mimo zmiany statusu (wartownik %1$s, stan %2$s).', 'mp-sales-workflow' ),
						$task['guard_status'],
						$current
					),
					array( 'errors' => array( 'guard_status' ) ),
					'guard_violated'
				);
			}
		}

		foreach ( (array) $followup['skip'] as $task ) {
			if ( '' === (string) $task['reason'] ) {
				return MP_SW_Result::fail(
					__( 'Zadanie pominięte bez podanego powodu.', 'mp-sales-workflow' ),
					array( 'errors' => array( 'skip.reason' ) ),
					'skip_without_reason'
				);
			}
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A6.2 „deduplikacja" — jedno otwarte zadanie typu; zmiana statusu anuluje.
 */
class MP_SW_D6_Agent_Dedup extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$flow     = isset( $snapshot['flow'] ) ? (array) $snapshot['flow'] : array();
		$followup = (array) $context->get( 'followup', array() );
		$effects  = (array) $context->get( 'effects', array() );

		$flow_id  = isset( $flow['row']['id'] ) ? (int) $flow['row']['id'] : 0;
		$open     = isset( $flow['open_tasks'] ) ? (array) $flow['open_tasks'] : array();
		$event_id = (string) $context->get( 'event_id', '' );
		$now      = isset( $followup['planned_at'] ) ? (string) $followup['planned_at'] : current_time( 'mysql', true );

		$open_types = array();

		foreach ( $open as $task ) {
			$open_types[] = (string) $task['type'];
		}

		$plan = MP_SW_D6_Scheduler::empty_plan();

		foreach ( (array) $followup['schedule'] as $task ) {
			$type = (string) $task['type'];

			/*
			 * Zadanie tego typu już czeka — drugiej pary nie tworzymy. To jest
			 * dokładnie ten przypadek, o którym mówi dokumentacja działu: powtórka
			 * zdarzenia nie ma powielać terminów. Baza broni tego dodatkowo kluczem
			 * UNIQUE na `open_key`, ale odbicie się o wyjątek byłoby drogą przez
			 * przerwaną transakcję — taniej jest nie wysyłać duplikatu w ogóle.
			 */
			if ( in_array( $type, $open_types, true ) ) {
				$plan['duplicates'][] = $type;
				continue;
			}

			$plan['create'][] = array(
				'type'         => $type,
				'due_at'       => (string) $task['due_at'],
				'guard_status' => (string) $task['guard_status'],
				'status'       => MP_SW_D6_Scheduler::STATUS_PENDING,
				'open_key'     => MP_SW_D6_Scheduler::open_key( $flow_id, $type ),
				'event_id'     => $event_id,
				'created_at'   => $now,
				'updated_at'   => $now,
			);

			$open_types[] = $type;
		}

		// Zmiana statusu anuluje oczekujące — wprost z diagramu.
		if ( in_array( MP_SW_D5_Machine::EFFECT_CLOSE_TASKS, $effects, true ) ) {
			foreach ( $open as $task ) {
				$plan['close'][] = array(
					'task_id' => (int) $task['id'],
					'type'    => (string) $task['type'],
					'status'  => MP_SW_D6_Scheduler::STATUS_CANCELLED,
				);
			}
		}

		$plan['fire'] = (array) $followup['fire'];
		$plan['skip'] = (array) $followup['skip'];

		return MP_SW_Result::ok( array( 'tasks_plan' => $plan ) );
	}
}

/**
 * K6.2 „brak-duplikatow-zadan" — ponowione zdarzenie nie tworzy drugiej pary.
 */
class MP_SW_D6_Critic_No_Duplicates extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data     = $agent_result->get_data();
		$plan     = isset( $data['tasks_plan'] ) ? (array) $data['tasks_plan'] : array();
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$flow     = isset( $snapshot['flow'] ) ? (array) $snapshot['flow'] : array();
		$open     = isset( $flow['open_tasks'] ) ? (array) $flow['open_tasks'] : array();

		$open_types = array();

		foreach ( $open as $task ) {
			$open_types[] = (string) $task['type'];
		}

		$planned = array();

		foreach ( (array) $plan['create'] as $task ) {
			$type = (string) $task['type'];

			if ( in_array( $type, $planned, true ) || in_array( $type, $open_types, true ) ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: typ zadania. */
						__( 'Drugie otwarte zadanie typu %s dla tego samego procesu.', 'mp-sales-workflow' ),
						$type
					),
					array( 'errors' => array( 'tasks_plan.create' ) ),
					'duplicate_task'
				);
			}

			if ( '' === (string) $task['event_id'] ) {
				// Bez znacznika zdarzenia nie da się później powiedzieć, które
				// wywołanie utworzyło zadanie — a to jedyny ślad łączący powtórkę
				// crona z terminem, który już powstał.
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: typ zadania. */
						__( 'Zadanie %s bez znacznika zdarzenia.', 'mp-sales-workflow' ),
						$type
					),
					array( 'errors' => array( 'tasks_plan.create.event_id' ) ),
					'task_without_event_id'
				);
			}

			$planned[] = $type;
		}

		$closing = array();

		foreach ( (array) $plan['close'] as $task ) {
			$closing[] = (int) $task['task_id'];
		}

		foreach ( (array) $plan['fire'] as $task ) {
			if ( in_array( (int) $task['task_id'], $closing, true ) ) {
				// Zadanie jednocześnie wykonane i anulowane dałoby dwa sprzeczne
				// zapisy w jednej transakcji — wynik zależałby od kolejności.
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %d: identyfikator zadania. */
						__( 'Zadanie %d naraz wykonane i anulowane.', 'mp-sales-workflow' ),
						$task['task_id']
					),
					array( 'errors' => array( 'tasks_plan' ) ),
					'conflicting_task_ops'
				);
			}
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * QA6 — bramka jakosci: warunek 4.5 i brak duplikatow zadan.
 */
class MP_SW_D6_QA_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$plan = (array) $context->get( 'tasks_plan', MP_SW_D6_Scheduler::empty_plan() );

		$guarded = 0;

		foreach ( (array) $plan['create'] as $task ) {
			if ( '' !== (string) $task['guard_status'] ) {
				++$guarded;
			}
		}

		return MP_SW_Result::ok(
			array(
				'qa_created'   => count( (array) $plan['create'] ),
				'qa_guarded'   => $guarded,
				'qa_closed'    => count( (array) $plan['close'] ),
				'qa_fired'     => count( (array) $plan['fire'] ),
				'qa_db_writes' => $context->get_db_writes(),
			)
		);
	}
}

/**
 * QA6 krytyk — zadanie bez wartownika statusu = FAIL.
 */
class MP_SW_D6_QA_Critic extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( $data['qa_created'] !== $data['qa_guarded'] ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: liczba zadań, 2: liczba zadań z wartownikiem. */
					__( 'Zadania bez wartownika statusu: zaplanowano %1$d, z wartownikiem %2$d.', 'mp-sales-workflow' ),
					$data['qa_created'],
					$data['qa_guarded']
				),
				array( 'errors' => array( 'guard_status' ) ),
				'task_without_guard'
			);
		}

		if ( $data['qa_db_writes'] > 0 ) {
			// Dział 6 tylko planuje. Zapis przed Działem 8 oznaczałby zadanie
			// utrwalone poza transakcją — zostałoby po wycofaniu reszty.
			return MP_SW_Result::fail(
				__( 'Dział 6 zapisał do bazy przed transakcją.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'db_writes' ) ),
				'premature_write'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * ZADANIA FOLLOW-UP.
 */
class MP_SW_Department_06 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 6;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'zadania-follow-up';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_D6_Agent_Schedule( '6.1', 'harmonogram', 'Planuje zadania d+3 i d+7 od wysłania oferty, z wartownikiem: guard_status = oferta_wyslana' ),
				'critic' => new MP_SW_D6_Critic_Guard( 'K6.1', 'warunek-4.5', 'Zadanie aktywuje się TYLKO gdy status niezmieniony — dosłownie wg zlecenia' ),
			),
			array(
				'agent'  => new MP_SW_D6_Agent_Dedup( '6.2', 'deduplikacja', 'Jedno otwarte zadanie danego typu na proces; zmiana statusu anuluje oczekujące' ),
				'critic' => new MP_SW_D6_Critic_No_Duplicates( 'K6.2', 'brak-duplikatów-zadań', 'Ponowione zdarzenie nie tworzy drugiej pary zadań (event_id)' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_D6_QA_Agent( 'QA6', 'bramka jakosci D6', 'warunek-4.5 + brak duplikatów zadań' ),
			new MP_SW_D6_QA_Critic( 'QA6.K', 'krytyk bramki D6', 'zadanie bez wartownika statusu = FAIL' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'ZADANIA FOLLOW-UP',
			'pkt 2: zadania follow-up · aut. 4.5',
			$pairs,
			$gate
		);
	}
}
