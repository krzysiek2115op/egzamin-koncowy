<?php
/**
 * Dzial 4 pipeline LP.3 — PRZYPISANIE HANDLOWCA.
 *
 * Zakres wg diagramu: pkt 2: przypisanie handlowca · aut. 4.2
 *
 * Operacje dzialu:
 *  - Dobór: kraj × język × zakres
 *  - Rotacja po ostatnim przypisaniu
 *  - Fallback zespołu przy braku kandydata
 *
 * Zrodlo (Golden Rule #2): docs/dzial-04/przypisanie-handlowca.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: reguły wg zlecenia + usermeta mp_* (konfiguracja)
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
 * PRZYPISANIE HANDLOWCA.
 */
class MP_SW_Department_04 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 4;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'przypisanie-handlowca';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_Stub_Agent( '4.1', 'dobór', 'Kandydaci z mapy: kraj zdarzenia × język × zakres obsługi (segment); nieobecni odpadają' ),
				'critic' => new MP_SW_Stub_Critic( 'K4.1', 'dopasowanie-kandydata', 'Kandydat musi istnieć w snapshocie i mieć rolę mp_handlowiec' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '4.2', 'rotacja', 'Z kandydatów wygrywa najdawniej przypisany; brak kandydata → zespół zapasowy z flagą' ),
				'critic' => new MP_SW_Stub_Critic( 'K4.2', 'pokrycie', 'Proces nigdy nie zostaje bez właściciela — fallback zamiast pustki' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '4.3', 'uzasadnienie', 'reason zapisany słownie (kraj, język, zakres, rotacja) + termin reakcji sla_due_at' ),
				'critic' => new MP_SW_Stub_Critic( 'K4.3', 'odtwarzalność-decyzji', 'To samo zdarzenie i ten sam snapshot dają to samo przypisanie' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_Stub_Agent( 'QA4', 'bramka jakosci D4', 'pokrycie-przypisania: zawsze ktoś przypisany' ),
			new MP_SW_Stub_Critic( 'QA4.K', 'krytyk bramki D4', 'pusty właściciel = FAIL_FATAL' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'PRZYPISANIE HANDLOWCA',
			'pkt 2: przypisanie handlowca · aut. 4.2',
			$pairs,
			$gate
		);
	}
}
