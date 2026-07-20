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

	/**
	 * @param MP_Pipeline_Logger|null $logger Logger błędów.
	 */
	public function __construct( MP_Pipeline_Logger $logger = null ) {
		$this->logger = $logger;
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
		foreach ( $this->departments as $department ) {
			$context->set_current_department( $department->get_number() );

			$result = $department->process( $context );

			if ( ! $result->is_ok() ) {
				// STOP: logujemy błąd i przerywamy (dane płyną w jedną stronę).
				if ( $this->logger ) {
					$this->logger->log_failure( $department, $result, $context );
				}
				return $result;
			}
		}

		return MP_Result::ok( $context->all() );
	}
}
