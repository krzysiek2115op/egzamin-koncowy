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
 *  - docs/dzial-01/wordpress-check_ajax_referer.md (check_ajax_referer)
 *  - docs/dzial-01/wordpress-current_user_can.md (current_user_can)
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

		nocache_headers(); // S7-2: poufna oferta (dane klienta/ceny) — bez cache w przeglądarce/proxy.
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . basename( $real_pdf ) . '"' );
		header( 'Content-Length: ' . filesize( $real_pdf ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_filesize
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $real_pdf );
		exit;
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
