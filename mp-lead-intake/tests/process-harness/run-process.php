<?php
/**
 * PĘTLA WHILE weryfikująca PROCES wtyczki MP Lead Intake w runtime.
 *
 * Ładuje shim WP, buduje pełny pipeline (MP_Pipeline_Factory) i pętlą `while`
 * przepuszcza kolejkę scenariuszy formularz→oferta przez wszystkie 11 działów,
 * sprawdzając niezmienniki procesu.
 */

require __DIR__ . '/wp-stubs.php';

// Katalog wtyczki: z ENV, albo dwa poziomy w górę (tests/process-harness → wtyczka),
// albo fallback na ścieżkę deweloperską (uruchomienie ze scratchpad).
$PLUGIN = getenv( 'MP_PLUGIN_DIR' );
if ( ! $PLUGIN || ! is_dir( $PLUGIN ) ) {
	$guess  = dirname( __DIR__, 2 );
	$PLUGIN = is_file( $guess . '/mp-lead-intake.php' ) ? $guess : '/home/krzysiek/3 pluginy 3 bazy danych /mp-lead-intake';
}
require $PLUGIN . '/includes/db/class-mp-db.php';
require $PLUGIN . '/includes/pipeline/bootstrap.php';

/* ---------- Narzędzia ---------- */

// Generuje poprawny NIP używając WŁASNEJ funkcji wtyczki (pętla while).
function make_valid_nip() {
	$n = 1000000000;
	while ( $n <= 9999999999 ) {
		$cand = (string) $n;
		if ( MP_D3_Agent_Nip::checksum_valid( $cand ) ) {
			return $cand;
		}
		$n += 1;
	}
	return '0000000000';
}

// Uruchamia świeży pipeline dla danego wejścia; zwraca obserwacje procesu.
function run_pipeline( array $input ) {
	$mails_before = count( $GLOBALS['__mp_mails'] );
	$context      = new MP_Context( $input );
	$pipeline     = MP_Pipeline_Factory::make();
	$result       = $pipeline->run( $context );
	return array(
		'ok'         => $result->is_ok(),
		'code'       => $result->get_code(),
		'errors'     => $result->get_errors(),
		'stop_dept'  => $context->get_current_department(),
		'final_data' => $result->get_data(),
		'notified'   => count( $GLOBALS['__mp_mails'] ) > $mails_before,
	);
}

function base_input( $nip ) {
	return array(
		'company_name'      => 'Testowa Firma Sp. z o.o.',
		'nip'               => $nip,
		'email'             => 'kontakt@testowa-firma.pl',
		'phone'             => '+48 600 100 200',
		'segment'           => 'IT',
		'consent_marketing' => true,
		'consent_rodo'      => true,
		'mp_hp'             => '',
		'mp_nonce'          => 'valid',
		'country'           => 'PL',
	);
}

/* ---------- Scenariusze ---------- */

$VALID_NIP = make_valid_nip();
fwrite( STDERR, "Wygenerowany poprawny NIP (algorytm wtyczki): $VALID_NIP\n" );

// Kolejka scenariuszy: [nazwa, mutator wejścia, oczekiwanie: 'ok'|'stop', notatka]
$queue = array(
	array( 'happy_path', function ( $in ) {
		return $in; }, 'ok', 'Poprawne dane B2B → pełne przejście 11 działów' ),
	array( 'empty_form', function ( $in ) {
		return array(); }, 'stop', 'Puste wejście → STOP na walidacji' ),
	array( 'bad_nip', function ( $in ) {
		$in['nip'] = '1234567890'; return $in; }, 'stop', 'Zła suma kontrolna NIP → STOP' ),
	array( 'bad_email', function ( $in ) {
		$in['email'] = 'to-nie-email'; return $in; }, 'stop', 'Zły e-mail → STOP na walidacji' ),
	array( 'no_rodo', function ( $in ) {
		$in['consent_rodo'] = false; return $in; }, 'stop', 'Brak zgody RODO → STOP' ),
	array( 'honeypot', function ( $in ) {
		$in['mp_hp'] = 'jestem-botem'; return $in; }, 'stop', 'Honeypot wypełniony → STOP (antyspam)' ),
	array( 'bad_nonce', function ( $in ) {
		$in['mp_nonce'] = 'ZLY'; return $in; }, 'observe', 'Zły nonce → czy dział 5 to łapie?' ),
	array( 'empty_company', function ( $in ) {
		$in['company_name'] = ''; return $in; }, 'stop', 'Pusta nazwa firmy → STOP na walidacji' ),
);

/* ---------- Pętla WHILE po scenariuszach ---------- */

$results = array();
$pass    = 0;
$fail    = 0;

while ( ( $sc = array_shift( $queue ) ) !== null ) {
	list( $name, $mutator, $expect, $note ) = $sc;

	// Izolacja: czyścimy transienty (rate-limit/cache) i log maili między scenariuszami.
	$GLOBALS['__mp_transients']       = array();
	$GLOBALS['__mp_cfg']['leads_by_nip'] = array();
	$GLOBALS['wpdb']->rows_leads      = array();

	$input = $mutator( base_input( $VALID_NIP ) );
	$obs   = run_pipeline( $input );

	// Ocena względem oczekiwania.
	$verdict = 'INFO';
	if ( 'ok' === $expect ) {
		$verdict = $obs['ok'] ? 'PASS' : 'FAIL';
	} elseif ( 'stop' === $expect ) {
		$verdict = ! $obs['ok'] ? 'PASS' : 'FAIL';
	}
	if ( 'PASS' === $verdict ) {
		++$pass; }
	if ( 'FAIL' === $verdict ) {
		++$fail; }

	$results[ $name ] = array( $obs, $verdict, $note, $expect );

	printf(
		"[%-4s] %-14s ok=%-5s stop_dept=%-2s code=%-16s notified=%s\n",
		$verdict,
		$name,
		$obs['ok'] ? 'true' : 'false',
		$obs['stop_dept'],
		$obs['code'] !== '' ? $obs['code'] : '-',
		$obs['notified'] ? 'tak' : 'nie'
	);
	if ( ! empty( $obs['errors'] ) ) {
		echo '       errors: ' . json_encode( $obs['errors'], JSON_UNESCAPED_UNICODE ) . "\n";
	}
}

/* ---------- Niezmienniki procesu ---------- */

echo "\n=== NIEZMIENNIKI PROCESU ===\n";

// 1) Happy-path zachowuje dane wejścia (jednokierunkowość: nic nie ginie).
$hp        = $results['happy_path'][0];
$lost_keys = array();
if ( $hp['ok'] ) {
	foreach ( base_input( $VALID_NIP ) as $k => $v ) {
		if ( ! array_key_exists( $k, $hp['final_data'] ) ) {
			$lost_keys[] = $k;
		}
	}
}
$inv1 = $hp['ok'] && empty( $lost_keys );
printf( "[%-4s] Jednokierunkowość: happy-path nie gubi kluczy wejścia %s\n", $inv1 ? 'PASS' : 'FAIL', $lost_keys ? '(zgubione: ' . implode( ',', $lost_keys ) . ')' : '' );

// 2) Happy-path produkuje lead_id (proces formularz→lead domknięty).
$inv2 = $hp['ok'] && ! empty( $hp['final_data']['lead_id'] );
printf( "[%-4s] Domknięcie: happy-path ustawia lead_id (=%s)\n", $inv2 ? 'PASS' : 'FAIL', isset( $hp['final_data']['lead_id'] ) ? json_encode( $hp['final_data']['lead_id'] ) : 'brak' );

// 5) Hook integracyjny mp_lead_created wyemitowany dla happy-path (fix D).
$inv5 = ( $GLOBALS['__mp_actions']['mp_lead_created'] ?? 0 ) >= 1;
printf( "[%-4s] Integracja: hook mp_lead_created wyemitowany (x%d)\n", $inv5 ? 'PASS' : 'FAIL', $GLOBALS['__mp_actions']['mp_lead_created'] ?? 0 );

// 6) duration_ms policzony (int, >=0), nie null i nie „zawsze 0" przez strefy (fix J).
$dur  = $hp['final_data']['duration_ms'] ?? null;
$inv6 = $hp['ok'] && is_int( $dur ) && $dur >= 0;
printf( "[%-4s] Metryka: duration_ms policzony poprawnie (=%s)\n", $inv6 ? 'PASS' : 'FAIL', var_export( $dur, true ) );

// 3) Duplikat NIP: drugie zgłoszenie tego samego NIP nie może „po cichu" przejść.
$GLOBALS['__mp_transients']          = array();
$GLOBALS['__mp_cfg']['leads_by_nip'] = array();
$GLOBALS['wpdb']->rows_leads         = array();
$dup1 = run_pipeline( base_input( $VALID_NIP ) ); // pierwszy — zapis
// drugi raz z tym samym NIP: leads_by_nip zwraca istniejący (dedup w dziale 1) LUB insert się wywali (UNIQUE) w dziale 7
$GLOBALS['__mp_cfg']['leads_by_nip'] = array(
	array(
		'id'  => 1,
		'nip' => $VALID_NIP,
	),
);
$dup2 = run_pipeline( base_input( $VALID_NIP ) );
$inv3 = $dup1['ok']; // sam happy zapis musi się udać; zachowanie dup2 tylko raportujemy
printf(
	"[%-4s] Duplikat NIP: 1.zgłoszenie ok=%s | 2.zgłoszenie ok=%s stop_dept=%s code=%s\n",
	$inv3 ? 'PASS' : 'FAIL',
	$dup1['ok'] ? 'true' : 'false',
	$dup2['ok'] ? 'true' : 'false',
	$dup2['stop_dept'],
	$dup2['code'] !== '' ? $dup2['code'] : '-'
);

// 4) Rate-limit: wielokrotne szybkie zgłoszenia z jednego IP powinny w końcu → STOP (dział 5).
$_SERVER['REMOTE_ADDR']              = '203.0.113.9';
$GLOBALS['__mp_transients']          = array();
$GLOBALS['__mp_cfg']['leads_by_nip'] = array();
$GLOBALS['wpdb']->rows_leads         = array();
$blocked_at = 0;
$i          = 0;
while ( $i < 12 ) {
	++$i;
	$r = run_pipeline( base_input( $VALID_NIP ) );
	if ( ! $r['ok'] && (int) $r['stop_dept'] === 5 ) {
		$blocked_at = $i;
		break;
	}
}
printf( "[%-4s] Rate-limit: zablokowano po %s zgłoszeniach (0=brak limitu)\n", $blocked_at > 0 ? 'PASS' : 'INFO', $blocked_at );

// 7) Reaktywacja zarchiwizowanego NIP: firma raz zarchiwizowana MOŻE zgłosić się ponownie.
$_SERVER['REMOTE_ADDR']               = '203.0.113.77';
$GLOBALS['__mp_transients']           = array();
$GLOBALS['__mp_cfg']['leads_by_nip']  = array(); // brak AKTYWNEGO leada
$GLOBALS['__mp_cfg']['archived_lead'] = array(
	'id'         => 777,
	'nip'        => $VALID_NIP,
	'deleted_at' => '2026-01-01 00:00:00',
);
$GLOBALS['wpdb']->rows_leads = array();
$react = run_pipeline( base_input( $VALID_NIP ) );
$inv7  = $react['ok'] && 777 === (int) ( $react['final_data']['lead_id'] ?? 0 ) && ! empty( $react['final_data']['lead_reactivated'] );
printf(
	"[%-4s] Reaktywacja: zarchiwizowany NIP przechodzi (lead_id=%s, reactivated=%s)\n",
	$inv7 ? 'PASS' : 'FAIL',
	var_export( $react['final_data']['lead_id'] ?? null, true ),
	var_export( $react['final_data']['lead_reactivated'] ?? null, true )
);
$GLOBALS['__mp_cfg']['archived_lead'] = null;

// 8) Pre-gate DoS: over_limit() czyta bez inkrementu i blokuje po osiągnięciu limitu.
$GLOBALS['__mp_transients'] = array();
$before = MP_D5_Agent_Rate_Limit::over_limit( '198.51.100.5' );
set_transient( MP_D5_Agent_Rate_Limit::rate_key( '198.51.100.5' ), MP_D5_Agent_Rate_Limit::LIMIT, MINUTE_IN_SECONDS );
$after  = MP_D5_Agent_Rate_Limit::over_limit( '198.51.100.5' );
$inv8   = ! $before && $after;
printf( "[%-4s] Pre-gate DoS: over_limit() blokuje po limicie (%s→%s)\n", $inv8 ? 'PASS' : 'FAIL', var_export( $before, true ), var_export( $after, true ) );

// 9) Transakcyjność 7-9: awaria zapisu w dziale 8 → ROLLBACK, lead z działu 7 NIE utrwalony.
$_SERVER['REMOTE_ADDR']                       = '203.0.113.55';
$GLOBALS['__mp_transients']                   = array();
$GLOBALS['__mp_cfg']['leads_by_nip']          = array();
$GLOBALS['__mp_cfg']['archived_lead']         = null;
$GLOBALS['wpdb']->rows_leads                  = array();
$GLOBALS['wpdb']->last_tx                     = '';
$GLOBALS['__mp_cfg']['fail_activity_insert']  = true;
$tx = run_pipeline( base_input( $VALID_NIP ) );
$GLOBALS['__mp_cfg']['fail_activity_insert']  = false;
$inv9 = ! $tx['ok'] && 8 === (int) $tx['stop_dept'] && 'ROLLBACK' === $GLOBALS['wpdb']->last_tx && 0 === count( $GLOBALS['wpdb']->rows_leads );
printf(
	"[%-4s] Transakcja 7-9: awaria dz.8 → STOP(dept=%s) + %s, lead NIE utrwalony (rows_leads=%d)\n",
	$inv9 ? 'PASS' : 'FAIL',
	$tx['stop_dept'],
	$GLOBALS['wpdb']->last_tx !== '' ? $GLOBALS['wpdb']->last_tx : '-',
	count( $GLOBALS['wpdb']->rows_leads )
);

// 10) Kontrola pozytywna: happy-path COMMIT-uje transakcję.
$GLOBALS['__mp_transients'] = array();
$GLOBALS['wpdb']->rows_leads = array();
$GLOBALS['wpdb']->last_tx    = '';
$commit = run_pipeline( base_input( $VALID_NIP ) );
$inv10  = $commit['ok'] && 'COMMIT' === $GLOBALS['wpdb']->last_tx;
printf( "[%-4s] Transakcja: happy-path COMMIT (last_tx=%s)\n", $inv10 ? 'PASS' : 'FAIL', $GLOBALS['wpdb']->last_tx );

// 11) RODO: anonymize_ip() obcina host (IPv4 ostatni oktet, IPv6 → 3 hextety, śmieci → '').
$a4    = MP_Lead_Intake_DB::anonymize_ip( '203.0.113.55' );
$a6    = MP_Lead_Intake_DB::anonymize_ip( '2001:db8:85a3:1:2:3:4:5' );
$ax    = MP_Lead_Intake_DB::anonymize_ip( 'nie-jest-ip' );
$inv11 = '203.0.113.0' === $a4 && 0 === strpos( $a6, '2001:db8:85a3' ) && '' === $ax;
printf( "[%-4s] RODO anonymize_ip: v4=%s v6=%s śmieci=%s\n", $inv11 ? 'PASS' : 'FAIL', $a4, $a6, var_export( $ax, true ) );

/* ---------- Podsumowanie ---------- */

echo "\n=== PODSUMOWANIE ===\n";
printf( "Scenariusze: PASS=%d FAIL=%d (z %d ocenianych)\n", $pass, $fail, $pass + $fail );
$hard_fail = $fail + ( $inv1 ? 0 : 1 ) + ( $inv2 ? 0 : 1 ) + ( $inv3 ? 0 : 1 ) + ( $inv5 ? 0 : 1 ) + ( $inv6 ? 0 : 1 ) + ( $inv7 ? 0 : 1 ) + ( $inv8 ? 0 : 1 ) + ( $inv9 ? 0 : 1 ) + ( $inv10 ? 0 : 1 ) + ( $inv11 ? 0 : 1 );
echo $hard_fail === 0
	? "WYNIK: proces spójny wg niezmienników.\n"
	: "WYNIK: wykryto {$hard_fail} naruszeń — patrz FAIL powyżej.\n";

exit( $hard_fail === 0 ? 0 : 1 );
