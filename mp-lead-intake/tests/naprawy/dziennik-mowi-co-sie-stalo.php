<?php
/**
 * Wpis w dzienniku o zatrzymaniu pipeline'u nie mówił, CO się stało.
 *
 * Uruchamianie: wp eval-file tests/naprawy/dziennik-mowi-co-sie-stalo.php
 *
 * `log_failure()` składało opis z numeru działu, klucza działu i MASZYNOWEGO
 * kodu błędu („Błąd w dziale 3 (vat), kod: vat_invalid"). Czytelne dla człowieka
 * komunikaty z `$result->get_errors()` szły wyłącznie do `meta_json` — a ten sam
 * plik dwukrotnie stwierdza, że `meta_json` nie jest pokazywany na liście wpisów
 * w panelu. Administrator oglądał więc dziennik pełen kodów, mając powód awarii
 * zapisany obok, w kolumnie, której nie widzi.
 *
 * Bliźniacza metoda `log_exception()` tę naprawę dostała: jej opis brzmi
 * „Nieoczekiwany wyjątek w %s: %s" i niesie treść komunikatu. Ścieżka
 * kontrolowanego STOP-u krytyka/bramki została pominięta — trzeci przypadek
 * tej samej pomyłki w tym projekcie (patrz `segment` obok `offer_number`
 * i strażnik SR5-03 we wtyczce 2, ale nie w 1).
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_dz'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegół przy porażce.
 * @return bool
 */
function dz_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_dz']['pass'];
		$GLOBALS['mp_dz']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_dz']['fail'];
	$GLOBALS['mp_dz']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function dz_koniec() {
	if ( empty( $GLOBALS['mp_dz']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_dz'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_dz']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'dz_koniec' );

/**
 * Kontekst przebiegu.
 *
 * @return MP_Context
 */
function dz_kontekst() {
	return new MP_Context(
		array(
			'lead_id'    => 4243,
			'request_id' => 'req-dziennik-1',
		)
	);
}

/**
 * Ostatni wpis dziennika o zadanej akcji.
 *
 * @param int    $od     Identyfikator, powyżej którego szukamy.
 * @param string $akcja  Nazwa akcji.
 * @return array|null
 */
function dz_wpis( $od, $akcja ) {
	global $wpdb;

	$t = MP_Lead_Intake_DB::activity_log_table();

	return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT * FROM {$t} WHERE id > %d AND action = %s ORDER BY id DESC LIMIT 1", $od, $akcja ),
		ARRAY_A
	);
}

$log_t  = MP_Lead_Intake_DB::activity_log_table();
$logger = new MP_Pipeline_Logger();
$dzial  = MP_Department_11::build();

/* ==================================================================== A */

$GLOBALS['mp_dz']['lines'][] = '=== A. opis niesie powod zrozumialy dla czlowieka ===';

$powod = 'Numer NIP nie przeszedł weryfikacji w rejestrze VIES.';
$max   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_transient( 'mp_notify_' . $dzial->get_key() );

$logger->log_failure( $dzial, MP_Result::fail( $powod, array(), 'vat_invalid' ), dz_kontekst() );
$wpis = dz_wpis( $max, 'pipeline_error' );

if ( ! dz_ok( is_array( $wpis ), 'wpis o zatrzymaniu powstal' ) ) {
	return;
}

dz_ok(
	false !== mb_strpos( (string) $wpis['description'], $powod ),
	'opis wpisu zawiera powod slowami',
	'description=' . $wpis['description']
);

/* ==================================================================== B */

$GLOBALS['mp_dz']['lines'][] = '';
$GLOBALS['mp_dz']['lines'][] = '=== B. kontr-asercje: diagnostyka nie znikla ===';

dz_ok(
	false !== strpos( (string) $wpis['description'], 'vat_invalid' ),
	'opis nadal niesie kod maszynowy (do zgloszenia awarii)'
);
dz_ok(
	false !== strpos( (string) $wpis['description'], (string) $dzial->get_number() ),
	'opis nadal wskazuje numer dzialu'
);

$meta = json_decode( (string) $wpis['meta_json'], true );
dz_ok(
	is_array( $meta ) && isset( $meta['errors'] ) && in_array( $powod, (array) $meta['errors'], true ),
	'komplet bledow nadal siedzi w meta_json'
);
dz_ok(
	is_array( $meta ) && isset( $meta['code'] ) && 'vat_invalid' === $meta['code'],
	'i kod tez'
);

/* ==================================================================== C */

$GLOBALS['mp_dz']['lines'][] = '';
$GLOBALS['mp_dz']['lines'][] = '=== C. wynik BEZ komunikatu nie zostawia smiecia ===';

$max2 = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_transient( 'mp_notify_' . $dzial->get_key() );

$logger->log_failure( $dzial, MP_Result::fail( '', array(), 'pusty_kod' ), dz_kontekst() );
$wpis2 = dz_wpis( $max2, 'pipeline_error' );

dz_ok( is_array( $wpis2 ), 'wpis bez komunikatu tez powstal' );
dz_ok(
	is_array( $wpis2 ) && false !== strpos( (string) $wpis2['description'], 'pusty_kod' ),
	'i nadal ma kod'
);
dz_ok(
	is_array( $wpis2 ) && ! preg_match( '~[:—-]\s*$~u', trim( (string) $wpis2['description'] ) ),
	'opis nie konczy sie wiszacym separatorem',
	is_array( $wpis2 ) ? 'description=' . $wpis2['description'] : ''
);

/* ==================================================================== D */

$GLOBALS['mp_dz']['lines'][] = '';
$GLOBALS['mp_dz']['lines'][] = '=== D. blizniacza sciezka wyjatku dziala jak dotad ===';

$max3 = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_transient( 'mp_notify_dept_11' );
delete_transient( 'mp_notify_' . $dzial->get_key() );

$logger->log_exception( new RuntimeException( 'Baza odmowila polaczenia' ), dz_kontekst(), 11 );
$wpis3 = dz_wpis( $max3, 'pipeline_exception' );

dz_ok(
	is_array( $wpis3 ) && false !== strpos( (string) $wpis3['description'], 'Baza odmowila polaczenia' ),
	'opis wyjatku nadal niesie komunikat',
	is_array( $wpis3 ) ? 'description=' . $wpis3['description'] : 'brak wpisu'
);

$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_t} WHERE id > %d", $max ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
