<?php
/**
 * Plugin Name:       MP Sales Workflow
 * Plugin URI:        https://github.com/krzysiek2115op/egzamin-koncowy
 * Description:       Przypisanie handlowca, statusy procesu, powiadomienia e-mail, zadania follow-up, dashboard i dziennik aktywności. Trzeci element procesu formularz → oferta.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            krzysiek2115op
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mp-sales-workflow
 *
 * @package MP_Sales_Workflow
 */

// Blokada bezpośredniego wywołania.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Stałe wtyczki ---
define( 'MP_SALES_WORKFLOW_VERSION', '0.1.0' );
define( 'MP_SALES_WORKFLOW_FILE', __FILE__ );
define( 'MP_SALES_WORKFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'MP_SALES_WORKFLOW_URL', plugin_dir_url( __FILE__ ) );
