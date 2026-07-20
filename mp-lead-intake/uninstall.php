<?php
/**
 * Deinstalacja wtyczki MP Lead Intake.
 *
 * Uruchamiane przez WordPress przy usuwaniu wtyczki. Usuwa tabele BD-3
 * oraz opcje wtyczki.
 *
 * @package MP_Lead_Intake
 */

// Wywoływane tylko przez WordPress podczas deinstalacji.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/db/class-mp-db.php';

MP_Lead_Intake_DB::uninstall();
