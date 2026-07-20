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

require_once $mp_pipeline_dir . 'class-mp-result.php';
require_once $mp_pipeline_dir . 'class-mp-context.php';
require_once $mp_pipeline_dir . 'pipeline-contracts.php';
require_once $mp_pipeline_dir . 'class-mp-stubs.php';
require_once $mp_pipeline_dir . 'abstract-mp-agent.php';
require_once $mp_pipeline_dir . 'abstract-mp-critic.php';
require_once $mp_pipeline_dir . 'class-mp-accept-critic.php';
require_once $mp_pipeline_dir . 'class-mp-quality-gate.php';
require_once $mp_pipeline_dir . 'class-mp-department.php';
require_once $mp_pipeline_dir . 'class-mp-pipeline-logger.php';
require_once $mp_pipeline_dir . 'class-mp-pipeline.php';

// --- Działy z realną logiką (krok 3) ---
require_once $mp_pipeline_dir . 'departments/class-mp-department-01.php';

require_once $mp_pipeline_dir . 'class-mp-pipeline-factory.php';

unset( $mp_pipeline_dir );
