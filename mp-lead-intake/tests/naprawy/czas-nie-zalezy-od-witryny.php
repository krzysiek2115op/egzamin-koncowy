<?php
/**
 * P1-Z2 — czas zapisywany i pytany wedlug USTAWIENIA WITRYNY, a nie wedlug tego,
 * czego dotyczy.
 *
 * Uruchamianie: wp eval-file tests/naprawy/czas-nie-zalezy-od-witryny.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-Z2  Biala lista pytana o dobe ze strefy witryny; created_at w logu
 *            zapisywany czasem lokalnym, choc tabela stoi na GMT
 *
 * DWIE SPRAWY, JEDNA PRZYCZYNA: `current_time()` bez flagi GMT oddaje czas wedlug
 * ustawienia witryny, a oba te miejsca potrzebuja czegos innego.
 *
 * 1. BIALA LISTA. Rejestr MF zwraca status NA DANY DZIEN i jest rejestrem POLSKIM
 *    — doba wynika z prawa, nie z tego, jak administrator ustawil strefe w panelu.
 *    Komentarz w kodzie deklarowal juz naprawe („data w strefie WITRYNY, nie
 *    w UTC"), ale domyslna instalacja WordPressa ma pusty timezone_string i
 *    gmt_offset 0, wiec current_time('Y-m-d') === gmdate('Y-m-d') i deklarowana
 *    poprawka nie dzialala WCALE. Miedzy polnoca a 1:00/2:00 czasu polskiego
 *    pytanie szlo o poprzednia dobe, a wynik utrwalal sie w transiencie na zla
 *    dobe razem z company_status_checked = true.
 *
 * 2. LOG AKTYWNOSCI. Kolumna `created_at` ma DEFAULT CURRENT_TIMESTAMP, wiec
 *    wiekszosc wpisow bierze czas serwera bazy. Dwa miejsca ustawialy ja jednak
 *    RECZNIE, czasem lokalnym witryny. W jednej tabeli siedzialy wiec wiersze
 *    w dwoch roznych strefach — a log jest sortowany po created_at, wiec kolejnosc
 *    zdarzen potrafila sie odwrocic. Ten sam plik, ktory to robil, deklaruje przy
 *    innych kolumnach: „GMT, jak kazda inna kolumna datetime w tej tabeli".
 *
 * ZASIEG SZERSZY NIZ ZGLOSZENIE. Audyt wskazal jedno miejsce (Agent 7.3). Sonda
 * znalazla dwa dalsze — RODO-anonimizacja i rejestr ofert — czyli ten sam blad
 * w trzech miejscach, z ktorych zgloszone bylo jedno.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_cz'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegol.
 * @return bool
 */
function cz_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_cz']['pass'];
		$GLOBALS['mp_cz']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_cz']['fail'];
	$GLOBALS['mp_cz']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Data polska policzona NIEZALEZNIE od kodu wtyczki — wzorzec odniesienia.
 *
 * @param int $ts Znacznik czasu.
 * @return string
 */
function cz_wzorzec( $ts ) {
	$d = new DateTimeImmutable( '@' . (int) $ts );

	return $d->setTimezone( new DateTimeZone( 'Europe/Warsaw' ) )->format( 'Y-m-d' );
}

$GLOBALS['mp_cz']['lines'][] = '=== A. doba Bialej listy jest polska, nie taka jak ustawienie witryny ===';

$cz_ma_metode = method_exists( 'MP_D3_Agent_Company_Status', 'polish_date' );

cz_ok(
	$cz_ma_metode,
	'A1: istnieje jawna metoda liczaca polska dobe — data przestaje byc efektem ubocznym ustawienia'
);

if ( $cz_ma_metode ) {
	/*
	 * Znaczniki dobrane tak, ze rozstrzygaja: 23:30 UTC to w Polsce juz NASTEPNY
	 * dzien — zima o godzine, latem o dwie. Test nie zalezy wiec od tego, o ktorej
	 * jest uruchamiany.
	 */
	$zima = gmmktime( 23, 30, 0, 1, 1, 2026 );
	$lato = gmmktime( 22, 30, 0, 7, 1, 2026 );

	cz_ok(
		'2026-01-02' === MP_D3_Agent_Company_Status::polish_date( $zima )
			&& cz_wzorzec( $zima ) === MP_D3_Agent_Company_Status::polish_date( $zima ),
		'A2: zima o 23:30 UTC pytamy juz o nastepna dobe (CET = UTC+1)',
		'wynik=' . MP_D3_Agent_Company_Status::polish_date( $zima )
	);
	cz_ok(
		'2026-07-02' === MP_D3_Agent_Company_Status::polish_date( $lato )
			&& cz_wzorzec( $lato ) === MP_D3_Agent_Company_Status::polish_date( $lato ),
		'A3: latem o 22:30 UTC tak samo (CEST = UTC+2) — zmiana czasu jest uwzgledniona',
		'wynik=' . MP_D3_Agent_Company_Status::polish_date( $lato )
	);
}

/*
 * Sedno: wynik ma NIE ZALEZEC od ustawienia witryny. Przestawiamy strefe na dwie
 * skrajnie odlegle (Auckland i Honolulu dzieli 22 godziny) i zadamy tej samej
 * odpowiedzi. Przed naprawa te dwa klucze rozjezdzaly sie przez wieksza czesc doby.
 */
$cz_stara_strefa = get_option( 'timezone_string' );
$cz_stary_offset = get_option( 'gmt_offset' );

update_option( 'timezone_string', 'Pacific/Auckland' );
$cz_klucz_auckland = MP_D3_Agent_Company_Status::wl_cache_key( '1234563218' );

update_option( 'timezone_string', 'Pacific/Honolulu' );
$cz_klucz_honolulu = MP_D3_Agent_Company_Status::wl_cache_key( '1234563218' );

update_option( 'timezone_string', '' );
update_option( 'gmt_offset', 0 );
$cz_klucz_domyslny = MP_D3_Agent_Company_Status::wl_cache_key( '1234563218' );

update_option( 'timezone_string', $cz_stara_strefa );
update_option( 'gmt_offset', $cz_stary_offset );

cz_ok(
	$cz_klucz_auckland === $cz_klucz_honolulu && $cz_klucz_honolulu === $cz_klucz_domyslny,
	'A4: klucz cache jest ten sam przy trzech roznych strefach witryny',
	'auckland=' . $cz_klucz_auckland . ' honolulu=' . $cz_klucz_honolulu . ' domyslna=' . $cz_klucz_domyslny
);
cz_ok(
	false !== strpos( $cz_klucz_domyslny, cz_wzorzec( time() ) ),
	'A5: i jest to doba polska, nie UTC z domyslnej instalacji',
	'klucz=' . $cz_klucz_domyslny . ' oczekiwana doba=' . cz_wzorzec( time() )
);

$GLOBALS['mp_cz']['lines'][] = '';
$GLOBALS['mp_cz']['lines'][] = '=== B. jedna tabela, jedna strefa: log aktywnosci na GMT ===';

/*
 * Mierzymy to, co naprawde idzie do bazy. Strefa witryny jest przestawiona na
 * Auckland — gdyby zapis szedl czasem lokalnym, wartosc rozminelaby sie z GMT
 * o dwanascie godzin.
 */
update_option( 'timezone_string', 'Pacific/Auckland' );

global $wpdb;
$cz_tabela = MP_Lead_Intake_DB::activity_log_table();
$cz_przed  = (int) $wpdb->get_var( "SELECT MAX(id) FROM $cz_tabela" ); // phpcs:ignore WordPress.DB

// Sciezka RODO: kasownik po adresie e-mail. Leada zakladamy sami, zeby test byl
// samowystarczalny i nie zalezal od zawartosci bazy.
$cz_mail = 'czas-' . wp_rand( 100000, 999999 ) . '@example.test';
MP_Lead_Intake_DB::insert_lead(
	array(
		'company_name' => 'MP test czasu',
		'email'        => $cz_mail,
		'nip'          => (string) wp_rand( 1000000000, 9999999999 ),
		'country'      => 'PL',
		'status'       => 'new',
	)
);
MP_Lead_Intake_Privacy::erase_by_email( $cz_mail );

$cz_wiersze = $wpdb->get_results( // phpcs:ignore WordPress.DB
	$wpdb->prepare( "SELECT action, created_at FROM $cz_tabela WHERE id > %d", $cz_przed ),
	ARRAY_A
);

update_option( 'timezone_string', $cz_stara_strefa );
update_option( 'gmt_offset', $cz_stary_offset );

$cz_gmt   = strtotime( gmdate( 'Y-m-d H:i:s' ) );
$cz_rozne = array();
foreach ( (array) $cz_wiersze as $w ) {
	$roznica = abs( strtotime( (string) $w['created_at'] ) - $cz_gmt );
	if ( $roznica > 300 ) {
		$cz_rozne[] = $w['action'] . '=' . $w['created_at'];
	}
}

cz_ok(
	array() === $cz_rozne,
	'B1: wpisy zapisane recznie trafiaja do logu w GMT, mimo strefy witryny na Auckland',
	'rozjechane=' . implode( ' | ', $cz_rozne ) . ' | gmt=' . gmdate( 'Y-m-d H:i:s' )
);

/*
 * KONTR-ASERCJA na zasieg. Nie chodzi o to, ze jakis pojedynczy zapis jest w GMT,
 * tylko ze w kodzie nie zostalo ANI JEDNO reczne ustawienie created_at czasem
 * lokalnym. Czytamy zrodla, bo to jedyny sposob, zeby objac takze sciezki, ktorych
 * ten test nie uruchamia.
 */
$cz_pliki = array(
	'includes/class-mp-privacy.php',
	'includes/class-mp-offer-registry.php',
	'includes/pipeline/departments/class-mp-department-07.php',
);
$cz_winne = array();
foreach ( $cz_pliki as $cz_plik ) {
	$tresc = (string) file_get_contents( MP_LEAD_INTAKE_DIR . $cz_plik );
	foreach ( explode( "\n", $tresc ) as $nr => $linia ) {
		if ( false !== strpos( $linia, "current_time( 'mysql' )" ) ) {
			$cz_winne[] = $cz_plik . ':' . ( $nr + 1 );
		}
	}
}

cz_ok(
	array() === $cz_winne,
	'B2: w zadnym z trzech plikow nie zostalo current_time( mysql ) bez flagi GMT',
	'zostalo=' . implode( ' , ', $cz_winne )
);

echo implode( "\n", $GLOBALS['mp_cz']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_cz']['pass'], $GLOBALS['mp_cz']['fail'] );
echo ( 0 === $GLOBALS['mp_cz']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
