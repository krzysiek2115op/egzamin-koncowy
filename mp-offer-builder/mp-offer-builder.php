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
