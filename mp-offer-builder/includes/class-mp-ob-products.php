<?php
/**
 * Pobieranie produktów WooCommerce KOMPLETEM, nie po jednym.
 *
 * `wc_get_product()` w pętli po pozycjach oferty daje tyle odczytów, ile
 * pozycji. Oferta na 40 pozycji to nie przypadek skrajny, tylko normalne
 * zamówienie hurtowe — a przy generowaniu PDF-a w tle kończy się to
 * przekroczeniem limitu czasu i ofertą, która „się nie wygenerowała".
 *
 * Tu pobieramy wszystko dwoma zapytaniami (produkty i warianty osobno, bo
 * WooCommerce trzyma warianty jako inny typ) i zwracamy mapę `id => produkt`.
 * Liczba zapytań przestaje zależeć od liczby pozycji.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mapa produktów po identyfikatorach.
 */
class MP_OB_Products {

	/**
	 * Statusy, które muszą wrócić z zapytania.
	 *
	 * Nie zawężamy do `publish`: Dział 2 SPRAWDZA status i odrzuca pozycję
	 * z produktem wycofanym. Gdyby zapytanie samo pomijało nieopublikowane,
	 * taki produkt wyglądałby na nieistniejący i komunikat dla handlowca
	 * mówiłby nieprawdę o powodzie odmowy.
	 *
	 * @var string[]
	 */
	const STATUSY = array( 'publish', 'private', 'draft', 'pending', 'future' );

	/**
	 * Pobiera produkty i warianty o podanych identyfikatorach.
	 *
	 * @param int[] $ids Identyfikatory produktów lub wariantów.
	 * @return array<int,WC_Product> Mapa `id => produkt`; brakujące pomijane.
	 */
	/**
	 * Czy pozycja jest zwolniona z VAT wg statusu podatkowego WooCommerce.
	 *
	 * WooCommerce zna trzy statusy: 'taxable', 'shipping' i 'none'. Opodatkowana
	 * jest wylacznie cena pozycji 'taxable'. 'none' to zwolnienie wprost,
	 * a 'shipping' („Tylko wysylka") znaczy, ze podatek dotyczy kosztu wysylki,
	 * nie ceny produktu — a oferta wysylki nie sprzedaje, wiec dla niej ta
	 * pozycja tez idzie z zerowa stawka.
	 *
	 * Predykat jest JEDEN, bo pytaja o niego dwa dzialy: Dzial 2 decyduje, dla
	 * ktorych klas pobrac stawki, a Dzial 6 — ktorym pozycjom naliczyc VAT. Gdy
	 * kazdy mial wlasny warunek, przestaly byc komplementarne: Dzial 2 pomijal
	 * wszystko rozne od 'taxable', Dzial 6 zwalnial tylko 'none', a pozycja
	 * 'shipping' wpadala w szczeline miedzy nimi.
	 *
	 * Brak statusu traktujemy jako opodatkowany — bezpieczniejsza strona bledu
	 * to naliczyc VAT i dostac odmowe z powodu braku stawki, niz po cichu
	 * wystawic dokument z zaniżonym podatkiem.
	 *
	 * @param array $product Pozycja ze snapshotu Dzialu 2.
	 * @return bool
	 */
	public static function zwolniona_z_vat( array $product ) {
		$status = isset( $product['tax_status'] ) ? (string) $product['tax_status'] : 'taxable';

		return 'none' === $status || 'shipping' === $status;
	}

	public static function map( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( empty( $ids ) || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$mapa = array();

		$zapytanie = array(
			'include' => $ids,
			'limit'   => -1,
			'status'  => self::STATUSY,
			'orderby' => 'none',
		);

		foreach ( (array) wc_get_products( $zapytanie ) as $produkt ) {
			if ( $produkt instanceof WC_Product ) {
				$mapa[ $produkt->get_id() ] = $produkt;
			}
		}

		// Warianty nie wracają ze zwykłego zapytania — WooCommerce traktuje je
		// jako osobny typ. Pytamy o nie tylko wtedy, gdy czegoś brakuje.
		$brakujace = array_values( array_diff( $ids, array_keys( $mapa ) ) );

		if ( ! empty( $brakujace ) ) {
			$zapytanie['include'] = $brakujace;
			$zapytanie['type']    = 'variation';

			foreach ( (array) wc_get_products( $zapytanie ) as $wariant ) {
				if ( $wariant instanceof WC_Product ) {
					$mapa[ $wariant->get_id() ] = $wariant;
				}
			}
		}

		return $mapa;
	}

	/**
	 * Identyfikator, po którym szukamy produktu dla pozycji oferty.
	 *
	 * Wariant ma pierwszeństwo przed produktem nadrzędnym — to on niesie cenę
	 * i to jego brak unieważnia pozycję.
	 *
	 * @param array $item Pozycja oferty.
	 * @return int
	 */
	public static function lookup_id( array $item ) {
		if ( ! empty( $item['variation_id'] ) ) {
			return (int) $item['variation_id'];
		}

		return isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
	}

	/**
	 * Mapa produktów dla listy pozycji oferty.
	 *
	 * @param array $items Pozycje oferty.
	 * @return array<int,WC_Product>
	 */
	/**
	 * Czy produkt jest naprawdę opublikowany — razem z rodzicem.
	 *
	 * WARIANT MA WŁASNY STATUS, NIEZALEŻNY OD PRODUKTU.
	 *
	 * Kontrola brzmiała `'publish' !== $product->get_status()` i dla pozycji
	 * wskazującej wariant pytała o status SAMEGO WARIANTU. WooCommerce przy
	 * wycofaniu produktu zmiennego z katalogu zmienia jednak status wyłącznie
	 * wpisu nadrzędnego — warianty są osobnymi wpisami i zostają w `publish`.
	 * Zmierzone wprost w `tests/naprawy/wariant-wycofanego-produktu.php`:
	 * po przełączeniu rodzica na szkic wariant nadal raportuje `publish`,
	 * a `get_parent_data()['status']` mówi `draft`.
	 *
	 * Skutkiem było przepuszczenie do oferty wariantu produktu, którego nie ma
	 * w opublikowanym katalogu — a opis agenta 2.1 deklaruje „filtruje po
	 * statusie publikacji".
	 *
	 * Status rodzica bierzemy z `get_parent_data()`, bo WooCommerce trzyma go
	 * tam przy odczycie wariantu — bez drugiego zapytania do bazy.
	 *
	 * @param WC_Product $product Produkt albo wariant.
	 * @return bool
	 */
	public static function opublikowany( $product ) {
		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() ) {
			return false;
		}

		if ( ! $product instanceof WC_Product_Variation ) {
			return true;
		}

		$rodzic = (array) $product->get_parent_data();
		$status = isset( $rodzic['status'] ) ? (string) $rodzic['status'] : '';

		/*
		 * Pusty status rodzica znaczy „nie wiadomo" — wariant osierocony albo
		 * dane niekompletne. Bezpieczniejszą stroną błędu jest tu odmowa:
		 * pozycja bez pewności co do katalogu nie ma czego szukać na dokumencie
		 * wychodzącym do klienta.
		 */
		return 'publish' === $status;
	}

	public static function for_items( array $items ) {
		$ids = array();

		foreach ( $items as $item ) {
			$ids[] = self::lookup_id( (array) $item );
		}

		return self::map( $ids );
	}
}
