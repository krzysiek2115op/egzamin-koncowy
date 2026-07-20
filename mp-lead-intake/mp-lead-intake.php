<?php
/**
 * Plugin Name:       MP Lead Intake
 * Plugin URI:        https://github.com/krzysiek2115op/egzamin-koncowy
 * Description:       Przyjęcie i kwalifikacja lead-a z formularza ofertowego WordPress. Pierwszy element procesu formularz → oferta.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            krzysiek2115op
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mp-lead-intake
 *
 * @package MP_Lead_Intake
 */

// Blokada bezpośredniego wywołania.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Stałe wtyczki ---
define( 'MP_LEAD_INTAKE_VERSION', '0.1.0' );
define( 'MP_LEAD_INTAKE_FILE', __FILE__ );
define( 'MP_LEAD_INTAKE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MP_LEAD_INTAKE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Aktywacja wtyczki.
 *
 * Tu (w kolejnym etapie) powstanie tabela bazy danych dla lead-ów
 * — pierwsza z trzech baz projektu.
 */
function mp_lead_intake_activate() {
	// TODO(etap: baza danych): utworzyć tabelę {$wpdb->prefix}mp_leads przez dbDelta().
}
register_activation_hook( __FILE__, 'mp_lead_intake_activate' );

/**
 * Deaktywacja wtyczki (bez usuwania danych — to robi uninstall.php).
 */
function mp_lead_intake_deactivate() {
	// TODO(etap: cron/kolejki): wyczyścić zaplanowane zadania, jeśli będą.
}
register_deactivation_hook( __FILE__, 'mp_lead_intake_deactivate' );

/**
 * Bootstrap wtyczki po załadowaniu wszystkich wtyczek.
 */
function mp_lead_intake_bootstrap() {
	load_plugin_textdomain( 'mp-lead-intake', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// TODO(etap: przyjęcie lead-a): podpiąć obsługę formularza (REST / admin-post / hook wtyczki formularzy).
	// TODO(etap: kwalifikacja): reguły scoringu lead-a.
}
add_action( 'plugins_loaded', 'mp_lead_intake_bootstrap' );
