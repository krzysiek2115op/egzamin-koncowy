<?php
/**
 * Zaślepki Agenta i Krytyka — rusztowanie na czas budowy pipeline'u.
 *
 * Każda para w działach powstaje najpierw jako zaślepka z PRAWDZIWYM
 * identyfikatorem, nazwą i zadaniem z diagramu LP.3; realna logika podstawiana
 * jest dział po dziale w kolejnym kroku. Dzięki temu struktura pipeline'u jest
 * kompletna i sprawdzalna, zanim powstanie choć jedna reguła biznesowa.
 *
 * UWAGA: zaślepka przepuszcza wszystko. Żeby niezaimplementowana para nie
 * przeszła niezauważona do produkcji, każda z nich znakuje wynik kluczem
 * `__stub` — `MP_SW_Pipeline_Factory::stub_report()` wypisuje na tej podstawie,
 * co jeszcze czeka na wypełnienie.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent-zaślepka: nie robi nic i zwraca sukces.
 */
class MP_SW_Stub_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		return MP_SW_Result::ok(
			array(
				'__stub' => array( 'agent' => $this->id ),
			)
		);
	}

	/** @return bool */
	public function is_stub() {
		return true;
	}
}

/**
 * Krytyk-zaślepka: akceptuje wynik agenta bez sprawdzania.
 */
class MP_SW_Stub_Critic extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		return MP_SW_Result::ok( $agent_result->get_data() );
	}

	/** @return bool */
	public function is_stub() {
		return true;
	}
}
