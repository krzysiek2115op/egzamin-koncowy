<?php
/**
 * Panel wp-admin handlowca — menu, router listy/budowy oferty (Krok 4,
 * decyzja architektoniczna B: UI budowania oferty żyje w pluginie 2, nie 3).
 *
 * Renderowanie (WP_List_Table, formularz budowy) NIE jest testowalne w
 * headless harnessie CLI (wymaga żywego wp-admin) — logika autoryzacji,
 * którą to renderowanie wykorzystuje (`MP_Offer_Builder_Download::can_download()`),
 * jest już pokryta osobno (Krok 4.3).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejestracja menu wp-admin i router widoków ('list' domyślnie / 'build').
 */
class MP_Offer_Builder_Admin {

	/** Slug strony menu i parametru $_GET['page']. */
	const PAGE_SLUG = 'mp-offer-builder';

	/**
	 * Zwrócony przez add_menu_page() — do porównania w admin_enqueue_scripts (WYŁĄCZNIE własny hook).
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * @return void
	 */
	public static function add_menu() {
		self::$hook_suffix = add_menu_page(
			__( 'Oferty', 'mp-offer-builder' ),
			__( 'MP Offer Builder', 'mp-offer-builder' ),
			MP_OB_D1_Agent_Permission::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-media-document'
		);
	}

	/**
	 * Ładuje JS/CSS WYŁĄCZNIE na własnym ekranie (nie na każdym $hook_suffix wp-admin).
	 *
	 * @param string $hook_suffix Bieżący hook ekranu wp-admin.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== self::$hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'mp-offer-builder-admin',
			MP_OFFER_BUILDER_URL . 'assets/css/mp-offer-builder-admin.css',
			array(),
			MP_OFFER_BUILDER_VERSION
		);
		wp_enqueue_script(
			'mp-offer-builder-admin',
			MP_OFFER_BUILDER_URL . 'assets/js/mp-offer-builder-admin.js',
			array(),
			MP_OFFER_BUILDER_VERSION,
			true
		);
	}

	/**
	 * Router: 'build' -> ekran budowy oferty (Krok 4.5), inaczej lista.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( MP_OB_D1_Agent_Permission::CAPABILITY ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'mp-offer-builder' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'build' === $view ) {
			self::render_build();
			return;
		}

		self::render_list();
	}

	/**
	 * Lista ofert (Krok 4.4) — WP_List_Table ładowana leniwie, tylko tutaj.
	 *
	 * @return void
	 */
	private static function render_list() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once __DIR__ . '/class-mp-offer-builder-list-table.php';

		$table = new MP_Offer_Builder_List_Table();
		$table->prepare_items();

		$build_url = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'view' => 'build',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Oferty', 'mp-offer-builder' ); ?></h1>
			<a href="<?php echo esc_url( $build_url ); ?>" class="page-title-action"><?php esc_html_e( 'Nowa oferta', 'mp-offer-builder' ); ?></a>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php $table->search_box( __( 'Szukaj', 'mp-offer-builder' ), 'mp-offer-builder-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Ekran budowy oferty — treść realna dopiero w Kroku 4.5.
	 *
	 * @return void
	 */
	private static function render_build() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Budowa oferty', 'mp-offer-builder' ); ?></h1>
		</div>
		<?php
	}
}
