<?php
/**
 * Dział 8 — Numeracja i wersja.
 *
 * CZYSTA FUNKCJA na kontekście (numbering[] ze snapshotu Działu 2) — zero
 * wywołań WC_* albo wpdb. Zawartość pliku (1 plik = 1 dział):
 *  - Agent 8.1 (numer)  — kandydat nowego numeru w roku (zawsze liczony)
 *  - Agent 8.2 (wersja) — korekta (ten sam numer, wersja+1) ALBO nowy numer, wersja 1
 *  - QA Agent 8            — jedno-albo-drugie
 *  - MP_OB_Department_08    — budowniczy działu
 *
 * Numer PRZED renderem PDF (kryt. 5.3) — zapewnione samą kolejnością działów
 * (8 przed 9), bez dodatkowego kodu. Kolizja numeru (dwie oferty równolegle
 * wyliczyły tego samego kandydata) wychodzi na indeksie UNIQUE dopiero PRZY
 * ZAPISIE — FAIL_RETRY (numer+1, ponowny render, ponowna transakcja, wiele
 * podejść — MAX_ATTEMPTS) należy do Działu 10 (zapis), retry LOKALNY wewnątrz jego agenta,
 * pipeline zostaje ściśle jednokierunkowy — patrz docs/dzial-08/mysql-unique-index.md.
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - docs/dzial-08/mysql-unique-index.md (UNIQUE(offer_number, version))
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 8.1 — kandydat nowego numeru w roku: ostatni numer + 1, zawsze liczony
 * (używany, chyba że Agent 8.2 rozpozna korektę istniejącej oferty).
 */
class MP_OB_D8_Agent_Number extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '8.1', 'Agent 8.1 — numer', 'Nowa oferta: kandydat = ostatni numer w roku + 1 (ze snapshotu), format OF/RRRR/NNNNNN' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$numbering = is_array( $context->get( 'numbering' ) ) ? $context->get( 'numbering' ) : array();
		// Rok z Działu 2 (Agent 2.5 "numeracja") — ten sam odczyt, żeby licznik
		// i rok pochodziły z JEDNEGO punktu w czasie (zasada "jeden odczyt").
		$year = isset( $numbering['year'] ) ? (int) $numbering['year'] : (int) current_time( 'Y' );
		$last = isset( $numbering['last_number'] ) ? (string) $numbering['last_number'] : null;

		$next_seq = 1;
		if ( $last ) {
			// get_last_offer_number_for_year() już filtruje LIKE po roku (Dział 2) —
			// tu wyłącznie parsujemy format, żaden dodatkowy odczyt.
			if ( 1 === preg_match( '/^OF\/' . $year . '\/(\d{6})$/', $last, $m ) ) {
				$next_seq = ( (int) $m[1] ) + 1;
			} else {
				return MP_OB_Result::fail( 'Nieprawidłowy format ostatniego numeru oferty w snapshocie.', array(), 'malformed_last_number' );
			}
		}

		// "licznik wraca do 1 z dniem 1 stycznia" — spełnione samym filtrem roku
		// w zapytaniu Działu 2 (last_number jest zawsze z BIEŻĄCEGO roku albo null).
		return MP_OB_Result::ok(
			array(
				'candidate_offer_number' => sprintf( 'OF/%d/%06d', $year, $next_seq ),
			)
		);
	}
}

/**
 * Krytyk 8.1 — ciągłość-numeracji: format OF/RRRR/NNNNNN, rok = rok bieżący.
 */
class MP_OB_D8_Critic_Continuity extends MP_OB_Abstract_Critic {

	/**
	 * @param MP_OB_Result  $agent_result Wynik agenta.
	 * @param MP_OB_Context $context      Kontekst.
	 * @return MP_OB_Result
	 */
	public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$data      = $agent_result->get_data();
		$candidate = isset( $data['candidate_offer_number'] ) ? (string) $data['candidate_offer_number'] : '';
		$numbering = is_array( $context->get( 'numbering' ) ) ? $context->get( 'numbering' ) : array();
		$year      = isset( $numbering['year'] ) ? (int) $numbering['year'] : (int) current_time( 'Y' );

		if ( 1 !== preg_match( '/^OF\/' . $year . '\/\d{6}$/', $candidate ) ) {
			return MP_OB_Result::fail( 'Kandydat numeru oferty ma nieprawidłowy format albo zły rok.', array(), 'invalid_candidate_number' );
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * Agent 8.2 — wersja: korekta (istniejący numer -> ten sam numer, wersja+1,
 * wskazanie na wersję poprzednią) ALBO nowa oferta (kandydat z Agenta 8.1, wersja 1).
 */
class MP_OB_D8_Agent_Version extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '8.2', 'Agent 8.2 — wersja', 'Korekta: ten sam numer, wersja + 1, wskazanie na wersję poprzednią; inaczej nowy numer z wersją 1' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$numbering        = is_array( $context->get( 'numbering' ) ) ? $context->get( 'numbering' ) : array();
		$existing_number  = isset( $numbering['existing_offer_number'] ) ? $numbering['existing_offer_number'] : null;
		$existing_version = isset( $numbering['existing_version'] ) ? (int) $numbering['existing_version'] : null;

		if ( $existing_number ) {
			// Korekta — "nowa wersja nigdy nie nadpisuje poprzedniej: historia
			// tylko dopisywana" (Dział 10 robi INSERT do wersji, nie UPDATE).
			return MP_OB_Result::ok(
				array(
					'offer_number'   => (string) $existing_number,
					'version'        => $existing_version + 1,
					'parent_version' => $existing_version,
					'numbering_mode' => 'correction',
				)
			);
		}

		$candidate = (string) $context->get( 'candidate_offer_number', '' );
		if ( '' === $candidate ) {
			return MP_OB_Result::fail( 'Brak kandydata numeru oferty z Agenta 8.1.', array(), 'missing_candidate_number' );
		}

		return MP_OB_Result::ok(
			array(
				'offer_number'   => $candidate,
				'version'        => 1,
				'parent_version' => null,
				'numbering_mode' => 'new_number',
			)
		);
	}
}

/**
 * Krytyk 8.2 — przyrost-wersji: wersja dodatnia; przy korekcie ściśle
 * parent_version + 1 (nigdy powtórzenie/nadpisanie tej samej wersji).
 */
class MP_OB_D8_Critic_Version_Increment extends MP_OB_Abstract_Critic {

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

		$data           = $agent_result->get_data();
		$version        = isset( $data['version'] ) ? $data['version'] : null;
		$parent_version = array_key_exists( 'parent_version', $data ) ? $data['parent_version'] : false;

		if ( ! is_int( $version ) || $version < 1 ) {
			return MP_OB_Result::fail( 'Wersja musi być dodatnią liczbą całkowitą.', array(), 'invalid_version' );
		}
		if ( null !== $parent_version && $version !== $parent_version + 1 ) {
			return MP_OB_Result::fail( 'Wersja korekty musi być dokładnie parent_version + 1 — bez nadpisań.', array(), 'version_not_incremented' );
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * QA Agent 8 — jedno-albo-drugie: nowy numer ALBO podbita wersja, nigdy oba naraz.
 */
class MP_OB_D8_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA8', 'QA Agent 8 — kontrola kompletności', 'Sprawdza jedno-albo-drugie: nowy numer ALBO podbita wersja — nigdy oba naraz' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$mode           = (string) $context->get( 'numbering_mode', '' );
		$version        = $context->get( 'version' );
		$parent_version = $context->get( 'parent_version' );
		$offer_number   = (string) $context->get( 'offer_number', '' );

		$is_new_number = 'new_number' === $mode && 1 === $version && null === $parent_version;
		$is_correction = 'correction' === $mode && null !== $parent_version && $version === $parent_version + 1;

		if ( '' === $offer_number || ( $is_new_number === $is_correction ) ) {
			// Albo żaden tryb nie pasuje, albo (strukturalnie niemożliwe, ale
			// sprawdzane defensywnie) oba naraz — obie sytuacje to STOP.
			return MP_OB_Result::fail(
				'Numeracja niejednoznaczna: ani czysto nowy numer, ani czysta korekta wersji.',
				array(
					'mode'           => $mode,
					'version'        => $version,
					'parent_version' => $parent_version,
				),
				'numbering_mode_ambiguous'
			);
		}

		return MP_OB_Result::ok( array( 'numbering_complete' => true ) );
	}
}

/**
 * Budowniczy działu 8.
 */
class MP_OB_Department_08 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_D8_Agent_Number(),
				'critic' => new MP_OB_D8_Critic_Continuity( 'K8.1', 'Krytyk 8.1 — ciągłość-numeracji' ),
			),
			array(
				'agent'  => new MP_OB_D8_Agent_Version(),
				'critic' => new MP_OB_D8_Critic_Version_Increment( 'K8.2', 'Krytyk 8.2 — przyrost-wersji' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D8_QA_Agent(),
			new MP_OB_Accept_Critic( 'QAK8', 'QA Krytyk 8 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			8,
			'numbering-version',
			'Numeracja i wersja',
			'Kandydat numeru oferty (nowa) albo przyrost wersji (korekta) — zawsze przed renderem PDF.',
			$pairs,
			$gate
		);
	}
}
