<?php
/**
 * Punkt wejscia AJAX — jedno zadanie na akcje uzytkownika (zasada 1 AJAX).
 *
 * Odpowiedz wychodzi wylacznie przez wp_send_json_*, wiec zadne echo ani HTML
 * nie moze sie do niej doklejic. Kod HTTP bierze sie z decyzji krytykow
 * (403 / 409 / 422), a nie z odgadywania po tresci komunikatu.
 *
 * Zrodlo (Golden Rule #2): docs/dzial-01/brama-i-kontrakt-zdarzenia.md
 * (nonce, current_user_can) oraz docs/dzial-09/wyjscie-i-uruchomienie-kolejki.md
 * (wp_send_json_success).
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Obsluga zadan AJAX.
 */
class MP_SW_Ajax {

	/** Akcja AJAX (admin-ajax.php?action=...). */
	const ACTION = 'mp_sw_event';

	/**
	 * Wpina punkt wejścia.
	 *
	 * Rejestrujemy WYŁĄCZNIE wariant dla zalogowanych. `wp_ajax_nopriv_`
	 * wystawiłoby proces sprzedażowy anonimowemu żądaniu — nonce sam tego nie
	 * zatrzyma, bo gość również dostaje ważny nonce.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Obsługuje żądanie i kończy je odpowiedzią JSON.
	 *
	 * @return void
	 */
	public static function handle() {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, MP_SW_D1::NONCE_ACTION ) ) {
			// 403, nie 400: żądanie jest zrozumiałe, tylko nieuprawnione.
			wp_send_json_error(
				array(
					'ok'      => false,
					'code'    => 'bad_nonce',
					'message' => __( 'Nieaktualny token żądania — odśwież stronę i spróbuj ponownie.', 'mp-sales-workflow' ),
				),
				403
			);
		}

		if ( ! current_user_can( MP_SW_Roles::CAP_HANDLE_EVENT ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'code'    => 'forbidden',
					'message' => __( 'Brak uprawnień do obsługi procesów sprzedażowych.', 'mp-sales-workflow' ),
				),
				403
			);
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		/*
		 * Nonce idzie DALEJ, do koperty. Sprawdzenie powyżej odcina żądanie od
		 * razu, ale krytyk K1.2 sprawdza je jeszcze raz na swoim poziomie — i to
		 * on jest jedynym miejscem, które o tym decyduje dla wszystkich wywołań
		 * ręcznych, także tych spoza tej klasy.
		 */
		$envelope          = self::envelope();
		$envelope['nonce'] = $nonce;

		$dispatched = MP_SW_Events::dispatch( $type, $envelope, MP_SW_D1::SOURCE_MANUAL );
		$status     = MP_SW_Events::http_status( $dispatched['result'] );
		$payload    = MP_SW_Events::payload( $dispatched['result'], $dispatched['context'] );

		if ( $dispatched['result']->is_ok() ) {
			wp_send_json_success( $payload, $status );
		}

		wp_send_json_error( $payload, $status );
	}

	/**
	 * Buduje kopertę z danych żądania.
	 *
	 * Surowe wartości NIE są tu weryfikowane pod kątem sensu — od tego jest
	 * Dział 1 i jego krytyk. Tutaj odbywa się wyłącznie odklejenie slashy i
	 * oczyszczenie typów, żeby dalej szły dane, a nie kod.
	 *
	 * @return array
	 */
	private static function envelope() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce sprawdzony w handle().
		$lead_id  = isset( $_POST['lead_id'] ) ? absint( wp_unslash( $_POST['lead_id'] ) ) : 0;
		$offer_id = isset( $_POST['offer_id'] ) ? absint( wp_unslash( $_POST['offer_id'] ) ) : 0;
		$task_id  = isset( $_POST['task_id'] ) ? absint( wp_unslash( $_POST['task_id'] ) ) : 0;

		$entity = array();

		if ( $lead_id > 0 ) {
			$entity['lead_id'] = $lead_id;
		}

		if ( $offer_id > 0 ) {
			$entity['offer_id'] = $offer_id;
		}

		if ( $task_id > 0 ) {
			$entity['task_id'] = $task_id;
		}

		$envelope = array(
			'entity' => $entity,
			'actor'  => array( 'user_id' => get_current_user_id() ),
			'lang'   => isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'pl',
		);

		if ( isset( $_POST['to_status'] ) ) {
			$envelope['to_status'] = sanitize_text_field( wp_unslash( $_POST['to_status'] ) );
		}

		if ( isset( $_POST['country'] ) ) {
			$envelope['country'] = sanitize_text_field( wp_unslash( $_POST['country'] ) );
		}

		/*
		 * Klucz idempotencji przysyła przeglądarka i nadaje go RAZ, przy budowie
		 * formularza. Dzięki temu dwukrotne kliknięcie „Wyślij ofertę" to dla nas
		 * to samo zdarzenie, a nie dwa — druga próba odbije się o UNIQUE.
		 */
		if ( isset( $_POST['event_id'] ) ) {
			$envelope['event_id'] = sanitize_text_field( wp_unslash( $_POST['event_id'] ) );
		}

		if ( isset( $_POST['scope'] ) ) {
			$envelope['scope'] = sanitize_text_field( wp_unslash( $_POST['scope'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $envelope;
	}
}
