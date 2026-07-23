<?php
/**
 * Dział 2 — Strzał odczytu BD-2.
 *
 * Krok 2 (rusztowanie): wszystkie pary Agent/Krytyk i bramka QA to zaślepki
 * (MP_OB_Stub_Agent/Critic) — realna logika trafi tu w kroku 3, wg
 * blueprint/LP2_diagram_wizualny.html (dział 2, 5 par A–K: produkty, ceny,
 * podatki, szablony, numeracja). Jeden odczyt WooCommerce, wyłącznie przez
 * oficjalne API (WC_Product/wc_get_products/WC_Tax) — nigdy surowym SQL.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Budowniczy działu 2.
 */
class MP_OB_Department_02 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_Stub_Agent( '2.1', 'Agent 2.1 — produkty', 'Produkty i warianty jedną partią przez oficjalne API — tylko opublikowane i możliwe do sprzedaży' ),
				'critic' => new MP_OB_Stub_Critic( 'K2.1', 'Krytyk 2.1 — istnienie-produktu' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '2.2', 'Agent 2.2 — ceny', 'regular_price i sale_price każdej pozycji — przez tabelę podręczną meta_lookup' ),
				'critic' => new MP_OB_Stub_Critic( 'K2.2', 'Krytyk 2.2 — kompletność-cen' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '2.3', 'Agent 2.3 — podatki', 'Stawki z WC_Tax wg klasy podatkowej; waluta sklepu i miejsca dziesiętne' ),
				'critic' => new MP_OB_Stub_Critic( 'K2.3', 'Krytyk 2.3 — stawka-istnieje' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '2.4', 'Agent 2.4 — szablony', 'Szablony ofert pl i en z tabeli wtyczki, z numerem wersji' ),
				'critic' => new MP_OB_Stub_Critic( 'K2.4', 'Krytyk 2.4 — wersja-szablonu' ),
			),
			array(
				'agent'  => new MP_OB_Stub_Agent( '2.5', 'Agent 2.5 — numeracja', 'Ostatni numer w roku + istniejące wersje oferty klienta' ),
				'critic' => new MP_OB_Stub_Critic( 'K2.5', 'Krytyk 2.5 — punkt-startu' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_Stub_Agent( 'QA2', 'QA Agent 2 — kontrola kompletności', 'Sprawdza jeden-odczyt: db_reads = 1, pięć sekcji snapshotu' ),
			new MP_OB_Accept_Critic( 'QAK2', 'QA Krytyk 2 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			2,
			'read-snapshot',
			'Strzał odczytu — BD-2',
			'Jeden odczyt WooCommerce (produkty, ceny, podatki, szablony, numeracja) — działy 3–9 to potem czyste funkcje na zamrożonym snapshocie.',
			$pairs,
			$gate
		);
	}
}
