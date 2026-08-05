<?php
/**
 * Odwiedzający dostawał w menu link do strony, której nie ma.
 *
 * Uruchamianie: wp eval-file tests/naprawy/link-do-strony-ktorej-nie-ma.php
 *
 * Flaga `OPTION_MENU_OK = 0` znaczy dwie różne rzeczy naraz: „nie udało się
 * dopisać pozycji do menu" ORAZ „strony z formularzem nie ma albo nie jest
 * opublikowana" (patrz gałęzie ustawiające ją w `refresh_menu_status()`).
 * Zapasowe wstrzykiwanie linku do `<nav>` czytało wyłącznie tę flagę, więc
 * uruchamiało się także w tym drugim przypadku.
 *
 * `url()` oddawało przy tym `get_permalink()` dla KAŻDEGO statusu wpisu — szkic
 * i kosz też mają adres. Skutek: strona zniknęła z witryny, a menu dalej
 * pokazywało do niej odnośnik, po którym gość dostawał 404. Panel mówił w tym
 * czasie co innego (gałąź „przywróć stronę do publikacji"), więc obie strony
 * widziały dwie różne wersje tego samego stanu.
 *
 * Naprawa u źródła: adres strony NIEPUBLICZNEJ to brak adresu. Oba miejsca,
 * które ten adres pokazują, już dziś radzą sobie z pustym ciągiem.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_lk'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegół przy porażce.
 * @return bool
 */
function lk_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_lk']['pass'];
		$GLOBALS['mp_lk']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_lk']['fail'];
	$GLOBALS['mp_lk']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik i przywraca stan.
 *
 * @return void
 */
function lk_koniec() {
	if ( empty( $GLOBALS['mp_lk']['lines'] ) ) {
		return;
	}

	if ( isset( $GLOBALS['mp_lk_strona'] ) ) {
		wp_delete_post( (int) $GLOBALS['mp_lk_strona'], true );
	}
	if ( array_key_exists( 'mp_lk_option', $GLOBALS ) ) {
		if ( false === $GLOBALS['mp_lk_option'] ) {
			delete_option( MP_Lead_Intake_Page::OPTION );
		} else {
			update_option( MP_Lead_Intake_Page::OPTION, $GLOBALS['mp_lk_option'] );
		}
	}

	$r    = $GLOBALS['mp_lk'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_lk']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'lk_koniec' );

$GLOBALS['mp_lk_option'] = get_option( MP_Lead_Intake_Page::OPTION, false );

$strona = wp_insert_post(
	array(
		'post_title'   => 'Zapytanie ofertowe (sonda linku)',
		'post_name'    => 'sonda-linku-' . wp_rand( 1000, 9999 ),
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
	)
);

if ( is_wp_error( $strona ) || ! $strona ) {
	lk_ok( false, 'sonda zalozyla strone testowa' );
	return;
}

$GLOBALS['mp_lk_strona'] = (int) $strona;
update_option( MP_Lead_Intake_Page::OPTION, (int) $strona );

$nav = '<nav><ul><li><a href="/o-nas">O nas</a></li></ul></nav>';

/* ==================================================================== A */

$GLOBALS['mp_lk']['lines'][] = '=== A. kontrola pozytywna: strona opublikowana ===';

lk_ok( '' !== MP_Lead_Intake_Page::url(), 'opublikowana strona ma adres' );

$html = MP_Lead_Intake_Page::inject_menu_link_html( $nav );
lk_ok(
	false !== strpos( $html, 'data-mp-lead-intake' ),
	'i link do niej trafia do menu'
);

/* ==================================================================== B */

$GLOBALS['mp_lk']['lines'][] = '';
$GLOBALS['mp_lk']['lines'][] = '=== B. strona w szkicu: adresu NIE MA ===';

wp_update_post(
	array(
		'ID'          => (int) $strona,
		'post_status' => 'draft',
	)
);
clean_post_cache( (int) $strona );

lk_ok(
	'' === MP_Lead_Intake_Page::url(),
	'strona w szkicu nie oddaje adresu',
	'url=' . MP_Lead_Intake_Page::url()
);

$html = MP_Lead_Intake_Page::inject_menu_link_html( $nav );
lk_ok(
	false === strpos( $html, 'data-mp-lead-intake' ),
	'i zaden link nie trafia do menu odwiedzajacego',
	'html=' . mb_substr( wp_strip_all_tags( $html ), 0, 90 )
);

/* ==================================================================== C */

$GLOBALS['mp_lk']['lines'][] = '';
$GLOBALS['mp_lk']['lines'][] = '=== C. strona w koszu: tak samo ===';

wp_trash_post( (int) $strona );
clean_post_cache( (int) $strona );

lk_ok( '' === MP_Lead_Intake_Page::url(), 'strona w koszu nie oddaje adresu', 'url=' . MP_Lead_Intake_Page::url() );

$html = MP_Lead_Intake_Page::inject_menu_link_html( $nav );
lk_ok(
	false === strpos( $html, 'data-mp-lead-intake' ),
	'i tu rowniez menu zostaje nietkniete'
);

/* ==================================================================== D */

$GLOBALS['mp_lk']['lines'][] = '';
$GLOBALS['mp_lk']['lines'][] = '=== D. kontr-asercje: przywrocenie strony przywraca link ===';

wp_untrash_post( (int) $strona );
wp_update_post(
	array(
		'ID'          => (int) $strona,
		'post_status' => 'publish',
	)
);
clean_post_cache( (int) $strona );

lk_ok( '' !== MP_Lead_Intake_Page::url(), 'po przywroceniu adres wraca' );

$html = MP_Lead_Intake_Page::inject_menu_link_html( $nav );
lk_ok( false !== strpos( $html, 'data-mp-lead-intake' ), 'i link wraca do menu' );

// Bufor nie może dokładać linku drugi raz do HTML-u, który już go ma.
lk_ok(
	1 === substr_count( MP_Lead_Intake_Page::inject_menu_link_html( $html ), 'data-mp-lead-intake' ),
	'link nie dubluje sie przy powtornym przebiegu bufora'
);

// Brak zapisanej strony w ogóle to też brak adresu — bez tego `url()` liczyłby
// permalink dla identyfikatora 0.
delete_option( MP_Lead_Intake_Page::OPTION );
lk_ok( '' === MP_Lead_Intake_Page::url(), 'brak zapisanej strony to brak adresu' );

/* ==================================================================== E */

/*
 * OSTRZEZENIE DLA JEDNEJ OSOBY NIE NAPRAWIA TEGO, CO WIDZA WSZYSCY POZOSTALI.
 *
 * Galaz `create()` wykrywajaca strone w stanie innym niz `publish` zapisywala
 * slad dla administratora i wychodzila, ZOSTAWIAJAC nietknieta pozycje menu
 * dolozona wczesniej przez `add_to_menus()`. Administrator widzial ostrzezenie
 * w panelu, a GOSC dalej widzial w menu motywu „Zapytanie ofertowe" i trafial
 * na 404 albo na wpis w koszu.
 */

$GLOBALS['mp_lk']['lines'][] = '';
$GLOBALS['mp_lk']['lines'][] = '=== E. pozycja menu znika razem z opublikowana strona ===';

$ln_menu_id = wp_create_nav_menu( 'mp-test-menu-' . wp_generate_password( 6, false ) );

if ( is_wp_error( $ln_menu_id ) ) {
	lk_ok( false, 'E: menu testowe zalozone', $ln_menu_id->get_error_message() );
} else {
	$ln_strona = wp_insert_post(
		array(
			'post_title'   => 'Zapytanie ofertowe (test menu)',
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
		)
	);

	update_option( MP_Lead_Intake_Page::OPTION, (int) $ln_strona );

	wp_update_nav_menu_item(
		(int) $ln_menu_id,
		0,
		array(
			'menu-item-title'     => 'Zapytanie ofertowe',
			'menu-item-object'    => 'page',
			'menu-item-object-id' => (int) $ln_strona,
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
		)
	);

	/**
	 * Ile pozycji menu wskazuje na te strone.
	 *
	 * @param int $menu_id   Menu.
	 * @param int $strona_id Strona.
	 * @return int
	 */
	function ln_pozycji( $menu_id, $strona_id ) {
		$ile = 0;
		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $poz ) {
			if ( 'page' === $poz->object && (int) $poz->object_id === (int) $strona_id ) {
				++$ile;
			}
		}
		return $ile;
	}

	lk_ok(
		ln_pozycji( (int) $ln_menu_id, (int) $ln_strona ) > 0,
		'E1: stan wyjsciowy — pozycja menu wskazuje na opublikowana strone'
	);

	// Administrator wrzuca strone do kosza.
	wp_trash_post( (int) $ln_strona );
	clean_post_cache( (int) $ln_strona );

	MP_Lead_Intake_Page::create();

	lk_ok(
		0 === ln_pozycji( (int) $ln_menu_id, (int) $ln_strona ),
		'E2: po wykryciu strony w koszu pozycja menu ZNIKA',
		'pozycji=' . ln_pozycji( (int) $ln_menu_id, (int) $ln_strona )
	);
	lk_ok(
		'' !== (string) get_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR, '' ),
		'E3: KONTR-ASERCJA — slad dla administratora nadal powstaje'
	);
	lk_ok(
		'0' === (string) get_option( MP_Lead_Intake_Page::OPTION_MENU_OK, '1' ),
		'E4: KONTR-ASERCJA — flaga menu nadal zgaszona'
	);

	wp_delete_post( (int) $ln_strona, true );
	wp_delete_nav_menu( (int) $ln_menu_id );
	delete_option( MP_Lead_Intake_Page::OPTION_PAGE_ERROR );
}
