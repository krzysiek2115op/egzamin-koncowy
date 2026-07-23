<?php
/**
 * Pipeline — orkiestrator przepływu przez 11 działów.
 *
 * Zasady: dane płyną tylko w jedną stronę (pipeline jednokierunkowy);
 * po każdym dziale odbywa się kontrola; przy błędzie — STOP i log.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orkiestrator pipeline.
 */
class MP_OB_Pipeline {

	/** @var MP_OB_Department[] Uporządkowana lista działów. */
	protected $departments = array();

	/** @var MP_OB_Pipeline_Logger|null */
	protected $logger;

	/** @var int Od którego numeru działu obejmować zapisy jedną transakcją DB (0 = wyłączone). */
	protected $transactional_from = 0;

	/**
	 * @param MP_OB_Pipeline_Logger|null $logger Logger błędów.
	 */
	public function __construct( MP_OB_Pipeline_Logger $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Ustawia próg transakcyjności: działy o numerze >= $n są objęte JEDNĄ
	 * transakcją DB (COMMIT na pełny sukces, ROLLBACK na STOP). Chroni przed
	 * osieroconym, niekompletnym zapisem oferty, gdyby zapis w dziale 10
	 * (nagłówek + pozycje + wersja + dziennik) zawiódł w połowie. Wartość 0
	 * wyłącza transakcyjność.
	 *
	 * @param int $n Numer działu początkowego transakcji.
	 * @return MP_OB_Pipeline
	 */
	public function set_transactional_from( $n ) {
		$this->transactional_from = (int) $n;
		return $this;
	}

	/**
	 * Dodaje dział na koniec pipeline.
	 *
	 * @param MP_OB_Department $department Dział.
	 * @return MP_OB_Pipeline
	 */
	public function add_department( MP_OB_Department $department ) {
		$this->departments[] = $department;
		return $this;
	}

	/** @return MP_OB_Department[] */
	public function get_departments() {
		return $this->departments;
	}

	/**
	 * Uruchamia cały pipeline.
	 *
	 * @param MP_OB_Context $context Kontekst startowy (dane z 1 AJAX).
	 * @return MP_OB_Result Wynik końcowy lub pierwszy błąd (STOP).
	 * @throws \Throwable Ponownie po ROLLBACK+logu, gdy dział/subskrybent hooka rzuci wyjątek.
	 */
	public function run( MP_OB_Context $context ) {
		global $wpdb;
		$in_transaction = false;

		try {
			foreach ( $this->departments as $department ) {
				// Transakcję otwieramy LENIWIE — dopiero przy pierwszym dziale zapisującym
				// (próg), by nie trzymać jej otwartej podczas działów 2-9 (odczyt/kalkulacje/render PDF).
				if ( $this->transactional_from > 0 && ! $in_transaction
					&& $department->get_number() >= $this->transactional_from ) {
					$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$in_transaction = true;
				}

				$context->set_current_department( $department->get_number() );

				$result = $department->process( $context );

				if ( ! $result->is_ok() ) {
					// STOP: najpierw ROLLBACK częściowych zapisów działu 10 (atomowość),
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
		} catch ( \Throwable $e ) {
			// Nieoczekiwany wyjątek/fatal (np. w subskrybencie do_action('mp_offer_created')
			// z przyszłej integracji plugin 3, albo błąd we własnym kodzie) — bez tego
			// ROLLBACK i log nigdy by się nie wykonały, a wywołujący (class-mp-offer-builder-ajax.php)
			// dostałby nieprzechwycony fatal zamiast JSON-a.
			if ( $in_transaction ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$in_transaction = false;
			}
			if ( $this->logger ) {
				$this->logger->log_exception( $e, $context, $context->get_current_department() );
			}
			throw $e;
		}

		if ( $in_transaction ) {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return MP_OB_Result::ok( $context->all() );
	}
}
