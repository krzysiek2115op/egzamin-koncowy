<?php
/**
 * Dział 11 — Odpowiedź i przekazanie.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 11, 2 pary A–K: zdarzenie,
 * odpowiedź). Automatyzacja 4.4: oferta do zatwierdzenia (przekazanie
 * do plugin 3 — Sales Workflow — poprzez zdarzenie mp_offer_created).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 11.
 */
class MP_OB_Department_11 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '11.1', 'Agent 11.1 — zdarzenie', 'mp_offer_created wystawione dokładnie raz, po COMMIT; oferta w statusie draft' ),
				'critic' => new MP_OB_Stub_Critic( 'K11.1', 'Krytyk 11.1 — jednokrotność' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '11.2', 'Agent 11.2 — odpowiedź', 'JSON: offer_id, numer, wersja, adres PDF, status, trace_id; kod HTTP wg wyniku' ),
				'critic' => new MP_OB_Stub_Critic( 'K11.2', 'Krytyk 11.2 — zakres-odpowiedzi' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA11', 'QA Agent 11 — kontrola kompletności', 'Sprawdza tylko-json: wyjście zgodne ze schematem, nic nie wisi w tle po odpowiedzi' ),
			new MP_OB_Accept_Critic( 'QAK11', 'QA Krytyk 11 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			11,
			'response-handoff',
			'Odpowiedź i przekazanie',
			'Zdarzenie mp_offer_created dokładnie raz po COMMIT i odpowiedź JSON z trace_id.',
			$pairs,
			$gate
		);
	}
}
