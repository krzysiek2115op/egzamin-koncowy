<?php
/**
 * Deinstalacja wtyczki MP Sales Workflow.
 *
 * Uruchamiane przez WordPress przy usuwaniu wtyczki. Sprzątanie rozszerza się
 * wraz z każdą warstwą, która coś trwale zapisuje — dziś są to tabele BD-1 i
 * wersja schematu; zaplanowane zadania cron oraz role/uprawnienia dojdą tu
 * razem z działami, które je zakładają.
 *
 * @package MP_Sales_Workflow
 */

// Wywoływane tylko przez WordPress podczas deinstalacji.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/db/class-mp-sales-workflow-db.php';

// Tabele BD-1 + zapisana wersja schematu.
MP_Sales_Workflow_DB::uninstall();
