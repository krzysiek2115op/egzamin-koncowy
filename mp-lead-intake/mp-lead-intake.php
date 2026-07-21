<?php
/**
 * Plugin Name:       MP Lead Intake
 * Plugin URI:        https://github.com/krzysiek2115op/egzamin-koncowy
 * Description:       Przyjęcie i kwalifikacja lead-a z formularza ofertowego WordPress. Pierwszy element procesu formularz → oferta.
 * Version:           0.9.0
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
define( 'MP_LEAD_INTAKE_VERSION', '0.9.0' );
define( 'MP_LEAD_INTAKE_FILE', __FILE__ );
define( 'MP_LEAD_INTAKE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MP_LEAD_INTAKE_URL', plugin_dir_url( __FILE__ ) );

// --- Warstwa bazy danych (BD-3) ---
require_once MP_LEAD_INTAKE_DIR . 'includes/db/class-mp-db.php';

// --- Warstwa pipeline (11 działów: agenci, krytycy, bramki jakości) ---
require_once MP_LEAD_INTAKE_DIR . 'includes/pipeline/bootstrap.php';

// --- Infrastruktura: role i pod-strona ---
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-roles.php';
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-page.php';

// --- Front: endpoint AJAX ("1 AJAX") i formularz ---
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-ajax.php';
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-form.php';

/**
 * Aktywacja wtyczki — tabele BD-3 + role + pod-strona z formularzem.
 */
function mp_lead_intake_activate() {
	MP_Lead_Intake_DB::install();
	MP_Lead_Intake_Roles::create();
	MP_Lead_Intake_Page::create();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'mp_lead_intake_activate' );

// Aktualizacja schematu bazy po podbiciu wersji (bez potrzeby reaktywacji).
add_action( 'admin_init', array( 'MP_Lead_Intake_DB', 'maybe_upgrade' ) );

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

	// "1 AJAX" — endpoint (drzwi we wtyczce), który uruchamia cały pipeline.
	MP_Lead_Intake_Ajax::register();
	// Formularz B2B (shortcode [mp_lead_intake_form]) + assety.
	MP_Lead_Intake_Form::register();
}
add_action( 'plugins_loaded', 'mp_lead_intake_bootstrap' );
