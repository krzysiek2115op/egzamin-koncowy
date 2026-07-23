<?php
/**
 * Dział 1 — Brama i kontrakt żądania.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 1, 3 pary A–K: kontrakt,
 * uprawnienie, idempotencja).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 1.
 */
class MP_OB_Department_01 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '1.1', 'Agent 1.1 — kontrakt', 'Waliduje wejściowy JSON: dane klienta, pozycje, wariant cenowy, język pl/en' ),
				'critic' => new MP_OB_Stub_Critic( 'K1.1', 'Krytyk 1.1 — schemat-wejścia' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '1.2', 'Agent 1.2 — uprawnienie', 'Żądanie automatyczne (po leadzie) albo ręczne od handlowca — ręczne z nonce i uprawnieniami' ),
				'critic' => new MP_OB_Stub_Critic( 'K1.2', 'Krytyk 1.2 — kto-woła' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '1.3', 'Agent 1.3 — idempotencja', 'request_id (UUID) na całe żądanie — podwójny klik to jedna oferta' ),
				'critic' => new MP_OB_Stub_Critic( 'K1.3', 'Krytyk 1.3 — klucz-idempotencji' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA1', 'QA Agent 1 — kontrola kompletności', 'Sprawdza komplet wejścia: pozycje + wariant + język' ),
			new MP_OB_Accept_Critic( 'QAK1', 'QA Krytyk 1 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			1,
			'gate-contract',
			'Brama i kontrakt żądania',
			'Wejście tylko JSON: walidacja schematu, uprawnień wywołania i klucza idempotencji.',
			$pairs,
			$gate
		);
	}
}
