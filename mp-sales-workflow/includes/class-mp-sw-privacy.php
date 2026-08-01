<?php
/**
 * Dane osobowe w LP.3 — minimalizacja i propagacja prawa do usuniecia.
 *
 * LP.3 przechowuje adres e-mail w dwoch miejscach i tylko dwoch: w wierszu
 * procesu (`client_email`, zeby dalo sie zbudowac powiadomienie bez siegania
 * do LP.1) oraz w kolejce powiadomien (`recipient`, zeby dalo sie PONOWIC
 * wysylke, ktora sie nie udala). Dziennik z kryterium 5.5 adresu nie zawiera —
 * odnotowuje identyfikator powiadomienia, bo do odtworzenia historii wysylek
 * wystarczy wiedziec, KTORE powiadomienie poszlo, a nie do kogo.
 *
 * Usuniecie danych musi przejsc przez wszystkie trzy wtyczki, inaczej "prawo do
 * bycia zapomnianym" konczy sie na pierwszej z nich. Wiersze ZOSTAJA, zmienia
 * sie tylko adres — spojnie z LP.1 i z wymogiem 5.5: skasowanie wierszy
 * zniszczyloby historie procesu, ktora jest przedmiotem odbioru.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anonimizacja danych klienta.
 */
class MP_SW_Privacy {

	/** Hak anonimizacji wystawiany przez LP.1. */
	const HOOK_ANONYMIZED = 'mp_lead_anonymized';

	/** Wzorzec adresu zastepczego (spojny z LP.1). */
	const PATTERN = 'deleted+%d@invalid';

	/**
	 * Czy adres jest już zastępczym adresem po anonimizacji.
	 *
	 * Jedno miejsce na tę wiedzę, bo pyta o nią także Dział 7: zanonimizowany
	 * wiersz procesu nie może zostać nadpisany świeżym odczytem z tabeli leadów
	 * wtyczki 1 — inaczej usunięty kontakt wracałby przy kolejnej wysyłce.
	 *
	 * Rozpoznajemy po domenie `@invalid` (RFC 2606 — zarezerwowana, nigdy nie
	 * istnieje realnie), a nie po pełnym wzorcu z identyfikatorem: ten sam adres
	 * zastępczy bywa kopiowany między wierszami przy migracjach.
	 *
	 * @param string $email Adres do sprawdzenia.
	 * @return bool
	 */
	public static function is_anonymized( $email ) {
		$email = strtolower( trim( (string) $email ) );

		if ( '' === $email ) {
			return false;
		}

		return (bool) preg_match( '/@invalid$/', $email );
	}

	/**
	 * Wpina nasluch.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK_ANONYMIZED, array( __CLASS__, 'on_lead_anonymized' ), 10, 1 );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Podpina sie pod EKSPORTER danych osobowych WordPressa.
	 *
	 * Dotad wpiety byl sam kasownik. LP.1 i LP.2 wpinaja oba filtry, wiec zadanie
	 * „Eksportuj dane osobowe" z Narzedzi oddawalo komplet z dwoch wtyczek
	 * i CISZE z trzeciej — bez bledu i bez ostrzezenia, wiec raport wygladal na
	 * kompletny. RODO daje prawo dostepu (art. 15) i przenoszenia (art. 20) obok
	 * prawa do usuniecia (art. 17); obsluzenie samego usuwania zalatwia jedno
	 * z trzech. Ta wtyczka nigdy nie twierdzila, ze jej dane nie sa osobowe —
	 * ma kasownik, anonimizacje i `is_anonymized()`.
	 *
	 * @param array $exporters Zarejestrowane eksportery.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['mp-sales-workflow'] = array(
			'exporter_friendly_name' => __( 'Procesy sprzedażowe MP', 'mp-sales-workflow' ),
			'callback'               => array( __CLASS__, 'export_by_email' ),
		);

		return (array) $exporters;
	}

	/**
	 * Eksporter rdzenia: wydaje dane podmiotu powiazane z adresem.
	 *
	 * Zasieg wynika wprost z naglowka tego pliku: LP.3 trzyma adres w DWOCH
	 * miejscach i tylko dwoch — w wierszu procesu (`client_email`) i w kolejce
	 * powiadomien (`recipient`). Dziennik aktywnosci adresu nie zawiera, wiec nie
	 * ma tu czego eksportowac. Eksport wezszy o jedno z tych miejsc bylby gorszy
	 * niz jego brak: wygladalby na kompletny.
	 *
	 * Zapytania wiaza po ADRESIE, nie po `lead_id`, bo zadanie RODO sklada sie
	 * po zweryfikowanym adresie — dobranie wierszy „tego samego leada" wydaloby
	 * dane procesow, ktore po anonimizacji naleza juz do nikogo.
	 *
	 * @param string $email Adres e-mail podmiotu danych.
	 * @param int    $page  Numer strony (mechanizm rdzenia).
	 * @return array
	 */
	public static function export_by_email( $email, $page = 1 ) {
		global $wpdb;

		$dane = array();

		// Komplet miesci sie na jednej stronie: proces na lead jest jeden
		// (UNIQUE `uq_lead`), a powiadomien do jednego adresu sa jednostki.
		if ( 1 !== (int) $page ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$flow_table = MP_Sales_Workflow_DB::flow_table();
		$procesy    = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, lead_id, offer_number, status, segment, lang, client_name, client_email, created_at FROM {$flow_table} WHERE client_email = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $email
			),
			ARRAY_A
		);

		foreach ( $procesy as $proces ) {
			$dane[] = array(
				'group_id'    => 'mp-sales-workflow-flow',
				'group_label' => __( 'Procesy sprzedażowe (MP Sales Workflow)', 'mp-sales-workflow' ),
				'item_id'     => 'mp-sw-flow-' . (int) $proces['id'],
				'data'        => array(
					array(
						'name'  => __( 'Numer oferty', 'mp-sales-workflow' ),
						'value' => (string) $proces['offer_number'],
					),
					array(
						'name'  => __( 'Nazwa klienta', 'mp-sales-workflow' ),
						'value' => (string) $proces['client_name'],
					),
					array(
						'name'  => __( 'E-mail', 'mp-sales-workflow' ),
						'value' => (string) $proces['client_email'],
					),
					array(
						'name'  => __( 'Status procesu', 'mp-sales-workflow' ),
						'value' => (string) $proces['status'],
					),
					array(
						'name'  => __( 'Segment', 'mp-sales-workflow' ),
						'value' => (string) $proces['segment'],
					),
					array(
						'name'  => __( 'Język korespondencji', 'mp-sales-workflow' ),
						'value' => (string) $proces['lang'],
					),
					array(
						'name'  => __( 'Utworzono (GMT)', 'mp-sales-workflow' ),
						'value' => (string) $proces['created_at'],
					),
				),
			);
		}

		$noti_table    = MP_Sales_Workflow_DB::notifications_table();
		$powiadomienia = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, template, recipient, subject, status, created_at FROM {$noti_table} WHERE recipient = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $email
			),
			ARRAY_A
		);

		foreach ( $powiadomienia as $powiadomienie ) {
			$dane[] = array(
				'group_id'    => 'mp-sales-workflow-notifications',
				'group_label' => __( 'Powiadomienia e-mail (MP Sales Workflow)', 'mp-sales-workflow' ),
				'item_id'     => 'mp-sw-notification-' . (int) $powiadomienie['id'],
				'data'        => array(
					array(
						'name'  => __( 'Rodzaj powiadomienia', 'mp-sales-workflow' ),
						'value' => (string) $powiadomienie['template'],
					),
					array(
						'name'  => __( 'Adresat', 'mp-sales-workflow' ),
						'value' => (string) $powiadomienie['recipient'],
					),
					array(
						'name'  => __( 'Temat', 'mp-sales-workflow' ),
						'value' => (string) $powiadomienie['subject'],
					),
					array(
						'name'  => __( 'Status wysyłki', 'mp-sales-workflow' ),
						'value' => (string) $powiadomienie['status'],
					),
					array(
						'name'  => __( 'Utworzono (GMT)', 'mp-sales-workflow' ),
						'value' => (string) $powiadomienie['created_at'],
					),
				),
			);
		}

		return array(
			'data' => $dane,
			'done' => true,
		);
	}

	/**
	 * LP.1 zanonimizowala leada — robimy to samo u siebie.
	 *
	 * @param int $lead_id Identyfikator leada.
	 * @return int Liczba zmienionych wierszy.
	 */
	public static function on_lead_anonymized( $lead_id ) {
		return self::anonymize_lead( (int) $lead_id );
	}

	/**
	 * Zastepuje adres klienta w procesie i w kolejce.
	 *
	 * @param int $lead_id Identyfikator leada.
	 * @return int Liczba zmienionych wierszy.
	 */
	public static function anonymize_lead( $lead_id ) {
		global $wpdb;

		$lead_id = (int) $lead_id;

		if ( $lead_id < 1 ) {
			return 0;
		}

		$flow_table = MP_Sales_Workflow_DB::flow_table();
		$flow_id    = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id FROM {$flow_table} WHERE lead_id = %d LIMIT 1", $lead_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $flow_id < 1 ) {
			return 0;
		}

		$placeholder = sprintf( self::PATTERN, $lead_id );
		$changed     = 0;

		$changed += (int) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$flow_table,
			array(
				'client_email' => $placeholder,
				'client_name'  => '',
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $flow_id )
		);

		/*
		 * Kolejka: czyscimy adres, temat i tresc, ale zostawiamy wiersze ze
		 * statusem, licznikiem prob i wersja szablonu. Dzieki temu na pytanie
		 * „czy klient dostal oferte nr X" nadal da sie odpowiedziec — numer oferty
		 * stoi w wierszu procesu, a powiadomienia wiaza sie z nim przez `flow_id`;
		 * adres, nazwa firmy w temacie i pelna tresc do odpowiedzi nie sa potrzebne.
		 *
		 * WARUNEK PO `recipient_user_id IS NULL`, a nie po „audience".
		 * Kolumny `audience` NIGDY NIE BYLO w DDL — istnieje wylacznie w tablicy
		 * wiadomosci w pamieci Dzialu 7. `$wpdb->update()` z takim warunkiem padalo
		 * po cichu (`false` -> `(int) false` -> 0), wiec zadanie usuniecia danych
		 * konczylo sie „sukcesem", a adres klienta zostawal w kolejce razem z
		 * nazwa firmy i cala trescia wiadomosci. `recipient_user_id` jest NULL
		 * dokladnie dla klienta — pracownicy maja tam swoje konto WordPressa.
		 *
		 * Musi to byc zapytanie wprost: `$wpdb->update()` nie potrafi wyrazic
		 * `IS NULL` w warunku.
		 */
		$notifications = MP_Sales_Workflow_DB::notifications_table();

		$changed += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$notifications} SET recipient = %s, subject = '', body = '', updated_at = %s WHERE flow_id = %d AND recipient_user_id IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$placeholder,
				current_time( 'mysql', true ),
				$flow_id
			)
		);

		/*
		 * Powiadomienia WEWNETRZNE (do handlowca) tez nios dane klienta: szablony
		 * podstawiaja {{client_name}} i w temat, i w tresc — „Nowy lead: <nazwa>",
		 * „Zmiana statusu: <nazwa>", „Przypomnienie: <nazwa>". Warunek powyzej
		 * omija te wiersze, bo maja w `recipient_user_id` konto WordPressa, wiec
		 * nazwa firmy klienta zostawala w bazie NA ZAWSZE — tabela powiadomien
		 * nie ma retencji, a zadanie usuniecia danych konczylo sie „sukcesem".
		 *
		 * Adresu pracownika NIE ruszamy: to dane firmowe, nie dane klienta,
		 * a wiersz ma zostac sladem wysylki (kryt. 5.5 — historia zostaje).
		 */
		$changed += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$notifications} SET subject = '', body = '', updated_at = %s WHERE flow_id = %d AND recipient_user_id IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$flow_id
			)
		);

		MP_SW_Log::notice(
			'MP3-E000',
			array(
				'reason'  => 'anonymized',
				'lead_id' => $lead_id,
				'flow_id' => $flow_id,
				'count'   => $changed,
			)
		);

		/**
		 * Proces sprzedazowy zanonimizowany.
		 *
		 * @param int $lead_id Identyfikator leada.
		 * @param int $flow_id Identyfikator procesu.
		 */
		do_action( 'mp_sw_flow_anonymized', $lead_id, $flow_id );

		return $changed;
	}

	/**
	 * Podpina sie pod eksporter/kasownik danych osobowych WordPressa.
	 *
	 * LP.1 na dzis NIE wystawia jeszcze haka `mp_lead_anonymized`, wiec sam
	 * nasluch bylby kontraktem na przyszlosc i niczym wiecej. Rejestracja w
	 * mechanizmie rdzenia sprawia, ze zadanie usuniecia danych zlozone przez
	 * administratora dziala JUZ TERAZ, bez czekania na tamta wtyczke.
	 *
	 * @param array $erasers Zarejestrowane kasowniki.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['mp-sales-workflow'] = array(
			'eraser_friendly_name' => __( 'Procesy sprzedażowe MP', 'mp-sales-workflow' ),
			'callback'             => array( __CLASS__, 'erase_by_email' ),
		);

		return (array) $erasers;
	}

	/**
	 * Kasownik rdzenia: anonimizuje procesy powiazane z adresem.
	 *
	 * @param string $email Adres e-mail.
	 * @param int    $page  Numer strony (mechanizm rdzenia).
	 * @return array
	 */
	public static function erase_by_email( $email, $page = 1 ) {
		// Rdzen stronicuje kasowanie i podaje numer strony; my konczymy prace za
		// jednym razem i zwracamy `done => true`, wiec numer nas nie interesuje.
		unset( $page );

		global $wpdb;

		$flow_table = MP_Sales_Workflow_DB::flow_table();
		$leads      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT lead_id FROM {$flow_table} WHERE client_email = %s", (string) $email ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$removed = false;

		foreach ( (array) $leads as $lead_id ) {
			if ( self::anonymize_lead( (int) $lead_id ) > 0 ) {
				$removed = true;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
