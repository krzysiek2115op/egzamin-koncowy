<?php
/**
 * Logger błędów pipeline.
 *
 * Zgodnie z zasadą: gdy Krytyk/Bramka wykryje błąd — STOP + log błędu do BD-3
 * (wp_mp_activity_log) + powiadomienie administratora.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zapisuje błędy pipeline do logu aktywności i powiadamia admina.
 */
class MP_Pipeline_Logger {

	/**
	 * Loguje porażkę działu do BD-3 i powiadamia administratora.
	 *
	 * @param MP_Department $department Dział, w którym wystąpił błąd.
	 * @param MP_Result     $result     Wynik z błędami.
	 * @param MP_Context    $context    Kontekst pipeline.
	 * @return void
	 */
	public function log_failure( MP_Department $department, MP_Result $result, MP_Context $context ) {
		global $wpdb;

		$table = MP_Lead_Intake_DB::activity_log_table();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'lead_id'     => $context->get( 'lead_id' ),
				'action'      => 'pipeline_error',
				'description' => sprintf(
					'Błąd w dziale %d (%s), kod: %s',
					$department->get_number(),
					$department->get_key(),
					$result->get_code()
				),
				'user_id'     => get_current_user_id() ? get_current_user_id() : null,
				'ip_address'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
				'meta_json'   => wp_json_encode(
					array(
						'department' => $department->get_number(),
						'code'       => $result->get_code(),
						'errors'     => $result->get_errors(),
						'data'       => $result->get_data(),
					)
				),
			)
		);

		$this->notify_admin( $department, $result );
	}

	/**
	 * Powiadomienie administratora o błędzie (e-mail), z ograniczeniem
	 * częstotliwości (max 1 wiadomość na 15 minut na dany dział), by nie spamować.
	 *
	 * Oficjalne API: wp_mail() https://developer.wordpress.org/reference/functions/wp_mail/
	 *
	 * @param MP_Department $department Dział.
	 * @param MP_Result     $result     Wynik.
	 * @return void
	 */
	protected function notify_admin( MP_Department $department, MP_Result $result ) {
		$throttle_key = 'mp_notify_' . $department->get_key();
		if ( get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, 15 * MINUTE_IN_SECONDS );

		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}

		$subject = sprintf( '[MP Lead Intake] Błąd w dziale %d (%s)', $department->get_number(), $department->get_key() );
		$body    = sprintf(
			"Pipeline zatrzymany w dziale %d (%s).\nKod: %s\nBłędy: %s\n",
			$department->get_number(),
			$department->get_key(),
			$result->get_code(),
			wp_json_encode( $result->get_errors() )
		);

		wp_mail( $to, $subject, $body );
	}
}
