<?php
/**
 * Endpoint AJAX wtyczki MP Lead Intake — realizacja zasady "1 AJAX".
 *
 * Jedno wywołanie: zbiera dane z formularza, buduje kontekst i uruchamia CAŁY
 * pipeline (11 działów) przez MP_Pipeline_Factory. Walidacja, CSRF (nonce),
 * antyspam i rate limit dzieją się WEWNĄTRZ pipeline (działy 2 i 5).
 *
 * Oficjalne API: add_action wp_ajax_* / wp_ajax_nopriv_*
 *   https://developer.wordpress.org/reference/hooks/wp_ajax_action/
 *   wp_send_json_success / wp_send_json_error.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Obsługa żądania AJAX.
 */
class MP_Lead_Intake_Ajax {

	/** Nazwa akcji AJAX. */
	const ACTION = 'mp_lead_intake_submit';

	/**
	 * Rejestruje akcje AJAX (zalogowani i niezalogowani — formularz publiczny).
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Obsługuje zgłoszenie: buduje kontekst i uruchamia pipeline.
	 *
	 * @return void
	 */
	public static function handle() {
		// Dane wejściowe (nonce/antyspam/rate-limit weryfikuje pipeline: działy 5 i 2).
		$input = array(
			'company_name'      => isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '',
			'nip'               => isset( $_POST['nip'] ) ? sanitize_text_field( wp_unslash( $_POST['nip'] ) ) : '',
			'email'             => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'phone'             => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'segment'           => isset( $_POST['segment'] ) ? sanitize_text_field( wp_unslash( $_POST['segment'] ) ) : '',
			'consent_marketing' => ! empty( $_POST['consent_marketing'] ),
			'consent_rodo'      => ! empty( $_POST['consent_rodo'] ),
			'mp_hp'             => isset( $_POST['mp_hp'] ) ? sanitize_text_field( wp_unslash( $_POST['mp_hp'] ) ) : '',
			'mp_nonce'          => isset( $_POST['mp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mp_nonce'] ) ) : '',
		);

		$context  = new MP_Context( $input );
		$pipeline = MP_Pipeline_Factory::make();
		$result   = $pipeline->run( $context );

		if ( $result->is_ok() ) {
			$data = $result->get_data();
			wp_send_json_success(
				array(
					'lead_id' => isset( $data['lead_id'] ) ? (int) $data['lead_id'] : null,
					'message' => 'Dziękujemy! Zapytanie zostało zarejestrowane.',
				)
			);
		}

		wp_send_json_error(
			array(
				'code'    => $result->get_code(),
				'message' => 'Nie udało się przetworzyć zgłoszenia. Sprawdź dane i spróbuj ponownie.',
				'errors'  => $result->get_errors(),
			),
			400
		);
	}
}
