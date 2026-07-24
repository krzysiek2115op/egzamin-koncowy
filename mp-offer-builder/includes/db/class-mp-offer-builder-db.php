<?php
/**
 * Warstwa bazy danych wtyczki MP Offer Builder — BD-2.
 *
 * Dwie strefy: WooCommerce (wp_posts/wp_postmeta lub tabele HPOS,
 * wp_wc_product_meta_lookup, wp_woocommerce_tax_rates) — WYŁĄCZNIE do odczytu,
 * przez oficjalne API (WC_Product/wc_get_products/WC_Tax), nigdy surowym SQL.
 * Strefa wtyczki (5 tabel poniżej) — zapis jedną transakcją w Dziale 10.
 *
 * Nazwy tabel mają infiks `mp_ob_` (nie samo `mp_`), bo plugin 1 (mp-lead-intake)
 * ma już fizyczną tabelę `wp_mp_offers` (rusztowanie pod BD-3) — przy współistnieniu
 * obu wtyczek na tej samej instalacji WP nazwa `wp_mp_offers` by kolidowała.
 *
 * @package MP_Offer_Builder
 */

// Blokada bezpośredniego wywołania.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasa zarządzająca schematem bazy danych BD-2.
 */
class MP_Offer_Builder_DB {

	/**
	 * Wersja schematu bazy. Podbijamy przy KAŻDEJ zmianie struktury tabel.
	 */
	const DB_VERSION = '0.3.0';

	/** Nazwa opcji WordPress przechowującej aktualną wersję bazy. */
	const DB_VERSION_OPTION = 'mp_offer_builder_db_version';

	/**
	 * Nazwa tabeli szablonów ofert (np. wp_mp_ob_offer_templates).
	 *
	 * @return string
	 */
	public static function templates_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_ob_offer_templates';
	}

	/**
	 * Nazwa tabeli ofert (np. wp_mp_ob_offers).
	 *
	 * @return string
	 */
	public static function offers_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_ob_offers';
	}

	/**
	 * Nazwa tabeli pozycji oferty (np. wp_mp_ob_offer_items).
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_ob_offer_items';
	}

	/**
	 * Nazwa tabeli wersji oferty (np. wp_mp_ob_offer_versions).
	 *
	 * @return string
	 */
	public static function versions_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_ob_offer_versions';
	}

	/**
	 * Nazwa tabeli logu aktywności (np. wp_mp_ob_offer_activity_log).
	 *
	 * @return string
	 */
	public static function activity_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_ob_offer_activity_log';
	}

	/**
	 * Tworzy/aktualizuje schemat BD-2 (dbDelta — bezpieczne dla istniejących danych).
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$templates       = self::templates_table();
		$offers          = self::offers_table();
		$items           = self::items_table();
		$versions        = self::versions_table();
		$log             = self::activity_log_table();

		// --- Tabela 1: szablony ofert (pl/en, wersjonowane) ---
		$sql_templates = "CREATE TABLE $templates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			lang varchar(5) NOT NULL,
			content longtext NOT NULL,
			variables longtext DEFAULT NULL,
			version varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY lang (lang),
			KEY status (status)
		) ENGINE=InnoDB $charset_collate;";

		// --- Tabela 2: oferty ---
		// lead_id jest MIĘKKIM odniesieniem (bez FK) do wp_mp_leads pluginu 1 —
		// twardy FK złamałby izolację wtyczek (plugin 2 nie może zakładać, że
		// plugin 1 jest zainstalowany). Dlatego dane klienta są też zdenormalizowane
		// (client_*) na samej ofercie, żeby oferta była kompletna sama w sobie.
		// UNIQUE(request_id): egzekwuje na poziomie DB krytyk idempotencji z Działu 1
		// blueprintu ("ten sam request_id nigdy nie tworzy drugiej oferty") — MySQL/InnoDB
		// pozwala na wiele wierszy z request_id=NULL (np. przyszłe wiersze zakładane spoza
		// pipeline'u „1 AJAX"), więc kolumna zostaje nullable.
		//
		// offer_number/lang jako NULL (decyzja 2026-07-23, uzgodniona z klientem): hook
		// mp_lead_created z pluginu 1 automatycznie zakłada SZKIC oferty (status='draft',
		// lead_id + snapshot klienta, ZERO pozycji) — w tym momencie nie ma jeszcze ani
		// numeru (ten wg Działu 8 diagramu powstaje dopiero PRZED renderem PDF), ani języka
		// (wybiera go handlowiec przy dokańczaniu oferty). MySQL/InnoDB traktuje każdy NULL
		// w UNIQUE(offer_number, version) jako odrębny, więc wiele draftów współistnieje bez
		// kolizji.
		// created_by (Krok 4, decyzja własności ofert uzgodniona z klientem): dostęp do
		// listy/PDF ograniczony do TWÓRCY oferty; administrator (manage_options) widzi
		// wszystko. Ustawiane przez Dział 10 (Agent 10.1) PRZY PIERWSZYM zapisie i
		// zachowywane bez zmian przy kolejnych korektach (pierwszy handlowiec zostaje
		// właścicielem na stałe).
		$sql_offers = "CREATE TABLE $offers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			offer_number varchar(30) DEFAULT NULL,
			version int(10) unsigned NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'draft',
			lang varchar(5) DEFAULT NULL,
			lead_id bigint(20) unsigned DEFAULT NULL,
			client_name varchar(191) DEFAULT NULL,
			client_email varchar(191) DEFAULT NULL,
			client_nip varchar(20) DEFAULT NULL,
			client_country char(2) DEFAULT NULL,
			net_grosze bigint(20) NOT NULL DEFAULT 0,
			vat_grosze bigint(20) NOT NULL DEFAULT 0,
			gross_grosze bigint(20) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'PLN',
			tax_mechanism varchar(20) DEFAULT NULL,
			template_id bigint(20) unsigned DEFAULT NULL,
			pdf_path varchar(255) DEFAULT NULL,
			pdf_sha256 char(64) DEFAULT NULL,
			request_id char(36) DEFAULT NULL,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_offer_number_version (offer_number, version),
			UNIQUE KEY uq_request_id (request_id),
			KEY lead_id (lead_id),
			KEY status (status),
			KEY created_by (created_by)
		) ENGINE=InnoDB $charset_collate;";

		// --- Tabela 3: pozycje oferty ---
		$sql_items = "CREATE TABLE $items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			offer_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned DEFAULT NULL,
			qty int(10) unsigned NOT NULL,
			price_base_grosze bigint(20) NOT NULL,
			discount_grosze bigint(20) NOT NULL DEFAULT 0,
			price_final_grosze bigint(20) NOT NULL,
			tax_rate decimal(5,2) NOT NULL DEFAULT 0.00,
			PRIMARY KEY  (id),
			KEY offer_id (offer_id)
		) ENGINE=InnoDB $charset_collate;";

		// --- Tabela 4: historia wersji oferty ---
		$sql_versions = "CREATE TABLE $versions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			offer_id bigint(20) unsigned NOT NULL,
			version_number int(10) unsigned NOT NULL,
			data_json longtext NOT NULL,
			pdf_path varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_by bigint(20) unsigned DEFAULT NULL,
			change_log text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY offer_id (offer_id)
		) ENGINE=InnoDB $charset_collate;";

		// --- Tabela 5: log aktywności (audyt, kryt. 5.5) ---
		$sql_log = "CREATE TABLE $log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			offer_id bigint(20) unsigned DEFAULT NULL,
			action varchar(100) NOT NULL,
			description text DEFAULT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			meta_json longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY offer_id (offer_id),
			KEY action (action),
			KEY created_at (created_at)
		) ENGINE=InnoDB $charset_collate;";

		dbDelta( $sql_templates );
		dbDelta( $sql_offers );
		dbDelta( $sql_items );
		dbDelta( $sql_versions );
		dbDelta( $sql_log );

		// Zapisujemy wersję bazy TYLKO gdy tabele faktycznie istnieją — wzorem
		// pluginu 1: dbDelta() nie rzuca wyjątku przy awarii (np. brak uprawnień
		// CREATE TABLE), bez tej weryfikacji cicha porażka zostałaby trwale
		// oznaczona jako "zainstalowane poprawnie".
		if ( self::tables_exist() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Ponownie uruchamia install() (dbDelta jest bezpieczny dla istniejących
	 * danych — dodaje/zmienia kolumny, nie kasuje wierszy), jeśli wersja
	 * zapisana w opcji różni się od DB_VERSION. Bez tego instalacje AKTYWOWANE
	 * przed zmianą schematu (np. dodaniem created_by w Kroku 4) nigdy nie
	 * dostałyby nowych kolumn/indeksów — register_activation_hook() odpala się
	 * tylko RAZ, przy (re)aktywacji wtyczki, nie przy zwykłej aktualizacji plików.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Sprawdza, czy wszystkie tabele BD-2 istnieją.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;
		foreach ( array( self::templates_table(), self::offers_table(), self::items_table(), self::versions_table(), self::activity_log_table() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Pobiera ofertę po ID (Dział 1: dokończenie istniejącego draftu z Kroku 2.5).
	 *
	 * @param int $offer_id ID oferty.
	 * @return array|null
	 */
	public static function get_offer( $offer_id ) {
		global $wpdb;
		$table = self::offers_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $offer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * Pobiera ofertę po request_id (Dział 1: idempotencja — "ten sam request_id
	 * nigdy nie tworzy drugiej oferty", sprawdzane pre-gate w AJAX przed pipeline).
	 *
	 * @param string $request_id UUID żądania.
	 * @return array|null
	 */
	public static function get_offer_by_request_id( $request_id ) {
		global $wpdb;
		$request_id = trim( (string) $request_id );
		if ( '' === $request_id ) {
			return null;
		}
		$table = self::offers_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %s", $request_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * Ostatni numer oferty użyty w danym roku (Dział 2, Agent 2.5 "numeracja") —
	 * "punkt startu" dla Działu 8, żeby ten nie musiał sam czytać BD-2 (zasada
	 * "jeden odczyt", działy 3-9 to czyste funkcje na zamrożonym snapshocie).
	 *
	 * @param int $year Rok (np. 2026).
	 * @return string|null Pełny numer (np. "OF/2026/000123"), albo null gdy brak.
	 */
	public static function get_last_offer_number_for_year( $year ) {
		global $wpdb;
		$table = self::offers_table();
		$like  = 'OF/' . (int) $year . '/%';
		$row   = $wpdb->get_var( $wpdb->prepare( "SELECT offer_number FROM {$table} WHERE offer_number LIKE %s ORDER BY offer_number DESC LIMIT 1", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ? (string) $row : null;
	}

	/**
	 * Najwyższa istniejąca wersja dla danego numeru oferty (korekta — Dział 8,
	 * Agent 8.2 "wersja": "ten sam numer, wersja + 1").
	 *
	 * @param string $offer_number Numer oferty.
	 * @return int|null
	 */
	public static function get_max_version_for_offer_number( $offer_number ) {
		global $wpdb;
		$table = self::offers_table();
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$table} WHERE offer_number = %s", (string) $offer_number ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return null !== $max ? (int) $max : null;
	}

	/**
	 * Lista ofert z filtrami — panel wp-admin handlowca (Krok 4).
	 *
	 * @param array $args Filtry: status, created_by, search (client_name/client_nip/
	 *                    offer_number), per_page, offset, orderby, order.
	 * @return array Wiersze ARRAY_A.
	 */
	public static function list_offers( array $args = array() ) {
		global $wpdb;
		$table = self::offers_table();

		list( $where_sql, $where_values ) = self::build_offers_where( $args );

		$allowed_orderby = array( 'id', 'offer_number', 'status', 'gross_grosze', 'version', 'updated_at', 'created_at' );
		$orderby         = ( ! empty( $args['orderby'] ) && in_array( $args['orderby'], $allowed_orderby, true ) ) ? $args['orderby'] : 'updated_at';
		$order           = ( ! empty( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$offset   = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql    = "SELECT * FROM {$table}{$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$values = array_merge( $where_values, array( $per_page, $offset ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Liczba ofert pasujących do tych samych filtrów co list_offers() (paginacja).
	 *
	 * @param array $args Filtry: status, created_by, search (per_page/offset/orderby/order ignorowane).
	 * @return int
	 */
	public static function count_offers( array $args = array() ) {
		global $wpdb;
		$table = self::offers_table();

		list( $where_sql, $where_values ) = self::build_offers_where( $args );
		$sql                              = "SELECT COUNT(*) FROM {$table}{$where_sql}";

		if ( $where_values ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where_values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Wspólne warunki WHERE dla list_offers()/count_offers() — status/created_by/search
	 * (wydzielone, żeby paginacja i liczenie zawsze filtrowały IDENTYCZNIE).
	 *
	 * @param array $args Filtry (patrz list_offers()).
	 * @return array{0: string, 1: array} [" WHERE ..." albo "", wartości do prepare()].
	 */
	private static function build_offers_where( array $args ) {
		global $wpdb;

		$conditions = array();
		$values     = array();

		if ( ! empty( $args['status'] ) ) {
			$conditions[] = 'status = %s';
			$values[]     = (string) $args['status'];
		}
		if ( isset( $args['created_by'] ) && '' !== $args['created_by'] ) {
			$conditions[] = 'created_by = %d';
			$values[]     = (int) $args['created_by'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$conditions[] = '(client_name LIKE %s OR client_nip LIKE %s OR offer_number LIKE %s)';
			$values[]     = $like;
			$values[]     = $like;
			$values[]     = $like;
		}

		$where_sql = $conditions ? ( ' WHERE ' . implode( ' AND ', $conditions ) ) : '';
		return array( $where_sql, $values );
	}

	/**
	 * Usuwa wszystkie tabele BD-2 i opcję wersji (deinstalacja wtyczki).
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		$tables = array(
			self::activity_log_table(),
			self::versions_table(),
			self::items_table(),
			self::offers_table(),
			self::templates_table(),
		);

		foreach ( $tables as $table ) {
			// Nazwa tabeli pochodzi z kodu (nie z danych użytkownika) — bezpieczne.
			$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		delete_option( self::DB_VERSION_OPTION );
	}
}
