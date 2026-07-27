<?php
/**
 * Dzial 7 pipeline LP.3 — POWIADOMIENIA E-MAIL.
 *
 * Zakres wg diagramu: pkt 2: powiadomienia e-mail · aut. 4.4
 *
 * Operacje dzialu:
 *  - Klient wg języka procesu, handlowiec pl
 *  - Kolejka z ponowieniami (maks. 3)
 *  - Wysyłka PO COMMIT, poza żądaniem
 *
 * Zrodla (Golden Rule #2): docs/dzial-07/ — co najmniej jedno oryginalne
 * zrodlo na dzial, dodawane w kolejnym kroku prac.
 * Diagram wskazuje: wp_mail() · wp_mail_from · phpmailer_init
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
 * POWIADOMIENIA E-MAIL.
 */
class MP_SW_Department_07 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 7;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'powiadomienia-e-mail';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_Stub_Agent( '7.1', 'adresaci', 'Typ zdarzenia → odbiorcy i szablon: klient (język procesu) po akceptacji oferty; handlowiec (pl) przy przypisaniu i follow-upie' ),
				'critic' => new MP_SW_Stub_Critic( 'K7.1', 'dobór-szablonu', 'Brak szablonu w języku odbiorcy = FAIL, nie ciche pl' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '7.2', 'treść', 'Podstawia zmienne (numer oferty, adres PDF ze zdarzenia, terminy); wersja szablonu do dziennika' ),
				'critic' => new MP_SW_Stub_Critic( 'K7.2', 'puste-pola', 'Żaden znacznik nie zostaje pusty; załącznik jako adres, nie plik w pętli' ),
			),
			array(
				'agent'  => new MP_SW_Stub_Agent( '7.3', 'kolejka', 'Wiersze queued w wp_mp_notifications — wysyłka wp_mail dopiero z kolejki, z ponowieniami i licznikiem prób' ),
				'critic' => new MP_SW_Stub_Critic( 'K7.3', 'zero-wysyłki-w-żądaniu', 'W pipeline żadnego SMTP; status sent/failed uzupełnia kolejka' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_Stub_Agent( 'QA7', 'bramka jakosci D7', 'kompletność-powiadomień: szablon + język + zmienne' ),
			new MP_SW_Stub_Critic( 'QA7.K', 'krytyk bramki D7', 'wysyłka w żądaniu = FAIL (łamie 1 AJAX)' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'POWIADOMIENIA E-MAIL',
			'pkt 2: powiadomienia e-mail · aut. 4.4',
			$pairs,
			$gate
		);
	}
}
