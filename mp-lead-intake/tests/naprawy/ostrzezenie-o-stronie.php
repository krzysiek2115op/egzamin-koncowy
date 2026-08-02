<?php
/**
 * U-13 i U-14 — ostrzezenie o stronie z formularzem klamalo w dwie strony.
 *
 * Uruchamianie: wp eval-file tests/naprawy/ostrzezenie-o-stronie.php
 *
 * Oba ustalenia pochodza z pary 1.26 audytu glebokiego („komunikaty dla czlowieka") —
 * pary, ktora na glebokosci `pelny` jest POMIJANA, wiec kazde wczesniejsze „GO"
 * powstalo bez niej.
 *
 * U-13. KOMUNIKAT NIE GASL PO NAPRAWIE, KTORA SAM ZALECAL. Ostrzezenie podaje dwie
 * drogi: „wylacz i wlacz wtyczke ponownie ALBO utworz strone recznie, wstawiajac na
 * niej krotki kod". Jedyne `delete_option( OPTION_PAGE_ERROR )` stalo w galezi
 * sukcesu `create()`, czyli gasila je WYLACZNIE pierwsza z nich. Administrator,
 * ktory wybral druga, mial dzialajacy formularz i wiszacy na kazdym ekranie panelu
 * czerwony komunikat, ze formularza nie ma — do tego `notice-error` BEZ
 * `is-dismissible`, wiec nie do zamkniecia.
 *
 * U-14. STRONA W KOSZU UCHODZILA ZA ISTNIEJACA. `if ( $existing && get_post( $existing ) )`
 * nie porownywalo `post_status`, a `get_post()` oddaje takze wpis w koszu, szkicu
 * i prywatny. Galaz konczy sie `return`, wiec OPTION_PAGE_ERROR zostawala pusta
 * i zaden komunikat sie nie pokazywal. Gorzej: `add_to_menus()` doklada pozycje menu
 * wskazujaca na wpis w koszu i ZWRACA SUKCES, wiec OPTION_MENU_OK szla na 1. Panel
 * wygladal dokladnie tak jak przy pelnym powodzeniu, a link prowadzil donikad.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['os'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $info    Kontekst przy porazce.
 * @return void
 */
function os_ok( $warunek, $opis, $info = '' ) {
	if ( $warunek ) {
		++$GLOBALS['os']['pass'];
		$GLOBALS['os']['lines'][] = '[PASS] ' . $opis;
		return;
	}

	++$GLOBALS['os']['fail'];
	$GLOBALS['os']['lines'][] = '[FAIL] ' . $opis . ( '' !== $info ? ' -- ' . $info : '' );
}

/**
 * Zwraca HTML ostrzezenia w panelu.
 *
 * @return string
 */
function os_notice() {
	ob_start();
	MP_Lead_Intake_Page::maybe_admin_notice();

	return (string) ob_get_clean();
}

/**
 * Kasuje wszystkie strony z krotkim kodem formularza i slady po nich.
 *
 * @return void
 */
function os_sprzataj() {
	$strony = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			's'           => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
		)
	);

	foreach ( (array) $strony as $sid ) {
		wp_delete_post( (int) $sid, true );
	}

	delete_option( MP_Lead_Intake_Page::OPTION );
	delete_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );
	delete_option( MP_Lead_Intake_Page::OPTION_MENU_OK );
}

$klasy = class_exists( 'MP_Lead_Intake_Page' ) && class_exists( 'MP_Lead_Intake_Form' );
os_ok( $klasy, '0: wtyczka 1 zaladowana' );

if ( ! $klasy ) {
	echo implode( "\n", $GLOBALS['os']['lines'] ) . "\n";
echo implode( "\n", $GLOBALS['os']['lines'] ) . "\n";
	echo "VERDICT_HAS_FAILURES\n";
	return;
}

// Ostrzezenie pokazuje sie tylko administratorowi.
$admini = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
wp_set_current_user( ! empty( $admini ) ? (int) $admini[0] : 1 );

os_ok( current_user_can( 'manage_options' ), '0: test dziala jako administrator' );

/* ==================================================================== A */
// U-13: ostrzezenie MUSI byc widoczne, dopoki strony naprawde nie ma.

os_sprzataj();
update_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, 'Test: wp_insert_post odmowil zapisu.' );

$html_bez_strony = os_notice();

os_ok(
	false !== strpos( $html_bez_strony, 'NIE POWSTAŁA' ),
	'A1: KONTR-ASERCJA — bez strony ostrzezenie nadal sie pokazuje',
	'HTML=' . substr( $html_bez_strony, 0, 120 )
);

os_ok(
	false !== strpos( $html_bez_strony, 'is-dismissible' ),
	'A2: U-13 — komunikat da sie zamknac (is-dismissible)',
	'brak klasy is-dismissible'
);

/* ==================================================================== B */
// U-13: po recznym zalozeniu strony ze skrotem komunikat GASNIE.

$reczna = wp_insert_post(
	array(
		'post_title'   => 'Zapytanie ofertowe (recznie)',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
	)
);

os_ok( $reczna > 0 && ! is_wp_error( $reczna ), 'B0: strona zalozona recznie', 'id=' . var_export( $reczna, true ) );

$html_po_recznej = os_notice();

os_ok(
	false === strpos( $html_po_recznej, 'NIE POWSTAŁA' ),
	'B1: U-13 — po wykonaniu DRUGIEJ z zalecanych drog komunikat znika',
	'HTML=' . substr( $html_po_recznej, 0, 160 )
);

os_ok(
	'' === (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, '' ),
	'B2: U-13 — slad po bledzie zostal skasowany, wiec nie wroci przy kolejnym wejsciu'
);

os_ok(
	(int) get_option( MP_Lead_Intake_Page::OPTION ) === (int) $reczna,
	'B3: wtyczka przyjmuje recznie zalozona strone za swoja (link w panelu prowadzi do niej)',
	'OPTION=' . get_option( MP_Lead_Intake_Page::OPTION )
);

/* ==================================================================== C */
// U-14: strona w koszu NIE jest strona istniejaca.

os_sprzataj();

$w_koszu = wp_insert_post(
	array(
		'post_title'   => 'Zapytanie ofertowe (do kosza)',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
	)
);

update_option( MP_Lead_Intake_Page::OPTION, (int) $w_koszu );
wp_trash_post( (int) $w_koszu );

MP_Lead_Intake_Page::create();

os_ok(
	'' !== (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, '' ),
	'C1: U-14 — strona w koszu zostaje ZGLOSZONA, a nie przemilczana',
	'OPTION_PAGE_ERROR pusta, czyli create() uznal wpis w koszu za dzialajaca strone'
);

$html_kosz = os_notice();

os_ok(
	false !== strpos( $html_kosz, 'MP Lead Intake' ) && '' !== trim( $html_kosz ),
	'C2: U-14 — administrator dostaje o tym komunikat',
	'panel milczy'
);

/* ==================================================================== D */
// KONTR-ASERCJA: opublikowana strona to nadal strona istniejaca i nikt nie krzyczy.

os_sprzataj();

$zwykla = wp_insert_post(
	array(
		'post_title'   => 'Zapytanie ofertowe',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
	)
);

update_option( MP_Lead_Intake_Page::OPTION, (int) $zwykla );
MP_Lead_Intake_Page::create();

os_ok(
	'' === (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, '' ),
	'D1: KONTR-ASERCJA — opublikowana strona nie wywoluje zadnego ostrzezenia'
);

os_ok(
	(int) get_option( MP_Lead_Intake_Page::OPTION ) === (int) $zwykla,
	'D2: KONTR-ASERCJA — istniejaca strona nie jest duplikowana'
);

os_ok(
	false === strpos( os_notice(), 'NIE POWSTAŁA' ),
	'D3: KONTR-ASERCJA — panel milczy, gdy wszystko jest w porzadku'
);

os_sprzataj();

/* ==================================================================== E */
// adopt_existing_page() dopasowywala po TEKSCIE, a nie po obecnosci skrotu.

os_sprzataj();

/*
 * `get_posts( 's' => '[mp_lead_intake_form]' )` to wyszukiwarka WordPressa:
 * przeszukuje tytul, zajawke i tresc, dzieli fraze na slowa i dopasowuje
 * czesciowo. Strona z instrukcja („wstaw krotki kod [mp_lead_intake_form] tam,
 * gdzie ma stanac formularz") pasowala tak samo dobrze jak strona z formularzem.
 * Po takim trafieniu wtyczka kasowala slad po bledzie i doklada do menu pozycje
 * „Zapytanie ofertowe" prowadzaca na strone BEZ formularza — czyli gasila
 * ostrzezenie, ktore mowilo prawde.
 */
$os_opis = wp_insert_post(
	array(
		'post_title'   => 'Jak osadzic skrot [' . MP_Lead_Intake_Form::SHORTCODE . ']',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_excerpt' => 'Instrukcja osadzania skrotu ['
			. MP_Lead_Intake_Form::SHORTCODE . '] na dowolnej stronie.',
		'post_content' => 'Skrot wstawia sie w edytorze bloków, w miejscu, w którym '
			. 'ma stanac formularz. Ta strona formularza NIE zawiera.',
	)
);

update_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, 'Nie udalo sie utworzyc strony (blad testowy).' );
$os_przyjeta = MP_Lead_Intake_Page::adopt_existing_page();

os_ok(
	false === $os_przyjeta,
	'E1: strona ze skrotem w TYTULE i ZAJAWCE nie jest brana za strone z formularzem',
	'przyjeta=' . var_export( $os_przyjeta, true )
);
os_ok(
	'' !== (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, '' ),
	'E2: ostrzezenie zostaje, bo formularza nadal nigdzie nie ma'
);

// KONTR-ASERCJA: strona z PRAWDZIWYM skrotem ma byc przyjeta jak dotad.
$os_prawdziwa = wp_insert_post(
	array(
		'post_title'   => 'Zapytanie ofertowe (recznie)',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
	)
);

os_ok(
	true === MP_Lead_Intake_Page::adopt_existing_page(),
	'E3: KONTR-ASERCJA — strona z wykonywanym skrotem nadal zostaje przyjeta'
);
os_ok(
	(int) get_option( MP_Lead_Intake_Page::OPTION ) === (int) $os_prawdziwa,
	'E4: i to wlasnie ona, a nie strona z instrukcja',
	'wybrano=' . (int) get_option( MP_Lead_Intake_Page::OPTION )
);

wp_delete_post( (int) $os_opis, true );
wp_delete_post( (int) $os_prawdziwa, true );

/* ==================================================================== F */
// refresh_menu_status() widzial strone w koszu i wychodzil po cichu.

os_sprzataj();

$os_kosz = wp_insert_post(
	array(
		'post_title'   => 'Zapytanie ofertowe',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
	)
);
update_option( MP_Lead_Intake_Page::OPTION, (int) $os_kosz );
update_option( MP_Lead_Intake_Page::OPTION_MENU_OK, 1 );
wp_update_post(
	array(
		'ID'          => (int) $os_kosz,
		'post_status' => 'draft',
	)
);

MP_Lead_Intake_Page::refresh_menu_status();

os_ok(
	'1' !== (string) get_option( MP_Lead_Intake_Page::OPTION_MENU_OK ),
	'F1: panel przestaje meldowac „wszystko dziala", gdy strona nie jest opublikowana',
	'MENU_OK=' . var_export( get_option( MP_Lead_Intake_Page::OPTION_MENU_OK ), true )
);
os_ok(
	'' !== (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, '' ),
	'F2: i zapisuje slad, ktory zobaczy administrator'
);

/*
 * KONTR-ASERCJA: opublikowana strona nie ma prawa niczego zapalac.
 *
 * Bez `os_sprzataj()` — ta funkcja kasuje WSZYSTKIE strony ze skrotem, wiec
 * zabralaby takze $os_kosz i przypadek mierzylby „strona usunieta" zamiast
 * „strona opublikowana". Czyscimy same opcje.
 */
delete_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );
delete_option( MP_Lead_Intake_Page::OPTION_MENU_OK );
update_option( MP_Lead_Intake_Page::OPTION, (int) $os_kosz );
wp_update_post(
	array(
		'ID'          => (int) $os_kosz,
		'post_status' => 'publish',
	)
);
MP_Lead_Intake_Page::refresh_menu_status();

os_ok(
	'' === (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, '' ),
	'F3: KONTR-ASERCJA — opublikowana strona nie zostawia zadnego sladu bledu'
);

wp_delete_post( (int) $os_kosz, true );

/* ==================================================================== G */
// Komunikat radzil droge naprawy, ktora z definicji nie moze pomoc.

os_sprzataj();

$os_szkic = wp_insert_post(
	array(
		'post_title'   => 'Zapytanie ofertowe',
		'post_status'  => 'draft',
		'post_type'    => 'page',
		'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
	)
);
update_option( MP_Lead_Intake_Page::OPTION, (int) $os_szkic );
MP_Lead_Intake_Page::create();

$os_komunikat = (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, '' );

os_ok(
	'' !== $os_komunikat,
	'G1: warunek scenariusza — komunikat o zlym statusie powstal'
);
/*
 * „Aktywuj wtyczke ponownie" nie moze zadzialac: ponowna aktywacja wchodzi
 * w DOKLADNIE te sama galaz (wpis istnieje, status nadal inny niz publish),
 * nadpisuje ten sam slad i konczy sie `return`. Rada, ktora odsyla czlowieka
 * do czynnosci bez skutku, jest gorsza niz jej brak — kaze mu uwierzyc, ze
 * zrobil, co trzeba.
 */
os_ok(
	false === mb_stripos( $os_komunikat, 'aktywuj wtyczkę ponownie' ),
	'G2: komunikat NIE odsyla do ponownej aktywacji, ktora trafi w te sama galaz',
	'komunikat=' . $os_komunikat
);
os_ok(
	false !== mb_stripos( $os_komunikat, 'przywróć' ) || false !== mb_stripos( $os_komunikat, 'opublikuj' ),
	'G3: i mowi, co NAPRAWDE pomaga — przywrocenie albo opublikowanie strony',
	'komunikat=' . $os_komunikat
);

wp_delete_post( (int) $os_szkic, true );
os_sprzataj();

echo implode( "\n", $GLOBALS['os']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['os']['pass'], $GLOBALS['os']['fail'] );
echo ( 0 === $GLOBALS['os']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
