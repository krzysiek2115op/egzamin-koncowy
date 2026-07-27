<?php
/**
 * Kontekst pipeline — koperta danych płynąca jednokierunkowo przez działy.
 *
 * Diagram LP.3: dane między działami przekazywane są w JSON. Ten obiekt
 * gromadzi dane, śledzi bieżący dział, zbiera błędy i potrafi zrobić
 * round-trip do JSON-a.
 *
 * Poza wzorzec z wtyczki 2 wychodzą tu LICZNIKI dostępu do bazy. Diagram
 * stawia je jako wprost mierzalne kryteria bramek jakości: Dział 2 —
 * "jeden-odczyt: db_reads = 1", Dział 8 — "db_writes = 1", a tryb
 * `dashboard.view` ma przejść z `db_writes = 0`. Bez licznika w kopercie
 * bramka nie miałaby czego sprawdzić i kryterium zostałoby deklaracją.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kontekst przepływu danych.
 */
class MP_SW_Context {

	/** @var array Zgromadzone dane (klucz => wartość). */
	protected $data = array();

	/** @var int Numer aktualnie przetwarzanego działu (1..9). */
	protected $current_department = 0;

	/** @var array Zebrane błędy. */
	protected $errors = array();

	/** @var int Liczba wykonanych strzałów odczytu do bazy. */
	protected $db_reads = 0;

	/** @var int Liczba wykonanych zapisów (transakcji) do bazy. */
	protected $db_writes = 0;

	/**
	 * @param array $initial Dane wejściowe (zdarzenie procesu z 1 AJAX / crona).
	 */
	public function __construct( array $initial = array() ) {
		$this->data = $initial;
	}

	/**
	 * Ustawia wartość.
	 *
	 * @param string $key   Klucz.
	 * @param mixed  $value Wartość.
	 * @return MP_SW_Context
	 */
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
		return $this;
	}

	/**
	 * Pobiera wartość.
	 *
	 * @param string $key      Klucz.
	 * @param mixed  $fallback Domyślna wartość.
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $fallback;
	}

	/**
	 * Dokłada zestaw danych (wynik działu).
	 *
	 * @param array $values Dane.
	 * @return MP_SW_Context
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
	 * @return MP_SW_Context
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
	 * @return MP_SW_Context
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
	 * Rejestruje wykonany strzał odczytu (Dział 2).
	 *
	 * @return MP_SW_Context
	 */
	public function count_db_read() {
		++$this->db_reads;
		return $this;
	}

	/**
	 * Rejestruje wykonany zapis (Dział 8 — jedna transakcja).
	 *
	 * @return MP_SW_Context
	 */
	public function count_db_write() {
		++$this->db_writes;
		return $this;
	}

	/** @return int */
	public function get_db_reads() {
		return $this->db_reads;
	}

	/** @return int */
	public function get_db_writes() {
		return $this->db_writes;
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
				'db'         => array(
					'reads'  => $this->db_reads,
					'writes' => $this->db_writes,
				),
			)
		);
	}

	/**
	 * Odtworzenie kontekstu z JSON.
	 *
	 * @param string $json JSON.
	 * @return MP_SW_Context
	 */
	public static function from_json( $json ) {
		$arr = json_decode( (string) $json, true );

		if ( ! is_array( $arr ) ) {
			// Uszkodzony/niepełny JSON — pusty, spójny kontekst zamiast błędu.
			$arr = array();
		}

		$ctx = new self( isset( $arr['data'] ) && is_array( $arr['data'] ) ? $arr['data'] : array() );

		if ( isset( $arr['department'] ) ) {
			$ctx->set_current_department( $arr['department'] );
		}

		// Round-trip musi odtworzyć także błędy i liczniki — inaczej bramka
		// jakości po deserializacji zobaczyłaby zerowe db_reads i przepuściła
		// przebieg, który w rzeczywistości czytał bazę wielokrotnie.
		if ( isset( $arr['errors'] ) && is_array( $arr['errors'] ) ) {
			$ctx->errors = $arr['errors'];
		}

		if ( isset( $arr['db']['reads'] ) ) {
			$ctx->db_reads = (int) $arr['db']['reads'];
		}

		if ( isset( $arr['db']['writes'] ) ) {
			$ctx->db_writes = (int) $arr['db']['writes'];
		}

		return $ctx;
	}
}
