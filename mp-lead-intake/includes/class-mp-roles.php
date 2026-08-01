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

	/** Podgląd leadów (wejście na ekran „Leady"). */
	const CAP_VIEW = 'mp_view_leads';

	/** Zmiana danych leada. */
	const CAP_MANAGE = 'mp_manage_leads';

	/** Rozdzielanie leadów — kto je ma, widzi WSZYSTKIE, nie tylko własne. */
	const CAP_ASSIGN = 'mp_assign_leads';

	/** Uprawnienia wtyczki. */
	const CAPS = array( self::CAP_VIEW, self::CAP_MANAGE, self::CAP_ASSIGN );

	/**
	 * Uprawnienia tej wtyczki per rola — wzorzec, do którego doprowadza `create()`.
	 *
	 * @return array<string,string[]>
	 */
	public static function caps_for_roles() {
		return array(
			self::MANAGER => self::CAPS,
			self::SALES   => array( self::CAP_VIEW ),
		);
	}

	/**
	 * Tworzy role i nadaje uprawnienia (wywoływane przy aktywacji).
	 *
	 * ROLE SĄ WSPÓŁDZIELONE Z MODUŁEM SPRZEDAŻOWYM — `mp_manager_sprzedazy`
	 * i `mp_handlowiec` to te same slugi, których używa wtyczka 3. Metoda
	 * `remove()` niżej o tym wie i dlatego ról nie kasuje; `create()` do 1.3.6
	 * o tym nie wiedziała i na tym polegał błąd:
	 *
	 * `add_role()` przy ISTNIEJĄCEJ roli nie robi NIC i zwraca null. Jeśli więc
	 * wtyczka 3 (albo poprzednia instalacja) założyła te role wcześniej, żadne
	 * z uprawnień tej wtyczki na nie nie trafiało. Rola nazywała się „Handlowiec",
	 * człowiek miał ją przypisaną — i dostawał „Brak uprawnień do podglądu leadów",
	 * bo `mp_view_leads` nigdy mu nie nadano. Wynik zależał od KOLEJNOŚCI aktywacji
	 * wtyczek, więc na jednej witrynie działało, a na drugiej nie.
	 *
	 * Uprawnienia dokładamy więc osobno, po `add_role()`, i tylko WŁASNE: pętla
	 * chodzi po `CAPS` tej wtyczki, nigdy po wszystkich, jakie rola posiada.
	 * Uprawnienia `mp_sw_*` należą do wtyczki 3 i nie mamy prawa ich ruszać.
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

		foreach ( self::caps_for_roles() as $slug => $wanted ) {
			$role = get_role( $slug );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::CAPS as $cap ) {
				if ( in_array( $cap, $wanted, true ) ) {
					$role->add_cap( $cap );
				} else {
					// Zawężenie musi działać przy aktualizacji, nie tylko przy
					// pierwszej instalacji — inaczej handlowiec z dawnej wersji
					// zostałby z uprawnieniem, którego ta wersja mu nie daje.
					$role->remove_cap( $cap );
				}
			}
		}

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
