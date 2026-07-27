<?php
/**
 * Dział 4 — Ceny bazowe.
 *
 * CZYSTA FUNKCJA na snapshocie z Działu 2 (prices[]) — zero wywołań WC_* albo
 * wpdb. Zawartość pliku (1 plik = 1 dział):
 *  - Agent 4.1 (jednostkowe) — cena jednostkowa w groszach, bez float
 *  - Agent 4.2 (pozycje)     — wartość pozycji = cena × ilość, suma kontrolna
 *  - QA Agent 4               — kontrola zero-float (arytmetyka całkowitoliczbowa)
 *  - MP_OB_Department_04      — budowniczy działu
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - docs/dzial-04/php-bcmath.md
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 4.1 — cena jednostkowa: sale_price jeśli aktywna, inaczej regular_price,
 * przeliczona na grosze przez BCMath (zero arytmetyki zmiennoprzecinkowej).
 */
class MP_OB_D4_Agent_Unit_Price extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '4.1', 'Agent 4.1 — jednostkowe', 'Cena jednostkowa: sale_price jeśli aktywna, inaczej regular_price; przeliczenie na grosze' );
	}

	/**
	 * Przelicza cenę (string, np. "129.99") na grosze BEZ arytmetyki float —
	 * patrz docs/dzial-04/php-bcmath.md.
	 *
	 * @param string $price Cena jako string (WC_Product zwraca string).
	 * @return int
	 */
	public static function to_grosze( $price ) {
		if ( function_exists( 'bcmul' ) && function_exists( 'bcadd' ) ) {
			// S4-01: zaokrąglenie połówkowe w górę (jak fallback round()), nie obcięcie —
			// scale 0 na bcmul obcinał sub-groszową część (rozjazd między środowiskami
			// przy cenach >2 miejsc po przecinku).
			return (int) bcadd( bcmul( (string) $price, '100', 2 ), '0.5', 0 );
		}
		// Fallback bez rozszerzenia BCMath: round() (nie (int) cast) chroni przed
		// obcięciem przez błąd reprezentacji float (patrz uzasadnienie w docs/dzial-04).
		return (int) round( ( (float) $price ) * 100 );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$items  = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();
		$prices = is_array( $context->get( 'prices' ) ) ? $context->get( 'prices' ) : array();
		$lines  = array();

		foreach ( $items as $i => $item ) {
			$price_row    = isset( $prices[ $i ] ) ? $prices[ $i ] : array();
			$on_sale      = ! empty( $price_row['on_sale'] );
			$price_source = $on_sale ? 'sale' : 'regular';
			$price_value  = $on_sale ? $price_row['sale_price'] : ( isset( $price_row['regular_price'] ) ? $price_row['regular_price'] : 0 );

			$lines[ $i ] = array(
				'unit_grosze'  => self::to_grosze( $price_value ),
				'price_source' => $price_source,
			);
		}

		return MP_OB_Result::ok( array( 'lines' => $lines ) );
	}
}

/**
 * Agent 4.2 — wartość pozycji = cena × ilość, w groszach, bez zaokrągleń
 * pośrednich (mnożenie int × int — bezpieczne, bez BCMath).
 */
class MP_OB_D4_Agent_Line_Value extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '4.2', 'Agent 4.2 — pozycje', 'Wartość pozycji = cena × ilość, w groszach, bez zaokrągleń pośrednich' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$items = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();
		$lines = is_array( $context->get( 'lines' ) ) ? $context->get( 'lines' ) : array();

		$subtotal = 0;
		foreach ( $items as $i => $item ) {
			$qty                        = isset( $item['qty'] ) ? (int) $item['qty'] : 0;
			$unit_grosze                = isset( $lines[ $i ]['unit_grosze'] ) ? (int) $lines[ $i ]['unit_grosze'] : 0;
			$line_grosze                = $unit_grosze * $qty;
			$lines[ $i ]['line_grosze'] = $line_grosze;
			$subtotal                  += $line_grosze;
		}

		return MP_OB_Result::ok(
			array(
				'lines'           => $lines,
				'subtotal_grosze' => $subtotal,
			)
		);
	}
}

/**
 * QA Agent 4 — zero-float: arytmetyka całkowitoliczbowa (suma pozycji = suma kontrolna).
 */
class MP_OB_D4_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA4', 'QA Agent 4 — kontrola kompletności', 'Sprawdza zero-float: arytmetyka całkowitoliczbowa' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$lines    = is_array( $context->get( 'lines' ) ) ? $context->get( 'lines' ) : array();
		$subtotal = (int) $context->get( 'subtotal_grosze', -1 );

		if ( $subtotal < 0 ) {
			return MP_OB_Result::fail( 'Brak sumy kontrolnej pozycji.', array(), 'missing_subtotal' );
		}

		// Suma kontrolna: suma line_grosze musi się zgadzać z subtotal_grosze —
		// jeśli nie, gdzieś wkradła się arytmetyka spoza int (regresja do float).
		$check_sum = 0;
		foreach ( $lines as $line ) {
			if ( ! isset( $line['unit_grosze'], $line['line_grosze'] ) || ! is_int( $line['unit_grosze'] ) || ! is_int( $line['line_grosze'] ) ) {
				return MP_OB_Result::fail( 'Wykryto wartość spoza int w wycenie pozycji (regresja float).', array(), 'non_integer_arithmetic' );
			}
			$check_sum += $line['line_grosze'];
		}

		if ( $check_sum !== $subtotal ) {
			return MP_OB_Result::fail( 'Suma pozycji niezgodna z sumą kontrolną.', array(), 'subtotal_mismatch' );
		}

		return MP_OB_Result::ok( array( 'base_prices_ok' => true ) );
	}
}

/**
 * Budowniczy działu 4.
 */
class MP_OB_Department_04 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_D4_Agent_Unit_Price(),
				'critic' => new MP_OB_Parity_Critic( 'K4.1', 'Krytyk 4.1 — wybór-ceny', 'lines' ),
			),
			array(
				'agent'  => new MP_OB_D4_Agent_Line_Value(),
				'critic' => new MP_OB_Parity_Critic( 'K4.2', 'Krytyk 4.2 — suma-pozycji', 'lines' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D4_QA_Agent(),
			new MP_OB_Accept_Critic( 'QAK4', 'QA Krytyk 4 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			4,
			'base-prices',
			'Ceny bazowe',
			'Wybór ceny jednostkowej (promocyjna/katalogowa) i suma pozycji w groszach.',
			$pairs,
			$gate
		);
	}
}
