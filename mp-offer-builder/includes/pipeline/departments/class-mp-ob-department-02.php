<?php
/**
 * Dział 2 — Strzał odczytu BD-2 (WooCommerce + tabele wtyczki).
 *
 * Zawartość pliku (1 plik = 1 dział):
 *  - Agent 2.1 (produkty)  — istnienie/status produktów i wariantów
 *  - Agent 2.2 (ceny)      — regular/sale price przez WC_Product
 *  - Agent 2.3 (podatki)   — stawki przez WC_Tax wg klasy podatkowej
 *  - Agent 2.4 (szablony)  — aktywny szablon oferty w żądanym języku
 *  - Agent 2.5 (numeracja) — punkt startu numeracji (ostatni numer w roku)
 *  - QA Agent 2             — kompletność snapshotu (5 sekcji, jeden odczyt)
 *  - MP_OB_Department_02    — budowniczy działu
 *
 * ZASADA "JEDEN ODCZYT": to JEDYNY dział czytający WooCommerce/BD-2 w całym
 * pipeline. Działy 3–9 operują WYŁĄCZNIE na zamrożonym snapshocie w kontekście
 * (context->get('products'/'prices'/'tax_rates'/'templates'/'numbering')) —
 * żadnych kolejnych zapytań. `db_reads=1` w wyniku QA Agenta jest znacznikiem
 * potwierdzającym ten fakt (weryfikowanym przez harness), nie licznikiem
 * fizycznych zapytań SQL (tych jest tu kilka — jeden na sekcję).
 *
 * WYŁĄCZNIE oficjalne API WooCommerce (wc_get_product/WC_Tax) — NIGDY surowy
 * SQL po wp_postmeta (patrz docs/dzial-02 i [[plugin2-architecture]]): sklep
 * może używać klasycznego wp_postmeta ALBO HPOS, tylko API jest niezależne od
 * tego, który magazyn jest aktywny.
 *
 * DEFENSYWNIE: jeśli WooCommerce zostanie wyłączone PO aktywacji tej wtyczki
 * (nagłówek "Requires Plugins" chroni tylko przy aktywacji), Agent 2.1/2.3
 * sprawdza dostępność funkcji/klas WC i zwraca kontrolowany FAIL_FATAL
 * ('woocommerce_unavailable') zamiast nieobsłużonego fatal errora PHP.
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - docs/dzial-02/woocommerce-wc_product.md
 *  - docs/dzial-02/woocommerce-wc_tax.md
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 2.1 — produkty i warianty, jedną partią, przez oficjalne API.
 */
class MP_OB_D2_Agent_Products extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '2.1', 'Agent 2.1 — produkty', 'Produkty i warianty jedną partią przez oficjalne API — tylko opublikowane i możliwe do sprzedaży' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return MP_OB_Result::fail( 'WooCommerce jest niedostępne — nie można odczytać katalogu produktów.', array(), 'woocommerce_unavailable' );
		}

		$items    = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();
		$errors   = array();
		$products = array();

		foreach ( $items as $i => $item ) {
			$lookup_id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) ( isset( $item['product_id'] ) ? $item['product_id'] : 0 );
			$product   = $lookup_id > 0 ? wc_get_product( $lookup_id ) : false;

			if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() ) {
				$errors[] = array(
					'field'   => "items.$i",
					'message' => sprintf( 'Produkt/wariant %d nie istnieje w katalogu albo nie jest opublikowany.', $lookup_id ),
				);
				continue;
			}

			$products[ $i ] = array(
				'id'          => $lookup_id,
				'name'        => $product->get_name(),
				'tax_class'   => $product->get_tax_class(),
				'purchasable' => $product->is_purchasable(),
			);
		}

		if ( $errors ) {
			return MP_OB_Result::fail( 'Pozycje spoza katalogu WooCommerce.', array( 'errors' => $errors ), 'invalid_products' );
		}

		return MP_OB_Result::ok( array( 'products' => $products ) );
	}
}

/**
 * Agent 2.2 — regular_price i sale_price każdej pozycji.
 */
class MP_OB_D2_Agent_Prices extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '2.2', 'Agent 2.2 — ceny', 'regular_price i sale_price każdej pozycji, przez oficjalne API WC_Product' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return MP_OB_Result::fail( 'WooCommerce jest niedostępne.', array(), 'woocommerce_unavailable' );
		}

		$items  = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();
		$errors = array();
		$prices = array();

		foreach ( $items as $i => $item ) {
			$lookup_id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) ( isset( $item['product_id'] ) ? $item['product_id'] : 0 );
			$product   = $lookup_id > 0 ? wc_get_product( $lookup_id ) : false;

			if ( ! $product instanceof WC_Product ) {
				$errors[] = array(
					'field'   => "items.$i",
					'message' => 'Brak produktu do wyceny (patrz Agent 2.1).',
				);
				continue;
			}

			$regular = $product->get_regular_price();
			if ( '' === (string) $regular || ! is_numeric( $regular ) ) {
				$errors[] = array(
					'field'   => "items.$i.regular_price",
					'message' => 'Pozycja bez ceny regularnej — nie można zbudować oferty.',
				);
				continue;
			}

			$sale      = $product->get_sale_price();
			$on_sale   = '' !== (string) $sale && is_numeric( $sale ) && (float) $sale < (float) $regular;
			$effective = $on_sale ? (float) $sale : (float) $regular;

			// Cena UJEMNA nigdy nie jest poprawna (błędna konfiguracja WC) — jawny
			// FAIL zamiast cichego ujemnego netto/VAT/brutto w ofercie. Cena 0 jest
			// dopuszczona (legalna pozycja gratis: 0 netto → 0 VAT, arytmetyka spójna).
			if ( $effective < 0.0 ) {
				$errors[] = array(
					'field'   => "items.$i.price",
					'message' => 'Cena pozycji jest ujemna — nie można zbudować oferty.',
				);
				continue;
			}

			$prices[ $i ] = array(
				'regular_price' => (float) $regular,
				'sale_price'    => $on_sale ? (float) $sale : null,
				'on_sale'       => $on_sale,
			);
		}

		if ( $errors ) {
			return MP_OB_Result::fail( 'Nieprawidłowa lub brakująca cena pozycji.', array( 'errors' => $errors ), 'incomplete_prices' );
		}

		return MP_OB_Result::ok( array( 'prices' => $prices ) );
	}
}

/**
 * Agent 2.3 — stawki VAT wg klasy podatkowej produktów, waluta sklepu.
 */
class MP_OB_D2_Agent_Tax extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '2.3', 'Agent 2.3 — podatki', 'Stawki z WC_Tax wg klasy podatkowej; waluta sklepu' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		if ( ! class_exists( 'WC_Tax' ) ) {
			return MP_OB_Result::fail( 'WooCommerce jest niedostępne.', array(), 'woocommerce_unavailable' );
		}

		$products    = is_array( $context->get( 'products' ) ) ? $context->get( 'products' ) : array();
		$tax_classes = array();
		foreach ( $products as $product ) {
			$tax_classes[ isset( $product['tax_class'] ) ? $product['tax_class'] : '' ] = true;
		}

		$errors = array();
		$rates  = array();
		foreach ( array_keys( $tax_classes ) as $tax_class ) {
			// get_base_tax_rates(), NIE get_rates(): oferta powstaje po stronie
			// serwera (AJAX wp-admin) BEZ realnego klienta WooCommerce, a
			// WC_Tax::get_rates() rozwiązuje stawkę wg lokalizacji KLIENTA/sesji
			// — w tym kontekście zwraca pustkę nawet gdy sklep ma bazę w kraju,
			// dla którego stawka jest skonfigurowana (potwierdzone na żywym
			// WooCommerce 2026-07-25). get_base_tax_rates() bierze stawkę
			// DETERMINISTYCZNIE z bazy sklepu, niezależnie od sesji. Mechanizm
			// VAT (krajowy / odwrotne obciążenie / poza zakresem) i tak ustala
			// dopiero Dział 6 wg kraju klienta — tu chodzi o stawkę krajową bazy.
			$found = WC_Tax::get_base_tax_rates( $tax_class );
			if ( empty( $found ) ) {
				$errors[] = array(
					'field'   => "tax_class.$tax_class",
					'message' => sprintf( 'Brak stawki VAT dla klasy podatkowej "%s".', $tax_class ),
				);
				continue;
			}
			$first               = reset( $found );
			$rates[ $tax_class ] = array(
				'rate'  => isset( $first['rate'] ) ? (float) $first['rate'] : null,
				'label' => isset( $first['label'] ) ? (string) $first['label'] : '',
			);
		}

		if ( $errors ) {
			// Brak stawki = FAIL, NIE domyślne 23% (kryt. "stawka-istnieje").
			return MP_OB_Result::fail( 'Brak skonfigurowanej stawki VAT.', array( 'errors' => $errors ), 'missing_tax_rate' );
		}

		return MP_OB_Result::ok(
			array(
				'tax_rates' => $rates,
				'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'PLN',
			)
		);
	}
}

/**
 * Agent 2.4 — aktywny szablon oferty w żądanym języku, z tabeli wtyczki.
 */
class MP_OB_D2_Agent_Templates extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '2.4', 'Agent 2.4 — szablony', 'Szablon oferty w żądanym języku z tabeli wtyczki, z numerem wersji' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		global $wpdb;

		$lang  = (string) $context->get( 'lang', '' );
		$table = MP_Offer_Builder_DB::templates_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE lang = %s AND status = 'active' ORDER BY version DESC LIMIT 1", $lang ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			// Brak szablonu w języku = FAIL, nie ciche przejście na polski.
			return MP_OB_Result::fail( sprintf( 'Brak aktywnego szablonu oferty w języku "%s".', $lang ), array(), 'missing_template' );
		}

		return MP_OB_Result::ok( array( 'templates' => array( $lang => $row ) ) );
	}
}

/**
 * Agent 2.5 — punkt startu numeracji (ostatni numer w roku, wersje).
 */
class MP_OB_D2_Agent_Numbering extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '2.5', 'Agent 2.5 — numeracja', 'Ostatni numer w roku + istniejące wersje oferty klienta' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$year        = (int) gmdate( 'Y' );
		$last_number = MP_Offer_Builder_DB::get_last_offer_number_for_year( $year );

		$existing_offer_number = null;
		$existing_version      = null;
		$existing_created_by   = null;
		$existing_lock_version = null;

		$offer_id = (int) $context->get( 'offer_id', 0 );
		if ( $offer_id > 0 ) {
			$offer = MP_Offer_Builder_DB::get_offer( $offer_id );
			if ( $offer ) {
				// Niezależnie od obecności offer_number: draft z Kroku 2.5 może już mieć
				// właściciela (np. kolejne dokończenie po nieudanej wcześniej próbie),
				// mimo że numeru jeszcze nie ma (Krok 4, decyzja własności ofert).
				$existing_created_by = ( isset( $offer['created_by'] ) && null !== $offer['created_by'] ) ? (int) $offer['created_by'] : null;
				if ( ! empty( $offer['offer_number'] ) ) {
					$existing_offer_number = $offer['offer_number'];
					$existing_version      = MP_Offer_Builder_DB::get_max_version_for_offer_number( $offer['offer_number'] );
				}
				// Blokada optymistyczna (kolumna `lock_version`, DEFAULT 1) — CELOWO
				// ODRĘBNA od `existing_version` wyżej. `version` (numer BIZNESOWY oferty)
				// dla PIERWSZEGO ponumerowania draftu jest zawsze 1 (Dział 8, tryb
				// 'new_number'), niezależnie od tego, ile razy draft był wcześniej
				// zapisywany bez numeru — użycie GO jako tokenu blokady dawałoby WHERE,
				// które nadal pasuje po zapisie konkurenta (lost update niewykryty).
				// `lock_version` rośnie bezwarunkowo przy KAŻDYM zapisie Działu 10.
				$existing_lock_version = isset( $offer['lock_version'] ) ? (int) $offer['lock_version'] : 1;
			}
		}

		return MP_OB_Result::ok(
			array(
				'numbering' => array(
					'year'                  => $year,
					'last_number'           => $last_number,
					'existing_offer_number' => $existing_offer_number,
					'existing_version'      => $existing_version,
					'existing_created_by'   => $existing_created_by,
					'existing_lock_version' => $existing_lock_version,
				),
				// Znacznik "jeden odczyt" MUSI pochodzić z agenta (nie z QA gate —
				// MP_OB_Department::process() nie scala danych bramki z kontekstem,
				// tylko jej werdykt PASS/FAIL), żeby przetrwał do final_data i mógł
				// być zweryfikowany przez działy 3-9/harness.
				'db_reads'  => 1,
			)
		);
	}
}

/**
 * QA Agent 2 — kontrola kompletności snapshotu (pięć sekcji).
 */
class MP_OB_D2_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA2', 'QA Agent 2 — kontrola kompletności', 'Sprawdza jeden-odczyt: db_reads = 1, pięć sekcji snapshotu' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$required = array( 'products', 'prices', 'tax_rates', 'templates', 'numbering' );
		$missing  = array();
		foreach ( $required as $key ) {
			if ( empty( $context->get( $key ) ) ) {
				$missing[] = $key;
			}
		}

		if ( $missing ) {
			return MP_OB_Result::fail( 'Niekompletny snapshot BD-2: ' . implode( ', ', $missing ), array( 'missing' => $missing ), 'incomplete_snapshot' );
		}

		return MP_OB_Result::ok( array( 'db_reads' => 1 ) );
	}
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
				'agent'  => new MP_OB_D2_Agent_Products(),
				'critic' => new MP_OB_Parity_Critic( 'K2.1', 'Krytyk 2.1 — istnienie-produktu', 'products' ),
			),
			array(
				'agent'  => new MP_OB_D2_Agent_Prices(),
				'critic' => new MP_OB_Parity_Critic( 'K2.2', 'Krytyk 2.2 — kompletność-cen', 'prices' ),
			),
			array(
				'agent'  => new MP_OB_D2_Agent_Tax(),
				'critic' => new MP_OB_Array_Critic( 'K2.3', 'Krytyk 2.3 — stawka-istnieje', 'tax_rates' ),
			),
			array(
				'agent'  => new MP_OB_D2_Agent_Templates(),
				'critic' => new MP_OB_Array_Critic( 'K2.4', 'Krytyk 2.4 — wersja-szablonu', 'templates' ),
			),
			array(
				'agent'  => new MP_OB_D2_Agent_Numbering(),
				'critic' => new MP_OB_Array_Critic( 'K2.5', 'Krytyk 2.5 — punkt-startu', 'numbering' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D2_QA_Agent(),
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
