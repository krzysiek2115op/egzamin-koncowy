<?php
/**
 * Dział 3 — Walidacja pozycji oferty.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 3, 2 pary A–K: istnienie, ilości).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 3.
 */
class MP_OB_Department_03 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '3.1', 'Agent 3.1 — istnienie', 'Każda pozycja żądania ma odpowiednik w snapshocie: produkt, konkretny wariant, status opublikowany' ),
				'critic' => new MP_OB_Stub_Critic( 'K3.1', 'Krytyk 3.1 — dopasowanie-pozycji' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '3.2', 'Agent 3.2 — ilości', 'Ilości całkowite 1–10 000; maksymalnie 50 pozycji na ofertę' ),
				'critic' => new MP_OB_Stub_Critic( 'K3.2', 'Krytyk 3.2 — zakres-ilości' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA3', 'QA Agent 3 — kontrola kompletności', 'Sprawdza policzalność całego koszyka' ),
			new MP_OB_Accept_Critic( 'QAK3', 'QA Krytyk 3 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			3,
			'validate-items',
			'Walidacja pozycji oferty',
			'Dopasowanie pozycji żądania do snapshotu i kontrola ilości/limitów koszyka.',
			$pairs,
			$gate
		);
	}
}
