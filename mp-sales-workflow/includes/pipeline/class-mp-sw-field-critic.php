<?php
/**
 * Krytyk sprawdzający obecność i niepustość pól w wyniku agenta.
 *
 * Najczęstszy wzorzec kontroli w diagramie LP.3 ("brak któregokolwiek = 422
 * z listą pól"): krytyk zbiera KOMPLET brakujących pól i zwraca je naraz,
 * zamiast przerywać na pierwszym — dzięki temu wywołujący dostaje pełną listę
 * poprawek w jednej odpowiedzi.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Krytyk pól wymaganych.
 */
class MP_SW_Field_Critic extends MP_SW_Abstract_Critic {

	/** @var string[] Klucze wymagane w danych agenta. */
	protected $required;

	/** @var string Kod błędu zwracany przy braku pól. */
	protected $error_code;

	/**
	 * @param string   $id         Identyfikator krytyka.
	 * @param string   $label      Nazwa.
	 * @param string[] $required   Wymagane klucze.
	 * @param string   $criterion  Kryterium akceptacji (z diagramu).
	 * @param string   $error_code Kod błędu.
	 */
	public function __construct( $id, $label, array $required, $criterion = '', $error_code = 'missing_fields' ) {
		parent::__construct( $id, $label, $criterion );
		$this->required   = $required;
		$this->error_code = $error_code;
	}

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data    = $agent_result->get_data();
		$missing = array();

		foreach ( $this->required as $key ) {
			if ( ! array_key_exists( $key, $data ) || '' === $data[ $key ] || null === $data[ $key ] ) {
				$missing[] = $key;
			}
		}

		if ( ! empty( $missing ) ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: lista brakujących pól. */
					__( 'Brak wymaganych pól: %s', 'mp-sales-workflow' ),
					implode( ', ', $missing )
				),
				array( 'errors' => $missing ),
				$this->error_code
			);
		}

		return MP_SW_Result::ok( $data );
	}
}
