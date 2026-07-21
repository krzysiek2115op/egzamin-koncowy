<?php
/**
 * Pipeline — orkiestrator przepływu przez 11 działów.
 *
 * Zasady: dane płyną tylko w jedną stronę (pipeline jednokierunkowy);
 * po każdym dziale odbywa się kontrola; przy błędzie — STOP i log.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orkiestrator pipeline.
 */
class MP_Pipeline {

	/** @var MP_Department[] Uporządkowana lista działów. */
	protected $departments = array();

	/** @var MP_Pipeline_Logger|null */
	protected $logger;

	/** @var int Od którego numeru działu obejmować zapisy jedną transakcją DB (0 = wyłączone). */
	protected $transactional_from = 0;

	/**
	 * @param MP_Pipeline_Logger|null $logger Logger błędów.
	 */
	public function __construct( MP_Pipeline_Logger $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Ustawia próg transakcyjności: działy o numerze >= $n są objęte JEDNĄ
	 * transakcją DB (COMMIT na pełny sukces, ROLLBACK na STOP). Chroni przed
	 * osieroconym, niekompletnym leadem, gdy zapis w dziale 8/9 zawiedzie po
	 * utworzeniu leada w dziale 7. Wartość 0 wyłącza transakcyjność.
	 *
	 * @param int $n Numer działu początkowego transakcji.
	 * @return MP_Pipeline
	 */
	public function set_transactional_from( $n ) {
		$this->transactional_from = (int) $n;
		return $this;
	}

	/**
	 * Dodaje dział na koniec pipeline.
	 *
	 * @param MP_Department $department Dział.
	 * @return MP_Pipeline
	 */
	public function add_department( MP_Department $department ) {
		$this->departments[] = $department;
		return $this;
	}

	/** @return MP_Department[] */
	public function get_departments() {
		return $this->departments;
	}

	/**
	 * Uruchamia cały pipeline.
	 *
	 * @param MP_Context $context Kontekst startowy (dane z 1 AJAX).
	 * @return MP_Result Wynik końcowy lub pierwszy błąd (STOP).
	 */
	public function run( MP_Context $context ) {
		global $wpdb;
		$in_transaction = false;

		foreach ( $this->departments as $department ) {
			// Transakcję otwieramy LENIWIE — dopiero przy pierwszym dziale zapisującym
			// (próg), by nie trzymać jej otwartej podczas kosztownych calli HTTP działu 3.
			if ( $this->transactional_from > 0 && ! $in_transaction
				&& $department->get_number() >= $this->transactional_from ) {
				$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$in_transaction = true;
			}

			$context->set_current_department( $department->get_number() );

			$result = $department->process( $context );

			if ( ! $result->is_ok() ) {
				// STOP: najpierw ROLLBACK częściowych zapisów działów 7-9 (atomowość),
				// DOPIERO potem log błędu — log musi przetrwać mimo wycofania transakcji.
				if ( $in_transaction ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$in_transaction = false;
				}
				if ( $this->logger ) {
					$this->logger->log_failure( $department, $result, $context );
				}
				return $result;
			}
		}

		if ( $in_transaction ) {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return MP_Result::ok( $context->all() );
	}
}
