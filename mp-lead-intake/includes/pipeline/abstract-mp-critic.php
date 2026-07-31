<?php
/**
 * Bazowa klasa Krytyka — wspólna dla konkretnych krytyków wszystkich działów.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstrakcyjny krytyk.
 */
abstract class MP_Abstract_Critic implements MP_Critic_Interface {

	/** @var string */
	protected $id;

	/** @var string */
	protected $label;

	/**
	 * @param string $id    Identyfikator.
	 * @param string $label Nazwa.
	 */
	public function __construct( $id, $label ) {
		$this->id    = $id;
		$this->label = $label;
	}

	/** @return string */
	public function get_id() {
		return $this->id;
	}

	/** @return string */
	public function get_label() {
		return $this->label;
	}

	/**
	 * @param MP_Result  $agent_result Wynik agenta.
	 * @param MP_Context $context      Kontekst.
	 * @return MP_Result
	 */
	abstract public function review( MP_Result $agent_result, MP_Context $context );
}
