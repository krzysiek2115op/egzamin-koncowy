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
		 * Nonce nadajemy tutaj, a nie przyjmujemy z żądania. To nie jest obejście
		 * kontroli: podstrona renderuje się po stronie serwera, więc nie istnieje
		 * „cudze" żądanie, przed którym nonce miałby bronić. Token wiąże zdarzenie
		 * z SESJĄ tego użytkownika (wp_create_nonce jest per użytkownik), dzięki
		 * czemu wpis w dzienniku nadal mówi, czyj to był podgląd. Za dostęp
		 * odpowiada uprawnienie — sprawdzone i tutaj, i przez krytyka K1.2 — a
		 * ścieżka `dashboard.view` i tak niczego nie zapisuje.
		 */
		$dispatched = MP_SW_Events::dispatch(
			MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW,
			array(
				'entity' => array(),
				'actor'  => array( 'user_id' => get_current_user_id() ),
				'nonce'  => wp_create_nonce( MP_SW_D1::NONCE_ACTION ),
			),
			MP_SW_D1::SOURCE_MANUAL
		);

		$context = $dispatched['context'];
		$scope   = (string) $context->get( 'scope', MP_SW_D3::SCOPE_OWN );
		$rows    = self::flows( $scope, get_current_user_id() );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Procesy sprzedażowe', 'mp-sales-workflow' ) . '</h1>';

		if ( ! $dispatched['result']->is_ok() ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html( implode( ' ', (array) $dispatched['result']->get_errors() ) )
				. '</p></div>';
		}

		self::render_summary( $context, $scope );
		self::render_table( $rows );

		echo '</div>';
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

		foreach ( array( 'Lead', 'Klient', 'Status', 'Handlowiec', 'Termin SLA', 'Otwarte zadania', 'Aktualizacja' ) as $naglowek ) {
			echo '<th>' . esc_html( $naglowek ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$owner = $row['assigned_user_id'] ? get_userdata( (int) $row['assigned_user_id'] ) : null;

			echo '<tr>';
			echo '<td>' . esc_html( $row['lead_id'] ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $row['client_name'] ? $row['client_name'] : '—' ) . '</td>';
			echo '<td>' . esc_html( self::status_label( (string) $row['status'] ) ) . '</td>';
			echo '<td>' . esc_html( $owner instanceof WP_User ? $owner->display_name : '—' ) . '</td>';
			echo '<td>' . esc_html( $row['sla_due_at'] ? $row['sla_due_at'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $row['open_tasks'] ) . '</td>';
			echo '<td>' . esc_html( $row['updated_at'] ) . '</td>';
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
	 * @return array
	 */
	public static function flows( $scope, $user_id ) {
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
		if ( MP_SW_D3::SCOPE_ALL !== $scope ) {
			$sql     .= ' WHERE f.assigned_user_id = %d';
			$params[] = (int) $user_id;
		}

		$sql     .= ' ORDER BY f.updated_at DESC LIMIT %d';
		$params[] = self::PER_PAGE;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}
}
