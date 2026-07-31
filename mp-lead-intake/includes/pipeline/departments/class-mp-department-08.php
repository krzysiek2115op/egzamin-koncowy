<?php
/**
 * Dział 8 — Zapis historii aktywności.
 *
 * 8.1 przygotowanie wpisu, 8.2 dodanie meta (user_id, IP, meta_json),
 * 8.3 zapis do wp_mp_activity_log (BD-3).
 *
 * Źródła (oficjalne) — Golden Rule #2. Dokumentacja czytana przez agentów:
 *  - docs/dzial-08/wordpress-wpdb-insert.md
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 8.1 — przygotowuje bazowy wpis logu.
 */
class MP_D8_Agent_Prepare extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '8.1', 'Przygotowuje wpis w logu', 'Budowa rekordu zdarzenia (lead_id, action, description)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$lead_id = (int) $context->get( 'lead_id', 0 );

		return MP_Result::ok(
			array(
				'activity_data' => array(
					'lead_id'     => $lead_id ? $lead_id : null,
					'action'      => 'lead_created',
					'description' => sprintf( 'Utworzono leada #%d', $lead_id ),
				),
			)
		);
	}
}

/**
 * Agent 8.2 — dodaje meta informacje (user_id, IP, meta_json).
 */
class MP_D8_Agent_Meta extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '8.2', 'Dodaje meta informacje', 'user_id, IP, meta_json (score/segment/status)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$data = (array) $context->get( 'activity_data', array() );

		$data['user_id']    = get_current_user_id() ? get_current_user_id() : null;
		$data['ip_address'] = isset( $_SERVER['REMOTE_ADDR'] ) ? MP_Lead_Intake_DB::anonymize_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : null;
		$data['meta_json']  = wp_json_encode(
			array(
				'score'          => $context->get( 'score' ),
				'salesman_id'    => $context->get( 'salesman_id' ),
				'segment'        => $context->get( 'segment' ),
				'country'        => $context->get( 'country' ),
				'vat_valid'      => $context->get( 'vat_valid' ),
				'company_status' => $context->get( 'company_status' ),
			)
		);

		return MP_Result::ok( array( 'activity_data' => $data ) );
	}
}

/**
 * Agent 8.3 — zapisuje wpis do wp_mp_activity_log.
 */
class MP_D8_Agent_Write extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '8.3', 'Zapisuje log w BD-3', 'INSERT do wp_mp_activity_log (wpdb::insert)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$data = (array) $context->get( 'activity_data', array() );
		if ( empty( $data ) ) {
			return MP_Result::fail( 'Brak danych logu', array(), 'no_activity' );
		}

		$log_id = MP_Lead_Intake_DB::insert_activity( $data );
		if ( ! $log_id ) {
			return MP_Result::fail( 'Nie udało się zapisać logu', array(), 'log_insert_failed' );
		}

		return MP_Result::ok(
			array(
				'log_id'     => $log_id,
				'action'     => 'lead_created',
				'log_status' => 'success',
			)
		);
	}
}

/**
 * QA Agent 8 — log musi być zapisany (log_id > 0).
 */
class MP_D8_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA8', 'QA Agent 8 — kontrola logu', 'Wymaga log_id > 0' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		if ( (int) $context->get( 'log_id', 0 ) <= 0 ) {
			return MP_Result::fail( 'Wpis logu nie został zapisany', array(), 'log_not_written' );
		}
		return MP_Result::ok( array( 'd8_logged' => true ) );
	}
}

/**
 * Budowniczy działu 8.
 */
class MP_Department_08 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D8_Agent_Prepare(),
				'critic' => new MP_Accept_Critic( 'K8.1', 'Krytyk 8.1 — przyjmuje wpis' ),
			),
			array(
				'agent'  => new MP_D8_Agent_Meta(),
				'critic' => new MP_Accept_Critic( 'K8.2', 'Krytyk 8.2 — przyjmuje meta' ),
			),
			array(
				'agent'  => new MP_D8_Agent_Write(),
				'critic' => new MP_Flag_Critic( 'K8.3', 'Krytyk 8.3 — weryfikuje zapis', 'log_id' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D8_QA_Agent(),
			new MP_Accept_Critic( 'QAK8', 'QA Krytyk 8 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			8,
			'activity-log',
			'Zapis historii aktywności',
			'Zapisanie operacji (utworzenie leada) w wp_mp_activity_log.',
			$pairs,
			$gate
		);
	}
}
