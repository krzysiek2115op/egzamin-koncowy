<?php
/**
 * Bazowa klasa Agenta — wspólna dla agentów wszystkich działów.
 *
 * Trzyma identyfikator, nazwę i opis zadania; konkretny agent implementuje
 * tylko `run()`. Opis zadania (`purpose`) siedzi przy agencie w kodzie —
 * Golden Rule #2 mówi, że dokumentacja opisuje ŹRÓDŁA, nie zadania.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstrakcyjny agent.
 */
abstract class MP_SW_Abstract_Agent implements MP_SW_Agent_Interface {

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
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	abstract public function run( MP_SW_Context $context );
}
