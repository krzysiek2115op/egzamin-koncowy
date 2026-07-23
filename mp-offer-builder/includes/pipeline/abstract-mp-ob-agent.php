<?php
/**
 * Bazowa klasa Agenta — wspólna dla konkretnych agentów wszystkich działów.
 *
 * Trzyma identyfikator, nazwę i opis zadania; konkretny agent implementuje
 * tylko metodę run().
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstrakcyjny agent.
 */
abstract class MP_OB_Abstract_Agent implements MP_OB_Agent_Interface {

	/** @var string */
	protected $id;

	/** @var string */
	protected $label;

	/** @var string */
	protected $purpose;

	/**
	 * @param string $id      Identyfikator, np. "1.1".
	 * @param string $label   Nazwa/rola.
	 * @param string $purpose Opis zadania.
	 */
	public function __construct( $id, $label, $purpose = '' ) {
		$this->id      = $id;
		$this->label   = $label;
		$this->purpose = $purpose;
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
	public function get_purpose() {
		return $this->purpose;
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	abstract public function run( MP_OB_Context $context );
}
