<?php
/**
 * Logger pipeline — zapisuje przyczynę zatrzymania przebiegu.
 *
 * CELOWO NIE PISZE DO BAZY. Diagram LP.3 rozlicza przebieg z liczby zapisów
 * (`db_writes = 1` w Dziale 8, `db_writes = 0` dla `dashboard.view`), a log
 * błędu powstaje najczęściej PO ROLLBACK-u — zapis do tabeli albo złamałby ten
 * warunek, albo zostałby wycofany razem z transakcją. Dziennik aktywności
 * (`wp_mp_sw_activity`) to co innego: tam trafia przebieg UDANY, w tej samej
 * transakcji co reszta zapisu (Dział 8).
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejestrator błędów pipeline.
 */
class MP_SW_Pipeline_Logger {

	/** @var array Bufor wpisów z bieżącego żądania (diagnostyka i testy). */
	protected $entries = array();

	/**
	 * Zapisuje zatrzymanie pipeline'u w dziale.
	 *
	 * @param MP_SW_Department $department Dział, w którym nastąpił STOP.
	 * @param MP_SW_Result     $result     Wynik z błędem.
	 * @param MP_SW_Context    $context    Kontekst.
	 * @return void
	 */
	public function log_failure( MP_SW_Department $department, MP_SW_Result $result, MP_SW_Context $context ) {
		$this->record(
			array(
				'type'       => 'failure',
				'department' => $department->get_number(),
				'key'        => $department->get_key(),
				'code'       => $result->get_code(),
				'errors'     => $result->get_errors(),
				'trace_id'   => (string) $context->get( 'trace_id', '' ),
				'event_id'   => (string) $context->get( 'event_id', '' ),
			)
		);
	}

	/**
	 * Zapisuje nieoczekiwany wyjątek.
	 *
	 * @param \Throwable    $e          Wyjątek.
	 * @param MP_SW_Context $context    Kontekst.
	 * @param int           $department Numer działu, w którym wystąpił.
	 * @return void
	 */
	public function log_exception( $e, MP_SW_Context $context, $department ) {
		$this->record(
			array(
				'type'       => 'exception',
				'department' => (int) $department,
				'code'       => 'exception',
				'errors'     => array( $e->getMessage() ),
				'file'       => $e->getFile() . ':' . $e->getLine(),
				'trace_id'   => (string) $context->get( 'trace_id', '' ),
				'event_id'   => (string) $context->get( 'event_id', '' ),
			)
		);
	}

	/**
	 * Dokłada wpis do bufora i — przy włączonym debugowaniu — do logu PHP.
	 *
	 * @param array $entry Wpis.
	 * @return void
	 */
	protected function record( array $entry ) {
		$this->entries[] = $entry;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostyka tylko przy WP_DEBUG.
			error_log( 'MP Sales Workflow: ' . wp_json_encode( $entry ) );
		}
	}

	/** @return array Wpisy z bieżącego żądania. */
	public function get_entries() {
		return $this->entries;
	}
}
