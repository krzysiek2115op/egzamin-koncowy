<?php
/**
 * Dzial 6 pipeline LP.3 — ZADANIA FOLLOW-UP.
 *
 * Zakres wg diagramu: pkt 2: zadania follow-up · aut. 4.5
 *
 * Operacje dzialu:
 *  - d+3 i d+7 z wartownikiem statusu
 *  - Zmiana statusu anuluje oczekujące
 *  - Wykonanie: cron → zdarzenie task.due
 *
 * Zrodlo (Golden Rule #2): docs/dzial-06/zadania-follow-up.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: WP-Cron → systemowy harmonogram (Plugin Handbook)
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
 * ZADANIA FOLLOW-UP.
 */
class MP_SW_Department_06 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 6;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'zadania-follow-up';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_Stub_Agent( '6.1', 'harmonogram', 'Planuje zadania d+3 i d+7 od wysłania oferty, z wartownikiem: guard_status = oferta_wyslana' ),
				'critic' => new MP_SW_Stub_Critic( 'K6.1', 'warunek-4.5', 'Zadanie aktywuje się TYLKO gdy status niezmieniony — dosłownie wg zlecenia' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '6.2', 'deduplikacja', 'Jedno otwarte zadanie danego typu na proces; zmiana statusu anuluje oczekujące' ),
				'critic' => new MP_SW_Stub_Critic( 'K6.2', 'brak-duplikatów-zadań', 'Ponowione zdarzenie nie tworzy drugiej pary zadań (event_id)' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_Stub_Agent( 'QA6', 'bramka jakosci D6', 'warunek-4.5 + brak duplikatów zadań' ),
			new MP_SW_Stub_Critic( 'QA6.K', 'krytyk bramki D6', 'zadanie bez wartownika statusu = FAIL' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'ZADANIA FOLLOW-UP',
			'pkt 2: zadania follow-up · aut. 4.5',
			$pairs,
			$gate
		);
	}
}
