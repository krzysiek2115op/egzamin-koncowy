<?php
/**
 * Audyt koncowy 1.3.8 — awaria strony formularza opisana, ale wyciszona.
 *
 * Uruchamianie: wp eval-file tests/naprawy/strona-awaria-nie-milczy.php
 *
 * A. SLAD BEZ ZGASZONEJ FLAGI TO SLAD, KTOREGO NIKT NIE ZOBACZY. Galezie
 *    awaryjne `create()` zapisuja OPTION_PAGE_ERROR, ale nie zeruja
 *    OPTION_MENU_OK — a `maybe_admin_notice()` wychodzi, gdy ta flaga ma
 *    wartosc inna niz '0'. Blizniacza `refresh_menu_status()` w dokladnie tym
 *    samym stanie flage zeruje. Komentarz w tym samym pliku opisuje ten
 *    mechanizm wyciszenia jako powod poprzedniej naprawy — i zostawia go
 *    wlaczonym o dwie galezie dalej.
 *
 * B. KOMUNIKAT ODSYLAL TAM, GDZIE TEGO WPISU NIE WIDAC. „Przywroc ja do
 *    publikacji w Strony -> Wszystkie strony" przy statusie `trash` jest rada
 *    nie do wykonania: kosz ma osobny widok. To ta sama klasa bledu, co rada
 *    „aktywuj wtyczke ponownie" usunieta w 1.3.8.
 *
 * C. `wp_insert_post()` BEZ DRUGIEGO ARGUMENTU zwraca przy porazce 0, nie
 *    WP_Error — wiec galaz `is_wp_error()` byla martwa, a administrator
 *    dostawal „WordPress odrzucil zapis bez podania przyczyny" TAKZE wtedy, gdy
 *    przyczyna byla znana. Komentarz obok obiecywal wprost: „przy WP_Error jest
 *    to komunikat tego, kto zapis zablokowal, i to jedyna wskazowka".
 *
 * D. KOMUNIKAT O USUNIETEJ STRONIE kazal „opublikowac strone z krotkim kodem
 *    formularza", nie podajac, jaki to kod. Administrator musial go zgadnac.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_sa'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

$GLOBALS['mp_sa_strony'] = array();

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function sa_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_sa']['pass'];
		$GLOBALS['mp_sa']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_sa']['fail'];
	$GLOBALS['mp_sa']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik i sprzata WYLACZNIE po sobie.
 *
 * Swiadomie nie kasujemy „wszystkich stron ze skrotem" — taka sonda mierzylaby
 * pozniej „strona usunieta" zamiast „strona opublikowana" (pulapka z 1.3.8).
 *
 * @return void
 */
function sa_koniec() {
	if ( empty( $GLOBALS['mp_sa']['lines'] ) ) {
		return;
	}

	foreach ( $GLOBALS['mp_sa_strony'] as $id ) {
		wp_delete_post( (int) $id, true );
	}

	if ( isset( $GLOBALS['mp_sa_opcje'] ) ) {
		foreach ( $GLOBALS['mp_sa_opcje'] as $klucz => $wartosc ) {
			if ( false === $wartosc ) {
				delete_option( $klucz );
			} else {
				update_option( $klucz, $wartosc );
			}
		}
	}

	$r    = $GLOBALS['mp_sa'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_sa']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'sa_koniec' );

/**
 * Zaklada strone z formularzem w zadanym statusie i wskazuje ja wtyczce.
 *
 * @param string $status Status wpisu.
 * @return int
 */
function sa_strona( $status ) {
	$id = wp_insert_post(
		array(
			'post_title'   => 'SA test formularz',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '[mp_lead_intake_form]',
		)
	);

	$id = (int) $id;
	$GLOBALS['mp_sa_strony'][] = $id;

	if ( 'publish' !== $status ) {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $status,
			)
		);
	}

	update_option( MP_Lead_Intake_Page::OPTION, $id );

	return $id;
}

$GLOBALS['mp_sa_opcje'] = array(
	MP_Lead_Intake_Page::OPTION            => get_option( MP_Lead_Intake_Page::OPTION, false ),
	MP_Lead_Intake_Page::OPTION_MENU_OK    => get_option( MP_Lead_Intake_Page::OPTION_MENU_OK, false ),
	MP_Lead_Intake_Page::OPTION_PAGE_ERROR => get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, false ),
);

/* ==================================================================== A */

$GLOBALS['mp_sa']['lines'][] = '=== A. create() przy stronie w koszu gasi flage menu ===';

sa_strona( 'trash' );
update_option( MP_Lead_Intake_Page::OPTION_MENU_OK, 1 );
delete_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );

MP_Lead_Intake_Page::create();

sa_ok(
	'0' === (string) get_option( MP_Lead_Intake_Page::OPTION_MENU_OK ),
	'OPTION_MENU_OK zgaszone — inaczej ostrzezenie w panelu milczy',
	'menu_ok=' . var_export( get_option( MP_Lead_Intake_Page::OPTION_MENU_OK ), true )
);

$slad_kosz = (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );

sa_ok( '' !== $slad_kosz, 'i slad o awarii zostal zapisany' );

/* ==================================================================== B */

$GLOBALS['mp_sa']['lines'][] = '';
$GLOBALS['mp_sa']['lines'][] = '=== B. komunikat odsyla tam, gdzie ten wpis widac ===';

sa_ok(
	false !== mb_stripos( $slad_kosz, 'Kosz' ),
	'przy statusie trash komunikat mowi o Koszu',
	'slad=' . $slad_kosz
);

sa_ok(
	false === mb_stripos( $slad_kosz, 'Wszystkie strony' ),
	'i NIE odsyla do „Wszystkie strony", gdzie kosza nie widac'
);

delete_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );
sa_strona( 'draft' );
MP_Lead_Intake_Page::create();

$slad_szkic = (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );

sa_ok(
	false !== mb_stripos( $slad_szkic, 'Wszystkie strony' ),
	'szkic dalej odsyla do „Wszystkie strony" — tam go widac',
	'slad=' . $slad_szkic
);

/* ==================================================================== C */

$GLOBALS['mp_sa']['lines'][] = '';
$GLOBALS['mp_sa']['lines'][] = '=== C. porazka zapisu podaje POWOD, gdy WordPress go zna ===';

delete_option( MP_Lead_Intake_Page::OPTION );
delete_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );

/*
 * Wymuszamy porazke po stronie WordPressa. Z drugim argumentem `$wp_error`
 * ustawionym na true `wp_insert_post()` oddaje w tym stanie WP_Error z wlasnym
 * komunikatem; bez niego — zwykle 0, czyli „bez podania przyczyny".
 */
add_filter( 'wp_insert_post_empty_content', '__return_true' );

MP_Lead_Intake_Page::create();

remove_filter( 'wp_insert_post_empty_content', '__return_true' );

$slad_porazka = (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );

sa_ok( '' !== $slad_porazka, 'nieudane zalozenie strony zostawia slad' );

sa_ok(
	false === mb_stripos( $slad_porazka, 'bez podania przyczyny' ),
	'a slad nie mowi „bez podania przyczyny", skoro przyczyna jest znana',
	'slad=' . $slad_porazka
);

sa_ok(
	'0' === (string) get_option( MP_Lead_Intake_Page::OPTION_MENU_OK ),
	'i tu rowniez flaga menu jest zgaszona',
	'menu_ok=' . var_export( get_option( MP_Lead_Intake_Page::OPTION_MENU_OK ), true )
);

/* ==================================================================== D */

$GLOBALS['mp_sa']['lines'][] = '';
$GLOBALS['mp_sa']['lines'][] = '=== D. komunikat o usunietej stronie podaje krotki kod ===';

$do_skasowania = sa_strona( 'publish' );
wp_delete_post( $do_skasowania, true );
delete_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );

MP_Lead_Intake_Page::refresh_menu_status();

$slad_usunieta = (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );

sa_ok(
	false !== strpos( $slad_usunieta, '[mp_lead_intake_form]' ),
	'komunikat podaje krotki kod wprost, zamiast kazac go zgadywac',
	'slad=' . $slad_usunieta
);

/* ==================================================================== E */

$GLOBALS['mp_sa']['lines'][] = '';
$GLOBALS['mp_sa']['lines'][] = '=== E. KONTR-ASERCJE: stan zdrowy dalej jest zdrowy ===';

$zdrowa = sa_strona( 'publish' );
update_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, 'stary slad do skasowania' );

/*
 * Dokladanie do menu WYLACZONE na czas tej asercji — udokumentowanym filtrem,
 * ktory `add_to_menus()` traktuje jako swiadoma decyzje, a nie porazke.
 *
 * Bez tego sonda mierzylaby KONFIGURACJE WITRYNY, a nie logike flagi:
 * `add_to_menus()` oddaje falsz, gdy zaden motyw nie ma PRZYPISANEGO menu — czyli
 * w kazdej swiezej instalacji. Na maszynie deweloperskiej (motyw demo z menu)
 * asercja przechodzila, a w CI padala. Sprawdzamy tu jedno: czy stan zdrowy
 * zapisuje WYNIK dokladania do menu, zamiast zostawiac flage z poprzedniego stanu.
 */
add_filter( 'mp_lead_intake_add_page_to_menu', '__return_false' );

MP_Lead_Intake_Page::refresh_menu_status();

remove_filter( 'mp_lead_intake_add_page_to_menu', '__return_false' );

sa_ok(
	false === get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, false ),
	'opublikowana strona kasuje slad po awarii'
);

sa_ok(
	'1' === (string) get_option( MP_Lead_Intake_Page::OPTION_MENU_OK ),
	'i zapala flage menu',
	'menu_ok=' . var_export( get_option( MP_Lead_Intake_Page::OPTION_MENU_OK ), true )
);

sa_ok(
	(int) get_option( MP_Lead_Intake_Page::OPTION ) === $zdrowa,
	'wskazanie strony pozostaje nietkniete'
);
