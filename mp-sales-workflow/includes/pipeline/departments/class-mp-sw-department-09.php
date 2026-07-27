<?php
/**
 * Dzial 9 pipeline LP.3 — WYJŚCIE I URUCHOMIENIE KOLEJKI.
 *
 * Zakres wg diagramu: aut. 4.4: wysyłka po akceptacji
 *
 * Operacje dzialu:
 *  - Zdarzenie po COMMIT, dokładnie raz
 *  - Kolejka rusza poza żądaniem
 *  - Wyjście tylko JSON + trace_id
 *
 * Zrodla (Golden Rule #2): docs/dzial-09/ — co najmniej jedno oryginalne
 * zrodlo na dzial, dodawane w kolejnym kroku prac.
 * Diagram wskazuje: do_action · wp_send_json_success · Action Scheduler
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
 * WYJŚCIE I URUCHOMIENIE KOLEJKI.
 */
class MP_SW_Department_09 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 9;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'wyjscie-i-uruchomienie-kolejki';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_Stub_Agent( '9.1', 'zdarzenia', 'Po COMMIT: mp_flow_updated (dokładnie raz) + zlecenie kolejki wysyłki przez harmonogram zadań' ),
				'critic' => new MP_SW_Stub_Critic( 'K9.1', 'jednokrotność', 'Nic nie wychodzi przed COMMIT — inaczej e-mail o stanie, którego nie ma' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '9.2', 'odpowiedź', 'JSON: status, przypisanie, liczba zadań i powiadomień, trace_id; kod HTTP wg wyniku' ),
				'critic' => new MP_SW_Stub_Critic( 'K9.2', 'zakres-odpowiedzi', 'Tylko dane tego procesu, przycięte zakresem roli aktora' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_Stub_Agent( 'QA9', 'bramka jakosci D9', 'tylko-json + zdarzenia po COMMIT' ),
			new MP_SW_Stub_Critic( 'QA9.K', 'krytyk bramki D9', 'nic nie wisi w żądaniu po odpowiedzi' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'WYJŚCIE I URUCHOMIENIE KOLEJKI',
			'aut. 4.4: wysyłka po akceptacji',
			$pairs,
			$gate
		);
	}
}
