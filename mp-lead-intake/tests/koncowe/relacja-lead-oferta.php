<?php
/**
 * Test na ZYWYM WordPressie: relacja lead -> oferta w BD-3 + produkty w zdarzeniu.
 *
 * Uruchamianie: wp eval-file tests/koncowe/relacja-lead-oferta.php
 * Srodowisko: WordPress + MySQL/MariaDB, aktywna wtyczka MP Lead Intake
 * (pozostale dwie nie sa wymagane — zdarzenia emitujemy tu wprost).
 *
 * Zakres wynika ze zlecenia:
 *  - BD-3 opisane jako „relacja lead -> oferta -> aktywnosc", a tabela
 *    `wp_mp_offers` stala pusta;
 *  - cel biznesowy: proces „bez recznego kopiowania danych", a produkty i
 *    wolumen podane przez klienta nie jechaly dalej niz do BD-3.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_r'] = array( 'pass' => 0, 'fail' => 0, 'lines' => array() );

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function mr_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_r']['pass'];
		$GLOBALS['mp_r']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_r']['fail'];
	$GLOBALS['mp_r']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function mr_dump() {
	if ( empty( $GLOBALS['mp_r']['lines'] ) ) {
		return;
	}

	$r   = $GLOBALS['mp_r'];
	$out = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-p1-relacja.txt' : '/tmp/mp-p1-relacja.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_r']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'mr_dump' );

/**
 * Poprawny NIP (wagi 6,5,7,2,3,4,5,6,7) — kazdy przebieg potrzebuje nowego,
 * bo pipeline deduplikuje leady po numerze.
 *
 * @param int $seed Ziarno.
 * @return string
 */
function mr_nip( $seed ) {
	$weights = array( 6, 5, 7, 2, 3, 4, 5, 6, 7 );

	for ( $i = 0; $i < 200; $i++ ) {
		$base = str_pad( (string) ( ( $seed + $i ) % 1000000000 ), 9, '0', STR_PAD_LEFT );
		$sum  = 0;

		for ( $k = 0; $k < 9; $k++ ) {
			$sum += $weights[ $k ] * (int) $base[ $k ];
		}

		if ( 10 !== $sum % 11 ) {
			return $base . ( $sum % 11 );
		}
	}

	return '1234563218';
}

global $wpdb;

$leads_t  = MP_Lead_Intake_DB::leads_table();
$offers_t = MP_Lead_Intake_DB::offers_table();

$GLOBALS['mp_r']['lines'][] = '=== A. Produkty i wolumen jada w zdarzeniu mp_lead_created ===';

$GLOBALS['mr_payload'] = null;
add_action(
	'mp_lead_created',
	function ( $lead_id, $payload ) {
		$GLOBALS['mr_payload'] = $payload;
	},
	1,
	2
);

$seria    = substr( (string) time(), -6 );
$nip      = mr_nip( (int) ( microtime( true ) * 100 ) % 900000000 + 700000 );
$produkty = 'linia montazowa, podajnik tasmowy';
$wolumen  = '250 szt./rok';

$ctx = new MP_Context(
	array(
		'company_name'      => 'Relacja Test ' . substr( $nip, 0, 4 ),
		'nip'               => $nip,
		'email'             => 'relacja-' . substr( $nip, 0, 6 ) . '@example.test',
		'phone'             => '+48555000111',
		'country'           => 'PL',
		'segment'           => 'roboty',
		'products'          => $produkty,
		'est_volume'        => $wolumen,
		'message'           => 'Prosze o oferte.',
		'consent_rodo'      => true,
		'consent_marketing' => false,
		'mp_nonce'          => wp_create_nonce( 'mp_lead_intake' ),
	)
);

$res     = MP_Pipeline_Factory::make()->run( $ctx );
$lead_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$leads_t} WHERE nip = %s", $nip ) ); // phpcs:ignore

mr_ok( $res->is_ok(), 'pipeline zakonczony sukcesem', wp_json_encode( $res->get_errors() ) );
mr_ok( $lead_id > 0, 'lead zapisany w BD-3' );

$payload = is_array( $GLOBALS['mr_payload'] ) ? $GLOBALS['mr_payload'] : array();
mr_ok( isset( $payload['products'] ), 'zdarzenie niesie pole products' );
mr_ok( isset( $payload['est_volume'] ), 'zdarzenie niesie pole est_volume' );
mr_ok( isset( $payload['products'] ) && false !== strpos( (string) $payload['products'], 'linia montazowa' ), 'products to TA SAMA tresc, ktora podal klient', isset( $payload['products'] ) ? (string) $payload['products'] : '(brak)' );
mr_ok( isset( $payload['est_volume'] ) && (string) $payload['est_volume'] === $wolumen, 'est_volume przekazany bez zmian' );

// Kontrola granicy: zdarzenie nie moze niesc danych CUDZYCH leadow ani sekretow.
$zabronione = array( 'mp_nonce', 'mp_hp', 'leads', 'offers', 'activity_log', 'ip', 'ip_address' );
$przecieki  = array();
foreach ( $zabronione as $klucz ) {
	if ( isset( $payload[ $klucz ] ) ) {
		$przecieki[] = $klucz;
	}
}
mr_ok( empty( $przecieki ), 'zdarzenie nie niesie tokenu, honeypota ani danych cudzych leadow', wp_json_encode( $przecieki ) );

$GLOBALS['mp_r']['lines'][] = '';
$GLOBALS['mp_r']['lines'][] = '=== B. Wskaznik oferty w wp_mp_offers (BD-3) ===';

$przed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$offers_t} WHERE lead_id = %d", $lead_id ) ); // phpcs:ignore

do_action(
	'mp_offer_created',
	9001,
	array(
		'lead_id'      => $lead_id,
		'offer_id'     => 9001,
		'offer_number' => 'OF/2026/' . $seria,
		'status'       => 'draft',
		'gross_grosze' => 123456,
		'currency'     => 'PLN',
	)
);

$wiersz = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE lead_id = %d ORDER BY id DESC LIMIT 1", $lead_id ), ARRAY_A ); // phpcs:ignore

if ( mr_ok( is_array( $wiersz ), 'oferta zapisana w BD-3 (relacja lead -> oferta)' ) ) {
	mr_ok( 'OF/2026/' . $seria === (string) $wiersz['offer_number'], 'numer oferty przepisany', (string) $wiersz['offer_number'] );
	mr_ok( '1234.56' === (string) $wiersz['total_amount'], 'kwota przeliczona z groszy na jednostki glowne', (string) $wiersz['total_amount'] );
	mr_ok( 'PLN' === (string) $wiersz['currency'], 'waluta przepisana' );
	mr_ok( 'draft' === (string) $wiersz['status'], 'status przepisany' );
}

// Zatwierdzenie tej samej oferty ma AKTUALIZOWAC wiersz, nie zakladac drugiego.
do_action(
	'mp_offer_approved',
	9001,
	array(
		'lead_id'      => $lead_id,
		'offer_id'     => 9001,
		'offer_number' => 'OF/2026/' . $seria,
		'status'       => 'approved',
		'gross_grosze' => 123456,
		'currency'     => 'PLN',
	)
);

$po = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$offers_t} WHERE lead_id = %d", $lead_id ) ); // phpcs:ignore
mr_ok( $po === $przed + 1, 'zatwierdzenie NIE zalozylo drugiego wiersza (jeden wiersz na oferte)', "przed={$przed} po={$po}" );
mr_ok( 'approved' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$offers_t} WHERE lead_id = %d ORDER BY id DESC LIMIT 1", $lead_id ) ), 'status wiersza zaktualizowany na approved' ); // phpcs:ignore

// Korekta oferty (nowa wersja pod tym samym numerem) tez nie mnozy wierszy.
do_action(
	'mp_offer_created',
	9002,
	array(
		'lead_id'      => $lead_id,
		'offer_id'     => 9002,
		'offer_number' => 'OF/2026/' . $seria,
		'status'       => 'draft',
		'gross_grosze' => 999900,
		'currency'     => 'PLN',
	)
);
mr_ok( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$offers_t} WHERE lead_id = %d", $lead_id ) ) === $przed + 1, 'korekta pod tym samym numerem nie mnozy wierszy' ); // phpcs:ignore
mr_ok( '9999.00' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT total_amount FROM {$offers_t} WHERE lead_id = %d ORDER BY id DESC LIMIT 1", $lead_id ) ), 'korekta zaktualizowala kwote' ); // phpcs:ignore

// Druga oferta dla tego samego leada = drugi wiersz.
do_action(
	'mp_offer_created',
	9003,
	array(
		'lead_id'      => $lead_id,
		'offer_id'     => 9003,
		'offer_number' => 'OF/2026/' . $seria . 'B',
		'status'       => 'draft',
		'gross_grosze' => 5000,
		'currency'     => 'EUR',
	)
);
mr_ok( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$offers_t} WHERE lead_id = %d", $lead_id ) ) === $przed + 2, 'inny numer oferty = osobny wiersz' ); // phpcs:ignore

$GLOBALS['mp_r']['lines'][] = '';
$GLOBALS['mp_r']['lines'][] = '=== C. Odmowy — czego rejestr NIE zapisuje ===';

$stan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$offers_t}" ); // phpcs:ignore

do_action( 'mp_offer_created', 9100, array( 'lead_id' => 0, 'offer_number' => 'OF/2026/' . $seria . 'D', 'status' => 'draft' ) );
mr_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$offers_t}" ) === $stan, 'oferta reczna (bez leada) nie tworzy wiersza' ); // phpcs:ignore

do_action( 'mp_offer_created', 9101, array( 'lead_id' => $lead_id, 'status' => 'draft' ) );
mr_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$offers_t}" ) === $stan, 'oferta bez numeru nie tworzy wiersza' ); // phpcs:ignore

do_action( 'mp_offer_created', 9102, array( 'lead_id' => 999999, 'offer_number' => 'OF/2026/' . $seria . 'E', 'status' => 'draft' ) );
mr_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$offers_t}" ) === $stan, 'oferta wskazujaca NIEISTNIEJACY lead nie tworzy sieroty' ); // phpcs:ignore

do_action( 'mp_offer_created', 9103, array( 'lead_id' => $lead_id, 'offer_number' => 'OF/2026/' . $seria . 'C', 'status' => 'draft', 'gross_grosze' => 'nie-liczba', 'currency' => 'ZLOTOWKI' ) );
$smiec = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE lead_id = %d AND offer_number = %s", $lead_id, 'OF/2026/' . $seria . 'C' ), ARRAY_A ); // phpcs:ignore
mr_ok( is_array( $smiec ) && '0.00' === (string) $smiec['total_amount'], 'kwota nieliczbowa nie trafia do bazy (zostaje 0.00)' );
mr_ok( is_array( $smiec ) && 'PLN' === (string) $smiec['currency'], 'niepoprawna waluta zastapiona domyslna' );

mr_dump();
