<?php
/**
 * Dzial 2 pipeline LP.3 — STRZAŁ ODCZYTU — BD-1.
 *
 * Zakres wg diagramu: BD-1 (pkt 3): role · kraj · zespół · język
 *
 * Operacje dzialu:
 *  - Jeden strzał: rdzeń WP + strefa wtyczki
 *  - Zamrożenie snapshotu w pamięci
 *  - Działy 3–7 = czyste funkcje
 *
 * Zrodlo (Golden Rule #2): docs/dzial-02/strzal-odczytu-bd-1.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: WP_User_Query (meta_query) · Working with User Metadata
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
 * STRZAŁ ODCZYTU — BD-1.
 */
class MP_SW_Department_02 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 2;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'strzal-odczytu-bd-1';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_Stub_Agent( '2.1', 'handlowcy', 'Mapa zespołu jedną partią: WP_User_Query z meta_query po mp_country, mp_team, mp_lang, mp_scope, mp_absent' ),
				'critic' => new MP_SW_Stub_Critic( 'K2.1', 'kompletność-mapy', 'Handlowiec bez kompletu meta trafia na listę braków, nie znika po cichu' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '2.2', 'role', 'Definicje ról z wp_options (wp_user_roles): administrator · mp_manager · mp_handlowiec' ),
				'critic' => new MP_SW_Stub_Critic( 'K2.2', 'role-istnieją', 'Brak którejkolwiek roli = FAIL_FATAL — kryterium 5.4 nie zadziała' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '2.3', 'stan procesu', 'Bieżący status, przypisanie i otwarte zadania z wp_mp_flow i wp_mp_tasks' ),
				'critic' => new MP_SW_Stub_Critic( 'K2.3', 'świeżość-stanu', 'Stan czytany raz; wersja wiersza (updated_at) idzie do koperty' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '2.4', 'obciążenie', 'Ostatnie przypisania per handlowiec z wp_mp_flow — podkładka pod rotację' ),
				'critic' => new MP_SW_Stub_Critic( 'K2.4', 'sprawiedliwość-rotacji', 'Rotacja liczona z danych, nie z pamięci procesu' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '2.5', 'szablony', 'Szablony powiadomień pl/en z wersją, mapowane na typy zdarzeń' ),
				'critic' => new MP_SW_Stub_Critic( 'K2.5', 'wersja-szablonu', 'Szablon bez wersji jest nieodtwarzalny w dzienniku wysyłek' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_Stub_Agent( 'QA2', 'bramka jakosci D2', 'jeden-odczyt: db_reads = 1, pięć sekcji snapshotu' ),
			new MP_SW_Stub_Critic( 'QA2.K', 'krytyk bramki D2', 'brak sekcji = FAIL_FATAL' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'STRZAŁ ODCZYTU — BD-1',
			'BD-1 (pkt 3): role · kraj · zespół · język',
			$pairs,
			$gate
		);
	}
}
