<?php
/**
 * Deinstalacja wtyczki MP Offer Builder.
 *
 * Uruchamiane przez WordPress przy usuwaniu wtyczki.
 *
 * @package MP_Offer_Builder
 */

// Wywoływane tylko przez WordPress podczas deinstalacji.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/db/class-mp-offer-builder-db.php';

MP_Offer_Builder_DB::uninstall();

// Sprząta capability przypisaną adminowi przy aktywacji (Dział 1, Agent 1.2).
$admin = get_role( 'administrator' );
if ( $admin && $admin->has_cap( 'mp_offer_builder_manage_offers' ) ) {
	$admin->remove_cap( 'mp_offer_builder_manage_offers' );
}
