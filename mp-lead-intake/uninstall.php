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
MP_Lead_Intake_DB::delete_transients();
MP_Lead_Intake_Roles::remove();
MP_Lead_Intake_Page::remove();

// Sprzątanie zaplanowanych zadań (retencja RODO + reconcile weryfikacji VAT).
wp_clear_scheduled_hook( 'mp_lead_intake_ip_retention' );
wp_clear_scheduled_hook( 'mp_lead_intake_vat_reconcile' );
// Pojedyncze, per-lead zdarzenia weryfikacji VAT (leady 'pending' w momencie
// deinstalacji) — bez tego zostałyby w opcji `cron` aż do przypadkowego
// odpalenia WP-Cron w przyszłości (audyt 2026-07-23).
wp_clear_scheduled_hook( 'mp_lead_intake_verify_vat' );
