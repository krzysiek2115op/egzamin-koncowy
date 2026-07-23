<?php
/**
 * Harness weryfikujący RUSZTOWANIE (krok 2) pipeline'u MP Offer Builder.
 *
 * Ładuje shim WP, buduje pełny pipeline (MP_OB_Pipeline_Factory) i sprawdza
 * mechanikę procesu — kolejność 11 działów, liczbę par A–K per dział wg
 * blueprint/LP2_diagram_wizualny.html, transakcyjność od działu 10 i
 * jednokierunkowy przepływ danych. Działy są na tym etapie zaślepkami
 * (MP_OB_Stub_Agent/Critic) — logika biznesowa trafi w kroku 3, dział po
 * dziale; wtedy ten harness urośnie o niezmienniki właściwe każdemu działowi
 * (wzorem tests/process-harness/run-process.php w mp-lead-intake).
 */

require __DIR__ . '/wp-stubs.php';

// Katalog wtyczki: z ENV, albo dwa poziomy w górę (tests/process-harness → wtyczka),
// albo fallback na ścieżkę deweloperską (uruchomienie ze scratchpad).
$PLUGIN = getenv( 'MP_PLUGIN_DIR' );
if ( ! $PLUGIN || ! is_dir( $PLUGIN ) ) {
	$guess  = dirname( __DIR__, 2 );
	$PLUGIN = is_file( $guess . '/mp-offer-builder.php' ) ? $guess : '/home/krzysiek/3 pluginy 3 bazy danych /mp-offer-builder';
}
require $PLUGIN . '/includes/db/class-mp-offer-builder-db.php';
require $PLUGIN . '/includes/pipeline/bootstrap.php';

/* ---------- Narzędzia ---------- */

// Przykładowe żądanie zgodne z kontraktem Działu 1 (blueprint: json wejścia).
function base_input() {
	return array(
		'client'     => array(
			'name'    => 'Testowa Firma Sp. z o.o.',
			'email'   => 'kontakt@testowa-firma.pl',
			'nip'     => '1234563218',
			'country' => 'PL',
		),
		'items'      => array(
			array(
				'product_id'   => 812,
				'variation_id' => 9101,
				'qty'          => 40,
			),
		),
		'wariant'    => 'partner',
		'lang'       => 'pl',
		'request_id' => '3f1c2a10-aaaa-4bbb-8ccc-000000000001',
	);
}

// Uruchamia świeży pipeline dla danego wejścia; zwraca obserwacje procesu.
function run_pipeline( array $input ) {
	$context  = new MP_OB_Context( $input );
	$pipeline = MP_OB_Pipeline_Factory::make();
	$result   = $pipeline->run( $context );
	return array(
		'ok'         => $result->is_ok(),
		'code'       => $result->get_code(),
		'errors'     => $result->get_errors(),
		'stop_dept'  => $context->get_current_department(),
		'final_data' => $result->get_data(),
	);
}

// Krytyk, który zawsze odrzuca — do testu mechaniki STOP/ROLLBACK (nie jest
// używany przez żaden z 11 prawdziwych działów, tylko w tym harnessie).
function make_failing_critic( $id, $label ) {
	return new class( $id, $label ) implements MP_OB_Critic_Interface {
		private $id;
		private $label;
		public function __construct( $id, $label ) {
			$this->id    = $id;
			$this->label = $label;
		}
		public function get_id() {
			return $this->id; }
		public function get_label() {
			return $this->label; }
		public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
			return MP_OB_Result::fail( 'zawsze odrzuca (test harnessu)', array(), 'harness_forced_fail' );
		}
	};
}

$results = array();
$pass    = 0;
$fail    = 0;

function record( $name, $verdict, $note ) {
	global $results, $pass, $fail;
	$results[ $name ] = array( $verdict, $note );
	if ( 'PASS' === $verdict ) {
		++$pass; }
	if ( 'FAIL' === $verdict ) {
		++$fail; }
	printf( "[%-4s] %-60s %s\n", $verdict, $name, $note );
}

/* ---------- Scenariusz 1: pełny przelot przez 11 działów-zaślepek ---------- */

$hp = run_pipeline( base_input() );
record(
	'happy_path_11_departments',
	$hp['ok'] ? 'PASS' : 'FAIL',
	sprintf( 'ok=%s stop_dept=%d code=%s', $hp['ok'] ? 'true' : 'false', $hp['stop_dept'], $hp['code'] ?: '-' )
);

/* ---------- Niezmienniki rusztowania (krok 2) ---------- */

echo "\n=== NIEZMIENNIKI RUSZTOWANIA ===\n";

// 1) Jednokierunkowość: happy-path nie gubi kluczy wejścia.
$lost_keys = array();
if ( $hp['ok'] ) {
	foreach ( base_input() as $k => $v ) {
		if ( ! array_key_exists( $k, $hp['final_data'] ) ) {
			$lost_keys[] = $k;
		}
	}
}
$inv1 = $hp['ok'] && empty( $lost_keys );
record( 'inv1_jednokierunkowosc_bez_utraty_kluczy', $inv1 ? 'PASS' : 'FAIL', $lost_keys ? 'zgubione: ' . implode( ',', $lost_keys ) : 'wszystkie klucze zachowane' );

// 2) 11 działów zarejestrowanych, ponumerowanych kolejno 1..11.
$pipeline    = MP_OB_Pipeline_Factory::make();
$departments = $pipeline->get_departments();
$numbers     = array_map( function ( $d ) {
	return $d->get_number(); }, $departments );
$inv2        = count( $departments ) === 11 && $numbers === range( 1, 11 );
record( 'inv2_11_dzialow_w_kolejnosci', $inv2 ? 'PASS' : 'FAIL', 'numery=' . implode( ',', $numbers ) );

// 3) Liczba par Agent-Krytyk per dział zgodna z blueprint/LP2_diagram_wizualny.html
//    (Dział 1=3, 2=5, 3-9=2, 10=3, 11=2 — suma 27, zgodnie z licznikiem w diagramie).
$expected_pairs = array( 1 => 3, 2 => 5, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2, 8 => 2, 9 => 2, 10 => 3, 11 => 2 );
$actual_pairs   = array();
foreach ( $departments as $d ) {
	$actual_pairs[ $d->get_number() ] = count( $d->get_pairs() );
}
$inv3 = $actual_pairs === $expected_pairs;
record(
	'inv3_liczba_par_wg_diagramu',
	$inv3 ? 'PASS' : 'FAIL',
	'suma=' . array_sum( $actual_pairs ) . ' (oczekiwano 27): ' . wp_json_encode( $actual_pairs )
);

// 4) Transakcyjność: START TRANSACTION dokładnie raz (przed działem 10), COMMIT
//    dokładnie raz na końcu — zgodnie z set_transactional_from(10) w fabryce.
$GLOBALS['wpdb']->tx_log = array();
run_pipeline( base_input() );
$inv4 = $GLOBALS['wpdb']->tx_log === array( 'START', 'COMMIT' );
record( 'inv4_transakcja_start_commit_od_dzialu_10', $inv4 ? 'PASS' : 'FAIL', 'tx_log=' . implode( '>', $GLOBALS['wpdb']->tx_log ) );

// 5) Mechanika STOP: Krytyk odrzucający zatrzymuje dział (Department::process
//    zwraca fail, kod 'critic_failed'), bez przechodzenia do bramki jakości.
$dep_forced_fail = new MP_OB_Department(
	99,
	'harness-forced-fail',
	'Dział testowy harnessu',
	'Wymusza odrzucenie przez krytyka, by zweryfikować mechanikę STOP.',
	array(
		array(
			'agent'  => new MP_OB_Stub_Agent( '99.1', 'Agent testowy', '' ),
			'critic' => make_failing_critic( 'K99.1', 'Krytyk zawsze odrzucający' ),
		),
	),
	new MP_OB_Quality_Gate(
		new MP_OB_Stub_Agent( 'QA99', 'QA nigdy nieuruchomiony', '' ),
		new MP_OB_Accept_Critic( 'QAK99', 'QA Krytyk 99' )
	)
);
$forced_result = $dep_forced_fail->process( new MP_OB_Context( array() ) );
$inv5          = ! $forced_result->is_ok() && 'critic_failed' === $forced_result->get_code();
record( 'inv5_mechanika_stop_na_odrzuceniu_krytyka', $inv5 ? 'PASS' : 'FAIL', 'code=' . $forced_result->get_code() );

// 6) Mechanika ROLLBACK: STOP w dziale >= 10 (transakcyjnym) wycofuje transakcję
//    i loguje błąd do wp_mp_ob_offer_activity_log (MP_OB_Pipeline_Logger).
$pipeline_rollback = new MP_OB_Pipeline( new MP_OB_Pipeline_Logger() );
$pipeline_rollback->set_transactional_from( 10 );
$pipeline_rollback->add_department( MP_OB_Department_09::build() ); // ostatni dział czytający przed zapisem
$pipeline_rollback->add_department( $dep_forced_fail );             // symuluje awarię W TRAKCIE zapisu (dział 10)
$GLOBALS['wpdb']->tx_log       = array();
$GLOBALS['wpdb']->activity_log = array();
$rb_result                     = $pipeline_rollback->run( new MP_OB_Context( array() ) );
$inv6                          = ! $rb_result->is_ok()
	&& $GLOBALS['wpdb']->tx_log === array( 'START', 'ROLLBACK' )
	&& count( $GLOBALS['wpdb']->activity_log ) === 1
	&& 'pipeline_error' === ( $GLOBALS['wpdb']->activity_log[0]['action'] ?? '' );
record(
	'inv6_mechanika_rollback_i_log_bledu',
	$inv6 ? 'PASS' : 'FAIL',
	'tx_log=' . implode( '>', $GLOBALS['wpdb']->tx_log ) . ' activity_log_rows=' . count( $GLOBALS['wpdb']->activity_log )
);

/* ---------- Podsumowanie ---------- */

echo "\n=== PODSUMOWANIE ===\n";
printf( "Scenariusze/niezmienniki: PASS=%d FAIL=%d\n", $pass, $fail );
echo 0 === $fail
	? "WYNIK: rusztowanie pipeline'u (krok 2) spójne wg niezmienników.\n"
	: "WYNIK: wykryto {$fail} naruszeń — patrz FAIL powyżej.\n";

exit( 0 === $fail ? 0 : 1 );
