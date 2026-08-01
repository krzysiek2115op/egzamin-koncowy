<?php
/**
 * P1-G8 / P1-G9 — aktywacja wtyczki milczala albo mowila nie to.
 *
 * Uruchamianie: wp eval-file tests/naprawy/ostrzezenia-aktywacji.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-G8  Nieudane utworzenie pod-strony konczylo sie CISZA
 *   - P1-G9  Jedno ostrzezenie podawalo jedna przyczyne z trzech mozliwych
 *
 * P1-G8. `create()` zapisywalo opcje tylko w galezi sukcesu:
 * `if ( $page_id && ! is_wp_error( $page_id ) ) { ... }` — bez `else`. Gdy
 * wp_insert_post() zawiodlo (inna wtyczka na filtrze, brak uprawnien, blad
 * bazy), nie powstawala ani strona, ani zaden slad. Co gorsza, jedyne
 * ostrzezenie w adminie bylo wtedy WYCISZONE domyslka: maybe_admin_notice()
 * wychodzi, gdy OPTION_MENU_OK ma wartosc inna niz '0', a nieustawiona opcja
 * zwraca domyslne '1'. Administrator widzial komunikat WordPressa „Wtyczka
 * wlaczona" i zakladal, ze formularz dziala. Klienci nie mieli gdzie go wypelnic.
 *
 * P1-G9. Ostrzezenie o menu podawalo JEDNA przyczyne — „Twoj motyw nie
 * rejestruje standardowego menu WordPressa" — a `add_to_menus()` zwraca false
 * w trzech roznych sytuacjach: brak lokalizacji menu, lokalizacje zarejestrowane
 * ale bez przypisanego menu (`$menu_id <= 0` → continue) oraz nieudane
 * wp_update_nav_menu_item(). Administrator motywu, ktory menu rejestruje
 * poprawnie, dostawal wiec diagnoze nieprawdziwa i instrukcje naprawy, ktora
 * nie moze pomoc.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_oa'] = array(
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
function oa_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_oa']['pass'];
		$GLOBALS['mp_oa']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_oa']['fail'];
	$GLOBALS['mp_oa']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Zwraca HTML ostrzezen wypisanych przez wtyczke w panelu.
 *
 * @return string
 */
function oa_ostrzezenia() {
	ob_start();
	MP_Lead_Intake_Page::maybe_admin_notice();

	return (string) ob_get_clean();
}

/**
 * Czysci stan opcji wtyczki przed kazdym przypadkiem.
 *
 * @return void
 */
function oa_wyczysc() {
	delete_option( MP_Lead_Intake_Page::OPTION );
	delete_option( MP_Lead_Intake_Page::OPTION_MENU_OK );

	if ( defined( 'MP_Lead_Intake_Page::OPTION_MENU_REASON' ) || constant_exists_mp_oa( 'OPTION_MENU_REASON' ) ) {
		delete_option( MP_Lead_Intake_Page::OPTION_MENU_REASON );
	}

	if ( constant_exists_mp_oa( 'OPTION_PAGE_ERROR' ) ) {
		delete_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );
	}
}

/**
 * Czy klasa strony ma stala o podanej nazwie.
 *
 * @param string $nazwa Nazwa stalej.
 * @return bool
 */
function constant_exists_mp_oa( $nazwa ) {
	return defined( 'MP_Lead_Intake_Page::' . $nazwa );
}

/**
 * Chowa w szkicach wszystkie opublikowane strony z krotkim kodem formularza.
 *
 * Od 1.3.7 ostrzezenie o nieutworzonej pod-stronie sprawdza STAN FAKTYCZNY, a nie
 * sam slad po bledzie (U-13/U-14): jesli formularz stoi juz na jakiejs opublikowanej
 * stronie, wtyczka przyjmuje ja za swoja i gasi komunikat. Kazdy przypadek, ktory
 * mierzy TRESC tego ostrzezenia, musi wiec najpierw usunac z pola widzenia strone
 * zalozona przez aktywacje — inaczej mierzy cudza strone, przypadkiem obecna w bazie.
 *
 * @return int[] Identyfikatory schowanych stron.
 */
function oa_schowaj_strony_z_formularzem() {
	$znalezione = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			's'           => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
		)
	);

	foreach ( (array) $znalezione as $id ) {
		wp_update_post(
			array(
				'ID'          => (int) $id,
				'post_status' => 'draft',
			)
		);
	}

	return array_map( 'intval', (array) $znalezione );
}

/**
 * Przywraca strony schowane przez oa_schowaj_strony_z_formularzem().
 *
 * @param array $ids Identyfikatory.
 * @return void
 */
function oa_przywroc_strony( array $ids ) {
	foreach ( $ids as $id ) {
		wp_update_post(
			array(
				'ID'          => (int) $id,
				'post_status' => 'publish',
			)
		);
	}
}

// Ostrzezenia widzi wylacznie administrator — bez tego maybe_admin_notice()
// wychodzi na pierwszym warunku i test mierzylby brak uprawnien, nie kod.
$admin = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_set_current_user( $admin );

$stara_strona = (int) get_option( MP_Lead_Intake_Page::OPTION );

$GLOBALS['mp_oa']['lines'][] = '=== A. pod-strona nie powstala ===';

oa_wyczysc();

/*
 * wp_insert_post() zwraca 0 przy „pustej" tresci — to najprostszy sposob, zeby
 * odtworzyc nieudany zapis bez psucia bazy. Dokladnie tak zachowuje sie inna
 * wtyczka, ktora odrzuci wpis na swoim filtrze.
 */
$oa_schowane_a = oa_schowaj_strony_z_formularzem();

add_filter( 'wp_insert_post_empty_content', '__return_true' );
MP_Lead_Intake_Page::create();
remove_filter( 'wp_insert_post_empty_content', '__return_true' );

$id_po_bledzie = (int) get_option( MP_Lead_Intake_Page::OPTION );
$html_bledu    = oa_ostrzezenia();

oa_przywroc_strony( $oa_schowane_a );

oa_ok(
	0 === $id_po_bledzie,
	'warunek scenariusza: strona faktycznie nie powstala',
	'page_id=' . $id_po_bledzie
);
oa_ok(
	'' !== trim( $html_bledu ),
	'administrator DOSTAJE ostrzezenie, a nie cisze',
	'html=' . wp_strip_all_tags( $html_bledu )
);
/*
 * mb_stripos, nie stripos: „Ł" jest w UTF-8 dwubajtowe, a bajtowe stripos()
 * nie zestawi go z „ł" — asercja padalaby na kodowaniu, nie na tresci.
 */
oa_ok(
	false !== mb_stripos( $html_bledu, 'nie powstała' ),
	'ostrzezenie mowi wprost, ze strona z formularzem nie powstala',
	'html=' . wp_strip_all_tags( $html_bledu )
);
oa_ok(
	false === stripos( $html_bledu, 'nie rejestruje standardow' ),
	'ostrzezenie NIE zrzuca winy na menu motywu',
	'html=' . wp_strip_all_tags( $html_bledu )
);

$GLOBALS['mp_oa']['lines'][] = '';
$GLOBALS['mp_oa']['lines'][] = '=== B. trzy powody braku wpisu w menu, trzy rozne komunikaty ===';

// Strona istnieje — badamy juz tylko sciezke dokladania do menu.
oa_wyczysc();
$strona_id = wp_insert_post(
	array(
		'post_title'   => 'MP test ostrzezenia',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[mp_lead_intake_form]',
	)
);
update_option( MP_Lead_Intake_Page::OPTION, (int) $strona_id );

$bez_lokalizacji = function () {
	return array();
};

/*
 * „Motyw nie rejestruje menu" trzeba odtworzyc W CALOSCI: nie wystarczy pusta
 * lista PRZYPISAN, bo od 1.3.7 wtyczka pyta osobno o REJESTRACJE. Aktywny motyw
 * w tym srodowisku lokalizacje rejestruje, wiec bez schowania rejestru test
 * mierzylby przypadek sasiedni — ten z sekcji nizej.
 */
$oa_rejestr_kopia                     = $GLOBALS['_wp_registered_nav_menus'] ?? array();
$GLOBALS['_wp_registered_nav_menus']  = array();

add_filter( 'theme_mod_nav_menu_locations', $bez_lokalizacji, 99 );
MP_Lead_Intake_Page::refresh_menu_status();
$html_bez_lokalizacji = oa_ostrzezenia();
remove_filter( 'theme_mod_nav_menu_locations', $bez_lokalizacji, 99 );

$GLOBALS['_wp_registered_nav_menus'] = $oa_rejestr_kopia;

oa_ok(
	'' !== trim( $html_bez_lokalizacji ),
	'motyw bez lokalizacji menu daje ostrzezenie'
);
oa_ok(
	false !== stripos( $html_bez_lokalizacji, 'nie rejestruje' ),
	'i to wlasnie o nierejestrowaniu menu przez motyw',
	'html=' . wp_strip_all_tags( $html_bez_lokalizacji )
);

$puste_lokalizacje = function () {
	// Motyw REJESTRUJE lokalizacje, ale administrator nie przypisal do niej menu.
	return array( 'primary' => 0 );
};
add_filter( 'theme_mod_nav_menu_locations', $puste_lokalizacje, 99 );
MP_Lead_Intake_Page::refresh_menu_status();
$html_puste = oa_ostrzezenia();
remove_filter( 'theme_mod_nav_menu_locations', $puste_lokalizacje, 99 );

oa_ok(
	'' !== trim( $html_puste ),
	'lokalizacja bez przypisanego menu tez daje ostrzezenie'
);
oa_ok(
	false === stripos( $html_puste, 'nie rejestruje' ),
	'ale NIE twierdzi, ze motyw menu nie rejestruje — bo rejestruje',
	'html=' . wp_strip_all_tags( $html_puste )
);
oa_ok(
	false !== stripos( $html_puste, 'przypisan' ),
	'tylko mowi o braku przypisanego menu — czyli o tym, co admin ma zrobic',
	'html=' . wp_strip_all_tags( $html_puste )
);
oa_ok(
	wp_strip_all_tags( $html_puste ) !== wp_strip_all_tags( $html_bez_lokalizacji ),
	'dwie rozne przyczyny daja dwa rozne komunikaty'
);

/*
 * Przypadek, ktorego powyzsza symulacja NIE odtwarzala, a ktory daje KAZDY
 * prawdziwy motyw swiezo po instalacji: `register_nav_menu()` wywolane, ale
 * zaden obiekt menu nie zostal do lokalizacji przypisany. WordPress zwraca
 * wtedy z `get_nav_menu_locations()` PUSTA tablice — tak samo jak dla motywu,
 * ktory zadnych lokalizacji nie rejestruje. Wtyczka rozpoznawala to po pustce
 * i mowila administratorowi „Twoj motyw nie rejestruje standardowego menu",
 * czyli nieprawde: motyw rejestruje, brakuje tylko przypisania.
 *
 * Symulacja `array( 'primary' => 0 )` opisuje ksztalt, ktorego WordPress w tej
 * sytuacji nie produkuje — dlatego naprawa P1-G9 wygladala na kompletna, a stan
 * spotykany najczesciej dostawal zla z trzech diagnoz.
 */
oa_wyczysc();

$oa_strona_lok = wp_insert_post(
	array(
		'post_title'   => 'MP test lokalizacji',
		'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	)
);
update_option( MP_Lead_Intake_Page::OPTION, (int) $oa_strona_lok );

$oa_nic_nieprzypisane = function () {
	return array();
};

register_nav_menu( 'glowne', 'Menu glowne' );
add_filter( 'theme_mod_nav_menu_locations', $oa_nic_nieprzypisane, 99 );
MP_Lead_Intake_Page::refresh_menu_status();
$html_zarejestrowana = oa_ostrzezenia();
remove_filter( 'theme_mod_nav_menu_locations', $oa_nic_nieprzypisane, 99 );

oa_ok(
	false === stripos( $html_zarejestrowana, 'nie rejestruje' ),
	'motyw, ktory lokalizacje ZAREJESTROWAL, nie jest oskarzany o jej brak',
	'html=' . wp_strip_all_tags( $html_zarejestrowana )
);
oa_ok(
	false !== stripos( $html_zarejestrowana, 'przypisan' ),
	'komunikat kieruje tam, gdzie trzeba: menu nie jest przypisane do lokalizacji',
	'html=' . wp_strip_all_tags( $html_zarejestrowana )
);

wp_delete_post( (int) $oa_strona_lok, true );

$GLOBALS['mp_oa']['lines'][] = '';
$GLOBALS['mp_oa']['lines'][] = '=== C. KONTR-ASERCJE: przy powodzeniu cisza jest wlasciwa ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na pokazywaniu ostrzezenia zawsze.
 * Panel pelen ostrzezen o niczym uczy administratora, zeby ich nie czytal —
 * i wtedy przegapi to jedno prawdziwe.
 */
$z_menu = function () use ( $strona_id ) {
	return array();
};
add_filter( 'mp_lead_intake_add_page_to_menu', '__return_false' );
MP_Lead_Intake_Page::refresh_menu_status();
$html_sukces = oa_ostrzezenia();
remove_filter( 'mp_lead_intake_add_page_to_menu', '__return_false' );

oa_ok(
	'' === trim( $html_sukces ),
	'gdy strona jest w menu (albo dokladanie wylaczono), nie ma zadnego ostrzezenia',
	'html=' . wp_strip_all_tags( $html_sukces )
);

oa_wyczysc();
update_option( MP_Lead_Intake_Page::OPTION, (int) $strona_id );

$html_bez_flagi = oa_ostrzezenia();

oa_ok(
	'' === trim( $html_bez_flagi ),
	'swiezo zainstalowana wtyczka bez ustalonego stanu tez milczy',
	'html=' . wp_strip_all_tags( $html_bez_flagi )
);

// Sprzatanie: strona testowa i przywrocenie poprzedniej opcji.
if ( $strona_id && ! is_wp_error( $strona_id ) ) {
	wp_delete_post( (int) $strona_id, true );
}

oa_wyczysc();

if ( $stara_strona > 0 ) {
	update_option( MP_Lead_Intake_Page::OPTION, $stara_strona );
}

$GLOBALS['mp_oa']['lines'][] = '';
$GLOBALS['mp_oa']['lines'][] = '=== Z. ostrzezenie daje to, co obiecuje, i nie obiecuje tego, czego nie zrobilo ===';

/*
 * DWA KOMUNIKATY, JEDNA WADA: mowily o czyms, czego nie bylo.
 *
 * 1. Ostrzezenie o nieudanej pod-stronie konczylo sie slowami „wstawiajac na niej
 *    krotki kod:", po czym w bloku <code> stala tresc bledu WordPressa. Czlowiek
 *    dostawal obietnice kodu do przeklejenia, a w miejscu tego kodu — komunikat
 *    awarii. Jedyna rzecz, ktora mial zrobic recznie, byla jedyna, ktorej nie dostal.
 *
 * 2. Ostrzezenie MENU_NO_ASSIGNED twierdzilo, ze „wtyczka sprobowala dolozyc" link
 *    do wykrytego menu. W tej galezi zadna proba sie nie odbyla: petla po
 *    lokalizacjach robi `continue` ZANIM dojdzie do wp_update_nav_menu_item(),
 *    a ten powod powstaje wlasnie dlatego, ze nie bylo gdzie wstawiac. Zdanie
 *    zostalo przeklejone z galezi „motyw nie rejestruje menu", gdzie fallback
 *    naprawde dziala — i kazalo szukac na stronie linku, ktorego nikt nie dodawal.
 */
oa_wyczysc();

$oa_schowane = oa_schowaj_strony_z_formularzem();

update_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, 'Nie udalo sie utworzyc strony (blad testowy).' );
$oa_html_strona = oa_ostrzezenia();
oa_wyczysc();

oa_przywroc_strony( $oa_schowane );

oa_ok(
	false !== strpos( $oa_html_strona, '[' . MP_Lead_Intake_Form::SHORTCODE . ']' ),
	'Z1: ostrzezenie o nieudanej pod-stronie POKAZUJE krotki kod, ktory kaze wstawic',
	'html=' . wp_strip_all_tags( $oa_html_strona )
);
oa_ok(
	false !== strpos( $oa_html_strona, 'Nie udalo sie utworzyc strony (blad testowy).' ),
	'Z2: i nadal podaje powod zgloszony przez WordPressa — nic nie zniklo',
	'html=' . wp_strip_all_tags( $oa_html_strona )
);

oa_wyczysc();

/*
 * Ostrzezenie o menu wypisuje sie tylko wtedy, gdy strona formularza ISTNIEJE —
 * bez niej nie ma dokad linkowac. Zakladamy ja wiec na czas tego przypadku,
 * inaczej sekcja mierzylaby brak strony, a nie tresc komunikatu.
 */
$oa_strona = wp_insert_post(
	array(
		'post_title'   => 'MP test ostrzezenia',
		'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	)
);
update_option( MP_Lead_Intake_Page::OPTION, (int) $oa_strona );
update_option( MP_Lead_Intake_Page::OPTION_MENU_OK, '0' );
update_option( MP_Lead_Intake_Page::OPTION_MENU_REASON, MP_Lead_Intake_Page::MENU_NO_ASSIGNED );
$oa_html_menu = oa_ostrzezenia();
wp_delete_post( (int) $oa_strona, true );
oa_wyczysc();

oa_ok(
	false === stripos( $oa_html_menu, 'sprobowala dolozyc' )
		&& false === stripos( $oa_html_menu, 'spróbowała dołożyć' ),
	'Z3: przy braku przypisanego menu nie twierdzimy, ze cokolwiek probowalismy dolozyc',
	'html=' . wp_strip_all_tags( $oa_html_menu )
);
oa_ok(
	'' !== trim( wp_strip_all_tags( $oa_html_menu ) ),
	'Z4: KONTR-ASERCJA — ostrzezenie nadal jest wypisywane, nie zniklo razem z nieprawda',
	'html=' . wp_strip_all_tags( $oa_html_menu )
);

echo implode( "\n", $GLOBALS['mp_oa']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_oa']['pass'], $GLOBALS['mp_oa']['fail'] );
echo ( 0 === $GLOBALS['mp_oa']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
