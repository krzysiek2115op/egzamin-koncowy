<?php
/**
 * Plugin Name:       MP Offer Builder
 * Plugin URI:        https://github.com/krzysiek2115op/egzamin-koncowy
 * Description:       Kalkulacja cenowa, integracja z WooCommerce, generowanie ofert PDF. Drugi element procesu formularz → oferta.
 * Version:           1.2.0
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
define( 'MP_OFFER_BUILDER_VERSION', '1.2.0' );
define( 'MP_OFFER_BUILDER_FILE', __FILE__ );
define( 'MP_OFFER_BUILDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'MP_OFFER_BUILDER_URL', plugin_dir_url( __FILE__ ) );

// --- Zależności runtime (Dompdf — Dział 9, Render PDF) ---
// Kontrolowany brak zamiast fatal errora, gdy ktoś wdroży kod bez
// `composer install` — Agent 9.1 i tak sprawdza class_exists() ponownie
// (ten sam wzorzec co WooCommerce/BCMath/intl w innych działach).
if ( file_exists( MP_OFFER_BUILDER_DIR . 'vendor/autoload.php' ) ) {
	require_once MP_OFFER_BUILDER_DIR . 'vendor/autoload.php';
}

// --- Warstwa bazy danych (BD-2) ---
require_once MP_OFFER_BUILDER_DIR . 'includes/db/class-mp-offer-builder-db.php';

// --- Przechowywanie plików PDF (katalog prywatny — decyzja architektoniczna C) ---
require_once MP_OFFER_BUILDER_DIR . 'includes/class-mp-offer-builder-storage.php';

// --- Warstwa pipeline (11 działów: agenci, krytycy, bramki jakości) ---
require_once MP_OFFER_BUILDER_DIR . 'includes/pipeline/bootstrap.php';

// --- Front: endpoint AJAX ("1 AJAX") ---
require_once MP_OFFER_BUILDER_DIR . 'includes/class-mp-offer-builder-ajax.php';

// --- Integracja z pluginem 1: automatyczny szkic oferty po utworzeniu leada ---
require_once MP_OFFER_BUILDER_DIR . 'includes/class-mp-offer-builder-lead-listener.php';

// --- Chroniony endpoint pobierania PDF (Krok 4, decyzja architektoniczna C) ---
require_once MP_OFFER_BUILDER_DIR . 'includes/class-mp-offer-builder-download.php';

// --- Panel wp-admin handlowca (Krok 4, decyzja architektoniczna B) ---
// class-mp-offer-builder-list-table.php ładowany leniwie wewnątrz Admin::render(),
// NIE tutaj — klasa bazowa WP_List_Table żyje wyłącznie w wp-admin.
require_once MP_OFFER_BUILDER_DIR . 'includes/admin/class-mp-offer-builder-admin.php';

// Zatwierdzenie oferty (krok 4 zlecenia) — status `approved` + `mp_offer_approved`.
require_once MP_OFFER_BUILDER_DIR . 'includes/class-mp-offer-builder-approval.php';

// Zgodność RODO (SR5-01): eksport/anonimizacja danych osobowych klienta.
require_once MP_OFFER_BUILDER_DIR . 'includes/class-mp-offer-builder-privacy.php';

/**
 * Bootstrap wtyczki po załadowaniu wszystkich wtyczek.
 *
 * @return void
 */
function mp_offer_builder_bootstrap() {
	load_plugin_textdomain( 'mp-offer-builder', false, dirname( plugin_basename( MP_OFFER_BUILDER_FILE ) ) . '/languages' );

	// Ścieżka aktualizacji schematu BD-2 dla instalacji aktywowanych PRZED
	// zmianą (register_activation_hook nie odpala się przy zwykłej podmianie
	// plików wtyczki, tylko przy jej (re)aktywacji) — patrz docblock maybe_upgrade().
	MP_Offer_Builder_DB::maybe_upgrade();

	// "1 AJAX" — endpoint (drzwi we wtyczce), który uruchamia cały pipeline.
	MP_Offer_Builder_Ajax::register();
	// mp_lead_created (plugin 1) → automatyczny szkic oferty (Krok 2.5).
	MP_Offer_Builder_Lead_Listener::register();
	// Chroniony endpoint pobierania PDF (nonce + capability + właściciel, Krok 4.3).
	MP_Offer_Builder_Download::register();
	// Panel wp-admin handlowca: menu, lista, ekran budowy (Krok 4.4/4.5).
	MP_Offer_Builder_Admin::register();
	// Zatwierdzenie oferty z listy (krok 4 zlecenia) → mp_offer_approved.
	MP_Offer_Builder_Approval::register();
	MP_Offer_Builder_Privacy::register();
}
add_action( 'plugins_loaded', 'mp_offer_builder_bootstrap' );

/**
 * SR3-02: ostrzeżenie dla administratora, gdy serwer to nginx — wtedy pliki
 * .htaccess (obrona w głąb katalogu prywatnego PDF) są IGNOROWANE, a poufność
 * ofert opiera się wyłącznie na nieodgadywalnej nazwie pliku (HMAC). Podpowiada
 * gotowy blok konfiguracji blokujący bezpośredni dostęp do katalogu.
 *
 * @return void
 */
function mp_offer_builder_nginx_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
	if ( false === stripos( $server, 'nginx' ) ) {
		return;
	}
	if ( get_user_meta( get_current_user_id(), 'mp_ob_nginx_notice_dismissed', true ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>MP Offer Builder:</strong> ';
	echo esc_html__( 'Wykryto serwer nginx. Pliki .htaccess NIE chronią katalogu ofert PDF — dodaj do konfiguracji nginx blok blokujący bezpośredni dostęp:', 'mp-offer-builder' );
	echo '</p><pre style="background:#f6f7f7;padding:8px;overflow:auto">location ~* /uploads/mp-offer-builder-private/ { deny all; return 403; }</pre>';
	echo '<p>' . esc_html__( 'Oferty pozostają chronione endpointem (nonce + uprawnienie + właściciel) oraz nieodgadywalną nazwą pliku, ale to ustawienie domyka obronę w głąb.', 'mp-offer-builder' ) . '</p></div>';
}
add_action( 'admin_notices', 'mp_offer_builder_nginx_notice' );

/**
 * Aktywacja wtyczki — tabele BD-2.
 */
function mp_offer_builder_activate() {
	MP_Offer_Builder_DB::install();

	// Dział 1, Agent 1.2 "uprawnienie" wymaga tej capability. Przypisana adminowi
	// jako bezpieczny domyślny właściciel — inne role dostają ją ręcznie (wp-admin
	// → Użytkownicy → Role, albo pluginem do zarządzania rolami), gdy klient
	// zdecyduje, kto konkretnie ma być "handlowcem" (poza zakresem BD-2/pipeline'u).
	$admin = get_role( 'administrator' );
	if ( $admin && ! $admin->has_cap( 'mp_offer_builder_manage_offers' ) ) {
		$admin->add_cap( 'mp_offer_builder_manage_offers' );
	}

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
