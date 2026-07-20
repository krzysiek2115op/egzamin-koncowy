<?php
/**
 * Dział 1 — Pobranie danych z bazy (BD-3).
 *
 * Jednym przebiegiem pobiera z BD-3 dane potrzebne dalej w pipeline:
 * istniejące leady pasujące do zgłaszającej firmy (po NIP), ich oferty
 * oraz historię aktywności. Dział tylko CZYTA (żadnych zapisów).
 *
 * Zawartość pliku (1 plik = 1 dział):
 *  - Agent 1.1 / 1.2 / 1.3  (pobranie leadów / ofert / historii)
 *  - Krytyk działu 1        (weryfikacja struktury wyniku agenta)
 *  - QA Agent 1             (kontrola kompletności działu)
 *  - MP_Department_01       (budowniczy działu)
 *
 * Dokumentacja: docs/dzial-01-pobranie-danych.md
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 1.1 — pobiera istniejące leady pasujące po NIP.
 */
class MP_D1_Agent_Fetch_Leads extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '1.1', 'Pobiera leady', 'Odczyt leadów pasujących po NIP z wp_mp_leads (bez zarchiwizowanych)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$nip   = trim( (string) $context->get( 'nip', '' ) );
		$leads = ( '' === $nip ) ? array() : MP_Lead_Intake_DB::get_leads_by_nip( $nip );

		return MP_Result::ok( array( 'leads' => $leads ) );
	}
}

/**
 * Agent 1.2 — pobiera oferty powiązane ze znalezionymi leadami.
 */
class MP_D1_Agent_Fetch_Offers extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '1.2', 'Pobiera oferty', 'Odczyt ofert z wp_mp_offers dla znalezionych lead_id' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$leads = (array) $context->get( 'leads', array() );
		$ids   = $leads ? wp_list_pluck( $leads, 'id' ) : array();
		$offers = $ids ? MP_Lead_Intake_DB::get_offers_by_lead_ids( $ids ) : array();

		return MP_Result::ok( array( 'offers' => $offers ) );
	}
}

/**
 * Agent 1.3 — pobiera historię aktywności powiązanych leadów.
 */
class MP_D1_Agent_Fetch_Activity extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '1.3', 'Pobiera historię aktywności', 'Odczyt ostatnich wpisów z wp_mp_activity_log dla lead_id' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$leads    = (array) $context->get( 'leads', array() );
		$ids      = $leads ? wp_list_pluck( $leads, 'id' ) : array();
		$activity = $ids ? MP_Lead_Intake_DB::get_activity_by_lead_ids( $ids ) : array();

		return MP_Result::ok( array( 'activity_log' => $activity ) );
	}
}

/**
 * Krytyk działu 1 — sprawdza, że agent zwrócił oczekiwany klucz jako tablicę.
 */
class MP_D1_Fetch_Critic extends MP_Abstract_Critic {

	/** @var string Oczekiwany klucz w danych agenta (leads/offers/activity_log). */
	protected $key;

	/**
	 * @param string $id    Identyfikator.
	 * @param string $label Nazwa.
	 * @param string $key   Oczekiwany klucz.
	 */
	public function __construct( $id, $label, $key ) {
		parent::__construct( $id, $label );
		$this->key = $key;
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
		if ( ! array_key_exists( $this->key, $data ) || ! is_array( $data[ $this->key ] ) ) {
			return MP_Result::fail(
				sprintf( 'Brak lub zła struktura danych: %s', $this->key ),
				array(),
				'invalid_structure'
			);
		}

		return MP_Result::ok( $data );
	}
}

/**
 * QA Agent 1 — sprawdza kompletność działu (leads, offers, activity_log).
 */
class MP_D1_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA1', 'QA Agent 1 — kontrola kompletności', 'Sprawdza, że pobrano leady, oferty i historię aktywności' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$required = array( 'leads', 'offers', 'activity_log' );
		$missing  = array();

		foreach ( $required as $key ) {
			if ( ! is_array( $context->get( $key ) ) ) {
				$missing[] = $key;
			}
		}

		if ( $missing ) {
			return MP_Result::fail(
				'Niekompletne dane działu 1: ' . implode( ', ', $missing ),
				array( 'missing' => $missing ),
				'incomplete'
			);
		}

		return MP_Result::ok( array( 'd1_complete' => true ) );
	}
}

/**
 * Budowniczy działu 1.
 */
class MP_Department_01 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D1_Agent_Fetch_Leads(),
				'critic' => new MP_D1_Fetch_Critic( 'K1.1', 'Krytyk 1.1 — weryfikuje leady', 'leads' ),
			),
			array(
				'agent'  => new MP_D1_Agent_Fetch_Offers(),
				'critic' => new MP_D1_Fetch_Critic( 'K1.2', 'Krytyk 1.2 — weryfikuje oferty', 'offers' ),
			),
			array(
				'agent'  => new MP_D1_Agent_Fetch_Activity(),
				'critic' => new MP_D1_Fetch_Critic( 'K1.3', 'Krytyk 1.3 — weryfikuje historię', 'activity_log' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D1_QA_Agent(),
			new MP_Accept_Critic( 'QAK1', 'QA Krytyk 1 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			1,
			'fetch-data',
			'Pobranie danych z bazy (BD-3)',
			'Pobranie wszystkich niezbędnych danych z BD-3 jednym strzałem (1 AJAX).',
			$pairs,
			$gate
		);
	}
}
