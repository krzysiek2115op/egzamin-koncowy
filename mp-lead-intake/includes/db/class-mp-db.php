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
	const DB_VERSION = '1.1.0';

	/** Nazwa opcji WordPress przechowującej aktualną wersję bazy. */
	const DB_VERSION_OPTION = 'mp_lead_intake_db_version';

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

	/* --------------------------------------------------------------------- *
	 *  Odczyt danych (używane m.in. przez Dział 1 pipeline)
	 * --------------------------------------------------------------------- */

	/**
	 * Zwraca aktywne (niezarchiwizowane) leady o podanym NIP.
	 *
	 * @param string $nip NIP firmy.
	 * @return array Lista wierszy (ARRAY_A); pusta, gdy brak.
	 */
	public static function get_leads_by_nip( $nip ) {
		global $wpdb;
		$table = self::leads_table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM $table WHERE nip = %s AND deleted_at IS NULL", $nip ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
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

	/* --------------------------------------------------------------------- *
	 *  Zapis danych (używane m.in. przez Działy 7, 8, 9)
	 * --------------------------------------------------------------------- */

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
			salesman_id bigint(20) unsigned DEFAULT NULL,
			score int(11) NOT NULL DEFAULT 0,
			status varchar(30) NOT NULL DEFAULT 'new',
			consent_marketing tinyint(1) NOT NULL DEFAULT 0,
			consent_rodo tinyint(1) NOT NULL DEFAULT 0,
			consent_marketing_at datetime DEFAULT NULL,
			consent_rodo_at datetime DEFAULT NULL,
			consent_version varchar(20) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			deleted_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_nip (nip),
			KEY email (email),
			KEY status (status),
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

		self::add_foreign_keys();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
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
