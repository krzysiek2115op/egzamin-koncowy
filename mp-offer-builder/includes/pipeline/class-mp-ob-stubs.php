<?php
/**
 * Zaślepki (stuby) Agenta i Krytyka — używane WYŁĄCZNIE przez harness testowy.
 *
 * Agent-zaślepka przepuszcza dane dalej, krytyk-zaślepka zawsze akceptuje.
 * Ścieżka produkcyjna używa realnych klas (MP_OB_Department_01..11 z agentami,
 * krytykami i bramkami QA); te stuby służą tylko do izolowanego testowania
 * mechaniki pipeline'u.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zaślepka Agenta.
 */
class MP_OB_Stub_Agent implements MP_OB_Agent_Interface {

	/** @var string */
	protected $id;

	/** @var string */
	protected $label;

	/** @var string Krótki opis zadania (do dokumentacji z kroku 3). */
	protected $purpose;

	/**
	 * @param string $id      Identyfikator.
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
	 * Zaślepka testowa: przepuszcza kontekst bez zmian (brak logiki produkcyjnej).
	 *
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		unset( $context );
		return MP_OB_Result::ok( array( '_stub_agent' => $this->id ) );
	}
}

/**
 * Zaślepka Krytyka.
 */
class MP_OB_Stub_Critic implements MP_OB_Critic_Interface {

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
	 * Zaślepka testowa: akceptuje wynik agenta bez weryfikacji.
	 *
	 * @param MP_OB_Result  $agent_result Wynik agenta.
	 * @param MP_OB_Context $context      Kontekst.
	 * @return MP_OB_Result
	 */
	public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
		unset( $context );
		return MP_OB_Result::ok( $agent_result->get_data() );
	}
}
