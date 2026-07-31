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

		$summary = array(
			'sent'    => 0,
			'failed'  => 0,
			'retry'   => 0,
			'blocked' => 0,
		);

		/*
		 * Bezpiecznik sprawdzamy PRZED pobraniem paczki. Zalew zdarzeń zamienia
		 * sklep w nadawcę masowej poczty, a skutkiem jest wpisanie domeny na
		 * czarne listy — jedyna szkoda w tym systemie, której nie da się cofnąć
		 * poprawką w kodzie.
		 */
		if ( MP_SW_Mailer::halted() ) {
			$summary['blocked'] = self::pending();

			return $summary;
		}

		/*
		 * Odstęp między próbami rośnie wykładniczo: 5 minut po pierwszej
		 * nieudanej próbie, 25 po drugiej. Ponawianie co przebieg dobijałoby się
		 * do serwera, który właśnie odmawia — a przy odmowie za nadmiar żądań
		 * przedłużałoby własną blokadę.
		 */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nazwa tabeli pochodzi ze stalej klasy.
				"SELECT * FROM {$table} WHERE status = %s AND attempts < %d AND ( attempts = 0 OR updated_at <= DATE_SUB( %s, INTERVAL POWER( 5, attempts ) MINUTE ) ) ORDER BY id ASC LIMIT %d",
				self::STATUS_QUEUED,
				self::MAX_ATTEMPTS,
				current_time( 'mysql', true ),
				$batch
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( ! MP_SW_Mailer::allow_send() ) {
				++$summary['blocked'];
				continue;
			}

			/*
			 * Przejecie zadania PRZED wysylka. Bez tego dwa rownolegle przebiegi
			 * crona dostawaly z powyzszego SELECT-a te sama paczke i wysylaly
			 * kazda wiadomosc dwa razy (P3-S1). WP-Cron nie ma pojedynczego
			 * procesu, a jego blokada `doing_cron` trwa 60 sekund — przy wolnym
			 * SMTP paczka 20 wiadomosci idzie dluzej.
			 */
			if ( ! self::claim( (int) $row['id'], (int) $row['attempts'] ) ) {
				++$summary['blocked'];
				continue;
			}

			// Licznik podbila juz klamra — send_one() dostaje wiersz po przejeciu.
			$row['attempts'] = (int) $row['attempts'] + 1;

			$outcome = self::send_one( $row );
			++$summary[ $outcome ];
		}

		return $summary;
	}

	/**
	 * Przejmuje wiersz kolejki na wylacznosc.
	 *
	 * Tabela powiadomien nie ma kolumny na token, wiec klamra jest OPTYMISTYCZNA:
	 * licznik prob pelni role wersji wiersza, a warunek `attempts = <odczytana>`
	 * przepusci tylko jeden z rownoleglych przebiegow. Kto podbil licznik, ten
	 * wysyla; pozostali dostaja `false` i pomijaja wiersz. Ten sam wzorzec, co
	 * `lock_version` przy zmianie statusu procesu.
	 *
	 * Inkrementacja jest tu celowo POLACZONA z przejeciem. Gdyby licznik rosl
	 * osobno — jak wczesniej, w `send_one()` — przejecie i oznaczenie nadal
	 * bylyby dwiema operacjami, czyli tym samym bledem, tylko wezszym.
	 *
	 * @param int $id       Identyfikator wiersza.
	 * @param int $attempts Liczba prob ODCZYTANA przez ten przebieg.
	 * @return bool Czy udalo sie przejac.
	 */
	public static function claim( $id, $attempts ) {
		global $wpdb;

		$table = MP_Sales_Workflow_DB::notifications_table();

		$zajete = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nazwa tabeli pochodzi ze stalej klasy.
				"UPDATE {$table} SET attempts = attempts + 1, updated_at = %s WHERE id = %d AND attempts = %d",
				current_time( 'mysql', true ),
				(int) $id,
				(int) $attempts
			)
		);

		return 1 === (int) $zajete;
	}

	/**
	 * Wysyła pojedynczy wiersz i odnotowuje wynik.
	 *
	 * @param array $row Wiersz kolejki.
	 * @return string sent | failed | retry
	 */
	private static function send_one( array $row ) {
		global $wpdb;

		$table = MP_Sales_Workflow_DB::notifications_table();
		$id    = (int) $row['id'];
		$now   = current_time( 'mysql', true );

		/*
		 * Licznik prób rośnie PRZED wysyłką — ale robi to teraz `claim()`, jedną
		 * instrukcją razem z przejęciem wiersza. Powód pozostaje ten sam co
		 * wcześniej: gdyby licznik rósł po wysyłce, przerwanie procesu w trakcie
		 * (limit czasu, restart) zostawiłoby wiersz z niezmienionym licznikiem
		 * i kolejny przebieg próbowałby w nieskończoność, a adresat mógł już
		 * dostać wiadomość. Doszedł drugi powód: dopóki podbicie było osobnym
		 * zapisem, dwa równoległe przebiegi zdążyły wejść między odczyt a zapis
		 * i wysłać to samo dwa razy (P3-S1).
		 */
		$attempts = (int) $row['attempts'];

		/*
		 * Nagłówki budowane WYŁĄCZNIE jako tablica stałych — nigdy przez sklejanie
		 * łańcucha z danymi z bazy. Temat przechodzi jeszcze raz przez czyszczenie,
		 * choć Dział 7 już to zrobił: wiersz mógł trafić do kolejki starszą wersją
		 * kodu albo zostać zmieniony poza pipelinem, a to jest ostatni moment przed
		 * przekazaniem go bibliotece pocztowej.
		 */
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$to      = sanitize_email( (string) $row['recipient'] );
		$subject = MP_SW_Mailer::header_safe( (string) $row['subject'] );

		if ( ! is_email( $to ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'status'     => self::STATUS_FAILED,
					'last_error' => 'invalid recipient',
					'updated_at' => $now,
				),
				array( 'id' => $id )
			);

			return 'failed';
		}

		$sent = wp_mail( $to, $subject, (string) $row['body'], $headers );

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
