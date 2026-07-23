<?php
/**
 * Wynik operacji w pipeline (Agenta, Krytyka, Bramki, Działu).
 *
 * Prosty obiekt-wartość: sukces/porażka + dane + błędy + kod.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Obiekt wyniku (Result / Outcome).
 */
class MP_OB_Result {

	/** @var bool Czy operacja się powiodła. */
	protected $ok;

	/** @var array Dane wynikowe. */
	protected $data;

	/** @var array Lista błędów (gdy porażka). */
	protected $errors;

	/** @var string Kod wyniku, np. 'critic_failed', 'gate_failed'. */
	protected $code;

	/**
	 * @param bool   $ok     Czy operacja się powiodła.
	 * @param array  $data   Dane.
	 * @param array  $errors Błędy.
	 * @param string $code   Kod.
	 */
	public function __construct( $ok, array $data = array(), array $errors = array(), $code = '' ) {
		$this->ok     = (bool) $ok;
		$this->data   = $data;
		$this->errors = $errors;
		$this->code   = $code;
	}

	/**
	 * Wynik pozytywny.
	 *
	 * @param array $data Dane.
	 * @return MP_OB_Result
	 */
	public static function ok( array $data = array() ) {
		return new self( true, $data );
	}

	/**
	 * Wynik negatywny (STOP pipeline).
	 *
	 * @param string|array $error Błąd lub lista błędów.
	 * @param array        $data  Dane pomocnicze.
	 * @param string       $code  Kod błędu.
	 * @return MP_OB_Result
	 */
	public static function fail( $error, array $data = array(), $code = 'error' ) {
		$errors = is_array( $error ) ? $error : array( $error );
		return new self( false, $data, $errors, $code );
	}

	/** @return bool */
	public function is_ok() {
		return $this->ok;
	}

	/** @return array */
	public function get_data() {
		return $this->data;
	}

	/** @return array */
	public function get_errors() {
		return $this->errors;
	}

	/** @return string */
	public function get_code() {
		return $this->code;
	}
}
