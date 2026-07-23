<?php
/**
 * Krytyk parzystości — sprawdza, że tablica pod danym kluczem w wyniku agenta
 * ma DOKŁADNIE tyle wpisów, ile jest pozycji w żądaniu (context->get('items')).
 *
 * Używany tam, gdzie KAŻDA pozycja musi zostać rozwiązana (produkt, cena, linia
 * wyceny) — nigdy "przynajmniej jedna", żeby brak jednej pozycji nie przeszedł
 * po cichu.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Krytyk parzystości sekcji względem liczby pozycji.
 */
class MP_OB_Parity_Critic extends MP_OB_Abstract_Critic {

	/** @var string */
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
		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$data  = $agent_result->get_data();
		$items = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();

		if ( ! isset( $data[ $this->key ] ) || ! is_array( $data[ $this->key ] ) || count( $data[ $this->key ] ) !== count( $items ) ) {
			return MP_OB_Result::fail( sprintf( 'Sekcja %s nie odpowiada liczbie pozycji.', $this->key ), array(), 'invalid_structure' );
		}

		return MP_OB_Result::ok( $data );
	}
}
