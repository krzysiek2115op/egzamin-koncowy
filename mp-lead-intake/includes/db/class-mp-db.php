<?php
/**
 * Warstwa bazy danych wtyczki MP Lead Intake — BD-3.
 *
 * Odpowiada za utworzenie, aktualizację i usunięcie trzech tabel:
 *   - {prefix}mp_leads         — leady (firmy zgłaszające zapytanie)
 *   - {prefix}mp_offers        — oferty powiązane z leadem
 *   - {prefix}mp_activity_log  — log zdarzeń (audyt: kto, co, kiedy)
 *
 * Relacja: lead -> oferta -> aktywność.
 *
 * @package MP_Lead_Intake
 */

// Blokada bezpośredniego wywołania.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasa zarządzająca schematem bazy danych BD-3.
 */
class MP_Lead_Intake_DB {

	/**
	 * Wersja schematu bazy. Podbijamy przy KAŻDEJ zmianie struktury tabel,
	 * żeby mechanizm migracji wiedział, że trzeba zaktualizować bazę.
	 */
	const DB_VERSION = '1.4.0';

	/** Nazwa opcji WordPress przechowującej aktualną wersję bazy. */
	const DB_VERSION_OPTION = 'mp_lead_intake_db_version';

	/** Retencja pełnego adresu IP w logu (dni). Po tym okresie IP jest usuwane (RODO). */
	const IP_RETENTION_DAYS = 90;

	/**
	 * Nazwa tabeli leadów (z prefiksem instalacji WP, np. wp_mp_leads).
	 *
	 * @return string
	 */
	public static function leads_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_leads';
	}

	/**
	 * Nazwa tabeli ofert (np. wp_mp_offers).
	 *
	 * @return string
	 */
	public static function offers_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_offers';
	}

	/**
	 * Nazwa tabeli logu aktywności (np. wp_mp_activity_log).
	 *
	 * @return string
	 */
	public static function activity_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_activity_log';
	}

	// --- Odczyt danych (używane m.in. przez Dział 1 pipeline). ---

	/**
	 * Zwraca aktywne (niezarchiwizowane) leady o podanym NIP + kraju.
	 *
	 * Klucz unikalności to (country, nip), nie sam nip — lokalne numery firmowe
	 * różnych krajów UE mogą się cyfrowo pokrywać (od 1.4.0, patrz DB_VERSION).
	 *
	 * NIEUDANY odczyt oddaje `null`, nie pustą tablicę. `wpdb::get_results()` na
	 * błędzie zwraca pustą tablicę i zostawia powód wyłącznie w `last_error`, więc
	 * bez tego rozróżnienia awaria bazy wygląda dokładnie tak samo jak „takiej
	 * firmy nie ma" — a to jedyna odpowiedź, której po nieudanym zapytaniu zgadywać
	 * nie wolno: na niej stoi dedup Działu 7.
	 *
	 * @param string $nip     NIP firmy.
	 * @param string $country Kod kraju ISO 3166-1 alpha-2 (np. 'PL').
	 * @return array|null Lista wierszy (ARRAY_A); pusta, gdy brak; null, gdy odczyt się nie udał.
	 */
	public static function get_leads_by_nip( $nip, $country ) {
		global $wpdb;
		$table = self::leads_table();

		// Zerowane PRZED zapytaniem, żeby po nim czytać błąd tego zapytania, a nie
		// ślad po poprzednim (realny wpdb robi to sam w query(); atrapa z harnessu
		// procesu — nie, a od tej wartości zależy dalej `leads_checked`).
		$wpdb->last_error = '';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM $table WHERE nip = %s AND country = %s AND deleted_at IS NULL", $nip, $country ),
			ARRAY_A
		);

		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Zwraca zarchiwizowanego (soft-deleted) leada o danym NIP + kraju, jeśli istnieje.
	 *
	 * Potrzebne, bo UNIQUE KEY uq_country_nip obejmuje też zarchiwizowane wiersze — bez
	 * tego firma raz zarchiwizowana nie mogłaby zgłosić się ponownie (INSERT biłby w UNIQUE).
	 *
	 * @param string $nip     NIP firmy.
	 * @param string $country Kod kraju ISO 3166-1 alpha-2 (np. 'PL').
	 * @return array|null Wiersz (ARRAY_A) lub null, gdy brak.
	 */
	public static function get_archived_lead_by_nip( $nip, $country ) {
		global $wpdb;
		$table = self::leads_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM $table WHERE nip = %s AND country = %s AND deleted_at IS NOT NULL ORDER BY id DESC LIMIT 1", $nip, $country ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Reaktywuje zarchiwizowanego leada: czyści deleted_at i nadpisuje dane
	 * bieżącym zgłoszeniem (nowe zapytanie od powracającej firmy).
	 *
	 * @param int   $id   Identyfikator istniejącego leada.
	 * @param array $data Nowe dane (kolumny wp_mp_leads).
	 * @return int|false ID leada lub false przy błędzie.
	 */
	public static function reactivate_lead( $id, array $data ) {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}
		$table = self::leads_table();

		// Atomowy "claim": zeruje deleted_at TYLKO jeśli wiersz nadal jest zarchiwizowany.
		// Chroni przed wyścigiem dwóch równoległych zgłoszeń dla tego samego, zarchiwizowanego
		// NIP (np. podwójne kliknięcie "wyślij") — bez tego oba żądania widziałyby ten sam
		// wiersz jako "do reaktywacji" i oba zakończyłyby się sukcesem, drugie cicho nadpisując
		// dane pierwszego (i podwójnie odpalając hook mp_lead_created w dziale 11). Świeży
		// INSERT ma analogiczną ochronę przez UNIQUE KEY(nip) — patrz insert_lead(); reaktywacja
		// (UPDATE, nie INSERT) potrzebowała własnej. Przegrany wyścigu dostaje affected_rows=0,
		// MP_D7_Agent_Create::run() to zwraca jako 'insert_failed' — ten sam STOP co przy
		// świeżym duplikacie NIP.
		$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "UPDATE $table SET deleted_at = NULL WHERE id = %d AND deleted_at IS NOT NULL", $id )
		);
		if ( 1 !== (int) $claimed ) {
			return false;
		}

		unset( $data['deleted_at'] ); // Już wyzerowane atomowym claimem powyżej.
		// GMT (nie lokalny czas WP) — updated_at jest porównywane w SQL w reset_stuck_vat()
		// z UTC_TIMESTAMP(); mieszanie stref dawało do ~2h błędnego okna bezczynności.
		$data['updated_at'] = current_time( 'mysql', true );
		if ( ! isset( $data['status'] ) || '' === $data['status'] ) {
			$data['status'] = 'new';
		}

		$ok = $wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return ( false !== $ok ) ? $id : false;
	}

	/**
	 * Zwraca oferty powiązane z podanymi lead_id.
	 *
	 * @param array $lead_ids Lista identyfikatorów leadów.
	 * @return array Lista wierszy (ARRAY_A).
	 */
	public static function get_offers_by_lead_ids( array $lead_ids ) {
		global $wpdb;
		if ( empty( $lead_ids ) ) {
			return array();
		}

		$table        = self::offers_table();
		$lead_ids     = array_map( 'absint', $lead_ids );
		$placeholders = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare( "SELECT * FROM $table WHERE lead_id IN ($placeholders)", $lead_ids ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Zwraca ostatnie wpisy historii aktywności dla podanych lead_id.
	 *
	 * @param array $lead_ids Lista identyfikatorów leadów.
	 * @param int   $limit    Maks. liczba wpisów (domyślnie 50).
	 * @return array Lista wierszy (ARRAY_A).
	 */
	public static function get_activity_by_lead_ids( array $lead_ids, $limit = 50 ) {
		global $wpdb;
		if ( empty( $lead_ids ) ) {
			return array();
		}

		$table        = self::activity_log_table();
		$lead_ids     = array_map( 'absint', $lead_ids );
		$limit        = absint( $limit );
		$placeholders = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );
		$params       = array_merge( $lead_ids, array( $limit ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare( "SELECT * FROM $table WHERE lead_id IN ($placeholders) ORDER BY created_at DESC LIMIT %d", $params ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// --- Zapis danych (używane m.in. przez Działy 7, 8, 9). ---

	/**
	 * Wstawia leada do wp_mp_leads.
	 *
	 * @param array $data Pary kolumna => wartość.
	 * @return int|false ID nowego leada lub false przy błędzie.
	 */
	public static function insert_lead( array $data ) {
		global $wpdb;
		$ok = $wpdb->insert( self::leads_table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Wstawia wpis do wp_mp_activity_log.
	 *
	 * @param array $data Pary kolumna => wartość.
	 * @return int|false ID wpisu lub false przy błędzie.
	 */
	public static function insert_activity( array $data ) {
		global $wpdb;
		$ok = $wpdb->insert( self::activity_log_table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : false;
	}

	// --- Weryfikacja VAT w tle (async dział 3): odczyt/claim/aktualizacja/reconcile. ---

	/**
	 * Zwraca pełny wiersz leada po ID (m.in. dla weryfikatora w tle).
	 *
	 * @param int $id Identyfikator leada.
	 * @return array|null Wiersz (ARRAY_A) lub null.
	 */
	public static function get_lead( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::leads_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Atomowo przejmuje leada do weryfikacji VAT: 'pending' → 'verifying'
	 * (+ inkrement licznika prób). Zwraca liczbę zajętych wierszy — 1 oznacza,
	 * że TEN wywołujący wygrał wyścig; 0 = ktoś inny już przejął / stan nie 'pending'
	 * (idempotencja przy równoległych cronach/reconcile).
	 *
	 * @param int $id Identyfikator leada.
	 * @return int Liczba zajętych wierszy (0 lub 1).
	 */
	public static function claim_vat_verification( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return 0;
		}
		$table = self::leads_table();

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE $table SET vat_status = 'verifying', vat_attempts = vat_attempts + 1, updated_at = %s WHERE id = %d AND vat_status = 'pending'",
				current_time( 'mysql', true ), // GMT — spójne z reset_stuck_vat()/UTC_TIMESTAMP().
				$id
			)
		);

		return (int) $affected;
	}

	/**
	 * Zapisuje wynik weryfikacji VAT (kolumny vat_*, score) dla leada.
	 *
	 * @param int   $id     Identyfikator leada.
	 * @param array $fields Pary kolumna => wartość (podzbiór kolumn wp_mp_leads).
	 * @return bool
	 */
	public static function update_vat_result( $id, array $fields ) {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 || empty( $fields ) ) {
			return false;
		}
		$fields['updated_at'] = current_time( 'mysql', true ); // GMT — jw.

		$ok = $wpdb->update( self::leads_table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return ( false !== $ok );
	}

	/**
	 * Zwraca ID leadów oczekujących na weryfikację VAT (vat_status='pending').
	 * Używane przez reconcile (siatka bezpieczeństwa cronu).
	 *
	 * @param int $limit Maks. liczba wierszy.
	 * @return array Lista wierszy (ARRAY_A) z kluczem 'id'.
	 */
	public static function get_leads_needing_vat( $limit = 100 ) {
		global $wpdb;
		$limit = max( 1, absint( $limit ) );
		$table = self::leads_table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id FROM $table WHERE vat_status = 'pending' AND deleted_at IS NULL ORDER BY id ASC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Odblokowuje „zawieszone" weryfikacje: 'verifying' starsze niż $minutes → 'pending'
	 * (worker padł w trakcie). Reconcile wraca do nich w następnym przebiegu.
	 *
	 * @param int $minutes Próg zawieszenia w minutach.
	 * @return int Liczba odblokowanych wierszy.
	 */
	public static function reset_stuck_vat( $minutes = 15 ) {
		global $wpdb;
		$minutes = max( 1, absint( $minutes ) );
		$table   = self::leads_table();

		// UTC_TIMESTAMP() (nie NOW()) — updated_at jest zapisywane w GMT (current_time('mysql', true)
		// w claim_vat_verification()/update_vat_result()/reactivate_lead()); NOW() zwraca czas
		// sesji MySQL, który zwykle NIE jest zsynchronizowany ze strefą WordPressa.
		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "UPDATE $table SET vat_status = 'pending' WHERE vat_status = 'verifying' AND updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d MINUTE )", $minutes )
		);

		return (int) $rows;
	}

	// --- RODO: anonimizacja i retencja adresów IP (dane osobowe). ---

	/**
	 * Anonimizuje adres IP (RODO / privacy by design): obcina część hosta,
	 * zostawiając tylko sieć. IPv4 → zerujemy ostatni oktet (203.0.113.55 →
	 * 203.0.113.0); IPv6 → zostają pierwsze 3 hextety (48 bitów). Zachowuje
	 * wartość do analizy nadużyć (podsieć), minimalizując dane. Wartość
	 * niepoprawna → pusty string.
	 *
	 * @param string $ip Adres IP.
	 * @return string Adres zanonimizowany ('' gdy niepoprawny).
	 */
	public static function anonymize_ip( $ip ) {
		$ip = trim( (string) $ip );
		if ( '' === $ip ) {
			return '';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed ) {
				$out = inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) );
				if ( false !== $out ) {
					return $out;
				}
			}
			return '';
		}
		return '';
	}

	/**
	 * Retencja RODO: usuwa (NULL-uje) adresy IP w logu starsze niż $days dni.
	 * Log audytowy pozostaje — znika tylko dana osobowa (IP). Uruchamiane cyklicznie
	 * (dzienny cron mp_lead_intake_ip_retention).
	 *
	 * @param int $days Okres retencji w dniach.
	 * @return int Liczba zaktualizowanych wierszy.
	 */
	public static function purge_old_ip_addresses( $days = self::IP_RETENTION_DAYS ) {
		global $wpdb;
		$table = self::activity_log_table();
		$days  = max( 1, absint( $days ) );

		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "UPDATE $table SET ip_address = NULL WHERE ip_address IS NOT NULL AND created_at < DATE_SUB( NOW(), INTERVAL %d DAY )", $days )
		);

		return (int) $rows;
	}

	/**
	 * Anonimizuje wpisy logu powiązane z leadem — żądanie RODO „prawo do bycia
	 * zapomnianym": usuwa IP z całej historii aktywności danego leada.
	 *
	 * @param int $lead_id Identyfikator leada.
	 * @return int Liczba zaktualizowanych wierszy.
	 */
	public static function anonymize_lead_ips( $lead_id ) {
		global $wpdb;
		$lead_id = absint( $lead_id );
		if ( $lead_id <= 0 ) {
			return 0;
		}
		$table = self::activity_log_table();

		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "UPDATE $table SET ip_address = NULL WHERE lead_id = %d", $lead_id )
		);

		return (int) $rows;
	}

	/**
	 * Usuwa wszystkie transienty wtyczki (rate-limit, cache VIES/Biała lista,
	 * throttling powiadomień). Wywoływane przy deinstalacji — czyste sprzątanie.
	 *
	 * @return void
	 */
	public static function delete_transients() {
		global $wpdb;
		$like_val = $wpdb->esc_like( '_transient_mp_' ) . '%';
		$like_to  = $wpdb->esc_like( '_transient_timeout_mp_' ) . '%';

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like_val, $like_to )
		);
	}

	/**
	 * Tworzy lub aktualizuje wszystkie tabele BD-3.
	 *
	 * Uruchamiane przy aktywacji wtyczki (oraz przez maybe_upgrade() po
	 * aktualizacji kodu). Korzysta z dbDelta(), które bezpiecznie tworzy
	 * brakujące tabele i dokłada brakujące kolumny — nie kasuje danych.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$leads           = self::leads_table();
		$offers          = self::offers_table();
		$log             = self::activity_log_table();

		// --- Tabela 1: leady (firmy) ---
		$sql_leads = "CREATE TABLE $leads (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_name varchar(255) NOT NULL,
			nip varchar(20) NOT NULL,
			email varchar(190) NOT NULL,
			phone varchar(30) DEFAULT NULL,
			country char(2) DEFAULT NULL,
			segment varchar(100) DEFAULT NULL,
			client_category varchar(50) DEFAULT NULL,
			products text DEFAULT NULL,
			est_volume varchar(100) DEFAULT NULL,
			salesman_id bigint(20) unsigned DEFAULT NULL,
			salesman_assigned_at datetime DEFAULT NULL,
			score int(11) NOT NULL DEFAULT 0,
			status varchar(30) NOT NULL DEFAULT 'new',
			vat_valid tinyint(1) DEFAULT NULL,
			company_status varchar(30) DEFAULT NULL,
			vat_status varchar(20) NOT NULL DEFAULT 'checked',
			vat_checked_at datetime DEFAULT NULL,
			vat_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			consent_marketing tinyint(1) NOT NULL DEFAULT 0,
			consent_rodo tinyint(1) NOT NULL DEFAULT 0,
			consent_marketing_at datetime DEFAULT NULL,
			consent_rodo_at datetime DEFAULT NULL,
			consent_version varchar(20) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			deleted_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_country_nip (country, nip),
			KEY email (email),
			KEY status (status),
			KEY vat_status (vat_status),
			KEY salesman_id (salesman_id),
			KEY deleted_at (deleted_at)
		) ENGINE=InnoDB $charset_collate;";

		// --- Tabela 2: oferty ---
		$sql_offers = "CREATE TABLE $offers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			offer_number varchar(50) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'draft',
			total_amount decimal(12,2) NOT NULL DEFAULT 0.00,
			currency char(3) NOT NULL DEFAULT 'PLN',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_offer_number (offer_number),
			KEY lead_id (lead_id)
		) ENGINE=InnoDB $charset_collate;";

		// --- Tabela 3: log aktywności (audyt) ---
		$sql_log = "CREATE TABLE $log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned DEFAULT NULL,
			action varchar(100) NOT NULL,
			description text DEFAULT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			meta_json longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY action (action),
			KEY created_at (created_at)
		) ENGINE=InnoDB $charset_collate;";

		dbDelta( $sql_leads );
		dbDelta( $sql_offers );
		dbDelta( $sql_log );

		self::upgrade_nip_unique_key();
		self::add_foreign_keys();

		// Zapisujemy wersję bazy TYLKO gdy tabele faktycznie istnieją. dbDelta() nie
		// rzuca wyjątku przy awarii (np. brak uprawnień CREATE TABLE) — bez tej
		// weryfikacji cicha porażka zostałaby trwale oznaczona jako "zainstalowane
		// poprawnie" i maybe_upgrade() nigdy więcej by nie spróbowała ponownie.
		if ( self::tables_exist() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Sprawdza, czy wszystkie 3 tabele BD-3 faktycznie istnieją w bazie.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;
		foreach ( array( self::leads_table(), self::offers_table(), self::activity_log_table() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Migruje UNIQUE KEY(nip) → UNIQUE KEY(country, nip) (od 1.4.0).
	 *
	 * DbDelta() jest wyłącznie addytywne — nie usuwa indeksów nieobecnych w nowym SQL.
	 * Samo dopisanie `uq_country_nip` w CREATE TABLE zostawiłoby stary `uq_nip` aktywny
	 * na już zainstalowanych bazach, cicho utrzymując pierwotny błąd (numer firmowy
	 * różnych krajów UE może się cyfrowo pokrywać — UNIQUE(nip) blokowałby drugą,
	 * niepowiązaną firmę). Usuwamy go jawnie, jeśli nadal istnieje; no-op na świeżej
	 * instalacji (indeks nigdy nie powstał).
	 *
	 * @return void
	 */
	private static function upgrade_nip_unique_key() {
		global $wpdb;
		$table = self::leads_table();

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				DB_NAME,
				$table,
				'uq_nip'
			)
		);

		if ( $exists > 0 ) {
			// Nazwa tabeli/indeksu pochodzi z kodu (nie z danych użytkownika) — bezpieczne.
			$wpdb->query( "ALTER TABLE $table DROP INDEX uq_nip" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * Dodaje klucze obce, których dbDelta() nie potrafi utworzyć.
	 *
	 * Relacja oferta -> lead z ON DELETE RESTRICT: baza NIE pozwoli fizycznie
	 * usunąć leada mającego oferty. Leady i tak tylko archiwizujemy (deleted_at),
	 * nie kasujemy. Tabela activity_log CELOWO bez FK — log audytowy ma przetrwać
	 * (przy żądaniu RODO anonimizujemy wpisy, a nie usuwamy).
	 *
	 * @return void
	 */
	private static function add_foreign_keys() {
		global $wpdb;

		$offers = self::offers_table();
		$leads  = self::leads_table();

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM information_schema.TABLE_CONSTRAINTS
				 WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s
				   AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
				DB_NAME,
				$offers,
				'fk_offers_lead'
			)
		);

		if ( 0 === $exists ) {
			// Nazwy tabel pochodzą z kodu (nie z danych użytkownika) — bezpieczne.
			$wpdb->query( "ALTER TABLE $offers ADD CONSTRAINT fk_offers_lead FOREIGN KEY (lead_id) REFERENCES $leads (id) ON DELETE RESTRICT ON UPDATE CASCADE" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * Uruchamia aktualizację schematu, jeśli wersja bazy w bazie różni się
	 * od wersji w kodzie (np. po aktualizacji wtyczki bez reaktywacji).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Usuwa tabele BD-3 i opcje wtyczki. Wywoływane z uninstall.php.
	 *
	 * Kolejność usuwania: log -> oferty -> leady (od "dzieci" do "rodzica"),
	 * na wypadek gdyby w przyszłości dodano klucze obce.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		$tables = array(
			self::activity_log_table(),
			self::offers_table(),
			self::leads_table(),
		);

		foreach ( $tables as $table ) {
			// Nazwa tabeli pochodzi z kodu (nie z danych użytkownika) — bezpieczne.
			$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		delete_option( self::DB_VERSION_OPTION );
	}
}
