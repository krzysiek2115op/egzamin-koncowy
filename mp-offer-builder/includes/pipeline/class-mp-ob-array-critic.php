<?php
/**
 * Krytyk niepustej tablicy — sprawdza, że w wyniku agenta dany klucz jest
 * niepustą tablicą (odpowiednik MP_OB_Field_Critic dla wartości tablicowych,
 * np. sekcji snapshotu BD-2/WooCommerce w Dziale 2).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Krytyk niepustej tablicy.
 */
class MP_OB_Array_Critic extends MP_OB_Abstract_Critic {

	/** @var string Sprawdzany klucz. */
	protected $key;

	/**
	 * @param string $id    Identyfikator.
	 * @param string $label Nazwa.
	 * @param string $key   Klucz do sprawdzenia.
	 */
	public function __construct( $id, $label, $key ) {
		parent::__construct( $id, $label );
		$this->key = $key;
	}

	/**
	 * @param MP_OB_Result  $agent_result Wynik agenta.
	 * @param MP_OB_Context $context      Kontekst.
	 * @return MP_OB_Result
	 */
	public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
		unset( $context );

		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$data = $agent_result->get_data();
		if ( empty( $data[ $this->key ] ) || ! is_array( $data[ $this->key ] ) ) {
			return MP_OB_Result::fail( sprintf( 'Pusta lub zła struktura sekcji: %s', $this->key ), array(), 'invalid_structure' );
		}

		return MP_OB_Result::ok( $data );
	}
}
