<?php
/**
 * Dział 6 — Podatki i suma końcowa.
 *
 * CZYSTA FUNKCJA na kontekście (client.country/vat_status z Działu 1, tax_rates
 * ze snapshotu Działu 2, subtotal_grosze/discount_total z Działów 4/5) — zero
 * wywołań WC_* albo wpdb. Zawartość pliku (1 plik = 1 dział):
 *  - Agent 6.1 (mechanizm)    — PL / UE odwrotne obciążenie / poza UE
 *  - Agent 6.2 (zaokrąglenia) — netto/VAT/brutto w groszach, zaokrąglenie raz
 *  - QA Agent 6                 — spójność-sumy (netto + VAT = brutto)
 *  - MP_OB_Department_06        — budowniczy działu
 *
 * BEZPIECZNY DOMYŚLNY WYBÓR: odwrotne obciążenie wymaga POTWIERDZONEGO ważnego
 * numeru VAT (client.vat_status === 'valid') — brak potwierdzenia = naliczana
 * krajowa stawka (mechanizm domestic), NIGDY ciche zerowanie VAT bez podstawy
 * prawnej. Patrz docs/dzial-06/dyrektywa-2006-112-we-art-196.md.
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - docs/dzial-06/dyrektywa-2006-112-we-art-196.md (odwrotne obciążenie)
 *  - docs/dzial-02/woocommerce-wc_tax.md (WC_Tax — stawka krajowa, już cytowane w Dziale 2)
 *  - docs/dzial-04/php-bcmath.md (zaokrąglenie bez float — ta sama technika co Dział 4)
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 6.1 — wybór mechanizmu VAT wg kraju i statusu VAT klienta.
 */
class MP_OB_D6_Agent_Mechanism extends MP_OB_Abstract_Agent {

	/** Kraje UE (ISO 3166-1 alpha-2) — wzorem mp-lead-intake/docs/dzial-04/iso-3166-1-kody-krajow.md. */
	const EU_COUNTRIES = array(
		'AT',
		'BE',
		'BG',
		'HR',
		'CY',
		'CZ',
		'DK',
		'EE',
		'FI',
		'FR',
		'DE',
		'GR',
		'HU',
		'IE',
		'IT',
		'LV',
		'LT',
		'LU',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SK',
		'SI',
		'ES',
		'SE',
	);

	public function __construct() {
		parent::__construct( '6.1', 'Agent 6.1 — mechanizm', 'PL — stawka krajowa; UE z ważnym VAT — odwrotne obciążenie 0%; poza UE — poza zakresem VAT' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$client     = is_array( $context->get( 'client' ) ) ? $context->get( 'client' ) : array();
		$country    = strtoupper( trim( (string) ( isset( $client['country'] ) ? $client['country'] : '' ) ) );
		$vat_status = isset( $client['vat_status'] ) ? (string) $client['vat_status'] : '';

		// Format kodu kraju (Dział 1 sprawdza tylko "niepuste", nie kształt) —
		// bez tej kontroli błędny/zniekształcony kod ("PLN", "12", spacje) trafiał
		// do gałęzi "poza UE" i dostawał CICHE 0% VAT z fałszywą podstawą prawną
		// ("kraj spoza UE"), zamiast jawnego błędu. ISO 3166-1 alpha-2 = zawsze
		// dokładnie 2 litery.
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return MP_OB_Result::fail(
				'Kod kraju klienta jest nieprawidłowy — wymagany dwuliterowy kod ISO 3166-1 alpha-2.',
				array(),
				'invalid_country'
			);
		}

		if ( 'PL' === $country ) {
			$mechanism = 'domestic';
			$basis     = 'Polska — stawka krajowa.';
		} elseif ( in_array( $country, self::EU_COUNTRIES, true ) ) {
			if ( 'valid' === $vat_status ) {
				$mechanism = 'reverse_charge';
				$basis     = 'Dyrektywa 2006/112/WE art. 196 — odwrotne obciążenie, ważny VAT UE.';
			} else {
				// Brak potwierdzenia ważnego VAT = bezpieczny domyślny wybór:
				// stawka krajowa, NIE ciche 0% (patrz docblock pliku).
				$mechanism = 'domestic';
				$basis     = 'UE bez potwierdzonego ważnego VAT — naliczona stawka krajowa (bezpieczny wariant domyślny).';
			}
		} else {
			$mechanism = 'out_of_scope';
			$basis     = 'Kraj spoza UE — poza zakresem dyrektywy VAT.';
		}

		return MP_OB_Result::ok(
			array(
				'tax_mechanism' => $mechanism,
				'tax_basis'     => $basis,
			)
		);
	}
}

/**
 * Agent 6.2 — netto/VAT/brutto w groszach, zaokrąglenie RAZ (metoda półówkowa),
 * wyłącznie arytmetyką całkowitoliczbową (BCMath — patrz docs/dzial-04).
 */
class MP_OB_D6_Agent_Rounding extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '6.2', 'Agent 6.2 — zaokrąglenia', 'Netto, VAT i brutto w groszach; zaokrąglenie raz — na sumie podatku, metodą półówkową' );
	}

	/**
	 * Zaokrągla net_grosze*rate/100 do najbliższego grosza (metoda półówkowa,
	 * "round half up"), wyłącznie arytmetyką na stringach (BCMath) — bez
	 * przejścia przez float. Patrz docs/dzial-04/php-bcmath.md.
	 *
	 * @param int       $net_grosze Kwota netto w groszach.
	 * @param int|float $rate       Stawka VAT w procentach (np. 23).
	 * @return int
	 */
	public static function vat_grosze( $net_grosze, $rate ) {
		if ( function_exists( 'bcmul' ) && function_exists( 'bcadd' ) && function_exists( 'bcdiv' ) ) {
			$raw = bcdiv( bcmul( (string) $net_grosze, (string) $rate, 10 ), '100', 10 );
			// +0.5 i obcięcie do scale=0 (bcadd) = zaokrąglenie w górę od połowy,
			// wyłącznie na stringach — nigdy nie przechodzi przez float.
			return (int) bcadd( $raw, '0.5', 0 );
		}
		// Fallback bez BCMath: round() z jawnym trybem połówkowym w górę.
		return (int) round( ( $net_grosze * $rate ) / 100, 0, PHP_ROUND_HALF_UP );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$net_grosze = (int) $context->get( 'subtotal_grosze', 0 ) - (int) $context->get( 'discount_total', 0 );
		$mechanism  = (string) $context->get( 'tax_mechanism', '' );

		if ( 'domestic' === $mechanism ) {
			$tax_rates = is_array( $context->get( 'tax_rates' ) ) ? $context->get( 'tax_rates' ) : array();
			$first     = reset( $tax_rates );
			$rate      = $first && isset( $first['rate'] ) ? (float) $first['rate'] : null;
			if ( null === $rate ) {
				return MP_OB_Result::fail( 'Brak stawki VAT do naliczenia (mechanizm domestic).', array(), 'missing_tax_rate' );
			}
		} else {
			// reverse_charge / out_of_scope — zawsze 0%, z RÓŻNYCH podstaw prawnych
			// (patrz tax_basis z Agenta 6.1), ale ten sam wynik liczbowy.
			$rate = 0.0;
		}

		$vat_grosze   = 0.0 === $rate ? 0 : self::vat_grosze( $net_grosze, $rate );
		$gross_grosze = $net_grosze + $vat_grosze;

		return MP_OB_Result::ok(
			array(
				'tax_rate'     => $rate,
				'net_grosze'   => $net_grosze,
				'vat_grosze'   => $vat_grosze,
				'gross_grosze' => $gross_grosze,
			)
		);
	}
}

/**
 * QA Agent 6 — spójność-sumy: netto + VAT = brutto, zawsze, w groszach.
 */
class MP_OB_D6_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA6', 'QA Agent 6 — kontrola kompletności', 'Sprawdza spójność-sumy: netto + VAT = brutto' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$net   = (int) $context->get( 'net_grosze', 0 );
		$vat   = (int) $context->get( 'vat_grosze', 0 );
		$gross = (int) $context->get( 'gross_grosze', -1 );

		if ( $net + $vat !== $gross ) {
			return MP_OB_Result::fail(
				'Suma netto+VAT nie zgadza się z brutto.',
				array(
					'net'   => $net,
					'vat'   => $vat,
					'gross' => $gross,
				),
				'totals_mismatch'
			);
		}

		return MP_OB_Result::ok( array( 'totals_consistent' => true ) );
	}
}

/**
 * Budowniczy działu 6.
 */
class MP_OB_Department_06 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_D6_Agent_Mechanism(),
				'critic' => new MP_OB_Field_Critic( 'K6.1', 'Krytyk 6.1 — wybór-mechanizmu', 'tax_mechanism' ),
			),
			array(
				'agent'  => new MP_OB_D6_Agent_Rounding(),
				'critic' => new MP_OB_Field_Critic( 'K6.2', 'Krytyk 6.2 — jeden-punkt-zaokrąglenia', 'gross_grosze' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D6_QA_Agent(),
			new MP_OB_Accept_Critic( 'QAK6', 'QA Krytyk 6 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			6,
			'tax-totals',
			'Podatki i suma końcowa',
			'Wybór mechanizmu VAT (PL / UE reverse charge / poza UE) i jedno zaokrąglenie na sumie.',
			$pairs,
			$gate
		);
	}
}
