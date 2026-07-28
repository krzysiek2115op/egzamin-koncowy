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

	/** Górna granica rozmiaru żądania (32 KB). */
	const MAX_BYTES = 32768;

	/**
	 * Pola, które wolno przysłać.
	 *
	 * Lista jest ZAMKNIĘTA i sprawdzana jawnie, zamiast po cichu ignorować
	 * nadmiar. Milczące pomijanie nieznanych pól brzmi łagodniej, ale kryje
	 * pomyłki: żądanie z literówką w nazwie pola wykonywałoby się „poprawnie",
	 * tylko bez tej wartości — a przy polu `to_status` znaczy to zupełnie inne
	 * przejście niż zamierzone.
	 *
	 * @return string[]
	 */
	public static function allowed_fields() {
		return array(
			'action',
			'_wpnonce',
			'_ajax_nonce',
			'type',
			'lead_id',
			'offer_id',
			'task_id',
			'lang',
			'to_status',
			'country',
			'event_id',
			'scope',
		);
	}

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
			self::refuse( MP_SW_Errors::E_NONCE, 403, array() );
		}

		if ( ! current_user_can( MP_SW_Roles::CAP_HANDLE_EVENT ) ) {
			self::refuse( MP_SW_Errors::E_CAPABILITY, 403, array() );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		/*
		 * MACIERZ POCHODZENIA (I-2) — sprawdzana TUTAJ, na wejściu, a nie dopiero
		 * w pipelinie. Kanał HTTP obsługuje wyłącznie to, co robi człowiek przy
		 * pulpicie. Bez tej kontroli zalogowany handlowiec mógł wysłać
		 * `type=offer.approved` z dowolnym `lead_id` i wypuścić firmowego e-maila
		 * do cudzego klienta — nonce miał własny, uprawnienie własne, a nic nie
		 * wiązało TYPU zdarzenia z kanałem, którym przyszło.
		 */
		if ( ! MP_SW_Origin::allowed( $type, MP_SW_D1::SOURCE_MANUAL ) ) {
			self::refuse(
				MP_SW_Errors::E_ORIGIN,
				403,
				array(
					'type'   => $type,
					'reason' => 'channel',
				)
			);
		}

		$needed = MP_SW_Roles::cap_for_event( $type );

		if ( '' !== $needed && ! current_user_can( $needed ) ) {
			self::refuse(
				MP_SW_Errors::E_CAPABILITY,
				403,
				array(
					'type'   => $type,
					'reason' => 'cap_' . $needed,
				)
			);
		}

		self::guard_payload( $type );

		/*
		 * Nonce idzie DALEJ, do koperty. Sprawdzenie powyżej odcina żądanie od
		 * razu, ale krytyk K1.2 sprawdza je jeszcze raz na swoim poziomie — i to
		 * on jest jedynym miejscem, które o tym decyduje dla wszystkich wywołań
		 * ręcznych, także tych spoza tej klasy.
		 */
		$envelope          = self::envelope();
		$envelope['nonce'] = $nonce;

		$dispatched = MP_SW_Events::from_http( $type, $envelope );
		$status     = MP_SW_Events::http_status( $dispatched['result'] );
		$payload    = MP_SW_Events::payload( $dispatched['result'], $dispatched['context'] );

		if ( $dispatched['result']->is_ok() ) {
			wp_send_json_success( $payload, $status );
		}

		wp_send_json_error( $payload, $status );
	}

	/**
	 * Odmowa: kod ze słownika, ślad w dzienniku technicznym, koniec żądania.
	 *
	 * @param string $code   Kod MP3-Exxx.
	 * @param int    $status Kod HTTP.
	 * @param array  $facts  Dodatkowe identyfikatory do dziennika.
	 * @return void
	 */
	private static function refuse( $code, $status, array $facts ) {
		$trace_id = wp_generate_uuid4();

		MP_SW_Log::security(
			$code,
			array_merge(
				array(
					'code'     => $code,
					'source'   => MP_SW_D1::SOURCE_MANUAL,
					'channel'  => 'http',
					'trace_id' => $trace_id,
					'user_id'  => get_current_user_id(),
					'ip_hash'  => MP_SW_Log::ip_hash(),
				),
				$facts
			)
		);

		wp_send_json_error(
			array(
				'ok'       => false,
				'code'     => $code,
				'message'  => MP_SW_Errors::message( $code ),
				'fields'   => array(),
				'trace_id' => $trace_id,
			),
			(int) $status
		);
	}

	/**
	 * Rozmiar żądania i pola spoza kontraktu.
	 *
	 * @param string $type Typ zdarzenia (do dziennika).
	 * @return void
	 */
	private static function guard_payload( $type ) {
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

		if ( $length > self::MAX_BYTES ) {
			self::refuse(
				MP_SW_Errors::E_TOO_LARGE,
				413,
				array(
					'type'  => $type,
					'count' => $length,
				)
			);
		}

		$allowed = self::allowed_fields();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony w handle().
		foreach ( array_keys( (array) $_POST ) as $field ) {
			if ( ! in_array( (string) $field, $allowed, true ) ) {
				self::refuse(
					MP_SW_Errors::E_UNKNOWN_FIELD,
					422,
					array(
						'type'   => $type,
						'reason' => 'field',
					)
				);
			}
		}
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
