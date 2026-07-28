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

	/** Nazwa akcji AJAX wyszukiwania produktów (Krok 4.5, ekran budowy oferty). */
	const SEARCH_ACTION = 'mp_offer_builder_search_products';

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
		add_action( 'wp_ajax_' . self::SEARCH_ACTION, array( __CLASS__, 'ajax_search_products' ) );
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
		wp_localize_script(
			'mp-offer-builder-admin',
			'mpOfferBuilder',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'listUrl'      => add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) ),
				'nonce'        => wp_create_nonce( 'mp_offer_builder' ),
				'searchAction' => self::SEARCH_ACTION,
				'submitAction' => MP_Offer_Builder_Ajax::ACTION,
				'i18n'         => array(
					'removeItem'   => __( 'Usuń', 'mp-offer-builder' ),
					'genericError' => __( 'Nie udało się zbudować oferty. Sprawdź dane i spróbuj ponownie.', 'mp-offer-builder' ),
				),
			)
		);
	}

	/**
	 * Obsługa AJAX wyszukiwania: nonce -> capability -> search_products().
	 *
	 * @return void
	 */
	public static function ajax_search_products() {
		if ( ! check_ajax_referer( 'mp_offer_builder', 'mp_ob_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Nieprawidłowy token bezpieczeństwa.', 'mp-offer-builder' ) ), 403 );
		}
		if ( ! current_user_can( MP_OB_D1_Agent_Permission::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'mp-offer-builder' ) ), 403 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_send_json_success( self::search_products( $term ) );
	}

	/**
	 * Wyszukiwanie produktów WYŁĄCZNIE przez oficjalne, publiczne API WooCommerce
	 * (`wc_get_products()`, patrz docs/dzial-02/woocommerce-wc_get_products.md) —
	 * zamiast wewnętrznej, niedokumentowanej akcji WC-core. Wydzielona z
	 * ajax_search_products(), testowalna bez wp_send_json / HTTP (ten sam wzorzec
	 * co MP_Offer_Builder_Download::can_download(), Krok 4.3).
	 *
	 * @param string $term Fraza wyszukiwania (nazwa produktu).
	 * @return array<int,array{id:int,name:string,price:string}>
	 */
	public static function search_products( $term ) {
		$term = trim( (string) $term );
		if ( '' === $term || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				's'      => $term,
				'status' => 'publish',
				'limit'  => 20,
			)
		);

		$results = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$results[] = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'price' => (string) $product->get_regular_price(),
			);
		}
		return $results;
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
	 * Ekran budowy oferty (Krok 4.5): pola klienta gdy brak offer_id, albo
	 * dane klienta tylko-do-odczytu z istniejącego draftu (właściciel
	 * zweryfikowany TĄ SAMĄ metodą co endpoint pobierania PDF, Krok 4.3);
	 * wariant/lang; dynamiczna tabela pozycji (JS, Krok 4.5).
	 *
	 * @return void
	 */
	private static function render_build() {
		$offer_id = isset( $_GET['offer_id'] ) ? absint( $_GET['offer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offer    = null;

		if ( $offer_id > 0 ) {
			$offer = MP_Offer_Builder_DB::get_offer( $offer_id );
			if ( ! $offer ) {
				wp_die( esc_html__( 'Oferta nie istnieje.', 'mp-offer-builder' ), '', array( 'response' => 404 ) );
			}
			if ( ! MP_Offer_Builder_Download::can_download( $offer, get_current_user_id() ) ) {
				wp_die( esc_html__( 'Brak dostępu do tej oferty.', 'mp-offer-builder' ), '', array( 'response' => 403 ) );
			}
			if ( MP_Offer_Builder_DB::STATUS_DRAFT !== (string) $offer['status'] ) {
				/*
				 * Oferta zatwierdzona jest zamrożona: PDF poszedł do klienta, a
				 * plugin 3 prowadzi po niej proces sprzedażowy. Dział 1 pipeline'u
				 * i tak odrzuciłby zapis (przyjmuje offer_id wyłącznie w statusie
				 * draft), ale odbicie się dopiero PO wypełnieniu całego formularza
				 * byłoby dla handlowca karą za cudzy błąd — mówimy od razu.
				 */
				wp_die(
					esc_html__( 'Oferta jest już zatwierdzona i nie można jej edytować. Zmiany wprowadź, budując nową ofertę dla tego klienta.', 'mp-offer-builder' ),
					'',
					array( 'response' => 409 )
				);
			}
		}

		// Pozycje do wypełnienia formularza przy edycji (nazwa produktu dociągana
		// przez wc_get_product wyłącznie do wyświetlenia w polu wyszukiwarki —
		// zapis i tak waliduje product_id niezależnie w Dziale 2/3).
		$prefill_items = array();
		if ( $offer ) {
			foreach ( MP_Offer_Builder_DB::get_offer_items( $offer_id ) as $it ) {
				$pid  = (int) $it['product_id'];
				$name = '';
				if ( function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $pid );
					$name    = $product ? $product->get_name() : '';
				}
				$prefill_items[] = array(
					'product_id'   => $pid,
					'variation_id' => null !== $it['variation_id'] ? (int) $it['variation_id'] : '',
					'qty'          => (int) $it['qty'],
					'name'         => $name,
				);
			}
		}
		?>
		<div class="wrap mp-offer-builder-build">
			<h1><?php esc_html_e( 'Budowa oferty', 'mp-offer-builder' ); ?></h1>
			<form id="mp-offer-builder-form">
				<?php wp_nonce_field( 'mp_offer_builder', 'mp_ob_nonce' ); ?>
				<input type="hidden" name="offer_id" value="<?php echo esc_attr( $offer_id ); ?>" />

				<h2><?php esc_html_e( 'Klient', 'mp-offer-builder' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="mp-ob-client-name"><?php esc_html_e( 'Nazwa firmy', 'mp-offer-builder' ); ?></label></th>
						<td>
							<?php if ( $offer ) : ?>
								<input type="text" id="mp-ob-client-name" value="<?php echo esc_attr( $offer['client_name'] ); ?>" readonly="readonly" />
							<?php else : ?>
								<input type="text" id="mp-ob-client-name" name="client[name]" required="required" />
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="mp-ob-client-email"><?php esc_html_e( 'E-mail', 'mp-offer-builder' ); ?></label></th>
						<td>
							<?php if ( $offer ) : ?>
								<input type="email" id="mp-ob-client-email" value="<?php echo esc_attr( $offer['client_email'] ); ?>" readonly="readonly" />
							<?php else : ?>
								<input type="email" id="mp-ob-client-email" name="client[email]" required="required" />
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="mp-ob-client-nip"><?php esc_html_e( 'NIP', 'mp-offer-builder' ); ?></label></th>
						<td>
							<?php if ( $offer ) : ?>
								<input type="text" id="mp-ob-client-nip" value="<?php echo esc_attr( $offer['client_nip'] ); ?>" readonly="readonly" />
							<?php else : ?>
								<input type="text" id="mp-ob-client-nip" name="client[nip]" required="required" />
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="mp-ob-client-country"><?php esc_html_e( 'Kraj (ISO 3166-1 alfa-2)', 'mp-offer-builder' ); ?></label></th>
						<td>
							<?php if ( $offer ) : ?>
								<input type="text" id="mp-ob-client-country" value="<?php echo esc_attr( $offer['client_country'] ); ?>" readonly="readonly" />
							<?php else : ?>
								<input type="text" id="mp-ob-client-country" name="client[country]" maxlength="2" required="required" />
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( ! $offer ) : ?>
						<tr>
							<th><label for="mp-ob-client-vat-valid"><?php esc_html_e( 'VAT UE potwierdzony', 'mp-offer-builder' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" id="mp-ob-client-vat-valid" value="1" />
									<?php esc_html_e( 'Klient z UE, którego numer VAT UE potwierdziłem jako ważny (np. w VIES). Zaznacz TYLKO w takim przypadku — włącza odwrotne obciążenie (0% VAT, art. 196 dyrektywy 2006/112/WE). Niezaznaczone = stawka krajowa.', 'mp-offer-builder' ); ?>
								</label>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<?php
				/*
				 * Prośba klienta prosto z formularza (plugin 1). Bez tego bloku
				 * handlowiec musiał otworzyć listę leadów w drugiej wtyczce i
				 * przepisać treść ręcznie — zlecenie wymaga procesu „bez ręcznego
				 * kopiowania danych". Tylko do odczytu i celowo NIE zamieniane na
				 * pozycje oferty: to zdanie po polsku, nie koszyk.
				 */
				$lead_products = $offer && ! empty( $offer['lead_products'] ) ? (string) $offer['lead_products'] : '';
				$lead_volume   = $offer && ! empty( $offer['lead_est_volume'] ) ? (string) $offer['lead_est_volume'] : '';
				?>
				<?php if ( '' !== $lead_products || '' !== $lead_volume ) : ?>
					<h2><?php esc_html_e( 'Czego szukał klient (z formularza)', 'mp-offer-builder' ); ?></h2>
					<table class="form-table">
						<?php if ( '' !== $lead_products ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Produkty / zakres zainteresowania', 'mp-offer-builder' ); ?></th>
								<td><p class="description" style="white-space:pre-wrap;"><?php echo esc_html( $lead_products ); ?></p></td>
							</tr>
						<?php endif; ?>
						<?php if ( '' !== $lead_volume ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Przewidywany wolumen', 'mp-offer-builder' ); ?></th>
								<td><p class="description"><?php echo esc_html( $lead_volume ); ?></p></td>
							</tr>
						<?php endif; ?>
					</table>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Wariant i język', 'mp-offer-builder' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="mp-ob-wariant"><?php esc_html_e( 'Wariant cenowy', 'mp-offer-builder' ); ?></label></th>
						<td>
							<select id="mp-ob-wariant" name="wariant">
								<option value="standard"><?php esc_html_e( 'Standard', 'mp-offer-builder' ); ?></option>
								<option value="partner"><?php esc_html_e( 'Partner', 'mp-offer-builder' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="mp-ob-lang"><?php esc_html_e( 'Język oferty', 'mp-offer-builder' ); ?></label></th>
						<td>
							<select id="mp-ob-lang" name="lang">
								<option value="pl"><?php esc_html_e( 'Polski', 'mp-offer-builder' ); ?></option>
								<option value="en">English</option>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pozycje oferty', 'mp-offer-builder' ); ?></h2>
				<table class="widefat mp-offer-builder-items" id="mp-ob-items-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Produkt (wyszukaj)', 'mp-offer-builder' ); ?></th>
							<th><?php esc_html_e( 'ID produktu', 'mp-offer-builder' ); ?></th>
							<th><?php esc_html_e( 'ID wariantu (opcjonalnie)', 'mp-offer-builder' ); ?></th>
							<th><?php esc_html_e( 'Ilość', 'mp-offer-builder' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="mp-ob-items-body"></tbody>
				</table>
				<script type="application/json" id="mp-ob-prefill-items"><?php echo wp_json_encode( $prefill_items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
				<p>
					<button type="button" class="button" id="mp-ob-add-item"><?php esc_html_e( 'Dodaj pozycję', 'mp-offer-builder' ); ?></button>
				</p>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Zapisz i wygeneruj ofertę', 'mp-offer-builder' ); ?></button>
				</p>
				<div id="mp-ob-form-message" role="alert"></div>
			</form>
		</div>
		<?php
	}
}
