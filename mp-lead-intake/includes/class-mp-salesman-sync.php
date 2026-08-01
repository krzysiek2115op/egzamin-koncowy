<?php
/**
 * Handlowiec przy leadzie — nadążanie za decyzją wtyczki 3.
 *
 * Kolumna `wp_mp_leads.salesman_id` należy do BD-3, ale WYBÓR handlowca do niej
 * nie należy. Zlecenie umieszcza „przypisanie handlowca" w zakresie wtyczki 3,
 * a BD-1 opisuje jako „logiczne powiązanie użytkownika z krajem, zespołem,
 * językiem i zakresem obsługi" — to tam leży cała wiedza potrzebna do decyzji.
 *
 * Wtyczka 1 uczestniczy w tym dwa razy i za każdym razem jako PYTAJĄCA:
 *
 *  1. Przy zakładaniu leada Dział 7 wystawia filtr `mp_lead_assign_salesman`.
 *     Odpowiedź wtyczki 3 idzie do kolumny i do koperty `mp_lead_created`,
 *     z której wtyczka 2 bierze właściciela szkicu oferty. Wszystkie trzy bazy
 *     dostają więc tego samego człowieka z JEDNEGO doboru.
 *  2. Później właściciel procesu potrafi się zmienić — rotacja w Dziale 4
 *     wtyczki 3, przepisanie przez managera. Ta klasa nasłuchuje wtedy
 *     `mp_sw_flow_updated` i dociąga zmianę do BD-3.
 *
 * DLACZEGO ZAPISUJE TO WTYCZKA 1, A NIE 3. Ta sama zasada, na której stoi
 * rejestr ofert (class-mp-offer-registry.php): żaden moduł nie pisze do cudzych
 * tabel, granice biegną po zdarzeniach. Wtyczka 3 ogłasza swoją decyzję i nie
 * musi wiedzieć, że BD-3 w ogóle istnieje.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nasłuch decyzji o właścicielu procesu sprzedażowego.
 */
class MP_Lead_Intake_Salesman_Sync {

	/** Zdarzenie wtyczki 3: proces zapisany (kontrakt Działu 9). */
	const HOOK_FLOW_UPDATED = 'mp_sw_flow_updated';

	/**
	 * Wpina nasłuch.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK_FLOW_UPDATED, array( __CLASS__, 'handle_flow_event' ), 10, 1 );
	}

	/**
	 * Osłona nasłuchu: żaden nasz wyjątek nie wychodzi do wtyczki 3.
	 *
	 * WordPress nie izoluje subskrybentów. Wyjątek rzucony tutaj poleciałby
	 * przez `do_action()` w pipeline wtyczki 3 — a to zdarzenie wychodzi już PO
	 * zatwierdzeniu zapisu procesu, więc wywróciłby obsługę czegoś, co się
	 * powiodło. Aktualizacja kolumny przy leadzie jest rzeczą pomocniczą i nie
	 * ma prawa zaszkodzić rzeczy głównej.
	 *
	 * @param array $payload Dane zdarzenia.
	 * @return bool Czy kolumna została zaktualizowana.
	 */
	public static function handle_flow_event( $payload = array() ) {
		try {
			return self::on_flow_event( $payload );
		} catch ( \Throwable $e ) {
			global $wpdb;

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				MP_Lead_Intake_DB::activity_log_table(),
				array(
					'lead_id'     => isset( $payload['lead_id'] ) ? absint( $payload['lead_id'] ) : null,
					'action'      => 'salesman_sync_exception',
					'description' => sprintf( 'Wyjątek przy synchronizacji handlowca: %s', $e->getMessage() ),
					'created_at'  => current_time( 'mysql', true ),
				)
			);

			return false;
		}
	}

	/**
	 * Przepisuje właściciela procesu do kolumny przy leadzie.
	 *
	 * @param array $payload Dane zdarzenia `mp_sw_flow_updated`.
	 * @return bool Czy kolumna została zaktualizowana.
	 */
	public static function on_flow_event( $payload = array() ) {
		global $wpdb;

		$payload = is_array( $payload ) ? $payload : array();
		$lead_id = isset( $payload['lead_id'] ) ? absint( $payload['lead_id'] ) : 0;
		$owner   = isset( $payload['assigned_user_id'] ) ? absint( $payload['assigned_user_id'] ) : 0;

		/*
		 * Zdarzenie BEZ właściciela nie znaczy „odbierz handlowca". Przez ten hak
		 * przechodzą także zapisy niedotyczące przypisania (zmiana statusu, cron,
		 * zamknięcie zadania), a wtedy pole po prostu nie niesie decyzji. Czyszczenie
		 * kolumny na taki sygnał kasowałoby prawdziwe przypisanie z powodu milczenia.
		 */
		if ( $lead_id < 1 || $owner < 1 ) {
			return false;
		}

		$table = MP_Lead_Intake_DB::leads_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id, salesman_id FROM {$table} WHERE id = %d", $lead_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		/*
		 * Brak wiersza to normalny stan, nie awaria: wtyczka 3 obsługuje też
		 * procesy zakładane poza formularzem (panel, import), których w BD-3
		 * nigdy nie było.
		 */
		if ( ! is_array( $row ) ) {
			return false;
		}

		// Ten sam człowiek co poprzednio — zapis niczego by nie zmienił poza
		// znacznikiem czasu, a ten ma opisywać PRZYPISANIE, nie ostatnie zdarzenie.
		if ( (int) $row['salesman_id'] === $owner ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'salesman_id'          => $owner,
				// GMT, jak każda inna kolumna datetime w tej tabeli (patrz class-mp-db.php).
				'salesman_assigned_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $lead_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return (bool) $updated;
	}
}
