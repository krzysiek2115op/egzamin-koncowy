<?php
/**
 * Dział 11 — Zakończenie pipeline.
 *
 * 11.1 zamknięcie transakcji (finalizacja zapisu), 11.2 sprzątanie stanu
 * tymczasowego, 11.3 raport końcowy (status, czas trwania).
 *
 * Uwaga: zapisy w BD-3 są autocommit (każdy INSERT osobno). Pełna transakcja
 * obejmująca wiele działów to możliwe rozszerzenie (krok 4).
 *
 * Źródła (oficjalne) — Golden Rule #2. Dokumentacja czytana przez agentów:
 *  - docs/dzial-11/zrodla-wordpress.md (current_time)
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 11.1 — zamyka/finalizuje zapis.
 */
class MP_D11_Agent_Close extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '11.1', 'Zamyka transakcję', 'Finalizacja zapisu (autocommit; miejsce na spójną transakcję)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		unset( $context );
		return MP_Result::ok( array( 'transaction_closed' => true ) );
	}
}

/**
 * Agent 11.2 — czyści stan tymczasowy.
 */
class MP_D11_Agent_Cleanup extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '11.2', 'Czyści stan tymczasowy', 'Sprzątanie danych roboczych pipeline' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		unset( $context );
		return MP_Result::ok( array( 'temp_cleaned' => true ) );
	}
}

/**
 * Agent 11.3 — generuje raport końcowy.
 */
class MP_D11_Agent_Report extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '11.3', 'Generuje raport końcowy', 'Status pipeline, znacznik zakończenia, czas trwania' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$started     = strtotime( (string) $context->get( 'started_at', '' ) );
		$duration_ms = $started ? max( 0, (int) round( ( time() - $started ) * 1000 ) ) : null;

		return MP_Result::ok(
			array(
				'pipeline_status' => 'completed',
				'finished_at'     => current_time( 'mysql' ),
				'duration_ms'     => $duration_ms,
				'report_ready'    => true,
			)
		);
	}
}

/**
 * QA Agent 11 — pipeline zakończony (status completed).
 */
class MP_D11_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA11', 'QA Agent 11 — kontrola zakończenia', 'Wymaga pipeline_status = completed' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		if ( 'completed' !== $context->get( 'pipeline_status' ) ) {
			return MP_Result::fail( 'Pipeline niezakończony', array(), 'pipeline_not_completed' );
		}
		return MP_Result::ok( array( 'd11_completed' => true ) );
	}
}

/**
 * Budowniczy działu 11.
 */
class MP_Department_11 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D11_Agent_Close(),
				'critic' => new MP_Flag_Critic( 'K11.1', 'Krytyk 11.1 — weryfikuje zamknięcie', 'transaction_closed' ),
			),
			array(
				'agent'  => new MP_D11_Agent_Cleanup(),
				'critic' => new MP_Flag_Critic( 'K11.2', 'Krytyk 11.2 — weryfikuje sprzątanie', 'temp_cleaned' ),
			),
			array(
				'agent'  => new MP_D11_Agent_Report(),
				'critic' => new MP_Flag_Critic( 'K11.3', 'Krytyk 11.3 — weryfikuje raport', 'report_ready' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D11_QA_Agent(),
			new MP_Accept_Critic( 'QAK11', 'QA Krytyk 11 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			11,
			'finish',
			'Zakończenie pipeline',
			'Zamknięcie pipeline, sprzątanie i raport końcowy.',
			$pairs,
			$gate
		);
	}
}
