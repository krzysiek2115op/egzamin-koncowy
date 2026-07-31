<?php
/**
 * Krytyk akceptujący — uniwersalny QA Krytyk bramki jakości.
 *
 * Przyjmuje wynik, gdy poprzedzający go (QA) Agent zwrócił sukces; w przeciwnym
 * razie odrzuca (przekazuje błąd dalej -> STOP). Używany w bramkach jakości,
 * gdzie cała ocena merytoryczna dzieje się w QA Agencie.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * QA Krytyk akceptujący/odrzucający.
 */
class MP_Accept_Critic extends MP_Abstract_Critic {

	/**
	 * @param MP_Result  $agent_result Wynik (QA) agenta.
	 * @param MP_Context $context      Kontekst.
	 * @return MP_Result
	 */
	public function review( MP_Result $agent_result, MP_Context $context ) {
		unset( $context );
		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}
		return MP_Result::ok( $agent_result->get_data() );
	}
}
