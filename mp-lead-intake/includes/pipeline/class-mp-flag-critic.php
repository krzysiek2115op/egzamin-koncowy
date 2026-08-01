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

	/** @var string Kod odmowy oddawany na zewnątrz. */
	protected $code;

	/**
	 * @param string $id         Identyfikator.
	 * @param string $label      Nazwa.
	 * @param string $flag_key   Klucz flagi (np. 'form_valid').
	 * @param string $errors_key Klucz z błędami (domyślnie 'errors').
	 * @param string $code       Kod odmowy; domyślnie zbiorczy `flag_failed`.
	 */
	public function __construct( $id, $label, $flag_key, $errors_key = 'errors', $code = 'flag_failed' ) {
		$this->code = (string) $code;
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

			/*
			 * KOD ODMOWY MÓWI, CO SIĘ STAŁO — I DLATEGO JEST PARAMETREM.
			 *
			 * Do 1.3.6 każdy krytyk flagowy oddawał ten sam `flag_failed`. Warstwa
			 * AJAX nie miała więc jak odróżnić „ta firma już u nas jest" od „zła
			 * suma kontrolna NIP" od „nie zgodziłeś się na przetwarzanie danych",
			 * i każdemu nadawcy odpowiadała tym samym „Sprawdź dane i spróbuj
			 * ponownie". Powtórne zgłoszenie tej samej firmy to odmowa POPRAWNA
			 * (kryterium odbioru żąda braku duplikatów), a nadawca poprawiał dane,
			 * które są dobre.
			 *
			 * Kod nadaje dział, bo tylko on wie, o co pytał. Domyślka zostaje
			 * zbiorcza: krytyk bez własnego kodu pilnuje niezmiennika wewnętrznego,
			 * o którym nadawcy formularza nie mamy nic do powiedzenia.
			 */
			return MP_Result::fail(
				sprintf( 'Warunek niespełniony: %s', $this->flag_key ),
				array( 'errors' => $errors ),
				$this->code
			);
		}

		return MP_Result::ok( $data );
	}
}
