<?php
/**
 * Role i uprawnienia wtyczki MP Lead Intake.
 *
 * Tworzy role: Manager sprzedaży, Handlowiec (administrator dostaje uprawnienia
 * dodatkowo). Zgodnie z kryterium odbioru: działające role administrator /
 * manager sprzedaży / handlowiec.
 *
 * Oficjalne API: add_role() https://developer.wordpress.org/reference/functions/add_role/
 *                get_role() https://developer.wordpress.org/reference/functions/get_role/
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zarządzanie rolami i uprawnieniami.
 */
class MP_Lead_Intake_Roles {

	const MANAGER = 'mp_manager_sprzedazy';
	const SALES   = 'mp_handlowiec';

	/** Uprawnienia wtyczki. */
	const CAPS = array( 'mp_view_leads', 'mp_manage_leads', 'mp_assign_leads' );

	/**
	 * Tworzy role i nadaje uprawnienia (wywoływane przy aktywacji).
	 *
	 * @return void
	 */
	public static function create() {
		add_role(
			self::MANAGER,
			'Manager sprzedaży',
			array(
				'read'            => true,
				'mp_view_leads'   => true,
				'mp_manage_leads' => true,
				'mp_assign_leads' => true,
			)
		);

		add_role(
			self::SALES,
			'Handlowiec',
			array(
				'read'          => true,
				'mp_view_leads' => true,
			)
		);

		// Administrator otrzymuje pełne uprawnienia wtyczki.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::CAPS as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Usuwa role i uprawnienia (wywoływane przy deinstalacji).
	 *
	 * @return void
	 */
	public static function remove() {
		remove_role( self::MANAGER );
		remove_role( self::SALES );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::CAPS as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
