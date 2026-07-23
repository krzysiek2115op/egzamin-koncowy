<?php
/**
 * Plugin Name:       MP Offer Builder
 * Plugin URI:        https://github.com/krzysiek2115op/egzamin-koncowy
 * Description:       Kalkulacja cenowa, integracja z WooCommerce, generowanie ofert PDF. Drugi element procesu formularz → oferta.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            krzysiek2115op
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mp-offer-builder
 *
 * @package MP_Offer_Builder
 */

// Blokada bezpośredniego wywołania.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Stałe wtyczki ---
define( 'MP_OFFER_BUILDER_VERSION', '0.1.0' );
define( 'MP_OFFER_BUILDER_FILE', __FILE__ );
define( 'MP_OFFER_BUILDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'MP_OFFER_BUILDER_URL', plugin_dir_url( __FILE__ ) );

// --- Warstwa bazy danych (BD-2) ---
require_once MP_OFFER_BUILDER_DIR . 'includes/db/class-mp-offer-builder-db.php';

/**
 * Aktywacja wtyczki — tabele BD-2.
 */
function mp_offer_builder_activate() {
	MP_Offer_Builder_DB::install();

	// dbDelta() nie rzuca wyjątku przy awarii (np. brak uprawnień CREATE TABLE) —
	// bez tej kontroli wtyczka aktywowałaby się "na sucho", a pierwszym objawem
	// byłby nieczytelny błąd przy pierwszej próbie utworzenia oferty.
	if ( ! MP_Offer_Builder_DB::tables_exist() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'MP Offer Builder: nie udało się utworzyć tabel bazy danych (BD-2). Sprawdź uprawnienia użytkownika bazy danych (CREATE TABLE) i spróbuj aktywować wtyczkę ponownie.', 'mp-offer-builder' ),
			esc_html__( 'Błąd aktywacji wtyczki MP Offer Builder', 'mp-offer-builder' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( MP_OFFER_BUILDER_FILE, 'mp_offer_builder_activate' );
