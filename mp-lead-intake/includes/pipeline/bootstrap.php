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
require_once $mp_pipeline_dir . 'class-mp-quality-gate.php';
require_once $mp_pipeline_dir . 'class-mp-department.php';
require_once $mp_pipeline_dir . 'class-mp-pipeline-logger.php';
require_once $mp_pipeline_dir . 'class-mp-pipeline.php';
require_once $mp_pipeline_dir . 'class-mp-pipeline-factory.php';

unset( $mp_pipeline_dir );
