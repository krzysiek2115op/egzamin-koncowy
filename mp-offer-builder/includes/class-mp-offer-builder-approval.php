<?php
/**
 * Zatwierdzenie oferty — domknięcie kroku 4 zlecenia po stronie modułu ofertowego.
 *
 * Zlecenie opisuje krok 4 jako „oferta zatwierdzona → wysyłka do klienta".
 * Wysyłkę, follow-upy i dziennik prowadzi plugin 3 — i od początku NASŁUCHUJE
 * zdarzenia `mp_offer_approved` (patrz `MP_SW_Hooks::HOOK_OFFER_APPROVED`).
 * Nikt go jednak nie wystawiał: pipeline kończył ofertę w statusie `draft`
 * i na tym się urywało. Ten plik dokłada brakujący akt zatwierdzenia.
 *
 * DLACZEGO STATUS ŻYJE TUTAJ, A NIE W PLUGINIE 3. Decyzja architektoniczna B
 * mówi, że statusami PROCESU sprzedażowego (nowy → kontakt → oferta →
 * negocjacje → sprzedaż) włada plugin 3. To zostaje bez zmian. `draft` →
 * `approved` jest czym innym: to cykl życia DOKUMENTU, którego właścicielem
 * jest ten moduł. Rozróżnienie nie jest formalnością — od niego zależy, czy
 * wolno ofertę jeszcze zmienić:
 *
 *  - Dział 1 pipeline'u przyjmuje `offer_id` WYŁĄCZNIE w statusie draft, więc
 *    zatwierdzenie samo z siebie zamyka ofertę na edycję (bez ani jednej
 *    zmiany w pipelinie);
 *  - `MP_Offer_Builder_Lead_Listener::on_lead_verified()` odświeża snapshot VAT
 *    tylko w szkicach — dopiero teraz ma to jak zadziałać, bo wcześniej ŻADNA
 *    oferta nie wychodziła ze stanu draft.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Przejście oferty w stan zatwierdzony i zdarzenie integracyjne dla pluginu 3.
 */
class MP_Offer_Builder_Approval {

	/** Hak integracyjny: oferta zatwierdzona (konsument: plugin 3, plugin 1). */
	const HOOK = 'mp_offer_approved';

	/** Nazwa akcji `admin_post_*` obsługującej przycisk z listy ofert. */
	const ACTION = 'mp_ob_approve_offer';

	/** Parametr komunikatu w adresie powrotnym. */
	const NOTICE_ARG = 'mp_ob_approved';

	/**
	 * Wpina obsługę akcji z panelu.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Zatwierdza ofertę i wystawia `mp_offer_approved` dokładnie raz.
	 *
	 * Warstwa DZIEDZINOWA — bez nonce'a i bez uprawnień roli (to sprawa granicy
	 * HTTP, patrz handle()). Sprawdza natomiast własność oferty, bo ta wynika z
	 * danych, a nie z żądania, i musi obowiązywać każdego wywołującego.
	 *
	 * @param int $offer_id Identyfikator oferty w BD-2.
	 * @param int $user_id  Kto zatwierdza (0 = system).
	 * @return true|WP_Error
	 */
	public static function approve( $offer_id, $user_id = 0 ) {
		global $wpdb;

		$offer_id = (int) $offer_id;
		$user_id  = (int) $user_id;
		$offer    = $offer_id > 0 ? MP_Offer_Builder_DB::get_offer( $offer_id ) : null;

		/*
		 * Nieistniejąca i cudza oferta dają TEN SAM błąd i ten sam komunikat —
		 * inaczej `offer_id` stałby się wyrocznią do wyliczania cudzych ofert
		 * (ten sam wzorzec co Dział 1 pipeline'u i endpoint pobierania PDF).
		 */
		if ( ! $offer || ( $user_id > 0 && ! MP_Offer_Builder_Download::can_download( $offer, $user_id ) ) ) {
			return new WP_Error(
				'offer_not_found',
				__( 'Oferta nie istnieje albo nie masz do niej dostępu.', 'mp-offer-builder' )
			);
		}

		if ( MP_Offer_Builder_DB::STATUS_APPROVED === (string) $offer['status'] ) {
			// Podwójne kliknięcie albo odświeżenie strony. Stan docelowy jest
			// osiągnięty, ale zdarzenie już poszło — drugi raz wystawić go nie wolno.
			return new WP_Error(
				'already_approved',
				__( 'Ta oferta była już zatwierdzona.', 'mp-offer-builder' )
			);
		}

		if ( MP_Offer_Builder_DB::STATUS_DRAFT !== (string) $offer['status'] ) {
			return new WP_Error(
				'wrong_status',
				__( 'Oferta jest w stanie, z którego nie da się jej zatwierdzić.', 'mp-offer-builder' )
			);
		}

		/*
		 * Nie ma czego zatwierdzać, dopóki nie ma dokumentu. Numer i PDF powstają
		 * dopiero w pełnym przebiegu pipeline'u (Działy 8/9) — szkic z samego
		 * zdarzenia `mp_lead_created` ma oba puste. Bez tej kontroli plugin 3
		 * dostałby polecenie „wyślij ofertę klientowi", nie mając pliku do wysłania;
		 * po tamtej stronie kończy się to odmową MP3-E190, czyli błędem zgłoszonym
		 * o jeden moduł za późno.
		 */
		if ( '' === (string) $offer['offer_number'] || '' === (string) $offer['pdf_path'] ) {
			return new WP_Error(
				'no_document',
				__( 'Oferta nie ma jeszcze numeru i pliku PDF — najpierw ją dokończ i wygeneruj dokument.', 'mp-offer-builder' )
			);
		}

		/*
		 * Przejście warunkowe: `WHERE status = 'draft'` w samym UPDATE. Kontrola
		 * wyżej jest tylko po to, żeby dać sensowny komunikat — o tym, KTO wygrał,
		 * decyduje baza. Przy dwóch równoległych żądaniach dokładnie jedno dostanie
		 * 1 zmieniony wiersz, więc zdarzenie wyjdzie dokładnie raz (ta sama zasada,
		 * co wartownik statusu wewnątrz transakcji zapisu w pluginie 3).
		 *
		 * `lock_version` ROŚNIE TAKŻE TUTAJ. Bez tego zatwierdzenie było dla
		 * blokady optymistycznej niewidzialne: Dział 2 czytał `lock_version = N`
		 * i `status = draft`, pipeline liczył i renderował PDF (setki milisekund),
		 * w tym czasie ktoś klikał „Zatwierdź", a Dział 10 zapisywał WHERE
		 * `lock_version = N` — trafiał, cofał status do `draft` i podmieniał plik
		 * JUŻ WYSŁANY klientowi. Oferta wracała na listę jako szkic z aktywnym
		 * przyciskiem, więc drugie kliknięcie wystawiało `mp_offer_approved`
		 * po raz drugi dla tego samego `offer_id` — wprost wbrew gwarancji
		 * „dokładnie raz", której plugin 3 pilnuje po swojej stronie.
		 */
		$changed = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MP_Offer_Builder_DB::offers_table(),
			array(
				'status'       => MP_Offer_Builder_DB::STATUS_APPROVED,
				'lock_version' => (int) $offer['lock_version'] + 1,
				'updated_at'   => current_time( 'mysql' ),
			),
			array(
				'id'     => $offer_id,
				'status' => MP_Offer_Builder_DB::STATUS_DRAFT,
			)
		);

		if ( 1 !== (int) $changed ) {
			return new WP_Error(
				'already_approved',
				__( 'Ta oferta była już zatwierdzona.', 'mp-offer-builder' )
			);
		}

		$lead_id = isset( $offer['lead_id'] ) ? (int) $offer['lead_id'] : 0;

		// Dziennik BD-2 przed zdarzeniem: subskrybent może paść, a ślad po
		// zatwierdzeniu ma zostać w każdym przypadku (kryt. 5.5 — historia
		// odtwarzalna z samego dziennika).
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MP_Offer_Builder_DB::activity_log_table(),
			array(
				'offer_id'    => $offer_id,
				'action'      => 'offer_approved',
				'description' => sprintf(
					/* translators: 1: numer oferty, 2: identyfikator leada. */
					__( 'Oferta %1$s zatwierdzona (lead_id=%2$d) — zdarzenie mp_offer_approved wystawione.', 'mp-offer-builder' ),
					(string) $offer['offer_number'],
					$lead_id
				),
				'user_id'     => $user_id > 0 ? $user_id : null,
				'meta_json'   => wp_json_encode(
					array(
						'lead_id'      => $lead_id,
						'offer_number' => (string) $offer['offer_number'],
						'version'      => (int) $offer['version'],
					)
				),
			)
		);

		/*
		 * Kontrakt zdarzenia jest lustrem `mp_offer_created` (Dział 11) — ten sam
		 * zestaw kluczy, inny status. Odbiorcy: plugin 3 (proces sprzedażowy, czyta
		 * `lead_id`) i plugin 1 (wskaźnik oferty w BD-3, czyta numer, status, kwotę
		 * i walutę). Zero w `lead_id` oznacza ofertę zbudowaną ręcznie, bez leada.
		 */
		do_action(
			self::HOOK,
			$offer_id,
			array(
				'offer_id'     => $offer_id,
				'offer_number' => (string) $offer['offer_number'],
				'version'      => (int) $offer['version'],
				'status'       => MP_Offer_Builder_DB::STATUS_APPROVED,
				'client_name'  => (string) $offer['client_name'],
				'gross_grosze' => (int) $offer['gross_grosze'],
				'currency'     => (string) $offer['currency'],
				'lead_id'      => $lead_id,
				'approved_by'  => $user_id,
			)
		);

		return true;
	}

	/**
	 * Granica HTTP: token CSRF → uprawnienie → operacja → powrót na listę.
	 *
	 * @return void
	 */
	public static function handle() {
		$offer_id = isset( $_REQUEST['offer_id'] ) ? absint( $_REQUEST['offer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::ACTION . '_' . $offer_id );

		if ( ! current_user_can( MP_OB_D1_Agent_Permission::CAPABILITY ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'mp-offer-builder' ), '', array( 'response' => 403 ) );
		}

		$result = self::approve( $offer_id, get_current_user_id() );
		$code   = is_wp_error( $result ) ? $result->get_error_code() : 'ok';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => MP_Offer_Builder_Admin::PAGE_SLUG,
					self::NOTICE_ARG => $code,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Komunikat po powrocie z akcji zatwierdzania.
	 *
	 * @return void
	 */
	public static function notice() {
		if ( empty( $_GET[ self::NOTICE_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'ok'               => array( 'success', __( 'Oferta zatwierdzona. Moduł sprzedażowy przejmuje wysyłkę do klienta.', 'mp-offer-builder' ) ),
			'already_approved' => array( 'info', __( 'Ta oferta była już zatwierdzona — nic się nie zmieniło.', 'mp-offer-builder' ) ),
			'no_document'      => array( 'error', __( 'Oferta nie ma jeszcze numeru i pliku PDF — najpierw ją dokończ i wygeneruj dokument.', 'mp-offer-builder' ) ),
			'offer_not_found'  => array( 'error', __( 'Oferta nie istnieje albo nie masz do niej dostępu.', 'mp-offer-builder' ) ),
			'wrong_status'     => array( 'error', __( 'Oferta jest w stanie, z którego nie da się jej zatwierdzić.', 'mp-offer-builder' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Adres akcji zatwierdzania dla danej oferty (token CSRF per-oferta).
	 *
	 * Odnośnik, a nie formularz POST, bo lista ofert jest już opakowana w
	 * `<form method="get">` (wyszukiwarka WP_List_Table) — formularz w formularzu
	 * to nieprawidłowy HTML i przeglądarka po prostu zignorowałaby wewnętrzny.
	 * Tym samym wzorcem działają akcje wierszy w rdzeniu WordPressa; przed
	 * fałszywym żądaniem broni nonce sprawdzany w handle().
	 *
	 * @param int $offer_id Identyfikator oferty.
	 * @return string
	 */
	public static function action_url( $offer_id ) {
		$offer_id = (int) $offer_id;

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'offer_id' => $offer_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $offer_id
		);
	}
}
