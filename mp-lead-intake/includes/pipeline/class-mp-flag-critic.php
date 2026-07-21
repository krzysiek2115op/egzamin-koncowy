<?php
/**
 * Krytyk flagi — uniwersalny krytyk sprawdzający boolowską flagę w wyniku agenta.
 *
 * Wiele agentów zwraca flagę typu `required_ok`, `form_valid` itp. Ten krytyk
 * akceptuje wynik, gdy flaga jest prawdziwa; w przeciwnym razie odrzuca (STOP),
 * przekazując ewentualne błędy z klucza `errors`.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Krytyk weryfikujący pojedynczą flagę logiczną.
 */
class MP_Flag_Critic extends MP_Abstract_Critic {

	/** @var string Klucz flagi w danych agenta. */
	protected $flag_key;

	/** @var string Klucz z listą błędów w danych agenta. */
	protected $errors_key;

	/**
	 * @param string $id         Identyfikator.
	 * @param string $label      Nazwa.
	 * @param string $flag_key   Klucz flagi (np. 'form_valid').
	 * @param string $errors_key Klucz z błędami (domyślnie 'errors').
	 */
	public function __construct( $id, $label, $flag_key, $errors_key = 'errors' ) {
		parent::__construct( $id, $label );
		$this->flag_key   = $flag_key;
		$this->errors_key = $errors_key;
	}

	/**
	 * @param MP_Result  $agent_result Wynik agenta.
	 * @param MP_Context $context      Kontekst.
	 * @return MP_Result
	 */
	public function review( MP_Result $agent_result, MP_Context $context ) {
		unset( $context );

		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$data = $agent_result->get_data();

		if ( empty( $data[ $this->flag_key ] ) ) {
			$errors = isset( $data[ $this->errors_key ] ) ? $data[ $this->errors_key ] : array();
			return MP_Result::fail(
				sprintf( 'Warunek niespełniony: %s', $this->flag_key ),
				array( 'errors' => $errors ),
				'flag_failed'
			);
		}

		return MP_Result::ok( $data );
	}
}
