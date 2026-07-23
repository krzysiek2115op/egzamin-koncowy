<?php
/**
 * Przechowywanie plików PDF ofert — katalog PRYWATNY (decyzja architektoniczna C,
 * 2026-07-23, patrz [[plugin2-architecture]]): NIGDY publiczny URL do pliku,
 * wyłącznie chroniony endpoint pobierania (nonce + capability + właściciel
 * oferty — Krok 4). Ten katalog leży pod wp-content/uploads, ale NIE jest
 * linkowany wprost nigdzie w kodzie frontowym — .htaccess/index.php to obrona
 * W GŁĄB, NIE główny mechanizm bezpieczeństwa (np. nginx nie honoruje
 * .htaccess) — głównym mechanizmem jest brak jakiegokolwiek bezpośredniego
 * linku do tego katalogu.
 *
 * Współdzielone przez Dział 9 (zapis TYMCZASOWY przy renderze) i Dział 10
 * (nazwa/lokalizacja DOCELOWA dopiero PO COMMIT — patrz gate Działu 9:
 * "plik tymczasowy; nazwa docelowa po COMMIT" i uwaga Działu 10: "po ROLLBACK
 * tymczasowy PDF kasowany").
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Katalog prywatny na pliki PDF ofert + operacje tymczasowy/docelowy/kasowanie.
 */
class MP_Offer_Builder_Storage {

	/**
	 * Katalog prywatny (tworzony + zabezpieczany przy pierwszym użyciu).
	 *
	 * @return string
	 */
	public static function private_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'mp-offer-builder-private';
		self::ensure_protected_dir( $dir );
		return $dir;
	}

	/**
	 * Katalog plików TYMCZASOWYCH (przed COMMIT Działu 10).
	 *
	 * @return string
	 */
	public static function tmp_dir() {
		$dir = self::private_dir() . '/tmp';
		self::ensure_protected_dir( $dir );
		return $dir;
	}

	/**
	 * Tworzy katalog (jeśli brak) i dokłada .htaccess/index.php jako obronę w głąb.
	 *
	 * @param string $dir Ścieżka katalogu.
	 * @return void
	 */
	private static function ensure_protected_dir( $dir ) {
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Zapisuje bajty PDF do NOWEGO pliku tymczasowego (Dział 9, Agent 9.1).
	 *
	 * @param string $pdf_bytes Zawartość pliku PDF.
	 * @return string Bezwzględna ścieżka zapisanego pliku.
	 */
	public static function write_tmp_pdf( $pdf_bytes ) {
		$path = self::tmp_dir() . '/of-' . wp_generate_uuid4() . '.pdf';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $pdf_bytes );
		return $path;
	}

	/**
	 * Przenosi plik tymczasowy do nazwy DOCELOWEJ — TYLKO po COMMIT (Dział 10).
	 *
	 * @param string $tmp_path     Ścieżka pliku tymczasowego.
	 * @param string $offer_number Numer oferty (do nazwy pliku).
	 * @param int    $version      Wersja oferty.
	 * @return string Bezwzględna ścieżka pliku docelowego.
	 */
	public static function finalize_pdf( $tmp_path, $offer_number, $version ) {
		$safe_number = trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $offer_number ), '-' );
		$final_path  = self::private_dir() . '/' . $safe_number . '-v' . (int) $version . '.pdf';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		rename( $tmp_path, $final_path );
		return $final_path;
	}

	/**
	 * Kasuje plik tymczasowy (ROLLBACK Działu 10 — "tymczasowy PDF kasowany").
	 *
	 * @param string $tmp_path Ścieżka pliku tymczasowego.
	 * @return void
	 */
	public static function delete_tmp( $tmp_path ) {
		if ( $tmp_path && file_exists( $tmp_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $tmp_path );
		}
	}
}
