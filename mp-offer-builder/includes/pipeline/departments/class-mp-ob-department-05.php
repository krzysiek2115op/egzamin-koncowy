<?php
/**
 * Dział 5 — Kalkulacja rabatów.
 *
 * CZYSTA FUNKCJA na kontekście (wariant, subtotal_grosze z Działu 4) — zero
 * wywołań WC_* albo wpdb. Zawartość pliku (1 plik = 1 dział):
 *  - Agent 5.1 (dobór)        — reguła wg wariantu i pasma wolumenu
 *  - Agent 5.2 (zastosowanie) — jedna metoda (total), limit łączny
 *  - QA Agent 5                 — odtwarzalność-rabatu
 *  - MP_OB_Department_05        — budowniczy działu
 *
 * Źródła — Golden Rule #2: brak zewnętrznego API/standardu (reguły biznesowe
 * projektu) — docs/dzial-05/reguly-rabatowe-konfiguracja.md (ŹRÓDŁO ORYGINALNE,
 * ta sama uwaga o zakresie "segment" opisana w tamtym pliku).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 5.1 — dobór reguły rabatowej wg wariantu i pasma wolumenu.
 */
class MP_OB_D5_Agent_Discount_Rules extends MP_OB_Abstract_Agent {

	/** Wersja słownika reguł (zapisywana przy ofercie — odtwarzalność-rabatu). */
	const RULES_VERSION = 'v1';

	/**
	 * Słownik reguł: catch-all (R-00) + progi wolumenu per wariant.
	 * Patrz docs/dzial-05/reguly-rabatowe-konfiguracja.md.
	 */
	const RULES = array(
		array(
			'rule_id' => 'R-00',
			'wariant' => null,
			'min_qty' => 0,
			'percent' => 0,
			'method'  => 'total',
		),
		array(
			'rule_id' => 'R-01',
			'wariant' => 'partner',
			'min_qty' => 1,
			'percent' => 5,
			'method'  => 'total',
		),
		array(
			'rule_id' => 'R-02',
			'wariant' => 'partner',
			'min_qty' => 50,
			'percent' => 10,
			'method'  => 'total',
		),
		array(
			'rule_id' => 'R-03',
			'wariant' => 'standard',
			'min_qty' => 1,
			'percent' => 0,
			'method'  => 'total',
		),
	);

	/**
	 * Obowiązujący słownik reguł: z ustawień, a gdy ich nie ma — wbudowany.
	 *
	 * `RULES` powyżej przestało być jedynym źródłem prawdy, a zostało DOMYŚLNYM.
	 * Progi rabatowe to decyzja handlowa; do 1.3.6 jej zmiana wymagała edycji tego
	 * pliku, wydania nowej wersji wtyczki i wgrania jej na produkcję. Ekran
	 * ustawień (MP_OB_Settings) zapisuje własny słownik do opcji.
	 *
	 * @return array
	 */
	public static function obowiazujace() {
		return class_exists( 'MP_OB_Settings' ) ? MP_OB_Settings::rules() : self::RULES;
	}

	/**
	 * Wersja obowiązującego słownika — trafia do oferty jako `rules_version`.
	 *
	 * Każdy zapis ustawień nadaje NOWĄ wersję. Bez tego dwie oferty z tym samym
	 * znacznikiem miałyby różne rabaty i znacznik przestałby cokolwiek znaczyć,
	 * a jest jedyną odpowiedzią na pytanie „dlaczego ta oferta ma taki rabat".
	 *
	 * @return string
	 */
	public static function wersja() {
		return class_exists( 'MP_OB_Settings' ) ? MP_OB_Settings::rules_version() : self::RULES_VERSION;
	}

	public function __construct() {
		parent::__construct( '5.1', 'Agent 5.1 — dobór', 'Reguły wg wariantu cenowego i pasma wolumenu; kolejność priorytetowa' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$wariant = (string) $context->get( 'wariant', '' );
		$items   = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();

		$total_qty = 0;
		foreach ( $items as $item ) {
			$total_qty += isset( $item['qty'] ) ? (int) $item['qty'] : 0;
		}

		// Kolejność priorytetowa: spośród reguł pasujących do wariantu (albo
		// catch-all R-00), wybieramy tę o NAJWYŻSZYM spełnionym progu ilości.
		$best = null;
		foreach ( self::obowiazujace() as $rule ) {
			$matches_wariant = null === $rule['wariant'] || $rule['wariant'] === $wariant;
			if ( ! $matches_wariant || $total_qty < $rule['min_qty'] ) {
				continue;
			}
			if ( null === $best || $rule['min_qty'] > $best['min_qty'] ) {
				$best = $rule;
			}
		}
		if ( null === $best ) {
			$obowiazujace = self::obowiazujace();
			$best         = $obowiazujace[0]; // R-00 — zawsze pasuje (min_qty=0, wariant=null).
		}

		$subtotal_grosze = (int) $context->get( 'subtotal_grosze', 0 );
		// Arytmetyka wyłącznie na int: (subtotal * percent) / 100, dzielenie całkowite
		// (intdiv) zamiast float — ta sama zasada zero-float co w Dziale 4.
		$amount_grosze = 0 === $best['percent'] ? 0 : intdiv( $subtotal_grosze * $best['percent'], 100 );

		return MP_OB_Result::ok(
			array(
				'discounts'      => array(
					array(
						'rule_id'       => $best['rule_id'],
						'amount_grosze' => $amount_grosze,
					),
				),
				'discount_total' => $amount_grosze,
				'rules_version'  => self::wersja(),
			)
		);
	}
}

/**
 * Agent 5.2 — zastosowanie: jedna metoda (total), górny limit łączny.
 */
class MP_OB_D5_Agent_Apply_Discount extends MP_OB_Abstract_Agent {

	/** Górny limit łącznego rabatu — % subtotal_grosze. */
	const LIMIT_PERCENT = 30;

	public function __construct() {
		parent::__construct( '5.2', 'Agent 5.2 — zastosowanie', 'Rabat na sumie (jedna metoda), górny limit łączny' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$subtotal_grosze = (int) $context->get( 'subtotal_grosze', 0 );
		$discount_total  = (int) $context->get( 'discount_total', 0 );
		$limit_grosze    = intdiv( $subtotal_grosze * self::LIMIT_PERCENT, 100 );

		if ( $discount_total > $limit_grosze ) {
			// "flaga do akceptacji, nie ciche przycięcie" — STOP jawny, nie cichy
			// clip do limitu; wymaga jawnej akceptacji poza tym pipeline'em (Krok 4).
			return MP_OB_Result::fail(
				sprintf( 'Rabat %d gr przekracza limit %d gr (%d%% sumy).', $discount_total, $limit_grosze, self::LIMIT_PERCENT ),
				array(
					'discount_total' => $discount_total,
					'limit_grosze'   => $limit_grosze,
				),
				'discount_over_limit'
			);
		}

		return MP_OB_Result::ok( array( 'discount_within_limit' => true ) );
	}
}

/**
 * QA Agent 5 — odtwarzalność-rabatu (ten sam koszyk + ta sama wersja reguł = ten sam wynik).
 */
class MP_OB_D5_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA5', 'QA Agent 5 — kontrola kompletności', 'Sprawdza odtwarzalność-rabatu' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$required = array( 'discounts', 'discount_total', 'rules_version' );
		$missing  = array();
		foreach ( $required as $key ) {
			if ( '' === $context->get( $key, '' ) && ! is_array( $context->get( $key ) ) ) {
				$missing[] = $key;
			}
		}

		if ( $missing ) {
			return MP_OB_Result::fail( 'Niekompletny wynik Działu 5: ' . implode( ', ', $missing ), array( 'missing' => $missing ), 'incomplete_discount' );
		}

		return MP_OB_Result::ok( array( 'discount_complete' => true ) );
	}
}

/**
 * Budowniczy działu 5.
 */
class MP_OB_Department_05 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_D5_Agent_Discount_Rules(),
				'critic' => new MP_OB_Array_Critic( 'K5.1', 'Krytyk 5.1 — cytat-reguły', 'discounts' ),
			),
			array(
				'agent'  => new MP_OB_D5_Agent_Apply_Discount(),
				'critic' => new MP_OB_Flag_Critic( 'K5.2', 'Krytyk 5.2 — limit-rabatu', 'discount_within_limit' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D5_QA_Agent(),
			new MP_OB_Accept_Critic( 'QAK5', 'QA Krytyk 5 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			5,
			'discounts',
			'Kalkulacja rabatów',
			'Dobór reguł rabatowych (wariant × wolumen) i limit łącznego rabatu.',
			$pairs,
			$gate
		);
	}
}
