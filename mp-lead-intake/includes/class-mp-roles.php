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
		/*
		 * ROLE SA WSPOLDZIELONE Z MODULEM SPRZEDAZOWYM.
		 *
		 * `mp_manager_sprzedazy` i `mp_handlowiec` to te same slugi, ktorych
		 * uzywa wtyczka 3 — ona swoje uprawnienia zdejmuje, ale rol NIE KASUJE
		 * wlasnie z tego powodu. Bezwarunkowe `remove_role()` tutaj sprawialo,
		 * ze odinstalowanie SAMEJ wtyczki 1 zabieralo definicje rol calej
		 * instalacji: wszyscy handlowcy i managerowie traca uprawnienia, pulpit
		 * sprzedazowy staje sie niedostepny, a lista ofert znika dla kazdego
		 * poza administratorem. Odzysk dopiero po reaktywacji wtyczki 3.
		 *
		 * Dlatego: uprawnienia WLASNE zdejmujemy zawsze, a sama role kasujemy
		 * tylko wtedy, gdy po ich zdjeciu nie zostalo w niej NIC — czyli nikt
		 * inny jej nie uzywa. Regula opisuje sie sama i nie wymaga wiedzy
		 * o tym, ktore wtyczki akurat sa zainstalowane.
		 */
		foreach ( array( self::MANAGER, self::SALES ) as $slug ) {
			$rola = get_role( $slug );

			if ( ! $rola ) {
				continue;
			}

			foreach ( self::CAPS as $cap ) {
				$rola->remove_cap( $cap );
			}

			// Ponowny odczyt: `remove_cap()` przepisuje opcje rol, a obiekt
			// w pamieci potrafi nie odzwierciedlac stanu po zapisie.
			$rola = get_role( $slug );

			if ( $rola && empty( array_filter( (array) $rola->capabilities ) ) ) {
				remove_role( $slug );
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::CAPS as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
