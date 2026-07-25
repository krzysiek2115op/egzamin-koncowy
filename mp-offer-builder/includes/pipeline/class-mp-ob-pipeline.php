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

	/** @var int Do którego numeru działu (włącznie) trwa transakcja — domyślnie "do końca". */
	protected $transactional_until = PHP_INT_MAX;

	/**
	 * @param MP_OB_Pipeline_Logger|null $logger Logger błędów.
	 */
	public function __construct( ?MP_OB_Pipeline_Logger $logger = null ) {
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
	 * Ustawia próg KOŃCA transakcji (włącznie) — domyślnie "do końca pipeline'u".
	 * Bez tego ustawienia transakcja trwałaby przez WSZYSTKIE działy od progu
	 * startowego aż po ostatni, łącznie z Działem 11 ("odpowiedź i przekazanie")
	 * — a jego zdarzenie `mp_offer_created` MUSI wystawić się DOPIERO PO COMMIT
	 * (blueprint Działu 11: "zdarzenie nigdy przed COMMIT — inaczej dalszy etap
	 * dostaje ofertę-widmo"). `MP_OB_Pipeline_Factory::make()` zamyka więc
	 * transakcję dokładnie na Dziale 10 (`set_transactional_until(10)`), żeby
	 * COMMIT zdążył się wykonać PRZED uruchomieniem Działu 11.
	 *
	 * @param int $n Numer działu kończącego transakcję.
	 * @return MP_OB_Pipeline
	 */
	public function set_transactional_until( $n ) {
		$this->transactional_until = (int) $n;
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

		// Siatka bezpieczeństwa na TWARDE fatale PHP (limit pamięci, exit() w
		// zależności), których `catch (\Throwable $e)` NIŻEJ nie łapie — bez tego
		// transakcja SQL mogłaby zostać formalnie otwarta na stałe po ubiciu
		// procesu, co przy trwałych połączeniach DB (persistent connections)
		// zaraziłoby transakcją NASTĘPNE, niepowiązane żądanie na tym samym
		// połączeniu. Logika wydzielona do maybe_rollback_on_fatal() — testowalna
		// wprost, bez czekania na realny shutdown skryptu (patrz docblock tamtej metody).
		register_shutdown_function(
			static function () use ( &$in_transaction, $wpdb ) {
				self::maybe_rollback_on_fatal( $in_transaction, $wpdb );
			}
		);

		try {
			foreach ( $this->departments as $department ) {
				// Transakcję ZAMYKAMY, zanim przetworzymy pierwszy dział PO progu końcowym —
				// COMMIT musi zdążyć się wykonać przed Działem 11 (zdarzenie/odpowiedź),
				// patrz docblock set_transactional_until().
				if ( $in_transaction && $department->get_number() > $this->transactional_until ) {
					$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$in_transaction = false;
				}

				// Transakcję otwieramy LENIWIE — dopiero przy pierwszym dziale zapisującym
				// (próg), by nie trzymać jej otwartej podczas działów 2-9 (odczyt/kalkulacje/render PDF).
				if ( $this->transactional_from > 0 && ! $in_transaction
					&& $department->get_number() >= $this->transactional_from
					&& $department->get_number() <= $this->transactional_until ) {
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
					// Sprzątanie tmp PDF NIEZALEŻNE od transakcji: Dział 9 (render) leży
					// PRZED progiem transakcyjności (transactional_from=10) — jeśli jego
					// WŁASNA bramka QA odrzuci render (np. brak embedded fontu), plik
					// tymczasowy już istnieje na dysku, mimo że żadna transakcja nigdy
					// się nie otworzyła. Bez tego taki plik zostaje osierocony na stałe.
					self::cleanup_orphaned_tmp_pdf( $context );
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
			self::cleanup_orphaned_tmp_pdf( $context );
			if ( $this->logger ) {
				$this->logger->log_exception( $e, $context, $context->get_current_department() );
			}
			throw $e;
		}

		if ( $in_transaction ) {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// Higiena/przyszłościowość: w KONFIGURACJI fabryki (transactional_until(10),
			// zawsze Dział 11 na końcu) ta gałąź i tak nigdy nie wykonuje się z
			// $in_transaction=true (próg zamyka się wcześniej w pętli powyżej), ale
			// MP_OB_Pipeline to klasa OGÓLNEGO przeznaczenia — bez tego resetu twardy
			// fatal PHP później w tym samym request (w innej konfiguracji progu)
			// wywołałby przez shutdown-guard ROLLBACK na już zacommitowanej transakcji.
			$in_transaction = false;
		}

		return MP_OB_Result::ok( $context->all() );
	}

	/**
	 * Wykonuje ROLLBACK, jeśli transakcja wciąż formalnie otwarta w chwili
	 * shutdown skryptu — jedyna siatka bezpieczeństwa na twarde fatale PHP
	 * (limit pamięci, `exit()` w zależności), które omijają `catch (\Throwable)`
	 * w run(). Wydzielona ze samego register_shutdown_function(), żeby dało
	 * się ją zweryfikować wprost (harness nie może czekać na realny shutdown
	 * procesu PHP).
	 *
	 * @param bool  $in_transaction Czy transakcja była otwarta w chwili shutdown.
	 * @param mixed $wpdb           Połączenie DB (realny $wpdb albo fake z harnessu).
	 * @return void
	 */
	public static function maybe_rollback_on_fatal( $in_transaction, $wpdb ) {
		if ( $in_transaction ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Kasuje plik PDF tymczasowy (Dział 9) po ROLLBACK — gate Działu 10:
	 * "Po ROLLBACK: tymczasowy PDF kasowany". Świadomie w pipeline, nie w
	 * samym Dziale 10: TYLKO pipeline wie z pewnością, że ROLLBACK faktycznie
	 * zaszedł (transakcja może się cofnąć z powodu błędu w KTÓRYMKOLWIEK
	 * dziale objętym progiem transakcyjności, nie tylko w Dziale 10 samym).
	 * `class_exists()` — framework pipeline'u nie zakłada twardo, że warstwa
	 * przechowywania plików jest zawsze załadowana.
	 *
	 * @param MP_OB_Context $context Kontekst.
	 * @return void
	 */
	private static function cleanup_orphaned_tmp_pdf( MP_OB_Context $context ) {
		if ( ! class_exists( 'MP_Offer_Builder_Storage' ) ) {
			return;
		}
		$pdf      = is_array( $context->get( 'pdf' ) ) ? $context->get( 'pdf' ) : array();
		$tmp_path = isset( $pdf['tmp_path'] ) ? (string) $pdf['tmp_path'] : '';
		if ( '' !== $tmp_path ) {
			MP_Offer_Builder_Storage::delete_tmp( $tmp_path );
		}
	}
}
