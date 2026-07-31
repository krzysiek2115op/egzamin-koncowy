<?php
/**
 * Dział 6 — Zapis zgód.
 *
 * 6.1 zgoda marketingowa (opcjonalna), 6.2 zgoda RODO (wymagana do przetwarzania),
 * 6.3 data i wersja zgody. Zgody i znaczniki trafiają do kontekstu i są zapisywane
 * przy tworzeniu leada (dział 7) w kolumnach consent_* tabeli wp_mp_leads.
 *
 * Źródła (oficjalne/oryginalne) — Golden Rule #2. Dokumentacja czytana przez agentów:
 *  - docs/dzial-06/wordpress-current_time.md
 *  - docs/dzial-06/rodo-zgody.md
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 6.1 — zgoda marketingowa (opcjonalna).
 */
class MP_D6_Agent_Marketing extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '6.1', 'Zgoda marketingowa', 'Odczyt zgody marketingowej (opcjonalna)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$marketing = $context->get( 'consent_marketing', false );
		return MP_Result::ok( array( 'consent_marketing' => ( $marketing ? 1 : 0 ) ) );
	}
}

/**
 * Agent 6.2 — zgoda RODO (wymagana).
 */
class MP_D6_Agent_Rodo extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '6.2', 'Zgoda RODO', 'Odczyt zgody RODO (wymagana do przetwarzania danych)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$rodo = (bool) $context->get( 'consent_rodo', false );
		return MP_Result::ok(
			array(
				'consent_rodo' => ( $rodo ? 1 : 0 ),
				'rodo_ok'      => $rodo,
			)
		);
	}
}

/**
 * Agent 6.3 — data i wersja zgody.
 */
class MP_D6_Agent_Consent_Meta extends MP_Abstract_Agent {

	/** Wersja treści zgód. */
	const CONSENT_VERSION = '1.0';

	public function __construct() {
		parent::__construct( '6.3', 'Data i wersja zgody', 'Znacznik czasu (current_time) + wersja treści zgód' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$now = current_time( 'mysql' );

		return MP_Result::ok(
			array(
				'consent_version'      => self::CONSENT_VERSION,
				'consent_rodo_at'      => $context->get( 'consent_rodo' ) ? $now : null,
				'consent_marketing_at' => $context->get( 'consent_marketing' ) ? $now : null,
			)
		);
	}
}

/**
 * QA Agent 6 — RODO musi być udzielone, wersja zgód ustawiona.
 */
class MP_D6_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA6', 'QA Agent 6 — kontrola zgód', 'Wymaga zgody RODO + wersji zgód' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		if ( ! $context->get( 'consent_rodo' ) ) {
			return MP_Result::fail( 'Brak wymaganej zgody RODO', array( 'errors' => array( 'consent_rodo' => 'Zgoda RODO jest wymagana' ) ), 'rodo_missing' );
		}
		if ( '' === trim( (string) $context->get( 'consent_version', '' ) ) ) {
			return MP_Result::fail( 'Brak wersji zgód', array(), 'consent_version_missing' );
		}
		return MP_Result::ok( array( 'd6_consents_ok' => true ) );
	}
}

/**
 * Budowniczy działu 6.
 */
class MP_Department_06 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D6_Agent_Marketing(),
				'critic' => new MP_Accept_Critic( 'K6.1', 'Krytyk 6.1 — przyjmuje zgodę marketingową' ),
			),
			array(
				'agent'  => new MP_D6_Agent_Rodo(),
				'critic' => new MP_Flag_Critic( 'K6.2', 'Krytyk 6.2 — weryfikuje zgodę RODO', 'rodo_ok' ),
			),
			array(
				'agent'  => new MP_D6_Agent_Consent_Meta(),
				'critic' => new MP_Field_Critic( 'K6.3', 'Krytyk 6.3 — weryfikuje wersję zgody', 'consent_version' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D6_QA_Agent(),
			new MP_Accept_Critic( 'QAK6', 'QA Krytyk 6 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			6,
			'consents',
			'Zapis zgód',
			'Zapisanie zgód (marketing + RODO) wraz z datą i wersją treści.',
			$pairs,
			$gate
		);
	}
}
