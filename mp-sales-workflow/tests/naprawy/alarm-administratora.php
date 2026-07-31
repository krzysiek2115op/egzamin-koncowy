<?php
/**
 * Ustalenie 1.19 — wynik `wp_mail()` przy alarmie administratora.
 *
 * Uruchamianie: wp eval-file tests/naprawy/alarm-administratora.php
 *
 * Bezpiecznik kolejki zatrzymuje wysylke i zawiadamia administratora jedna
 * wiadomoscia. Wynik tej wysylki nie byl sprawdzany, wiec przy zepsutym SMTP
 * kolejka stawala, a JEDYNY sygnal o tym przepadal po cichu. To ta sama
 * rodzina co Grupa A: brak potwierdzenia uznany za potwierdzenie.
 *
 * Alarmu o nieudanym alarmie nie da sie wyslac mailem — musi trafic tam, gdzie
 * widac go bez poczty, czyli do dziennika technicznego.
 *
 * @package MP_Sales_Workflow
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

	$path = is_dir( '/scr' ) ? '/scr/mp-p3-alarm.txt' : '/tmp/mp-p3-alarm.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_al']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'al_dump' );

/**
 * Wpisy dziennika o podanym powodzie.
 *
 * @param string $reason Powod.
 * @return array
 */
function al_wpisy( $reason ) {
	$znalezione = array();

	foreach ( MP_SW_Log::entries() as $wpis ) {
		if ( isset( $wpis['reason'] ) && $reason === (string) $wpis['reason'] ) {
			$znalezione[] = $wpis;
		}
	}

	return $znalezione;
}

// Stan wyjsciowy: bezpiecznik zwolniony, alarm niewyslany w tej godzinie.
MP_SW_Mailer::resume();
delete_transient( MP_SW_Mailer::ALERT_KEY );

$GLOBALS['mp_al']['lines'][] = '=== A: poczta DZIALA — alarm idzie, nic nie trafia do dziennika ===';

MP_SW_Log::reset();
add_filter( 'pre_wp_mail', '__return_true', 99 );
MP_SW_Mailer::trip( 999 );
remove_filter( 'pre_wp_mail', '__return_true', 99 );

al_ok(
	1 === count( al_wpisy( 'rate' ) ),
	'zadzialanie bezpiecznika jest odnotowane (to bylo juz wczesniej)',
	'wpisow=' . count( al_wpisy( 'rate' ) )
);
al_ok(
	0 === count( al_wpisy( 'breaker_alert_not_sent' ) ),
	'przy dzialajacej poczcie NIE ma wpisu o nieudanym alarmie'
);

MP_SW_Mailer::resume();
delete_transient( MP_SW_Mailer::ALERT_KEY );

$GLOBALS['mp_al']['lines'][] = '';
$GLOBALS['mp_al']['lines'][] = '=== B: poczta ODRZUCA — porazka musi byc widoczna bez poczty ===';

MP_SW_Log::reset();

/*
 * `pre_wp_mail` zwracajace false to dokladnie to, co robi WordPress przy
 * odmowie serwera: wp_mail() konczy sie wartoscia false, nie wyjatkiem.
 */
add_filter( 'pre_wp_mail', '__return_false', 99 );
MP_SW_Mailer::trip( 999 );
remove_filter( 'pre_wp_mail', '__return_false', 99 );

$porazki = al_wpisy( 'breaker_alert_not_sent' );

al_ok(
	1 === count( $porazki ),
	'nieudany alarm administratora trafia do dziennika technicznego',
	'wpisow=' . count( $porazki )
);
al_ok(
	! empty( $porazki ) && MP_SW_Errors::E_MAIL === (string) $porazki[0]['code'],
	'wpis niesie kod poczty (MP3-E170)',
	empty( $porazki ) ? 'brak wpisu' : (string) $porazki[0]['code']
);
al_ok(
	! empty( $porazki ) && isset( $porazki[0]['count'] ) && 999 === (int) $porazki[0]['count'],
	'wpis niesie licznik, przy ktorym bezpiecznik zadzialal'
);

$blob = strtolower( wp_json_encode( $porazki ) );
al_ok(
	1 !== preg_match( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $blob ),
	'wpis NIE zawiera adresu e-mail administratora (RODO, biala lista faktow)',
	$blob
);

al_ok(
	MP_SW_Mailer::halted(),
	'kolejka pozostaje zatrzymana — nieudany alarm nie odblokowuje bezpiecznika'
);

$GLOBALS['mp_al']['lines'][] = '';
$GLOBALS['mp_al']['lines'][] = '=== C: ograniczenie czestotliwosci dziala dalej ===';

MP_SW_Log::reset();

/*
 * TU CELOWO NIE MA `resume()`. Wznowienie kolejki zbroi alarm na nowo
 * (kasuje transient), bo po interwencji administratora nastepne zatrzymanie
 * ma sie zglosic. Sprawdzamy sytuacje odwrotna: bezpiecznik odpala drugi raz
 * w tej samej godzinie, bez interwencji — wtedy nie wolno wyslac niczego
 * ani niczego dopisac.
 */
add_filter( 'pre_wp_mail', '__return_false', 99 );
MP_SW_Mailer::trip( 1000 );
remove_filter( 'pre_wp_mail', '__return_false', 99 );

al_ok(
	0 === count( al_wpisy( 'breaker_alert_not_sent' ) ),
	'powtorny bezpiecznik w tej samej godzinie nie probuje wysylac ponownie'
);

// Sprzatanie.
MP_SW_Mailer::resume();
delete_transient( MP_SW_Mailer::ALERT_KEY );
delete_transient( MP_SW_Mailer::WINDOW_KEY );
MP_SW_Log::reset();
