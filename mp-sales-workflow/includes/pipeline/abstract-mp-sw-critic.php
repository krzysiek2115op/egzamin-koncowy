<?php
/**
 * Bazowa klasa Krytyka — wspólna dla krytyków wszystkich działów.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstrakcyjny krytyk.
 */
abstract class MP_SW_Abstract_Critic implements MP_SW_Critic_Interface {

	/** @var string */
	protected $id;

	/** @var string */
	protected $label;

	/** @var string Kryterium, które krytyk egzekwuje (z diagramu LP.3). */
	protected $criterion;

	/**
	 * @param string $id        Identyfikator.
	 * @param string $label     Nazwa.
	 * @param string $criterion Kryterium akceptacji.
	 */
	public function __construct( $id, $label, $criterion = '' ) {
		$this->id        = $id;
		$this->label     = $label;
		$this->criterion = $criterion;
	}

	/** @return string */
	public function get_id() {
		return $this->id;
	}

	/** @return string */
	public function get_label() {
		return $this->label;
	}

	/** @return string */
	public function get_criterion() {
		return $this->criterion;
	}

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	abstract public function review( MP_SW_Result $agent_result, MP_SW_Context $context );
}
