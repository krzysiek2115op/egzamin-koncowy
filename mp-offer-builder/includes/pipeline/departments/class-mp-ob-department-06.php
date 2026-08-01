<?php
/**
 * Dział 6 — Podatki i suma końcowa.
 *
 * CZYSTA FUNKCJA na kontekście (client.country/vat_status z Działu 1, tax_rates
 * ze snapshotu Działu 2, subtotal_grosze/discount_total z Działów 4/5) — zero
 * wywołań WC_* albo wpdb. Zawartość pliku (1 plik = 1 dział):
 *  - Agent 6.1 (mechanizm)    — PL / UE odwrotne obciążenie / poza UE
 *  - Agent 6.2 (zaokrąglenia) — netto/VAT/brutto w groszach, zaokrąglenie per klasa podatkowa
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
 *  - docs/dzial-02/woocommerce-wc_get_products.md (WC_Tax — stawka krajowa, już cytowane w Dziale 2)
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

	/**
	 * Kody krajów przypisane w ISO 3166-1 alpha-2.
	 *
	 * Źródło: https://www.iso.org/iso-3166-country-codes.html (pobrano 2026-07-21),
	 * ta sama norma, którą cytuje mp-lead-intake/docs/dzial-04/iso-3166-1-kody-krajow.md.
	 *
	 * Lista jest WBUDOWANA, a nie brana z WooCommerce, i to jest sedno naprawy.
	 * Poprzednio kontrola „nieznanego kodu kraju" wykonywała się wyłącznie wtedy,
	 * gdy dostępne było `WC()->countries` — a to zupełnie inny warunek niż ten,
	 * który gwarantuje Dział 2 (`class_exists( 'WC_Tax' )`), bo `WC()->countries`
	 * zapełnia się dopiero na haku `init`. Zabezpieczenie przed cichym 0% VAT
	 * nie może zależeć od tego, jak daleko zaszedł bootstrap sklepu.
	 *
	 * Lista WooCommerce nie nadaje się też na wzorzec z drugiego powodu: sklep
	 * może ją zawęzić filtrem `woocommerce_countries`, więc brak kodu na tej
	 * liście nie znaczy „taki kraj nie istnieje", tylko „ten sklep tam nie sprzedaje".
	 *
	 * @return string[]
	 */
	public static function iso_countries() {
		static $kody = null;

		if ( null === $kody ) {
			$kody = explode(
				' ',
				'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN '
				. 'BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ '
				. 'DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL '
				. 'GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM '
				. 'JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME '
				. 'MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP '
				. 'NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD '
				. 'SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO '
				. 'TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'
			);
		}

		return $kody;
	}

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

		// S4-02: format to za mało — literówka/nieprzypisany kod ("DR" zamiast
		// "DE") ma poprawny kształt, ale trafiał do gałęzi "poza UE" i dostawał
		// CICHE 0% VAT. Sprawdzenie idzie po WBUDOWANEJ liście ISO (patrz
		// iso_countries()) i wykonuje się ZAWSZE — wcześniej siedziało pod
		// warunkiem dostępności WC()->countries, więc akurat tam, gdzie sklep
		// nie był jeszcze w pełni zainicjalizowany, zabezpieczenia nie było.
		if ( ! in_array( $country, self::iso_countries(), true ) ) {
			return MP_OB_Result::fail(
				'Nieznany kod kraju klienta — nie występuje w normie ISO 3166-1 alpha-2.',
				array(),
				'unknown_country'
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
 * Agent 6.2 — netto/VAT/brutto w groszach, zaokrąglenie metodą półówkową per klasa podatkowa,
 * wyłącznie arytmetyką całkowitoliczbową (BCMath — patrz docs/dzial-04).
 */
class MP_OB_D6_Agent_Rounding extends MP_OB_Abstract_Agent {

	/** Syntetyczna "klasa" dla pozycji zwolnionych z VAT (tax_status='none') — zawsze 0%. */
	const TAX_NONE = '__mp_ob_none__';

	public function __construct() {
		parent::__construct( '6.2', 'Agent 6.2 — zaokrąglenia', 'Netto, VAT i brutto w groszach; zaokrąglenie metodą półówkową raz na każdą klasę podatkową' );
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
	 * Proporcjonalny podział kwoty (grosze) — floor( amount * part / total ),
	 * wyłącznie arytmetyką całkowitą (BCMath, fallback bez float na 64-bit).
	 *
	 * @param int $amount Kwota do rozdzielenia (grosze).
	 * @param int $part   Udział tej części.
	 * @param int $total  Suma udziałów.
	 * @return int
	 */
	private static function prorate( $amount, $part, $total ) {
		if ( $total <= 0 ) {
			return 0;
		}
		if ( function_exists( 'bcmul' ) && function_exists( 'bcdiv' ) ) {
			return (int) bcdiv( bcmul( (string) $amount, (string) $part, 0 ), (string) $total, 0 );
		}
		return (int) floor( ( $amount * $part ) / $total );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$net_grosze     = (int) $context->get( 'subtotal_grosze', 0 ) - (int) $context->get( 'discount_total', 0 );
		$mechanism      = (string) $context->get( 'tax_mechanism', '' );
		$line_tax_rates = array();

		if ( 'domestic' === $mechanism ) {
			$tax_rates = is_array( $context->get( 'tax_rates' ) ) ? $context->get( 'tax_rates' ) : array();
			$products  = is_array( $context->get( 'products' ) ) ? $context->get( 'products' ) : array();
			$lines     = is_array( $context->get( 'lines' ) ) ? $context->get( 'lines' ) : array();
			$subtotal  = (int) $context->get( 'subtotal_grosze', 0 );
			$discount  = (int) $context->get( 'discount_total', 0 );

			// VAT liczony PER KLASA PODATKOWA, nie jedną stawką na całość: koszyk
			// B2B może mieszać stawki (np. 23% + 8%), a jedna stawka na sumie
			// zawyżała/zaniżała podatek. Rabat jest naliczany NA SUMIE (Dział 5),
			// więc rozdzielamy go proporcjonalnie na klasy, żeby suma netto klas
			// równała się dokładnie net_grosze. Dla POJEDYNCZEJ klasy wynik jest
			// identyczny jak wcześniej (jedna stawka na całe netto).
			$class_subtotal = array();
			foreach ( $lines as $i => $line ) {
				// S3-02: produkt ze statusem 'none' (zwolniony z VAT) trafia do
				// odrębnej klasy 0% — NIE dostaje stawki wg tax_class.
				// Pozycja bez odpowiednika w snapshocie to nie jest pozycja „bez klasy" —
				// to znak, że Dział 2 i Dział 4 rozjechały się co do zbioru pozycji.
				// Bez tego guardu PHP oddawał ostrzeżenie o nieistniejącym kluczu, a klasa
				// podatkowa wychodziła '' — czyli pozycja wpadała do stawki podstawowej.
				// Następna linia guard już miała; ta go nie miała, choć czyta to samo.
				if ( ! isset( $products[ $i ] ) ) {
					return MP_OB_Result::fail(
						'Pozycja bez odpowiednika w snapshocie produktów — nie zgadujemy jej klasy podatkowej.',
						array( 'line_index' => $i ),
						'line_without_product'
					);
				}

				$is_exempt = MP_OB_Products::zwolniona_z_vat( (array) $products[ $i ] );
				$tc        = $is_exempt ? self::TAX_NONE : ( isset( $products[ $i ]['tax_class'] ) ? (string) $products[ $i ]['tax_class'] : '' );
				$lg        = isset( $line['line_grosze'] ) ? (int) $line['line_grosze'] : 0;
				if ( ! isset( $class_subtotal[ $tc ] ) ) {
					$class_subtotal[ $tc ] = 0;
				}
				$class_subtotal[ $tc ] += $lg;
			}

			/*
			 * DWIE PODSTAWY MUSZĄ BYĆ TĄ SAMĄ PODSTAWĄ.
			 *
			 * `net_grosze` powstaje z `subtotal_grosze - discount_total`, a VAT —
			 * z sumy `lines[*]['line_grosze']`. Nikt tych dwóch liczb ze sobą nie
			 * porównywał, a rozjazd nie daje żadnego błędu, tylko INNY PODATEK:
			 * przy pustych `lines` i niezerowym `subtotal` wychodziło netto 1000 zł
			 * z VAT-em 0 zł, a przy `lines` mniejszych od `subtotal` — VAT policzony
			 * od ułamka kwoty (netto 1000 zł, VAT 23 zł, czyli 2,3% w mechanizmie
			 * krajowym). Bramka QA6 tego nie widzi, bo sprawdza wyłącznie
			 * `netto + VAT = brutto`, a ta równość jest wtedy spełniona.
			 *
			 * Krytyk Działu 4 pilnuje tej samej sumy kontrolnej i w pełnym przebiegu
			 * zatrzymałby rozjazd wcześniej. Dział 6 nie może jednak opierać
			 * poprawności PODATKU na kontroli z innego działu — to tutaj powstaje
			 * liczba, która trafia na dokument handlowy.
			 */
			if ( array_sum( $class_subtotal ) !== $subtotal ) {
				return MP_OB_Result::fail(
					'Podstawa VAT niezgodna z sumą pozycji — nie liczymy podatku od innej kwoty niż netto.',
					array(
						'subtotal_grosze' => $subtotal,
						'lines_grosze'    => array_sum( $class_subtotal ),
					),
					'tax_base_mismatch'
				);
			}

			// Każda występująca klasa MUSI mieć stawkę (Dział 2 ją zebrał; brak = FAIL,
			// nigdy domyślne 23% — ta sama zasada co Agent 2.3).
			foreach ( array_keys( $class_subtotal ) as $tc ) {
				if ( self::TAX_NONE === $tc ) {
					continue; // klasa zwolniona (0%) nie wymaga skonfigurowanej stawki.
				}

				/*
				 * `is_numeric()`, a nie samo `isset()` plus kontrola `null`. Wpis
				 * `array( 'rate' => '' )` — albo `false`, albo tekst — przechodził
				 * poprzedni warunek: `isset()` jest wtedy prawdziwe, a wartość nie
				 * jest `null`. Niżej `(float) ''` daje 0.0 i warunek `0.0 === $rate`
				 * zerował VAT dla całej klasy. Wychodził dokument handlowy z cichym
				 * 0% w mechanizmie krajowym — bez błędu i bez śladu w dzienniku.
				 *
				 * „Brak stawki" i „stawka równa zero" to dwie różne rzeczy. Zero
				 * jest legalną stawką (eksport, klasa „Zero rate"), ale musi być
				 * podane WPROST jako liczba; pusta wartość znaczy, że stawki nie ma
				 * — i wtedy oferty nie wolno policzyć.
				 */
				if ( ! isset( $tax_rates[ $tc ]['rate'] ) || ! is_numeric( $tax_rates[ $tc ]['rate'] ) ) {
					return MP_OB_Result::fail( 'Brak stawki VAT do naliczenia (mechanizm domestic).', array(), 'missing_tax_rate' );
				}
			}

			// Podział rabatu na klasy proporcjonalnie do ich wartości; resztę z
			// zaokrągleń w dół dorzucamy do klasy o największej wartości, żeby suma
			// rabatów klas == discount_total co do grosza (a więc suma netto == net_grosze).
			$class_discount = array();
			$assigned       = 0;
			$max_tc         = null;
			$max_val        = -1;
			foreach ( $class_subtotal as $tc => $cs ) {
				$share                 = ( $subtotal > 0 && $discount > 0 ) ? self::prorate( $discount, $cs, $subtotal ) : 0;
				$class_discount[ $tc ] = $share;
				$assigned             += $share;
				if ( $cs > $max_val ) {
					$max_val = $cs;
					$max_tc  = $tc;
				}
			}
			if ( null !== $max_tc && $discount > 0 ) {
				$class_discount[ $max_tc ] += ( $discount - $assigned );
			}

			// VAT per klasa (zaokrąglenie RAZ na klasę), suma = vat_grosze oferty.
			$vat_grosze = 0;
			foreach ( $class_subtotal as $tc => $cs ) {
				$class_net   = $cs - $class_discount[ $tc ];
				$rate        = ( self::TAX_NONE === $tc ) ? 0.0 : (float) $tax_rates[ $tc ]['rate'];
				$vat_grosze += ( 0.0 === $rate || $class_net <= 0 ) ? 0 : self::vat_grosze( $class_net, $rate );
			}

			// Stawka per pozycja (do zapisu w Dziale 10) — wg klasy danej pozycji.
			foreach ( $lines as $i => $line ) {
				$is_exempt            = MP_OB_Products::zwolniona_z_vat( (array) $products[ $i ] );
				$tc                   = $is_exempt ? self::TAX_NONE : ( isset( $products[ $i ]['tax_class'] ) ? (string) $products[ $i ]['tax_class'] : '' );
				$line_tax_rates[ $i ] = ( self::TAX_NONE === $tc ) ? 0.0 : (float) $tax_rates[ $tc ]['rate'];
			}

			// Reprezentatywna stawka oferty: jedna klasa -> ta stawka; wiele klas ->
			// null (na dokumencie pokazujemy kwotę VAT, nie pojedynczą stawkę).
			$rate         = ( 1 === count( $class_subtotal ) && $line_tax_rates ) ? (float) reset( $line_tax_rates ) : null;
			$gross_grosze = $net_grosze + $vat_grosze;
		} else {
			/*
			 * Tu VAT wynosi 0 z mocy prawa, więc rozjazd podstaw NIE MOŻE dać złego
			 * podatku — i dlatego kontrola z gałęzi krajowej nie została tu przeniesiona
			 * w całości. Zostaje jednak druga połowa szkody, wspólna dla każdego
			 * mechanizmu: gdy pozycje SĄ, ale nie sumują się do podstawy, dokument
			 * pokazuje listę pozycji, która nie dodaje się do własnego netto. Dział 10
			 * zapisuje pozycje po jednej, więc rozjazd trafia wprost na papier klienta.
			 *
			 * Warunek jest zawężony do pozycji NIEPUSTYCH, bo oferta bez pozycji przy
			 * odwrotnym obciążeniu jest dopuszczona świadomie (patrz sekcja F testu
			 * podstawa-vat.php) — inaczej niż w gałęzi krajowej, gdzie puste pozycje
			 * przy niezerowej podstawie są właśnie tym błędem, którego szuka P2-G10.
			 */
			$pozycje = is_array( $context->get( 'lines' ) ) ? $context->get( 'lines' ) : array();
			if ( $pozycje ) {
				$suma_pozycji = 0;
				foreach ( $pozycje as $pozycja ) {
					$suma_pozycji += isset( $pozycja['line_grosze'] ) ? (int) $pozycja['line_grosze'] : 0;
				}

				$podstawa = (int) $context->get( 'subtotal_grosze', 0 );
				if ( $suma_pozycji !== $podstawa ) {
					return MP_OB_Result::fail(
						'Podstawa niezgodna z sumą pozycji — dokument pokazywałby pozycje, które nie dodają się do netto.',
						array(
							'subtotal_grosze' => $podstawa,
							'lines_grosze'    => $suma_pozycji,
						),
						'tax_base_mismatch'
					);
				}
			}

			// reverse_charge / out_of_scope — zawsze 0%, z RÓŻNYCH podstaw prawnych
			// (patrz tax_basis z Agenta 6.1), ale ten sam wynik liczbowy. line_tax_rates
			// zostają puste -> Dział 10 zapisze 0.00 dla każdej pozycji (fallback).
			$rate         = 0.0;
			$vat_grosze   = 0;
			$gross_grosze = $net_grosze;
		}

		return MP_OB_Result::ok(
			array(
				'tax_rate'       => $rate,
				'line_tax_rates' => $line_tax_rates,
				'net_grosze'     => $net_grosze,
				'vat_grosze'     => $vat_grosze,
				'gross_grosze'   => $gross_grosze,
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
				'critic' => new MP_OB_Field_Critic( 'K6.2', 'Krytyk 6.2 — zaokrąglenie-per-klasa', 'gross_grosze' ),
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
			'Wybór mechanizmu VAT (PL / UE reverse charge / poza UE) i zaokrąglenie per klasa podatkowa.',
			$pairs,
			$gate
		);
	}
}
