<?php
/**
 * Worker kolejki powiadomien — jedyne miejsce, ktore realnie wysyla e-maile.
 *
 * Dziala POZA zadaniem uzytkownika (cron), bo wysylka trwa tyle, ile odpowiada
 * serwer SMTP. Dokumentacja dzialu 7 mowi wprost, ze prawda z wp_mail() "does
 * not automatically mean that the user received the email successfully" —
 * dlatego wiersz ma licznik prob i kolumne bledu, a nie tylko stan wyslane.
 *
 * Zrodlo (Golden Rule #2): docs/dzial-07/powiadomienia-e-mail.md
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Przetwarzanie kolejki powiadomien.
 */
class MP_SW_Queue {

	/** Wiersz czeka na wysyłkę. */
	const STATUS_QUEUED = 'queued';

	/** Wiersz wysłany. */
	const STATUS_SENT = 'sent';

	/** Wiersz porzucony po wyczerpaniu prób. */
	const STATUS_FAILED = 'failed';

	/**
	 * Ile wierszy bierzemy w jednym przebiegu.
	 *
	 * Limit jest po to, żeby przebieg crona kończył się w rozsądnym czasie i nie
	 * wpadł w limit wykonania PHP z połową kolejki wysłaną. Reszta poczeka do
	 * następnego przebiegu — wiersze i tak zostają w stanie `queued`.
	 */
	const BATCH = 20;

	/** Ile razy próbujemy wysłać, zanim uznamy wiersz za porzucony. */
	const MAX_ATTEMPTS = 3;

	/** Filtr pozwalający witrynie zmienić rozmiar paczki. */
	const FILTER_BATCH = 'mp_sw_queue_batch';

	/**
	 * Przetwarza jedną paczkę kolejki.
	 *
	 * @return array{sent:int,failed:int,retry:int}
	 */
	public static function run() {
		global $wpdb;

		$table = MP_Sales_Workflow_DB::notifications_table();
		$batch = (int) apply_filters( self::FILTER_BATCH, self::BATCH );
		$batch = max( 1, min( 200, $batch ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND attempts < %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_QUEUED,
				self::MAX_ATTEMPTS,
				$batch
			),
			ARRAY_A
		);

		$summary = array(
			'sent'   => 0,
			'failed' => 0,
			'retry'  => 0,
		);

		foreach ( (array) $rows as $row ) {
			$outcome = self::send_one( $row );
			++$summary[ $outcome ];
		}

		return $summary;
	}

	/**
	 * Wysyła pojedynczy wiersz i odnotowuje wynik.
	 *
	 * @param array $row Wiersz kolejki.
	 * @return string sent | failed | retry
	 */
	private static function send_one( array $row ) {
		global $wpdb;

		$table    = MP_Sales_Workflow_DB::notifications_table();
		$id       = (int) $row['id'];
		$attempts = (int) $row['attempts'] + 1;
		$now      = current_time( 'mysql', true );

		/*
		 * Licznik prób rośnie PRZED wysyłką. Gdyby rósł po niej, przerwanie
		 * procesu w trakcie (limit czasu, restart) zostawiłoby wiersz z
		 * niezmienionym licznikiem i kolejny przebieg próbowałby w nieskończoność
		 * — a adresat mógł już dostać wiadomość.
		 */
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'attempts'   => $attempts,
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$sent    = wp_mail( $row['recipient'], $row['subject'], (string) $row['body'], $headers );

		if ( $sent ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'status'     => self::STATUS_SENT,
					'sent_at'    => $now,
					'last_error' => null,
					'updated_at' => $now,
				),
				array( 'id' => $id )
			);

			/**
			 * Powiadomienie wyszło.
			 *
			 * @param int   $id  Identyfikator wiersza kolejki.
			 * @param array $row Wiersz kolejki.
			 */
			do_action( 'mp_sw_notification_sent', $id, $row );

			return 'sent';
		}

		$error     = self::last_error();
		$exhausted = $attempts >= self::MAX_ATTEMPTS;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				// Po wyczerpaniu prób wiersz przestaje wracać w zapytaniu paczki,
				// ale ZOSTAJE w tabeli razem z komunikatem — inaczej nie dałoby
				// się dojść, dlaczego handlowiec nie dostał przypomnienia.
				'status'     => $exhausted ? self::STATUS_FAILED : self::STATUS_QUEUED,
				'last_error' => mb_substr( $error, 0, 255 ),
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);

		if ( $exhausted ) {
			/**
			 * Powiadomienie porzucone po wyczerpaniu prób.
			 *
			 * @param int    $id    Identyfikator wiersza kolejki.
			 * @param string $error Ostatni komunikat błędu.
			 */
			do_action( 'mp_sw_notification_failed', $id, $error );

			return 'failed';
		}

		return 'retry';
	}

	/**
	 * Komunikat ostatniego błędu wysyłki.
	 *
	 * @return string
	 */
	private static function last_error() {
		global $phpmailer;

		if ( isset( $phpmailer ) && is_object( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return (string) $phpmailer->ErrorInfo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		return __( 'Wysyłka nie powiodła się — brak szczegółów od serwera poczty.', 'mp-sales-workflow' );
	}

	/**
	 * Ile wierszy czeka w kolejce.
	 *
	 * @return int
	 */
	public static function pending() {
		global $wpdb;

		$table = MP_Sales_Workflow_DB::notifications_table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s AND attempts < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_QUEUED,
				self::MAX_ATTEMPTS
			)
		);
	}
}
