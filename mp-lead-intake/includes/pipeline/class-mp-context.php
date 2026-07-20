<?php
/**
 * Kontekst pipeline — "paczka" danych płynąca jednokierunkowo przez działy.
 *
 * Zgodnie z zasadami: dane między działami przekazywane są w formacie JSON.
 * Ten obiekt gromadzi dane, śledzi bieżący dział i zbiera błędy; potrafi
 * serializować się do JSON (to_json) i odtwarzać z JSON (from_json).
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kontekst przepływu danych.
 */
class MP_Context {

	/** @var array Zgromadzone dane (klucz => wartość). */
	protected $data = array();

	/** @var int Numer aktualnie przetwarzanego działu (1..11). */
	protected $current_department = 0;

	/** @var array Zebrane błędy. */
	protected $errors = array();

	/**
	 * @param array $initial Dane wejściowe (np. surowe pola formularza).
	 */
	public function __construct( array $initial = array() ) {
		$this->data = $initial;
	}

	/**
	 * Ustawia wartość.
	 *
	 * @param string $key   Klucz.
	 * @param mixed  $value Wartość.
	 * @return MP_Context
	 */
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
		return $this;
	}

	/**
	 * Pobiera wartość.
	 *
	 * @param string $key     Klucz.
	 * @param mixed  $default Domyślna wartość.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	/**
	 * Dokłada zestaw danych (wynik działu).
	 *
	 * @param array $values Dane.
	 * @return MP_Context
	 */
	public function merge( array $values ) {
		$this->data = array_merge( $this->data, $values );
		return $this;
	}

	/** @return array Wszystkie dane. */
	public function all() {
		return $this->data;
	}

	/**
	 * @param int $number Numer działu.
	 * @return MP_Context
	 */
	public function set_current_department( $number ) {
		$this->current_department = (int) $number;
		return $this;
	}

	/** @return int */
	public function get_current_department() {
		return $this->current_department;
	}

	/**
	 * Dodaje błąd do kontekstu.
	 *
	 * @param string $code    Kod.
	 * @param string $message Opis.
	 * @return MP_Context
	 */
	public function add_error( $code, $message ) {
		$this->errors[] = array(
			'code'       => $code,
			'message'    => $message,
			'department' => $this->current_department,
		);
		return $this;
	}

	/** @return array */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Serializacja do JSON (dane między działami).
	 *
	 * @return string
	 */
	public function to_json() {
		return wp_json_encode(
			array(
				'department' => $this->current_department,
				'data'       => $this->data,
				'errors'     => $this->errors,
			)
		);
	}

	/**
	 * Odtworzenie kontekstu z JSON.
	 *
	 * @param string $json JSON.
	 * @return MP_Context
	 */
	public static function from_json( $json ) {
		$arr = json_decode( $json, true );
		$ctx = new self( isset( $arr['data'] ) && is_array( $arr['data'] ) ? $arr['data'] : array() );
		if ( isset( $arr['department'] ) ) {
			$ctx->set_current_department( $arr['department'] );
		}
		return $ctx;
	}
}
