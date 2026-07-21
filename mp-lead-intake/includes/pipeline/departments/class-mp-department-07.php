<?php
/**
 * Dział 7 — Utworzenie leada.
 *
 * 7.1 dedup (unikalność firmy po NIP), 7.2 przygotowanie danych (scoring +
 * przypisanie handlowca), 7.3 zapis leada do wp_mp_leads (BD-3).
 *
 * Źródła (oficjalne/oryginalne) — Golden Rule #2. Dokumentacja czytana przez agentów:
 *  - docs/dzial-07/wordpress-wpdb-insert.md
 *  - docs/dzial-07/scoring-przypisanie.md
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 7.1 — sprawdza unikalność firmy (dedup po NIP).
 */
class MP_D7_Agent_Dedup extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '7.1', 'Sprawdza unikalność firmy', 'Dedup po NIP w wp_mp_leads (brak duplikatów)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$nip      = (string) $context->get( 'nip', '' );
		$existing = ( '' !== $nip ) ? MP_Lead_Intake_DB::get_leads_by_nip( $nip ) : array();
		$dup      = ! empty( $existing );

		return MP_Result::ok(
			array(
				'is_duplicate'     => $dup,
				'unique_ok'        => ! $dup,
				'existing_lead_id' => $dup ? (int) $existing[0]['id'] : null,
			)
		);
	}
}

/**
 * Agent 7.2 — przygotowuje dane leada (scoring + przypisanie handlowca).
 */
class MP_D7_Agent_Prepare extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '7.2', 'Przygotowuje dane leada', 'Scoring + przypisanie handlowca + mapowanie pól' );
	}

	/**
	 * Deterministyczny scoring leada.
	 *
	 * @param MP_Context $context Kontekst.
	 * @return int
	 */
	protected function calculate_score( MP_Context $context ) {
		$score = 0;
		if ( true === $context->get( 'vat_valid' ) ) {
			$score += 30;
		}
		if ( 'Czynny' === $context->get( 'company_status' ) ) {
			$score += 20;
		}
		if ( '' !== trim( (string) $context->get( 'phone', '' ) ) ) {
			$score += 10;
		}
		if ( $context->get( 'consent_marketing' ) ) {
			$score += 10;
		}
		if ( in_array( $context->get( 'segment' ), array( 'Produkcja', 'IT', 'Budownictwo' ), true ) ) {
			$score += 15;
		}
		return $score;
	}

	/**
	 * Przypisanie handlowca (użytkownik z rolą mp_handlowiec).
	 *
	 * @param string $nip NIP (deterministyczny wybór).
	 * @return int|null
	 */
	protected function assign_salesman( $nip ) {
		$users = get_users( array( 'role' => 'mp_handlowiec', 'fields' => 'ID' ) );
		if ( empty( $users ) ) {
			return null;
		}
		$index = abs( crc32( (string) $nip ) ) % count( $users );
		return (int) $users[ $index ];
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$score    = $this->calculate_score( $context );
		$nip      = (string) $context->get( 'nip', '' );
		$salesman = $this->assign_salesman( $nip );

		$company = (string) $context->get( 'company_name', '' );
		$email   = (string) $context->get( 'email', '' );

		$lead_data = array(
			'company_name'         => $company,
			'nip'                  => $nip,
			'email'                => $email,
			'phone'                => ( '' !== trim( (string) $context->get( 'phone', '' ) ) ) ? (string) $context->get( 'phone' ) : null,
			'country'              => (string) $context->get( 'country', 'PL' ),
			'segment'              => ( '' !== trim( (string) $context->get( 'segment', '' ) ) ) ? (string) $context->get( 'segment' ) : null,
			'client_category'      => ( '' !== trim( (string) $context->get( 'client_category', '' ) ) ) ? (string) $context->get( 'client_category' ) : null,
			'salesman_id'          => $salesman,
			'score'                => $score,
			'status'               => 'new',
			'consent_marketing'    => (int) $context->get( 'consent_marketing', 0 ),
			'consent_rodo'         => (int) $context->get( 'consent_rodo', 0 ),
			'consent_marketing_at' => $context->get( 'consent_marketing_at' ),
			'consent_rodo_at'      => $context->get( 'consent_rodo_at' ),
			'consent_version'      => ( '' !== trim( (string) $context->get( 'consent_version', '' ) ) ) ? (string) $context->get( 'consent_version' ) : null,
		);

		$ready = ( '' !== $company && '' !== $nip && '' !== $email );

		return MP_Result::ok(
			array(
				'lead_data'   => $lead_data,
				'score'       => $score,
				'salesman_id' => $salesman,
				'lead_ready'  => $ready,
			)
		);
	}
}

/**
 * Agent 7.3 — tworzy leada w BD-3.
 */
class MP_D7_Agent_Create extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '7.3', 'Tworzy lead w BD-3', 'INSERT do wp_mp_leads (wpdb::insert)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$lead_data = (array) $context->get( 'lead_data', array() );
		if ( empty( $lead_data ) ) {
			return MP_Result::fail( 'Brak danych leada', array(), 'no_lead_data' );
		}

		$lead_id = MP_Lead_Intake_DB::insert_lead( $lead_data );
		if ( ! $lead_id ) {
			return MP_Result::fail( 'Nie udało się utworzyć leada', array(), 'insert_failed' );
		}

		return MP_Result::ok(
			array(
				'lead_id'    => $lead_id,
				'status'     => 'new',
				'created_at' => current_time( 'mysql' ),
			)
		);
	}
}

/**
 * QA Agent 7 — lead musi być utworzony (lead_id > 0).
 */
class MP_D7_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA7', 'QA Agent 7 — kontrola utworzenia', 'Wymaga lead_id > 0' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		if ( (int) $context->get( 'lead_id', 0 ) <= 0 ) {
			return MP_Result::fail( 'Lead nie został utworzony', array(), 'lead_not_created' );
		}
		return MP_Result::ok( array( 'd7_created' => true ) );
	}
}

/**
 * Budowniczy działu 7.
 */
class MP_Department_07 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D7_Agent_Dedup(),
				'critic' => new MP_Flag_Critic( 'K7.1', 'Krytyk 7.1 — weryfikuje unikalność', 'unique_ok' ),
			),
			array(
				'agent'  => new MP_D7_Agent_Prepare(),
				'critic' => new MP_Flag_Critic( 'K7.2', 'Krytyk 7.2 — weryfikuje gotowość danych', 'lead_ready' ),
			),
			array(
				'agent'  => new MP_D7_Agent_Create(),
				'critic' => new MP_Flag_Critic( 'K7.3', 'Krytyk 7.3 — weryfikuje zapis', 'lead_id' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D7_QA_Agent(),
			new MP_Accept_Critic( 'QAK7', 'QA Krytyk 7 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			7,
			'create-lead',
			'Utworzenie leada',
			'Dedup po NIP, scoring, przypisanie handlowca i zapis leada do wp_mp_leads.',
			$pairs,
			$gate
		);
	}
}
