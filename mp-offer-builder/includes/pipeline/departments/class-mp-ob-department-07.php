<?php
/**
 * Dział 7 — Szablon i treść oferty.
 *
 * CZYSTA FUNKCJA na kontekście (templates[] ze snapshotu Działu 2, client/items
 * z Działu 1, lines/subtotal z Działu 4, discounts z Działu 5, tax_* z Działu 6)
 * — zero wywołań WC_* albo wpdb (zasada "jeden odczyt", patrz Dział 2). Zawartość
 * pliku (1 plik = 1 dział):
 *  - Agent 7.1 (dobór)    — szablon wg języka żądania, z zamrożonego snapshotu
 *  - Agent 7.2 (scalenie) — podstawienie znaczników {{...}}, formaty kwot/dat wg języka
 *  - QA Agent 7             — jednojęzyczność dokumentu
 *  - MP_OB_Department_07    — budowniczy działu
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - docs/dzial-07/php-intl-numberformatter.md (formatowanie kwot wg locale)
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 7.1 — dobór szablonu wg języka żądania, z zamrożonego snapshotu Działu 2.
 *
 * Dział 2 pobiera z BD-2 TYLKO szablon w żądanym języku (patrz `templates[lang]`
 * w class-mp-ob-department-02.php) — brak szablonu w tym języku już tam kończy
 * się FAIL 'missing_template'. Ten agent i tak weryfikuje ponownie (defense-in-
 * depth, ten sam wzorzec co niezależna suma kontrolna w Dziale 4/6), zamiast
 * ślepo ufać, że snapshot zawiera dokładnie to, czego oczekuje Dział 7.
 */
class MP_OB_D7_Agent_Selection extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '7.1', 'Agent 7.1 — dobór', 'Szablon wg języka z żądania: pl albo en; wersja szablonu do historii' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$lang      = (string) $context->get( 'lang', '' );
		$templates = is_array( $context->get( 'templates' ) ) ? $context->get( 'templates' ) : array();
		$template  = isset( $templates[ $lang ] ) && is_array( $templates[ $lang ] ) ? $templates[ $lang ] : null;

		if ( ! $template || ! isset( $template['lang'] ) || (string) $template['lang'] !== $lang ) {
			// Brak szablonu w języku = FAIL, NIGDY ciche przejście na polski (patrz
			// blueprint Działu 7 — to samo zdanie powtórzone jako wymóg wprost).
			return MP_OB_Result::fail( sprintf( 'Brak szablonu oferty w języku "%s" w zamrożonym snapshocie.', $lang ), array(), 'missing_template_selection' );
		}

		return MP_OB_Result::ok(
			array(
				'template_id'      => isset( $template['id'] ) ? (int) $template['id'] : null,
				'template_version' => isset( $template['version'] ) ? (string) $template['version'] : '',
				'template_lang'    => (string) $template['lang'],
				'template_content' => isset( $template['content'] ) ? (string) $template['content'] : '',
			)
		);
	}
}

/**
 * Krytyk 7.1 — język-szablonu: szablon musi być w JĘZYKU żądania, nie w innym.
 */
class MP_OB_D7_Critic_Language extends MP_OB_Abstract_Critic {

	/**
	 * @param MP_OB_Result  $agent_result Wynik agenta.
	 * @param MP_OB_Context $context      Kontekst.
	 * @return MP_OB_Result
	 */
	public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$data = $agent_result->get_data();
		if ( ( isset( $data['template_lang'] ) ? $data['template_lang'] : '' ) !== (string) $context->get( 'lang', '' ) ) {
			return MP_OB_Result::fail( 'Język dobranego szablonu nie zgadza się z żądaniem.', array(), 'template_language_mismatch' );
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * Agent 7.2 — scalenie: podstawia dane klienta/pozycji/rabatów/sum do szablonu,
 * liczby i daty w konwencji języka (patrz docs/dzial-07/php-intl-numberformatter.md).
 */
class MP_OB_D7_Agent_Merge extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '7.2', 'Agent 7.2 — scalenie', 'Podstawia dane klienta, pozycje, rabaty, sumy; liczby i daty w konwencji języka' );
	}

	/**
	 * Formatuje kwotę w groszach na tekst wg konwencji językowej. WYŁĄCZNIE
	 * prezentacyjne — nie zasila żadnej dalszej arytmetyki (ta zostaje w groszach
	 * aż do zapisu, patrz docs/dzial-07/php-intl-numberformatter.md).
	 *
	 * @param int    $grosze   Kwota w groszach.
	 * @param string $lang     'pl' albo 'en'.
	 * @param string $currency Kod waluty (np. 'PLN').
	 * @return string
	 */
	public static function format_money( $grosze, $lang, $currency ) {
		$amount = ( (int) $grosze ) / 100;

		if ( class_exists( 'NumberFormatter' ) ) {
			$locale    = 'pl' === $lang ? 'pl_PL' : 'en_US';
			$formatter = new NumberFormatter( $locale, NumberFormatter::DECIMAL );
			$formatter->setAttribute( NumberFormatter::FRACTION_DIGITS, 2 );
			$number = $formatter->format( $amount );
		} else {
			// Fallback bez rozszerzenia intl — te same konwencje separatorów, WŁĄCZNIE
			// z separatorem tysięcznym: NumberFormatter dla pl_PL zwraca twardą spację
			// (U+00A0, NBSP), nie zwykłą spację (U+0020) — bez tego serwer bez intl
			// wypuszczałby BYTE-owo inny dokument niż serwer z intl dla tych samych danych.
			$number = 'pl' === $lang ? number_format( $amount, 2, ',', "\xc2\xa0" ) : number_format( $amount, 2, '.', ',' );
		}

		$symbol = 'PLN' === $currency ? ( 'pl' === $lang ? 'zł' : 'PLN' ) : (string) $currency;

		return trim( $number . ' ' . $symbol );
	}

	/**
	 * Formatuje datę wg konwencji językowej (bez zależności od intl — data-only,
	 * bez stref czasowych).
	 *
	 * @param int    $timestamp Znacznik czasu (GMT).
	 * @param string $lang      'pl' albo 'en'.
	 * @return string
	 */
	/**
	 * Escapowanie wartości POLA (dane klienta/produktu) do HTML szablonu ORAZ
	 * neutralizacja nawiasów podstawienia (S5-01) — dane z literalnym "{{token}}"
	 * nie mogą udawać niepodstawionego znacznika ani wstrzyknąć się w szablon.
	 * Nawiasy stają się encjami (Dompdf wyświetli je jako { }).
	 *
	 * @param string $value Wartość pola.
	 * @return string
	 */
	public static function esc_field( $value ) {
		return str_replace( array( '{', '}' ), array( '&#123;', '&#125;' ), esc_html( (string) $value ) );
	}

	public static function format_date( $timestamp, $lang ) {
		return 'pl' === $lang ? gmdate( 'd.m.Y', $timestamp ) : gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Buduje wiersze tabeli pozycji (HTML) z products/lines/items ze snapshotu.
	 *
	 * @param array  $items    Pozycje (Dział 1).
	 * @param array  $products Produkty (Dział 2).
	 * @param array  $lines    Wyceny pozycji (Dział 4).
	 * @param string $lang     'pl' albo 'en'.
	 * @param string $currency Kod waluty.
	 * @return string
	 */
	private static function build_items_table( array $items, array $products, array $lines, $lang, $currency ) {
		$rows = '';
		foreach ( $items as $i => $item ) {
			$name        = isset( $products[ $i ]['name'] ) ? (string) $products[ $i ]['name'] : '';
			$qty         = isset( $item['qty'] ) ? (int) $item['qty'] : 0;
			$unit_grosze = isset( $lines[ $i ]['unit_grosze'] ) ? (int) $lines[ $i ]['unit_grosze'] : 0;
			$line_grosze = isset( $lines[ $i ]['line_grosze'] ) ? (int) $lines[ $i ]['line_grosze'] : 0;

			$rows .= sprintf(
				'<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
				self::esc_field( $name ),
				$qty,
				esc_html( self::format_money( $unit_grosze, $lang, $currency ) ),
				esc_html( self::format_money( $line_grosze, $lang, $currency ) )
			);
		}
		return '<table>' . $rows . '</table>';
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$lang     = (string) $context->get( 'lang', '' );
		$currency = (string) $context->get( 'currency', 'PLN' );
		$client   = is_array( $context->get( 'client' ) ) ? $context->get( 'client' ) : array();
		$items    = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();
		$products = is_array( $context->get( 'products' ) ) ? $context->get( 'products' ) : array();
		$lines    = is_array( $context->get( 'lines' ) ) ? $context->get( 'lines' ) : array();
		$content  = (string) $context->get( 'template_content', '' );

		// Adnotacja odwrotnego obciążenia — TYLKO gdy mechanizm faktycznie
		// reverse_charge (patrz Dział 6 i docs/dzial-06), w języku dokumentu.
		$mechanism = (string) $context->get( 'tax_mechanism', '' );
		if ( 'reverse_charge' === $mechanism ) {
			$note = 'pl' === $lang
				? 'Odwrotne obciążenie — art. 196 dyrektywy 2006/112/WE. Podatek rozlicza nabywca.'
				: 'Reverse charge — Article 196 of Directive 2006/112/EC. VAT to be accounted for by the recipient.';
		} else {
			$note = '';
		}

		$dict = array(
			'client_name'        => self::esc_field( isset( $client['name'] ) ? $client['name'] : '' ),
			'client_email'       => self::esc_field( isset( $client['email'] ) ? $client['email'] : '' ),
			'client_nip'         => self::esc_field( isset( $client['nip'] ) ? $client['nip'] : '' ),
			'client_country'     => self::esc_field( isset( $client['country'] ) ? $client['country'] : '' ),
			'items_table'        => self::build_items_table( $items, $products, $lines, $lang, $currency ),
			'subtotal'           => esc_html( self::format_money( (int) $context->get( 'subtotal_grosze', 0 ), $lang, $currency ) ),
			'discount_total'     => esc_html( self::format_money( (int) $context->get( 'discount_total', 0 ), $lang, $currency ) ),
			'net_total'          => esc_html( self::format_money( (int) $context->get( 'net_grosze', 0 ), $lang, $currency ) ),
			'vat_total'          => esc_html( self::format_money( (int) $context->get( 'vat_grosze', 0 ), $lang, $currency ) ),
			'gross_total'        => esc_html( self::format_money( (int) $context->get( 'gross_grosze', 0 ), $lang, $currency ) ),
			'currency'           => esc_html( $currency ),
			// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- data WYŚWIETLANA w PDF: current_time+gmdate renderuje lokalną datę ścienną (strefa sklepu), nie do arytmetyki.
			'offer_date'         => self::format_date( current_time( 'timestamp' ), $lang ),
			'tax_mechanism_note' => esc_html( $note ),
		);

		$html = preg_replace_callback(
			'/\{\{([a-zA-Z0-9_]+)\}\}/',
			function ( $matches ) use ( $dict ) {
				return array_key_exists( $matches[1], $dict ) ? $dict[ $matches[1] ] : $matches[0];
			},
			$content
		);

		// "żaden znacznik podstawienia nie zostaje pusty ani w nawiasach" — STOP
		// jawny na pierwszym niepodstawionym znaczniku, zamiast wysłać handlowcowi
		// dokument z widocznym "{{...}}".
		if ( preg_match( '/\{\{[a-zA-Z0-9_]+\}\}/', $html, $leftover ) ) {
			return MP_OB_Result::fail( sprintf( 'Niepodstawiony znacznik w szablonie: %s', $leftover[0] ), array( 'placeholder' => $leftover[0] ), 'unfilled_placeholder' );
		}

		return MP_OB_Result::ok(
			array(
				'document' => array(
					'lang'             => $lang,
					'template_version' => (string) $context->get( 'template_version', '' ),
					'html'             => $html,
				),
			)
		);
	}
}

/**
 * Krytyk 7.2 — puste-pola: niezależna, powtórna weryfikacja braku znaczników
 * (defense-in-depth — ten sam wzorzec co niezależna suma kontrolna w Dziale 4).
 */
class MP_OB_D7_Critic_No_Placeholders extends MP_OB_Abstract_Critic {

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
		$html = isset( $data['document']['html'] ) ? (string) $data['document']['html'] : '';

		if ( '' === $html || false !== strpos( $html, '{{' ) ) {
			return MP_OB_Result::fail( 'Dokument pusty albo zawiera niepodstawiony znacznik.', array(), 'empty_or_unfilled_document' );
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * QA Agent 7 — jednojęzyczność dokumentu: template_lang = document.lang = lang żądania.
 */
class MP_OB_D7_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA7', 'QA Agent 7 — kontrola kompletności', 'Sprawdza jednojęzyczność dokumentu: mieszanka pl/en w jednej ofercie = FAIL' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$lang          = (string) $context->get( 'lang', '' );
		$template_lang = (string) $context->get( 'template_lang', '' );
		$document      = is_array( $context->get( 'document' ) ) ? $context->get( 'document' ) : array();
		$document_lang = isset( $document['lang'] ) ? (string) $document['lang'] : '';

		if ( '' === $lang || $lang !== $template_lang || $lang !== $document_lang ) {
			return MP_OB_Result::fail(
				'Niespójność językowa dokumentu.',
				array(
					'lang'          => $lang,
					'template_lang' => $template_lang,
					'document_lang' => $document_lang,
				),
				'document_language_mismatch'
			);
		}

		return MP_OB_Result::ok( array( 'document_lang_consistent' => true ) );
	}
}

/**
 * Budowniczy działu 7.
 */
class MP_OB_Department_07 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_D7_Agent_Selection(),
				'critic' => new MP_OB_D7_Critic_Language( 'K7.1', 'Krytyk 7.1 — język-szablonu' ),
			),
			array(
				'agent'  => new MP_OB_D7_Agent_Merge(),
				'critic' => new MP_OB_D7_Critic_No_Placeholders( 'K7.2', 'Krytyk 7.2 — puste-pola' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D7_QA_Agent(),
			new MP_OB_Accept_Critic( 'QAK7', 'QA Krytyk 7 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			7,
			'template-content',
			'Szablon i treść oferty',
			'Dobór szablonu wg języka i scalenie danych oferty w dokument HTML, bez niepodstawionych znaczników.',
			$pairs,
			$gate
		);
	}
}
