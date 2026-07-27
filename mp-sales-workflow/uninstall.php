<?php
/**
 * Deinstalacja wtyczki MP Sales Workflow.
 *
 * Uruchamiane przez WordPress przy usuwaniu wtyczki. Na etapie szkieletu
 * wtyczka nie zakłada jeszcze żadnych tabel, opcji ani zaplanowanych zadań —
 * sprzątanie dochodzi tu wraz z każdą warstwą, która coś trwale zapisuje
 * (BD-1, opcje, zdarzenia cron follow-up, role/uprawnienia).
 *
 * @package MP_Sales_Workflow
 */

// Wywoływane tylko przez WordPress podczas deinstalacji.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
