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

	/**
	 * Manager sprzedaży — widzi i prowadzi procesy całego zespołu.
	 *
	 * Slug jest TEN SAM, co w MP Lead Intake (`mp_manager_sprzedazy`). Wcześniej
	 * ta wtyczka zakładała własne `mp_manager` z identyczną nazwą wyświetlaną, więc
	 * na instalacji z obiema wtyczkami administrator widział w liście ról „Manager
	 * sprzedaży" dwa razy i nie miał jak ich odróżnić — po wybraniu złej manager nie
	 * widział albo leadów, albo procesów sprzedażowych. Zlecenie wymaga TRZECH ról
	 * (administrator, manager sprzedaży, handlowiec), nie czterech.
	 *
	 * Ustępuje ta wtyczka, nie tamta: MP Lead Intake jest wdrożone i ma odebrane
	 * testy, więc zmiana jego sluga osierociłaby konta, którym klient zdążył nadać
	 * rolę.
	 */
	const ROLE_MANAGER = 'mp_manager_sprzedazy';

	/** Nazwa roli managera z wersji sprzed ujednolicenia — tylko do migracji. */
	const ROLE_MANAGER_LEGACY = 'mp_manager';

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
	 * Odpowiedź na pytanie WooCommerce, czy wypchnąć użytkownika z `/wp-admin/`.
	 *
	 * WooCommerce filtrem `woocommerce_prevent_admin_access` wyrzuca z panelu
	 * każdego, kto nie ma `edit_posts` ani `manage_woocommerce`, i odsyła go na
	 * stronę „Moje konto". Role tej wtyczki mają `read` i uprawnienia własne —
	 * i tak ma zostać, bo handlowiec nie ma powodu edytować wpisów bloga.
	 *
	 * Skutek był jednak taki, że na KAŻDEJ instalacji zgodnej z wymaganiami
	 * produktu (wtyczka 2 wymaga WooCommerce) handlowiec i manager dostawali
	 * `302` na `/my-account/` zamiast jedynego ekranu, który ta wtyczka dla nich
	 * robi. Znalezione dopiero wejściem na ten ekran prawdziwym logowaniem —
	 * czytanie `current_user_can()` niczego by nie pokazało, bo uprawnienia są
	 * poprawne; blokada stała piętro wyżej, w cudzej wtyczce.
	 *
	 * Odpowiadamy WĄSKO i tylko na cudze „tak":
	 *  - decyzję zmieniamy wyłącznie posiadaczom NASZYCH uprawnień,
	 *  - gdy WooCommerce nikogo nie wypycha, milczymy — inaczej wtyczka zaczęłaby
	 *    wypychać ludzi z panelu na własną rękę,
	 *  - nikomu niczego nie dodajemy: `edit_posts` nadal nie mają.
	 *
	 * @param bool $wypchnij Decyzja WooCommerce.
	 * @return bool
	 */
	public static function pozwol_na_panel( $wypchnij ) {
		if ( ! $wypchnij ) {
			return $wypchnij;
		}

		foreach ( self::all_caps() as $cap ) {
			if ( current_user_can( $cap ) ) {
				return false;
			}
		}

		return $wypchnij;
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

		self::migrate_legacy_manager();

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
	 * Przenosi konta ze starej roli `mp_manager` na ujednoliconą i kasuje starą.
	 *
	 * Sama zmiana stałej nie wystarcza: na instalacji, która działała na
	 * poprzedniej wersji, rola `mp_manager` zostaje w bazie razem z przypisanymi
	 * do niej kontami. Bez tej migracji administrator dalej widziałby dwa
	 * „Managery sprzedaży", a managerowie straciliby dostęp do procesów —
	 * uprawnienia synchronizujemy tylko na rolach z `required_roles()`.
	 *
	 * Rola kasowana jest DOPIERO po przepisaniu kont. Odwrotna kolejność zabrałaby
	 * im jedyną rolę i zostawiła konta bez żadnej.
	 *
	 * @return int Liczba przeniesionych kont.
	 */
	public static function migrate_legacy_manager() {
		if ( ! get_role( self::ROLE_MANAGER_LEGACY ) && ! get_role( self::ROLE_MANAGER ) ) {
			return 0;
		}

		$moved = 0;
		$users = get_users(
			array(
				'role'   => self::ROLE_MANAGER_LEGACY,
				'fields' => 'ID',
				'number' => -1,
			)
		);

		foreach ( (array) $users as $user_id ) {
			$user = new WP_User( (int) $user_id );

			if ( ! $user->exists() ) {
				continue;
			}

			$user->add_role( self::ROLE_MANAGER );
			$user->remove_role( self::ROLE_MANAGER_LEGACY );
			++$moved;
		}

		if ( get_role( self::ROLE_MANAGER_LEGACY ) ) {
			remove_role( self::ROLE_MANAGER_LEGACY );
		}

		return $moved;
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
		/*
		 * Role NIE są kasowane — obie dzielimy z MP Lead Intake, które zakłada
		 * `mp_manager_sprzedazy` i `mp_handlowiec` na własny użytek. Usunięcie ich
		 * przy deinstalacji tej wtyczki zabrałoby handlowcom dostęp do leadów,
		 * czyli zepsułoby CUDZY moduł. Zdejmujemy wyłącznie to, co sami nadaliśmy.
		 *
		 * Wcześniej wywoływane tu `remove_role()` było bezpieczne tylko dlatego,
		 * że rola managera miała wtedy własną, osobną nazwę.
		 */
		foreach ( array( self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_SALESMAN ) as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::all_caps() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
