<?php
/**
 * Dział 4 — Ceny bazowe.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 4, 2 pary A–K: jednostkowe,
 * pozycje). Kryt. 5.3: kwoty wyłącznie w groszach (BIGINT), zero float.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 4.
 */
class MP_OB_Department_04 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '4.1', 'Agent 4.1 — jednostkowe', 'Cena jednostkowa: sale_price jeśli aktywna, inaczej regular_price; przeliczenie na grosze' ),
				'critic' => new MP_OB_Stub_Critic( 'K4.1', 'Krytyk 4.1 — wybór-ceny' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '4.2', 'Agent 4.2 — pozycje', 'Wartość pozycji = cena × ilość, w groszach, bez zaokrągleń pośrednich' ),
				'critic' => new MP_OB_Stub_Critic( 'K4.2', 'Krytyk 4.2 — suma-pozycji' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA4', 'QA Agent 4 — kontrola kompletności', 'Sprawdza zero-float: arytmetyka całkowitoliczbowa' ),
			new MP_OB_Accept_Critic( 'QAK4', 'QA Krytyk 4 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			4,
			'base-prices',
			'Ceny bazowe',
			'Wybór ceny jednostkowej (promocyjna/katalogowa) i suma pozycji w groszach.',
			$pairs,
			$gate
		);
	}
}
