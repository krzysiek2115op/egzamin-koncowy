<?php
/**
 * Dział 10 — Zwrócenie wyniku do pluginu.
 *
 * 10.1 budowa odpowiedzi, 10.2 podsumowanie/status, 10.3 finalizacja payloadu (JSON).
 *
 * Źródła (oficjalne) — Golden Rule #2. Dokumentacja czytana przez agentów:
 *  - docs/dzial-10/wordpress-wp_json_encode.md
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 10.1 — buduje odpowiedź.
 */
class MP_D10_Agent_Build extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '10.1', 'Buduje odpowiedź', 'Złożenie wyniku: success + lead_id' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$lead_id = (int) $context->get( 'lead_id', 0 );
		return MP_Result::ok(
			array(
				'response' => array(
					'success' => ( $lead_id > 0 ),
					'lead_id' => $lead_id,
				),
			)
		);
	}
}

/**
 * Agent 10.2 — dodaje podsumowanie i status.
 */
class MP_D10_Agent_Summary extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '10.2', 'Dodaje podsumowanie', 'Status + score + handlowiec + komunikat' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$r = (array) $context->get( 'response', array() );

		$r['status']      = (string) $context->get( 'status', 'new' );
		$r['score']       = (int) $context->get( 'score', 0 );
		$r['salesman_id'] = $context->get( 'salesman_id' );
		$r['message']     = ! empty( $r['success'] ) ? 'Lead utworzony pomyślnie' : 'Błąd tworzenia leada';

		return MP_Result::ok( array( 'response' => $r ) );
	}
}

/**
 * Agent 10.3 — finalizuje payload (JSON).
 */
class MP_D10_Agent_Finalize extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '10.3', 'Finalizuje payload', 'Ostateczny JSON zwrotny (wp_json_encode)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$r = (array) $context->get( 'response', array() );

		return MP_Result::ok(
			array(
				'response'      => $r,
				'response_json' => wp_json_encode( $r ),
				'result_ready'  => ! empty( $r['success'] ),
			)
		);
	}
}

/**
 * QA Agent 10 — wynik gotowy (success + lead_id).
 */
class MP_D10_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA10', 'QA Agent 10 — kontrola wyniku', 'Wymaga result_ready + lead_id > 0' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		if ( ! $context->get( 'result_ready' ) || (int) $context->get( 'lead_id', 0 ) <= 0 ) {
			return MP_Result::fail( 'Wynik niegotowy', array(), 'result_not_ready' );
		}
		return MP_Result::ok( array( 'd10_result_ready' => true ) );
	}
}

/**
 * Budowniczy działu 10.
 */
class MP_Department_10 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D10_Agent_Build(),
				'critic' => new MP_Accept_Critic( 'K10.1', 'Krytyk 10.1 — przyjmuje odpowiedź' ),
			),
			array(
				'agent'  => new MP_D10_Agent_Summary(),
				'critic' => new MP_Accept_Critic( 'K10.2', 'Krytyk 10.2 — przyjmuje podsumowanie' ),
			),
			array(
				'agent'  => new MP_D10_Agent_Finalize(),
				'critic' => new MP_Flag_Critic( 'K10.3', 'Krytyk 10.3 — weryfikuje payload', 'result_ready' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D10_QA_Agent(),
			new MP_Accept_Critic( 'QAK10', 'QA Krytyk 10 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			10,
			'return-result',
			'Zwrócenie wyniku do pluginu',
			'Zbudowanie i finalizacja odpowiedzi zwrotnej (success, lead_id, message).',
			$pairs,
			$gate
		);
	}
}
