<?php
/**
 * Zaślepki (stuby) Agenta i Krytyka — rusztowanie kroku 2.
 *
 * Na tym etapie agenci NIE mają jeszcze logiki (przepuszczają dane dalej),
 * a krytycy zawsze akceptują. W kroku 3 podmienimy je na konkretne klasy
 * z realnymi zadaniami i weryfikacją.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zaślepka Agenta.
 */
class MP_Stub_Agent implements MP_Agent_Interface {

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
	 * TODO(krok 3): tu trafi realna logika agenta.
	 *
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		return MP_Result::ok( array( '_stub_agent' => $this->id ) );
	}
}

/**
 * Zaślepka Krytyka.
 */
class MP_Stub_Critic implements MP_Critic_Interface {

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
	 * TODO(krok 3): realna weryfikacja wyniku agenta.
	 *
	 * @param MP_Result  $agent_result Wynik agenta.
	 * @param MP_Context $context      Kontekst.
	 * @return MP_Result
	 */
	public function review( MP_Result $agent_result, MP_Context $context ) {
		return MP_Result::ok( $agent_result->get_data() );
	}
}
