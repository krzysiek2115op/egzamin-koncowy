<?php
/**
 * Ustalenie 1.19 — wynik `wp_mail()` przy alarmie administratora.
 *
 * Uruchamianie: wp eval-file tests/naprawy/alarm-administratora.php
 *
 * Logger pipeline'u wysyla administratorowi dwa rodzaje alarmow: o zatrzymaniu
 * dzialu (`notify_admin`) i o nieoczekiwanym wyjatku (`log_exception`).
 * Wynik `wp_mail()` nie byl sprawdzany, wiec przy zepsutym SMTP alarm
 * przepadal bez sladu — a to wlasnie wtedy jest najbardziej potrzebny.
 *
 * Alarmu o nieudanym alarmie nie da sie wyslac mailem, wiec porazka ma trafic
 * do dziennika w bazie, obok wpisu, ktory ten alarm wywolal.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_al'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function al_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_al']['pass'];
		$GLOBALS['mp_al']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_al']['fail'];
	$GLOBALS['mp_al']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function al_dump() {
	if ( empty( $GLOBALS['mp_al']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_al'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-p2-alarm.txt' : '/tmp/mp-p2-alarm.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_al']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'al_dump' );

global $wpdb;

$log_t = MP_Offer_Builder_DB::activity_log_table();

/**
 * Ile wpisow o nieudanym alarmie lezy w dzienniku.
 *
 * Nazwa tabeli liczona NA MIEJSCU, nie przez `global`: `wp eval-file` wykonuje
 * plik w zasiegu funkcji, wiec zmienne z jego szczytu nie sa globalne —
 * `global $log_t` dalby pusta nazwe i zapytanie z bledem skladni.
 *
 * @return int
 */
function al_ile_porazek() {
	global $wpdb;

	$log_t = MP_Offer_Builder_DB::activity_log_table();

	return (int) $wpdb->get_var( // phpcs:ignore
		$wpdb->prepare( "SELECT COUNT(*) FROM {$log_t} WHERE action = %s", 'admin_alert_failed' ) // phpcs:ignore
	);
}

$logger = new MP_OB_Pipeline_Logger();

// Ograniczniki czestotliwosci z wczesniejszych przebiegow musza zniknac,
// inaczej logger w ogole nie dojdzie do wysylki.
delete_transient( 'mp_ob_notify_exception' );
// Ogranicznik wyjatkow ma dzis klucz OSOBNY DLA DZIALU; ten test chodzi na Dziale 7.
delete_transient( 'mp_ob_notify_exception_7' );

$GLOBALS['mp_al']['lines'][] = '=== A: poczta DZIALA — nic nie trafia do dziennika porazek ===';

$przed = al_ile_porazek();

add_filter( 'pre_wp_mail', '__return_true', 99 );
$logger->log_exception( new RuntimeException( 'test 1.19 — poczta dziala' ), new MP_OB_Context( array( 'offer_id' => null ) ), 7 );
remove_filter( 'pre_wp_mail', '__return_true', 99 );

al_ok( al_ile_porazek() === $przed, 'przy dzialajacej poczcie nie ma wpisu o nieudanym alarmie' );

$GLOBALS['mp_al']['lines'][] = '';
$GLOBALS['mp_al']['lines'][] = '=== B: poczta ODRZUCA — wyjatek pipeline ===';

delete_transient( 'mp_ob_notify_exception' );
// Ogranicznik wyjatkow ma dzis klucz OSOBNY DLA DZIALU; ten test chodzi na Dziale 7.
delete_transient( 'mp_ob_notify_exception_7' );
$przed = al_ile_porazek();

/*
 * `pre_wp_mail` zwracajace false to dokladnie to, co robi WordPress przy
 * odmowie serwera: wp_mail() konczy sie wartoscia false, nie wyjatkiem.
 */
add_filter( 'pre_wp_mail', '__return_false', 99 );
$logger->log_exception( new RuntimeException( 'test 1.19 — poczta odrzuca' ), new MP_OB_Context( array( 'offer_id' => null ) ), 7 );
remove_filter( 'pre_wp_mail', '__return_false', 99 );

al_ok( al_ile_porazek() === $przed + 1, 'nieudany alarm o wyjatku trafia do dziennika', 'przed=' . $przed . ' po=' . al_ile_porazek() );

$wpis = (array) $wpdb->get_row( // phpcs:ignore
	$wpdb->prepare( "SELECT * FROM {$log_t} WHERE action = %s ORDER BY id DESC LIMIT 1", 'admin_alert_failed' ), // phpcs:ignore
	ARRAY_A
);

// Sprawdzamy `meta_json`, nie opis: opis jest po polsku z ogonkami i porownanie
// bez nich („wyjat" vs „wyjąt") daje falszywy FAIL.
al_ok(
	isset( $wpis['meta_json'] ) && false !== strpos( (string) $wpis['meta_json'], 'pipeline_exception' ),
	'wpis mowi, KTORY alarm nie doszedl',
	isset( $wpis['meta_json'] ) ? (string) $wpis['meta_json'] : 'brak wpisu'
);

$blob = strtolower( (string) wp_json_encode( $wpis ) );
al_ok(
	1 !== preg_match( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $blob ),
	'wpis NIE zawiera adresu administratora',
	$blob
);

$GLOBALS['mp_al']['lines'][] = '';
$GLOBALS['mp_al']['lines'][] = '=== C: poczta ODRZUCA — zatrzymanie dzialu ===';

$dzial = MP_OB_Department_10::build();
$wynik = MP_OB_Result::fail( 'test 1.19', array(), 'test_code' );

delete_transient( 'mp_ob_notify_' . $dzial->get_key() );
$przed = al_ile_porazek();

add_filter( 'pre_wp_mail', '__return_false', 99 );
$logger->log_failure( $dzial, $wynik, new MP_OB_Context( array( 'offer_id' => null ) ) );
remove_filter( 'pre_wp_mail', '__return_false', 99 );

al_ok( al_ile_porazek() === $przed + 1, 'nieudany alarm o zatrzymaniu dzialu tez trafia do dziennika', 'przed=' . $przed . ' po=' . al_ile_porazek() );

$GLOBALS['mp_al']['lines'][] = '';
$GLOBALS['mp_al']['lines'][] = '=== D: ogranicznik czestotliwosci dziala dalej ===';

$przed = al_ile_porazek();

// Transient zostal ustawiony w sekcji C — druga proba w ciagu 15 minut nie ma
// prawa niczego wyslac ani dopisac.
add_filter( 'pre_wp_mail', '__return_false', 99 );
$logger->log_failure( $dzial, $wynik, new MP_OB_Context( array( 'offer_id' => null ) ) );
remove_filter( 'pre_wp_mail', '__return_false', 99 );

al_ok( al_ile_porazek() === $przed, 'powtorzony alarm w oknie 15 minut nie probuje wysylac ponownie' );

// Sprzatanie: wpisy tego przebiegu.
$wpdb->query( // phpcs:ignore
	$wpdb->prepare( "DELETE FROM {$log_t} WHERE action IN ( %s, %s, %s ) AND description LIKE %s", 'admin_alert_failed', 'pipeline_exception', 'pipeline_error', '%test 1.19%' ) // phpcs:ignore
);
delete_transient( 'mp_ob_notify_exception' );
// Ogranicznik wyjatkow ma dzis klucz OSOBNY DLA DZIALU; ten test chodzi na Dziale 7.
delete_transient( 'mp_ob_notify_exception_7' );
delete_transient( 'mp_ob_notify_' . $dzial->get_key() );
