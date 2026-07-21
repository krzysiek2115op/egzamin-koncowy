<?php
/**
 * Deinstalacja wtyczki MP Lead Intake.
 *
 * Uruchamiane przez WordPress przy usuwaniu wtyczki. Usuwa tabele BD-3, role,
 * pod-stronę oraz opcje wtyczki.
 *
 * @package MP_Lead_Intake
 */

// Wywoływane tylko przez WordPress podczas deinstalacji.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/db/class-mp-db.php';
require_once __DIR__ . '/includes/class-mp-roles.php';
require_once __DIR__ . '/includes/class-mp-page.php';

MP_Lead_Intake_DB::uninstall();
MP_Lead_Intake_Roles::remove();
MP_Lead_Intake_Page::remove();
