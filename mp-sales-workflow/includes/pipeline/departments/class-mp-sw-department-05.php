<?php
/**
 * Dzial 5 pipeline LP.3 — MASZYNA STATUSÓW.
 *
 * Zakres wg diagramu: pkt 2: statusy procesu
 *
 * Operacje dzialu:
 *  - Słownik dozwolonych przejść (wersjonowany)
 *  - Skutki przejścia wyliczone jawnie
 *
 * Zrodlo (Golden Rule #2): docs/dzial-05/maszyna-statusow.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: maszyna stanow wg zlecenia · kryt. 5.5. Sam slownik
 * przejsc pochodzi ze zlecenia, ale dokumentacja dzialu opisuje NARZEDZIA,
 * ktorymi jest realizowany: sprawdzenie w trybie scislym (in_array ze strict —
 * `match` wymaga PHP 8, a wtyczka deklaruje 7.4), current_time() (jedno zrodlo
 * czasu w GMT dla SLA) i wpdb::update().
 *
 * Dzial NICZEGO nie zapisuje — wylicza tylko, czy przejscie jest legalne i
 * jakie ma skutki. Zapis nalezy do Dzialu 8.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slownik przejsc i skutkow.
 */
class MP_SW_D5_Machine {

	/** Wersja słownika przejść — trafia do dziennika razem z decyzją. */
	const MACHINE_VERSION = 'v1';

	/** Skutek: zaplanuj zadania follow-up d+3 i d+7. */
	const EFFECT_SCHEDULE_FOLLOWUPS = 'schedule_followups';

	/** Skutek: powiadom klienta. */
	const EFFECT_NOTIFY_CLIENT = 'notify_client';

	/** Skutek: powiadom handlowca. */
	const EFFECT_NOTIFY_SALESMAN = 'notify_salesman';

	/** Skutek: zamknij oczekujące zadania procesu. */
	const EFFECT_CLOSE_TASKS = 'close_tasks';

	/** Skutek: ustaw termin pierwszego kontaktu. */
	const EFFECT_SET_SLA = 'set_sla';

	/**
	 * Dozwolone przejścia: status źródłowy => lista docelowych.
	 *
	 * Statusy końcowe (`won`, `lost`) mają pustą listę — z nich nie wychodzi
	 * już żadne przejście.
	 *
	 * @return array<string,string[]>
	 */
	public static function transitions() {
		return array(
			MP_Sales_Workflow_DB::STATUS_NEW         => array( MP_Sales_Workflow_DB::STATUS_ASSIGNED ),
			MP_Sales_Workflow_DB::STATUS_ASSIGNED    => array( MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT, MP_Sales_Workflow_DB::STATUS_LOST ),
			MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT => array( MP_Sales_Workflow_DB::STATUS_OFFER_SENT, MP_Sales_Workflow_DB::STATUS_LOST ),
			MP_Sales_Workflow_DB::STATUS_OFFER_SENT  => array( MP_Sales_Workflow_DB::STATUS_NEGOTIATION, MP_Sales_Workflow_DB::STATUS_WON, MP_Sales_Workflow_DB::STATUS_LOST ),
			MP_Sales_Workflow_DB::STATUS_NEGOTIATION => array( MP_Sales_Workflow_DB::STATUS_WON, MP_Sales_Workflow_DB::STATUS_LOST ),
			MP_Sales_Workflow_DB::STATUS_WON         => array(),
			MP_Sales_Workflow_DB::STATUS_LOST        => array(),
		);
	}

	/**
	 * Skutki wejścia w dany status — jedyne źródło prawdy dla Działów 6 i 7.
	 *
	 * @param string $to Status docelowy.
	 * @return string[]
	 */
	public static function effects_for( $to ) {
		$map = array(
			MP_Sales_Workflow_DB::STATUS_ASSIGNED    => array( self::EFFECT_SET_SLA, self::EFFECT_NOTIFY_SALESMAN ),
			MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT => array(),
			MP_Sales_Workflow_DB::STATUS_OFFER_SENT  => array( self::EFFECT_SCHEDULE_FOLLOWUPS, self::EFFECT_NOTIFY_CLIENT ),
			MP_Sales_Workflow_DB::STATUS_NEGOTIATION => array( self::EFFECT_CLOSE_TASKS, self::EFFECT_NOTIFY_SALESMAN ),
			MP_Sales_Workflow_DB::STATUS_WON         => array( self::EFFECT_CLOSE_TASKS, self::EFFECT_NOTIFY_SALESMAN ),
			MP_Sales_Workflow_DB::STATUS_LOST        => array( self::EFFECT_CLOSE_TASKS ),
		);

		return isset( $map[ $to ] ) ? $map[ $to ] : array();
	}

	/**
	 * Status docelowy wynikający z typu zdarzenia.
	 *
	 * @param string $type    Typ zdarzenia.
	 * @param array  $context_data Dane koperty (dla status.change).
	 * @return string Pusty ciąg, gdy zdarzenie nie zmienia statusu.
	 */
	public static function target_status( $type, array $context_data ) {
		if ( MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED === $type ) {
			return MP_Sales_Workflow_DB::STATUS_ASSIGNED;
		}

		if ( MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED === $type ) {
			return MP_Sales_Workflow_DB::STATUS_OFFER_SENT;
		}

		if ( MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE === $type ) {
			$to = isset( $context_data['to_status'] ) ? (string) $context_data['to_status'] : '';
			return trim( $to );
		}

		// `task.due` i `dashboard.view` statusu nie ruszają.
		return '';
	}

	/**
	 * Typy zdarzeń, którym wolno nie mieć statusu docelowego.
	 *
	 * Lista zamknięta i wyliczona wprost. Wcześniej „brak statusu docelowego"
	 * rozpoznawało się po pustym wyniku `target_status()`, więc `status.change`
	 * z pustym `to_status` wyglądał dokładnie tak samo jak podgląd pulpitu —
	 * i przechodził jako zdarzenie, które statusu nie rusza.
	 *
	 * @return string[]
	 */
	public static function statusless_events() {
		return array(
			MP_SW_Pipeline_Factory::EVENT_TASK_DUE,
			MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW,
		);
	}

	/**
	 * Czy przejście jest dozwolone.
	 *
	 * Porównanie w trybie ścisłym: bez `$strict` PHP przed wersją 8.0 uznałby
	 * niektóre wartości za równe mimo różnych typów (patrz dokumentacja działu),
	 * a status ma być dopasowany co do znaku.
	 *
	 * @param string $from Status źródłowy.
	 * @param string $to   Status docelowy.
	 * @return bool
	 */
	public static function is_allowed( $from, $to ) {
		$transitions = self::transitions();

		if ( ! isset( $transitions[ $from ] ) ) {
			return false;
		}

		return in_array( $to, $transitions[ $from ], true );
	}
}

/**
 * A5.1 „przejście" — sprawdza przejście w słowniku.
 */
class MP_SW_D5_Agent_Transition extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$event = (array) $context->get( 'event', array() );
		$type  = isset( $event['type'] ) ? (string) $event['type'] : '';
		$flow  = (array) $context->get( 'flow', array() );

		$from = isset( $flow['row']['status'] ) && '' !== (string) $flow['row']['status']
			? (string) $flow['row']['status']
			: MP_Sales_Workflow_DB::STATUS_NEW;

		$to = MP_SW_D5_Machine::target_status( $type, $context->all() );

		/*
		 * Pusty status docelowy jest LEGALNY tylko dla zdarzeń, które statusu nie
		 * ruszają z definicji. Dla `status.change` oznacza koperta niekompletną:
		 * ktoś prosi o zmianę statusu, nie mówiąc na jaki.
		 *
		 * Wcześniej taka koperta szła gałęzią poniżej — z `allowed = true`.
		 * Wywołujący dostawał sukces, a proces mimo to był zapisywany: token
		 * blokady rósł i zdarzenie lądowało w rejestrze. Czyli nie tylko cisza
		 * zamiast błędu, ale i zużyty klucz idempotencji, przez który POPRAWIONA
		 * próba z tym samym `event_id` odbiłaby się jako powtórka.
		 */
		if ( '' === $to && ! in_array( $type, MP_SW_D5_Machine::statusless_events(), true ) ) {
			return MP_SW_Result::fail(
				__( 'Zmiana statusu bez statusu docelowego.', 'mp-sales-workflow' ),
				array(
					'errors'      => array( 'to_status' ),
					'http_status' => 400,
				),
				'missing_target_status'
			);
		}

		// Zdarzenie, które nie rusza statusu, jest legalne z definicji — nie ma
		// przejścia do sprawdzenia.
		if ( '' === $to ) {
			return MP_SW_Result::ok(
				array(
					'transition' => array(
						'from'            => $from,
						'to'              => $from,
						'allowed'         => true,
						'changes_status'  => false,
						'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
					),
				)
			);
		}

		$known   = in_array( $to, MP_Sales_Workflow_DB::statuses(), true );
		$allowed = $known && MP_SW_D5_Machine::is_allowed( $from, $to );

		/*
		 * Przejście „w to samo miejsce" (np. powtórzone zatwierdzenie oferty)
		 * nie jest błędem — status po prostu się nie zmienia. Rozróżnienie jest
		 * istotne: inaczej ponowione zdarzenie kończyłoby się odmową zamiast
		 * cichym potwierdzeniem stanu, który już obowiązuje.
		 */
		if ( ! $allowed && $from === $to ) {
			return MP_SW_Result::ok(
				array(
					'transition' => array(
						'from'            => $from,
						'to'              => $to,
						'allowed'         => true,
						'changes_status'  => false,
						'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
					),
				)
			);
		}

		return MP_SW_Result::ok(
			array(
				'transition' => array(
					'from'            => $from,
					'to'              => $to,
					'allowed'         => $allowed,
					'changes_status'  => $allowed,
					'known_status'    => $known,
					'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
				),
			)
		);
	}
}

/**
 * K5.1 „legalność-przejścia" — przejście spoza słownika kończy się odmową.
 */
class MP_SW_D5_Critic_Legality extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data       = $agent_result->get_data();
		$transition = isset( $data['transition'] ) ? (array) $data['transition'] : array();

		if ( empty( $transition['allowed'] ) ) {
			$from = isset( $transition['from'] ) ? $transition['from'] : '?';
			$to   = isset( $transition['to'] ) ? $transition['to'] : '?';

			// Stan zostaje bez zmian — odmowa jest jedyną reakcją, żadnego
			// „przybliżonego" przejścia do najbliższego dozwolonego statusu.
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: status źródłowy, 2: status docelowy. */
					__( 'Przejście %1$s → %2$s spoza słownika — stan bez zmian.', 'mp-sales-workflow' ),
					$from,
					$to
				),
				array(
					'errors'      => array( 'transition' ),
					'from'        => $from,
					'to'          => $to,
					'http_status' => 409,
				),
				'illegal_transition'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A5.2 „skutki" — jawna lista następstw przejścia.
 */
class MP_SW_D5_Agent_Effects extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$transition = (array) $context->get( 'transition', array() );

		$effects = empty( $transition['changes_status'] )
			? array()
			: MP_SW_D5_Machine::effects_for( (string) $transition['to'] );

		$sla_due_at = '';

		if ( in_array( MP_SW_D5_Machine::EFFECT_SET_SLA, $effects, true ) ) {
			// Jedno źródło czasu w GMT — mieszanie stref dawało w poprzednich
			// wtyczkach realne rozjazdy terminów.
			$sla_due_at = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) . ' +1 day' ) );
		}

		return MP_SW_Result::ok(
			array(
				'effects'    => $effects,
				'sla_due_at' => $sla_due_at,
			)
		);
	}
}

/**
 * K5.2 „komplet-skutków" — każdy skutek ma pokrycie w regule przejścia.
 */
class MP_SW_D5_Critic_Effects extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data       = $agent_result->get_data();
		$effects    = isset( $data['effects'] ) ? (array) $data['effects'] : array();
		$transition = (array) $context->get( 'transition', array() );

		/*
		 * Status docelowy odczytany NIEZALEŻNIE od tablicy `transition`. Wcześniej
		 * `$expected` powstawało z dokładnie tego samego wyrażenia, co `$effects`
		 * u agenta 5.2 — `effects_for( $transition['to'] )` na tym samym kontekście.
		 * Porównanie zawsze wychodziło na zero, więc krytyk sprawdzał tylko WYNIK
		 * agenta (to zostaje, patrz niżej), a WEJŚCIA nie sprawdzał nikt: koperta
		 * z podmienionym `to` szła przez parę bez słowa, a Działy 6 i 7 wykonywały
		 * skutki NIE TEGO przejścia.
		 */
		$event    = (array) $context->get( 'event', array() );
		$type     = isset( $event['type'] ) ? (string) $event['type'] : '';
		$to_agent = isset( $transition['to'] ) ? (string) $transition['to'] : '';
		$to_event = MP_SW_D5_Machine::target_status( $type, $context->all() );

		/*
		 * Kod `transition_not_from_event` celowo NIE trafia do słownika
		 * `MP_SW_Errors::map()` i celowo nie niesie `http_status`: taka koperta nie
		 * powstaje z żadnego żądania użytkownika, tylko z błędu w kodzie albo
		 * podmiany danych między działami. To inwariant wewnętrzny i ma wychodzić
		 * jako MP3-E500, tak samo jak `multiple_writes`.
		 */
		if ( ! empty( $transition['changes_status'] ) && $to_event !== $to_agent ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: status żądany przez zdarzenie, 2: status z tablicy przejścia. */
					__( 'Przejście prowadzi gdzie indziej niż żądanie zdarzenia — zdarzenie: %1$s, przejście: %2$s.', 'mp-sales-workflow' ),
					'' !== $to_event ? $to_event : '—',
					'' !== $to_agent ? $to_agent : '—'
				),
				array( 'errors' => array( 'transition.to' ) ),
				'transition_not_from_event'
			);
		}

		$expected = empty( $transition['changes_status'] )
			? array()
			: MP_SW_D5_Machine::effects_for( $to_event );

		/*
		 * Porównanie w obie strony. Skutek nadmiarowy to dokładnie to „przy
		 * okazji", którego zabrania kryterium — np. wysyłka do klienta doklejona
		 * do przejścia, które jej nie przewiduje. Skutek brakujący jest równie
		 * groźny: follow-up, który nigdy nie powstanie.
		 */
		$extra   = array_values( array_diff( $effects, $expected ) );
		$missing = array_values( array_diff( $expected, $effects ) );

		if ( ! empty( $extra ) || ! empty( $missing ) ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: skutki nadmiarowe, 2: skutki brakujące. */
					__( 'Skutki niezgodne z regułą przejścia — nadmiarowe: %1$s, brakujące: %2$s', 'mp-sales-workflow' ),
					$extra ? implode( ', ', $extra ) : '—',
					$missing ? implode( ', ', $missing ) : '—'
				),
				array( 'errors' => array_merge( $extra, $missing ) ),
				'effects_mismatch'
			);
		}

		if ( in_array( MP_SW_D5_Machine::EFFECT_SET_SLA, $effects, true ) && '' === (string) $data['sla_due_at'] ) {
			return MP_SW_Result::fail(
				__( 'Skutek set_sla bez wyliczonego terminu.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'sla_due_at' ) ),
				'sla_not_calculated'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * QA5 — bramka jakości: legalność przejścia i komplet skutków.
 */
class MP_SW_D5_QA_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$transition = (array) $context->get( 'transition', array() );

		return MP_SW_Result::ok(
			array(
				'qa_allowed' => ! empty( $transition['allowed'] ),
				'qa_version' => isset( $transition['machine_version'] ) ? (string) $transition['machine_version'] : '',
				'qa_effects' => (array) $context->get( 'effects', array() ),
			)
		);
	}
}

/**
 * QA5 krytyk — stan zmienia się tylko przez maszynę.
 */
class MP_SW_D5_QA_Critic extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( empty( $data['qa_allowed'] ) ) {
			return MP_SW_Result::fail(
				__( 'Przejście nierozstrzygnięte przez maszynę statusów.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'transition' ) ),
				'transition_not_resolved'
			);
		}

		if ( '' === $data['qa_version'] ) {
			// Bez wersji słownika nie da się później odtworzyć, wg jakich reguł
			// zapadła decyzja — a dziennik ma to pokazywać (kryterium 5.5).
			return MP_SW_Result::fail(
				__( 'Decyzja bez wersji słownika przejść.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'machine_version' ) ),
				'missing_machine_version'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * MASZYNA STATUSÓW.
 */
class MP_SW_Department_05 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 5;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'maszyna-statusow';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_D5_Agent_Transition( '5.1', 'przejście', 'Sprawdza przejście w słowniku: nowy → przypisany → oferta_robocza → oferta_wyslana → negocjacje → wygrany / przegrany' ),
				'critic' => new MP_SW_D5_Critic_Legality( 'K5.1', 'legalność-przejścia', 'Przejście spoza słownika = odmowa z kodem, stan bez zmian' ),
			),
			array(
				'agent'  => new MP_SW_D5_Agent_Effects( '5.2', 'skutki', 'Wylicza skutki przejścia: nowe SLA, zamknięcie oczekujących zadań, powiadomienia do wysłania' ),
				'critic' => new MP_SW_D5_Critic_Effects( 'K5.2', 'komplet-skutków', 'Każdy skutek ma pokrycie w regule przejścia — nic „przy okazji”' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_D5_QA_Agent( 'QA5', 'bramka jakosci D5', 'legalność-przejścia + komplet skutków' ),
			new MP_SW_D5_QA_Critic( 'QA5.K', 'krytyk bramki D5', 'stan zmienia się tylko przez maszynę, nigdy wprost' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'MASZYNA STATUSÓW',
			'pkt 2: statusy procesu',
			$pairs,
			$gate
		);
	}
}
