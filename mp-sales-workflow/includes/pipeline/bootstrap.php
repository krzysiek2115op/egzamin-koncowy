<?php
/**
 * Loader warstwy pipeline — ładuje klasy w kolejności zależności.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mp_sw_pipeline_dir = __DIR__ . '/';

// Rdzeń: wynik, koperta, kontrakty.
require_once $mp_sw_pipeline_dir . 'class-mp-sw-result.php';
require_once $mp_sw_pipeline_dir . 'class-mp-sw-context.php';
require_once $mp_sw_pipeline_dir . 'pipeline-contracts.php';

// Klasy bazowe i gotowe warianty krytyków.
require_once $mp_sw_pipeline_dir . 'abstract-mp-sw-agent.php';
require_once $mp_sw_pipeline_dir . 'abstract-mp-sw-critic.php';
require_once $mp_sw_pipeline_dir . 'class-mp-sw-stubs.php';
require_once $mp_sw_pipeline_dir . 'class-mp-sw-field-critic.php';
require_once $mp_sw_pipeline_dir . 'class-mp-sw-flag-critic.php';
require_once $mp_sw_pipeline_dir . 'class-mp-sw-array-critic.php';

// Struktura przebiegu.
require_once $mp_sw_pipeline_dir . 'class-mp-sw-quality-gate.php';
require_once $mp_sw_pipeline_dir . 'class-mp-sw-department.php';
require_once $mp_sw_pipeline_dir . 'class-mp-sw-pipeline-logger.php';
require_once $mp_sw_pipeline_dir . 'class-mp-sw-pipeline.php';

// Działy 1–9 (na tym etapie: struktura z zaślepkami).
require_once $mp_sw_pipeline_dir . 'departments/class-mp-sw-department-01.php';
require_once $mp_sw_pipeline_dir . 'departments/class-mp-sw-department-02.php';
require_once $mp_sw_pipeline_dir . 'departments/class-mp-sw-department-03.php';
require_once $mp_sw_pipeline_dir . 'departments/class-mp-sw-department-04.php';
require_once $mp_sw_pipeline_dir . 'departments/class-mp-sw-department-05.php';
require_once $mp_sw_pipeline_dir . 'departments/class-mp-sw-department-06.php';
require_once $mp_sw_pipeline_dir . 'departments/class-mp-sw-department-07.php';
require_once $mp_sw_pipeline_dir . 'departments/class-mp-sw-department-08.php';
require_once $mp_sw_pipeline_dir . 'departments/class-mp-sw-department-09.php';

require_once $mp_sw_pipeline_dir . 'class-mp-sw-pipeline-factory.php';

/*
 * Strażnik wysyłki wpina się od razu przy ładowaniu, a nie dopiero w Dziale 7:
 * krytyk K7.3 ma wykryć wysyłkę wykonaną GDZIEKOLWIEK w żądaniu, także przed
 * wejściem w pipeline.
 */
MP_SW_D7_Notifier::install_guard();

unset( $mp_sw_pipeline_dir );
