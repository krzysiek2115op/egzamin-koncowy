<?php
/**
 * Dzial 3 pipeline LP.3 — UPRAWNIENIA I ZAKRES ROLI.
 *
 * Zakres wg diagramu: kryt. 5.4: role i uprawnienia
 *
 * Operacje dzialu:
 *  - current_user_can na operację
 *  - Zakres danych wg roli i zespołu
 *
 * Zrodla (Golden Rule #2): docs/dzial-03/ — co najmniej jedno oryginalne
 * zrodlo na dzial, dodawane w kolejnym kroku prac.
 * Diagram wskazuje: Roles and Capabilities · current_user_can()
 *
 * Pary Agent+Krytyk sa na razie zaslepkami z prawdziwymi identyfikatorami,
 * nazwami i zadaniami — realna logika podstawiana jest para po parze.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UPRAWNIENIA I ZAKRES ROLI.
 */
class MP_SW_Department_03 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 3;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'uprawnienia-i-zakres-roli';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_Stub_Agent( '3.1', 'aktor', 'current_user_can() wobec operacji: zmiana statusu, ręczne przypisanie, wgląd w dashboard' ),
				'critic' => new MP_SW_Stub_Critic( 'K3.1', 'prawo-do-operacji', 'Operacja spoza uprawnień roli = 403, nigdy ciche zawężenie' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '3.2', 'zakres', 'Przycina widoczność: handlowiec — swoje procesy · manager — zespół · administrator — wszystko' ),
				'critic' => new MP_SW_Stub_Critic( 'K3.2', 'cięcie-zakresu', 'Zakres liczony z roli i mp_team ze snapshotu, nie z parametru żądania' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_Stub_Agent( 'QA3', 'bramka jakosci D3', 'zakres-roli: operacja i widok dozwolone dla aktora' ),
			new MP_SW_Stub_Critic( 'QA3.K', 'krytyk bramki D3', 'parametr żądania nigdy nie rozszerza zakresu' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'UPRAWNIENIA I ZAKRES ROLI',
			'kryt. 5.4: role i uprawnienia',
			$pairs,
			$gate
		);
	}
}
