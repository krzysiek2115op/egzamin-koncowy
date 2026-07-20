<?php
/**
 * Deinstalacja wtyczki MP Lead Intake.
 *
 * Uruchamiane przez WordPress przy usuwaniu wtyczki. Tu (w kolejnym etapie)
 * usuniemy tabelę lead-ów i opcje wtyczki.
 *
 * @package MP_Lead_Intake
 */

// Wywoływane tylko przez WordPress podczas deinstalacji.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// TODO(etap: baza danych): DROP TABLE {$wpdb->prefix}mp_leads oraz delete_option() dla ustawień.
