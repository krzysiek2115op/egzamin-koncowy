<?php
/**
 * Strona pokazowa postawiona POZA korzeniem domeny.
 *
 * Uruchamianie: wp eval-file /demo/tests/motyw-poza-korzeniem.php
 *
 * WP Playground — czyli jedyne srodowisko, w ktorym egzaminator to demo zobaczy —
 * serwuje WordPressa pod prefiksem sciezki `/scope:<id>/` (WordPress/wordpress-playground,
 * packages/php-wasm/scopes: „A scoped URL: http://localhost:8778/scope:my-site/wp-login.php").
 * Dla WordPressa to zwykla instalacja w PODKATALOGU i `home_url()` ten prefiks zawiera.
 *
 * A. POZYCJA MENU OD WTYCZKI. Motyw budowal adres pozycji dwuetapowo: brał z niej
 *    CALA sciezke URL jako „slug", a potem doklejal ja z powrotem do `home_url()`.
 *    W korzeniu domeny obie operacje sie znosza i wynik jest poprawny. W podkatalogu
 *    prefiks instalacji zostaje policzony DWA RAZY — /scope:0.77/scope:0.77/… — i jedyna
 *    pozycja nawigacji pochodzaca z menu WordPressa (podstrona formularza wtyczki 1)
 *    prowadzi w pustke. Pozostale pozycje sa „na sztywno" w motywie i dzialaja, wiec
 *    awaria wyglada na usterke tej jednej podstrony.
 *
 * B. STAN AKTYWNY. Ten sam „slug" sluzy do zaznaczania biezacej pozycji przez
 *    porownanie z `kk_current_slug()`, ktora zwraca `post_name`. Sciezka z prefiksem
 *    nie zrowna sie z nim nigdy.
 *
 * C. ODNOSNIKI W TRESCI STRON. Fragmenty HTML maja 19 odnosnikow zaczynajacych sie od
 *    ukosnika (`href="/kontakt/"`). Ukosnik na poczatku znaczy „korzen domeny", a nie
 *    „korzen witryny" — w podkatalogu kazdy z nich wychodzi POZA instalacje.
 *
 * D. KONTR-ASERCJE: w korzeniu domeny nic sie nie zmienia, a adresy zewnetrzne,
 *    kotwice i odnosniki juz bezwzgledne zostaja nietkniete.
 *
 * @package Kredyt_Kompas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_pk'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

$GLOBALS['mp_pk_stan'] = array(
	'home'    => get_option( 'home' ),
	'siteurl' => get_option( 'siteurl' ),
	'mods'    => get_theme_mod( 'nav_menu_locations', array() ),
	'page'    => get_option( 'mp_lead_intake_page_id', false ),
	'ok'      => get_option( 'mp_lead_intake_menu_ok', false ),
	'reason'  => get_option( 'mp_lead_intake_menu_reason', false ),
	'menu_id' => 0,
	'strona'  => 0,
);

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function pk_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_pk']['pass'];
		$GLOBALS['mp_pk']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_pk']['fail'];
	$GLOBALS['mp_pk']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik i przywraca stan witryny.
 *
 * Sprzatanie siedzi w tej samej funkcji co werdykt, bo ta sonda przestawia
 * `home`/`siteurl` calej witryny: porzucenie ich w stanie „podkatalog" zepsuloby
 * KAZDY nastepny test w tym srodowisku, a wygladaloby na jego wlasna usterke.
 *
 * @return void
 */
function pk_koniec() {
	if ( empty( $GLOBALS['mp_pk']['lines'] ) ) {
		return;
	}

	$stan = $GLOBALS['mp_pk_stan'];

	update_option( 'home', $stan['home'] );
	update_option( 'siteurl', $stan['siteurl'] );
	wp_cache_flush();

	if ( $stan['strona'] > 0 ) {
		wp_delete_post( (int) $stan['strona'], true );
	}
	if ( $stan['menu_id'] > 0 ) {
		wp_delete_nav_menu( (int) $stan['menu_id'] );
	}
	set_theme_mod( 'nav_menu_locations', $stan['mods'] );

	$opcje = array(
		'mp_lead_intake_page_id'     => 'page',
		'mp_lead_intake_menu_ok'     => 'ok',
		'mp_lead_intake_menu_reason' => 'reason',
	);
	foreach ( $opcje as $opcja => $klucz ) {
		if ( false === $stan[ $klucz ] ) {
			delete_option( $opcja );
		} else {
			update_option( $opcja, $stan[ $klucz ] );
		}
	}

	$r    = $GLOBALS['mp_pk'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_pk']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'pk_koniec' );

/* Sonda mierzy motyw strony pokazowej — bez niego nie ma czego mierzyc. */
if ( ! function_exists( 'kk_menu_items' ) ) {
	$GLOBALS['mp_pk']['lines'][] = '=== POMINIETE: motyw kredyt-kompas nie jest aktywny ===';
	pk_ok( false, 'motyw strony pokazowej musi byc aktywny, zeby to zmierzyc' );
	return;
}

/* ================================================ przygotowanie stanu jak w demo */

$menu = wp_get_nav_menu_object( 'PK Menu główne' );
$menu_id = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( 'PK Menu główne' );
$GLOBALS['mp_pk_stan']['menu_id'] = $menu_id;

$lok           = (array) get_theme_mod( 'nav_menu_locations', array() );
$lok['glowne'] = $menu_id;
set_theme_mod( 'nav_menu_locations', $lok );

delete_option( 'mp_lead_intake_page_id' );
MP_Lead_Intake_Page::create();

$strona = (int) get_option( 'mp_lead_intake_page_id' );
$GLOBALS['mp_pk_stan']['strona'] = $strona;

/* ==================================================================== warunki */

$GLOBALS['mp_pk']['lines'][] = '=== 0. warunki pomiaru ===';

pk_ok( $strona > 0, 'wtyczka 1 zalozyla podstrone formularza', 'id=' . $strona );

pk_ok(
	'1' === (string) get_option( 'mp_lead_intake_menu_ok' ),
	'i dopisala ja do menu WordPressa — czyli nawigacje rysuje MOTYW, nie awaryjny regex',
	'menu_ok=' . var_export( get_option( 'mp_lead_intake_menu_ok' ), true )
);

/* ============================================================ A. adres pozycji */

$GLOBALS['mp_pk']['lines'][] = '';
$GLOBALS['mp_pk']['lines'][] = '=== A. WordPress w podkatalogu: pozycja menu prowadzi tam, gdzie strona ===';

update_option( 'home', $GLOBALS['mp_pk_stan']['home'] . '/scope:0.77' );
update_option( 'siteurl', $GLOBALS['mp_pk_stan']['siteurl'] . '/scope:0.77' );
wp_cache_flush();

$prawdziwy = get_permalink( $strona );
$pozycje   = kk_menu_items();
$adres     = '';

foreach ( $pozycje as $slug => $etykieta ) {
	if ( 'Zapytanie ofertowe' === $etykieta ) {
		$adres = kk_url( $slug );
		break;
	}
}

pk_ok( '' !== $adres, 'podstrona formularza jest w nawigacji', 'pozycje=' . wp_json_encode( array_keys( $pozycje ) ) );

pk_ok(
	$adres === $prawdziwy,
	'a jej adres to permalink strony, nie sklejka z podwojonym prefiksem instalacji',
	'nawigacja=' . $adres . ' | strona=' . $prawdziwy
);

pk_ok(
	false === strpos( $adres, 'scope:0.77/scope:0.77' ),
	'prefiks instalacji nie zostal policzony dwa razy'
);

/* ============================================================ B. stan aktywny */

$GLOBALS['mp_pk']['lines'][] = '';
$GLOBALS['mp_pk']['lines'][] = '=== B. stan aktywny da sie w ogole trafic ===';

$post_name = get_post_field( 'post_name', $strona );
$klucze    = array_keys( $pozycje, 'Zapytanie ofertowe', true );

pk_ok(
	in_array( $post_name, $klucze, true ),
	'klucz pozycji rowna sie post_name, ktore zwraca kk_current_slug()',
	'klucz=' . wp_json_encode( $klucze ) . ' post_name=' . $post_name
);

/* ==================================================== C. odnosniki w tresci */

$GLOBALS['mp_pk']['lines'][] = '';
$GLOBALS['mp_pk']['lines'][] = '=== C. odnosniki w tresci stron zostaja w instalacji ===';

pk_ok(
	function_exists( 'kk_tresc_z_adresami' ),
	'motyw ma funkcje przeliczajaca odnosniki tresci na adresy witryny'
);

if ( function_exists( 'kk_tresc_z_adresami' ) ) {
	$wejscie = '<a href="/kontakt/#formularz">Kontakt</a>'
		. '<a href="/polityka-prywatnosci/">Polityka</a>'
		. '<a href="https://example.test/obcy/">Obcy</a>'
		. '<a href="#formularz">Kotwica</a>'
		. '<img src="https://images.unsplash.com/foto.jpg" alt="">';

	$wynik = kk_tresc_z_adresami( $wejscie );

	pk_ok(
		false !== strpos( $wynik, 'href="' . home_url( '/kontakt/' ) . '#formularz"' ),
		'odnosnik od ukosnika wskazuje na strone W instalacji',
		'wynik=' . $wynik
	);

	pk_ok(
		false === strpos( $wynik, 'href="/' ),
		'i nie zostal ani jeden odnosnik liczony od korzenia DOMENY'
	);

	pk_ok(
		false !== strpos( $wynik, 'href="https://example.test/obcy/"' ),
		'KONTR-ASERCJA: adres zewnetrzny nietkniety'
	);

	pk_ok(
		false !== strpos( $wynik, 'href="#formularz"' ),
		'KONTR-ASERCJA: kotwica nietknieta'
	);

	pk_ok(
		false !== strpos( $wynik, 'src="https://images.unsplash.com/foto.jpg"' ),
		'KONTR-ASERCJA: adres obrazka nietkniety'
	);

	pk_ok(
		kk_tresc_z_adresami( $wynik ) === $wynik,
		'KONTR-ASERCJA: powtorne przeliczenie niczego nie zmienia (idempotencja)'
	);
}

/*
 * Sprawdzenie na PRAWDZIWEJ tresci demo i przez PRAWDZIWY lancuch filtrow —
 * sonda wywolujaca tylko `kk_tresc_z_adresami()` bezposrednio przeszlaby takze
 * wtedy, gdyby nikt tej funkcji nigdzie nie podpial.
 */
$kontakt = get_page_by_path( 'kontakt' );

if ( $kontakt instanceof WP_Post ) {
	$wyrenderowana = apply_filters( 'the_content', $kontakt->post_content );

	pk_ok(
		false === strpos( $wyrenderowana, 'href="/' ),
		'wyrenderowana strona demo nie ma odnosnikow liczonych od korzenia domeny',
		'kontakt: ' . substr( (string) strstr( $wyrenderowana, 'href="/' ), 0, 60 )
	);

	pk_ok(
		false !== strpos( $wyrenderowana, 'href="' . home_url( '/polityka-prywatnosci/' ) . '"' ),
		'a odnosnik do polityki prywatnosci prowadzi w obrebie instalacji'
	);
}

/* ===================================================== D. kontr-asercja korzenia */

$GLOBALS['mp_pk']['lines'][] = '';
$GLOBALS['mp_pk']['lines'][] = '=== D. KONTR-ASERCJA: w korzeniu domeny nic sie nie zmienia ===';

update_option( 'home', $GLOBALS['mp_pk_stan']['home'] );
update_option( 'siteurl', $GLOBALS['mp_pk_stan']['siteurl'] );
wp_cache_flush();

$pozycje_korzen = kk_menu_items();
$adres_korzen   = '';

foreach ( $pozycje_korzen as $slug => $etykieta ) {
	if ( 'Zapytanie ofertowe' === $etykieta ) {
		$adres_korzen = kk_url( $slug );
		break;
	}
}

pk_ok(
	$adres_korzen === get_permalink( $strona ),
	'w korzeniu domeny adres pozycji dalej rowna sie permalinkowi',
	'nawigacja=' . $adres_korzen . ' | strona=' . get_permalink( $strona )
);

pk_ok(
	isset( $pozycje_korzen['kalkulator'] ) && 'Kalkulator zdolności' === $pozycje_korzen['kalkulator'],
	'a wlasne pozycje motywu zostaja na swoim miejscu'
);
