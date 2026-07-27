<?php
/**
 * Dzial 8 pipeline LP.3 — ZAPIS — JEDNA TRANSAKCJA.
 *
 * Zakres wg diagramu: pkt 2: dziennik aktywności · kryt. 5.5
 *
 * Operacje dzialu:
 *  - Jedna transakcja: flow + zadania + kolejka + dziennik
 *  - Konflikt wersji = ponowienie od Działu 2
 *  - Po ROLLBACK kolejka pusta
 *
 * Zrodlo (Golden Rule #2): docs/dzial-08/zapis-jedna-transakcja.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: dbDelta() · MySQL START TRANSACTION / COMMIT
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
 * ZAPIS — JEDNA TRANSAKCJA.
 */
class MP_SW_Department_08 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 8;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'zapis-jedna-transakcja';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_Stub_Agent( '8.1', 'plan', 'Komplet operacji: wp_mp_flow (status + przypisanie), wp_mp_tasks, wp_mp_notifications (queued), wp_mp_activity' ),
				'critic' => new MP_SW_Stub_Critic( 'K8.1', 'zgodność-z-DDL', 'Statusy ze słownika, długości w limitach, każda operacja ma wiersz dziennika' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '8.2', 'transakcja', 'START TRANSACTION → operacje → COMMIT; konflikt wersji wiersza (updated_at) → FAIL_RETRY; inny błąd → ROLLBACK' ),
				'critic' => new MP_SW_Stub_Critic( 'K8.2', 'jeden-zapis', 'db_writes = 1; nie istnieje status bez wpisu w dzienniku' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '8.3', 'dziennik', 'Wpisy z old_value → new_value: status.changed · lead.assigned · task.planned · notification.queued' ),
				'critic' => new MP_SW_Stub_Critic( 'K8.3', 'odtwarzalność', 'Historię statusów i wysyłek odtwarza sam dziennik — kryterium 5.5' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_Stub_Agent( 'QA8', 'bramka jakosci D8', 'atomowość: wiersze = plan' ),
			new MP_SW_Stub_Critic( 'QA8.K', 'krytyk bramki D8', 'żadnych rekordów częściowych i e-maili-widm' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'ZAPIS — JEDNA TRANSAKCJA',
			'pkt 2: dziennik aktywności · kryt. 5.5',
			$pairs,
			$gate
		);
	}
}
