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

// --- Warstwa bazy danych (BD-1) ---
require_once MP_SALES_WORKFLOW_DIR . 'includes/db/class-mp-sales-workflow-db.php';

// --- Role, uprawnienia i szablony powiadomień ---
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-roles.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-templates.php';

// --- Pipeline (9 działów LP.3) ---
require_once MP_SALES_WORKFLOW_DIR . 'includes/pipeline/bootstrap.php';

/**
 * Aktywacja wtyczki — założenie tabel BD-1.
 *
 * Nieudane założenie tabel jest zgłaszane jawnie (wyjątek przerywa aktywację
 * i WordPress pokazuje komunikat), zamiast zostawiać aktywną wtyczkę bez bazy —
 * każde kolejne żądanie kończyłoby się wtedy błędem zapisu.
 *
 * @return void
 */
function mp_sales_workflow_activate() {
	if ( ! MP_Sales_Workflow_DB::install() ) {
		wp_die(
			esc_html__( 'MP Sales Workflow: nie udało się utworzyć tabel bazy danych. Sprawdź uprawnienia użytkownika bazy i spróbuj ponownie.', 'mp-sales-workflow' )
		);
	}

	// Role są warunkiem działania Działu 3 (zakres roli) i kryterium odbioru
	// ze zlecenia — bez nich Dział 2 zatrzymuje pipeline jako FAIL_FATAL.
	if ( ! MP_SW_Roles::install() ) {
		wp_die(
			esc_html__( 'MP Sales Workflow: nie udało się utworzyć ról (handlowiec, manager sprzedaży). Sprawdź uprawnienia i spróbuj ponownie.', 'mp-sales-workflow' )
		);
	}
}
register_activation_hook( __FILE__, 'mp_sales_workflow_activate' );

/**
 * Migracja schematu po aktualizacji plików wtyczki.
 *
 * Hook aktywacji NIE uruchamia się przy podmianie plików (FTP, deploy), więc bez
 * tego sprawdzenia zaktualizowana witryna zostałaby ze starym schematem.
 *
 * @return void
 */
function mp_sales_workflow_maybe_upgrade() {
	MP_Sales_Workflow_DB::maybe_upgrade();

	// Role bywają usuwane ręcznie albo przez inne wtyczki zarządzające
	// uprawnieniami; odtwarzamy je bez czekania na ponowną aktywację.
	if ( ! MP_SW_Roles::roles_exist() ) {
		MP_SW_Roles::install();
	}
}
add_action( 'plugins_loaded', 'mp_sales_workflow_maybe_upgrade' );
