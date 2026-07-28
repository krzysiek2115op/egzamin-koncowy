<?php
/**
 * Dzial 9 pipeline LP.3 — WYJŚCIE I URUCHOMIENIE KOLEJKI.
 *
 * Zakres wg diagramu: aut. 4.4: wysyłka po akceptacji
 *
 * Operacje dzialu:
 *  - Zdarzenie po COMMIT, dokładnie raz
 *  - Kolejka rusza poza żądaniem
 *  - Wyjście tylko JSON + trace_id
 *
 * Zrodlo (Golden Rule #2): docs/dzial-09/wyjscie-i-uruchomienie-kolejki.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: do_action · wp_send_json_success · Action Scheduler
 *
 * To jedyny dzial ZA transakcja. Pipeline zatwierdza zapis PRZED wejsciem tutaj
 * i zostawia w kopercie znacznik `committed` — bez niego krytyk K9.1 nie mialby
 * czego sprawdzic, a "nic nie wychodzi przed COMMIT" zostaloby deklaracja.
 *
 * Dzial NIE WYSYLA e-maili. Zleca tylko przebieg kolejki przez harmonogram
 * zadan: wysylka ma sie odbyc POZA tym zadaniem, zeby odpowiedz nie czekala na
 * serwer SMTP (zasada 1 AJAX).
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wystawianie zdarzen i zlecanie kolejki.
 */
class MP_SW_D9_Emitter {

	/** Zdarzenie: proces sprzedażowy zmieniony. */
	const HOOK_FLOW_UPDATED = 'mp_sw_flow_updated';

	/** Zdarzenie: powiadomienia czekają w kolejce. */
	const HOOK_QUEUE_READY = 'mp_sw_notifications_queued';

	/** Hak crona uruchamiający wysyłkę kolejki. */
	const CRON_QUEUE = 'mp_sw_run_queue';

	/** @var array Wystawione zdarzenia: "event_id|hook" => liczba wywołań. */
	protected static $fired = array();

	/**
	 * Wystawia zdarzenie dokładnie raz na identyfikator zdarzenia.
	 *
	 * Jedno uruchomienie crona przetwarza wiele zdarzeń po kolei, a odbiorcy
	 * (wtyczki 1 i 2) reagują na nie zapisem. Powtórzone wystawienie tego samego
	 * zdarzenia oznaczałoby u nich podwójną reakcję.
	 *
	 * @param string $hook     Nazwa haka.
	 * @param string $event_id Identyfikator zdarzenia.
	 * @param array  $payload  Dane przekazywane odbiorcom.
	 * @return bool Czy zdarzenie zostało wystawione teraz.
	 */
	public static function emit( $hook, $event_id, array $payload ) {
		$key = $event_id . '|' . $hook;

		if ( isset( self::$fired[ $key ] ) ) {
			++self::$fired[ $key ];
			return false;
		}

		self::$fired[ $key ] = 1;

		do_action( $hook, $payload );

		return true;
	}

	/**
	 * Ile razy próbowano wystawić dane zdarzenie.
	 *
	 * @param string $hook     Nazwa haka.
	 * @param string $event_id Identyfikator zdarzenia.
	 * @return int
	 */
	public static function times( $hook, $event_id ) {
		$key = $event_id . '|' . $hook;

		return isset( self::$fired[ $key ] ) ? (int) self::$fired[ $key ] : 0;
	}

	/**
	 * Czyści rejestr wystawionych zdarzeń.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$fired = array();
	}

	/**
	 * Zleca przebieg kolejki poza żądaniem.
	 *
	 * Sprawdzenie `wp_next_scheduled()` jest wprost z dokumentacji działu 6:
	 * "Use wp_next_scheduled() to prevent duplicate events". Bez niego każde
	 * zdarzenie dokładałoby własny termin.
	 *
	 * Zaplanowanie zadania zapisuje do `wp_options` — świadomie POZA transakcją
	 * procesu i dopiero po COMMIT. Wewnątrz zostawiłoby po wycofaniu termin do
	 * wysyłki powiadomień, których w bazie już nie ma.
	 *
	 * @return bool Czy zlecenie zostało dodane teraz.
	 */
	public static function schedule_queue() {
		if ( wp_next_scheduled( self::CRON_QUEUE ) ) {
			return false;
		}

		return (bool) wp_schedule_single_event( time() + 60, self::CRON_QUEUE );
	}
}

/**
 * A9.1 „zdarzenia" — po COMMIT: zdarzenie dokladnie raz i zlecenie kolejki.
 */
class MP_SW_D9_Agent_Events extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$event         = (array) $context->get( 'event', array() );
		$event_id      = (string) $context->get( 'event_id', '' );
		$committed     = (bool) $context->get( 'committed', false );
		$writes        = $context->get_db_writes();
		$notifications = (array) $context->get( 'notifications', array() );

		/*
		 * Zdarzenia wychodzą TYLKO wtedy, gdy było co zatwierdzać i zostało to
		 * zatwierdzone. Podgląd pulpitu niczego nie zapisuje, więc nie ma o czym
		 * powiadamiać — a zdarzenie „proces zmieniony" po samym odczycie byłoby
		 * po prostu nieprawdą.
		 */
		if ( $writes > 0 && ! $committed ) {
			return MP_SW_Result::fail(
				__( 'Próba wystawienia zdarzenia przed zatwierdzeniem zapisu.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'committed' ) ),
				'emit_before_commit'
			);
		}

		$dispatched = array();
		$scheduled  = false;

		if ( $writes > 0 ) {
			$transition = (array) $context->get( 'transition', array() );

			$payload = array(
				'event_id' => $event_id,
				'lead_id'  => isset( $event['entity']['lead_id'] ) ? (int) $event['entity']['lead_id'] : 0,
				'flow_id'  => (int) $context->get( 'flow_id', 0 ),
				'status'   => isset( $transition['to'] ) ? (string) $transition['to'] : '',
				'trace_id' => (string) $context->get( 'trace_id', $event_id ),
			);

			if ( MP_SW_D9_Emitter::emit( MP_SW_D9_Emitter::HOOK_FLOW_UPDATED, $event_id, $payload ) ) {
				$dispatched[] = MP_SW_D9_Emitter::HOOK_FLOW_UPDATED;
			}

			if ( ! empty( $notifications ) ) {
				$queue_payload          = $payload;
				$queue_payload['count'] = count( $notifications );

				if ( MP_SW_D9_Emitter::emit( MP_SW_D9_Emitter::HOOK_QUEUE_READY, $event_id, $queue_payload ) ) {
					$dispatched[] = MP_SW_D9_Emitter::HOOK_QUEUE_READY;
				}

				$scheduled = MP_SW_D9_Emitter::schedule_queue();
			}
		}

		return MP_SW_Result::ok(
			array(
				'dispatched'      => $dispatched,
				'queue_scheduled' => $scheduled,
				'emit_times'      => MP_SW_D9_Emitter::times( MP_SW_D9_Emitter::HOOK_FLOW_UPDATED, $event_id ),
			)
		);
	}
}

/**
 * K9.1 „jednokrotnosc" — nic nie wychodzi przed COMMIT, nic dwa razy.
 */
class MP_SW_D9_Critic_Once extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data       = $agent_result->get_data();
		$dispatched = isset( $data['dispatched'] ) ? (array) $data['dispatched'] : array();

		if ( $context->get_db_writes() > 0 && ! $context->get( 'committed', false ) ) {
			// E-mail o stanie, którego nie ma — dokładnie to, przed czym broni
			// kryterium działu.
			return MP_SW_Result::fail(
				__( 'Zdarzenia wystawione przed zatwierdzeniem zapisu.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'committed' ) ),
				'emit_before_commit'
			);
		}

		if ( count( array_unique( $dispatched ) ) !== count( $dispatched ) ) {
			return MP_SW_Result::fail(
				__( 'To samo zdarzenie wystawione więcej niż raz.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'dispatched' ) ),
				'duplicate_emit'
			);
		}

		if ( (int) $data['emit_times'] > 1 ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %d: liczba prób wystawienia zdarzenia. */
					__( 'Zdarzenie procesu wystawiane %d raz(y) w jednym żądaniu.', 'mp-sales-workflow' ),
					$data['emit_times']
				),
				array( 'errors' => array( 'emit_times' ) ),
				'duplicate_emit'
			);
		}

		if ( 0 === $context->get_db_writes() && ! empty( $dispatched ) ) {
			return MP_SW_Result::fail(
				__( 'Zdarzenie wystawione mimo braku zapisu — odbiorcy dostaliby sygnał o niczym.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'dispatched' ) ),
				'emit_without_write'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A9.2 „odpowiedz" — JSON z wynikiem procesu i kodem HTTP.
 */
class MP_SW_D9_Agent_Response extends MP_SW_Abstract_Agent {

	/**
	 * Klucze dopuszczone w odpowiedzi.
	 *
	 * @return string[]
	 */
	public static function allowed_keys() {
		return array( 'ok', 'lead_id', 'flow_id', 'status', 'assigned_user_id', 'tasks', 'notifications', 'trace_id', 'http_status' );
	}

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$event      = (array) $context->get( 'event', array() );
		$transition = (array) $context->get( 'transition', array() );
		$assignment = (array) $context->get( 'assignment', array() );
		$plan       = (array) $context->get( 'tasks_plan', MP_SW_D6_Scheduler::empty_plan() );
		$event_id   = (string) $context->get( 'event_id', '' );

		$response = array(
			'ok'               => true,
			'lead_id'          => isset( $event['entity']['lead_id'] ) ? (int) $event['entity']['lead_id'] : 0,
			'flow_id'          => (int) $context->get( 'flow_id', 0 ),
			'status'           => isset( $transition['to'] ) ? (string) $transition['to'] : '',
			'assigned_user_id' => isset( $assignment['user_id'] ) ? (int) $assignment['user_id'] : 0,
			'tasks'            => array(
				'created' => count( (array) $plan['create'] ),
				'closed'  => count( (array) $plan['close'] ) + count( (array) $plan['fire'] ),
			),
			'notifications'    => count( (array) $context->get( 'notifications', array() ) ),
			// trace_id wraca do wywołującego, żeby dało się połączyć odpowiedź z
			// wpisami dziennika bez zgadywania po czasie.
			'trace_id'         => (string) $context->get( 'trace_id', $event_id ),
			'http_status'      => 200,
		);

		return MP_SW_Result::ok( array( 'response' => $response ) );
	}
}

/**
 * K9.2 „zakres-odpowiedzi" — tylko dane tego procesu, przyciete zakresem roli.
 */
class MP_SW_D9_Critic_Scope extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data     = $agent_result->get_data();
		$response = isset( $data['response'] ) ? (array) $data['response'] : array();
		$event    = (array) $context->get( 'event', array() );

		$extra = array_diff( array_keys( $response ), MP_SW_D9_Agent_Response::allowed_keys() );

		if ( ! empty( $extra ) ) {
			/*
			 * Odpowiedź budowana jest z tej samej koperty, w której leży snapshot
			 * całej firmy: lista handlowców, ich adresy, obciążenie, treści
			 * powiadomień. Wystarczy jedna nadmiarowa pozycja, żeby to wyszło na
			 * zewnątrz — dlatego dopuszczamy wyłącznie znane klucze.
			 */
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: lista nadmiarowych kluczy. */
					__( 'Odpowiedź zawiera dane spoza zakresu: %s', 'mp-sales-workflow' ),
					implode( ', ', $extra )
				),
				array( 'errors' => array_values( $extra ) ),
				'response_out_of_scope'
			);
		}

		$lead_id = isset( $event['entity']['lead_id'] ) ? (int) $event['entity']['lead_id'] : 0;

		if ( (int) $response['lead_id'] !== $lead_id ) {
			return MP_SW_Result::fail(
				__( 'Odpowiedź dotyczy innego procesu niż zdarzenie.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'response.lead_id' ) ),
				'response_wrong_process'
			);
		}

		/*
		 * Handlowiec widzi swój proces, ale nie dowiaduje się z odpowiedzi, do
		 * kogo trafił cudzy. Przy zakresie węższym niż „wszystko" identyfikator
		 * właściciela zostaje w odpowiedzi tylko wtedy, gdy to on sam.
		 */
		$scope = (string) $context->get( 'scope', MP_SW_D3::SCOPE_ALL );
		$actor = isset( $event['actor']['user_id'] ) ? (int) $event['actor']['user_id'] : 0;
		$owner = (int) $response['assigned_user_id'];

		if ( MP_SW_D3::SCOPE_OWN === $scope && $actor > 0 && $owner > 0 && $owner !== $actor ) {
			return MP_SW_Result::fail(
				__( 'Odpowiedź ujawnia właściciela cudzego procesu przy zakresie „własne".', 'mp-sales-workflow' ),
				array( 'errors' => array( 'response.assigned_user_id' ) ),
				'response_scope_leak'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * QA9 — bramka jakosci: tylko JSON i zdarzenia po COMMIT.
 */
class MP_SW_D9_QA_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$response = (array) $context->get( 'response', array() );
		$encoded  = wp_json_encode( $response );

		return MP_SW_Result::ok(
			array(
				'qa_json'          => is_string( $encoded ),
				'qa_trace'         => isset( $response['trace_id'] ) ? (string) $response['trace_id'] : '',
				'qa_committed'     => (bool) $context->get( 'committed', false ),
				'qa_db_writes'     => $context->get_db_writes(),
				'qa_mail_attempts' => MP_SW_D7_Notifier::mail_attempts(),
				'qa_dispatched'    => count( (array) $context->get( 'dispatched', array() ) ),
			)
		);
	}
}

/**
 * QA9 krytyk — nic nie wisi w zadaniu po odpowiedzi.
 */
class MP_SW_D9_QA_Critic extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( empty( $data['qa_json'] ) ) {
			// Odpowiedzi, której nie da się zakodować, wywołujący nie odbierze —
			// dostanie pustą treść z kodem 200.
			return MP_SW_Result::fail(
				__( 'Odpowiedzi nie da się zapisać jako JSON.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'response' ) ),
				'response_not_json'
			);
		}

		if ( '' === $data['qa_trace'] ) {
			return MP_SW_Result::fail(
				__( 'Odpowiedź bez trace_id — nie da się jej połączyć z dziennikiem.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'trace_id' ) ),
				'missing_trace_id'
			);
		}

		if ( $data['qa_db_writes'] > 0 && empty( $data['qa_committed'] ) ) {
			return MP_SW_Result::fail(
				__( 'Odpowiedź wychodzi przed zatwierdzeniem zapisu.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'committed' ) ),
				'response_before_commit'
			);
		}

		if ( $data['qa_mail_attempts'] > 0 ) {
			// „Nic nie wisi w żądaniu po odpowiedzi": wysyłka należy do kolejki
			// uruchamianej przez harmonogram, nie do tego przebiegu.
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %d: liczba prób wysyłki. */
					__( 'Wysyłka wykonana w żądaniu (%d) — kolejka ma ruszyć poza nim.', 'mp-sales-workflow' ),
					$data['qa_mail_attempts']
				),
				array( 'errors' => array( 'mail_attempts' ) ),
				'send_in_request'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * WYJŚCIE I URUCHOMIENIE KOLEJKI.
 */
class MP_SW_Department_09 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 9;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'wyjscie-i-uruchomienie-kolejki';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_D9_Agent_Events( '9.1', 'zdarzenia', 'Po COMMIT: mp_sw_flow_updated (dokładnie raz) + zlecenie kolejki wysyłki przez harmonogram zadań' ),
				'critic' => new MP_SW_D9_Critic_Once( 'K9.1', 'jednokrotność', 'Nic nie wychodzi przed COMMIT — inaczej e-mail o stanie, którego nie ma' ),
			),
			array(
				'agent'  => new MP_SW_D9_Agent_Response( '9.2', 'odpowiedź', 'JSON: status, przypisanie, liczba zadań i powiadomień, trace_id; kod HTTP wg wyniku' ),
				'critic' => new MP_SW_D9_Critic_Scope( 'K9.2', 'zakres-odpowiedzi', 'Tylko dane tego procesu, przycięte zakresem roli aktora' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_D9_QA_Agent( 'QA9', 'bramka jakosci D9', 'tylko-json + zdarzenia po COMMIT' ),
			new MP_SW_D9_QA_Critic( 'QA9.K', 'krytyk bramki D9', 'nic nie wisi w żądaniu po odpowiedzi' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'WYJŚCIE I URUCHOMIENIE KOLEJKI',
			'aut. 4.4: wysyłka po akceptacji',
			$pairs,
			$gate
		);
	}
}
