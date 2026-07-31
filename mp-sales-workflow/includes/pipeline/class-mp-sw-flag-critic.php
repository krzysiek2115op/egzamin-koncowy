<?php
/**
 * Krytyk sprawdzający, że agent ustawił wymaganą flagę logiczną.
 *
 * Używany tam, gdzie diagram LP.3 stawia warunek zerojedynkowy, np. Dział 7:
 * "wysyłka w żądaniu = FAIL" — flaga `queued_only` musi być prawdziwa.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Krytyk flagi logicznej.
 */
class MP_SW_Flag_Critic extends MP_SW_Abstract_Critic {

	/** @var string Klucz flagi w danych agenta. */
	protected $flag;

	/** @var string Komunikat błędu, gdy flaga nie jest ustawiona. */
	protected $message;

	/** @var string Kod błędu. */
	protected $error_code;

	/**
	 * @param string $id         Identyfikator krytyka.
	 * @param string $label      Nazwa.
	 * @param string $flag       Klucz flagi.
	 * @param string $message    Komunikat błędu.
	 * @param string $criterion  Kryterium akceptacji (z diagramu).
	 * @param string $error_code Kod błędu.
	 */
	public function __construct( $id, $label, $flag, $message, $criterion = '', $error_code = 'flag_not_set' ) {
		parent::__construct( $id, $label, $criterion );
		$this->flag       = $flag;
		$this->message    = $message;
		$this->error_code = $error_code;
	}

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( empty( $data[ $this->flag ] ) ) {
			return MP_SW_Result::fail(
				$this->message,
				array( 'errors' => array( $this->flag ) ),
				$this->error_code
			);
		}

		return MP_SW_Result::ok( $data );
	}
}
