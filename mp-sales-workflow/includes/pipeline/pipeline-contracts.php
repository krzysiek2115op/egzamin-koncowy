<?php
/**
 * Kontrakty pipeline: Agent i Krytyk.
 *
 * Diagram LP.3: każdy Agent WYKONUJE zadanie, a przypisany mu Krytyk (1:1)
 * WERYFIKUJE jego wynik. Zadanie agenta/krytyka opisane jest przy NIM w kodzie
 * (`get_label()` / `get_purpose()`), nie w dokumentacji — Golden Rule #2.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent — wykonuje pojedyncze zadanie w dziale.
 */
interface MP_SW_Agent_Interface {

	/** @return string Identyfikator, np. "1.1". */
	public function get_id();

	/** @return string Czytelna nazwa/rola. */
	public function get_label();

	/**
	 * Wykonuje zadanie agenta.
	 *
	 * @param MP_SW_Context $context Kontekst pipeline.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context );
}

/**
 * Krytyk — weryfikuje wynik przypisanego Agenta.
 */
interface MP_SW_Critic_Interface {

	/** @return string Identyfikator krytyka. */
	public function get_id();

	/** @return string Czytelna nazwa. */
	public function get_label();

	/**
	 * Sprawdza wynik agenta.
	 *
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst pipeline.
	 * @return MP_SW_Result Pozytywny = akceptuje, negatywny = STOP.
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context );
}
