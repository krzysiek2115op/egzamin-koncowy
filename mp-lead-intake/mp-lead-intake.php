<?php
/**
 * Plugin Name:       MP Lead Intake
 * Plugin URI:        https://github.com/krzysiek2115op/egzamin-koncowy
 * Description:       Przyjęcie i kwalifikacja lead-a z formularza ofertowego WordPress. Pierwszy element procesu formularz → oferta.
 * Version:           1.3.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            krzysiek2115op
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mp-lead-intake
 *
 * Copyright (C) 2026 krzysiek2115op
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software Foundation,
 * Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 * Pelny tekst licencji jest tez w pliku LICENSE obok tego pliku.
 *
 * @package MP_Lead_Intake
 */

// Blokada bezpośredniego wywołania.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Stałe wtyczki ---
define( 'MP_LEAD_INTAKE_VERSION', '1.3.4' );
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

// --- Weryfikacja VAT w tle (async dział 3, poza ścieżką żądania) ---
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-vat-verifier.php';
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-offer-registry.php';
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-privacy.php';

// --- Front: endpoint AJAX ("1 AJAX") i formularz ---
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-ajax.php';
require_once MP_LEAD_INTAKE_DIR . 'includes/class-mp-form.php';

/**
 * Aktywacja wtyczki — tabele BD-3 + role + pod-strona z formularzem.
 */
function mp_lead_intake_activate() {
	MP_Lead_Intake_DB::install();

	// dbDelta() nie rzuca wyjątku przy awarii (np. brak uprawnień CREATE TABLE) —
	// bez tej kontroli wtyczka aktywowałaby się "na sucho" (role, pod-strona, crony),
	// a pierwszym objawem byłby nieczytelny błąd przy pierwszym zgłoszeniu formularza.
	if ( ! MP_Lead_Intake_DB::tables_exist() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'MP Lead Intake: nie udało się utworzyć tabel bazy danych (BD-3). Sprawdź uprawnienia użytkownika bazy danych (CREATE TABLE) i spróbuj aktywować wtyczkę ponownie.', 'mp-lead-intake' ),
			esc_html__( 'Błąd aktywacji wtyczki MP Lead Intake', 'mp-lead-intake' ),
			array( 'back_link' => true )
		);
	}

	MP_Lead_Intake_Roles::create();
	MP_Lead_Intake_Page::create();

	// Retencja RODO: dzienne czyszczenie adresów IP z logu (jeśli nie zaplanowane).
	if ( ! wp_next_scheduled( 'mp_lead_intake_ip_retention' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'mp_lead_intake_ip_retention' );
	}

	// Async dział 3: godzinny reconcile weryfikacji VAT (siatka bezpieczeństwa —
	// odblokowuje zawieszone i dokolejkowuje zaległe 'pending', gdy WP-Cron zawiedzie).
	if ( ! wp_next_scheduled( 'mp_lead_intake_vat_reconcile' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'mp_lead_intake_vat_reconcile' );
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
	// Sprzątanie zaplanowanych zadań (retencja RODO + reconcile weryfikacji VAT).
	wp_clear_scheduled_hook( 'mp_lead_intake_ip_retention' );
	wp_clear_scheduled_hook( 'mp_lead_intake_vat_reconcile' );
}
register_deactivation_hook( __FILE__, 'mp_lead_intake_deactivate' );

/**
 * Bootstrap wtyczki po załadowaniu wszystkich wtyczek.
 */
function mp_lead_intake_bootstrap() {
	load_plugin_textdomain( 'mp-lead-intake', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Hardening: opt-in globalne nagłówki bezpieczeństwa (domyślnie OFF).
	MP_Lead_Intake_Security::register();
	// Ostrzeżenie w panelu, gdy motyw nie wspiera menu WP (jawnie, bez cichej porażki).
	add_action( 'admin_notices', array( 'MP_Lead_Intake_Page', 'maybe_admin_notice' ) );
	// Fallback: gdy motyw nie rejestruje menu WP, spróbuj dołożyć link do
	// wykrytego <nav> bezpośrednio w renderowanym HTML-u (poza panelem admina).
	add_action( 'template_redirect', array( 'MP_Lead_Intake_Page', 'maybe_start_menu_buffer' ) );
	// Odśwież status menu, gdy admin zmieni motyw lub przypisze/zmieni menu —
	// inaczej flaga OPTION_MENU_OK zostaje "lepka" (audyt wydajności, Ś-1).
	add_action( 'switch_theme', array( 'MP_Lead_Intake_Page', 'refresh_menu_status' ) );
	add_action( 'wp_update_nav_menu', array( 'MP_Lead_Intake_Page', 'refresh_menu_status' ) );
	// SEO: meta description na pod-stronie formularza (motyw może nie mieć własnej).
	add_action( 'wp_head', array( 'MP_Lead_Intake_Page', 'maybe_meta_description' ) );
	// Async weryfikacja VAT w tle (kolejkowanie po utworzeniu leada + reconcile).
	MP_Lead_Intake_Vat_Verifier::register();
	// Relacja lead -> oferta z BD-3: nasluch zdarzen modulu ofertowego.
	MP_Lead_Intake_Offer_Registry::register();
	MP_Lead_Intake_Privacy::register();
	// "1 AJAX" — endpoint (drzwi we wtyczce), który uruchamia cały pipeline.
	MP_Lead_Intake_Ajax::register();
	// Formularz B2B (shortcode [mp_lead_intake_form]) + assety.
	MP_Lead_Intake_Form::register();
}
add_action( 'plugins_loaded', 'mp_lead_intake_bootstrap' );
