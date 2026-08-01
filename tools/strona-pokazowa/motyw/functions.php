<?php
/**
 * Kredyt Kompas — funkcje motywu.
 * Konwersja statycznego HTML → WordPress. Treść stron trzymana jest w
 * post_content (edytowalna w panelu), a header/stopka renderuje motyw.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KK_VERSION', '1.0.1' );

/**
 * Nawigacja główna: slug => etykieta. Slug '' = strona główna.
 *
 * Motyw ma własne pozycje „na sztywno" i DODATKOWO dokłada te z menu WordPressa
 * przypisanego do lokalizacji `glowne` (patrz register_nav_menu niżej). Dzięki
 * temu wtyczki, które zakładają swoją podstronę i dopisują ją do menu witryny
 * standardowym `wp_update_nav_menu_item()`, pojawiają się w nawigacji same.
 *
 * Filtr 'kk_menu_items' zostaje jako punkt rozszerzenia dla przypadków spoza
 * menu WordPressa.
 *
 * PODSTRONY /panel/ TU NIE MA — i to jest celowe. Prowadzi ona wprost do
 * logowania WordPressa, a wejście do CMS-a nie jest pozycją oferty dla klienta.
 * Do 1.3.6 stała w tej tablicy, mimo że komentarz przy samej podstronie (niżej
 * w tym pliku) opisywał ją jako „dyskretne wejście, którego nie ma w nawigacji".
 * Kod i jego opis mówiły dwie różne rzeczy; prawdziwy był opis.
 */
function kk_menu_items() {
	$wlasne = array(
		''                   => 'Start',
		'kredyty-hipoteczne' => 'Kredyty hipoteczne',
		'kalkulator'         => 'Kalkulator zdolności',
		'o-nas'              => 'O nas',
		'faq'                => 'FAQ',
		'kontakt'            => 'Kontakt',
	);

	return apply_filters( 'kk_menu_items', array_merge( $wlasne, kk_menu_items_z_wordpressa() ) );
}

/**
 * Pozycje z menu WordPressa przypisanego do lokalizacji `glowne`.
 *
 * Bez zarejestrowanej lokalizacji wtyczka MP Lead Intake nie miała gdzie dopisać
 * swojej podstrony i sięgała po ścieżkę awaryjną: bufor HTML na KAŻDEJ stronie
 * frontendu i wstrzyknięcie odnośnika w `<nav>` wyrażeniem regularnym. Działało,
 * ale kosztem przepuszczania całego kodu strony przez `preg_replace` — i było
 * obejściem braku, a nie rozwiązaniem.
 *
 * @return array<string,string> slug => etykieta.
 */
function kk_menu_items_z_wordpressa() {
	$lokalizacje = get_nav_menu_locations();

	if ( empty( $lokalizacje['glowne'] ) ) {
		return array();
	}

	$pozycje = wp_get_nav_menu_items( (int) $lokalizacje['glowne'] );

	if ( ! is_array( $pozycje ) ) {
		return array();
	}

	$wynik = array();

	foreach ( $pozycje as $pozycja ) {
		$slug = trim( (string) wp_parse_url( $pozycja->url, PHP_URL_PATH ), '/' );

		if ( '' === $slug ) {
			continue;
		}

		$wynik[ $slug ] = $pozycja->title;
	}

	return $wynik;
}

/** Slug aktualnie wyświetlanej strony (pusty dla strony głównej). */
function kk_current_slug() {
	if ( is_front_page() ) {
		return '';
	}
	$obj = get_queried_object();
	return ( $obj instanceof WP_Post ) ? $obj->post_name : '';
}

/** URL strony po slugu (pusty slug => home). */
function kk_url( $slug ) {
	return $slug === '' ? home_url( '/' ) : home_url( '/' . $slug . '/' );
}

/* --- Wsparcie motywu + zasoby ------------------------------------------ */

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	// Lokalizacja menu — patrz kk_menu_items_z_wordpressa(). Motyw nadal rysuje
	// menu sam, ale wtyczki mają gdzie dopisać swoją podstronę standardową drogą.
	register_nav_menu( 'glowne', __( 'Menu główne', 'kredyt-kompas' ) );
} );

add_action( 'wp_enqueue_scripts', function () {
	$dir = get_stylesheet_directory_uri();
	// Google Fonts (jak w oryginale).
	wp_enqueue_style( 'kk-fonts', 'https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap', array(), null );
	// Właściwe style strony.
	wp_enqueue_style( 'kk-main', $dir . '/assets/styles.css', array(), KK_VERSION );
	// Skrypt strony (na końcu body).
	wp_enqueue_script( 'kk-main', $dir . '/assets/script.js', array(), KK_VERSION, true );
} );

/* --- Treść bespoke: nie autoformatuj HTML z oryginału ------------------- */
add_action( 'wp', function () {
	if ( is_page() || is_front_page() ) {
		remove_filter( 'the_content', 'wpautop' );
		remove_filter( 'the_content', 'wptexturize' );
	}
} );

/* --- Zasiew stron przy aktywacji motywu -------------------------------- */
/**
 * Tworzy strony z fragmentów HTML (parts/*.html), ustawia stronę główną,
 * skraca permalinki do /%postname%/ i buduje menu. Idempotentne.
 */
function kk_seed_content() {
	$parts_dir = get_stylesheet_directory() . '/parts/';

	// slug => tytuł
	$pages = array(
		'index'                 => 'Start',
		'kredyty-hipoteczne'    => 'Kredyty hipoteczne',
		'kalkulator'            => 'Kalkulator zdolności',
		'o-nas'                 => 'O nas',
		'faq'                   => 'FAQ',
		'kontakt'               => 'Kontakt',
		'polityka-prywatnosci'  => 'Polityka prywatności',
	);

	$ids = array();
	foreach ( $pages as $file => $title ) {
		$slug    = ( $file === 'index' ) ? 'start' : $file;
		$content = '';
		if ( file_exists( $parts_dir . $file . '.html' ) ) {
			$content = file_get_contents( $parts_dir . $file . '.html' );
		}

		$existing = get_page_by_path( $slug );
		if ( $existing ) {
			// Strona już istnieje — nie nadpisujemy (zachowujemy edycje z CMS).
			$ids[ $file ] = $existing->ID;
		} else {
			$ids[ $file ] = wp_insert_post( array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			) );
		}
	}

	// Strona główna = "start".
	if ( ! empty( $ids['index'] ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['index'] );
	}

	// Ładne permalinki.
	update_option( 'permalink_structure', '/%postname%/' );
	if ( function_exists( 'flush_rewrite_rules' ) ) {
		flush_rewrite_rules();
	}
}
add_action( 'after_switch_theme', 'kk_seed_content' );

/**
 * Playground aktywuje motyw ustawiając opcję bezpośrednio (bez switch_theme),
 * więc after_switch_theme może się nie odpalić. Zasiewamy jednorazowo na init.
 */
add_action( 'init', function () {
	if ( get_option( 'kk_seeded' ) !== '1' ) {
		kk_seed_content();
		update_option( 'kk_seeded', '1' );
	}
}, 20 );

/* --- Ukryta podstrona /panel/ → logowanie do WordPressa ---------------- */
/**
 * Dyskretne wejście do CMS. Nie ma jej w nawigacji. Wchodząc na /panel/:
 *  - niezalogowany → ekran logowania WP (po zalogowaniu ląduje w kokpicie),
 *  - zalogowany    → od razu kokpit (/wp-admin/).
 */
add_action( 'init', function () {
	if ( get_option( 'kk_panel_seeded' ) !== '1' ) {
		if ( ! get_page_by_path( 'panel' ) ) {
			wp_insert_post( array(
				'post_title'   => 'Panel',
				'post_name'    => 'panel',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			) );
		}
		update_option( 'kk_panel_seeded', '1' );
	}
}, 21 );

add_action( 'template_redirect', function () {
	if ( ! is_page( 'panel' ) ) {
		return;
	}
	$target = is_user_logged_in() ? admin_url() : wp_login_url( admin_url() );
	wp_safe_redirect( $target );
	exit;
} );
