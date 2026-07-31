<?php
/**
 * Kontrakty (interfejsy) pipeline: Agent i Krytyk.
 *
 * Zgodnie z diagramem: każdy Agent WYKONUJE zadanie, a przypisany mu Krytyk
 * WERYFIKUJE jego wynik. W kroku 3 podstawimy konkretne klasy realizujące
 * te interfejsy (deterministyczny kod PHP — opcja A).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent — wykonuje pojedyncze zadanie w dziale.
 */
interface MP_OB_Agent_Interface {

	/** @return string Identyfikator, np. "1.1". */
	public function get_id();

	/** @return string Czytelna nazwa/rola. */
	public function get_label();

	/**
	 * Wykonuje zadanie agenta.
	 *
	 * @param MP_OB_Context $context Kontekst pipeline.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context );
}

/**
 * Krytyk — weryfikuje wynik przypisanego Agenta.
 */
interface MP_OB_Critic_Interface {

	/** @return string Identyfikator krytyka. */
	public function get_id();

	/** @return string Czytelna nazwa. */
	public function get_label();

	/**
	 * Sprawdza wynik agenta.
	 *
	 * @param MP_OB_Result  $agent_result Wynik agenta.
	 * @param MP_OB_Context $context      Kontekst pipeline.
	 * @return MP_OB_Result Pozytywny = akceptuje, negatywny = STOP.
	 */
	public function review( MP_OB_Result $agent_result, MP_OB_Context $context );
}
