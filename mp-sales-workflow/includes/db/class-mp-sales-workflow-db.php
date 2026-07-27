<?php
/**
 * BD-1 — warstwa bazy danych wtyczki MP Sales Workflow.
 *
 * Zakres wg diagramu LP.3 (blueprint/LP3_diagram_wizualny.html), lewa kolumna:
 * rdzeń WordPressa (wp_users, wp_usermeta, wp_options) jest w żądaniu TYLKO do
 * odczytu, a wtyczka zapisuje wyłącznie do własnych tabel — jedną transakcją
 * (Dział 8). Ten plik odpowiada za sam schemat i cykl życia tabel; metody
 * odczytu/zapisu dochodzą wraz z działami, które ich realnie używają.
 *
 * Nazwy tabel mają infiks `mp_sw_` (decyzja klienta z 2026-07-27, propozycja A):
 * wtyczka 1 ma już fizyczną tabelę `wp_mp_activity_log`, a diagram przewidywał
 * `wp_mp_activity` — różnica jednego członu w nazwie, na tej samej bazie, przy
 * trzech aktywnych wtyczkach projektu. Infiks eliminuje ryzyko pomyłki i pozwala
 * bezpiecznie sprzątać po sobie przy deinstalacji.
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - docs/dzial-08/dbdelta-i-transakcje.md (reguły formatowania SQL dla dbDelta)
 *  - docs/dzial-01/mysql-unique-index.md   (UNIQUE + wiele NULL — idempotencja)
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schemat i cykl życia tabel BD-1.
 */
class MP_Sales_Workflow_DB {

	/**
	 * Wersja schematu. Podnoszona przy KAŻDEJ zmianie DDL — `maybe_upgrade()`
	 * porównuje ją z wartością zapisaną w opcji i dopiero wtedy woła dbDelta().
	 */
	const DB_VERSION = '0.1.0';

	/** Opcja przechowująca wersję schematu zainstalowaną na tej witrynie. */
	const DB_VERSION_OPTION = 'mp_sales_workflow_db_version';

	// --- Słownik statusów procesu (kolumna flow.status) -----------------------
	// Klucze angielskie, etykiety PL/EN dochodzą w warstwie prezentacji (decyzja
	// klienta z 2026-07-27, propozycja E): wtyczki 1 i 2 trzymają w bazie klucze
	// angielskie ('new', 'draft', 'sent'), a diagram LP.3 sam operuje `lang: "en"`
	// i szablonami pl/en — polskie klucze w bazie wymuszałyby tłumaczenie wartości
	// kolumny na potrzeby angielskich powiadomień.
	//
	// Diagram (Dział 5) nazywa te stany: nowy · przypisany · oferta_robocza ·
	// oferta_wyslana · negocjacje · wygrany / przegrany. Sam GRAF dozwolonych
	// przejść między nimi należy do Działu 5 (maszyna statusów), nie do tej
	// warstwy — tutaj jest wyłącznie zbiór dopuszczalnych wartości kolumny.

	const STATUS_NEW         = 'new';
	const STATUS_ASSIGNED    = 'assigned';
	const STATUS_OFFER_DRAFT = 'offer_draft';
	const STATUS_OFFER_SENT  = 'offer_sent';
	const STATUS_NEGOTIATION = 'negotiation';
	const STATUS_WON         = 'won';
	const STATUS_LOST        = 'lost';

	/**
	 * Pełny słownik statusów procesu.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array(
			self::STATUS_NEW,
			self::STATUS_ASSIGNED,
			self::STATUS_OFFER_DRAFT,
			self::STATUS_OFFER_SENT,
			self::STATUS_NEGOTIATION,
			self::STATUS_WON,
			self::STATUS_LOST,
		);
	}

	/**
	 * Procesy sprzedażowe — jeden wiersz na lead (patrz `uq_lead`).
	 *
	 * @return string
	 */
	public static function flow_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_sw_flow';
	}

	/**
	 * Zadania follow-up (d+3 / d+7) wraz z wartownikiem statusu.
	 *
	 * @return string
	 */
	public static function tasks_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_sw_tasks';
	}

	/**
	 * Kolejka powiadomień e-mail — wysyłka dopiero PO COMMIT, poza żądaniem.
	 *
	 * @return string
	 */
	public static function notifications_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_sw_notifications';
	}

	/**
	 * Dziennik aktywności (kryterium odbioru 5.5 — odtworzenie historii).
	 *
	 * @return string
	 */
	public static function activity_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_sw_activity';
	}

	/**
	 * Rejestr obsłużonych zdarzeń — nośnik idempotencji (`uq_event_id`).
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'mp_sw_events';
	}

	/**
	 * Wszystkie tabele BD-1 — używane przez `tables_exist()` i `uninstall()`.
	 *
	 * @return string[]
	 */
	public static function tables() {
		return array(
			self::flow_table(),
			self::tasks_table(),
			self::notifications_table(),
			self::activity_table(),
			self::events_table(),
		);
	}

	/**
	 * Zakłada (lub aktualizuje) tabele BD-1 i zapisuje wersję schematu.
	 *
	 * Wersja zapisywana jest TYLKO po potwierdzeniu, że tabele faktycznie
	 * istnieją: dbDelta() nie zwraca statusu błędu, więc zapis wersji "w ciemno"
	 * zamieniłby nieudaną instalację w cichą, trwałą awarię — przy kolejnym
	 * uruchomieniu `maybe_upgrade()` uznałby schemat za aktualny i nigdy nie
	 * spróbowałby ponownie (ta sama pułapka wyszła w audycie wtyczki 1).
	 *
	 * @return bool Czy schemat jest gotowy do użycia.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::schema( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		if ( ! self::tables_exist() ) {
			return false;
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		return true;
	}

	/**
	 * Uruchamia migrację schematu, gdy zapisana wersja jest starsza niż w kodzie.
	 *
	 * Wołane przy każdym ładowaniu wtyczki (nie tylko przy aktywacji) — bez tego
	 * witryna zaktualizowana przez podmianę plików (FTP, deploy) zostałaby ze
	 * starym schematem: hook aktywacji już się na niej nie uruchomi.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::DB_VERSION_OPTION, '' );

		if ( self::DB_VERSION === $installed ) {
			return;
		}

		self::install();
	}

	/**
	 * Czy wszystkie tabele BD-1 istnieją w bazie.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Usuwa tabele BD-1 i wersję schematu (deinstalacja wtyczki).
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nazwa tabeli pochodzi z kodu, nie z danych użytkownika.
		}

		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Definicje CREATE TABLE dla wszystkich tabel BD-1.
	 *
	 * Składnia podlega wymogom dbDelta() (docs/dzial-08/dbdelta-i-transakcje.md):
	 * każde pole w osobnej linii, DWIE spacje po `PRIMARY KEY`, słowo `KEY`
	 * zamiast `INDEX`, brak backticków, typy małymi literami, jawne długości.
	 *
	 * Znaczniki czasu są zwykłymi kolumnami `datetime` bez DEFAULT
	 * CURRENT_TIMESTAMP — wypełnia je kod, jednym źródłem czasu w GMT
	 * (`current_time( 'mysql', true )`). W poprzednich wtyczkach projektu
	 * mieszanie czasu bazy z czasem WordPressa dawało realne rozjazdy stref.
	 *
	 * @param string $charset_collate Wynik `$wpdb->get_charset_collate()`.
	 * @return string[]
	 */
	private static function schema( $charset_collate ) {
		$flow          = self::flow_table();
		$tasks         = self::tasks_table();
		$notifications = self::notifications_table();
		$activity      = self::activity_table();
		$events        = self::events_table();

		$sql = array();

		/*
		 * PROCES SPRZEDAŻOWY.
		 *
		 * `lead_id` jest UNIQUE, bo proces jest tożsamy z leadem (decyzja klienta
		 * z 2026-07-27, propozycja D): zdarzenie `offer.approved` z wtyczki 2 musi
		 * trafić w TEN SAM wiersz, co wcześniejsze `lead.created` z wtyczki 1.
		 * Pojedyncze pole `entity_ref` z diagramu rozdzielałoby je na dwa procesy
		 * tej samej firmy — z podwójnym follow-upem i zawyżonym lejkiem.
		 *
		 * `offer_id`/`offer_number` to snapshot BIEŻĄCEJ oferty przekazany w
		 * zdarzeniu. Wtyczka 3 celowo nie czyta tabel wtyczki 2 — granice modułów
		 * biegną po zdarzeniach, nie po bazie.
		 *
		 * `lock_version` to dedykowany token blokady optymistycznej (decyzja
		 * klienta z 2026-07-27, propozycja C), rosnący bezwarunkowo przy każdym
		 * zapisie. Diagram proponował `updated_at`, ale `datetime` ma rozdzielczość
		 * jednej sekundy — dwa zapisy w tej samej sekundzie miałyby identyczny
		 * znacznik i nadpisanie przeszłoby niezauważone.
		 */
		$sql[] = "CREATE TABLE {$flow} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			offer_id bigint(20) unsigned DEFAULT NULL,
			offer_number varchar(32) DEFAULT NULL,
			status varchar(32) NOT NULL DEFAULT 'new',
			assigned_user_id bigint(20) unsigned DEFAULT NULL,
			assigned_at datetime DEFAULT NULL,
			assign_reason varchar(255) DEFAULT NULL,
			assign_fallback tinyint(1) unsigned NOT NULL DEFAULT 0,
			sla_due_at datetime DEFAULT NULL,
			lang varchar(5) NOT NULL DEFAULT 'pl',
			country char(2) DEFAULT NULL,
			segment varchar(64) DEFAULT NULL,
			client_name varchar(191) DEFAULT NULL,
			client_email varchar(191) DEFAULT NULL,
			lock_version bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_lead (lead_id),
			KEY idx_status (status),
			KEY idx_assigned (assigned_user_id),
			KEY idx_sla (sla_due_at),
			KEY idx_updated (updated_at)
		) {$charset_collate};";

		/*
		 * ZADANIA FOLLOW-UP (d+3 / d+7).
		 *
		 * `guard_status` to wartownik z kryterium 4.5: zadanie wykonuje się tylko
		 * wtedy, gdy status procesu nadal jest ten, przy którym je zaplanowano.
		 *
		 * `open_key` realizuje krytyka K6.2 ("jedno otwarte zadanie danego typu na
		 * proces") na poziomie bazy, a nie sprawdzeniem w kodzie. Kolumna trzyma
		 * `<flow_id>:<type>` dopóki zadanie jest otwarte i NULL, gdy zostanie
		 * zamknięte lub anulowane — UNIQUE blokuje wtedy drugie otwarte zadanie
		 * tego samego typu, a zamkniętych dopuszcza dowolnie wiele, bo "a UNIQUE
		 * index permits multiple NULL values" (docs/dzial-01/mysql-unique-index.md).
		 * Sprawdzenie SELECT-em przed INSERT-em byłoby podatne na wyścig dwóch
		 * równoległych wywołań crona.
		 */
		$sql[] = "CREATE TABLE {$tasks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			flow_id bigint(20) unsigned NOT NULL,
			type varchar(32) NOT NULL,
			due_at datetime NOT NULL,
			guard_status varchar(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			assignee bigint(20) unsigned DEFAULT NULL,
			event_id char(36) DEFAULT NULL,
			open_key varchar(64) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_open_task (open_key),
			KEY idx_flow (flow_id),
			KEY idx_due (due_at, status),
			KEY idx_event (event_id)
		) {$charset_collate};";

		/*
		 * KOLEJKA POWIADOMIEŃ.
		 *
		 * `subject`/`body` przechowują GOTOWĄ treść wyliczoną w Dziale 7, a nie
		 * sam odnośnik do szablonu: część zmiennych (adres pobrania PDF, terminy)
		 * pochodzi ze zdarzenia i po jego zakończeniu nie da się ich odtworzyć.
		 * `template_version` zostaje mimo to — dziennik wysyłek ma pokazywać, na
		 * której wersji szablonu powstała treść (krytyk K2.5).
		 *
		 * `attempts`/`last_error` obsługują ponowienia; limit prób jest stałą w
		 * kodzie kolejki, nie kolumną — to parametr wysyłki, nie cecha wiersza.
		 */
		$sql[] = "CREATE TABLE {$notifications} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			flow_id bigint(20) unsigned NOT NULL,
			event_id char(36) DEFAULT NULL,
			template varchar(64) NOT NULL,
			template_version varchar(16) NOT NULL DEFAULT '',
			lang varchar(5) NOT NULL DEFAULT 'pl',
			recipient varchar(191) NOT NULL,
			recipient_user_id bigint(20) unsigned DEFAULT NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext,
			status varchar(20) NOT NULL DEFAULT 'queued',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_flow (flow_id),
			KEY idx_queue (status, created_at),
			KEY idx_event (event_id)
		) {$charset_collate};";

		/*
		 * DZIENNIK AKTYWNOŚCI.
		 *
		 * Bez klucza obcego — świadomie, tak samo jak dziennik wtyczki 1: audyt ma
		 * przetrwać usunięcie procesu, a wymogi RODO realizuje się anonimizacją
		 * wpisu, nie jego kasowaniem.
		 *
		 * `entity_ref` zostaje w formie z diagramu (opis tekstowy encji, np.
		 * "lead:812") — tu, w odróżnieniu od tabeli procesów, nie służy do
		 * łączenia wierszy, więc rozbijanie go na kolumny nic by nie dało.
		 */
		$sql[] = "CREATE TABLE {$activity} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id char(36) DEFAULT NULL,
			flow_id bigint(20) unsigned DEFAULT NULL,
			entity_ref varchar(64) DEFAULT NULL,
			action varchar(64) NOT NULL,
			old_value text,
			new_value text,
			actor_type varchar(20) NOT NULL DEFAULT 'system',
			actor_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_flow (flow_id),
			KEY idx_event (event_id),
			KEY idx_action (action),
			KEY idx_created (created_at)
		) {$charset_collate};";

		/*
		 * REJESTR ZDARZEŃ — idempotencja (decyzja klienta z 2026-07-27, propozycja B).
		 *
		 * Diagram wymaga, by ten sam `event_id` nigdy nie został obsłużony dwa razy
		 * (krytyk K1.3), ale jedyne miejsce, w którym `event_id` występował, to
		 * dziennik — a tam na jedno zdarzenie przypada KILKA wierszy, więc UNIQUE
		 * nie dało się tam założyć i gwarancja pozostawała deklaracją.
		 *
		 * Wiersz powstaje w tej samej transakcji co reszta zapisu (Dział 8, zasada
		 * "1 transakcja"), więc równoległe żądanie z tym samym `event_id` zostaje
		 * wstrzymane na indeksie do COMMIT-u pierwszego, a potem odbite błędem
		 * klucza — bez dodatkowego zapisu przed transakcją.
		 *
		 * `result_json` przechowuje odpowiedź zwróconą przy pierwszym przebiegu:
		 * powtórka tego samego zdarzenia ma oddać ten sam wynik, zamiast wykonać
		 * pracę drugi raz (ten sam wzorzec sprawdził się we wtyczce 2).
		 */
		$sql[] = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id char(36) NOT NULL,
			type varchar(64) NOT NULL,
			lead_id bigint(20) unsigned DEFAULT NULL,
			offer_id bigint(20) unsigned DEFAULT NULL,
			actor_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'done',
			result_json longtext,
			trace_id char(36) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_event_id (event_id),
			KEY idx_type (type),
			KEY idx_created (created_at)
		) {$charset_collate};";

		return $sql;
	}
}
