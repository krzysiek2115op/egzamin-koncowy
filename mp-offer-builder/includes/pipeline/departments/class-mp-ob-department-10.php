<?php
/**
 * Dział 10 — Zapis, jedna transakcja.
 *
 * Objęty JEDNĄ transakcją DB: `MP_OB_Pipeline_Factory::make()` woła
 * `set_transactional_from(10)->set_transactional_until(10)` — pipeline sam
 * wykonuje START TRANSACTION przed tym działem i COMMIT/ROLLBACK zaraz po
 * nim (patrz docblock `MP_OB_Pipeline::set_transactional_until()`), więc
 * AGENCI TEGO DZIAŁU NIE wołają SQL-owego START/COMMIT/ROLLBACK sami —
 * tylko wykonują właściwe INSERT/UPDATE; ewentualny FAIL uruchamia ROLLBACK
 * na poziomie pipeline'u automatycznie. Zawartość pliku (1 plik = 1 dział):
 *  - Agent 10.1 (plan)       — komplet operacji + walidacja zgodności z DDL
 *  - Agent 10.2 (transakcja) — INSERT/UPDATE nagłówka+pozycji+wersji; RETRY
 *                              lokalny przy kolizji UNIQUE(offer_number, version)
 *  - Agent 10.3 (dziennik)   — zdarzenie offer.created/offer.versioned, przed/po
 *  - QA Agent 10               — atomowość (wiersze = plan, bez PDF-sierot)
 *  - MP_OB_Department_10        — budowniczy działu
 *
 * NAZWA DOCELOWA PLIKU PDF (rename tmp→final) celowo NIE dzieje się tutaj —
 * ten dział kończy się PRZED faktycznym SQL COMMIT (patrz wyżej), a gate
 * Działu 9 wymaga: "nazwa docelowa po COMMIT". Finalizację pliku wykonuje
 * Agent 11.1 (Dział 11 startuje dopiero PO commit — patrz docblock tamtego
 * działu).
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - docs/dzial-10/dbdelta-i-transakcje.md (limity DDL, COMMIT/ROLLBACK)
 *  - docs/dzial-08/mysql-unique-index.md (UNIQUE(offer_number, version), już cytowane w Dziale 8)
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 10.1 — plan: komplet operacji (nagłówek, pozycje, wersja, dziennik),
 * zwalidowany pod kątem limitów/typów ze schematu DDL — ZANIM cokolwiek
 * dotknie bazy.
 */
class MP_OB_D10_Agent_Plan extends MP_OB_Abstract_Agent {

	/** Jedyny dozwolony status z tego pipeline'u — plugin 3 włada dalszymi statusami (decyzja B). */
	const ALLOWED_STATUSES = array( 'draft' );

	/** Limity długości pól tekstowych — lustro schematu w class-mp-offer-builder-db.php. */
	const FIELD_LIMITS = array(
		'offer_number'      => 30,
		'lang'              => 5,
		'client_name'       => 191,
		'client_email'      => 191,
		'client_nip'        => 20,
		'client_country'    => 2,
		'client_vat_status' => 20,
		'currency'          => 3,
		'tax_mechanism'     => 20,
		'pdf_path'          => 255,
		'pdf_sha256'        => 64,
		'request_id'        => 36,
	);

	public function __construct() {
		parent::__construct( '10.1', 'Agent 10.1 — plan', 'Komplet operacji: nagłówek, pozycje, wersja (pełny stan data_json), dziennik' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$client       = is_array( $context->get( 'client' ) ) ? $context->get( 'client' ) : array();
		$items        = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();
		$lines        = is_array( $context->get( 'lines' ) ) ? $context->get( 'lines' ) : array();
		$pdf          = is_array( $context->get( 'pdf' ) ) ? $context->get( 'pdf' ) : array();
		$numbering    = is_array( $context->get( 'numbering' ) ) ? $context->get( 'numbering' ) : array();
		$offer_number = (string) $context->get( 'offer_number', '' );
		$version      = (int) $context->get( 'version', 0 );
		$pdf_path     = MP_Offer_Builder_Storage::final_pdf_path( $offer_number, $version );

		// Właściciel oferty (Krok 4, decyzja klienta): NOWA oferta / pierwsze dokończenie
		// draftu bez właściciela -> bieżący użytkownik; draft z JUŻ USTAWIONYM created_by
		// (Dział 2, Agent 2.5, TEN SAM odczyt co existing_offer_number) -> zachowany bez
		// zmian — pierwszy handlowiec zostaje właścicielem na stałe, korekty go nie podmieniają.
		$existing_created_by = isset( $numbering['existing_created_by'] ) ? $numbering['existing_created_by'] : null;
		$created_by          = null !== $existing_created_by ? (int) $existing_created_by : get_current_user_id();

		// Blokada optymistyczna: token CELOWO NIEZALEŻNY od `version` (numeru
		// wersji BIZNESOWEJ, Dział 8) — patrz docblock kolumny `lock_version`
		// w class-mp-offer-builder-db.php ("version" dla pierwszego ponumerowania
		// draftu jest zawsze 1, więc jako token blokady nie wykrywałby zapisu
		// konkurenta). `lock_version` rośnie bezwarunkowo przy KAŻDYM zapisie.
		$existing_lock_version = isset( $numbering['existing_lock_version'] ) ? (int) $numbering['existing_lock_version'] : null;
		$new_lock_version      = null !== $existing_lock_version ? $existing_lock_version + 1 : 1;

		// Obrona w głąb przeciw IDOR: Dział 1 już blokuje zapis cudzej oferty PRZED
		// uruchomieniem reszty pipeline'u, ale Dział 10 (jedyny dział z prawem zapisu)
		// NIE POWINIEN ufać ślepo, że ta kontrola na pewno zaszła wcześniej — sprawdzamy
		// własność jeszcze raz, tuż przed zapisem, niezależnym odczytem z Działu 2.
		if ( null !== $existing_created_by && get_current_user_id() !== (int) $existing_created_by && ! current_user_can( 'manage_options' ) ) {
			return MP_OB_Result::fail( 'Brak uprawnień do zapisu wskazanej oferty.', array(), 'not_offer_owner' );
		}

		$header = array(
			'offer_number'      => $offer_number,
			'version'           => $version,
			'status'            => 'draft',
			'lang'              => (string) $context->get( 'lang', '' ),
			'client_name'       => isset( $client['name'] ) ? (string) $client['name'] : '',
			'client_email'      => isset( $client['email'] ) ? (string) $client['email'] : '',
			'client_nip'        => isset( $client['nip'] ) ? (string) $client['nip'] : '',
			'client_country'    => isset( $client['country'] ) ? (string) $client['country'] : '',
			'client_vat_status' => isset( $client['vat_status'] ) ? (string) $client['vat_status'] : '',
			'net_grosze'        => (int) $context->get( 'net_grosze', 0 ),
			'vat_grosze'        => (int) $context->get( 'vat_grosze', 0 ),
			'gross_grosze'      => (int) $context->get( 'gross_grosze', 0 ),
			'currency'          => (string) $context->get( 'currency', 'PLN' ),
			'tax_mechanism'     => (string) $context->get( 'tax_mechanism', '' ),
			'pdf_path'          => $pdf_path,
			'pdf_sha256'        => isset( $pdf['sha256'] ) ? (string) $pdf['sha256'] : '',
			'request_id'        => (string) $context->get( 'request_id', '' ),
			'created_by'        => $created_by,
			'lock_version'      => $new_lock_version,
			// Jawnie ustawiane na KAŻDYM zapisie (schemat NIE ma ON UPDATE
			// CURRENT_TIMESTAMP) — przy okazji gwarantuje, że UPDATE zawsze
			// realnie zmienia przynajmniej jedną kolumnę, więc "0 wierszy
			// zmienionych" w Agencie 10.2 jednoznacznie znaczy "WHERE nie
			// trafił" (blokada optymistyczna), nie "wartości identyczne".
			'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
		);

		$offer_id = (int) $context->get( 'offer_id', 0 );
		if ( $offer_id > 0 ) {
			// Dokończenie draftu z Kroku 2.5 (Dział 1, offer_mode='draft') — UPDATE, nie INSERT.
			$header['id'] = $offer_id;
		}

		// Stawka VAT per pozycja z Działu 6 (wg klasy podatkowej pozycji). Fallback do
		// jednej stawki oferty tylko gdy brak mapy (np. reverse_charge/out_of_scope=0.00).
		$line_tax_rates = is_array( $context->get( 'line_tax_rates' ) ) ? $context->get( 'line_tax_rates' ) : array();
		$item_rows      = array();
		foreach ( $items as $i => $item ) {
			$item_rows[] = array(
				'product_id'         => (int) ( isset( $item['product_id'] ) ? $item['product_id'] : 0 ),
				'variation_id'       => ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : null,
				'qty'                => (int) ( isset( $item['qty'] ) ? $item['qty'] : 0 ),
				'price_base_grosze'  => (int) ( isset( $lines[ $i ]['unit_grosze'] ) ? $lines[ $i ]['unit_grosze'] : 0 ),
				'discount_grosze'    => 0, // rabat naliczany NA SUMIE (Dział 5), nie per-pozycja — patrz docs/dzial-05.
				'price_final_grosze' => (int) ( isset( $lines[ $i ]['line_grosze'] ) ? $lines[ $i ]['line_grosze'] : 0 ),
				'tax_rate'           => isset( $line_tax_rates[ $i ] ) ? (float) $line_tax_rates[ $i ] : (float) $context->get( 'tax_rate', 0 ),
			);
		}

		$mode         = (string) $context->get( 'numbering_mode', '' );
		$before_after = array(
			'before' => array(
				'offer_number' => isset( $numbering['existing_offer_number'] ) ? $numbering['existing_offer_number'] : null,
				'version'      => isset( $numbering['existing_version'] ) ? $numbering['existing_version'] : null,
			),
			'after'  => array(
				'offer_number' => $offer_number,
				'version'      => $version,
			),
		);

		$version_row = array(
			'version_number' => $version,
			'data_json'      => wp_json_encode( $context->all() ),
			'pdf_path'       => $pdf_path,
			'created_by'     => get_current_user_id(),
			'change_log'     => 'correction' === $mode ? 'Korekta oferty.' : 'Utworzenie oferty.',
		);

		$log_row = array(
			'action'      => 'correction' === $mode ? 'offer.versioned' : 'offer.created',
			'description' => sprintf( 'Oferta %s, wersja %d.', $offer_number, $version ),
			'user_id'     => get_current_user_id(),
			'meta_json'   => wp_json_encode( $before_after ),
		);

		$errors = array();
		foreach ( self::FIELD_LIMITS as $field => $max ) {
			if ( isset( $header[ $field ] ) && strlen( (string) $header[ $field ] ) > $max ) {
				$errors[] = array(
					'field'   => "header.$field",
					'message' => sprintf( 'Pole %s przekracza limit %d znaków (DDL).', $field, $max ),
				);
			}
		}
		if ( ! in_array( $header['status'], self::ALLOWED_STATUSES, true ) ) {
			$errors[] = array(
				'field'   => 'header.status',
				'message' => 'Status spoza słownika dozwolonych wartości.',
			);
		}
		if ( empty( $item_rows ) ) {
			$errors[] = array(
				'field'   => 'items',
				'message' => 'Plan zapisu bez żadnej pozycji — nie istnieje oferta bez pozycji.',
			);
		}

		if ( $errors ) {
			return MP_OB_Result::fail( 'Plan zapisu niezgodny z ograniczeniami schematu (DDL).', array( 'errors' => $errors ), 'ddl_violation' );
		}

		return MP_OB_Result::ok(
			array(
				'write_plan' => array(
					'header'                => $header,
					'items'                 => $item_rows,
					'version'               => $version_row,
					'log'                   => $log_row,
					// Blokada optymistyczna (Agent 10.2): lock_version ODCZYTANY
					// w Dziale 2 (Agent 2.5, "jeden odczyt") — null tylko gdy to
					// NOWA oferta (offer_id=0, wtedy INSERT, nic do zablokowania).
					'expected_lock_version' => $offer_id > 0 ? $existing_lock_version : null,
				),
			)
		);
	}
}

/**
 * Krytyk 10.1 — zgodność-z-DDL: plan ma wszystkie cztery sekcje (defense-in-
 * depth, niezależnie od walidacji długości już wykonanej w Agencie 10.1).
 */
class MP_OB_D10_Critic_DDL extends MP_OB_Abstract_Critic {

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
		$plan = isset( $data['write_plan'] ) && is_array( $data['write_plan'] ) ? $data['write_plan'] : array();
		foreach ( array( 'header', 'items', 'version', 'log' ) as $section ) {
			if ( empty( $plan[ $section ] ) ) {
				return MP_OB_Result::fail( sprintf( 'Plan zapisu niekompletny: brak sekcji "%s".', $section ), array(), 'incomplete_write_plan' );
			}
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * Agent 10.2 — transakcja: INSERT/UPDATE nagłówka + INSERT pozycji + INSERT
 * wersji. Kolizja UNIQUE(offer_number, version) → RETRY LOKALNY (kolejny numer/
 * wersja liczone W PAMIĘCI z numeru, który skolidował, + ponowny render PDF,
 * żeby metadane zgadzały się z nowym numerem), maks. MAX_ATTEMPTS podejść; inny
 * błąd zapisu → FAIL (pipeline robi ROLLBACK).
 */
class MP_OB_D10_Agent_Transaction extends MP_OB_Abstract_Agent {

	/** Maksymalna liczba podejść przy kolizji numeracji (obsługuje N równoległych zapisów). */
	const MAX_ATTEMPTS = 5;

	public function __construct() {
		parent::__construct( '10.2', 'Agent 10.2 — transakcja', 'INSERT-y nagłówka+pozycji+wersji; kolizja UNIQUE — RETRY lokalny (kolejny numer/wersja + ponowny render), wiele podejść' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		global $wpdb;

		$plan = is_array( $context->get( 'write_plan' ) ) ? $context->get( 'write_plan' ) : array();
		if ( empty( $plan['header'] ) || empty( $plan['items'] ) || empty( $plan['version'] ) ) {
			return MP_OB_Result::fail( 'Plan zapisu niekompletny.', array(), 'incomplete_write_plan' );
		}

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$header    = $plan['header'];
			$offer_id  = isset( $header['id'] ) ? (int) $header['id'] : 0;
			$is_update = $offer_id > 0;
			unset( $header['id'] );

			if ( $offer_id > 0 ) {
				$update_where = array( 'id' => $offer_id );
				if ( null !== $plan['expected_lock_version'] ) {
					// Blokada optymistyczna: WHERE zawiera lock_version ODCZYTANY w
					// Dziale 2, NIGDY biznesowy `version` (ten dla pierwszego
					// ponumerowania draftu jest zawsze 1 — patrz docblock Agenta 10.1
					// i kolumny lock_version w class-mp-offer-builder-db.php).
					// `lock_version` w $header (nowy = stary+1) gwarantuje, że
					// dopasowany wiersz ZAWSZE realnie się zmienia, więc 0 wierszy
					// dotkniętych jednoznacznie znaczy "WHERE nie trafił" — ktoś
					// inny zapisał tę ofertę pomiędzy odczytem (Dział 2) a tym zapisem.
					$update_where['lock_version'] = (int) $plan['expected_lock_version'];
				}
				$update_result = $wpdb->update( MP_Offer_Builder_DB::offers_table(), $header, $update_where );
				if ( 0 === $update_result && isset( $update_where['lock_version'] ) ) {
					return MP_OB_Result::fail(
						'Oferta została zmieniona przez innego użytkownika w międzyczasie. Odśwież i spróbuj ponownie.',
						array(),
						'concurrent_modification'
					);
				}
				$ok = false !== $update_result;
			} else {
				$ok = false !== $wpdb->insert( MP_Offer_Builder_DB::offers_table(), $header );
				if ( $ok ) {
					$offer_id = (int) $wpdb->insert_id;
				}
			}

			if ( ! $ok ) {
				// S1-2: kolizja UNIQUE(request_id) — inne RÓWNOLEGŁE żądanie z tym samym
				// request_id zapisało ofertę pomiędzy pre-gate (AJAX) a tym INSERT-em.
				// Idempotencja (kryt. Działu 1: "ten sam request_id nigdy nie tworzy
				// drugiej oferty"): przerywamy kodem 'idempotent_replay' — pipeline robi
				// ROLLBACK (kasuje nasz nadmiarowy tmp PDF) i NIE wchodzi w Dział 11, więc
				// mp_offer_created NIE odpala się drugi raz. Warstwa AJAX zwraca wtedy dane
				// ISTNIEJĄCEJ oferty (get_offer_by_request_id), dokładnie jak pre-gate.
				if ( false !== strpos( (string) $wpdb->last_error, 'uq_request_id' ) ) {
					return MP_OB_Result::fail(
						'Oferta dla tego żądania już istnieje (idempotencja).',
						array( 'request_id' => isset( $header['request_id'] ) ? (string) $header['request_id'] : '' ),
						'idempotent_replay'
					);
				}
				$is_collision = false !== strpos( (string) $wpdb->last_error, 'uq_offer_number_version' );
				if ( $is_collision && $attempt < self::MAX_ATTEMPTS ) {
					$retried_plan = self::retry_after_collision( $context, $plan );
					if ( null === $retried_plan ) {
						return MP_OB_Result::fail( 'Ponowny render PDF po kolizji numeracji nie powiódł się.', array(), 'retry_render_failed' );
					}
					$plan = $retried_plan;
					continue;
				}
				return MP_OB_Result::fail(
					'Zapis nagłówka oferty nie powiódł się: ' . $wpdb->last_error,
					array(),
					$is_collision ? 'numbering_collision_unresolved' : 'write_failed'
				);
			}

			$affected_rows = 1; // nagłówek.

			if ( $is_update ) {
				// Korekta: pozycje z POPRZEDNIEJ wersji tej samej oferty muszą zniknąć
				// PRZED wstawieniem nowych — inaczej każda korekta tylko DOPISYWAŁABY
				// wiersze do wp_mp_ob_offer_items, dublując pozycje z poprzednich wersji
				// (offer_items nie ma kolumny version — jeden komplet na offer_id).
				// Sprawdzone jak każdy inny zapis w tej metodzie: niesprawdzony DELETE
				// przy awarii SQL zostawiłby stare pozycje NA MIEJSCU, a nowe i tak
				// zostałyby dopisane obok nich — cichy powrót Critical#2 pod inną postacią.
				if ( false === $wpdb->delete( MP_Offer_Builder_DB::items_table(), array( 'offer_id' => $offer_id ) ) ) {
					return MP_OB_Result::fail( 'Usunięcie starych pozycji oferty nie powiodło się: ' . $wpdb->last_error, array(), 'write_failed' );
				}
			}

			foreach ( $plan['items'] as $item_row ) {
				$item_row['offer_id'] = $offer_id;
				// Niesprawdzony INSERT tutaj czyniłby kontrolę atomowości QA Agenta 10
				// tautologiczną (affected_rows rósłby "na sucho", niezależnie od tego,
				// czy wiersz REALNIE powstał) — patrz docblock tamtego agenta.
				if ( false === $wpdb->insert( MP_Offer_Builder_DB::items_table(), $item_row ) ) {
					return MP_OB_Result::fail( 'Zapis pozycji oferty nie powiódł się: ' . $wpdb->last_error, array(), 'write_failed' );
				}
				++$affected_rows;
			}

			$version_row             = $plan['version'];
			$version_row['offer_id'] = $offer_id;
			if ( false === $wpdb->insert( MP_Offer_Builder_DB::versions_table(), $version_row ) ) {
				return MP_OB_Result::fail( 'Zapis wersji oferty nie powiódł się: ' . $wpdb->last_error, array(), 'write_failed' );
			}
			++$affected_rows;

			return MP_OB_Result::ok(
				array(
					'offer_id'      => $offer_id,
					'offer_number'  => $header['offer_number'],
					'version'       => $header['version'],
					'affected_rows' => $affected_rows,
					'db_writes'     => 1,
					// Plan mógł się zmienić przy retry (nowy numer/wersja/pdf_path) —
					// Agent 10.3 (dziennik) musi zapisać AKTUALNĄ, nie pierwotną wersję.
					'write_plan'    => $plan,
				)
			);
		}

		return MP_OB_Result::fail( 'Nie udało się zapisać oferty po dostępnych próbach.', array(), 'write_failed' );
	}

	/**
	 * Kolizja UNIQUE(offer_number, version): porzuca stary plik tymczasowy PDF
	 * (metadane wskazują STARY, skolidowany numer), wylicza nowego kandydata
	 * (świeży odczyt BD-2 — Dział 10 to dział ZAPISU, "jeden odczyt" dotyczy
	 * tylko działów 3-9), renderuje PDF PONOWNIE (metadane muszą zgadzać się
	 * z nowym numerem) i aktualizuje plan.
	 *
	 * @param MP_OB_Context $context Kontekst (aktualizowany w miejscu).
	 * @param array         $plan    Plan zapisu (poprzednia wersja).
	 * @return array|null Zaktualizowany plan, albo null gdy ponowny render się nie powiódł
	 *                     (wywołujący MUSI wtedy przerwać — plan z NOWYM numerem/wersją,
	 *                     ale STARYM (już skasowanym) pdf_path/pdf_sha256 byłby niespójny).
	 */
	private static function retry_after_collision( MP_OB_Context $context, array $plan ) {
		$old_pdf = is_array( $context->get( 'pdf' ) ) ? $context->get( 'pdf' ) : array();
		if ( ! empty( $old_pdf['tmp_path'] ) ) {
			MP_Offer_Builder_Storage::delete_tmp( $old_pdf['tmp_path'] );
		}

		if ( 'correction' === (string) $context->get( 'numbering_mode', '' ) ) {
			$new_version = (int) $plan['header']['version'] + 1;
		} else {
			// Kolejny numer liczymy z numeru, KTÓRY WŁAŚNIE SKOLIDOWAŁ (+1) —
			// w PAMIĘCI, a nie przez ponowny odczyt MAX z bazy. Pod REPEATABLE READ
			// (domyślny poziom izolacji InnoDB) zwykły SELECT wewnątrz otwartej
			// transakcji Działu 10 czyta ze snapshotu sprzed startu transakcji i
			// zwróciłby TEN SAM stary numer → druga kolizja i porażka retry.
			// Inkrement w pamięci jest niezależny od snapshotu i deterministyczny;
			// przy N równoległych zapisach kolejne próby (MAX_ATTEMPTS) rozwiązują
			// kolizję, każda podbijając numer o 1.
			$year = (int) gmdate( 'Y' );
			$seq  = 1; // ostateczny fallback: pierwsza oferta (np. przełom roku, brak ofert w nowym roku).
			if ( 1 === preg_match( '/^OF\/' . $year . '\/(\d{6})$/', (string) $plan['header']['offer_number'], $m ) ) {
				$seq = ( (int) $m[1] ) + 1;
			} else {
				// Numer z innego roku / nietypowy → bezpieczny fallback: świeży odczyt.
				$fresh_last = MP_Offer_Builder_DB::get_last_offer_number_for_year( $year );
				if ( $fresh_last && 1 === preg_match( '/^OF\/' . $year . '\/(\d{6})$/', $fresh_last, $mm ) ) {
					$seq = ( (int) $mm[1] ) + 1;
				}
			}
			$plan['header']['offer_number'] = sprintf( 'OF/%d/%06d', $year, $seq );
			$new_version                    = 1;
		}
		$plan['header']['version']         = $new_version;
		$plan['version']['version_number'] = $new_version;

		$context->set( 'offer_number', $plan['header']['offer_number'] );
		$context->set( 'version', $new_version );

		$render_result = ( new MP_OB_D9_Agent_Render() )->run( $context );
		if ( ! $render_result->is_ok() ) {
			// Bez tego STOP-u wywołujący dostałby plan z NOWYM offer_number/version
			// (już zapisanym wyżej), ale ze STARYM pdf_path/pdf_sha256 wskazującym
			// na plik, który dopiero co skasowaliśmy (delete_tmp powyżej) — próba
			// zapisu takiego planu tworzyłaby ofertę z martwym wskaźnikiem na PDF.
			return null;
		}

		$render_data = $render_result->get_data();
		$context->set( 'pdf', $render_data['pdf'] );
		$new_pdf_path                 = MP_Offer_Builder_Storage::final_pdf_path( $plan['header']['offer_number'], $new_version );
		$plan['header']['pdf_path']   = $new_pdf_path;
		$plan['header']['pdf_sha256'] = file_exists( $render_data['pdf']['tmp_path'] ) ? hash_file( 'sha256', $render_data['pdf']['tmp_path'] ) : '';
		$plan['version']['pdf_path']  = $new_pdf_path;
		// S6-02: data_json wersji to PEŁNY snapshot stanu — po retry musi
		// odzwierciedlać AKTUALNY numer/wersję/pdf (kontekst zaktualizowany powyżej),
		// inaczej historia wersji utrwaliłaby stan sprzed kolizji. write_plan pomijamy
		// — to dane pochodne, nieobecne w pierwotnym data_json (Agent 10.1 liczył je
		// PRZED dopisaniem planu do kontekstu).
		$snapshot = $context->all();
		unset( $snapshot['write_plan'] );
		$plan['version']['data_json'] = wp_json_encode( $snapshot );

		return $plan;
	}
}

/**
 * Krytyk 10.2 — jeden-zapis: db_writes=1 i ID oferty potwierdzone (inaczej
 * "nie istnieje oferta bez pozycji ani wersji" nie da się zagwarantować).
 */
class MP_OB_D10_Critic_One_Write extends MP_OB_Abstract_Critic {

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
		if ( 1 !== ( isset( $data['db_writes'] ) ? $data['db_writes'] : null ) || empty( $data['offer_id'] ) ) {
			return MP_OB_Result::fail( 'Brak potwierdzenia jednego spójnego zapisu (db_writes=1) albo ID oferty.', array(), 'not_single_write' );
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * Agent 10.3 — dziennik: zdarzenie offer.created / offer.versioned, wartości
 * przed i po (odtwarzalne z samego dziennika, kryt. 5.5).
 */
class MP_OB_D10_Agent_Log extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '10.3', 'Agent 10.3 — dziennik', 'Zdarzenia offer.created / offer.versioned z wartościami przed i po' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		global $wpdb;

		$plan     = is_array( $context->get( 'write_plan' ) ) ? $context->get( 'write_plan' ) : array();
		$offer_id = (int) $context->get( 'offer_id', 0 );

		if ( $offer_id <= 0 || empty( $plan['log'] ) ) {
			return MP_OB_Result::fail( 'Brak oferty albo planu dziennika do zapisu logu.', array(), 'missing_log_plan' );
		}

		$log_row             = $plan['log'];
		$log_row['offer_id'] = $offer_id;
		if ( false === $wpdb->insert( MP_Offer_Builder_DB::activity_log_table(), $log_row ) ) {
			return MP_OB_Result::fail( 'Zapis wpisu dziennika nie powiódł się: ' . $wpdb->last_error, array(), 'write_failed' );
		}

		return MP_OB_Result::ok(
			array(
				'affected_rows' => ( (int) $context->get( 'affected_rows', 0 ) ) + 1,
			)
		);
	}
}

/**
 * Krytyk 10.3 — odtwarzalność: wpis dziennika ma wartości przed i po
 * (historia statusów/wersji odtwarzalna z samego dziennika, kryt. 5.5).
 */
class MP_OB_D10_Critic_Reproducibility extends MP_OB_Abstract_Critic {

	/**
	 * @param MP_OB_Result  $agent_result Wynik agenta.
	 * @param MP_OB_Context $context      Kontekst.
	 * @return MP_OB_Result
	 */
	public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$plan = is_array( $context->get( 'write_plan' ) ) ? $context->get( 'write_plan' ) : array();
		$meta = isset( $plan['log']['meta_json'] ) ? json_decode( (string) $plan['log']['meta_json'], true ) : null;

		if ( ! is_array( $meta ) || ! array_key_exists( 'before', $meta ) || ! array_key_exists( 'after', $meta ) ) {
			return MP_OB_Result::fail( 'Wpis dziennika bez wartości przed/po — historia nieodtwarzalna.', array(), 'log_not_reproducible' );
		}

		return MP_OB_Result::ok( $agent_result->get_data() );
	}
}

/**
 * QA Agent 10 — atomowość: liczba zapisanych wierszy zgodna z planem
 * (nagłówek + pozycje + wersja + dziennik), plik PDF tymczasowy wciąż na
 * dysku (gotowy do finalizacji w Dziale 11 — bez tego byłby "PDF-sierotą":
 * wiersz w bazie wskazujący na plik, który nigdy nie powstanie).
 */
class MP_OB_D10_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA10', 'QA Agent 10 — kontrola kompletności', 'Sprawdza atomowość: wiersze = plan, żadnych rekordów częściowych i PDF-sierot' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$plan     = is_array( $context->get( 'write_plan' ) ) ? $context->get( 'write_plan' ) : array();
		$expected = 1 + count( isset( $plan['items'] ) ? $plan['items'] : array() ) + 1 + 1; // nagłówek + pozycje + wersja + dziennik.
		$actual   = (int) $context->get( 'affected_rows', 0 );

		if ( $actual !== $expected ) {
			return MP_OB_Result::fail( sprintf( 'Liczba zapisanych wierszy (%d) niezgodna z planem (%d).', $actual, $expected ), array(), 'atomicity_mismatch' );
		}

		$pdf      = is_array( $context->get( 'pdf' ) ) ? $context->get( 'pdf' ) : array();
		$tmp_path = isset( $pdf['tmp_path'] ) ? (string) $pdf['tmp_path'] : '';
		if ( '' === $tmp_path || ! file_exists( $tmp_path ) ) {
			return MP_OB_Result::fail( 'Plik PDF tymczasowy zniknął przed finalizacją — ryzyko osieroconego rekordu.', array(), 'pdf_orphan_risk' );
		}

		return MP_OB_Result::ok( array( 'write_atomic' => true ) );
	}
}

/**
 * Budowniczy działu 10.
 */
class MP_OB_Department_10 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_D10_Agent_Plan(),
				'critic' => new MP_OB_D10_Critic_DDL( 'K10.1', 'Krytyk 10.1 — zgodność-z-DDL' ),
			),
			array(
				'agent'  => new MP_OB_D10_Agent_Transaction(),
				'critic' => new MP_OB_D10_Critic_One_Write( 'K10.2', 'Krytyk 10.2 — jeden-zapis' ),
			),
			array(
				'agent'  => new MP_OB_D10_Agent_Log(),
				'critic' => new MP_OB_D10_Critic_Reproducibility( 'K10.3', 'Krytyk 10.3 — odtwarzalność' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D10_QA_Agent(),
			new MP_OB_Accept_Critic( 'QAK10', 'QA Krytyk 10 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			10,
			'save-transaction',
			'Zapis — jedna transakcja',
			'Zapis nagłówka, pozycji, wersji i dziennika jedną transakcją DB; po ROLLBACK — tymczasowy PDF kasowany.',
			$pairs,
			$gate
		);
	}
}
