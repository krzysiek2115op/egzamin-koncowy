<?php
/**
 * Dział 11 — Odpowiedź i przekazanie.
 *
 * Startuje ŚCIŚLE PO faktycznym SQL COMMIT (patrz `MP_OB_Pipeline_Factory::
 * make()` — `set_transactional_until(10)` — i docblock `MP_OB_Pipeline::
 * set_transactional_until()`). Dzięki temu Agent 11.1 może bezpiecznie: (a)
 * przenieść PDF z nazwy tymczasowej na docelową (gate Działu 9: "nazwa
 * docelowa po COMMIT") i (b) wystawić zdarzenie integracyjne dla pluginu 3
 * (Sales Workflow), wiedząc, że oferta NAPRAWDĘ istnieje w bazie — bez tego
 * porządku subskrybent zdarzenia dostałby "ofertę-widmo" (dane, które
 * transakcja mogła jeszcze wycofać). Zawartość pliku (1 plik = 1 dział):
 *  - Agent 11.1 (zdarzenie) — finalizacja PDF + do_action('mp_offer_created') raz
 *  - Agent 11.2 (odpowiedź) — JSON: offer_id/numer/wersja/pdf_url/status/trace_id
 *  - QA Agent 11              — tylko-json (biały zestaw pól, nic w tle)
 *  - MP_OB_Department_11        — budowniczy działu
 *
 * Automatyzacja 4.4: oferta do zatwierdzenia — przekazanie do pluginu 3 przez
 * zdarzenie `mp_offer_created` (plugin 3 jest tylko konsumentem statusów,
 * patrz decyzja architektoniczna B — nie buduje ofert).
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - docs/dzial-11/wordpress-hooks-i-json.md (do_action/did_action, wp_send_json_success)
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 11.1 — zdarzenie: finalizuje plik PDF (tmp → nazwa docelowa) i
 * wystawia `mp_offer_created` DOKŁADNIE RAZ.
 */
class MP_OB_D11_Agent_Event extends MP_OB_Abstract_Agent {

	/** Hook integracyjny konsumowany przez plugin 3 (Sales Workflow). */
	const HOOK = 'mp_offer_created';

	public function __construct() {
		parent::__construct( '11.1', 'Agent 11.1 — zdarzenie', 'mp_offer_created wystawione dokładnie raz, po COMMIT; oferta w statusie draft' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$offer_id     = (int) $context->get( 'offer_id', 0 );
		$offer_number = (string) $context->get( 'offer_number', '' );
		$version      = (int) $context->get( 'version', 0 );

		if ( $offer_id <= 0 || '' === $offer_number ) {
			// Defense-in-depth: Dział 10 MUSI przebiec (i naprawdę zapisać ofertę)
			// przed tym dzialem — bez tego zdarzenie ogłosiłoby ofertę-widmo.
			return MP_OB_Result::fail( 'Brak potwierdzonej, zapisanej oferty (Dział 10 musi przebiec wcześniej).', array(), 'missing_committed_offer' );
		}

		$pdf      = is_array( $context->get( 'pdf' ) ) ? $context->get( 'pdf' ) : array();
		$tmp_path = isset( $pdf['tmp_path'] ) ? (string) $pdf['tmp_path'] : '';
		if ( '' !== $tmp_path && file_exists( $tmp_path ) ) {
			// Sprawdzone PRZED do_action() poniżej — bez tego nieudana finalizacja
			// (dysk pełny, uprawnienia) i tak wystawiłaby zdarzenie integracyjne dla
			// pluginu 3 z pdf_url wskazującym na plik, który nigdy nie powstał pod
			// nazwą docelową (oferta jest już ZAPISANA — Dział 10 skończył PRZED
			// COMMIT — więc tego etapu nie da się wycofać transakcją; jedyna obrona
			// to STOP tutaj, zanim zdarzenie wyjdzie na zewnątrz).
			if ( false === MP_Offer_Builder_Storage::finalize_pdf( $tmp_path, $offer_number, $version ) ) {
				return MP_OB_Result::fail( 'Finalizacja pliku PDF (przeniesienie z nazwy tymczasowej) nie powiodła się.', array(), 'pdf_finalize_failed' );
			}
		}

		$client = is_array( $context->get( 'client' ) ) ? $context->get( 'client' ) : array();

		$before = did_action( self::HOOK );
		do_action(
			self::HOOK,
			$offer_id,
			array(
				'offer_id'     => $offer_id,
				'offer_number' => $offer_number,
				'version'      => $version,
				'status'       => MP_Offer_Builder_DB::STATUS_DRAFT,
				'client_name'  => isset( $client['name'] ) ? (string) $client['name'] : '',
				'gross_grosze' => (int) $context->get( 'gross_grosze', 0 ),
				'currency'     => (string) $context->get( 'currency', 'PLN' ),

				/*
				 * F4 (bramka integracyjna): identyfikator leada w zdarzeniu.
				 * Plugin 3 prowadzi proces sprzedażowy PO LEADZIE — bez tego pola
				 * nie miał jak powiązać oferty ze sprawą i zdarzenie przechodziło
				 * mu bokiem. Zgadywanie po nazwie klienta odpadało: dwie firmy o
				 * tej samej nazwie trafiłyby do siebie nawzajem.
				 *
				 * Zero oznacza ofertę utworzoną ręcznie, bez leada — odbiorca ma to
				 * rozróżnić, a nie dostać pole, którego brakuje.
				 */
				'lead_id'      => (int) $context->get( 'lead_id', 0 ),
			)
		);
		$after = did_action( self::HOOK );

		return MP_OB_Result::ok(
			array(
				'event_fire_count' => $after - $before,
				'trace_id'         => wp_generate_uuid4(),
			)
		);
	}
}

/**
 * Krytyk 11.1 — jednokrotność: `did_action()` przed/po Agentem 11.1 różni się
 * DOKŁADNIE o 1 (patrz docs/dzial-11/wordpress-hooks-i-json.md).
 */
class MP_OB_D11_Critic_Once extends MP_OB_Abstract_Critic {

	/**
	 * @param MP_OB_Result  $agent_result Wynik agenta.
	 * @param MP_OB_Context $context      Kontekst.
	 * @return MP_OB_Result
	 */
	public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
		unset( $context );
		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$data = $agent_result->get_data();
		if ( 1 !== ( isset( $data['event_fire_count'] ) ? $data['event_fire_count'] : null ) ) {
			return MP_OB_Result::fail( 'Zdarzenie mp_offer_created wystawione niedokładnie raz.', array(), 'event_not_fired_once' );
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * Agent 11.2 — odpowiedź: JSON z dokładnie ustalonym zestawem pól — bez
 * ścieżek serwera i danych innych ofert.
 */
class MP_OB_D11_Agent_Response extends MP_OB_Abstract_Agent {

	/**
	 * Nazwa akcji chronionego endpointu pobierania PDF (decyzja architektoniczna
	 * C — NIGDY bezpośredni link do pliku). Handler z nonce + capability +
	 * weryfikacją właściciela oferty powstaje w Kroku 4 — kontrakt URL (nazwa
	 * akcji + nonce per-oferta) jest stabilny już teraz.
	 */
	const DOWNLOAD_ACTION = 'mp_offer_builder_download_pdf';

	public function __construct() {
		parent::__construct( '11.2', 'Agent 11.2 — odpowiedź', 'JSON: offer_id, numer, wersja, adres PDF, status, trace_id; kod HTTP wg wyniku' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$offer_id     = (int) $context->get( 'offer_id', 0 );
		$offer_number = (string) $context->get( 'offer_number', '' );
		$version      = (int) $context->get( 'version', 0 );
		$trace_id     = (string) $context->get( 'trace_id', '' );

		if ( $offer_id <= 0 || '' === $offer_number || '' === $trace_id ) {
			return MP_OB_Result::fail( 'Brak danych do zbudowania odpowiedzi (Agent 11.1 musi przebiec wcześniej).', array(), 'missing_response_data' );
		}

		$pdf_url = add_query_arg(
			array(
				'action'   => self::DOWNLOAD_ACTION,
				'offer_id' => $offer_id,
				'_wpnonce' => wp_create_nonce( self::DOWNLOAD_ACTION . '_' . $offer_id ),
			),
			admin_url( 'admin-ajax.php' )
		);

		return MP_OB_Result::ok(
			array(
				'response' => array(
					'success'      => true,
					'offer_id'     => $offer_id,
					'offer_number' => $offer_number,
					'version'      => $version,
					'pdf_url'      => $pdf_url,
					'status'       => MP_Offer_Builder_DB::STATUS_DRAFT,
					'trace_id'     => $trace_id,
				),
			)
		);
	}
}

/**
 * Krytyk 11.2 — zakres-odpowiedzi: WYŁĄCZNIE biały zestaw pól — bez ścieżek
 * serwera (np. `tmp_path`) i bez danych innych ofert (`write_plan`, `client`, ...).
 */
class MP_OB_D11_Critic_Response_Scope extends MP_OB_Abstract_Critic {

	/** Jedyne dozwolone klucze odpowiedzi. */
	const ALLOWED_KEYS = array( 'success', 'offer_id', 'offer_number', 'version', 'pdf_url', 'status', 'trace_id' );

	/**
	 * @param MP_OB_Result  $agent_result Wynik agenta.
	 * @param MP_OB_Context $context      Kontekst.
	 * @return MP_OB_Result
	 */
	public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
		unset( $context );
		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$data     = $agent_result->get_data();
		$response = isset( $data['response'] ) && is_array( $data['response'] ) ? $data['response'] : array();
		$extra    = array_diff( array_keys( $response ), self::ALLOWED_KEYS );
		$missing  = array_diff( self::ALLOWED_KEYS, array_keys( $response ) );

		if ( $extra || $missing ) {
			return MP_OB_Result::fail(
				'Odpowiedź poza dozwolonym schematem.',
				array(
					'extra'   => array_values( $extra ),
					'missing' => array_values( $missing ),
				),
				'response_out_of_scope'
			);
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * QA Agent 11 — tylko-json: wyjście zgodne ze schematem, plik tymczasowy PDF
 * już nie istnieje pod starą nazwą (finalizacja się zdarzyła, nic nie zostaje
 * "wiszące" w katalogu tymczasowym).
 */
class MP_OB_D11_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA11', 'QA Agent 11 — kontrola kompletności', 'Sprawdza tylko-json: wyjście zgodne ze schematem, nic nie wisi w tle po odpowiedzi' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$response = is_array( $context->get( 'response' ) ) ? $context->get( 'response' ) : array();
		if ( empty( $response['success'] ) || empty( $response['trace_id'] ) ) {
			return MP_OB_Result::fail( 'Odpowiedź niekompletna.', array(), 'incomplete_response' );
		}

		$pdf      = is_array( $context->get( 'pdf' ) ) ? $context->get( 'pdf' ) : array();
		$tmp_path = isset( $pdf['tmp_path'] ) ? (string) $pdf['tmp_path'] : '';
		if ( '' !== $tmp_path && file_exists( $tmp_path ) ) {
			// Plik nadal pod nazwą TYMCZASOWĄ mimo udanego zapisu — finalizacja
			// (Agent 11.1) nie zaszła; "nic nie wisi w tle po odpowiedzi" złamane.
			return MP_OB_Result::fail( 'Plik PDF nadal pod nazwą tymczasową — finalizacja się nie powiodła.', array(), 'pdf_not_finalized' );
		}

		return MP_OB_Result::ok( array( 'handoff_complete' => true ) );
	}
}

/**
 * Budowniczy działu 11.
 */
class MP_OB_Department_11 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_D11_Agent_Event(),
				'critic' => new MP_OB_D11_Critic_Once( 'K11.1', 'Krytyk 11.1 — jednokrotność' ),
			),
			array(
				'agent'  => new MP_OB_D11_Agent_Response(),
				'critic' => new MP_OB_D11_Critic_Response_Scope( 'K11.2', 'Krytyk 11.2 — zakres-odpowiedzi' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D11_QA_Agent(),
			new MP_OB_Accept_Critic( 'QAK11', 'QA Krytyk 11 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			11,
			'response-handoff',
			'Odpowiedź i przekazanie',
			'Zdarzenie mp_offer_created dokładnie raz po COMMIT i odpowiedź JSON z trace_id.',
			$pairs,
			$gate
		);
	}
}
