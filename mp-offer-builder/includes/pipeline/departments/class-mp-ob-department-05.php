<?php
/**
 * Dział 5 — Kalkulacja rabatów.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 5, 2 pary A–K: dobór, zastosowanie).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 5.
 */
class MP_OB_Department_05 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '5.1', 'Agent 5.1 — dobór', 'Reguły wg wariantu cenowego, segmentu i pasma wolumenu; kolejność priorytetowa' ),
				'critic' => new MP_OB_Stub_Critic( 'K5.1', 'Krytyk 5.1 — cytat-reguły' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '5.2', 'Agent 5.2 — zastosowanie', 'Rabat na pozycji albo na sumie — jedna metoda, jawnie wybrana; górny limit łączny' ),
				'critic' => new MP_OB_Stub_Critic( 'K5.2', 'Krytyk 5.2 — limit-rabatu' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA5', 'QA Agent 5 — kontrola kompletności', 'Sprawdza odtwarzalność-rabatu: ten sam koszyk + ta sama wersja reguł = ten sam wynik' ),
			new MP_OB_Accept_Critic( 'QAK5', 'QA Krytyk 5 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			5,
			'discounts',
			'Kalkulacja rabatów',
			'Dobór reguł rabatowych (wariant × segment × wolumen) i limit łącznego rabatu.',
			$pairs,
			$gate
		);
	}
}
