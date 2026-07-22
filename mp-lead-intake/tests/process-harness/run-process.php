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
require $PLUGIN . '/includes/class-mp-vat-verifier.php';

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
//    increment() woła się tu jawnie PRZED każdym run_pipeline(), bo w prawdziwym
//    życiu robi to pre-gate w class-mp-ajax.php (poza pipeline) — sam run_pipeline()
//    (z pominięciem ajax.php) niczego już nie inkrementuje, patrz niezmiennik 20.
$_SERVER['REMOTE_ADDR']              = '203.0.113.9';
$GLOBALS['__mp_transients']          = array();
$GLOBALS['__mp_cfg']['leads_by_nip'] = array();
$GLOBALS['wpdb']->rows_leads         = array();
$blocked_at = 0;
$i          = 0;
while ( $i < 12 ) {
	++$i;
	MP_D5_Agent_Rate_Limit::increment( '203.0.113.9' );
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
// rows_leads musi mieć odpowiadający wiersz (nie tylko __mp_cfg['archived_lead']) —
// reactivate_lead() od fixu race-condition (2026-07-22) atomowo "claimuje" wiersz
// w rows_leads (UPDATE...WHERE deleted_at IS NOT NULL) PRZED nadpisaniem reszty danych.
$GLOBALS['wpdb']->rows_leads = array(
	array(
		'id'         => 777,
		'nip'        => $VALID_NIP,
		'country'    => 'PL',
		'deleted_at' => '2026-01-01 00:00:00',
	),
);
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

/* ---------- Niezmienniki ASYNC (weryfikacja VAT w tle, dział 3 → P-1) ---------- */

echo "\n=== NIEZMIENNIKI ASYNC (weryfikacja VAT w tle) ===\n";

// Znajdź wiersz leada po id w fake $wpdb.
$find_lead = function ( $id ) {
	foreach ( $GLOBALS['wpdb']->rows_leads as $r ) {
		if ( (int) ( isset( $r['id'] ) ? $r['id'] : 0 ) === (int) $id ) {
			return $r;
		}
	}
	return null;
};

// Izolacja stanu dla ścieżki async.
$reset_async = function () {
	$GLOBALS['__mp_transients']            = array();
	$GLOBALS['__mp_cfg']['leads_by_nip']   = array();
	$GLOBALS['__mp_cfg']['archived_lead']  = null;
	$GLOBALS['__mp_cfg']['http_responses'] = array();
	$GLOBALS['wpdb']->rows_leads           = array();
	$GLOBALS['__mp_cron']                  = array();
};

// 12) Async cache-miss: lead tworzony jako vat_status='pending', BEZ HTTP w ścieżce żądania.
$reset_async();
$GLOBALS['__mp_http_calls'] = 0;
$a12    = run_pipeline( base_input( $VALID_NIP ) );
$lead12 = $find_lead( isset( $a12['final_data']['lead_id'] ) ? $a12['final_data']['lead_id'] : 0 );
$inv12  = $a12['ok'] && $lead12 && 'pending' === ( isset( $lead12['vat_status'] ) ? $lead12['vat_status'] : '' ) && 0 === (int) $GLOBALS['__mp_http_calls'];
printf(
	"[%-4s] Async: cache-miss → lead 'pending', 0 HTTP w żądaniu (vat_status=%s, http=%d)\n",
	$inv12 ? 'PASS' : 'FAIL',
	$lead12 && isset( $lead12['vat_status'] ) ? $lead12['vat_status'] : '-',
	(int) $GLOBALS['__mp_http_calls']
);

// 13) Worker rozstrzyga: VIES valid + WL 'Czynny' → 'checked', re-scoring (+50), mp_lead_verified.
$reset_async();
$prov13     = run_pipeline( base_input( $VALID_NIP ) );
$lid13      = (int) ( isset( $prov13['final_data']['lead_id'] ) ? $prov13['final_data']['lead_id'] : 0 );
$row13a     = $find_lead( $lid13 );
$prov_score = (int) ( $row13a && isset( $row13a['score'] ) ? $row13a['score'] : -1 );
$GLOBALS['__mp_cfg']['http_responses'] = array(
	'ec.europa.eu'     => array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'isValid' => true, 'name' => 'ACME' ) ) ),
	'wl-api.mf.gov.pl' => array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'result' => array( 'subject' => array( 'statusVat' => 'Czynny' ) ) ) ) ),
);
$verified_before = isset( $GLOBALS['__mp_actions']['mp_lead_verified'] ) ? $GLOBALS['__mp_actions']['mp_lead_verified'] : 0;
MP_Lead_Intake_Vat_Verifier::run( $lid13 );
$row13b = $find_lead( $lid13 );
$inv13  = $row13b
	&& 'checked' === ( isset( $row13b['vat_status'] ) ? $row13b['vat_status'] : '' )
	&& 1 === (int) ( isset( $row13b['vat_valid'] ) ? $row13b['vat_valid'] : -1 )
	&& 'Czynny' === ( isset( $row13b['company_status'] ) ? $row13b['company_status'] : '' )
	&& (int) ( isset( $row13b['score'] ) ? $row13b['score'] : 0 ) === $prov_score + 50
	&& ( isset( $GLOBALS['__mp_actions']['mp_lead_verified'] ) ? $GLOBALS['__mp_actions']['mp_lead_verified'] : 0 ) === $verified_before + 1;
printf(
	"[%-4s] Worker: 'checked', vat_valid=1, status=Czynny, score %d→%d (+50), mp_lead_verified++\n",
	$inv13 ? 'PASS' : 'FAIL',
	$prov_score,
	(int) ( $row13b && isset( $row13b['score'] ) ? $row13b['score'] : 0 )
);

// 14) Idempotencja: drugi run() = no-op (claim=0), bez ponownej emisji i HTTP.
$verified_before14 = isset( $GLOBALS['__mp_actions']['mp_lead_verified'] ) ? $GLOBALS['__mp_actions']['mp_lead_verified'] : 0;
$http_before14     = (int) $GLOBALS['__mp_http_calls'];
MP_Lead_Intake_Vat_Verifier::run( $lid13 );
$inv14 = ( isset( $GLOBALS['__mp_actions']['mp_lead_verified'] ) ? $GLOBALS['__mp_actions']['mp_lead_verified'] : 0 ) === $verified_before14
	&& (int) $GLOBALS['__mp_http_calls'] === $http_before14;
printf( "[%-4s] Idempotencja: powtórny worker to no-op (verified +0, http +0)\n", $inv14 ? 'PASS' : 'FAIL' );

// 15) Zły VAT (VIES INVALID) → vat_status='invalid', lead ZOSTAJE (bez soft-delete).
$reset_async();
$prov15 = run_pipeline( base_input( $VALID_NIP ) );
$lid15  = (int) ( isset( $prov15['final_data']['lead_id'] ) ? $prov15['final_data']['lead_id'] : 0 );
$GLOBALS['__mp_cfg']['http_responses'] = array(
	'ec.europa.eu' => array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'isValid' => false, 'userError' => 'INVALID' ) ) ),
);
MP_Lead_Intake_Vat_Verifier::run( $lid15 );
$row15 = $find_lead( $lid15 );
$inv15 = $row15
	&& 'invalid' === ( isset( $row15['vat_status'] ) ? $row15['vat_status'] : '' )
	&& empty( $row15['deleted_at'] )
	&& 0 === (int) ( isset( $row15['vat_valid'] ) ? $row15['vat_valid'] : -1 );
printf(
	"[%-4s] Zły VAT: vat_status=invalid, lead zostaje (deleted_at=%s, vat_valid=%s)\n",
	$inv15 ? 'PASS' : 'FAIL',
	var_export( $row15 && isset( $row15['deleted_at'] ) ? $row15['deleted_at'] : null, true ),
	var_export( $row15 && isset( $row15['vat_valid'] ) ? $row15['vat_valid'] : null, true )
);

// 16) Kolejkowanie: on_lead_created('pending') planuje 1 zdarzenie; druga próba nie duplikuje.
$reset_async();
$prov16 = run_pipeline( base_input( $VALID_NIP ) );
$lid16  = (int) ( isset( $prov16['final_data']['lead_id'] ) ? $prov16['final_data']['lead_id'] : 0 );
$GLOBALS['__mp_cron'] = array();
MP_Lead_Intake_Vat_Verifier::on_lead_created( $lid16, array( 'vat_status' => 'pending' ) );
$after_first = count( $GLOBALS['__mp_cron'] );
MP_Lead_Intake_Vat_Verifier::on_lead_created( $lid16, array( 'vat_status' => 'pending' ) );
$after_second   = count( $GLOBALS['__mp_cron'] );
$scheduled_hook = $after_first > 0 ? $GLOBALS['__mp_cron'][0]['hook'] : '-';
$inv16 = 1 === $after_first && 1 === $after_second && MP_Lead_Intake_Vat_Verifier::VERIFY_HOOK === $scheduled_hook;
printf(
	"[%-4s] Kolejka: on_lead_created('pending') → 1 zdarzenie (dedup: %d→%d, hook=%s)\n",
	$inv16 ? 'PASS' : 'FAIL',
	$after_first,
	$after_second,
	$scheduled_hook
);

// 17) Reconcile: zaległy 'pending' w bazie → dokolejkowanie zdarzenia weryfikacji.
$reset_async();
run_pipeline( base_input( $VALID_NIP ) ); // rows_leads ma leada 'pending'
$GLOBALS['__mp_cron'] = array();
MP_Lead_Intake_Vat_Verifier::reconcile();
$inv17 = count( $GLOBALS['__mp_cron'] ) >= 1 && MP_Lead_Intake_Vat_Verifier::VERIFY_HOOK === ( isset( $GLOBALS['__mp_cron'][0]['hook'] ) ? $GLOBALS['__mp_cron'][0]['hook'] : '' );
printf( "[%-4s] Reconcile: zaległy 'pending' dokolejkowany (zdarzeń=%d)\n", $inv17 ? 'PASS' : 'FAIL', count( $GLOBALS['__mp_cron'] ) );

// 18) Reaktywacja resetuje cykl VAT: zarchiwizowany lead z vat_attempts=5/'unknown'
//     po reaktywacji → vat_status='pending', vat_attempts=0, deleted_at wyczyszczone.
$reset_async();
$_SERVER['REMOTE_ADDR']      = '203.0.113.88';
$GLOBALS['wpdb']->rows_leads = array(
	array(
		'id'           => 900,
		'nip'          => $VALID_NIP,
		'vat_status'   => 'unknown',
		'vat_attempts' => 5,
		'deleted_at'   => '2026-01-01 00:00:00',
	),
);
$GLOBALS['__mp_cfg']['archived_lead'] = array(
	'id'         => 900,
	'nip'        => $VALID_NIP,
	'deleted_at' => '2026-01-01 00:00:00',
);
$re     = run_pipeline( base_input( $VALID_NIP ) );
$row900 = $find_lead( 900 );
$inv18  = $re['ok'] && $row900
	&& 'pending' === ( isset( $row900['vat_status'] ) ? $row900['vat_status'] : '' )
	&& 0 === (int) ( isset( $row900['vat_attempts'] ) ? $row900['vat_attempts'] : -1 )
	&& empty( $row900['deleted_at'] );
printf(
	"[%-4s] Reaktywacja resetuje VAT: vat_status=%s, vat_attempts=%s, deleted_at=%s\n",
	$inv18 ? 'PASS' : 'FAIL',
	$row900 && isset( $row900['vat_status'] ) ? $row900['vat_status'] : '-',
	var_export( $row900 && isset( $row900['vat_attempts'] ) ? $row900['vat_attempts'] : null, true ),
	var_export( $row900 && isset( $row900['deleted_at'] ) ? $row900['deleted_at'] : null, true )
);
$GLOBALS['__mp_cfg']['archived_lead'] = null;

// 19) Audyt (jakość kodu + architektura): VIES rozstrzyga, ale Biała lista akurat
//     nie odpowiada (WP_Error, brak klucza 'wl-api.mf.gov.pl' w canned responses) →
//     company_status NIE MOŻE zostać nadpisany wartością null, jeśli poprzednio był
//     już ustalony ('Czynny') — inaczej lead cicho traci +20 pkt scoringu na zawsze.
$reset_async();
$prov19 = run_pipeline( base_input( $VALID_NIP ) );
$lid19  = (int) ( isset( $prov19['final_data']['lead_id'] ) ? $prov19['final_data']['lead_id'] : 0 );
$GLOBALS['__mp_cfg']['http_responses'] = array(
	'ec.europa.eu'     => array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'isValid' => true, 'name' => 'ACME' ) ) ),
	'wl-api.mf.gov.pl' => array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'result' => array( 'subject' => array( 'statusVat' => 'Czynny' ) ) ) ) ),
);
MP_Lead_Intake_Vat_Verifier::run( $lid19 ); // 1. przebieg: WL OK → company_status='Czynny' utrwalony.
$row19a = $find_lead( $lid19 );

// Symulacja ponownej weryfikacji (np. po reconcile) z WL akurat niedostępnym —
// TYLKO klucz VIES w canned responses, WL bez dopasowania → WP_Error w stubie.
$GLOBALS['wpdb']->update(
	MP_Lead_Intake_DB::leads_table(),
	array( 'vat_status' => 'pending' ),
	array( 'id' => $lid19 )
);
$GLOBALS['__mp_cfg']['http_responses'] = array(
	'ec.europa.eu' => array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'isValid' => true, 'name' => 'ACME' ) ) ),
);
MP_Lead_Intake_Vat_Verifier::run( $lid19 );
$row19b = $find_lead( $lid19 );
$inv19  = $row19a && 'Czynny' === ( isset( $row19a['company_status'] ) ? $row19a['company_status'] : '' )
	&& $row19b
	&& 'Czynny' === ( isset( $row19b['company_status'] ) ? $row19b['company_status'] : '' )
	&& (int) ( isset( $row19b['score'] ) ? $row19b['score'] : -1 ) === (int) ( isset( $row19a['score'] ) ? $row19a['score'] : -2 );
printf(
	"[%-4s] WL-down po VIES-checked: company_status zachowany (1.=%s, 2.=%s), score bez zmian (%d→%d)\n",
	$inv19 ? 'PASS' : 'FAIL',
	$row19a && isset( $row19a['company_status'] ) ? $row19a['company_status'] : '-',
	$row19b && isset( $row19b['company_status'] ) ? $row19b['company_status'] : '-',
	$row19a && isset( $row19a['score'] ) ? (int) $row19a['score'] : -1,
	$row19b && isset( $row19b['score'] ) ? (int) $row19b['score'] : -1
);

// 20) Fix (znaleziony w testach manualnych na żywym WP, scenariusz 8/10): licznik
//     rate-limit ma teraz JEDYNE źródło inkrementu w increment() (wołane z pre-gate
//     w class-mp-ajax.php, symulowane tu wprost), więc rośnie NAWET gdy pipeline
//     odpada wcześnie (dz.3, zła suma kontrolna NIP — PRZED dz.5). Wcześniej
//     inkrement siedział tylko w agencie 5.3 (dz.5, nigdy nieosiągniętym przy
//     złym NIP) — flood błędnych zgłoszeń całkowicie omijał limit.
$reset_async();
$rl_ip                      = '203.0.113.20';
$GLOBALS['__mp_transients'] = array();
$_SERVER['REMOTE_ADDR']     = $rl_ip;
$BAD_NIP                    = '1234567890'; // suma kontrolna reszta=10 -> zawsze niepoprawny (STOP w dz.3).
$inv20                      = ! MP_D5_Agent_Rate_Limit::over_limit( $rl_ip );
for ( $i = 0; $i < MP_D5_Agent_Rate_Limit::LIMIT; $i++ ) {
	MP_D5_Agent_Rate_Limit::increment( $rl_ip ); // to samo, co teraz robi pre-gate w ajax.php.
	$bad20  = run_pipeline( base_input( $BAD_NIP ) );
	$inv20 = $inv20 && ! $bad20['ok'] && 3 === (int) $bad20['stop_dept'];
}
$inv20 = $inv20 && MP_D5_Agent_Rate_Limit::over_limit( $rl_ip );
printf(
	"[%-4s] Fix rate-limit: %d prób z błędnym NIP (STOP w dz.3, przed dz.5) i tak dobija limit (over_limit=%s)\n",
	$inv20 ? 'PASS' : 'FAIL',
	MP_D5_Agent_Rate_Limit::LIMIT,
	var_export( MP_D5_Agent_Rate_Limit::over_limit( $rl_ip ), true )
);

// 21) Race reaktywacji: drugie wywołanie reactivate_lead() na już zreaktywowanym leadzie
//     (deleted_at już NULL po pierwszym) musi przegrać (affected=0), nie cicho nadpisać
//     danych "zwycięzcy" — ochrona przed wyścigiem dwóch równoległych zgłoszeń tego samego,
//     zarchiwizowanego NIP (audyt 2026-07-22, znaleziony niezależnie przez 2 sub-agentów;
//     przed fixem oba wywołania kończyły się sukcesem, drugie cicho nadpisywało pierwsze).
$reset_async();
$GLOBALS['wpdb']->rows_leads = array(
	array(
		'id'         => 950,
		'nip'        => $VALID_NIP,
		'country'    => 'PL',
		'deleted_at' => '2026-01-01 00:00:00',
	),
);
$first_claim  = MP_Lead_Intake_DB::reactivate_lead( 950, array( 'company_name' => 'Pierwszy (wygrany)' ) );
$second_claim = MP_Lead_Intake_DB::reactivate_lead( 950, array( 'company_name' => 'Drugi (przegrany wyścigu)' ) );
$row950       = $find_lead( 950 );
$inv21        = 950 === $first_claim && false === $second_claim
	&& $row950 && 'Pierwszy (wygrany)' === ( isset( $row950['company_name'] ) ? $row950['company_name'] : '' );
printf(
	"[%-4s] Race reaktywacji: 2. równoległe wywołanie przegrywa (1.=%s, 2.=%s, dane=%s)\n",
	$inv21 ? 'PASS' : 'FAIL',
	var_export( $first_claim, true ),
	var_export( $second_claim, true ),
	$row950 && isset( $row950['company_name'] ) ? $row950['company_name'] : '-'
);

// 22) Cross-country: ten sam NIP (cyfrowo), różny kraj → NIE koliduje. Klucz unikalności
//     w BD-3 jest (country, nip), nie sam nip (fix DB-01, audyt 2026-07-22) — lokalne numery
//     firmowe różnych krajów UE mogą się cyfrowo pokrywać.
$reset_async();
$insert_pl = MP_Lead_Intake_DB::insert_lead(
	array(
		'company_name' => 'Firma PL',
		'nip'           => $VALID_NIP,
		'email'         => 'a@example.test',
		'country'       => 'PL',
	)
);
$insert_de = MP_Lead_Intake_DB::insert_lead(
	array(
		'company_name' => 'Firma DE',
		'nip'           => $VALID_NIP,
		'email'         => 'b@example.test',
		'country'       => 'DE',
	)
);
$inv22 = false !== $insert_pl && false !== $insert_de && $insert_pl !== $insert_de;
printf(
	"[%-4s] Cross-country: ten sam NIP, różne kraje (PL,DE) → oba INSERT-y ok (id=%s,%s)\n",
	$inv22 ? 'PASS' : 'FAIL',
	var_export( $insert_pl, true ),
	var_export( $insert_de, true )
);

/* ---------- Podsumowanie ---------- */

echo "\n=== PODSUMOWANIE ===\n";
printf( "Scenariusze: PASS=%d FAIL=%d (z %d ocenianych)\n", $pass, $fail, $pass + $fail );
$hard_fail = $fail + ( $inv1 ? 0 : 1 ) + ( $inv2 ? 0 : 1 ) + ( $inv3 ? 0 : 1 ) + ( $inv5 ? 0 : 1 ) + ( $inv6 ? 0 : 1 ) + ( $inv7 ? 0 : 1 ) + ( $inv8 ? 0 : 1 ) + ( $inv9 ? 0 : 1 ) + ( $inv10 ? 0 : 1 ) + ( $inv11 ? 0 : 1 )
	+ ( $inv12 ? 0 : 1 ) + ( $inv13 ? 0 : 1 ) + ( $inv14 ? 0 : 1 ) + ( $inv15 ? 0 : 1 ) + ( $inv16 ? 0 : 1 ) + ( $inv17 ? 0 : 1 ) + ( $inv18 ? 0 : 1 ) + ( $inv19 ? 0 : 1 ) + ( $inv20 ? 0 : 1 )
	+ ( $inv21 ? 0 : 1 ) + ( $inv22 ? 0 : 1 );
echo $hard_fail === 0
	? "WYNIK: proces spójny wg niezmienników.\n"
	: "WYNIK: wykryto {$hard_fail} naruszeń — patrz FAIL powyżej.\n";

exit( $hard_fail === 0 ? 0 : 1 );
