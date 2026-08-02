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
 *  - docs/dzial-02/woocommerce-wc_get_products.md
 *  - WC_Product  https://woocommerce.github.io/code-reference/classes/WC-Product.html
 *  - WC_Tax      https://woocommerce.github.io/code-reference/classes/WC-Tax.html
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
		parent::__construct( '2.1', 'Agent 2.1 — produkty', 'Produkty i warianty jedną partią przez oficjalne API — filtruje po statusie publikacji, a możliwość sprzedaży zapisuje do snapshotu (egzekwuje ją Dział 3)' );
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

		/*
		 * Produkty pobrane KOMPLETEM przed petla. Wczesniej kazda pozycja oferty
		 * kosztowala osobny odczyt, wiec zamowienie hurtowe na 40 pozycji robilo
		 * 40 zapytan tam, gdzie wystarcza dwa.
		 */
		$produkty = MP_OB_Products::for_items( $items );

		foreach ( $items as $i => $item ) {
			$lookup_id = MP_OB_Products::lookup_id( (array) $item );
			$product   = isset( $produkty[ $lookup_id ] ) ? $produkty[ $lookup_id ] : false;

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
				// S3-02: status podatkowy produktu — produkt 'none' (zwolniony) NIE
				// może dostać VAT wg klasy (Dział 6 traktuje 'none' jako 0%).
				'tax_status'  => $product->get_tax_status(),
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

		/*
		 * CENY BRUTTO W SKLEPIE. `get_regular_price()` i `get_price()` zwracaja
		 * liczbe SUROWA — jej znaczenie zalezy od ustawienia „Ceny wprowadzone
		 * z podatkiem" (`woocommerce_prices_include_tax`), typowo wlaczonego
		 * w polskich sklepach. Dzial 6 traktuje te liczbe jako NETTO i dolicza
		 * VAT, wiec przy cenach brutto oferta wychodzila drozsza o cala stawke:
		 * 123,00 brutto -> netto 123,00 + VAT 28,29 = 151,29. Blad byl CICHY —
		 * wszystkie bramki przechodzily, bo arytmetyka jest wewnetrznie spojna,
		 * a rozjazd widac dopiero na dokumencie u klienta.
		 *
		 * `wc_get_price_excluding_tax()` zdejmuje podatek wg klasy podatkowej
		 * produktu; przy sklepie z cenami netto zwraca te sama liczbe.
		 */

		/*
		 * Brak funkcji do ODCZYTU ustawienia był traktowany inaczej niż brak funkcji
		 * do PRZELICZENIA (warunek niżej): tamten kończy dział twardym FAIL-em, ten
		 * przechodził cicho w „cennik jest netto". To dokładnie to samo ryzyko —
		 * doliczenie VAT-u do ceny, która już go zawiera — tylko wpisane w wartość
		 * domyślną koniunkcji zamiast w jawną decyzję.
		 */
		if ( ! function_exists( 'wc_prices_include_tax' ) ) {
			return MP_OB_Result::fail(
				'WooCommerce nie udostępnia ustawienia „Ceny wprowadzone z podatkiem" — bez niego nie wiadomo, czy cennik jest netto, czy brutto.',
				array(),
				'tax_setting_unavailable'
			);
		}

		$ceny_z_podatkiem = wc_prices_include_tax();

		if ( $ceny_z_podatkiem && ! function_exists( 'wc_get_price_excluding_tax' ) ) {
			// Twardy FAIL zamiast liczenia dalej: milczace doliczenie VAT-u do ceny,
			// ktora juz go zawiera, to blad na dokumencie handlowym.
			return MP_OB_Result::fail(
				'Sklep ma ceny wprowadzone z podatkiem, a WooCommerce nie udostępnia przeliczenia na netto.',
				array(),
				'tax_conversion_unavailable'
			);
		}

		/*
		 * Produkty pobrane KOMPLETEM przed petla. Wczesniej kazda pozycja oferty
		 * kosztowala osobny odczyt, wiec zamowienie hurtowe na 40 pozycji robilo
		 * 40 zapytan tam, gdzie wystarcza dwa.
		 */
		$produkty = MP_OB_Products::for_items( $items );

		foreach ( $items as $i => $item ) {
			$lookup_id = MP_OB_Products::lookup_id( (array) $item );
			$product   = isset( $produkty[ $lookup_id ] ) ? $produkty[ $lookup_id ] : false;

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

			// S3-01: uwzględnij HARMONOGRAM promocji (date_on_sale_from/to).
			// is_on_sale()/get_price() liczą aktywną cenę zgodnie z oknem dat,
			// inaczej niż surowe get_sale_price() (samo meta, bez sprawdzania dat) —
			// produkt z wygasłą/zaplanowaną promocją brałby błędnie cenę promo.
			$on_sale   = $product->is_on_sale();
			$active    = $product->get_price();
			$ma_cene   = '' !== (string) $active && is_numeric( $active );
			$effective = $ma_cene ? (float) $active : (float) $regular;

			/*
			 * „PROMOCJA" BEZ CENY PROMOCYJNEJ TO NIE PROMOCJA.
			 *
			 * Gdy katalog zgłasza aktywną promocję, a `get_price()` nie oddaje
			 * liczby (rozjechane meta `_price` i `_sale_price` po imporcie cennika
			 * bez synchronizacji), cena promocyjna była po cichu zastępowana
			 * regularną — i tak trafiała do snapshotu: `sale_price` równe
			 * `regular_price` przy `on_sale = true`. Snapshot deklarował promocję,
			 * której wartość nie jest ceną promocyjną, a dokument dla klienta
			 * obiecywał rabat równy zeru.
			 *
			 * Nie zgadujemy, ile ta promocja miała wynosić. Pozycja idzie do błędów,
			 * tak samo jak brak ceny regularnej kilka linii wyżej.
			 */
			if ( $on_sale && ! $ma_cene ) {
				$errors[] = array(
					'field'   => "items.$i.sale_price",
					'message' => 'Produkt jest oznaczony jako promocyjny, ale nie ma aktywnej ceny promocyjnej — nie zgadujemy jej wysokości.',
				);
				continue;
			}

			/*
			 * Cena ujemna odrzucana NA SUROWYCH danych z katalogu, PRZED konwersją.
			 * Wcześniej sprawdzenie stało dopiero za nią i przy cenniku brutto
			 * przepuszczało `-123`: `wc_get_price_excluding_tax()` nie jest zwykłym
			 * dzieleniem przez stawkę i dla wartości ujemnej nie oddaje wartości
			 * ujemnej, więc warunek `< 0` nie miał już czego złapać. Komentarz niżej
			 * obiecywał ochronę właśnie przed surową zawartością `_regular_price`,
			 * a badał liczbę, która nie była już tą liczbą.
			 *
			 * Ta gałąź wykonuje się tylko przy `prices_include_tax = yes`, czyli
			 * w sklepach z cennikiem brutto — dlatego luka przetrwała testy
			 * prowadzone na cenniku netto.
			 *
			 * Sprawdzamy OBIE ceny, nie tylko efektywną. `_price` i `_regular_price`
			 * to dwa osobne pola meta i potrafią się rozjechać: import cennika bez
			 * synchronizacji, zapis SQL, wtyczka do promocji. Przy
			 * `_regular_price = -100` i `_price = 50` cena efektywna jest dodatnia,
			 * więc warunek na samej efektywnej nie łapał — a produkt bez aktywnej
			 * promocji ma `on_sale = false`, czyli Agent 4.1 bierze wprost
			 * `regular_price`. Na dokument handlowy szła wtedy ujemna wartość
			 * pozycji przy przechodzących bramkach, bo arytmetyka pozostaje
			 * wewnętrznie spójna. Cena 0 zostaje dopuszczona (pozycja gratis:
			 * 0 netto → 0 VAT).
			 */
			if ( (float) $regular < 0.0 || $effective < 0.0 ) {
				$errors[] = array(
					'field'   => (float) $regular < 0.0 ? "items.$i.regular_price" : "items.$i.price",
					'message' => 'Cena pozycji jest ujemna — nie można zbudować oferty.',
				);
				continue;
			}

			// Sprowadzenie do NETTO na samej granicy odczytu — dalej caly pipeline
			// pracuje juz na jednej, jednoznacznej jednostce.
			if ( $ceny_z_podatkiem ) {
				$regular = wc_get_price_excluding_tax(
					$product,
					array(
						'price' => $regular,
						'qty'   => 1,
					)
				);

				$effective = (float) wc_get_price_excluding_tax(
					$product,
					array(
						'price' => $effective,
						'qty'   => 1,
					)
				);
			}

			/*
			 * Drugiego sprawdzenia ceny ujemnej TU NIE MA i nie jest to przeoczenie.
			 *
			 * Stało tu powtórzenie warunku sprzed konwersji — ten sam zakres (obie
			 * ceny), ta sama granica. Nie istniało wejście, które przeszłoby przez
			 * pierwsze i wpadło w drugie: po pierwszym obie wartości są nieujemne,
			 * a `wc_get_price_excluding_tax()` — wedle komentarza przy tamtym
			 * sprawdzeniu — wartości ujemnej z nieujemnej nie robi. Martwy blok
			 * wyglądał jak druga linia obrony, a był kopią pierwszej.
			 */
			$prices[ $i ] = array(
				'regular_price' => (float) $regular,
				'sale_price'    => $on_sale ? $effective : null,
				'on_sale'       => $on_sale,

				// Slad w snapshocie: po latach musi byc widac, czy cena zrodlowa
				// wymagala przeliczenia, czy byla juz netto.
				'from_gross'    => $ceny_z_podatkiem,
			);
		}

		if ( $errors ) {
			return MP_OB_Result::fail( 'Nieprawidłowa lub brakująca cena pozycji.', array( 'errors' => $errors ), 'incomplete_prices' );
		}

		/*
		 * Klucz nazywa ZAWARTOŚĆ tego wyniku, a nie ustawienie sklepu.
		 *
		 * `prices_include_tax` znaczyło „sklep ma cennik brutto" — a ceny w tym
		 * samym wyniku były już przeliczone na netto, czyli podatku NIE zawierały.
		 * Czytelnik snapshotu dowiadywał się czegoś dokładnie odwrotnego niż stan
		 * rzeczy. Pole per pozycja (`from_gross`) nazywało to poprawnie od początku
		 * i teraz oba mówią to samo.
		 */
		return MP_OB_Result::ok(
			array(
				'prices'            => $prices,
				'prices_from_gross' => $ceny_z_podatkiem,
			)
		);
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
			/*
			 * Tylko pozycje OPODATKOWANE wnoszą wymóg stawki. Agent 2.1 zapisuje
			 * `tax_status` właśnie po to (S3-02), a Agent 6.2 wyprowadza pozycje
			 * 'none' do własnej klasy i jawnie zwalnia je z tego wymogu — więc
			 * stawka dla klasy pozycji zwolnionej nigdy nie zostanie użyta.
			 * Bez tego filtra usługa zwolniona z VAT w klasie bez skonfigurowanych
			 * stawek (np. domyślna „Zero rate") wywracała CAŁY dział błędem
			 * 'missing_tax_rate' i blokowała ofertę, w której wszystkie realnie
			 * potrzebne stawki były na miejscu.
			 *
			 * Klasa używana także przez pozycję opodatkowaną nadal trafi do zbioru
			 * — z tamtej pozycji.
			 */
			if ( MP_OB_Products::zwolniona_z_vat( (array) $product ) ) {
				continue;
			}

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

			/*
			 * WSZYSTKIE dopasowane stawki, nie pierwsza z brzegu.
			 *
			 * `get_base_tax_rates()` zwraca TABLICĘ stawek pasujących do bazy sklepu
			 * — dokumentacja WooCommerce mówi wprost o „array of matching rates".
			 * Wcześniej `reset( $found )` brało pierwszy wiersz, a resztę wtyczka
			 * po cichu wyrzucała i kończyła dział statusem OK. Sklep z dwiema
			 * skonfigurowanymi stawkami dla klasy (np. 20% + 3% dopłaty lokalnej)
			 * dostawał na ofercie 20% — VAT i kwota brutto NIŻSZE niż w sklepie,
			 * bez błędu i bez śladu.
			 *
			 * Stawki zwykłe sumujemy, bo WooCommerce nalicza każdą od tej samej
			 * podstawy netto. Stawki ZŁOŻONEJ (compound) tak przedstawić się nie da
			 * — liczy się ją od kwoty już opodatkowanej, więc jednej liczbie
			 * procentowej nie odpowiada. Zamiast zgadywać, odmawiamy: dokument
			 * handlowy z wymyśloną stawką jest gorszy niż brak dokumentu.
			 */
			$rate   = 0.0;
			$labels = array();

			foreach ( $found as $wiersz ) {
				if ( isset( $wiersz['compound'] ) && 'yes' === $wiersz['compound'] ) {
					return MP_OB_Result::fail(
						sprintf( 'Klasa podatkowa "%s" ma stawkę złożoną (compound) — oferta nie potrafi jej przedstawić jedną stawką.', $tax_class ),
						array(
							'errors' => array(
								array(
									'field'   => "tax_class.$tax_class",
									'message' => 'Stawka złożona (compound) nie jest obsługiwana w ofercie.',
								),
							),
						),
						'compound_tax_rate'
					);
				}

				$rate    += isset( $wiersz['rate'] ) ? (float) $wiersz['rate'] : 0.0;
				$labels[] = isset( $wiersz['label'] ) ? (string) $wiersz['label'] : '';
			}

			$labels = array_filter( array_unique( $labels ) );

			$rates[ $tax_class ] = array(
				'rate'  => $rate,
				'label' => implode( ' + ', $labels ),
			);
		}

		if ( $errors ) {
			// Brak stawki = FAIL, NIE domyślne 23% (kryt. "stawka-istnieje").
			return MP_OB_Result::fail( 'Brak skonfigurowanej stawki VAT.', array( 'errors' => $errors ), 'missing_tax_rate' );
		}

		return MP_OB_Result::ok(
			array(
				'tax_rates'       => $rates,
				// Ile klas NAPRAWDE wymagalo stawki. Krytyk 2.3 nie ma jak tego
				// odtworzyc z samego `tax_rates`: pusty zbior znaczy albo „zadna
				// stawka nie byla potrzebna" (same pozycje zwolnione — oferta
				// poprawna), albo „stawek brakuje" (blad). Bez tej liczby te dwa
				// przypadki sa nierozroznialne.
				'taxable_classes' => count( $tax_classes ),
				'currency'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'PLN',
			)
		);
	}
}

/**
 * Krytyk 2.3 — stawka istnieje dla kazdej klasy, ktora jej wymaga.
 *
 * Zastapil MP_OB_Array_Critic, ktorego jedyny warunek brzmial „tablica
 * `tax_rates` ma byc niepusta". Po tym, jak Agent 2.3 przestal zbierac klasy
 * pozycji zwolnionych z VAT, oferta zlozona WYLACZNIE ze zwolnien dawala pusty
 * zbior stawek — poprawnie — i byla przez to odrzucana. A oferta na same uslugi
 * zwolnione (art. 43 ustawy o VAT: szkolenia, uslugi medyczne, finansowe) to
 * normalna oferta, nie przypadek skrajny.
 */
class MP_OB_D2_Tax_Critic extends MP_OB_Abstract_Critic {

	/**
	 * @param MP_OB_Result  $agent_result Wynik agenta.
	 * @param MP_OB_Context $context      Kontekst.
	 * @return MP_OB_Result
	 */
	public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
		unset( $context );

		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$data = $agent_result->get_data();

		if ( ! isset( $data['tax_rates'] ) || ! is_array( $data['tax_rates'] ) ) {
			return MP_OB_Result::fail( 'Pusta lub zła struktura sekcji: tax_rates', array(), 'invalid_structure' );
		}

		$wymagane = isset( $data['taxable_classes'] ) ? (int) $data['taxable_classes'] : 0;

		// Kazda klasa wymagajaca stawki musi ja miec. Agent zwraca FAIL, gdy
		// stawki brakuje, wiec tu chodzi o kontrole zgodnosci liczb — zeby
		// ciche zgubienie wpisu miedzy zbieraniem klas a budowaniem stawek
		// nie przeszlo dalej.
		if ( count( $data['tax_rates'] ) !== $wymagane ) {
			return MP_OB_Result::fail(
				sprintf(
					'Stawki VAT niekompletne: klas wymagajacych stawki %d, stawek %d.',
					$wymagane,
					count( $data['tax_rates'] )
				),
				array(),
				'invalid_structure'
			);
		}

		return MP_OB_Result::ok( $data );
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
		// S5-02: rok wg strefy SKLEPU (current_time), nie UTC (gmdate) — inaczej
		// oferta tuż po lokalnej północy na przełomie roku dostaje numer starego roku.
		$year        = (int) current_time( 'Y' );
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
		$required = array( 'products', 'prices', 'templates', 'numbering' );
		$missing  = array();
		foreach ( $required as $key ) {
			if ( empty( $context->get( $key ) ) ) {
				$missing[] = $key;
			}
		}

		/*
		 * `tax_rates` sprawdzane osobno: ma byc OBECNE, ale wolno mu byc puste.
		 * Reszta sekcji snapshotu pusta znaczy „brakuje danych"; tutaj pusty
		 * zbior to poprawny wynik oferty zlozonej z samych pozycji zwolnionych
		 * z VAT — zadna stawka nie byla potrzebna. `empty()` nie odroznia tego
		 * od braku, `is_array()` odroznia: sekcja nieustawiona daje null.
		 */
		if ( ! is_array( $context->get( 'tax_rates' ) ) ) {
			$missing[] = 'tax_rates';
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
				'critic' => new MP_OB_D2_Tax_Critic( 'K2.3', 'Krytyk 2.3 — stawka-istnieje' ),
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
