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

		// Uprawnienie na wejściu (fail-fast, defense-in-depth) — spójnie z
		// ajax_search_products()/download; pipeline (Dział 1, Agent 1.2) sprawdza je
		// ponownie, ale nie chcemy nawet uruchamiać pre-gate/odczytu BD bez capability.
		if ( ! current_user_can( MP_OB_D1_Agent_Permission::CAPABILITY ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => 'Brak uprawnień do budowania ofert.',
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

		// Pre-gate idempotencji (analogicznie do rate-limitu w pluginie 1, fail-fast
		// PRZED pipeline): "ten sam request_id nigdy nie tworzy drugiej oferty" — jeśli
		// oferta z tym request_id już istnieje, zwracamy JEJ dane zamiast ponownie
		// uruchamiać cały pipeline (podwójny klik = ta sama odpowiedź).
		$existing = MP_Offer_Builder_DB::get_offer_by_request_id( $request_id );
		if ( $existing ) {
			// Ta sama kontrola własności co reszta pipeline'u (Dział 1/10) — request_id
			// trafia do klienta jako trace_id (Dział 11), więc BEZ tej kontroli podanie
			// cudzego request_id ujawniłoby metadane cudzej oferty (IDOR).
			$existing_owner = isset( $existing['created_by'] ) && null !== $existing['created_by'] ? (int) $existing['created_by'] : null;
			if ( null !== $existing_owner && get_current_user_id() !== $existing_owner && ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'code'     => 'forbidden',
						'message'  => 'Brak dostępu do wskazanego żądania.',
						'trace_id' => $request_id,
					),
					403
				);
			}
			wp_send_json_success(
				array(
					'offer_id'     => (int) $existing['id'],
					'offer_number' => $existing['offer_number'],
					'version'      => (int) $existing['version'],
					'pdf_url'      => null,
					'status'       => $existing['status'],
					'trace_id'     => $request_id,
				)
			);
		}

		// offer_id (opcjonalny): dokończenie istniejącego draftu z Kroku 2.5
		// (MP_Offer_Builder_Lead_Listener) zamiast nowej oferty od zera.
		$offer_id = isset( $decoded['offer_id'] ) ? absint( $decoded['offer_id'] ) : 0;

		$context = new MP_OB_Context(
			array(
				'offer_id'   => $offer_id > 0 ? $offer_id : null,
				'client'     => self::sanitize_client( isset( $input['client'] ) && is_array( $input['client'] ) ? $input['client'] : array() ),
				'items'      => self::sanitize_items( isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array() ),
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
			self::http_status_for_code( $result->get_code() )
		);
	}

	/**
	 * Sanityzuje zagnieżdżone pola client.* NA GRANICY systemu — wejście to
	 * surowy JSON (nie $_POST), więc żaden mechanizm WP nie dotyka go wcześniej.
	 * Dział 1 i tak whitelistuje/waliduje te same klucze niezależnie (defense-
	 * in-depth), a Dział 7 escapuje je ponownie przy renderze HTML — ale bez
	 * sanityzacji TUTAJ surowe sterujące znaki/HTML trafiałyby niepotrzebnie
	 * do BD-2 i dalej w głąb pipeline'u.
	 *
	 * @param array $raw Surowe dane client.* z żądania.
	 * @return array
	 */
	public static function sanitize_client( array $raw ) {
		$client = array();
		foreach ( array( 'name', 'email', 'nip', 'country', 'vat_status' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				$client[ $key ] = sanitize_text_field( wp_unslash( (string) $raw[ $key ] ) );
			}
		}
		return $client;
	}

	/**
	 * Sanityzuje pozycje (items[]) NA GRANICY systemu — wymusza typ liczbowy
	 * nieujemny na product_id/variation_id/qty PRZED wejściem do pipeline'u
	 * (Dział 1 i tak rzutuje na (int) ponownie, to jest wyłącznie granica).
	 *
	 * @param array $raw Surowa lista pozycji z żądania.
	 * @return array
	 */
	public static function sanitize_items( array $raw ) {
		$items = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$items[] = array(
				'product_id'   => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
				'variation_id' => ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : null,
				'qty'          => isset( $item['qty'] ) ? absint( $item['qty'] ) : 0,
			);
		}
		return $items;
	}

	/**
	 * Mapuje kod błędu wyniku pipeline'u na kod HTTP odpowiedzi.
	 *
	 * Ziarnistość ograniczona przez MP_OB_Department::process(): kod agenta
	 * przetrwa tylko gdy to AGENT zwrócił fail (np. 'invalid_contract',
	 * 'forbidden', 'incomplete' z Działu 1); odrzucenie przez krytyka/bramkę
	 * zawsze daje generyczne 'critic_failed'/'gate_failed' (400).
	 *
	 * @param string $code Kod z MP_OB_Result::get_code().
	 * @return int
	 */
	private static function http_status_for_code( $code ) {
		switch ( $code ) {
			case 'invalid_contract':
			case 'incomplete':
			case 'invalid_request_id':
				return 422;
			case 'forbidden':
				return 403;
			default:
				return 400;
		}
	}
}
