<?php
/**
 * Benchmark + edge-case debug dla pipeline MP Lead Intake (poza WP).
 * Mierzy: liczbę zapytań DB/request, pamięć (leak przy N przebiegach), czas,
 * rozmiar JSON; uruchamia twarde edge-case'y.
 */

require __DIR__ . '/wp-stubs.php';

// $wpdb z licznikami (podmieniamy zanim wtyczka go użyje).
class Bench_WPDB extends MP_Fake_WPDB {
	public $calls = array(
		'read'    => 0,
		'write'   => 0,
		'tx'      => 0,
		'prepare' => 0,
	);
	public function reset_calls() {
		$this->calls = array(
			'read'    => 0,
			'write'   => 0,
			'tx'      => 0,
			'prepare' => 0,
		);
	}
	public function get_results( $q, $o = OBJECT ) {
		++$this->calls['read'];
		return parent::get_results( $q, $o ); }
	public function get_row( $q, $o = OBJECT, $y = 0 ) {
		++$this->calls['read'];
		return parent::get_row( $q, $o, $y ); }
	public function get_var( $q = null, $x = 0, $y = 0 ) {
		++$this->calls['read'];
		return parent::get_var( $q, $x, $y ); }
	public function insert( $t, $d, $f = null ) {
		++$this->calls['write'];
		return parent::insert( $t, $d, $f ); }
	public function update( $t, $d, $w, $f = null, $wf = null ) {
		++$this->calls['write'];
		return parent::update( $t, $d, $w, $f, $wf ); }
	public function prepare( $q, ...$a ) {
		++$this->calls['prepare'];
		return parent::prepare( $q, ...$a ); }
	public function query( $q ) {
		$u = strtoupper( trim( (string) $q ) );
		if ( in_array( $u, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
			++$this->calls['tx'];
		} else {
			++$this->calls['write'];
		}
		return parent::query( $q );
	}
}
$GLOBALS['wpdb'] = new Bench_WPDB();

$PLUGIN = getenv( 'MP_PLUGIN_DIR' );
if ( ! $PLUGIN || ! is_dir( $PLUGIN ) ) {
	$guess  = dirname( __DIR__, 2 );
	$PLUGIN = is_file( $guess . '/mp-lead-intake.php' ) ? $guess : '/home/krzysiek/3 pluginy 3 bazy danych /mp-lead-intake';
}
require $PLUGIN . '/includes/db/class-mp-db.php';
require $PLUGIN . '/includes/pipeline/bootstrap.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

function valid_nip() {
	$n = 1000000000;
	while ( $n <= 9999999999 ) {
		if ( MP_D3_Agent_Nip::checksum_valid( (string) $n ) ) {
			return (string) $n; }
		++$n;
	}
	return '';
}
$VALID = valid_nip();

function good_input( $nip ) {
	return array(
		'company_name'      => 'Testowa Firma IT Sp. z o.o.',
		'nip'               => $nip,
		'email'             => 'kontakt@firma.pl',
		'phone'             => '+48 600 100 200',
		'segment'           => '',
		'consent_marketing' => true,
		'consent_rodo'      => true,
		'mp_hp'             => '',
		'mp_nonce'          => 'valid',
		'country'           => 'PL',
	);
}
function reset_state() {
	$GLOBALS['__mp_transients']           = array();
	$GLOBALS['__mp_cfg']['leads_by_nip']  = array();
	$GLOBALS['__mp_cfg']['archived_lead'] = null;
	$GLOBALS['wpdb']->rows_leads          = array();
}
function run_once( array $in ) {
	$ctx = new MP_Context( $in );
	return MP_Pipeline_Factory::make()->run( $ctx );
}

echo "NIP testowy: $VALID\n\n";

/* --- Zapytania DB + HTTP na 1 zgłoszenie (happy path, brak istniejącego leada) --- */
reset_state();
$GLOBALS['wpdb']->reset_calls();
$GLOBALS['__mp_http_calls'] = 0;
$r         = run_once( good_input( $VALID ) );
$c         = $GLOBALS['wpdb']->calls;
$http_reqs = (int) $GLOBALS['__mp_http_calls'];
echo "=== ZAPYTANIA DB / 1 zgłoszenie (happy path) ===\n";
printf( "odczyty=%d  zapisy=%d  transakcje(START/COMMIT)=%d  prepare=%d  | HTTP w żądaniu=%d | ok=%s\n",
	$c['read'], $c['write'], $c['tx'], $c['prepare'], $http_reqs, $r->is_ok() ? 'true' : 'false' );
echo ( 0 === $http_reqs
	? "  → P-1 rozwiązane: 0 wywołań HTTP w ścieżce żądania (VIES/Biała lista przeniesione do tła).\n"
	: "  → UWAGA: $http_reqs wywołań HTTP w ścieżce żądania (spodziewane 0 w trybie async).\n" );
echo "  (UWAGA: transienty rate-limit w realnym WP to DODATKOWE zapytania do wp_options,\n";
echo "   chyba że działa persistent object cache. Weryfikacja VAT/statusu = worker w tle.)\n\n";

/* --- Rozmiar JSON odpowiedzi (sukces) --- */
$sample = array(
	'success' => true,
	'data'    => array(
		'lead_id'    => 123,
		'message'    => 'Dziękujemy! Zapytanie zostało zarejestrowane.',
		'request_id' => '00000000-0000-4000-8000-000000000000',
	),
);
echo '=== ROZMIAR JSON odpowiedzi sukcesu: ' . strlen( wp_json_encode( $sample ) ) . " B ===\n\n";

/* --- Czas + pamięć (leak) przy N przebiegach --- */
$N = 300;
gc_collect_cycles();
$mem_start  = memory_get_usage();
$peak_start = memory_get_peak_usage( true );
$t0         = microtime( true );
for ( $i = 0; $i < $N; $i++ ) {
	reset_state();
	run_once( good_input( $VALID ) );
}
$elapsed  = microtime( true ) - $t0;
gc_collect_cycles();
$mem_end  = memory_get_usage();
$peak_end = memory_get_peak_usage( true );

echo "=== CZAS / PAMIĘĆ ($N przebiegów pełnego pipeline) ===\n";
printf( "śr. czas pipeline: %.3f ms/req   (całość %.1f ms)\n", ( $elapsed / $N ) * 1000, $elapsed * 1000 );
printf( "pamięć: start=%.2f MB  koniec=%.2f MB  Δ=%.0f B  | peak: %.2f→%.2f MB\n",
	$mem_start / 1048576, $mem_end / 1048576, $mem_end - $mem_start, $peak_start / 1048576, $peak_end / 1048576 );
echo ( abs( $mem_end - $mem_start ) < 200000 ? "  → brak narastania pamięci (no leak)\n\n" : "  → UWAGA: pamięć narasta\n\n" );

/* --- EDGE CASES --- */
echo "=== EDGE CASES ===\n";
$long = str_repeat( 'A', 10000 );
$cases = array(
	array( 'company 10k znaków', array_merge( good_input( $VALID ), array( 'company_name' => $long ) ) ),
	array( 'emoji w nazwie', array_merge( good_input( $VALID ), array( 'company_name' => 'Firma 😀🚀 IT' ) ) ),
	array( 'chińskie znaki', array_merge( good_input( $VALID ), array( 'company_name' => '测试公司科技' ) ) ),
	array( 'polskie + segment', array_merge( good_input( $VALID ), array( 'company_name' => 'PRZEDSIĘBIORSTWO USŁUGI ŁĄCZNOŚCI' ) ) ),
	array( 'nip = null', array_merge( good_input( $VALID ), array( 'nip' => null ) ) ),
	array( 'nip = true (bool)', array_merge( good_input( $VALID ), array( 'nip' => true ) ) ),
	array( 'nip = -1234567890', array_merge( good_input( $VALID ), array( 'nip' => '-1234567890' ) ) ),
	array( 'nip = 0', array_merge( good_input( $VALID ), array( 'nip' => 0 ) ) ),
	array( 'nip = 12345.67 (float)', array_merge( good_input( $VALID ), array( 'nip' => 12345.67 ) ) ),
	array( 'nip = 0000000000', array_merge( good_input( $VALID ), array( 'nip' => '0000000000' ) ) ),
	array( 'email z unicode', array_merge( good_input( $VALID ), array( 'email' => 'jan@fïrma.pl' ) ) ),
	array( 'segment ręczny "IT"', array_merge( good_input( $VALID ), array( 'segment' => 'IT' ) ) ),
);
foreach ( $cases as $case ) {
	list( $label, $in ) = $case;
	reset_state(); // czysty stan — jeden przebieg na case (bez podwójnego insertu tego samego NIP)
	$ctx = new MP_Context( $in );
	$res = MP_Pipeline_Factory::make()->run( $ctx );
	$seg = $res->is_ok() ? ( ' segment=' . $ctx->get( 'segment' ) ) : '';
	printf( "%-26s ok=%-5s stop_dept=%-2s code=%-14s%s\n",
		$label, $res->is_ok() ? 'true' : 'false', $ctx->get_current_department(), $res->get_code() !== '' ? $res->get_code() : '-', $seg );
}
echo "\n(Uwaga: brak pola 'data' w formularzu → edge-case'y Date/Future/Past/Invalid Date = N/A.)\n";
