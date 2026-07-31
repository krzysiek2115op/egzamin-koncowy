<?php
/**
 * RODO: kasownik i eksporter danych osobowych leada (BD-3).
 *
 * Zrodlo (Golden Rule #2): docs/dzial-09/rodo-zgody-i-dziennik.md
 *
 * Powstalo, bo audyt koncowy pokazal, ze prawo do bycia zapomnianym KONCZYLO
 * SIE NA GRANICY WTYCZKI. Moduly ofertowy i sprzedazowy maja wlasne kasowniki,
 * ale modul sprzedazowy czyta adres klienta NA ZYWO z `wp_mp_leads` i daje mu
 * pierwszenstwo — wiec dane wyczyszczone po tamtej stronie WRACALY stad przy
 * kolejnym powiadomieniu. Bez kasownika w tej wtyczce zadanie usuniecia danych
 * bylo pozorne.
 *
 * Zakres kasowania jest wazony celowo:
 *  - `email` i `phone` to kontakt do OSOBY — znikaja,
 *  - `company_name` i `nip` identyfikuja PODMIOT (osoba prawna nie jest osoba
 *    fizyczna w rozumieniu RODO), a do tego sa potrzebne w ksiegowosci —
 *    zostaja. Gdyby klient prowadzil jednoosobowa dzialalnosc, administrator
 *    moze usunac wiersz recznie; automat nie zgaduje.
 *
 * Adres zastepczy zamiast pustego ciagu, bo tabela ma wiez unikalnosci na parze
 * kraj+NIP i wiele wierszy z pustym adresem bywa mylonych ze soba w raportach.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wpiecie w mechanizm prywatnosci WordPressa.
 */
class MP_Lead_Intake_Privacy {

	/** Hak wystawiany po anonimizacji leada — nasluchuje go wtyczka 3. */
	const HOOK_ANONYMIZED = 'mp_lead_anonymized';

	/** Wzorzec adresu zastepczego. */
	const PATTERN = 'deleted+%d@invalid';

	/** Ile leadow na jedno przejscie kasownika. */
	const BATCH = 50;

	/**
	 * Wpina eksporter i kasownik rdzenia.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
	}

	/**
	 * Rejestruje kasownik.
	 *
	 * @param array $erasers Kasowniki.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['mp-lead-intake'] = array(
			'eraser_friendly_name' => __( 'Zgłoszenia z formularza', 'mp-lead-intake' ),
			'callback'             => array( __CLASS__, 'erase_by_email' ),
		);

		return $erasers;
	}

	/**
	 * Rejestruje eksporter.
	 *
	 * @param array $exporters Eksportery.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['mp-lead-intake'] = array(
			'exporter_friendly_name' => __( 'Zgłoszenia z formularza', 'mp-lead-intake' ),
			'callback'               => array( __CLASS__, 'export_by_email' ),
		);

		return $exporters;
	}

	/**
	 * Anonimizuje leady o podanym adresie.
	 *
	 * @param string $email Adres z żądania.
	 * @param int    $page  Strona (paginacja rdzenia, od 1).
	 * @return array
	 */
	public static function erase_by_email( $email, $page = 1 ) {
		global $wpdb;

		$email = trim( (string) $email );
		$page  = max( 1, (int) $page );

		if ( '' === $email ) {
			return self::empty_result( true );
		}

		$table = MP_Lead_Intake_DB::leads_table();
		$rows  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email,
				self::BATCH
			)
		);

		if ( empty( $rows ) ) {
			return self::empty_result( true );
		}

		$removed = 0;

		foreach ( $rows as $lead_id ) {
			$lead_id = (int) $lead_id;

			$zmienione = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'email'      => sprintf( self::PATTERN, $lead_id ),
					'phone'      => '',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $lead_id )
			);

			if ( ! $zmienione ) {
				continue;
			}

			++$removed;

			MP_Lead_Intake_DB::anonymize_lead_ips( $lead_id );

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				MP_Lead_Intake_DB::activity_log_table(),
				array(
					'lead_id'     => $lead_id,
					'action'      => 'lead_anonymized',
					'description' => 'Dane kontaktowe usunięte na żądanie (RODO).',
					'created_at'  => current_time( 'mysql' ),
				)
			);

			/**
			 * Lead zanonimizowany — sygnał dla modułów ofertowego i sprzedażowego.
			 *
			 * Bez tego każdy z nich musiałby odpytywać nas cyklicznie albo — co
			 * gorsza — nie dowiedziałby się wcale i dalej wysyłał powiadomienia
			 * na adres, który miał zniknąć.
			 *
			 * @param int $lead_id Identyfikator leada.
			 */
			do_action( self::HOOK_ANONYMIZED, $lead_id );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $removed > 0
				? array( sprintf( /* translators: %d: liczba zgłoszeń. */ __( 'Zanonimizowano zgłoszenia: %d.', 'mp-lead-intake' ), $removed ) )
				: array(),
			// Kolejna strona tylko wtedy, gdy paczka wyszla pelna.
			'done'           => count( $rows ) < self::BATCH,
		);
	}

	/**
	 * Eksportuje dane leadów o podanym adresie.
	 *
	 * @param string $email Adres z żądania.
	 * @param int    $page  Strona.
	 * @return array
	 */
	public static function export_by_email( $email, $page = 1 ) {
		global $wpdb;

		$email = trim( (string) $email );
		$page  = max( 1, (int) $page );

		if ( '' === $email ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$table = MP_Lead_Intake_DB::leads_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, company_name, nip, email, phone, country, segment, status, created_at FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email,
				self::BATCH,
				( $page - 1 ) * self::BATCH
			),
			ARRAY_A
		);

		$export = array();

		foreach ( (array) $rows as $row ) {
			$data = array();

			foreach ( $row as $klucz => $wartosc ) {
				$data[] = array(
					'name'  => $klucz,
					'value' => (string) $wartosc,
				);
			}

			$export[] = array(
				'group_id'    => 'mp-lead-intake',
				'group_label' => __( 'Zgłoszenia z formularza', 'mp-lead-intake' ),
				'item_id'     => 'lead-' . (int) $row['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $export,
			'done' => count( (array) $rows ) < self::BATCH,
		);
	}

	/**
	 * Pusty wynik kasownika.
	 *
	 * @param bool $done Czy to koniec.
	 * @return array
	 */
	private static function empty_result( $done ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => (bool) $done,
		);
	}
}
