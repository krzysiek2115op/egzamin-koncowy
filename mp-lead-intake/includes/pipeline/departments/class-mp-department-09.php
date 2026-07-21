<?php
/**
 * Dział 9 — Rozpoczęcie procesu.
 *
 * 9.1 inicjacja procesu (process_id), 9.2 etap początkowy (stage=lead_intake),
 * 9.3 zapis stanu procesu do wp_mp_activity_log (action=process_started).
 *
 * Źródła (oficjalne) — Golden Rule #2. Dokumentacja czytana przez agentów:
 *  - docs/dzial-09/zrodla-wordpress.md (wpdb::insert, current_time)
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 9.1 — inicjuje proces.
 */
class MP_D9_Agent_Init extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '9.1', 'Inicjuje proces', 'Nadanie identyfikatora procesu (na bazie lead_id)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$process_id = (int) $context->get( 'lead_id', 0 );
		return MP_Result::ok( array( 'process_id' => $process_id ) );
	}
}

/**
 * Agent 9.2 — ustala etap początkowy.
 */
class MP_D9_Agent_Stage extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '9.2', 'Ustala etap początkowy', 'Stage = lead_intake + znacznik startu' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		unset( $context );
		return MP_Result::ok(
			array(
				'stage'      => 'lead_intake',
				'started_at' => current_time( 'mysql' ),
			)
		);
	}
}

/**
 * Agent 9.3 — zapisuje stan procesu do logu.
 */
class MP_D9_Agent_Persist extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '9.3', 'Zapisuje stan procesu', 'Wpis process_started do wp_mp_activity_log' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$lead_id = (int) $context->get( 'lead_id', 0 );

		$log_id = MP_Lead_Intake_DB::insert_activity(
			array(
				'lead_id'     => $lead_id ? $lead_id : null,
				'action'      => 'process_started',
				'description' => 'Rozpoczęto proces obsługi leada',
				'user_id'     => get_current_user_id() ? get_current_user_id() : null,
				'ip_address'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
				'meta_json'   => wp_json_encode(
					array(
						'process_id' => $context->get( 'process_id' ),
						'stage'      => $context->get( 'stage' ),
						'started_at' => $context->get( 'started_at' ),
					)
				),
			)
		);

		if ( ! $log_id ) {
			return MP_Result::fail( 'Nie udało się zapisać startu procesu', array(), 'process_log_failed' );
		}

		return MP_Result::ok( array( 'process_log_id' => $log_id, 'process_started' => true ) );
	}
}

/**
 * QA Agent 9 — proces musi być rozpoczęty (stage + process_started).
 */
class MP_D9_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA9', 'QA Agent 9 — kontrola procesu', 'Wymaga stage + process_started' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		if ( '' === trim( (string) $context->get( 'stage', '' ) ) || ! $context->get( 'process_started' ) ) {
			return MP_Result::fail( 'Proces nie został rozpoczęty', array(), 'process_not_started' );
		}
		return MP_Result::ok( array( 'd9_process_ready' => true ) );
	}
}

/**
 * Budowniczy działu 9.
 */
class MP_Department_09 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D9_Agent_Init(),
				'critic' => new MP_Flag_Critic( 'K9.1', 'Krytyk 9.1 — weryfikuje inicjację', 'process_id' ),
			),
			array(
				'agent'  => new MP_D9_Agent_Stage(),
				'critic' => new MP_Field_Critic( 'K9.2', 'Krytyk 9.2 — weryfikuje etap', 'stage' ),
			),
			array(
				'agent'  => new MP_D9_Agent_Persist(),
				'critic' => new MP_Flag_Critic( 'K9.3', 'Krytyk 9.3 — weryfikuje zapis stanu', 'process_started' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D9_QA_Agent(),
			new MP_Accept_Critic( 'QAK9', 'QA Krytyk 9 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			9,
			'start-process',
			'Rozpoczęcie procesu',
			'Inicjacja procesu obsługi leada (etap początkowy) i zapis do BD-3.',
			$pairs,
			$gate
		);
	}
}
