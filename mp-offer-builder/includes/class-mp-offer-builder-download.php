<?php
/**
 * Endpoint pobierania PDF ofert — chroniony URL (decyzja architektoniczna C,
 * 2026-07-23, patrz [[plugin2-architecture]]): nonce + capability + weryfikacja
 * właściciela oferty, NIGDY bezpośredni link do pliku w wp-content/uploads.
 *
 * Nazwa akcji i wzorzec nonce (`DOWNLOAD_ACTION . '_' . $offer_id`) są TYM SAMYM
 * kontraktem, który buduje `MP_OB_D11_Agent_Response::run()` (Dział 11) —
 * zero duplikowania stringa akcji.
 *
 * Logika autoryzacji (`can_download()`) jest wydzielona do metody testowalnej
 * bez realnego streamu pliku — `handle()` sam (nagłówki HTTP + `readfile()`)
 * nie jest testowalny w harnessie CLI (patrz tests/process-harness).
 *
 * Oficjalne API — Golden Rule #2:
 *  - docs/dzial-01/json-schema-2020-12.md (check_ajax_referer)
 *  - docs/dzial-01/json-schema-2020-12.md (current_user_can)
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strumieniuje plik PDF ofercie, do której wywołujący ma dostęp.
 */
class MP_Offer_Builder_Download {

	/**
	 * Rejestruje akcję AJAX pod TĄ SAMĄ nazwą, którą Dział 11 wpisuje w pdf_url.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_ajax_' . MP_OB_D11_Agent_Response::DOWNLOAD_ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Obsługuje żądanie: nonce -> capability -> istnienie oferty -> właściciel
	 * -> istnienie pliku -> strumień. Zatrzymuje się (wp_die) na pierwszym
	 * niespełnionym warunku.
	 *
	 * @return void
	 */
	public static function handle() {
		$offer_id = isset( $_GET['offer_id'] ) ? absint( $_GET['offer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! check_ajax_referer( MP_OB_D11_Agent_Response::DOWNLOAD_ACTION . '_' . $offer_id, '_wpnonce', false ) ) {
			wp_die( esc_html__( 'Nieprawidłowy token bezpieczeństwa.', 'mp-offer-builder' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( MP_OB_D1_Agent_Permission::CAPABILITY ) ) {
			wp_die( esc_html__( 'Brak uprawnień do pobierania ofert.', 'mp-offer-builder' ), '', array( 'response' => 403 ) );
		}

		$offer = MP_Offer_Builder_DB::get_offer( $offer_id );
		if ( ! $offer ) {
			wp_die( esc_html__( 'Oferta nie istnieje.', 'mp-offer-builder' ), '', array( 'response' => 404 ) );
		}

		if ( ! self::can_download( $offer, get_current_user_id() ) ) {
			// SR3-01: odmowa dostępu do CUDZEJ oferty (próba IDOR przez uprawnionego
			// handlowca) trafia do dziennika audytu — inaczej nadużycie byłoby niewykrywalne.
			self::log_access( $offer_id, 'offer.pdf_denied', 'owner_mismatch' );
			wp_die( esc_html__( 'Brak dostępu do tej oferty.', 'mp-offer-builder' ), '', array( 'response' => 403 ) );
		}

		$pdf_path = isset( $offer['pdf_path'] ) ? (string) $offer['pdf_path'] : '';
		if ( '' === $pdf_path || ! file_exists( $pdf_path ) ) {
			wp_die( esc_html__( 'Plik PDF jeszcze nie istnieje.', 'mp-offer-builder' ), '', array( 'response' => 404 ) );
		}

		// Obrona w głąb (S7-1): plik MUSI leżeć w prywatnym katalogu wtyczki.
		// pdf_path pochodzi z BD i jest ustawiany wyłącznie przez Dział 10, ale
		// endpoint nie ufa mu na tyle, by strumieniować dowolną ścieżkę z dysku.
		$real_pdf  = realpath( $pdf_path );
		$real_base = realpath( MP_Offer_Builder_Storage::private_dir() );
		if ( false === $real_pdf || false === $real_base || 0 !== strpos( $real_pdf, $real_base . DIRECTORY_SEPARATOR ) ) {
			wp_die( esc_html__( 'Plik PDF poza dozwolonym katalogiem.', 'mp-offer-builder' ), '', array( 'response' => 404 ) );
		}

		// SR3-01: rozliczalność — kto/kiedy pobrał poufną ofertę B2B (indywidualne ceny).
		self::log_access( $offer_id, 'offer.pdf_downloaded', '' );

		nocache_headers(); // S7-2: poufna oferta (dane klienta/ceny) — bez cache w przeglądarce/proxy.
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );          // SR3-03/SR4-02: bez MIME-sniffingu.
		header( 'X-Frame-Options: DENY' );                     // SR4-03: bez osadzania w ramce (clickjacking).
		header( 'Referrer-Policy: no-referrer' );              // SR5-05: nazwa pliku nie wycieka w Referer.
		header( 'Cache-Control: private, no-store, max-age=0' ); // SR3-04: twardszy no-store dla dokumentu z cenami.
		header( 'Content-Disposition: attachment; filename="' . basename( $real_pdf ) . '"' );
		header( 'Content-Length: ' . filesize( $real_pdf ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_filesize
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $real_pdf );
		exit;
	}

	/**
	 * Wpis audytowy pobrania/odmowy PDF do BD-2 (SR3-01, rozliczalność dostępu do
	 * poufnych ofert). Best-effort — ewentualny błąd logu nie blokuje odpowiedzi.
	 * Zapisujemy user_id + offer_id + akcję (created_at z DEFAULT schematu); BEZ IP
	 * (minimalizacja danych — RODO). Odmowy 'offer.pdf_denied' służą wykrywaniu IDOR.
	 *
	 * @param int    $offer_id ID oferty.
	 * @param string $action   'offer.pdf_downloaded' albo 'offer.pdf_denied'.
	 * @param string $note     Krótki, nietajny opis (np. powód odmowy).
	 * @return void
	 */
	private static function log_access( $offer_id, $action, $note = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			MP_Offer_Builder_DB::activity_log_table(),
			array(
				'offer_id'    => (int) $offer_id,
				'action'      => (string) $action,
				'description' => (string) $note,
				'user_id'     => get_current_user_id(),
			)
		);
	}

	/**
	 * Kontrola właściciela (decyzja własności ofert, Krok 4): administrator
	 * (`manage_options`) widzi/pobiera wszystko, w przeciwnym razie TYLKO
	 * twórca oferty. Wydzielona z handle(), testowalna bez streamu/nagłówków.
	 *
	 * @param array $offer   Wiersz oferty (ARRAY_A z BD-2), musi zawierać 'created_by'.
	 * @param int   $user_id ID zalogowanego użytkownika.
	 * @return bool
	 */
	public static function can_download( array $offer, $user_id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return isset( $offer['created_by'] ) && null !== $offer['created_by'] && (int) $offer['created_by'] === (int) $user_id;
	}
}
