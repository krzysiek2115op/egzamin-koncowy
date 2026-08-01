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
add_filter( 'wp_insert_post_empty_content', '__return_true' );
MP_Lead_Intake_Page::create();
remove_filter( 'wp_insert_post_empty_content', '__return_true' );

$id_po_bledzie = (int) get_option( MP_Lead_Intake_Page::OPTION );
$html_bledu    = oa_ostrzezenia();

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
add_filter( 'theme_mod_nav_menu_locations', $bez_lokalizacji, 99 );
MP_Lead_Intake_Page::refresh_menu_status();
$html_bez_lokalizacji = oa_ostrzezenia();
remove_filter( 'theme_mod_nav_menu_locations', $bez_lokalizacji, 99 );

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

echo implode( "\n", $GLOBALS['mp_oa']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_oa']['pass'], $GLOBALS['mp_oa']['fail'] );
echo ( 0 === $GLOBALS['mp_oa']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
