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
	public static function for_items( array $items ) {
		$ids = array();

		foreach ( $items as $item ) {
			$ids[] = self::lookup_id( (array) $item );
		}

		return self::map( $ids );
	}
}
