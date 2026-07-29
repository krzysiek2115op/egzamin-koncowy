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

	/** @var int Po którym dziale ZAMKNĄĆ transakcję (0 = dopiero na końcu pipeline'u). */
	protected $transactional_until = 0;

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
	 * Ustawia dział, PO KTÓRYM transakcja jest zamykana (COMMIT).
	 *
	 * Bez tego progu transakcja trwała do końca pipeline'u — a Dział 11 wystawia
	 * `mp_lead_created` WEWNĄTRZ niej. Skutki były trzy i żaden nie był widoczny
	 * z kodu samego pipeline'u:
	 *
	 *  1. Wtyczka 3 otwiera własną transakcję w swoim Dziale 8, co w MySQL robi
	 *     NIEJAWNY COMMIT transakcji otwartej tutaj. Gwarancja „awaria działu 8/9
	 *     wycofa też leada" przestawała obowiązywać w chwili instalacji wtyczki 3.
	 *  2. Bez wtyczki 3 szkic oferty wtyczki 2 powstawał wewnątrz NASZEJ transakcji;
	 *     nasz ROLLBACK kasował wiersz w CUDZEJ bazie, a tamta strona zdążyła już
	 *     zwrócić `offer_id` wiersza, którego po chwili nie ma.
	 *  3. Wiersz leada trzymał blokadę na `uq_country_nip` przez cały czas pracy
	 *     subskrybentów — renderowanie PDF, wywołania HTTP, kolejkowanie poczty.
	 *
	 * Po zmianie zapisy działów 7–10 są nadal atomowe, a subskrybenci pracują na
	 * danych JUŻ ZATWIERDZONYCH. Cena jest świadoma: awaria subskrybenta nie
	 * wycofa leada — i dobrze, bo lead jest poprawny, a to integracja zawiodła.
	 *
	 * @param int $n Numer działu, po którym następuje COMMIT.
	 * @return MP_Pipeline
	 */
	public function set_transactional_until( $n ) {
		$this->transactional_until = (int) $n;
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
	 * @throws \Throwable Ponownie po ROLLBACK+logu, gdy dział/subskrybent hooka rzuci wyjątek.
	 */
	public function run( MP_Context $context ) {
		global $wpdb;
		$in_transaction = false;

		try {
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

				if ( $result->is_ok() && $in_transaction && $this->transactional_until > 0
					&& $department->get_number() >= $this->transactional_until ) {
					// Ostatni dział zapisujący ma za sobą — zamykamy transakcję TU,
					// żeby emisja haka w Dziale 11 poszła na danych zatwierdzonych.
					$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$in_transaction = false;
				}

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
		} catch ( \Throwable $e ) {
			// Nieoczekiwany wyjątek/fatal (np. w subskrybencie do_action('mp_lead_created')
			// z przyszłej integracji plugin 2/3, albo błąd we własnym kodzie) — bez tego
			// ROLLBACK i log nigdy by się nie wykonały (audyt 2026-07-22, PIPE-02), a
			// wywołujący (class-mp-ajax.php) dostałby nieprzechwycony fatal zamiast JSON-a.
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

		return MP_Result::ok( $context->all() );
	}
}
