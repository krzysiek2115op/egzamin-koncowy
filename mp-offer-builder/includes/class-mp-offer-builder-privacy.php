<?php
/**
 * Zgodność RODO/GDPR (SR5-01) — integracja z narzędziami prywatności WordPressa.
 *
 * Oferty B2B przechowują dane osobowe kontaktu klienta (nazwa firmy, e-mail, NIP,
 * kraj) w `wp_mp_ob_offers`, pełny snapshot w `wp_mp_ob_offer_versions.data_json`
 * oraz w plikach PDF. Ta klasa udostępnia:
 *  - EKSPORTER (`wp_privacy_personal_data_exporters`) — Narzędzia → Eksport danych
 *    osobowych: zwraca dane klienta powiązane z podanym adresem e-mail.
 *  - USUWANIE (`wp_privacy_personal_data_erasers`) — Narzędzia → Usuń dane osobowe:
 *    ANONIMIZUJE kolumny `client_*` (wiersz oferty zostaje — wymóg księgowo-prawny
 *    B2B), REDAGUJE snapshot `data_json` wersji i KASUJE pliki PDF z danymi/cenami.
 *  - Sugerowaną treść polityki prywatności (`wp_add_privacy_policy_content`).
 *
 * Retencja: wtyczka NIE kasuje ofert automatycznie (dokument handlowy o wartości
 * dowodowej) — usunięcie danych osobowych następuje wyłącznie na żądanie podmiotu
 * przez powyższe narzędzia. Politykę retencji określa administrator (patrz readme).
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eksporter/usuwanie danych osobowych klienta (RODO).
 */
class MP_Offer_Builder_Privacy {

	/** Identyfikator grupy/eksportera/erasera. */
	const SLUG = 'mp-offer-builder';

	/** Marker wstawiany w miejsce zanonimizowanych danych. */
	const REDACTED = '[usunięto na żądanie RODO]';

	/**
	 * Rejestruje filtry prywatności WP.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * @param array $exporters Rejestr eksporterów.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters[ self::SLUG ] = array(
			'exporter_friendly_name' => __( 'MP Offer Builder — oferty', 'mp-offer-builder' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array $erasers Rejestr eraserów.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers[ self::SLUG ] = array(
			'eraser_friendly_name' => __( 'MP Offer Builder — oferty', 'mp-offer-builder' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Znajduje oferty powiązane z adresem e-mail (dane osobowe kontaktu klienta).
	 *
	 * @param string $email Adres e-mail podmiotu danych.
	 * @return array Wiersze ofert (ARRAY_A).
	 */
	private static function offers_for_email( $email ) {
		global $wpdb;
		$table = MP_Offer_Builder_DB::offers_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, offer_number, version, status, pdf_path FROM {$table} WHERE client_email = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email
			),
			ARRAY_A
		);
	}

	/**
	 * Eksporter: zwraca dane osobowe klienta powiązane z e-mailem (stronicowany kontrakt WP).
	 *
	 * @param string $email Adres e-mail.
	 * @param int    $page  Numer strony (od 1) — zwracamy wszystko na stronie 1.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$page = (int) $page;
		$data = array();
		if ( 1 === $page ) {
			global $wpdb;
			$table = MP_Offer_Builder_DB::offers_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, offer_number, version, status, client_name, client_email, client_nip, client_country, created_at FROM {$table} WHERE client_email = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(string) $email
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$data[] = array(
					'group_id'    => self::SLUG,
					'group_label' => __( 'Oferty (MP Offer Builder)', 'mp-offer-builder' ),
					'item_id'     => 'mp-ob-offer-' . (int) $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Numer oferty', 'mp-offer-builder' ),
							'value' => (string) $row['offer_number'] . ' / v' . (int) $row['version'],
						),
						array(
							'name'  => __( 'Nazwa klienta', 'mp-offer-builder' ),
							'value' => (string) $row['client_name'],
						),
						array(
							'name'  => __( 'E-mail', 'mp-offer-builder' ),
							'value' => (string) $row['client_email'],
						),
						array(
							'name'  => __( 'NIP', 'mp-offer-builder' ),
							'value' => (string) $row['client_nip'],
						),
						array(
							'name'  => __( 'Kraj', 'mp-offer-builder' ),
							'value' => (string) $row['client_country'],
						),
						array(
							'name'  => __( 'Status', 'mp-offer-builder' ),
							'value' => (string) $row['status'],
						),
						array(
							'name'  => __( 'Utworzono', 'mp-offer-builder' ),
							'value' => (string) $row['created_at'],
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Usuwanie: anonimizuje kolumny client_*, redaguje snapshot data_json wersji,
	 * kasuje pliki PDF ofert powiązanych z e-mailem. Wiersz oferty POZOSTAJE
	 * (dokument handlowy), lecz bez danych osobowych.
	 *
	 * @param string $email Adres e-mail.
	 * @param int    $page  Numer strony.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$page     = (int) $page;
		$email    = (string) $email;
		$removed  = false;
		$messages = array();

		if ( 1 !== $page ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$offers = self::offers_for_email( $email );
		if ( empty( $offers ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$offers_table   = MP_Offer_Builder_DB::offers_table();
		$versions_table = MP_Offer_Builder_DB::versions_table();

		foreach ( $offers as $offer ) {
			$offer_id = (int) $offer['id'];

			// 1) Anonimizacja kolumn PII w nagłówku oferty (wiersz zostaje).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$anon = $wpdb->update(
				$offers_table,
				array(
					'client_name'       => self::REDACTED,
					'client_email'      => null,
					'client_nip'        => null,
					'client_country'    => null,
					'client_vat_status' => null,
				),
				array( 'id' => $offer_id )
			);
			if ( false !== $anon ) {
				$removed = true;
			}

			// 2) Redakcja pełnego snapshotu wersji (data_json niesie PII + ceny).
			$redaction = wp_json_encode(
				array(
					'redacted' => true,
					'reason'   => 'gdpr_erasure',
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$versions_table,
				array( 'data_json' => $redaction ),
				array( 'offer_id' => $offer_id )
			);

			// 3) Kasowanie pliku PDF (dane klienta + indywidualne ceny na dysku).
			$pdf_path = isset( $offer['pdf_path'] ) ? (string) $offer['pdf_path'] : '';
			if ( '' !== $pdf_path ) {
				$real_pdf  = realpath( $pdf_path );
				$real_base = realpath( MP_Offer_Builder_Storage::private_dir() );
				if ( false !== $real_pdf && false !== $real_base && 0 === strpos( $real_pdf, $real_base . DIRECTORY_SEPARATOR ) && is_file( $real_pdf ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					wp_delete_file( $real_pdf );
				}
			}

			// 4) Wpis audytowy usunięcia (rozliczalność RODO) — bez PII w opisie.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				MP_Offer_Builder_DB::activity_log_table(),
				array(
					'offer_id'    => $offer_id,
					'action'      => 'offer.pii_erased',
					'description' => 'Dane osobowe klienta zanonimizowane na żądanie (RODO).',
					'user_id'     => get_current_user_id(),
				)
			);
		}

		$messages[] = sprintf(
			/* translators: %d: liczba ofert */
			__( 'Zanonimizowano dane osobowe w %d ofercie/ofertach MP Offer Builder (wiersze zachowane jako dokumenty handlowe).', 'mp-offer-builder' ),
			count( $offers )
		);

		return array(
			'items_removed'  => $removed,
			'items_retained' => true, // wiersz oferty zachowany (bez PII) — dokument handlowy.
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Sugerowana treść polityki prywatności (Ustawienia → Prywatność).
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = __(
			'Wtyczka MP Offer Builder przechowuje dane kontaktowe klientów biznesowych (nazwa firmy, e-mail, NIP, kraj) oraz wygenerowane oferty PDF z indywidualnymi warunkami cenowymi. Dane są wykorzystywane wyłącznie do przygotowania i archiwizacji ofert handlowych. Oferty stanowią dokumenty o wartości dowodowej i nie są usuwane automatycznie; dane osobowe podmiotu danych mogą zostać wyeksportowane lub zanonimizowane na żądanie za pomocą narzędzi Narzędzia → Eksport/Usuń dane osobowe.',
			'mp-offer-builder'
		);
		wp_add_privacy_policy_content( 'MP Offer Builder', wp_kses_post( wpautop( $content ) ) );
	}
}
