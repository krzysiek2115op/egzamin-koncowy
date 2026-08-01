<?php
/**
 * Ustalenie 1.25 — „nie ustalono" to nie to samo co „ustalono, ze nie".
 *
 * Uruchamianie: wp eval-file tests/naprawy/biala-lista-niepelna-odpowiedz.php
 *
 * Agent 3.3 brał `statusVat` z odpowiedzi Białej listy tak:
 *
 *   $status = isset( $body['result']['subject']['statusVat'] ) ? ... : null;
 *   set_transient( $cache_key, $status, 12 * HOUR_IN_SECONDS );
 *   return array( 'company_status' => $status, 'company_status_checked' => true );
 *
 * Dwie rzeczy naraz. Po pierwsze, przy odpowiedzi bez `subject` zwracal
 * `checked => true` przy `status => null` — czyli „sprawdzone", choc zadnego
 * statusu nie uzyskano. Po drugie, `null` szedl do cache na 12 h, a
 * `set_transient( key, null )` zapisuje pusty ciag: `get_transient()` oddaje
 * potem `''`, wiec warunek `false !== $cached` uznaje to za TRAFIENIE.
 * Przez pol doby kazdy lead z tym NIP-em dostawal „status sprawdzony" bez
 * statusu i nigdy nie trafial do weryfikatora w tle.
 *
 * Dokumentacja API (docs/dzial-03/biala-lista-vat-api.md, pobrana 2026-07-21)
 * rozstrzyga spor o wage: `statusVat` przyjmuje „Czynny", „Zwolniony" ORAZ
 * „Niezarejestrowany". NIP spoza wykazu dostaje wiec wlasny status, a nie
 * pusta odpowiedz. Brak `subject` NIE jest rozstrzygnieciem — to odpowiedz
 * niepelna i tak trzeba ja traktowac.
 *
 * Test podstawia odpowiedzi HTTP przez `pre_http_request`, wiec nie rusza sieci.
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-G1  Niepelna odpowiedz Bialej listy raportowana jako sprawdzony status
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_bl'] = array(
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
function bl_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_bl']['pass'];
		$GLOBALS['mp_bl']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_bl']['fail'];
	$GLOBALS['mp_bl']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function bl_dump() {
	if ( empty( $GLOBALS['mp_bl']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_bl'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_bl']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'bl_dump' );

/**
 * Podstawia odpowiedz Bialej listy zamiast realnego zapytania HTTP.
 *
 * @param array|null $cialo Tresc odpowiedzi (null = 500 bez tresci).
 * @return void
 */
function bl_udawaj( $cialo ) {
	$GLOBALS['mp_bl_cialo'] = $cialo;

	remove_all_filters( 'pre_http_request' );

	add_filter(
		'pre_http_request',
		static function ( $wynik, $args, $url ) {
			if ( false === strpos( (string) $url, 'wl-api.mf.gov.pl' ) ) {
				return $wynik;
			}

			++$GLOBALS['mp_bl_zapytan'];

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $GLOBALS['mp_bl_cialo'] ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		},
		10,
		3
	);
}

$GLOBALS['mp_bl_zapytan'] = 0;

// Tryb synchroniczny: chcemy sprawdzic sam resolve_wl(), bez odkladania do tla.
add_filter( 'mp_lead_intake_async_verification', '__return_false' );

$nip  = '1234563218';
$data = gmdate( 'Y-m-d' );

/**
 * Czysci cache Bialej listy dla NIP-u testowego.
 *
 * @return void
 */
function bl_wyczysc() {
	delete_transient( MP_D3_Agent_Company_Status::wl_cache_key( '1234563218', gmdate( 'Y-m-d' ) ) );
	delete_transient( MP_D3_Agent_Company_Status::wl_cache_key( '1234563218' ) );
}

/* ==================================================================== A */

$GLOBALS['mp_bl']['lines'][] = '=== A. odpowiedz pelna: status ustalony ===';

bl_wyczysc();
bl_udawaj(
	array(
		'result' => array(
			'subject' => array(
				'nip'       => $nip,
				'statusVat' => 'Czynny',
			),
		),
	)
);

$a = MP_D3_Agent_Company_Status::resolve_wl( $nip );

bl_ok( 'Czynny' === $a['company_status'], 'status „Czynny" przechodzi', 'status=' . var_export( $a['company_status'], true ) );
bl_ok( ! empty( $a['company_status_checked'] ), 'oznaczone jako sprawdzone' );

$cache_a = get_transient( MP_D3_Agent_Company_Status::wl_cache_key( $nip, $data ) );

bl_ok( 'Czynny' === $cache_a, 'ustalony status trafia do cache', 'cache=' . var_export( $cache_a, true ) );

/* ==================================================================== B */

$GLOBALS['mp_bl']['lines'][] = '';
$GLOBALS['mp_bl']['lines'][] = '=== B. „Niezarejestrowany" TEZ jest rozstrzygnieciem ===';

/*
 * To jest sedno sporu miedzy sedziami audytu. Wedlug dokumentacji API NIP
 * spoza wykazu dostaje wlasny status „Niezarejestrowany" — a wiec odpowiedz
 * BEZ `subject` nie jest „nie ma go w wykazie", tylko odpowiedzia niepelna.
 */
bl_wyczysc();
bl_udawaj(
	array(
		'result' => array(
			'subject' => array(
				'nip'       => $nip,
				'statusVat' => 'Niezarejestrowany',
			),
		),
	)
);

$b = MP_D3_Agent_Company_Status::resolve_wl( $nip );

bl_ok( 'Niezarejestrowany' === $b['company_status'], 'status „Niezarejestrowany" jest zwracany', 'status=' . var_export( $b['company_status'], true ) );
bl_ok( ! empty( $b['company_status_checked'] ), 'i liczy sie jako sprawdzony — bo jest rozstrzygnieciem' );

/* ==================================================================== C */

$GLOBALS['mp_bl']['lines'][] = '';
$GLOBALS['mp_bl']['lines'][] = '=== C. odpowiedz 200 BEZ subject: nie ustalono ===';

bl_wyczysc();
bl_udawaj( array( 'result' => array( 'requestId' => 'abc' ) ) );

$c = MP_D3_Agent_Company_Status::resolve_wl( $nip );

bl_ok( null === $c['company_status'], 'status pozostaje pusty', 'status=' . var_export( $c['company_status'], true ) );
bl_ok( empty( $c['company_status_checked'] ), 'NIE jest oznaczone jako sprawdzone' );

$cache_c = get_transient( MP_D3_Agent_Company_Status::wl_cache_key( $nip, $data ) );

bl_ok( false === $cache_c, 'nieustalony status NIE trafia do cache', 'cache=' . var_export( $cache_c, true ) );

/* ==================================================================== D */

$GLOBALS['mp_bl']['lines'][] = '';
$GLOBALS['mp_bl']['lines'][] = '=== D. brak wpisu w cache oznacza ponowna probe ===';

/*
 * Wlasciwy skutek bledu: kolejny lead z tym samym NIP-em ma jeszcze raz
 * zapytac Biala liste, a nie odczytac „sprawdzone" z zatrutego cache.
 */
$przed = $GLOBALS['mp_bl_zapytan'];
$d     = MP_D3_Agent_Company_Status::resolve_wl( $nip );
$po    = $GLOBALS['mp_bl_zapytan'];

bl_ok( $po > $przed, 'druga proba faktycznie pyta Biala liste', 'zapytan przybylo: ' . ( $po - $przed ) );
bl_ok( empty( $d['company_status_checked'] ), 'i nadal nie udaje, ze cos ustalila' );
bl_ok(
	! isset( $d['company_status_source'] ) || 'cache' !== $d['company_status_source'],
	'wynik nie pochodzi z cache',
	'source=' . ( isset( $d['company_status_source'] ) ? $d['company_status_source'] : '-' )
);

/* ==================================================================== E */

$GLOBALS['mp_bl']['lines'][] = '';
$GLOBALS['mp_bl']['lines'][] = '=== E. tryb w tle: lead trafia do ponowienia ===';

remove_filter( 'mp_lead_intake_async_verification', '__return_false' );
bl_wyczysc();

$agent = new MP_D3_Agent_Company_Status();
$wynik = $agent->run( new MP_Context( array( 'nip' => $nip ) ) );
$dane  = $wynik->get_data();

bl_ok( ! empty( $dane['company_status_pending'] ), 'brak wpisu w cache odklada sprawdzenie do tla' );
bl_ok( empty( $dane['company_status_checked'] ), 'i nie melduje sprawdzenia' );

/*
 * Kontrola przeciwna: gdy w cache siedzi REALNY status, tryb w tle ma go uzyc
 * i NIE odkladac niczego. Bez tej asercji „naprawa" mogla polegac na
 * wylaczeniu cache w ogole.
 */
set_transient( MP_D3_Agent_Company_Status::wl_cache_key( $nip ), 'Czynny', HOUR_IN_SECONDS );

$wynik2 = $agent->run( new MP_Context( array( 'nip' => $nip ) ) );
$dane2  = $wynik2->get_data();

bl_ok( 'Czynny' === $dane2['company_status'], 'realny status z cache nadal jest uzywany', 'status=' . var_export( $dane2['company_status'], true ) );
bl_ok( ! empty( $dane2['company_status_checked'] ), 'i liczy sie jako sprawdzony' );
bl_ok( empty( $dane2['company_status_pending'] ), 'nic nie idzie do ponowienia' );

/*
 * P1-G5. Data zapytania do Bialej listy liczona w UTC zamiast w strefie witryny.
 *
 * API zwraca `statusVat` NA DANY DZIEN. W polskiej strefie (UTC+1/UTC+2)
 * `gmdate('Y-m-d')` miedzy polnoca a 1:00/2:00 wskazuje jeszcze dzien poprzedni,
 * wiec firma zarejestrowana jako podatnik VAT czynny od dzis dostawala status
 * „Niezarejestrowany" — prawdziwy, ale na wczoraj — i wynik byl zapisywany jako
 * sprawdzony. Klucz cache dotyczyl tej samej, zlej doby, wiec bledny status
 * utrwalal sie na kolejne 12 godzin.
 */
$GLOBALS['mp_bl']['lines'][] = '';
$GLOBALS['mp_bl']['lines'][] = '=== D. data pytania w strefie witryny, nie w UTC ===';

$zrodlo_d3 = file_get_contents( dirname( dirname( __DIR__ ) ) . '/includes/pipeline/departments/class-mp-department-03.php' );

bl_ok(
	is_string( $zrodlo_d3 ) && false === strpos( $zrodlo_d3, "gmdate( 'Y-m-d' )" ),
	'data nie jest juz liczona w UTC'
);
/*
 * ZMIANA REGULY (P1-Z2). Ta asercja wymagala wczesniej `current_time( 'Y-m-d' )`,
 * czyli daty ze strefy WITRYNY. Bylo to lepsze niz UTC, ale nadal zalezne od
 * ustawienia w panelu — a na DOMYSLNEJ instalacji WordPressa (pusty
 * timezone_string, gmt_offset 0) `current_time` daje dokladnie to samo co
 * `gmdate`, wiec deklarowana naprawa nie dzialala tam wcale. Biala lista jest
 * rejestrem POLSKIM, wiec doba ma wynikac z prawa, nie z konfiguracji witryny.
 */
bl_ok(
	is_string( $zrodlo_d3 ) && 0 === substr_count( $zrodlo_d3, "current_time( 'Y-m-d' )" ),
	'data nie jest juz brana ze strefy witryny',
	'wystapien: ' . ( is_string( $zrodlo_d3 ) ? substr_count( $zrodlo_d3, "current_time( 'Y-m-d' )" ) : -1 )
);
bl_ok(
	is_string( $zrodlo_d3 ) && 2 === substr_count( $zrodlo_d3, 'self::polish_date()' ),
	'obie daty — pytania do API i klucza cache — licza dobe POLSKA',
	'wystapien: ' . ( is_string( $zrodlo_d3 ) ? substr_count( $zrodlo_d3, 'self::polish_date()' ) : 0 )
);

/*
 * Dowod, ze roznica jest realna — niezalezny od tego, ktora jest godzina.
 * Bierzemy konkretna chwile: 31 grudnia 23:30 UTC. W strefie Europe/Warsaw to
 * juz 1 stycznia. Gdyby zapytanie do API szlo z data UTC, dotyczyloby innej
 * doby niz ta, w ktorej klient wysyla formularz.
 *
 * Strefa jest ustawiana na czas testu i przywracana — testowy WordPress stoi
 * w UTC, wiec bez tego obie funkcje dawalyby te sama wartosc i test
 * przechodzilby na pusto.
 */
$strefa_przed = get_option( 'timezone_string' );
$offset_przed = get_option( 'gmt_offset' );
update_option( 'timezone_string', 'Europe/Warsaw' );

$chwila = mktime( 23, 30, 0, 12, 31, 2026 ) - ( (int) date( 'Z', mktime( 23, 30, 0, 12, 31, 2026 ) ) );
$w_utc     = gmdate( 'Y-m-d', $chwila );
$w_witynie = wp_date( 'Y-m-d', $chwila );

bl_ok(
	$w_utc !== $w_witynie,
	'ta sama chwila to w UTC i w strefie witryny INNA data',
	'UTC=' . $w_utc . ' witryna=' . $w_witynie
);

update_option( 'timezone_string', $strefa_przed );
update_option( 'gmt_offset', $offset_przed );

bl_wyczysc();
remove_all_filters( 'pre_http_request' );
