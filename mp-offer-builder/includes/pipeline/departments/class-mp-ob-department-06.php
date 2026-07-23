<?php
/**
 * Dział 6 — Podatki i suma końcowa.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 6, 2 pary A–K: mechanizm,
 * zaokrąglenia). Kryt. 5.3 i art. 196 dyrektywy VAT (odwrotne obciążenie).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 6.
 */
class MP_OB_Department_06 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '6.1', 'Agent 6.1 — mechanizm', 'PL — stawka krajowa z WC_Tax; UE z ważnym VAT — odwrotne obciążenie 0%; poza UE — poza zakresem VAT' ),
				'critic' => new MP_OB_Stub_Critic( 'K6.1', 'Krytyk 6.1 — wybór-mechanizmu' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '6.2', 'Agent 6.2 — zaokrąglenia', 'Netto, VAT i brutto w groszach; zaokrąglenie raz — na sumie podatku, metodą półówkową' ),
				'critic' => new MP_OB_Stub_Critic( 'K6.2', 'Krytyk 6.2 — jeden-punkt-zaokrąglenia' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA6', 'QA Agent 6 — kontrola kompletności', 'Sprawdza spójność-sumy: netto + VAT = brutto, zawsze, w groszach' ),
			new MP_OB_Accept_Critic( 'QAK6', 'QA Krytyk 6 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			6,
			'tax-totals',
			'Podatki i suma końcowa',
			'Wybór mechanizmu VAT (PL / UE reverse charge / poza UE) i jedno zaokrąglenie na sumie.',
			$pairs,
			$gate
		);
	}
}
