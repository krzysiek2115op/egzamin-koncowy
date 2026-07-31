<?php
/**
 * Logger błędów pipeline.
 *
 * Zgodnie z zasadą: gdy Krytyk/Bramka wykryje błąd — STOP + log błędu do BD-2
 * (wp_mp_ob_offer_activity_log) + powiadomienie administratora.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zapisuje błędy pipeline do logu aktywności i powiadamia administratora.
 */
class MP_OB_Pipeline_Logger {

	/**
	 * Loguje porażkę działu do BD-2 i powiadamia administratora.
	 *
	 * @param MP_OB_Department $department Dział, w którym wystąpił błąd.
	 * @param MP_OB_Result     $result     Wynik z błędami.
	 * @param MP_OB_Context    $context    Kontekst pipeline.
	 * @return void
	 */
	public function log_failure( MP_OB_Department $department, MP_OB_Result $result, MP_OB_Context $context ) {
		global $wpdb;

		$table = MP_Offer_Builder_DB::activity_log_table();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'offer_id'    => $context->get( 'offer_id' ),
				'action'      => 'pipeline_error',
				'description' => sprintf(
					'Błąd w dziale %d (%s), kod: %s',
					$department->get_number(),
					$department->get_key(),
					$result->get_code()
				),
				'user_id'     => get_current_user_id() ? get_current_user_id() : null,
				'meta_json'   => wp_json_encode(
					array(
						'request_id' => $context->get( 'request_id' ),
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
	 * Loguje NIEOCZEKIWANY wyjątek/błąd PHP w trakcie pipeline'u i powiadamia
	 * administratora. W przeciwieństwie do log_failure() (kontrolowany STOP
	 * krytyka/bramki, opisany przez MP_OB_Result) — to ścieżka awaryjna:
	 * Throwable przerwał wykonanie w sposób, którego MP_OB_Result nie mógł opisać.
	 *
	 * @param \Throwable    $e        Przechwycony wyjątek/błąd.
	 * @param MP_OB_Context $context  Kontekst pipeline w chwili awarii.
	 * @param int           $dept_num Numer działu, w którym doszło do awarii (0 = nieznany).
	 * @return void
	 */
	public function log_exception( \Throwable $e, MP_OB_Context $context, $dept_num ) {
		global $wpdb;

		$table = MP_Offer_Builder_DB::activity_log_table();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'offer_id'    => $context->get( 'offer_id' ),
				'action'      => 'pipeline_exception',
				'description' => sprintf( 'Nieoczekiwany wyjątek w dziale %d: %s', (int) $dept_num, $e->getMessage() ),
				'user_id'     => get_current_user_id() ? get_current_user_id() : null,
				'meta_json'   => wp_json_encode(
					array(
						'request_id' => $context->get( 'request_id' ),
						'department' => (int) $dept_num,
						'exception'  => get_class( $e ),
						'message'    => $e->getMessage(),
					)
				),
			)
		);

		$throttle_key = 'mp_ob_notify_exception';
		if ( get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, 15 * MINUTE_IN_SECONDS );

		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}

		$sent = wp_mail(
			$to,
			sprintf( '[MP Offer Builder] Nieoczekiwany wyjątek w pipeline (dział %d)', (int) $dept_num ),
			sprintf( "Pipeline przerwany wyjątkiem w dziale %d.\nTyp: %s\nKomunikat: %s\n", (int) $dept_num, get_class( $e ), $e->getMessage() )
		);

		if ( ! $sent ) {
			$this->log_alert_failure(
				sprintf( 'Alarm o wyjątku w dziale %d NIE został wysłany — serwer poczty odrzucił wiadomość.', (int) $dept_num ),
				array(
					'alert'      => 'pipeline_exception',
					'department' => (int) $dept_num,
				)
			);
		}
	}

	/**
	 * Odnotowuje, że alarm do administratora nie doszedł.
	 *
	 * Alarmu o nieudanym alarmie nie da się wysłać pocztą — a zepsuta poczta
	 * jest najbardziej prawdopodobnym powodem, dla którego pierwszy nie dotarł.
	 * Ślad musi więc zostać tam, gdzie widać go BEZ poczty: w dzienniku, obok
	 * wpisu, który ten alarm wywołał.
	 *
	 * Ogranicznika częstotliwości nie zwalniamy: to limit tempa, a nie znacznik
	 * sukcesu. Przy trwale zepsutym SMTP ponawianie przy każdym błędzie
	 * zamieniłoby jedną wiadomość na kwadrans w lawinę.
	 *
	 * @param string $description Co dokładnie nie doszło.
	 * @param array  $meta        Dodatkowe fakty do `meta_json`.
	 * @return void
	 */
	protected function log_alert_failure( $description, array $meta = array() ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MP_Offer_Builder_DB::activity_log_table(),
			array(
				'offer_id'    => null,
				'action'      => 'admin_alert_failed',
				'description' => (string) $description,
				// Bez adresu administratora: dziennik ma mowic, CO nie doszlo,
				// a nie do kogo mialo pojsc.
				'user_id'     => null,
				'meta_json'   => wp_json_encode( $meta ),
			)
		);
	}

	/**
	 * Powiadomienie administratora o błędzie (e-mail), z ograniczeniem
	 * częstotliwości (max 1 wiadomość na 15 minut na dany dział), by nie spamować.
	 *
	 * Oficjalne API: wp_mail() https://developer.wordpress.org/reference/functions/wp_mail/
	 *
	 * @param MP_OB_Department $department Dział.
	 * @param MP_OB_Result     $result     Wynik.
	 * @return void
	 */
	protected function notify_admin( MP_OB_Department $department, MP_OB_Result $result ) {
		$throttle_key = 'mp_ob_notify_' . $department->get_key();
		if ( get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, 15 * MINUTE_IN_SECONDS );

		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}

		$subject = sprintf( '[MP Offer Builder] Błąd w dziale %d (%s)', $department->get_number(), $department->get_key() );
		$body    = sprintf(
			"Pipeline zatrzymany w dziale %d (%s).\nKod: %s\nBłędy: %s\n",
			$department->get_number(),
			$department->get_key(),
			$result->get_code(),
			wp_json_encode( $result->get_errors() )
		);

		$sent = wp_mail( $to, $subject, $body );

		if ( ! $sent ) {
			$this->log_alert_failure(
				sprintf(
					'Alarm o zatrzymaniu działu %d (%s) NIE został wysłany — serwer poczty odrzucił wiadomość.',
					$department->get_number(),
					$department->get_key()
				),
				array(
					'alert'      => 'pipeline_error',
					'department' => $department->get_number(),
					'code'       => $result->get_code(),
				)
			);
		}
	}
}
