<?php
/**
 * Bramka Jakości (QA) — uruchamiana PO każdym dziale.
 *
 * Zasada z diagramu LP.3: bramka to DOKŁADNIE 1 QA Agent + 1 QA Krytyk,
 * nigdy więcej. QA Agent ocenia wynik całego działu, QA Krytyk akceptuje
 * albo odrzuca (STOP).
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bramka kontroli jakości działu.
 */
class MP_SW_Quality_Gate {

	/** @var MP_SW_Agent_Interface Jedyny QA Agent. */
	protected $qa_agent;

	/** @var MP_SW_Critic_Interface Jedyny QA Krytyk. */
	protected $qa_critic;

	/**
	 * @param MP_SW_Agent_Interface  $qa_agent  QA Agent (dokładnie 1).
	 * @param MP_SW_Critic_Interface $qa_critic QA Krytyk (dokładnie 1).
	 */
	public function __construct( MP_SW_Agent_Interface $qa_agent, MP_SW_Critic_Interface $qa_critic ) {
		$this->qa_agent  = $qa_agent;
		$this->qa_critic = $qa_critic;
	}

	/**
	 * Ocena jakości działu: QA Agent -> QA Krytyk.
	 *
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function evaluate( MP_SW_Context $context ) {
		$agent_result = $this->qa_agent->run( $context );

		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		return $this->qa_critic->review( $agent_result, $context );
	}

	/** @return MP_SW_Agent_Interface */
	public function get_qa_agent() {
		return $this->qa_agent;
	}

	/** @return MP_SW_Critic_Interface */
	public function get_qa_critic() {
		return $this->qa_critic;
	}
}
