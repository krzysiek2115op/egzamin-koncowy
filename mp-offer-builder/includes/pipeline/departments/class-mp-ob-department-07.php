<?php
/**
 * Dział 7 — Szablon i treść oferty.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 7, 2 pary A–K: dobór, scalenie).
 * Kryt. 5.3: PL i EN.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 7.
 */
class MP_OB_Department_07 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '7.1', 'Agent 7.1 — dobór', 'Szablon wg języka z żądania: pl albo en; wersja szablonu do historii' ),
				'critic' => new MP_OB_Stub_Critic( 'K7.1', 'Krytyk 7.1 — język-szablonu' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '7.2', 'Agent 7.2 — scalenie', 'Podstawia dane klienta, pozycje, rabaty, sumy; liczby i daty w konwencji języka' ),
				'critic' => new MP_OB_Stub_Critic( 'K7.2', 'Krytyk 7.2 — puste-pola' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA7', 'QA Agent 7 — kontrola kompletności', 'Sprawdza jednojęzyczność dokumentu' ),
			new MP_OB_Accept_Critic( 'QAK7', 'QA Krytyk 7 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			7,
			'template-content',
			'Szablon i treść oferty',
			'Dobór szablonu wg języka i scalenie z danymi klienta/pozycji/rabatów/sum.',
			$pairs,
			$gate
		);
	}
}
