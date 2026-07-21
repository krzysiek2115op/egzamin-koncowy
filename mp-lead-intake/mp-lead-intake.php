<?php
/**
 * Plugin Name:       MP Lead Intake
 * Plugin URI:        https://github.com/krzysiek2115op/egzamin-koncowy
 * Description:       Przyjęcie i kwalifikacja lead-a z formularza ofertowego WordPress. Pierwszy element procesu formularz → oferta.
 * Version:           1.0.0
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
define( 'MP_LEAD_INTAKE_VERSION', '1.0.0' );
define( 'MP_LEAD_INTAKE_FILE', __FILE__ );
define( 'MP_LEAD_INTAKE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MP_LEAD_INTAKE_URL', plugin_dir_url( __FILE__ ) );

// --- Warstwa bazy danych (BD-3) ---
require_once MP_LEAD_INTAKE_DIR . 'includes/db/class-mp-db.php';

// --- Warstwa pipeline (11 działów: agenci, krytycy, bramki jakości) ---
require_once MP_LEAD_INTAKE_DIR . 'includes/pipeline/bootstrap.php';

// --- Infrastruktura: role, pod-strona, hardening ---
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-roles.php';
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-page.php';
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-security.php';

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

	// Retencja RODO: dzienne czyszczenie adresów IP z logu (jeśli nie zaplanowane).
	if ( ! wp_next_scheduled( 'mp_lead_intake_ip_retention' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'mp_lead_intake_ip_retention' );
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'mp_lead_intake_activate' );

// Aktualizacja schematu bazy po podbiciu wersji (bez potrzeby reaktywacji).
add_action( 'admin_init', array( 'MP_Lead_Intake_DB', 'maybe_upgrade' ) );

// Retencja RODO: cron usuwa adresy IP z logu starsze niż okres retencji (90 dni).
add_action( 'mp_lead_intake_ip_retention', array( 'MP_Lead_Intake_DB', 'purge_old_ip_addresses' ) );

/**
 * Deaktywacja wtyczki (bez usuwania danych — to robi uninstall.php).
 */
function mp_lead_intake_deactivate() {
	// Sprzątanie zaplanowanych zadań (retencja RODO).
	wp_clear_scheduled_hook( 'mp_lead_intake_ip_retention' );
}
register_deactivation_hook( __FILE__, 'mp_lead_intake_deactivate' );

/**
 * Bootstrap wtyczki po załadowaniu wszystkich wtyczek.
 */
function mp_lead_intake_bootstrap() {
	load_plugin_textdomain( 'mp-lead-intake', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Hardening: opt-in globalne nagłówki bezpieczeństwa (domyślnie OFF).
	MP_Lead_Intake_Security::register();
	// "1 AJAX" — endpoint (drzwi we wtyczce), który uruchamia cały pipeline.
	MP_Lead_Intake_Ajax::register();
	// Formularz B2B (shortcode [mp_lead_intake_form]) + assety.
	MP_Lead_Intake_Form::register();
}
add_action( 'plugins_loaded', 'mp_lead_intake_bootstrap' );
