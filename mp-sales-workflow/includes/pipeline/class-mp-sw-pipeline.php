<?php
/**
 * Pipeline — orkiestrator przepływu przez działy LP.3.
 *
 * Zasady z diagramu: przepływ jednokierunkowy, kontrola po każdym dziale,
 * przy błędzie STOP i log. Zapis odbywa się JEDNĄ transakcją (Dział 8), a
 * Dział 9 (zdarzenia wyjściowe + uruchomienie kolejki e-mail) musi zobaczyć
 * dane już zatwierdzone — inaczej powstałby e-mail o stanie, którego w bazie
 * nie ma.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orkiestrator pipeline.
 */
class MP_SW_Pipeline {

	/** @var MP_SW_Department[] Uporządkowana lista działów. */
	protected $departments = array();

	/** @var MP_SW_Pipeline_Logger|null */
	protected $logger;

	/** @var int Numer działu, od którego obowiązuje transakcja (0 = wyłączona). */
	protected $transactional_from = 0;

	/** @var int Numer działu, na którym transakcja się zamyka (włącznie). */
	protected $transactional_until = PHP_INT_MAX;

	/**
	 * @param MP_SW_Pipeline_Logger|null $logger Logger błędów.
	 */
	public function __construct( ?MP_SW_Pipeline_Logger $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Ustawia dział, od którego zaczyna się transakcja.
	 *
	 * Transakcja otwierana jest LENIWIE — dopiero przy pierwszym dziale
	 * zapisującym, żeby nie trzymać jej otwartej przez działy 2–7 (odczyt,
	 * uprawnienia, przypisanie, statusy, zadania, treści e-maili).
	 *
	 * @param int $n Numer działu.
	 * @return MP_SW_Pipeline
	 */
	public function set_transactional_from( $n ) {
		$this->transactional_from = (int) $n;
		return $this;
	}

	/**
	 * Ustawia dział, na którym transakcja się zamyka (włącznie).
	 *
	 * Bez tego progu transakcja trwałaby do końca pipeline'u, obejmując Dział 9
	 * — a ten wystawia zdarzenia i uruchamia kolejkę wysyłki. Diagram jest tu
	 * kategoryczny: "Nic nie wychodzi przed COMMIT". Fabryka zamyka więc
	 * transakcję dokładnie na Dziale 8.
	 *
	 * @param int $n Numer działu.
	 * @return MP_SW_Pipeline
	 */
	public function set_transactional_until( $n ) {
		$this->transactional_until = (int) $n;
		return $this;
	}

	/**
	 * Dodaje dział na koniec pipeline.
	 *
	 * @param MP_SW_Department $department Dział.
	 * @return MP_SW_Pipeline
	 */
	public function add_department( MP_SW_Department $department ) {
		$this->departments[] = $department;
		return $this;
	}

	/** @return MP_SW_Department[] */
	public function get_departments() {
		return $this->departments;
	}

	/**
	 * Uruchamia cały pipeline.
	 *
	 * @param MP_SW_Context $context Kontekst startowy (zdarzenie procesu).
	 * @return MP_SW_Result Wynik końcowy albo pierwszy błąd (STOP).
	 * @throws \Throwable Ponownie, po ROLLBACK-u i zalogowaniu.
	 */
	public function run( MP_SW_Context $context ) {
		global $wpdb;

		$in_transaction = false;

		/*
		 * Siatka bezpieczeństwa na TWARDE fatale PHP (wyczerpanie pamięci,
		 * `exit()` w cudzym kodzie), których `catch ( \Throwable )` nie łapie.
		 * Bez niej transakcja mogłaby zostać formalnie otwarta po ubiciu
		 * procesu, a przy trwałych połączeniach do bazy zaraziłaby NASTĘPNE,
		 * niepowiązane żądanie na tym samym połączeniu.
		 */
		register_shutdown_function(
			static function () use ( &$in_transaction, $wpdb ) {
				self::maybe_rollback_on_fatal( $in_transaction, $wpdb );
			}
		);

		try {
			foreach ( $this->departments as $department ) {
				// COMMIT wykonujemy PRZED pierwszym działem za progiem — Dział 9
				// nie może zobaczyć niezatwierdzonych danych.
				if ( $in_transaction && $department->get_number() > $this->transactional_until ) {
					$committed      = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$in_transaction = false;

					if ( false === $committed ) {
						/*
						 * COMMIT się nie powiódł (brak miejsca, transakcja
						 * wycofana po przekroczeniu czasu blokady). Nie wolno
						 * przejść do Działu 9 — wysłalibyśmy powiadomienia o
						 * procesie, którego w bazie nie ma.
						 */
						$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

						$commit_fail = MP_SW_Result::fail(
							__( 'Nie udało się zatwierdzić zapisu procesu (COMMIT).', 'mp-sales-workflow' ),
							array(),
							'commit_failed'
						);

						if ( $this->logger ) {
							$this->logger->log_failure( $department, $commit_fail, $context );
						}

						return $commit_fail;
					}

					/*
					 * Znacznik zatwierdzenia w kopercie. Dział 9 nie ma innego
					 * sposobu, żeby SPRAWDZIĆ, że transakcja jest domknięta — a bez
					 * tego jego krytyk „nic nie wychodzi przed COMMIT" byłby samą
					 * deklaracją. Pipeline jest jedynym miejscem, które to wie, więc
					 * to on zostawia ślad.
					 */
					$context->set( 'committed', true );
					$context->set( 'committed_at', current_time( 'mysql', true ) );
				}

				if ( $this->transactional_from > 0 && ! $in_transaction
					&& $department->get_number() >= $this->transactional_from
					&& $department->get_number() <= $this->transactional_until ) {
					$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$in_transaction = true;
				}

				$context->set_current_department( $department->get_number() );

				$result = $department->process( $context );

				if ( ! $result->is_ok() ) {
					// STOP: najpierw ROLLBACK (atomowość zapisu i pusta kolejka
					// powiadomień), dopiero potem log — log ma przetrwać wycofanie.
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
			/*
			 * Nieoczekiwany wyjątek — np. w subskrybencie zdarzenia wystawianego
			 * przez Dział 9 albo we własnym kodzie. Bez tego bloku ROLLBACK i log
			 * nigdy by się nie wykonały, a wywołujący dostałby surowy fatal
			 * zamiast odpowiedzi JSON.
			 */
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
			/*
			 * W konfiguracji z fabryki ta gałąź się nie wykonuje (próg zamyka
			 * transakcję wcześniej, Dział 9 zawsze jest ostatni), ale klasa jest
			 * ogólnego przeznaczenia: bez tego COMMIT-u i resetu flagi twardy
			 * fatal w dalszej części żądania wywołałby przez shutdown-guard
			 * ROLLBACK na już zatwierdzonej transakcji.
			 */
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$in_transaction = false;
		}

		return MP_SW_Result::ok( $context->all() );
	}

	/**
	 * Wycofuje transakcję, jeśli w chwili wygaszania skryptu wciąż jest otwarta.
	 *
	 * Wydzielone z domknięcia `register_shutdown_function()`, żeby dało się to
	 * sprawdzić testem wprost — bez czekania na realne zakończenie procesu PHP.
	 *
	 * @param bool  $in_transaction Czy transakcja była otwarta.
	 * @param mixed $wpdb           Połączenie do bazy.
	 * @return void
	 */
	public static function maybe_rollback_on_fatal( $in_transaction, $wpdb ) {
		if ( $in_transaction ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
