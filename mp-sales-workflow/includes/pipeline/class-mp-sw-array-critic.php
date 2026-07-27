<?php
/**
 * Krytyk sprawdzający, że wskazany klucz jest niepustą tablicą.
 *
 * Diagram LP.3 wymaga tego m.in. w Dziale 2 (snapshot ma pięć sekcji, brak
 * sekcji = FAIL_FATAL) i w Dziale 7 (lista adresatów nie może być pusta).
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Krytyk niepustej kolekcji.
 */
class MP_SW_Array_Critic extends MP_SW_Abstract_Critic {

	/** @var string Klucz kolekcji w danych agenta. */
	protected $key;

	/** @var string[] Klucze wymagane wewnątrz kolekcji (opcjonalnie). */
	protected $required_keys;

	/** @var string Kod błędu. */
	protected $error_code;

	/**
	 * @param string   $id            Identyfikator krytyka.
	 * @param string   $label         Nazwa.
	 * @param string   $key           Klucz kolekcji.
	 * @param string[] $required_keys Wymagane klucze wewnątrz kolekcji.
	 * @param string   $criterion     Kryterium akceptacji (z diagramu).
	 * @param string   $error_code    Kod błędu.
	 */
	public function __construct( $id, $label, $key, array $required_keys = array(), $criterion = '', $error_code = 'empty_collection' ) {
		parent::__construct( $id, $label, $criterion );
		$this->key           = $key;
		$this->required_keys = $required_keys;
		$this->error_code    = $error_code;
	}

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( ! isset( $data[ $this->key ] ) || ! is_array( $data[ $this->key ] ) || empty( $data[ $this->key ] ) ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: nazwa brakującej kolekcji. */
					__( 'Pusta lub brakująca kolekcja: %s', 'mp-sales-workflow' ),
					$this->key
				),
				array( 'errors' => array( $this->key ) ),
				$this->error_code
			);
		}

		$missing = array();

		foreach ( $this->required_keys as $required ) {
			if ( ! array_key_exists( $required, $data[ $this->key ] ) ) {
				$missing[] = $required;
			}
		}

		if ( ! empty( $missing ) ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: nazwa kolekcji, 2: lista brakujących sekcji. */
					__( 'Kolekcja %1$s nie ma sekcji: %2$s', 'mp-sales-workflow' ),
					$this->key,
					implode( ', ', $missing )
				),
				array( 'errors' => $missing ),
				$this->error_code
			);
		}

		return MP_SW_Result::ok( $data );
	}
}
