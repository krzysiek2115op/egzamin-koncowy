<?php
/**
 * Plugin Name:       MP Sales Workflow
 * Plugin URI:        https://github.com/krzysiek2115op/egzamin-koncowy
 * Description:       Przypisanie handlowca, statusy procesu, powiadomienia e-mail, zadania follow-up, dashboard i dziennik aktywności. Trzeci element procesu formularz → oferta.
 * Version:           1.3.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            krzysiek2115op
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mp-sales-workflow
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
 * @package MP_Sales_Workflow
 */

// Blokada bezpośredniego wywołania.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Stałe wtyczki ---
define( 'MP_SALES_WORKFLOW_VERSION', '1.3.4' );
define( 'MP_SALES_WORKFLOW_FILE', __FILE__ );
define( 'MP_SALES_WORKFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'MP_SALES_WORKFLOW_URL', plugin_dir_url( __FILE__ ) );

// --- Warstwa bazy danych (BD-1) ---
require_once MP_SALES_WORKFLOW_DIR . 'includes/db/class-mp-sales-workflow-db.php';

// --- Bezpieczeństwo: kody błędów, dziennik techniczny, macierz pochodzenia ---
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-errors.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-log.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-origin.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-meta-guard.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-download.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-mailer.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-privacy.php';

// --- Role, uprawnienia i szablony powiadomień ---
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-roles.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-templates.php';

// --- Pipeline (9 działów LP.3) ---
require_once MP_SALES_WORKFLOW_DIR . 'includes/pipeline/bootstrap.php';

// --- Warstwa techniczna: wejście, harmonogram, kolejka, panel ---
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-events.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-ajax.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-queue.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-cron.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-hooks.php';
require_once MP_SALES_WORKFLOW_DIR . 'includes/admin/class-mp-sw-admin.php';

/*
 * Wyjątek od reguły „wszystko z `boot()`": obsługa podpisanego linku do oferty
 * musi wisieć na `init` z priorytetem NIŻSZYM niż `boot()`, a callback dopięty
 * z wnętrza już wykonywanego `init` na niższym priorytecie zostałby w tym
 * przebiegu pominięty — czyli nigdy by się nie wykonał. Dlatego wpinamy go
 * przy ładowaniu pliku. Sama rejestracja niczego nie czyta z bazy.
 */
MP_SW_Download::register();

/**
 * Wpina warstwę techniczną.
 *
 * Wszystko dzieje się na `init`, a nie przy ładowaniu pliku: role i tabele mogą
 * jeszcze nie istnieć w chwili `require`, a nasłuch haków wtyczek 1 i 2 musi być
 * gotowy zanim tamte cokolwiek wystawią.
 *
 * @return void
 */
function mp_sales_workflow_boot() {
	MP_SW_Ajax::register();
	MP_SW_Cron::register();
	MP_SW_Hooks::register();
	MP_SW_Meta_Guard::register();

	/*
	 * MP_SW_Mailer NIE wpina sie tutaj. Wpinal wczesniej filtry `wp_mail_from`
	 * i `wp_mail_from_name` na cale zadanie, a te filtry nie wiedza, czyja
	 * wiadomosc leci — nadawce wtyczki sprzedazowej dostawal wiec kazdy mail
	 * witryny, z resetem hasla wlacznie. Nadawce ustawia teraz sama wysylka:
	 * MP_SW_Mailer::send().
	 */
	MP_SW_Privacy::register();

	if ( is_admin() ) {
		MP_SW_Admin::register();
	}
}
add_action( 'init', 'mp_sales_workflow_boot' );

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

	MP_SW_Cron::schedule();
}
register_activation_hook( __FILE__, 'mp_sales_workflow_activate' );

/**
 * Dezaktywacja — sprzątamy po sobie w harmonogramie.
 *
 * Tabele i role ZOSTAJĄ: wyłączenie wtyczki nie jest tym samym co jej usunięcie,
 * a skasowanie danych procesów przy zwykłym wyłączeniu byłoby nie do odwrócenia.
 * Tym zajmuje się uninstall.php.
 *
 * @return void
 */
function mp_sales_workflow_deactivate() {
	MP_SW_Cron::unschedule();
}
register_deactivation_hook( __FILE__, 'mp_sales_workflow_deactivate' );

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
