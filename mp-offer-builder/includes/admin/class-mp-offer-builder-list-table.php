<?php
/**
 * Tabela ofert w panelu wp-admin (Krok 4, decyzja B — UI budowania oferty w
 * pluginie 2). Klasa bazowa `WP_List_Table` żyje WYŁĄCZNIE w wp-admin — ten
 * plik jest ładowany leniwie, dopiero w `MP_Offer_Builder_Admin::render_list()`,
 * nigdy przy bootstrapie wtyczki (patrz `require_once` tuż przed użyciem tam).
 *
 * Dostęp do wierszy: `created_by` wymuszony W ZAPYTANIU dla użytkownika bez
 * `manage_options` (Krok 4, decyzja własności ofert) — nie tylko ukryty w UI.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lista ofert — kolumny, filtry, paginacja przez MP_Offer_Builder_DB::list_offers()/count_offers().
 */
class MP_Offer_Builder_List_Table extends WP_List_Table {

	/** Liczba wierszy na stronę. */
	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'mp_ob_offer',
				'plural'   => 'mp_ob_offers',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'offer_number' => __( 'Numer', 'mp-offer-builder' ),
			'client_name'  => __( 'Klient', 'mp-offer-builder' ),
			'status'       => __( 'Status', 'mp-offer-builder' ),
			'gross_grosze' => __( 'Brutto', 'mp-offer-builder' ),
			'version'      => __( 'Wersja', 'mp-offer-builder' ),
			'updated_at'   => __( 'Zaktualizowano', 'mp-offer-builder' ),
			'actions'      => __( 'Akcje', 'mp-offer-builder' ),
		);
	}

	/**
	 * Buduje filtry z żądania, wymusza izolację właściciela dla nie-adminów,
	 * i pobiera stronę wyników + łączną liczbę z BD-2 (Krok 4.1).
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page     = self::PER_PAGE;
		$current_page = $this->get_pagenum();

		$args = array(
			'per_page' => $per_page,
			'offset'   => ( $current_page - 1 ) * $per_page,
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			// Wymuszone w zapytaniu (nie tylko w UI) — nie-admin nigdy nie widzi cudzej oferty.
			$args['created_by'] = get_current_user_id();
		}

		if ( ! empty( $_GET['offer_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['status'] = sanitize_text_field( wp_unslash( $_GET['offer_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$total_items = MP_Offer_Builder_DB::count_offers( $args );
		$this->items = MP_Offer_Builder_DB::list_offers( $args );

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * @param array  $item        Wiersz oferty.
	 * @param string $column_name Nazwa kolumny.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'offer_number':
				return empty( $item['offer_number'] ) ? esc_html__( 'SZKIC', 'mp-offer-builder' ) : esc_html( $item['offer_number'] );
			case 'client_name':
				return esc_html( (string) $item['client_name'] );
			case 'status':
				return esc_html( (string) $item['status'] );
			case 'gross_grosze':
				return esc_html( number_format_i18n( ( (int) $item['gross_grosze'] ) / 100, 2 ) . ' ' . (string) $item['currency'] );
			case 'version':
				return esc_html( (string) $item['version'] );
			case 'updated_at':
				return esc_html( (string) $item['updated_at'] );
			case 'actions':
				return $this->column_actions( $item );
			default:
				return '';
		}
	}

	/**
	 * Odnośniki „Dokończ”/„Edytuj” (do ekranu budowy, Krok 4.5) i „Pobierz PDF”
	 * (TYM SAMYM wzorcem linku co Dział 11 — MP_OB_D11_Agent_Response) gdy plik istnieje.
	 *
	 * @param array $item Wiersz oferty.
	 * @return string
	 */
	public function column_actions( array $item ) {
		$offer_id  = (int) $item['id'];
		$build_url = add_query_arg(
			array(
				'page'     => MP_Offer_Builder_Admin::PAGE_SLUG,
				'view'     => 'build',
				'offer_id' => $offer_id,
			),
			admin_url( 'admin.php' )
		);
		$label     = empty( $item['offer_number'] ) ? __( 'Dokończ', 'mp-offer-builder' ) : __( 'Edytuj', 'mp-offer-builder' );

		$links   = array();
		$links[] = '<a href="' . esc_url( $build_url ) . '">' . esc_html( $label ) . '</a>';

		if ( ! empty( $item['pdf_path'] ) ) {
			$pdf_url = add_query_arg(
				array(
					'action'   => MP_OB_D11_Agent_Response::DOWNLOAD_ACTION,
					'offer_id' => $offer_id,
					'_wpnonce' => wp_create_nonce( MP_OB_D11_Agent_Response::DOWNLOAD_ACTION . '_' . $offer_id ),
				),
				admin_url( 'admin-ajax.php' )
			);
			$links[] = '<a href="' . esc_url( $pdf_url ) . '">' . esc_html__( 'Pobierz PDF', 'mp-offer-builder' ) . '</a>';
		}

		return implode( ' | ', $links );
	}
}
