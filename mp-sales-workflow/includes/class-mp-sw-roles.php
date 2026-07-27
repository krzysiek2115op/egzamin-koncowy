<?php
/**
 * Role i uprawnienia wtyczki.
 *
 * Kryterium odbioru ze zlecenia: "Działające role: administrator, manager
 * sprzedaży, handlowiec". Krytyk K2.2 diagramu LP.3 traktuje brak
 * którejkolwiek roli jako błąd krytyczny — bez nich Dział 3 (zakres roli) nie
 * ma czego egzekwować, więc pipeline nie powinien w ogóle ruszać.
 *
 * Role zakładane są przy aktywacji i usuwane przy deinstalacji. Rola
 * `administrator` nie jest tworzona (należy do WordPressa) — dostaje wyłącznie
 * uprawnienia tej wtyczki.
 *
 * Zrodlo (Golden Rule #2): docs/dzial-03/uprawnienia-i-zakres-roli.md
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejestr ról i uprawnień.
 */
class MP_SW_Roles {

	/** Handlowiec — prowadzi własne procesy sprzedażowe. */
	const ROLE_SALESMAN = 'mp_handlowiec';

	/** Manager sprzedaży — widzi i prowadzi procesy całego zespołu. */
	const ROLE_MANAGER = 'mp_manager';

	/** Rola wbudowana WordPressa, której dokładamy uprawnienia wtyczki. */
	const ROLE_ADMIN = 'administrator';

	/** Uprawnienie: wywołanie zdarzenia procesu (także ręczne). */
	const CAP_HANDLE_EVENT = 'mp_sw_handle_event';

	/** Uprawnienie: dostęp do procesów spoza własnego przydziału. */
	const CAP_MANAGE_ALL = 'mp_sw_manage_all';

	/**
	 * Role wymagane do działania wtyczki — sprawdzane przez Dział 2.
	 *
	 * @return string[]
	 */
	public static function required_roles() {
		return array( self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_SALESMAN );
	}

	/**
	 * Uprawnienia przypisane roli.
	 *
	 * @param string $role Nazwa roli.
	 * @return array<string,bool>
	 */
	public static function caps_for( $role ) {
		$map = array(
			self::ROLE_SALESMAN => array(
				'read'                 => true,
				self::CAP_HANDLE_EVENT => true,
			),
			self::ROLE_MANAGER  => array(
				'read'                 => true,
				self::CAP_HANDLE_EVENT => true,
				self::CAP_MANAGE_ALL   => true,
			),
			self::ROLE_ADMIN    => array(
				self::CAP_HANDLE_EVENT => true,
				self::CAP_MANAGE_ALL   => true,
			),
		);

		return isset( $map[ $role ] ) ? $map[ $role ] : array();
	}

	/**
	 * Zakłada role wtyczki i dokłada uprawnienia administratorowi.
	 *
	 * Wywoływane przy aktywacji. Operacja jest idempotentna: `add_role()` nie
	 * nadpisuje istniejącej roli, więc uprawnienia dokładamy osobno — inaczej
	 * ponowna aktywacja po ręcznej zmianie uprawnień nic by nie naprawiła.
	 *
	 * @return bool Czy wszystkie wymagane role istnieją po wykonaniu metody.
	 */
	public static function install() {
		add_role( self::ROLE_SALESMAN, __( 'Handlowiec', 'mp-sales-workflow' ), self::caps_for( self::ROLE_SALESMAN ) );
		add_role( self::ROLE_MANAGER, __( 'Manager sprzedaży', 'mp-sales-workflow' ), self::caps_for( self::ROLE_MANAGER ) );

		foreach ( self::required_roles() as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::caps_for( $role_name ) as $cap => $grant ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap, (bool) $grant );
				}
			}
		}

		return self::roles_exist();
	}

	/**
	 * Czy wszystkie wymagane role istnieją.
	 *
	 * @return bool
	 */
	public static function roles_exist() {
		foreach ( self::required_roles() as $role_name ) {
			if ( ! get_role( $role_name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Które z wymaganych ról są nieobecne.
	 *
	 * @return string[]
	 */
	public static function missing_roles() {
		$missing = array();

		foreach ( self::required_roles() as $role_name ) {
			if ( ! get_role( $role_name ) ) {
				$missing[] = $role_name;
			}
		}

		return $missing;
	}

	/**
	 * Usuwa role wtyczki i odbiera uprawnienia administratorowi.
	 *
	 * Rola `administrator` zostaje — odbieramy jej wyłącznie to, co sami
	 * nadaliśmy.
	 *
	 * @return void
	 */
	public static function uninstall() {
		$admin = get_role( self::ROLE_ADMIN );

		if ( $admin ) {
			foreach ( array_keys( self::caps_for( self::ROLE_ADMIN ) ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}

		remove_role( self::ROLE_SALESMAN );
		remove_role( self::ROLE_MANAGER );
	}
}
