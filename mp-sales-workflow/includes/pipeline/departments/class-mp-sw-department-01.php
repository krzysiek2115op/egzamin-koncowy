<?php
/**
 * Dzial 1 pipeline LP.3 — BRAMA I KONTRAKT ZDARZENIA.
 *
 * Zakres wg diagramu: zdarzenie procesu · tylko JSON
 *
 * Operacje dzialu:
 *  - Słownik typów zdarzeń
 *  - Uprawnienia przy wywołaniu ręcznym
 *  - Klucz idempotencji na zdarzenie
 *
 * Zrodla (Golden Rule #2): docs/dzial-01/ — co najmniej jedno oryginalne
 * zrodlo na dzial, dodawane w kolejnym kroku prac.
 * Diagram wskazuje: JSON Schema 2020-12 · WP REST permission_callback · Nonces
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
 * BRAMA I KONTRAKT ZDARZENIA.
 */
class MP_SW_Department_01 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 1;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'brama-i-kontrakt-zdarzenia';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_Stub_Agent( '1.1', 'kontrakt', 'Waliduje JSON zdarzenia: typ ze słownika (lead.created · offer.approved · status.change · task.due · dashboard.view), encja, aktor' ),
				'critic' => new MP_SW_Stub_Critic( 'K1.1', 'schemat-zdarzenia', 'Typ spoza słownika = 422; komplet błędów naraz' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '1.2', 'źródło', 'Zdarzenie systemowe (hook), timer (cron) albo ręczne od handlowca / managera — ręczne z nonce i uprawnieniem' ),
				'critic' => new MP_SW_Stub_Critic( 'K1.2', 'kto-woła', 'Wywołanie ręczne bez uprawnień = 403 przed pracą' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '1.3', 'idempotencja', 'event_id (UUID) na zdarzenie; timer d+3 wykonany dokładnie raz' ),
				'critic' => new MP_SW_Stub_Critic( 'K1.3', 'klucz-idempotencji', 'Ten sam event_id nigdy nie obsłuży zdarzenia dwa razy' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_Stub_Agent( 'QA1', 'bramka jakosci D1', 'komplet zdarzenia: typ + encja + aktor' ),
			new MP_SW_Stub_Critic( 'QA1.K', 'krytyk bramki D1', 'brak któregokolwiek = 422 z listą pól' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'BRAMA I KONTRAKT ZDARZENIA',
			'zdarzenie procesu · tylko JSON',
			$pairs,
			$gate
		);
	}
}
