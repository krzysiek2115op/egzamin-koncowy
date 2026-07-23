<?php
/**
 * Endpoint AJAX wtyczki MP Offer Builder — realizacja zasady "1 AJAX".
 *
 * Jedno wywołanie: dekoduje żądanie JSON (wejście tylko JSON — Dział 1),
 * buduje kontekst i uruchamia CAŁY pipeline (11 działów) przez
 * MP_OB_Pipeline_Factory. Wg blueprint/LP2_diagram_wizualny.html (Dział 1,
 * Agent 1.2 „uprawnienie"): wywołanie jest automatyczne (po leadzie, z
 * przyszłej integracji plugin 1) albo ręczne od handlowca — w obu
 * przypadkach wymaga zalogowanego użytkownika z nonce, więc TU rejestrujemy
 * wyłącznie wp_ajax_ (bez _nopriv, w przeciwieństwie do publicznego
 * formularza w plugin 1).
 *
 * Oficjalne API: add_action wp_ajax_*
 *   https://developer.wordpress.org/reference/hooks/wp_ajax_action/
 *   wp_send_json_success / wp_send_json_error.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Obsługa żądania AJAX.
 */
class MP_Offer_Builder_Ajax {

	/** Nazwa akcji AJAX. */
	const ACTION = 'mp_offer_builder_submit';

	/**
	 * Rejestruje akcję AJAX (wyłącznie zalogowani — patrz uzasadnienie wyżej).
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Obsługuje żądanie: buduje kontekst i uruchamia pipeline.
	 *
	 * @return void
	 */
	public static function handle() {
		// CSRF: weryfikacja nonce na wejściu (fail-fast, Dział 1 Agent 1.2 "kto-woła").
		if ( ! check_ajax_referer( 'mp_offer_builder', 'mp_ob_nonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => 'Nieprawidłowy token bezpieczeństwa. Odśwież stronę i spróbuj ponownie.',
				),
				403
			);
		}

		// Wejście TYLKO JSON (Dział 1, zk-label diagramu) — surowe ciało żądania,
		// nie tablica $_POST (kontrakt to zagnieżdżony obiekt client/items, nie
		// płaskie pola formularza jak w plugin 1).
		$raw     = file_get_contents( 'php://input' );
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		// Whitelist kluczy wejścia — pola spoza schematu są jawnie odrzucane
		// (Dział 1, Agent 1.1 "kontrakt"), nie ciche przejście przez pipeline.
		$input = isset( $decoded['input'] ) && is_array( $decoded['input'] ) ? $decoded['input'] : array();

		// Idempotencja: request_id (UUID) na całe żądanie — Dział 1 Agent 1.3.
		// Klient może podać własny (podwójny klik ponawia TEN SAM request_id),
		// inaczej generujemy nowy.
		$request_id = isset( $decoded['request_id'] ) && is_string( $decoded['request_id'] ) && '' !== trim( $decoded['request_id'] )
			? sanitize_text_field( wp_unslash( $decoded['request_id'] ) )
			: wp_generate_uuid4();

		$context = new MP_OB_Context(
			array(
				'client'     => isset( $input['client'] ) && is_array( $input['client'] ) ? $input['client'] : array(),
				'items'      => isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array(),
				'wariant'    => isset( $input['wariant'] ) ? sanitize_text_field( wp_unslash( (string) $input['wariant'] ) ) : '',
				'lang'       => isset( $input['lang'] ) ? sanitize_text_field( wp_unslash( (string) $input['lang'] ) ) : '',
				'request_id' => $request_id,
			)
		);

		$pipeline = MP_OB_Pipeline_Factory::make();

		try {
			$result = $pipeline->run( $context );
		} catch ( \Throwable $e ) {
			// Pipeline już zrobił ROLLBACK/log (patrz MP_OB_Pipeline::run()) — TU tylko
			// gwarantujemy kontrakt "zawsze JSON" wobec wywołującego.
			wp_send_json_error(
				array(
					'code'     => 'processing_failed',
					'message'  => 'Nie udało się zbudować oferty. Spróbuj ponownie.',
					'trace_id' => $request_id,
				),
				500
			);
		}

		if ( $result->is_ok() ) {
			$data = $result->get_data();
			wp_send_json_success(
				array(
					'offer_id'     => isset( $data['offer_id'] ) ? $data['offer_id'] : null,
					'offer_number' => isset( $data['offer_number'] ) ? $data['offer_number'] : null,
					'version'      => isset( $data['version'] ) ? $data['version'] : null,
					'pdf_url'      => isset( $data['pdf_url'] ) ? $data['pdf_url'] : null,
					'status'       => isset( $data['status'] ) ? $data['status'] : null,
					'trace_id'     => $request_id,
				)
			);
		}

		// Bez ujawniania wewnętrznego kodu/pól wywołującemu — generyczny komunikat
		// + trace_id. Szczegóły (kod działu, błędy pól) są w logu BD-2 pod tym
		// samym request_id (Dział 10 Agent 10.3 "dziennik").
		wp_send_json_error(
			array(
				'code'     => 'processing_failed',
				'message'  => 'Nie udało się zbudować oferty. Sprawdź dane i spróbuj ponownie.',
				'trace_id' => $request_id,
			),
			400
		);
	}
}
