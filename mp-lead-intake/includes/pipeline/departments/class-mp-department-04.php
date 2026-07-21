<?php
/**
 * Dział 4 — Przypisanie kraju i segmentu.
 *
 * 4.1 kraj (ISO 3166-1 alpha-2; dla polskiego NIP domyślnie PL),
 * 4.2 segment (deterministyczny słownik branż — konfiguracja projektu),
 * 4.3 kategoria klienta (formularz B2B → domyślnie B2B).
 *
 * Źródła (oficjalne/oryginalne) — Golden Rule #2. Dokumentacja czytana przez agentów:
 *  - docs/dzial-04/iso-3166-1-kody-krajow.md
 *  - docs/dzial-04/segmentacja-konfiguracja.md
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 4.1 — ustala kraj (ISO 3166-1 alpha-2).
 */
class MP_D4_Agent_Country extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '4.1', 'Ustala kraj', 'Kod kraju ISO 3166-1 alpha-2 (domyślnie PL dla NIP)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$country = strtoupper( trim( (string) $context->get( 'country', '' ) ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$country = 'PL';
		}
		return MP_Result::ok( array( 'country' => $country ) );
	}
}

/**
 * Agent 4.2 — przypisuje segment wg słownika branż (konfiguracja projektu).
 */
class MP_D4_Agent_Segment extends MP_Abstract_Agent {

	/** Słownik: fragment nazwy => segment. Deterministyczna konfiguracja. */
	const MAP = array(
		'produkc'   => 'Produkcja',
		'fabry'     => 'Produkcja',
		'usług'     => 'Usługi',
		'serwis'    => 'Usługi',
		'handel'    => 'Handel',
		'sklep'     => 'Handel',
		'transport' => 'Transport',
		'logist'    => 'Transport',
		'budow'     => 'Budownictwo',
		'it'        => 'IT',
		'software'  => 'IT',
		'tech'      => 'IT',
	);

	public function __construct() {
		parent::__construct( '4.2', 'Przypisuje segment', 'Segment wg słownika branż (konfiguracja), domyślnie "Inne"' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		// Segment może przyjść wprost z formularza; inaczej dobieramy ze słownika.
		$segment = trim( (string) $context->get( 'segment', '' ) );

		if ( '' === $segment ) {
			$haystack = strtolower( (string) $context->get( 'company_name', '' ) );
			$segment  = 'Inne';
			foreach ( self::MAP as $needle => $value ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					$segment = $value;
					break;
				}
			}
		}

		return MP_Result::ok( array( 'segment' => $segment ) );
	}
}

/**
 * Agent 4.3 — dobiera kategorię klienta.
 */
class MP_D4_Agent_Category extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '4.3', 'Dobiera kategorię klienta', 'Kategoria klienta (formularz B2B → B2B)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$category = trim( (string) $context->get( 'client_category', '' ) );
		if ( '' === $category ) {
			$category = 'B2B';
		}
		return MP_Result::ok( array( 'client_category' => $category ) );
	}
}

/**
 * QA Agent 4 — sprawdza, że kraj, segment i kategoria są ustawione.
 */
class MP_D4_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA4', 'QA Agent 4 — kontrola przypisania', 'Kraj + segment + kategoria ustawione' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		foreach ( array( 'country', 'segment', 'client_category' ) as $key ) {
			if ( '' === trim( (string) $context->get( $key, '' ) ) ) {
				return MP_Result::fail( 'Brak przypisania: ' . $key, array(), 'assignment_incomplete' );
			}
		}
		return MP_Result::ok( array( 'd4_assigned' => true ) );
	}
}

/**
 * Budowniczy działu 4.
 */
class MP_Department_04 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D4_Agent_Country(),
				'critic' => new MP_Field_Critic( 'K4.1', 'Krytyk 4.1 — weryfikuje kraj', 'country' ),
			),
			array(
				'agent'  => new MP_D4_Agent_Segment(),
				'critic' => new MP_Field_Critic( 'K4.2', 'Krytyk 4.2 — weryfikuje segment', 'segment' ),
			),
			array(
				'agent'  => new MP_D4_Agent_Category(),
				'critic' => new MP_Field_Critic( 'K4.3', 'Krytyk 4.3 — weryfikuje kategorię', 'client_category' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D4_QA_Agent(),
			new MP_Accept_Critic( 'QAK4', 'QA Krytyk 4 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			4,
			'country-segment',
			'Przypisanie kraju i segmentu',
			'Automatyczne przypisanie kraju (ISO 3166-1), segmentu i kategorii klienta.',
			$pairs,
			$gate
		);
	}
}
