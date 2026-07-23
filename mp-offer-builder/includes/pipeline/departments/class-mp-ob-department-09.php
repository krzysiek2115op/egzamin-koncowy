<?php
/**
 * Dział 9 — Render PDF.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 9, 2 pary A–K: render, kontrola).
 * Dompdf — zależność RUNTIME (pakowanie odłożone do kroku 3, dział 9).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 9.
 */
class MP_OB_Department_09 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '9.1', 'Agent 9.1 — render', 'HTML z Działu 7 — PDF A4; fonty osadzone; metadane (tytuł = numer oferty)' ),
				'critic' => new MP_OB_Stub_Critic( 'K9.1', 'Krytyk 9.1 — diakrytyka' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '9.2', 'Agent 9.2 — kontrola', 'Strony > 0, rozmiar w limicie; w treści pliku numer oferty i suma brutto; SHA-256' ),
				'critic' => new MP_OB_Stub_Critic( 'K9.2', 'Krytyk 9.2 — zawartość-pdf' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA9', 'QA Agent 9 — kontrola kompletności', 'Sprawdza plik-to-nie-dowód: sprawdzana treść, nie istnienie; plik tymczasowy do czasu COMMIT' ),
			new MP_OB_Accept_Critic( 'QAK9', 'QA Krytyk 9 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			9,
			'render-pdf',
			'Render PDF',
			'Render HTML szablonu do PDF A4 (fonty osadzone) i kontrola treści + skrótu pliku.',
			$pairs,
			$gate
		);
	}
}
