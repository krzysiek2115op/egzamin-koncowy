<?php
/**
 * Fabryka pipeline — buduje działy LP.3 wraz z parami i bramkami jakości.
 *
 * TO JEST MAPA CAŁEGO PIPELINE'U w jednym miejscu.
 *
 * Odwzorowane reguły diagramu:
 *  - dział ma wiele par, każdy Agent ma dokładnie 1 Krytyka,
 *  - po każdym dziale 1 Bramka Jakości = dokładnie 1 QA Agent + 1 QA Krytyk,
 *  - zapis jedną transakcją w Dziale 8, zamkniętą PRZED Działem 9,
 *  - tryb `dashboard.view` przechodzi tylko przez działy 1-2-3-9 z zerową
 *    liczbą zapisów.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buduje kompletny pipeline LP.3.
 */
class MP_SW_Pipeline_Factory {

	/** Pełna ścieżka zapisu: działy 1–9. */
	const MODE_FULL = 'full';

	/** Ścieżka tylko do odczytu: działy 1-2-3-9, zero zapisów. */
	const MODE_READ_ONLY = 'read_only';

	// Słownik typów zdarzeń (Dział 1, Agent A1.1 „kontrakt").
	const EVENT_LEAD_CREATED   = 'lead.created';
	const EVENT_OFFER_APPROVED = 'offer.approved';
	const EVENT_STATUS_CHANGE  = 'status.change';
	const EVENT_TASK_DUE       = 'task.due';
	const EVENT_DASHBOARD_VIEW = 'dashboard.view';

	/** Dział, w którym wykonuje się jedyny zapis (jedna transakcja). */
	const WRITE_DEPARTMENT = 8;

	/**
	 * Pełny słownik typów zdarzeń.
	 *
	 * @return string[]
	 */
	public static function event_types() {
		return array(
			self::EVENT_LEAD_CREATED,
			self::EVENT_OFFER_APPROVED,
			self::EVENT_STATUS_CHANGE,
			self::EVENT_TASK_DUE,
			self::EVENT_DASHBOARD_VIEW,
		);
	}

	/**
	 * Tryb przebiegu dla danego typu zdarzenia.
	 *
	 * Rozstrzygnięcie trybu należy do fabryki, a nie do warunków rozsianych po
	 * działach: gdyby każdy dział sam sprawdzał, czy „to tylko podgląd", zasada
	 * `db_writes = 0` zależałaby od dyscypliny w dziewięciu miejscach naraz.
	 * Tutaj dział zapisujący po prostu nie trafia do przebiegu.
	 *
	 * @param string $event_type Typ zdarzenia.
	 * @return string
	 */
	public static function mode_for( $event_type ) {
		return self::EVENT_DASHBOARD_VIEW === $event_type ? self::MODE_READ_ONLY : self::MODE_FULL;
	}

	/**
	 * Tworzy pipeline właściwy dla typu zdarzenia.
	 *
	 * @param string $event_type Typ zdarzenia ze słownika.
	 * @return MP_SW_Pipeline
	 */
	public static function make( $event_type = self::EVENT_LEAD_CREATED ) {
		if ( self::MODE_READ_ONLY === self::mode_for( $event_type ) ) {
			return self::make_read_only();
		}

		return self::make_full();
	}

	/**
	 * Pełna ścieżka: działy 1–9, zapis jedną transakcją w Dziale 8.
	 *
	 * @return MP_SW_Pipeline
	 */
	protected static function make_full() {
		$pipeline = new MP_SW_Pipeline( new MP_SW_Pipeline_Logger() );

		// Transakcja otwiera się i zamyka na Dziale 8, żeby Dział 9 (zdarzenia
		// wyjściowe i uruchomienie kolejki e-mail) działał już po COMMIT.
		$pipeline->set_transactional_from( self::WRITE_DEPARTMENT );
		$pipeline->set_transactional_until( self::WRITE_DEPARTMENT );

		$pipeline->add_department( MP_SW_Department_01::build() );
		$pipeline->add_department( MP_SW_Department_02::build() );
		$pipeline->add_department( MP_SW_Department_03::build() );
		$pipeline->add_department( MP_SW_Department_04::build() );
		$pipeline->add_department( MP_SW_Department_05::build() );
		$pipeline->add_department( MP_SW_Department_06::build() );
		$pipeline->add_department( MP_SW_Department_07::build() );
		$pipeline->add_department( MP_SW_Department_08::build() );
		$pipeline->add_department( MP_SW_Department_09::build() );

		return $pipeline;
	}

	/**
	 * Ścieżka podglądu (`dashboard.view`): brama, odczyt, zakres roli, wyjście.
	 *
	 * Transakcja pozostaje wyłączona — dział zapisujący nie wchodzi w skład
	 * przebiegu, więc `db_writes` nie ma jak wzrosnąć powyżej zera.
	 *
	 * @return MP_SW_Pipeline
	 */
	protected static function make_read_only() {
		$pipeline = new MP_SW_Pipeline( new MP_SW_Pipeline_Logger() );

		$pipeline->add_department( MP_SW_Department_01::build() );
		$pipeline->add_department( MP_SW_Department_02::build() );
		$pipeline->add_department( MP_SW_Department_03::build() );
		$pipeline->add_department( MP_SW_Department_09::build() );

		return $pipeline;
	}

	/**
	 * Wykaz par, które są jeszcze zaślepkami — miernik postępu prac.
	 *
	 * @return array Numer działu => lista identyfikatorów par z zaślepką.
	 */
	public static function stub_report() {
		$report = array();

		foreach ( self::make_full()->get_departments() as $department ) {
			$stubs = array();

			foreach ( $department->get_pairs() as $pair ) {
				if ( $pair['agent'] instanceof MP_SW_Stub_Agent || $pair['critic'] instanceof MP_SW_Stub_Critic ) {
					$stubs[] = $pair['agent']->get_id();
				}
			}

			$gate = $department->get_gate();

			if ( $gate->get_qa_agent() instanceof MP_SW_Stub_Agent || $gate->get_qa_critic() instanceof MP_SW_Stub_Critic ) {
				$stubs[] = 'QA' . $department->get_number();
			}

			if ( ! empty( $stubs ) ) {
				$report[ $department->get_number() ] = $stubs;
			}
		}

		return $report;
	}
}
