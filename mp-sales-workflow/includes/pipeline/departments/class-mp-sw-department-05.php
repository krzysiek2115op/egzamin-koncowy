<?php
/**
 * Dzial 5 pipeline LP.3 — MASZYNA STATUSÓW.
 *
 * Zakres wg diagramu: pkt 2: statusy procesu
 *
 * Operacje dzialu:
 *  - Słownik dozwolonych przejść (wersjonowany)
 *  - Skutki przejścia wyliczone jawnie
 *
 * Zrodlo (Golden Rule #2): docs/dzial-05/maszyna-statusow.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: maszyna stanów wg zlecenia · kryt. 5.5
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
 * MASZYNA STATUSÓW.
 */
class MP_SW_Department_05 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 5;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'maszyna-statusow';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_Stub_Agent( '5.1', 'przejście', 'Sprawdza przejście w słowniku: nowy → przypisany → oferta_robocza → oferta_wyslana → negocjacje → wygrany / przegrany' ),
				'critic' => new MP_SW_Stub_Critic( 'K5.1', 'legalność-przejścia', 'Przejście spoza słownika = odmowa z kodem, stan bez zmian' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '5.2', 'skutki', 'Wylicza skutki przejścia: nowe SLA, zamknięcie oczekujących zadań, powiadomienia do wysłania' ),
				'critic' => new MP_SW_Stub_Critic( 'K5.2', 'komplet-skutków', 'Każdy skutek ma pokrycie w regule przejścia — nic „przy okazji”' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_Stub_Agent( 'QA5', 'bramka jakosci D5', 'legalność-przejścia + komplet skutków' ),
			new MP_SW_Stub_Critic( 'QA5.K', 'krytyk bramki D5', 'stan zmienia się tylko przez maszynę, nigdy wprost' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'MASZYNA STATUSÓW',
			'pkt 2: statusy procesu',
			$pairs,
			$gate
		);
	}
}
