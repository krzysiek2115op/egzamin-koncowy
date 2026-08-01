<?php
/**
 * Loader warstwy pipeline — ładuje klasy w kolejności zależności.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mp_pipeline_dir = __DIR__ . '/';

// Numer VAT: kanoniczna postać i lokalna kontrola formatu. Ładowane TUTAJ, a nie
// tylko w pliku głównym wtyczki, bo działy 1, 2 i 3 na tym stoją, a pipeline bywa
// ładowany samodzielnie — tak robi harness procesu, który nie zna pliku głównego.
require_once dirname( $mp_pipeline_dir ) . '/class-mp-vat-number.php';

require_once $mp_pipeline_dir . 'class-mp-result.php';
require_once $mp_pipeline_dir . 'class-mp-context.php';
require_once $mp_pipeline_dir . 'pipeline-contracts.php';
require_once $mp_pipeline_dir . 'class-mp-stubs.php';
require_once $mp_pipeline_dir . 'abstract-mp-agent.php';
require_once $mp_pipeline_dir . 'abstract-mp-critic.php';
require_once $mp_pipeline_dir . 'class-mp-accept-critic.php';
require_once $mp_pipeline_dir . 'class-mp-flag-critic.php';
require_once $mp_pipeline_dir . 'class-mp-field-critic.php';
require_once $mp_pipeline_dir . 'class-mp-quality-gate.php';
require_once $mp_pipeline_dir . 'class-mp-department.php';
require_once $mp_pipeline_dir . 'class-mp-pipeline-logger.php';
require_once $mp_pipeline_dir . 'class-mp-pipeline.php';

// Wspólny scorer (używany przez dział 7.2 i weryfikator w tle) — przed działami.
require_once $mp_pipeline_dir . 'class-mp-lead-scoring.php';

// --- Działy z realną logiką (krok 3) ---
require_once $mp_pipeline_dir . 'departments/class-mp-department-01.php';
require_once $mp_pipeline_dir . 'departments/class-mp-department-02.php';
require_once $mp_pipeline_dir . 'departments/class-mp-department-03.php';
require_once $mp_pipeline_dir . 'departments/class-mp-department-04.php';
require_once $mp_pipeline_dir . 'departments/class-mp-department-05.php';
require_once $mp_pipeline_dir . 'departments/class-mp-department-06.php';
require_once $mp_pipeline_dir . 'departments/class-mp-department-07.php';
require_once $mp_pipeline_dir . 'departments/class-mp-department-08.php';
require_once $mp_pipeline_dir . 'departments/class-mp-department-09.php';
require_once $mp_pipeline_dir . 'departments/class-mp-department-10.php';
require_once $mp_pipeline_dir . 'departments/class-mp-department-11.php';

require_once $mp_pipeline_dir . 'class-mp-pipeline-factory.php';

unset( $mp_pipeline_dir );
