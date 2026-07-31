<?php
/**
 * Krytyk pola — sprawdza, że w wyniku agenta dany klucz jest niepusty.
 *
 * Uniwersalny dla agentów, które ustawiają pojedyncze pole tekstowe
 * (np. country, segment, client_category).
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Krytyk niepustego pola.
 */
class MP_Field_Critic extends MP_Abstract_Critic {

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
		if ( ! isset( $data[ $this->key ] ) || '' === trim( (string) $data[ $this->key ] ) ) {
			return MP_Result::fail( sprintf( 'Puste pole: %s', $this->key ), array(), 'empty_field' );
		}

		return MP_Result::ok( $data );
	}
}
