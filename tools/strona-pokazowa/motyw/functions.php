<?php
/**
 * Kredyt Kompas — funkcje motywu.
 * Konwersja statycznego HTML → WordPress. Treść stron trzymana jest w
 * post_content (edytowalna w panelu), a header/stopka renderuje motyw.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KK_VERSION', '1.0.2' );

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
		$slug = kk_slug_z_adresu( $pozycja->url );

		if ( '' === $slug ) {
			continue;
		}

		$wynik[ $slug ] = $pozycja->title;
	}

	return $wynik;
}

/**
 * Slug pozycji menu, czyli ścieżka liczona OD KORZENIA WITRYNY.
 *
 * Pozycja menu zna pełny adres, a motyw potrzebuje samego sluga — bo z niego
 * `kk_url()` buduje adres z powrotem, a `kk_current_slug()` porównuje go
 * z `post_name` bieżącej strony. Wcześniej za sluga robiła CAŁA ścieżka URL.
 *
 * W korzeniu domeny obie operacje się znoszą i wynik jest poprawny. Gdy jednak
 * WordPress stoi w PODKATALOGU — a tak właśnie serwuje go WP Playground, pod
 * prefiksem `/scope:<id>/` — prefiks instalacji zostaje policzony dwa razy:
 * `/scope:0.77/scope:0.77/zapytanie-ofertowe/`. Pozostałe pozycje nawigacji są
 * w motywie „na sztywno" i budują się z samego sluga, więc działały; nie działała
 * dokładnie ta jedna, która przychodzi z menu WordPressa — podstrona formularza
 * wtyczki 1. Awaria wyglądała więc na usterkę tej podstrony, a nie nawigacji.
 *
 * @param string $url Pełny adres pozycji menu.
 * @return string Ścieżka względem witryny, bez ukośników brzegowych.
 */
function kk_slug_z_adresu( $url ) {
	$sciezka = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
	$baza    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

	if ( '' !== $baza && '/' !== $baza && 0 === strpos( $sciezka, $baza ) ) {
		$sciezka = substr( $sciezka, strlen( $baza ) );
	}

	return trim( $sciezka, '/' );
}

/**
 * Przelicza odnośniki treści liczone od korzenia DOMENY na adresy witryny.
 *
 * Fragmenty w `parts/*.html` pochodzą ze statycznego oryginału stojącego
 * w korzeniu domeny i mają 19 odnośników postaci `href="/kontakt/"`. Wiodący
 * ukośnik znaczy „korzeń domeny", a nie „korzeń witryny" — w instalacji
 * podkatalogowej (Playground: `/scope:<id>/`) każdy z nich wyprowadza POZA
 * witrynę, na adres, pod którym nie ma WordPressa.
 *
 * Przeliczamy przy renderowaniu, a nie przy zasiewie, świadomie: adres witryny
 * w Playground zmienia się między sesjami, więc adres wpisany do bazy przy
 * zakładaniu stron byłby prawdziwy dokładnie raz. Wzorzec jest wąski (jeden
 * atrybut, wiodący pojedynczy ukośnik), a przeliczenie idempotentne — wynik
 * zaczyna się od schematu, więc drugi przebieg go już nie dotyka.
 *
 * @param string $html Treść strony.
 * @return string Treść z adresami wskazującymi w obrębie witryny.
 */
function kk_tresc_z_adresami( $html ) {
	if ( ! is_string( $html ) || '' === $html ) {
		return $html;
	}

	$baza = home_url( '/' );

	$wynik = preg_replace_callback(
		'~(?<![\w-])(href|src|action)="/(?!/)([^"]*)"~i',
		static function ( $m ) use ( $baza ) {
			return $m[1] . '="' . esc_url( $baza . $m[2] ) . '"';
		},
		$html
	);

	// preg_replace_callback() zwraca null przy błędzie — wtedy lepsza treść
	// z niedziałającymi odnośnikami niż pusta strona.
	return null === $wynik ? $html : $wynik;
}
add_filter( 'the_content', 'kk_tresc_z_adresami' );

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

	/*
	 * Menu WordPressa przypisane do lokalizacji `glowne`.
	 *
	 * Sama `register_nav_menu()` NIE WYSTARCZY: dopóki do lokalizacji nie jest
	 * przypisany obiekt menu, `get_nav_menu_locations()` zwraca pustkę i wtyczka
	 * MP Lead Intake nie ma gdzie dopisać swojej podstrony — sięga wtedy po ścieżkę
	 * awaryjną, czyli przepuszczenie całego kodu strony przez `preg_replace`.
	 * Rejestracja bez przypisania zostawiała więc dokładnie ten stan, który miała
	 * usunąć, a administrator dostawał w panelu ostrzeżenie o menu.
	 *
	 * Menu zostaje PUSTE. Motyw rysuje własne pozycje z `kk_menu_items()`; to menu
	 * istnieje po to, żeby wtyczki miały gdzie dopisać swoje strony standardową
	 * drogą — i tylko takie pozycje w nim będą.
	 */
	$menu_glowne = wp_get_nav_menu_object( 'Menu główne' );

	if ( ! $menu_glowne ) {
		$menu_id = wp_create_nav_menu( 'Menu główne' );
	} else {
		$menu_id = (int) $menu_glowne->term_id;
	}

	if ( ! is_wp_error( $menu_id ) && (int) $menu_id > 0 ) {
		$lokalizacje            = (array) get_theme_mod( 'nav_menu_locations', array() );
		$lokalizacje['glowne']  = (int) $menu_id;
		set_theme_mod( 'nav_menu_locations', $lokalizacje );
	}

	/*
	 * Ładne permalinki. `set_permalink_structure()`, a nie samo `update_option()`:
	 * ta druga zapisuje opcję, ale zostawia obiekt przepisywania w stanie sprzed
	 * zmiany, więc do końca TEGO żądania WordPress dalej buduje adresy `?page_id=`.
	 * Bez znaczenia, dopóki zasiew i pierwsze wejście na stronę to dwa różne
	 * żądania — ale wtedy kod działa przez okoliczność, a nie przez to, co robi.
	 */
	global $wp_rewrite;

	if ( $wp_rewrite instanceof WP_Rewrite ) {
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	} else {
		update_option( 'permalink_structure', '/%postname%/' );
	}

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
