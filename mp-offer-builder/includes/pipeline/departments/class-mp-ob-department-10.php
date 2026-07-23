<?php
/**
 * Dział 10 — Zapis, jedna transakcja.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 10, 3 pary A–K: plan, transakcja,
 * dziennik). Kryt. 5.1/5.5, unikalna numeracja. Ten dział jest objęty jedną
 * transakcją DB (MP_OB_Pipeline_Factory::make() -> set_transactional_from(10)).
 * Kolizja UNIQUE(offer_number, version) -> FAIL_RETRY do działu 8, maks. 2
 * podejścia — retry LOKALNY wewnątrz agenta zapisu, nie pętla pipeline'u.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 10.
 */
class MP_OB_Department_10 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '10.1', 'Agent 10.1 — plan', 'Komplet operacji: nagłówek, pozycje, wersja (pełny stan data_json), dziennik' ),
				'critic' => new MP_OB_Stub_Critic( 'K10.1', 'Krytyk 10.1 — zgodność-z-DDL' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '10.2', 'Agent 10.2 — transakcja', 'START TRANSACTION — INSERT-y — COMMIT; kolizja UNIQUE — RETRY do D8; inny błąd — ROLLBACK' ),
				'critic' => new MP_OB_Stub_Critic( 'K10.2', 'Krytyk 10.2 — jeden-zapis' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '10.3', 'Agent 10.3 — dziennik', 'Zdarzenia offer.created / offer.versioned z wartościami przed i po' ),
				'critic' => new MP_OB_Stub_Critic( 'K10.3', 'Krytyk 10.3 — odtwarzalność' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA10', 'QA Agent 10 — kontrola kompletności', 'Sprawdza atomowość: wiersze = plan, żadnych rekordów częściowych i PDF-sierot' ),
			new MP_OB_Accept_Critic( 'QAK10', 'QA Krytyk 10 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			10,
			'save-transaction',
			'Zapis — jedna transakcja',
			'Zapis nagłówka, pozycji, wersji i dziennika jedną transakcją DB; po ROLLBACK — tymczasowy PDF kasowany.',
			$pairs,
			$gate
		);
	}
}
