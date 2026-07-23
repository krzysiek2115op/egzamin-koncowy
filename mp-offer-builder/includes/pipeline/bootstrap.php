<?php
/**
 * Loader warstwy pipeline — ładuje klasy w kolejności zależności.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mp_ob_pipeline_dir = __DIR__ . '/';

require_once $mp_ob_pipeline_dir . 'class-mp-ob-result.php';
require_once $mp_ob_pipeline_dir . 'class-mp-ob-context.php';
require_once $mp_ob_pipeline_dir . 'pipeline-contracts.php';
require_once $mp_ob_pipeline_dir . 'class-mp-ob-stubs.php';
require_once $mp_ob_pipeline_dir . 'abstract-mp-ob-agent.php';
require_once $mp_ob_pipeline_dir . 'abstract-mp-ob-critic.php';
require_once $mp_ob_pipeline_dir . 'class-mp-ob-accept-critic.php';
require_once $mp_ob_pipeline_dir . 'class-mp-ob-flag-critic.php';
require_once $mp_ob_pipeline_dir . 'class-mp-ob-field-critic.php';
require_once $mp_ob_pipeline_dir . 'class-mp-ob-array-critic.php';
require_once $mp_ob_pipeline_dir . 'class-mp-ob-quality-gate.php';
require_once $mp_ob_pipeline_dir . 'class-mp-ob-department.php';
require_once $mp_ob_pipeline_dir . 'class-mp-ob-pipeline-logger.php';
require_once $mp_ob_pipeline_dir . 'class-mp-ob-pipeline.php';

// --- Działy (krok 2: zaślepki; krok 3: realna logika, dział po dziale) ---
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-01.php';
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-02.php';
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-03.php';
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-04.php';
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-05.php';
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-06.php';
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-07.php';
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-08.php';
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-09.php';
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-10.php';
require_once $mp_ob_pipeline_dir . 'departments/class-mp-ob-department-11.php';

require_once $mp_ob_pipeline_dir . 'class-mp-ob-pipeline-factory.php';

unset( $mp_ob_pipeline_dir );
