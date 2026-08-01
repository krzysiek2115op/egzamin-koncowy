<?php
/**
 * Dział 3 — Walidacja pozycji oferty.
 *
 * CZYSTA FUNKCJA na zamrożonym snapshocie z Działu 2 — zero wywołań WC_* albo wpdb
 * (zasada "jeden odczyt", patrz docblock class-mp-ob-department-02.php).
 *
 * Zawartość pliku (1 plik = 1 dział):
 *  - Agent 3.1 (istnienie) — pozycja ma odpowiednik w snapshocie (produkty[i])
 *  - Agent 3.2 (ilości)    — ilości w dozwolonym zakresie, limit koszyka
 *  - QA Agent 3             — policzalność całego koszyka
 *  - MP_OB_Department_03    — budowniczy działu
 *
 * "nigdy pierwszy pasujący" (kryt. dopasowanie-pozycji) jest spełnione PRZEZ
 * KONSTRUKCJĘ: Dział 2 indeksuje snapshot dokładnie po indeksie pozycji z
 * żądania (products[i]), nie przez wyszukiwanie/dopasowywanie — nie ma więc
 * możliwości podstawienia "pierwszego pasującego" produktu.
 *
 * Źródła — Golden Rule #2: brak zewnętrznego źródła technicznego (walidacja to
 * reguły biznesowe wg zlecenia klienta, nie API/standard strony trzeciej) —
 * patrz docs/dzial-03/limity-koszyka.md (ŹRÓDŁO ORYGINALNE, wzorem
 * mp-lead-intake/docs/dzial-04/iso-3166-1-kody-krajow.md).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 3.1 — pozycja ma odpowiednik w snapshocie: produkt, wariant, publish.
 */
class MP_OB_D3_Agent_Existence extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '3.1', 'Agent 3.1 — istnienie', 'Każda pozycja żądania ma odpowiednik w snapshocie: produkt, konkretny wariant, status opublikowany' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$items    = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();
		$products = is_array( $context->get( 'products' ) ) ? $context->get( 'products' ) : array();
		$errors   = array();

		foreach ( $items as $i => $item ) {
			if ( ! isset( $products[ $i ] ) || empty( $products[ $i ]['purchasable'] ) ) {
				$errors[] = array(
					'field'   => "items.$i",
					'message' => 'Pozycja nie ma odpowiednika w snapshocie albo produkt nie jest możliwy do sprzedaży.',
				);
			}
		}

		if ( $errors ) {
			return MP_OB_Result::fail( 'Pozycje bez pokrycia w snapshocie.', array( 'errors' => $errors ), 'items_not_matched' );
		}

		return MP_OB_Result::ok( array( 'items_matched' => true ) );
	}
}

/**
 * Agent 3.2 — ilości w dozwolonym zakresie, limit pozycji na ofertę.
 */
class MP_OB_D3_Agent_Quantities extends MP_OB_Abstract_Agent {

	/** Minimalna/maksymalna ilość sztuk na pozycję. */
	const MIN_QTY = 1;
	const MAX_QTY = 10000;

	/** Maksymalna liczba pozycji na ofertę. */
	const MAX_ITEMS = 50;

	public function __construct() {
		parent::__construct( '3.2', 'Agent 3.2 — ilości', 'Ilości całkowite 1–10 000; maksymalnie 50 pozycji na ofertę' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$items  = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();
		$errors = array();

		if ( count( $items ) > self::MAX_ITEMS ) {
			$errors[] = array(
				'field'   => 'items',
				'message' => sprintf( 'Zbyt wiele pozycji (%d) — limit to %d.', count( $items ), self::MAX_ITEMS ),
			);
		}

		foreach ( $items as $i => $item ) {
			$qty = isset( $item['qty'] ) ? $item['qty'] : null;
			// Ułamek/string niecałkowity = błąd wejścia (kryt. "zakres-ilości") —
			// porównanie z (int)$qty wykrywa np. "5.5" albo 5.5, nie tylko ujemne/zero.
			if ( ! is_numeric( $qty ) || (float) (int) $qty !== (float) $qty
				|| self::MIN_QTY > (int) $qty || (int) $qty > self::MAX_QTY ) {
				$errors[] = array(
					'field'   => "items.$i.qty",
					'message' => sprintf( 'Ilość musi być liczbą całkowitą w zakresie %d–%d.', self::MIN_QTY, self::MAX_QTY ),
				);
			}
		}

		if ( $errors ) {
			return MP_OB_Result::fail( 'Nieprawidłowe ilości.', array( 'errors' => $errors ), 'invalid_quantities' );
		}

		// items_valid (kontrakt JSON diagramu) MUSI pochodzić z agenta, nie z QA
		// gate — MP_OB_Department::process() nie scala danych bramki z kontekstem
		// (patrz analogiczna uwaga przy db_reads w Dziale 2). Ten agent jest
		// ostatnią parą przed bramką, więc dotarcie tu = istnienie już potwierdzone.
		return MP_OB_Result::ok(
			array(
				'quantities_ok' => true,
				'items_valid'   => true,
			)
		);
	}
}

/**
 * QA Agent 3 — policzalność całego koszyka (jeden brak unieważnia kalkulację).
 */
class MP_OB_D3_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA3', 'QA Agent 3 — kontrola kompletności', 'Sprawdza policzalność całego koszyka' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		if ( empty( $context->get( 'items_matched' ) ) || empty( $context->get( 'quantities_ok' ) ) ) {
			return MP_OB_Result::fail( 'Koszyk nie jest policzalny — brak jednej z kontroli Działu 3.', array(), 'cart_not_computable' );
		}

		return MP_OB_Result::ok( array( 'items_valid' => true ) );
	}
}

/**
 * Budowniczy działu 3.
 */
class MP_OB_Department_03 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_D3_Agent_Existence(),
				'critic' => new MP_OB_Flag_Critic( 'K3.1', 'Krytyk 3.1 — dopasowanie-pozycji', 'items_matched' ),
			),
			array(
				'agent'  => new MP_OB_D3_Agent_Quantities(),
				'critic' => new MP_OB_Flag_Critic( 'K3.2', 'Krytyk 3.2 — zakres-ilości', 'quantities_ok' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D3_QA_Agent(),
			new MP_OB_Accept_Critic( 'QAK3', 'QA Krytyk 3 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			3,
			'validate-items',
			'Walidacja pozycji oferty',
			'Dopasowanie pozycji żądania do snapshotu i kontrola ilości/limitów koszyka.',
			$pairs,
			$gate
		);
	}
}
