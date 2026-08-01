<?php
/**
 * Dział 1 — Pobranie danych z bazy (BD-3).
 *
 * Pobiera z BD-3 dane potrzebne dalej w pipeline: istniejące leady pasujące
 * do zgłaszającej firmy (po NIP). Dział tylko CZYTA (żadnych zapisów).
 *
 * Zawartość pliku (1 plik = 1 dział):
 *  - Agent 1.1              (pobranie leadów)
 *  - Krytyk działu 1        (weryfikacja struktury wyniku agenta)
 *  - QA Agent 1             (kontrola kompletności działu)
 *  - MP_Department_01       (budowniczy działu)
 *
 * Źródła (oficjalne) — Golden Rule #2. Dokumentacja, którą "czytają" agenci/krytycy:
 *  - docs/dzial-01/wordpress-wpdb-get_results.md
 *  - docs/dzial-01/wordpress-wpdb-get_results.md
 * Dane wyłącznie z BD-3 (wp_mp_leads) przez wpdb — bez danych zmyślonych/wtórnych.
 * ZADANIE każdego agenta/krytyka jest przypisane do niego (patrz label/opis i metoda
 * run() w klasach niżej), nie w dokumentacji.
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
		// Ta sama normalizacja kraju co dział 4.1 (dział 1 działa PRZED nim, więc nie
		// może reużyć jego wyniku) — klucz unikalności w BD-3 to (country, nip), nie
		// sam nip (lokalne numery firmowe różnych krajów UE mogą się cyfrowo pokrywać).
		$country = MP_Vat_Number::country( $context->get( 'country', '' ) );
		// Numer w tej samej postaci, w jakiej trafia do BD-3 (dział 2 kanonizuje tak
		// samo). Bez tego zapis typu "123-456-32-18" nie trafiłby w istniejącego
		// klienta i dział 1 „ślepłby" na jego historię. Kraj musi być ustalony PRZED
		// numerem, bo to on rozstrzyga, czy litery są śmieciem, czy treścią.
		$nip  = MP_Vat_Number::normalize( $context->get( 'nip', '' ), $country );
		$rows = ( '' === $nip ) ? null : MP_Lead_Intake_DB::get_leads_by_nip( $nip, $country );

		/*
		 * `leads_checked` mówi, że zapytanie SIĘ ODBYŁO i SIĘ UDAŁO — czym innym
		 * niż „lista jest pusta". Bez tego znacznika Dział 7 rozstrzygał unikalność
		 * firmy po samej pustce w kopercie, więc brak NIP-u (nie ma czego szukać)
		 * i awaria odczytu (nie wiadomo, czy jest czego szukać) wyglądały tak samo
		 * jak potwierdzone „takiej firmy nie ma".
		 *
		 * `leads` zostaje tablicą zawsze, bo tego wymagają K1.1 i QA1 — brak
		 * odpowiedzi z bazy nie ma prawa zmieniać KSZTAŁTU koperty, tylko jej
		 * wiarygodność.
		 */
		return MP_Result::ok(
			array(
				'leads'         => is_array( $rows ) ? $rows : array(),
				'leads_checked' => is_array( $rows ),
			)
		);
	}
}

/**
 * Krytyk działu 1 — sprawdza, że agent zwrócił oczekiwany klucz jako tablicę.
 */
class MP_D1_Fetch_Critic extends MP_Abstract_Critic {

	/** @var string Oczekiwany klucz w danych agenta (leads). */
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
 * QA Agent 1 — sprawdza kompletność działu (leads).
 */
class MP_D1_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA1', 'QA Agent 1 — kontrola kompletności', 'Sprawdza, że pobrano leady' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$required = array( 'leads' );
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
