<?php
/**
 * Krytyk pola — sprawdza, że w wyniku agenta dany klucz jest niepusty.
 *
 * Uniwersalny dla agentów, które ustawiają pojedyncze pole (np. offer_number,
 * tax_mechanism, template lang).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Krytyk niepustego pola.
 */
class MP_OB_Field_Critic extends MP_OB_Abstract_Critic {

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
		if ( ! isset( $data[ $this->key ] ) || '' === trim( (string) $data[ $this->key ] ) ) {
			return MP_OB_Result::fail( sprintf( 'Puste pole: %s', $this->key ), array(), 'empty_field' );
		}

		return MP_OB_Result::ok( $data );
	}
}
