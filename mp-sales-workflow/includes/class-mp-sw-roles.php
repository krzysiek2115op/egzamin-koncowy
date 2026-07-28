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

	/** Widok własnych procesów. */
	const CAP_VIEW_OWN = 'mp_sw_view_own';

	/** Widok procesów całego zespołu (`mp_team`). */
	const CAP_VIEW_TEAM = 'mp_sw_view_team';

	/** Widok wszystkich procesów w systemie. */
	const CAP_VIEW_ALL = 'mp_sw_view_all';

	/** Zmiana statusu procesu. */
	const CAP_CHANGE_STATUS = 'mp_sw_change_status';

	/** Przypisanie procesu handlowcowi. */
	const CAP_ASSIGN = 'mp_sw_assign';

	/** Ustawienia wtyczki. */
	const CAP_MANAGE_SETTINGS = 'mp_sw_manage_settings';

	/**
	 * Klucz meta z przypisaniem do zespołu.
	 *
	 * Prefiks `mp_sw_`, nie `mp_` — tak nazwał go Dział 2 przy pierwszym odczycie
	 * i tak leży w bazach, które już działają. Strażnik metadanych chroni cały
	 * prefiks `mp_`, więc obejmuje oba warianty nazewnictwa.
	 */
	const META_TEAM = 'mp_sw_team';

	/**
	 * Uprawnienie wymagane dla danego typu zdarzenia.
	 *
	 * Jedno wspólne `mp_sw_handle_event` nie wystarcza: przepuszczało handlowca
	 * do KAŻDEGO typu zdarzenia, bo było odpowiedzią na pytanie „czy ten ktoś w
	 * ogóle obsługuje procesy", a nie „czy wolno mu zrobić właśnie to".
	 *
	 * @param string $type Typ zdarzenia.
	 * @return string Nazwa uprawnienia; pusty ciąg = typ bez mapowania.
	 */
	public static function cap_for_event( $type ) {
		$map = array(
			MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW => self::CAP_VIEW_OWN,
			MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE  => self::CAP_CHANGE_STATUS,
			MP_SW_Origin::EVENT_LEAD_ASSIGN              => self::CAP_ASSIGN,
		);

		$type = (string) $type;

		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}

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
				'read'                  => true,
				self::CAP_HANDLE_EVENT  => true,
				self::CAP_VIEW_OWN      => true,
				self::CAP_CHANGE_STATUS => true,
			),

			/*
			 * Manager dostaje ZESPÓŁ, nie wszystko. Wcześniej miał `manage_all`,
			 * czyli widok całej firmy — wygodne, ale niezgodne z tym, po co w ogóle
			 * istnieje `mp_team`: przy dwóch zespołach handlowych manager jednego
			 * czytał negocjacje drugiego. Pełny widok zostaje przy administratorze.
			 */
			self::ROLE_MANAGER  => array(
				'read'                  => true,
				self::CAP_HANDLE_EVENT  => true,
				self::CAP_VIEW_OWN      => true,
				self::CAP_VIEW_TEAM     => true,
				self::CAP_CHANGE_STATUS => true,
				self::CAP_ASSIGN        => true,
			),
			self::ROLE_ADMIN    => array(
				self::CAP_HANDLE_EVENT    => true,
				self::CAP_MANAGE_ALL      => true,
				self::CAP_VIEW_OWN        => true,
				self::CAP_VIEW_TEAM       => true,
				self::CAP_VIEW_ALL        => true,
				self::CAP_CHANGE_STATUS   => true,
				self::CAP_ASSIGN          => true,
				self::CAP_MANAGE_SETTINGS => true,
			),
		);

		return isset( $map[ $role ] ) ? $map[ $role ] : array();
	}

	/**
	 * Wszystkie uprawnienia NALEŻĄCE DO TEJ WTYCZKI.
	 *
	 * Lista jest granicą własności: tylko te uprawnienia wolno nam nadawać,
	 * odbierać i kasować przy deinstalacji.
	 *
	 * @return string[]
	 */
	public static function all_caps() {
		return array(
			self::CAP_HANDLE_EVENT,
			self::CAP_MANAGE_ALL,
			self::CAP_VIEW_OWN,
			self::CAP_VIEW_TEAM,
			self::CAP_VIEW_ALL,
			self::CAP_CHANGE_STATUS,
			self::CAP_ASSIGN,
			self::CAP_MANAGE_SETTINGS,
		);
	}

	/**
	 * Zakłada role wtyczki i doprowadza uprawnienia do stanu wzorcowego.
	 *
	 * Wywoływane przy aktywacji. Operacja jest idempotentna: `add_role()` nie
	 * nadpisuje istniejącej roli, więc uprawnienia ustawiamy osobno — inaczej
	 * ponowna aktywacja po ręcznej zmianie uprawnień nic by nie naprawiła.
	 *
	 * Uprawnienia są SYNCHRONIZOWANE w obie strony, nie tylko dokładane. Sama
	 * ścieżka „dołóż brakujące" zostawiłaby na już zainstalowanych witrynach
	 * managerów z dawnym `mp_sw_manage_all` — czyli z widokiem całej firmy,
	 * którego ta wersja im nie daje. Zawężenie uprawnień musi działać przy
	 * aktualizacji, nie tylko przy pierwszej instalacji.
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

			$wanted = self::caps_for( $role_name );

			/*
			 * Pętla chodzi po uprawnieniach TEJ wtyczki, nigdy po wszystkich, jakie
			 * rola posiada. `read`, `edit_posts` i reszta rdzenia nie są nasze —
			 * odebranie ich administratorowi zamknęłoby mu panel.
			 */
			foreach ( self::all_caps() as $cap ) {
				$has  = $role->has_cap( $cap );
				$want = ! empty( $wanted[ $cap ] );

				if ( $want && ! $has ) {
					$role->add_cap( $cap, true );
				}

				if ( ! $want && $has ) {
					$role->remove_cap( $cap );
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
			foreach ( self::all_caps() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}

		remove_role( self::ROLE_SALESMAN );
		remove_role( self::ROLE_MANAGER );
	}
}
