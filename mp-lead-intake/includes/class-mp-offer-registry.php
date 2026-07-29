<?php
/**
 * Rejestr ofert w BD-3 — materializuje relację lead → oferta.
 *
 * Zlecenie opisuje BD-3 jako „wp_mp_leads + wp_mp_offers + wp_mp_activity_log:
 * relacja lead → oferta → aktywność". Tabela `wp_mp_offers` istniała od początku,
 * ale stała PUSTA: oferty buduje wtyczka 2 we własnych tabelach (`wp_mp_ob_*`,
 * osobna nazwa, żeby przy współistnieniu obu wtyczek nic nie kolidowało), więc
 * relacja z BD-3 nie miała gdzie powstać.
 *
 * DLACZEGO TO ROBI WTYCZKA 1, A NIE 2. `wp_mp_offers` należy do BD-3, czyli do
 * tej wtyczki. Gdyby zapisywała tam wtyczka 2, złamalibyśmy zasadę, na której
 * stoi cała architektura: żaden moduł nie pisze do cudzych tabel, a granice biegną
 * po zdarzeniach. Tutaj jest odwrotnie — to wtyczka 1 nasłuchuje cudzego zdarzenia
 * i sama zapisuje u siebie. Wtyczka 2 nie musi wiedzieć, że BD-3 w ogóle istnieje.
 *
 * Wpis jest WSKAŹNIKIEM, nie kopią oferty: numer, status i kwota. Pozycje, wersje
 * i PDF zostają tam, gdzie ich miejsce — w module ofertowym. Duplikowanie ich
 * tutaj oznaczałoby dwa źródła prawdy o tej samej ofercie.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nasłuch zdarzeń modułu ofertowego.
 */
class MP_Lead_Intake_Offer_Registry {

	/** Zdarzenie emitowane przez wtyczkę 2 po finalizacji oferty. */
	const HOOK_CREATED = 'mp_offer_created';

	/** Zdarzenie zatwierdzenia oferty (kontrakt wtyczki 2). */
	const HOOK_APPROVED = 'mp_offer_approved';

	/**
	 * Wpina nasłuch.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK_CREATED, array( __CLASS__, 'handle_offer_event' ), 10, 2 );
		add_action( self::HOOK_APPROVED, array( __CLASS__, 'handle_offer_event' ), 10, 2 );
	}

	/**
	 * Osłona nasłuchu: żaden nasz wyjątek nie wychodzi do modułu ofertowego.
	 *
	 * WordPress nie izoluje subskrybentów — wyjątek rzucony tutaj poleciałby
	 * przez `do_action()` prosto w pipeline wtyczki 2, gdzie kończy się
	 * ROLLBACK-iem. Znaczyłoby to, że nasz wskaźnik przy leadzie (rzecz
	 * pomocnicza) potrafi wywrócić WYSTAWIENIE OFERTY (rzecz główna).
	 * Zapisujemy więc ślad do dziennika i milkniemy.
	 *
	 * @param int   $offer_id Identyfikator oferty w module ofertowym.
	 * @param array $payload  Dane oferty ze zdarzenia.
	 * @return int Identyfikator wiersza w BD-3; 0 gdy nic nie zapisano.
	 */
	public static function handle_offer_event( $offer_id, $payload = array() ) {
		try {
			return self::on_offer_event( $offer_id, $payload );
		} catch ( \Throwable $e ) {
			global $wpdb;

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				MP_Lead_Intake_DB::activity_log_table(),
				array(
					'lead_id'     => isset( $payload['lead_id'] ) ? absint( $payload['lead_id'] ) : null,
					'action'      => 'offer_registry_exception',
					'description' => sprintf( 'Wyjątek przy zapisie wskaźnika oferty %d: %s', (int) $offer_id, $e->getMessage() ),
					'created_at'  => current_time( 'mysql' ),
				)
			);

			return 0;
		}
	}

	/**
	 * Zapisuje albo odświeża wskaźnik oferty przy leadzie.
	 *
	 * Ta sama obsługa dla obu zdarzeń: przy utworzeniu powstaje wiersz, przy
	 * zatwierdzeniu aktualizuje się jego status i kwota. Osobne metody musiałyby
	 * i tak robić to samo, a rozjechałyby się przy pierwszej zmianie.
	 *
	 * @param int   $offer_id Identyfikator oferty w module ofertowym.
	 * @param array $payload  Dane oferty ze zdarzenia.
	 * @return int Identyfikator wiersza w BD-3; 0 gdy nic nie zapisano.
	 */
	public static function on_offer_event( $offer_id, $payload = array() ) {
		global $wpdb;

		$payload = is_array( $payload ) ? $payload : array();
		$lead_id = isset( $payload['lead_id'] ) ? absint( $payload['lead_id'] ) : 0;
		$number  = isset( $payload['offer_number'] ) ? sanitize_text_field( (string) $payload['offer_number'] ) : '';

		/*
		 * Oferta ręczna (bez leada) nie ma czego wiązać, a oferta bez numeru byłaby
		 * wskaźnikiem donikąd — w obu przypadkach milczymy zamiast zgadywać.
		 */
		if ( $lead_id < 1 || '' === $number ) {
			return 0;
		}

		// Lead mógł zostać w międzyczasie usunięty; wiersz-sierota w BD-3 psułby
		// relację, o którą w tym wszystkim chodzi.
		$table  = MP_Lead_Intake_DB::leads_table();
		$exists = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $lead_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $exists < 1 ) {
			return 0;
		}

		$offers = MP_Lead_Intake_DB::offers_table();
		$now    = current_time( 'mysql' );

		$dane = array(
			'lead_id'      => $lead_id,
			'offer_number' => substr( $number, 0, 50 ),
			'status'       => substr( self::status( $payload ), 0, 30 ),
			'total_amount' => self::amount( $payload ),
			'currency'     => substr( self::currency( $payload ), 0, 3 ),
			'updated_at'   => $now,
		);

		/*
		 * Szukamy po SAMYM numerze oferty, bo taki jest klucz w BD-3
		 * (`uq_offer_number` — numer jest unikalny w skali firmy, nie w obrębie
		 * leada). Wyszukiwanie po parze (lead, numer) wyglądało rozsądnie, ale
		 * przy numerze zajętym przez inny lead kończyło się cichą porażką INSERT-a
		 * na więzie unikalności: rejestr milczał, a wiersz nie powstawał.
		 *
		 * Identyfikator z modułu ofertowego celowo nie jest kluczem: korekta
		 * tworzy tam NOWĄ wersję pod tym samym numerem, a w BD-3 ma zostać jeden
		 * wiersz na ofertę, nie jeden na każdą poprawkę.
		 */
		$istniejacy = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, lead_id FROM {$offers} WHERE offer_number = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$dane['offer_number']
			),
			ARRAY_A
		);

		$row_id = is_array( $istniejacy ) ? (int) $istniejacy['id'] : 0;

		/*
		 * Numer zajęty przez INNY lead to anomalia po stronie modułu ofertowego
		 * (dwa leady z tym samym numerem oferty). Nie przepisujemy wtedy cudzego
		 * wiersza na siebie — po takiej „naprawie" nie dałoby się już odtworzyć,
		 * do kogo oferta należała naprawdę.
		 */
		if ( $row_id > 0 && (int) $istniejacy['lead_id'] !== $lead_id ) {
			return 0;
		}

		if ( $row_id > 0 ) {
			$wpdb->update( $offers, $dane, array( 'id' => $row_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			return $row_id;
		}

		$dane['created_at'] = $now;
		$wpdb->insert( $offers, $dane ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * Status oferty ze zdarzenia.
	 *
	 * @param array $payload Dane oferty.
	 * @return string
	 */
	private static function status( array $payload ) {
		$status = isset( $payload['status'] ) ? sanitize_key( (string) $payload['status'] ) : '';

		return '' !== $status ? $status : 'draft';
	}

	/**
	 * Waluta ze zdarzenia.
	 *
	 * @param array $payload Dane oferty.
	 * @return string
	 */
	private static function currency( array $payload ) {
		$currency = isset( $payload['currency'] ) ? strtoupper( sanitize_text_field( (string) $payload['currency'] ) ) : '';

		return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'PLN';
	}

	/**
	 * Kwota brutto w jednostkach głównych.
	 *
	 * Moduł ofertowy liczy w groszach (liczby całkowite — bez błędów
	 * zaokrąglenia), a BD-3 trzyma `decimal(12,2)`. Przeliczenie jest tutaj, przy
	 * granicy modułów, a nie w bazie.
	 *
	 * @param array $payload Dane oferty.
	 * @return string
	 */
	private static function amount( array $payload ) {
		if ( isset( $payload['gross_grosze'] ) && is_numeric( $payload['gross_grosze'] ) ) {
			return number_format( ( (int) $payload['gross_grosze'] ) / 100, 2, '.', '' );
		}

		if ( isset( $payload['total_amount'] ) && is_numeric( $payload['total_amount'] ) ) {
			return number_format( (float) $payload['total_amount'], 2, '.', '' );
		}

		return '0.00';
	}
}
