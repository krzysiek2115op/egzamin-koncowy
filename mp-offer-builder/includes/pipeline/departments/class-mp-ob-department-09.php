<?php
/**
 * Dział 9 — Render PDF.
 *
 * JEDYNY dział poza Działem 2 (odczyt BD-2/WC) i Działami 10/11 (zapis) z
 * realnym efektem ubocznym — render PDF z natury zapisuje plik. Zapis TYLKO
 * do katalogu PRYWATNEGO-TYMCZASOWEGO (MP_Offer_Builder_Storage::tmp_dir()) —
 * nazwa/lokalizacja DOCELOWA powstaje dopiero po COMMIT w Dziale 10 (patrz
 * gate poniżej: "plik tymczasowy; nazwa docelowa po COMMIT"), zero zapisów do
 * BD-2 (to nadal wyłącznie Dział 10). Zawartość pliku (1 plik = 1 dział):
 *  - Agent 9.1 (render)   — HTML z Działu 7 → PDF A4, Dompdf, metadane
 *  - Agent 9.2 (kontrola) — strony/rozmiar/treść (metadane)/SHA-256
 *  - QA Agent 9              — plik-to-nie-dowód (niezależna re-weryfikacja skrótu)
 *  - MP_OB_Department_09      — budowniczy działu
 *
 * DOMPDF — zależność RUNTIME zainstalowana realnie (composer require
 * dompdf/dompdf ^3.1, `vendor/` w repozytorium wtyczki — standardowa praktyka
 * dystrybucji wtyczek WP, które nie mogą zakładać `composer install` na
 * hostingu klienta). Kontrolowany brak (class_exists) zamiast fatal errora,
 * gdyby ktoś jednak wdrożył kod bez `vendor/` — ten sam wzorzec obronny co
 * WooCommerce/BCMath/intl w innych działach.
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - docs/dzial-09/dompdf.md (użycie, fonty DejaVu, metadane PDF)
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 9.1 — render: HTML (Dział 7) → PDF A4, Dompdf, fonty DejaVu domyślnie
 * osadzone, metadane PDF (Title = numer oferty, własny klucz = suma brutto).
 */
class MP_OB_D9_Agent_Render extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '9.1', 'Agent 9.1 — render', 'HTML z Działu 7 — PDF A4; fonty osadzone; metadane (tytuł = numer oferty)' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			return MP_OB_Result::fail( 'Biblioteka Dompdf niedostępna (brak vendor/ — composer install w wtyczce).', array(), 'dompdf_unavailable' );
		}

		$document = is_array( $context->get( 'document' ) ) ? $context->get( 'document' ) : array();
		$html     = isset( $document['html'] ) ? (string) $document['html'] : '';
		if ( '' === $html ) {
			return MP_OB_Result::fail( 'Brak treści dokumentu do renderu (Dział 7).', array(), 'missing_document' );
		}

		$offer_number = (string) $context->get( 'offer_number', '' );
		if ( '' === $offer_number ) {
			// Defense-in-depth: Dział 8 (numeracja) MUSI przebiec przed tym
			// dzialem (kolejność 1..11) — obrona na wypadek błędu wiązania.
			return MP_OB_Result::fail( 'Brak numeru oferty (Dział 8 musi przebiec przed renderem).', array(), 'missing_offer_number' );
		}
		$gross_grosze = (int) $context->get( 'gross_grosze', 0 );

		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false ); // offline — bez pobierania zdalnych zasobów (SSRF).
		// Wymuszone na poziomie Options, NIE zdane na CSS szablonu Działu 7 —
		// diakrytyka (ą ć ę ł ń ó ś ź ż) musi renderować się poprawnie NIEZALEŻNIE
		// od tego, czy szablon pamięta o "font-family: DejaVu Sans" (bez tego
		// Dompdf używa domyślnych fontów bazowych PDF — nieosadzonych, "krzaki").
		$options->set( 'defaultFont', 'DejaVu Sans' );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		// Metadane PDF — "tytuł = numer oferty" (diagram), plus własny klucz z
		// sumą brutto: podstawa realnej weryfikacji treści w Agencie 9.2/9.1
		// (patrz docs/dzial-09/dompdf.md — dlaczego metadane, nie treść strony).
		$dompdf->addInfo( 'Title', $offer_number );
		$dompdf->addInfo( 'MPOfferGross', (string) $gross_grosze );

		$pdf_bytes = $dompdf->output();
		$pages     = (int) $dompdf->getCanvas()->get_page_count();
		$tmp_path  = MP_Offer_Builder_Storage::write_tmp_pdf( $pdf_bytes );

		return MP_OB_Result::ok(
			array(
				'pdf' => array(
					'tmp_path' => $tmp_path,
					'pages'    => $pages,
					'bytes'    => strlen( $pdf_bytes ),
				),
			)
		);
	}
}

/**
 * Krytyk 9.1 — diakrytyka: PDF ma OSADZONY font DejaVu (nie systemowy) —
 * warunek konieczny, żeby ą ć ę ł ń ó ś ź ż wyszły poprawnie, nie jako "krzaki".
 */
class MP_OB_D9_Critic_Diacritics extends MP_OB_Abstract_Critic {

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

		$data     = $agent_result->get_data();
		$tmp_path = isset( $data['pdf']['tmp_path'] ) ? (string) $data['pdf']['tmp_path'] : '';
		$bytes    = ( '' !== $tmp_path && file_exists( $tmp_path ) ) ? file_get_contents( $tmp_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// /FontFile2 = program TrueType FIZYCZNIE osadzony w pliku (nie referencja
		// do fontu systemowego); BaseFont musi wskazywać na rodzinę DejaVu.
		if ( false === $bytes || false === strpos( $bytes, '/FontFile2' ) || 1 !== preg_match( '/\/BaseFont\s*\/[A-Za-z0-9+]*DejaVu/', $bytes ) ) {
			return MP_OB_Result::fail( 'Brak osadzonego fontu DejaVu w wygenerowanym PDF — ryzyko krzaków na diakrytykach.', array(), 'font_not_embedded' );
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * Agent 9.2 — kontrola: strony > 0, rozmiar w limicie, treść (metadane PDF)
 * zgodna z "kopertą" (kontekst pipeline'u), SHA-256 pliku.
 */
class MP_OB_D9_Agent_Control extends MP_OB_Abstract_Agent {

	/** Limit rozmiaru PDF oferty — rozsądny górny próg dla dokumentu B2B. */
	const MAX_PDF_BYTES = 5242880; // 5 MB.

	public function __construct() {
		parent::__construct( '9.2', 'Agent 9.2 — kontrola', 'Strony > 0, rozmiar w limicie; w treści pliku numer oferty i suma brutto; SHA-256' );
	}

	/**
	 * Sprawdza, czy $haystack (surowe bajty PDF) zawiera $needle zakodowany
	 * jako tekst PDF UTF-16BE z BOM — tak Dompdf/CPDF zapisuje wartości
	 * /Info (patrz docs/dzial-09/dompdf.md, zweryfikowane eksperymentalnie).
	 *
	 * @param string $haystack Surowe bajty pliku PDF.
	 * @param string $needle   Szukany tekst (UTF-8).
	 * @return bool
	 */
	private static function contains_pdf_info_string( $haystack, $needle ) {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			return false;
		}
		$encoded = "\xFE\xFF" . mb_convert_encoding( $needle, 'UTF-16BE', 'UTF-8' );
		return false !== strpos( $haystack, $encoded );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$pdf      = is_array( $context->get( 'pdf' ) ) ? $context->get( 'pdf' ) : array();
		$tmp_path = isset( $pdf['tmp_path'] ) ? (string) $pdf['tmp_path'] : '';
		$pages    = isset( $pdf['pages'] ) ? (int) $pdf['pages'] : 0;
		$bytes    = isset( $pdf['bytes'] ) ? (int) $pdf['bytes'] : 0;

		if ( $pages < 1 ) {
			return MP_OB_Result::fail( 'Wygenerowany PDF nie ma żadnej strony.', array(), 'empty_pdf' );
		}
		if ( $bytes > self::MAX_PDF_BYTES ) {
			return MP_OB_Result::fail( sprintf( 'PDF przekracza limit rozmiaru (%d > %d bajtów).', $bytes, self::MAX_PDF_BYTES ), array(), 'pdf_too_large' );
		}
		if ( '' === $tmp_path || ! file_exists( $tmp_path ) ) {
			return MP_OB_Result::fail( 'Plik PDF nie istnieje na dysku.', array(), 'pdf_file_missing' );
		}

		$file_bytes = file_get_contents( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $file_bytes ) {
			return MP_OB_Result::fail( 'Nie udało się odczytać pliku PDF do kontroli.', array(), 'pdf_unreadable' );
		}

		$offer_number = (string) $context->get( 'offer_number', '' );
		$gross_grosze = (string) (int) $context->get( 'gross_grosze', 0 );

		// "numer i kwota wyciągnięte z pliku = zgodne z kopertą" — porównanie
		// z metadanymi WYCIĄGNIĘTYMI z realnych bajtów pliku, nie z kontekstem
		// od razu (patrz docs/dzial-09/dompdf.md — dlaczego metadane).
		if ( ! self::contains_pdf_info_string( $file_bytes, $offer_number ) || ! self::contains_pdf_info_string( $file_bytes, $gross_grosze ) ) {
			return MP_OB_Result::fail( 'Numer oferty albo suma brutto nieobecne w metadanych wygenerowanego PDF.', array(), 'pdf_content_mismatch' );
		}

		return MP_OB_Result::ok(
			array(
				'pdf' => array_merge(
					$pdf,
					array( 'sha256' => hash( 'sha256', $file_bytes ) )
				),
			)
		);
	}
}

/**
 * Krytyk 9.2 — zawartość-pdf: skrót SHA-256 obecny i w poprawnym formacie
 * (64 znaki szesnastkowe) — sama treściowa kontrola już zaszła w Agencie 9.2.
 */
class MP_OB_D9_Critic_Content extends MP_OB_Abstract_Critic {

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

		$data   = $agent_result->get_data();
		$sha256 = isset( $data['pdf']['sha256'] ) ? (string) $data['pdf']['sha256'] : '';

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return MP_OB_Result::fail( 'Brak poprawnego skrótu SHA-256 pliku PDF.', array(), 'invalid_sha256' );
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * QA Agent 9 — plik-to-nie-dowód: samo istnienie pliku niczego nie potwierdza;
 * niezależne przeliczenie SHA-256 WPROST z dysku musi zgadzać się z wynikiem
 * kontroli treści, a plik musi leżeć w katalogu prywatnym-tymczasowym.
 */
class MP_OB_D9_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA9', 'QA Agent 9 — kontrola kompletności', 'Sprawdza plik-to-nie-dowód: sprawdzana treść, nie istnienie; plik tymczasowy do czasu COMMIT' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$pdf      = is_array( $context->get( 'pdf' ) ) ? $context->get( 'pdf' ) : array();
		$tmp_path = isset( $pdf['tmp_path'] ) ? (string) $pdf['tmp_path'] : '';
		$sha256   = isset( $pdf['sha256'] ) ? (string) $pdf['sha256'] : '';

		if ( '' === $tmp_path || ! file_exists( $tmp_path ) ) {
			return MP_OB_Result::fail( 'Plik PDF nie istnieje na dysku.', array(), 'pdf_file_missing' );
		}
		if ( 0 !== strpos( $tmp_path, MP_Offer_Builder_Storage::tmp_dir() ) ) {
			// "nazwa docelowa po COMMIT" — przed Działem 10 plik MUSI leżeć
			// wyłącznie w katalogu tymczasowym, nigdy gdzie indziej.
			return MP_OB_Result::fail( 'Plik PDF poza katalogiem prywatnym-tymczasowym.', array(), 'pdf_outside_protected_dir' );
		}

		$actual_sha256 = hash_file( 'sha256', $tmp_path );
		if ( $actual_sha256 !== $sha256 ) {
			return MP_OB_Result::fail( 'Skrót SHA-256 pliku na dysku nie zgadza się z wynikiem kontroli treści — plik-to-nie-dowód.', array(), 'pdf_hash_mismatch' );
		}

		return MP_OB_Result::ok( array( 'pdf_verified' => true ) );
	}
}

/**
 * Budowniczy działu 9.
 */
class MP_OB_Department_09 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_D9_Agent_Render(),
				'critic' => new MP_OB_D9_Critic_Diacritics( 'K9.1', 'Krytyk 9.1 — diakrytyka' ),
			),
			array(
				'agent'  => new MP_OB_D9_Agent_Control(),
				'critic' => new MP_OB_D9_Critic_Content( 'K9.2', 'Krytyk 9.2 — zawartość-pdf' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D9_QA_Agent(),
			new MP_OB_Accept_Critic( 'QAK9', 'QA Krytyk 9 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			9,
			'render-pdf',
			'Render PDF',
			'Render HTML szablonu do PDF A4 (fonty DejaVu osadzone) i kontrola treści + skrótu pliku.',
			$pairs,
			$gate
		);
	}
}
