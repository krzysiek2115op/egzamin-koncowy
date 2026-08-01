<?php
/**
 * Ekran leadów — jedyne miejsce, w którym człowiek widzi zawartość BD-3.
 *
 * Do wersji 1.3.6 ta wtyczka nie rejestrowała ŻADNEGO ekranu panelu: w całym
 * module nie było ani jednego `add_menu_page()`. Zgłoszenia z formularza trafiały
 * do `wp_mp_leads` i tam zostawały — żeby je obejrzeć, trzeba było wejść do bazy.
 * Jednocześnie wtyczka od początku zakładała dwie role i trzy uprawnienia
 * (`mp_view_leads`, `mp_manage_leads`, `mp_assign_leads` — class-mp-roles.php),
 * nadawane nikomu do niczego, bo nie istniał ekran, który by o nie zapytał.
 *
 * Ten sam ekran domyka drugą lukę: PUNKTACJĘ. `MP_Lead_Scoring::calculate()` liczy
 * ją przy każdym zgłoszeniu i ponownie po weryfikacji VAT w tle, po czym wynik
 * ląduje w kolumnie `score` — i nie pokazywał go nikt. Zlecenie wymienia scoring
 * jako element kwalifikacji leada, a liczba, której nikt nie widzi, niczego nie
 * kwalifikuje.
 *
 * ZAKRES WIDOKU IDZIE ZA ROLĄ. Handlowiec widzi własne leady, manager sprzedaży
 * i administrator — wszystkie. Bez tego rozróżnienia ekran pokazywałby każdemu
 * handlowcowi nazwy, adresy i telefony klientów spoza jego zakresu obsługi, czyli
 * cudze dane osobowe.
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - add_menu_page()       https://developer.wordpress.org/reference/functions/add_menu_page/
 *  - current_user_can()    https://developer.wordpress.org/reference/functions/current_user_can/
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Podstrona z listą leadów.
 */
class MP_Lead_Intake_Admin {

	/** Slug podstrony. */
	const PAGE = 'mp-lead-intake-leady';

	/** Ile leadów na stronie. */
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
			__( 'Leady', 'mp-lead-intake' ),
			__( 'Leady', 'mp-lead-intake' ),
			MP_Lead_Intake_Roles::CAP_VIEW,
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-groups',
			26
		);
	}

	/**
	 * Czy użytkownik ma wstęp na ekran.
	 *
	 * @param int $user_id Użytkownik; 0 = bieżący.
	 * @return bool
	 */
	public static function can_view( $user_id = 0 ) {
		return $user_id > 0
			? user_can( (int) $user_id, MP_Lead_Intake_Roles::CAP_VIEW )
			: current_user_can( MP_Lead_Intake_Roles::CAP_VIEW );
	}

	/**
	 * Czy użytkownik widzi WSZYSTKIE leady, czy tylko własne.
	 *
	 * Rozstrzyga uprawnienie `mp_assign_leads` — ma je manager sprzedaży
	 * i administrator, nie ma handlowiec (class-mp-roles.php). Kto rozdziela
	 * leady, musi widzieć całość; kto je obsługuje, widzi swoje.
	 *
	 * @param int $user_id Użytkownik; 0 = bieżący.
	 * @return bool
	 */
	public static function sees_all( $user_id = 0 ) {
		return $user_id > 0
			? user_can( (int) $user_id, MP_Lead_Intake_Roles::CAP_ASSIGN )
			: current_user_can( MP_Lead_Intake_Roles::CAP_ASSIGN );
	}

	/**
	 * Leady do pokazania wraz z liczbą wszystkich pasujących.
	 *
	 * @param int $strona Numer strony (od 1).
	 * @return array Klucze `wiersze` i `razem`.
	 */
	public static function fetch( $strona = 1 ) {
		global $wpdb;

		$tabela = MP_Lead_Intake_DB::leads_table();
		$offset = ( max( 1, (int) $strona ) - 1 ) * self::PER_PAGE;

		// Zarchiwizowane (soft-delete) nie są leadami do obsługi — są śladem po
		// wykonanym prawie do usunięcia danych i nie mają na tym ekranie czego szukać.
		$where  = 'deleted_at IS NULL';
		$params = array();

		if ( ! self::sees_all() ) {
			$where   .= ' AND salesman_id = %d';
			$params[] = get_current_user_id();
		}

		$sql_count = "SELECT COUNT(*) FROM {$tabela} WHERE {$where}";
		$razem     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $sql_count, $params ) ) // phpcs:ignore WordPress.DB
			: $wpdb->get_var( $sql_count ) ); // phpcs:ignore WordPress.DB

		$sql  = "SELECT * FROM {$tabela} WHERE {$where} ORDER BY score DESC, id DESC LIMIT %d OFFSET %d";
		$args = array_merge( $params, array( self::PER_PAGE, $offset ) );

		$wiersze = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array(
			'wiersze' => $wiersze,
			'razem'   => $razem,
		);
	}

	/**
	 * Rysuje stronę.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::can_view() ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Brak uprawnień do podglądu leadów.', 'mp-lead-intake' )
				. '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sam odczyt, bez zapisu.
		$strona = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$dane   = self::fetch( $strona );
		$oferty = MP_Lead_Intake_DB::get_offers_by_lead_ids( wp_list_pluck( $dane['wiersze'], 'id' ) );

		$per_lead = array();

		foreach ( (array) $oferty as $oferta ) {
			$lid              = isset( $oferta['lead_id'] ) ? (int) $oferta['lead_id'] : 0;
			$per_lead[ $lid ] = isset( $per_lead[ $lid ] ) ? $per_lead[ $lid ] + 1 : 1;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Leady', 'mp-lead-intake' ) . '</h1>';

		echo '<p class="description">' . esc_html(
			self::sees_all()
				/* translators: %d: liczba leadów. */
				? sprintf( _n( 'Wszystkie leady: %d.', 'Wszystkie leady: %d.', $dane['razem'], 'mp-lead-intake' ), $dane['razem'] )
				/* translators: %d: liczba leadów. */
				: sprintf( _n( 'Twoje leady: %d.', 'Twoje leady: %d.', $dane['razem'], 'mp-lead-intake' ), $dane['razem'] )
		) . '</p>';

		if ( empty( $dane['wiersze'] ) ) {
			// Pustka jest stanem normalnym (nikt jeszcze nie wypełnił formularza),
			// więc mówimy o niej wprost zamiast pokazywać pustą tabelę.
			echo '<p>' . esc_html__( 'Nie ma jeszcze żadnych zgłoszeń.', 'mp-lead-intake' ) . '</p></div>';
			return;
		}

		$naglowki = array(
			esc_html__( 'Firma', 'mp-lead-intake' ),
			esc_html__( 'NIP', 'mp-lead-intake' ),
			esc_html__( 'Kontakt', 'mp-lead-intake' ),
			esc_html__( 'Kraj', 'mp-lead-intake' ),
			esc_html__( 'Segment', 'mp-lead-intake' ),
			esc_html__( 'Punktacja', 'mp-lead-intake' ),
			esc_html__( 'Status VAT', 'mp-lead-intake' ),
			esc_html__( 'Handlowiec', 'mp-lead-intake' ),
			esc_html__( 'Oferty', 'mp-lead-intake' ),
			esc_html__( 'Zgłoszono', 'mp-lead-intake' ),
		);

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';

		foreach ( $naglowki as $naglowek ) {
			echo '<th scope="col">' . esc_html( $naglowek ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $dane['wiersze'] as $lead ) {
			$id        = (int) $lead['id'];
			$handlowec = ! empty( $lead['salesman_id'] ) ? get_userdata( (int) $lead['salesman_id'] ) : null;

			echo '<tr>';
			echo '<td>' . esc_html( (string) $lead['company_name'] ) . '</td>';
			echo '<td>' . esc_html( (string) $lead['nip'] ) . '</td>';
			echo '<td>' . esc_html( (string) $lead['email'] );

			if ( ! empty( $lead['phone'] ) ) {
				echo '<br><span class="description">' . esc_html( (string) $lead['phone'] ) . '</span>';
			}

			echo '</td>';
			echo '<td>' . esc_html( (string) $lead['country'] ) . '</td>';
			echo '<td>' . esc_html( (string) $lead['segment'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $lead['score'] ) . '</td>';
			echo '<td>' . esc_html( self::vat_label( (string) $lead['vat_status'] ) ) . '</td>';

			/*
			 * Pusto znaczy „wtyczka 3 jeszcze nie wskazała handlowca albo nie jest
			 * zainstalowana" — od 1.3.7 ta wtyczka nikogo nie przypisuje sama (U-1).
			 * Kreska mówi to wprost, zamiast zostawiać pustą komórkę, o której nie
			 * wiadomo, czy czegoś nie brakuje.
			 */
			$kto = $handlowec instanceof WP_User
				? $handlowec->display_name
				: __( '— nieprzypisany', 'mp-lead-intake' );

			echo '<td>' . esc_html( $kto ) . '</td>';
			echo '<td>' . esc_html( (string) ( isset( $per_lead[ $id ] ) ? $per_lead[ $id ] : 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) $lead['created_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$stron = (int) ceil( $dane['razem'] / self::PER_PAGE );

		if ( $stron > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $strona,
						'total'     => $stron,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * Nazwa stanu weryfikacji VAT dla człowieka.
	 *
	 * Słownik jest ten sam co w Dziale 7 i w weryfikatorze w tle. Wartość spoza
	 * słownika oddajemy surową zamiast podmieniać na „nieznany": nowy stan
	 * dodany bez wpisu tutaj ma być widoczny, a nie ukryty pod etykietą.
	 *
	 * @param string $status Wartość kolumny `vat_status`.
	 * @return string
	 */
	public static function vat_label( $status ) {
		$slownik = array(
			'valid'   => __( 'potwierdzony', 'mp-lead-intake' ),
			'checked' => __( 'sprawdzony', 'mp-lead-intake' ),
			'pending' => __( 'w trakcie sprawdzania', 'mp-lead-intake' ),
			'unknown' => __( 'nieustalony', 'mp-lead-intake' ),
		);

		return isset( $slownik[ $status ] ) ? $slownik[ $status ] : $status;
	}
}
