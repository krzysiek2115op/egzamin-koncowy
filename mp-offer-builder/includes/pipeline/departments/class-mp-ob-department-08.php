<?php
/**
 * Dział 8 — Numeracja i wersja.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 8, 2 pary A–K: numer, wersja).
 * Numer PRZED renderem PDF (kryt. 5.3); kolizja numeru = FAIL_RETRY do tego
 * działu, maks. 2 podejścia — retry LOKALNY wewnątrz działu 10 (zapis),
 * pipeline zostaje ściśle jednokierunkowy.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 8.
 */
class MP_OB_Department_08 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '8.1', 'Agent 8.1 — numer', 'Nowa oferta: kandydat = ostatni numer w roku + 1 (ze snapshotu), format OF/RRRR/NNNNNN' ),
				'critic' => new MP_OB_Stub_Critic( 'K8.1', 'Krytyk 8.1 — ciągłość-numeracji' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '8.2', 'Agent 8.2 — wersja', 'Korekta: ten sam numer, wersja + 1, wskazanie na wersję poprzednią' ),
				'critic' => new MP_OB_Stub_Critic( 'K8.2', 'Krytyk 8.2 — przyrost-wersji' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA8', 'QA Agent 8 — kontrola kompletności', 'Sprawdza jedno-albo-drugie: nowy numer ALBO podbita wersja — nigdy oba naraz' ),
			new MP_OB_Accept_Critic( 'QAK8', 'QA Krytyk 8 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			8,
			'numbering-version',
			'Numeracja i wersja',
			'Kandydat numeru oferty (nowa) albo przyrost wersji (korekta) — zawsze przed renderem PDF.',
			$pairs,
			$gate
		);
	}
}
