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
require_once __DIR__ . '/includes/class-mp-offer-builder-storage.php';

// Tabele BD-2 + wersja schematu.
MP_Offer_Builder_DB::uninstall();

// Wygenerowane pliki PDF ofert + prywatny katalog + sekret nazw plików — bez tego
// po "usunięciu wtyczki" na dysku zostawałyby wrażliwe oferty PDF i osierocona opcja.
MP_Offer_Builder_Storage::delete_all();
delete_option( MP_Offer_Builder_Storage::FILE_SECRET_OPTION );

// Sprząta capability ze WSZYSTKICH ról: przy aktywacji dostaje ją administrator,
// ale klient mógł nadać ją także innym rolom (np. "Handlowiec" — patrz docblock
// aktywacji), więc czyszczenie tylko admina zostawiałoby ją na stałe.
$mp_roles = wp_roles();
if ( $mp_roles instanceof WP_Roles ) {
	foreach ( array_keys( $mp_roles->roles ) as $mp_role_slug ) {
		$mp_role = get_role( $mp_role_slug );
		if ( $mp_role && $mp_role->has_cap( 'mp_offer_builder_manage_offers' ) ) {
			$mp_role->remove_cap( 'mp_offer_builder_manage_offers' );
		}
	}
}
