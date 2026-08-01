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
		/*
		 * Warunkiem jest FAKT ODCZYTU, nie obecność klucza. Wcześniej `$dup`
		 * wynikało z samej pustki w kopercie, więc „nie ma takiej firmy",
		 * „nikt nie pytał" i „zapytanie padło" dawały tę samą odpowiedź:
		 * unikalność potwierdzona. Dział 1 melduje teraz `leads_checked`
		 * i bez tego meldunku 7.1 odmawia, zamiast zgadywać.
		 */
		if ( true !== $context->get( 'leads_checked' ) ) {
			return MP_Result::fail(
				'Unikalność firmy niesprawdzona — odczyt leadów z BD-3 się nie odbył.',
				array( 'errors' => array( 'leads_checked' ) ),
				'dedup_not_checked'
			);
		}

		$nip = (string) $context->get( 'nip', '' );
		// Reużycie wyniku działu 1.1 (już pobrał aktywne leady po tym samym,
		// znormalizowanym NIP) zamiast powtórnego zapytania do BD-3 — audyt
		// architektury (S-2) wykazał, że dział 1 był pobierany, ale nigdzie
		// dalej nieużywany. NIP w kontekście jest identyczny w obu miejscach
		// (ta sama normalizacja preg_replace('/\D+/','',...) na tym samym
		// wejściu, dział 1 działa PRZED normalizującym działem 2).
		$existing = (array) $context->get( 'leads', array() );
		$dup      = ! empty( $existing );

		// Aktywny lead o tym NIP = twardy duplikat (STOP przez K7.1). Zarchiwizowany
		// (soft-delete) = kandydat do REAKTYWACJI w 7.3 — inaczej UNIQUE(nip) zablokowałby
		// ponowne zgłoszenie powracającej firmy generycznym „insert_failed".
		$reactivate_id = null;
		if ( ! $dup && '' !== $nip ) {
			// Dział 4 już znormalizował/zwalidował 'country' w kontekście (działa przed
			// działem 7) — klucz unikalności w BD-3 to (country, nip), patrz dział 1.1.
			$country  = (string) $context->get( 'country', 'PL' );
			$archived = MP_Lead_Intake_DB::get_archived_lead_by_nip( $nip, $country );

			/*
			 * `null` = odczyt się NIE odbył (patrz class-mp-db.php). Potwierdzony
			 * brak archiwum to pusta tablica. Bez tego rozróżnienia awaria
			 * zapytania wyglądała identycznie jak „firma nigdy tu nie była":
			 * 7.3 szedł na INSERT, ten bił w UNIQUE (country, nip) — bo wiersz po
			 * soft-delete nadal tam stoi — i oddawał generyczne `insert_failed`.
			 * Ta sama zasada co przy `leads_checked` wyżej: „nie ustalono" to nie
			 * to samo co „ustalono, że nie".
			 */
			if ( is_null( $archived ) ) {
				return MP_Result::fail(
					'Archiwum firm niesprawdzone — odczyt z BD-3 się nie odbył.',
					array( 'errors' => array( 'archive_checked' ) ),
					'archive_not_checked'
				);
			}

			if ( isset( $archived['id'] ) ) {
				$reactivate_id = (int) $archived['id'];
			}
		}

		return MP_Result::ok(
			array(
				'is_duplicate'       => $dup,
				'unique_ok'          => ! $dup,
				'existing_lead_id'   => $dup ? (int) $existing[0]['id'] : null,
				'reactivate_lead_id' => $reactivate_id,
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
		// Wspólny scorer (to samo źródło prawdy, którego używa weryfikator w tle
		// przy re-scoringu po potwierdzeniu VAT/statusu). Przy async cache-miss
		// vat_valid/company_status są null → score prowizoryczny (bez bonusu VAT),
		// korygowany w tle po weryfikacji.
		return MP_Lead_Scoring::calculate(
			array(
				'vat_valid'         => $context->get( 'vat_valid' ),
				'company_status'    => $context->get( 'company_status' ),
				'phone'             => $context->get( 'phone', '' ),
				'consent_marketing' => $context->get( 'consent_marketing' ),
				'segment'           => $context->get( 'segment' ),
			)
		);
	}

	/**
	 * Przypisanie handlowca (użytkownik z rolą mp_handlowiec).
	 *
	 * @param string $nip NIP (deterministyczny wybór).
	 * @return int|null
	 */
	protected function assign_salesman( $nip ) {
		$users = get_users(
			array(
				'role'   => 'mp_handlowiec',
				'fields' => 'ID',
			)
		);
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

		// Stan weryfikacji VAT/statusu. W trybie async cache-miss (vat_pending) →
		// zapisujemy leada jako 'pending'; weryfikator w tle rozstrzygnie i skoryguje
		// score. Cache-hit lub tryb synchroniczny → 'checked' od razu.
		$vat_valid      = $context->get( 'vat_valid' );
		$company_status = $context->get( 'company_status' );
		$vat_pending    = ( $context->get( 'vat_pending' ) || $context->get( 'company_status_pending' ) );

		/*
		 * F2 (bramka integracyjna): przy rozstrzygniętej weryfikacji rozróżniamy
		 * POTWIERDZONY ważny numer ('valid') od sprawdzenia bez potwierdzenia
		 * ('checked'). To samo rozróżnienie robi weryfikator w tle — patrz
		 * class-mp-vat-verifier.php. Bez niego wtyczka 2 nie mogła odróżnić leada
		 * z ważnym VAT UE i odwrotne obciążenie było ze ścieżki leada nieosiągalne.
		 */

		/*
		 * `null` ZNACZY „NIE WIEMY", A NIE „SPRAWDZONE".
		 *
		 * Gałąź `else` łapała wcześniej dwie różne rzeczy naraz: potwierdzone
		 * `false` (sprawdzono, numer nieważny) i `null` (weryfikacja się NIE
		 * rozstrzygnęła). W trybie synchronicznym `resolve_vies()` zwraca właśnie
		 * `null` przy awarii VIES albo odpowiedzi bez `isValid` — i to BEZ flagi
		 * `vat_pending`, bo ta powstaje tylko przy cache-missie w trybie async.
		 *
		 * Skutek był trwały: lead dostawał `vat_status = 'checked'` i znacznik
		 * czasu sprawdzenia, więc dla wtyczki 2, raportów i weryfikatora w tle
		 * wyglądał na rozstrzygniętego. Weryfikator bierze WYŁĄCZNIE wiersze
		 * ze statusem `pending` (class-mp-vat-verifier.php), a `vat_attempts`
		 * zapisujemy tu jako 0 — nikt więc nigdy nie wracał do tego numeru, mimo
		 * że jedyne, co o nim wiadomo, to że nic nie wiadomo.
		 */
		if ( $vat_pending || is_null( $vat_valid ) ) {
			$vat_status = 'pending';
		} elseif ( true === $vat_valid ) {
			$vat_status = 'valid';
		} else {
			$vat_status = 'checked';
		}

		$lead_data = array(
			'company_name'         => $company,
			'nip'                  => $nip,
			'email'                => $email,
			'phone'                => ( '' !== trim( (string) $context->get( 'phone', '' ) ) ) ? (string) $context->get( 'phone' ) : null,
			'country'              => (string) $context->get( 'country', 'PL' ),
			'segment'              => ( '' !== trim( (string) $context->get( 'segment', '' ) ) ) ? (string) $context->get( 'segment' ) : null,
			'client_category'      => ( '' !== trim( (string) $context->get( 'client_category', '' ) ) ) ? (string) $context->get( 'client_category' ) : null,
			'products'             => ( '' !== trim( (string) $context->get( 'products', '' ) ) ) ? (string) $context->get( 'products' ) : null,
			'est_volume'           => ( '' !== trim( (string) $context->get( 'est_volume', '' ) ) ) ? (string) $context->get( 'est_volume' ) : null,
			'salesman_id'          => $salesman,
			// GMT, jak każda inna kolumna datetime w tej tabeli (patrz class-mp-db.php:
			// updated_at, vat_checked_at). Dotąd jedyna szła czasem LOKALNYM witryny
			// i siedziała w wierszu tuż obok vat_checked_at w GMT — dwie kolumny tego
			// samego wiersza w dwóch strefach, nieporównywalne między sobą.
			'salesman_assigned_at' => $salesman ? current_time( 'mysql', true ) : null,
			'score'                => $score,
			'status'               => 'new',
			'vat_valid'            => is_null( $vat_valid ) ? null : ( $vat_valid ? 1 : 0 ),
			'company_status'       => ( '' !== trim( (string) $company_status ) ) ? (string) $company_status : null,
			'vat_status'           => $vat_status,
			// Świeży cykl weryfikacji dla KAŻDEGO zgłoszenia (także reaktywacji powracającej
			// firmy) — inaczej stare vat_attempts z zarchiwizowanego leada wypchnęłyby status
			// od razu do 'unknown', pomijając weryfikację w tle.
			'vat_attempts'         => 0,
			// GMT — spójne z tą samą kolumną zapisywaną w class-mp-vat-verifier.php.
			// Znacznik idzie za STATUSEM, nie za samą flagą `vat_pending`: data
			// sprawdzenia przy nierozstrzygniętej weryfikacji byłaby datą czegoś,
			// co się nie odbyło.
			'vat_checked_at'       => 'pending' === $vat_status ? null : current_time( 'mysql', true ),
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
				'vat_status'  => $vat_status,
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

		$reactivate_id = (int) $context->get( 'reactivate_lead_id', 0 );
		if ( $reactivate_id > 0 ) {
			// Powracająca firma (zarchiwizowany NIP) — reaktywacja zamiast INSERT.
			$lead_id = MP_Lead_Intake_DB::reactivate_lead( $reactivate_id, $lead_data );
		} else {
			$lead_id = MP_Lead_Intake_DB::insert_lead( $lead_data );
		}

		if ( ! $lead_id ) {
			return MP_Result::fail( 'Nie udało się utworzyć leada', array(), 'insert_failed' );
		}

		return MP_Result::ok(
			array(
				'lead_id'          => $lead_id,
				'status'           => 'new',
				'created_at'       => current_time( 'mysql', true ),
				'lead_reactivated' => ( $reactivate_id > 0 ),
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
