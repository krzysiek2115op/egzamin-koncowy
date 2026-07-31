<?php
/**
 * Pod-strona WordPress wtyczki MP Lead Intake.
 *
 * Wymóg unikalny pluginu 1: po aktywacji tworzy pod-stronę z formularzem
 * (shortcode [mp_lead_intake_form]). Strona to zwykła strona WordPress, więc
 * renderuje się w szablonie aktywnego motywu i DZIEDZICZY jego wygląd
 * (nagłówek, stopka, style) — stąd "dopasowanie do motywu". Inne wtyczki
 * (plugin 2/3) mogą dokładać treść przez hook 'mp_lead_intake_after_form'.
 *
 * Oficjalne API: wp_insert_post() https://developer.wordpress.org/reference/functions/wp_insert_post/
 * Dodanie do menu: get_nav_menu_locations() i wp_update_nav_menu_item() —
 * https://developer.wordpress.org/reference/functions/get_nav_menu_locations/
 * https://developer.wordpress.org/reference/functions/wp_update_nav_menu_item/
 * (WordPress NIE dokłada nowych stron do istniejącego, ręcznie zbudowanego
 * menu motywu automatycznie — trzeba to zrobić explicite tym API.)
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tworzenie i usuwanie pod-strony.
 */
class MP_Lead_Intake_Page {

	/** Opcja przechowująca ID utworzonej strony. */
	const OPTION = 'mp_lead_intake_page_id';

	/** Opcja: czy stronę udało się umieścić w co najmniej jednym menu motywu. */
	const OPTION_MENU_OK = 'mp_lead_intake_menu_ok';

	/** Tytuł pod-strony — jedno źródło prawdy (post_title, wpis menu, H1, fallback linku). */
	const TITLE = 'Zapytanie ofertowe';

	/** Krótki opis pod-strony — jedno źródło prawdy (meta description, lead pod H1). */
	const DESCRIPTION = 'Wypełnij formularz zapytania ofertowego, a nasz zespół skontaktuje się z Tobą z indywidualną ofertą.';

	/**
	 * Tworzy pod-stronę, jeśli jeszcze nie istnieje (idempotentnie).
	 *
	 * @return void
	 */
	public static function create() {
		$existing = (int) get_option( self::OPTION );
		if ( $existing && get_post( $existing ) ) {
			// Strona już istnieje — nie duplikujemy, ale ewentualnie dołóż do
			// menu (np. motyw dostał przypisane menu PO utworzeniu strony).
			update_option( self::OPTION_MENU_OK, self::add_to_menus( $existing ) ? 1 : 0 );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => self::TITLE,
				'post_name'    => 'zapytanie-ofertowe',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[mp_lead_intake_form]',
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::OPTION, (int) $page_id );
			update_option( self::OPTION_MENU_OK, self::add_to_menus( (int) $page_id ) ? 1 : 0 );
		}
	}

	/**
	 * Ponownie sprawdza i odświeża OPTION_MENU_OK. Flaga ustawiana w create()
	 * jest z natury "lepka": jeśli admin PO fakcie przypisze menu do lokalizacji
	 * motywu (albo przełączy się na motyw, który rejestruje menu), bez tego
	 * odświeżenia flaga zostałaby nieaktualna — fallback (bufor HTML na KAŻDEJ
	 * stronie frontendu + ostrzeżenie w adminie) działałby dłużej niż potrzeba.
	 * Podpięte pod 'switch_theme' i 'wp_update_nav_menu'.
	 *
	 * @return void
	 */
	public static function refresh_menu_status() {
		$page_id = (int) get_option( self::OPTION );
		if ( ! $page_id || ! get_post( $page_id ) ) {
			return;
		}
		update_option( self::OPTION_MENU_OK, self::add_to_menus( $page_id ) ? 1 : 0 );
	}

	/**
	 * Dokłada stronę do KAŻDEJ lokalizacji menu motywu, która ma przypisane
	 * menu (get_nav_menu_locations()) — inaczej podstrona byłaby "niewidoczna"
	 * dla klienta mimo poprawnego utworzenia. Idempotentne: pomija lokalizację,
	 * jeśli strona już jest w danym menu (sprawdzenie po object_id).
	 * Wyłączalne filtrem 'mp_lead_intake_add_page_to_menu' (domyślnie true).
	 *
	 * UWAGA: część motywów (zwłaszcza własnoręcznie pisanych, jak niestandardowe
	 * szablony klienta) w ogóle NIE rejestruje menu przez register_nav_menu() —
	 * renderują nawigację na sztywno w PHP. Dla takich motywów get_nav_menu_locations()
	 * zwraca pustą tablicę i NIE ISTNIEJE żaden bezpieczny, generyczny sposób
	 * doklejenia linku (modyfikowanie plików motywu przez plugin byłoby kruche —
	 * zniknęłoby przy każdej aktualizacji motywu). W takim wypadku zwracamy false,
	 * a create() zapisuje to w OPTION_MENU_OK — maybe_admin_notice() poinformuje
	 * administratora wprost, zamiast pozostawić go w niewiedzy (Golden Rule: bez
	 * teatru bezpieczeństwa/automatyzacji — jawna informacja zamiast cichej porażki).
	 *
	 * @param int $page_id ID strony.
	 * @return bool True, jeśli strona jest (lub została dodana) w co najmniej
	 *              jednym menu motywu; false, gdy motyw nie ma żadnej lokalizacji
	 *              menu do wykorzystania.
	 */
	private static function add_to_menus( $page_id ) {
		if ( ! apply_filters( 'mp_lead_intake_add_page_to_menu', true ) ) {
			return true; // Świadomie wyłączone filtrem — nie traktujemy jako porażki.
		}

		$locations = get_nav_menu_locations();
		if ( empty( $locations ) ) {
			return false;
		}

		$added_anywhere = false;
		foreach ( array_unique( $locations ) as $menu_id ) {
			$menu_id = (int) $menu_id;
			if ( $menu_id <= 0 ) {
				continue;
			}

			$items   = wp_get_nav_menu_items( $menu_id );
			$in_menu = false;
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( 'page' === $item->object && (int) $item->object_id === $page_id ) {
						$in_menu = true;
						break;
					}
				}
			}
			if ( $in_menu ) {
				$added_anywhere = true;
				continue;
			}

			$result = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'     => self::TITLE,
					'menu-item-object-id' => $page_id,
					'menu-item-object'    => 'page',
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
				)
			);
			if ( $result && ! is_wp_error( $result ) ) {
				$added_anywhere = true;
			}
		}

		return $added_anywhere;
	}

	/**
	 * Ostrzeżenie w panelu admina, gdy nie udało się automatycznie dodać strony
	 * do żadnego menu (motyw nie rejestruje menu WP) — jawna informacja zamiast
	 * cichej porażki, niezależnie od tego, czy zadziała fallback HTML poniżej
	 * (fallback jest "best effort" — admin i tak dostaje pewny, ręczny link).
	 * Wyłączalne filtrem 'mp_lead_intake_show_menu_notice'.
	 *
	 * @return void
	 */
	public static function maybe_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '0' !== (string) get_option( self::OPTION_MENU_OK, '1' ) ) {
			return; // Brak flagi porażki (albo jeszcze nie ustawiona, albo sukces).
		}
		if ( ! apply_filters( 'mp_lead_intake_show_menu_notice', true ) ) {
			return;
		}

		$url = self::url();
		if ( '' === $url ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>MP Lead Intake:</strong> %s <a href="%s" target="_blank" rel="noopener">%s</a></p></div>',
			esc_html__( 'Twój motyw nie rejestruje standardowego menu WordPressa — wtyczka spróbowała automatycznie dołożyć link do formularza w wykrytym menu strony (element <nav>). Sprawdź na stronie, czy się pojawił; jeśli nie, dodaj ręcznie link do:', 'mp-lead-intake' ),
			esc_url( $url ),
			esc_html( $url )
		);
	}

	/**
	 * Uruchamia bufor wyjścia frontendu, który dołoży link do menu w renderowanym
	 * HTML-u (zob. inject_menu_link_html()) — TYLKO gdy oficjalne API menu (wyżej)
	 * zawiodło, bo motyw nie rejestruje żadnej lokalizacji menu. Zero kosztu/ryzyka
	 * dla motywów, dla których oficjalne API już zadziałało.
	 *
	 * Pominięte celowo dla feedów i REST — tam wstrzyknięcie HTML-u zepsułoby
	 * format odpowiedzi (XML/JSON). Wyłączalne filtrem
	 * 'mp_lead_intake_menu_html_fallback'.
	 *
	 * @return void
	 */
	public static function maybe_start_menu_buffer() {
		if ( is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( '0' !== (string) get_option( self::OPTION_MENU_OK, '1' ) ) {
			return;
		}
		if ( ! apply_filters( 'mp_lead_intake_menu_html_fallback', true ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'inject_menu_link_html' ) );
	}

	/**
	 * Dokłada link do formularza w pierwszym elemencie <nav> znalezionym w
	 * wyrenderowanym HTML-u strony — dla motywów, które nie rejestrują menu
	 * przez register_nav_menu() (więc oficjalne API WP nic tu nie może zrobić).
	 *
	 * Celowo NIE używamy DOMDocument na całej stronie: pełne parsowanie i
	 * ponowna serializacja całego dokumentu (encoding, DOCTYPE, znaczniki
	 * <script> ze znakami specjalnymi) może subtelnie uszkodzić inne części
	 * strony klienta. Zamiast tego: precyzyjnie dopasowany, ograniczony do
	 * fragmentu <nav>...</nav> wzorzec regex + preg_replace_callback (bez
	 * interpretacji backreference w treści), z insercją w JEDNYM znanym
	 * miejscu. Brak dopasowania = brak zmian w HTML-u (fail-safe) — wtedy
	 * jedyną informacją zostaje ostrzeżenie w adminie (maybe_admin_notice()).
	 * Idempotentne (znacznik data-mp-lead-intake pilnuje przed duplikatem).
	 *
	 * @param string $html Pełny HTML strony (callback ob_start()).
	 * @return string HTML, ewentualnie z dołożonym linkiem.
	 */
	public static function inject_menu_link_html( $html ) {
		if ( ! is_string( $html ) || false !== strpos( $html, 'data-mp-lead-intake' ) ) {
			return $html;
		}

		$url = self::url();
		if ( '' === $url ) {
			return $html;
		}

		$href = esc_url( $url );
		// Literał, nie self::TITLE — WPCS (WordPress.WP.I18n) wymaga w __()/esc_html__()
		// dosłownego stringa do wyciągania tłumaczeń, stąd ta jedna świadoma duplikacja.
		$label = esc_html__( 'Zapytanie ofertowe', 'mp-lead-intake' );
		$count = 0;

		// Wariant A: <nav> zawiera listę <ul>...</ul> — dołóż <li><a> jako
		// ostatni element listy (dziedziczy stylowanie CSS listy motywu).
		$with_list = preg_replace_callback(
			'/(<nav\b[^>]*>.*?)(<\/ul>\s*<\/nav>)/is',
			function ( $m ) use ( $href, $label ) {
				return $m[1] . '<li><a href="' . $href . '" data-mp-lead-intake="1">' . $label . '</a></li>' . $m[2];
			},
			$html,
			1,
			$count
		);
		if ( $count > 0 && null !== $with_list ) {
			return $with_list;
		}

		// Wariant B: <nav> bez listy (płaskie linki wprost w <nav>) — dołóż
		// <a> tuż przed zamknięciem </nav>.
		$flat = preg_replace_callback(
			'/(<nav\b[^>]*>.*?)(<\/nav>)/is',
			function ( $m ) use ( $href, $label ) {
				return $m[1] . '<a href="' . $href . '" data-mp-lead-intake="1">' . $label . '</a>' . $m[2];
			},
			$html,
			1,
			$count
		);
		if ( $count > 0 && null !== $flat ) {
			return $flat;
		}

		return $html; // Brak <nav> w markupie motywu — zostaje samo ostrzeżenie w adminie.
	}

	/**
	 * Meta description pod-strony formularza — SEO. Motyw klienta (jak w tym
	 * projekcie widać na przykładzie kredyt-kompas) może nie mieć żadnej wtyczki
	 * SEO ani własnej logiki meta description; ta pod-strona i tak powinna mieć
	 * poprawny opis w wynikach wyszukiwania. Działa TYLKO na tej jednej stronie
	 * (is_page), więc nie koliduje z ewentualną wtyczką SEO zainstalowaną później
	 * dla pozostałych stron — a i tak można wyłączyć filtrem
	 * 'mp_lead_intake_seo_meta_description'.
	 *
	 * @return void
	 */
	public static function maybe_meta_description() {
		$page_id = (int) get_option( self::OPTION );
		if ( ! $page_id || ! is_page( $page_id ) ) {
			return;
		}
		if ( ! apply_filters( 'mp_lead_intake_seo_meta_description', true ) ) {
			return;
		}

		printf(
			'<meta name="description" content="%s">' . "\n",
			// Literał (nie self::DESCRIPTION) — wymóg WPCS I18n, jak wyżej.
			esc_attr__( 'Wypełnij formularz zapytania ofertowego, a nasz zespół skontaktuje się z Tobą z indywidualną ofertą.', 'mp-lead-intake' )
		);
	}

	/**
	 * Usuwa pod-stronę i opcję (wywoływane przy deinstalacji).
	 *
	 * @return void
	 */
	public static function remove() {
		$page_id = (int) get_option( self::OPTION );
		if ( $page_id ) {
			self::remove_from_menus( $page_id );
			wp_delete_post( $page_id, true );
		}
		delete_option( self::OPTION );
		delete_option( self::OPTION_MENU_OK );
	}

	/**
	 * Usuwa wpisy menu wskazujące na stronę (across wszystkie menu, nie tylko
	 * przypisane lokalizacje) — inaczej po deinstalacji zostałby "martwy" link.
	 *
	 * @param int $page_id ID strony.
	 * @return void
	 */
	private static function remove_from_menus( $page_id ) {
		$menus = wp_get_nav_menus();
		if ( empty( $menus ) ) {
			return;
		}

		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( 'page' === $item->object && (int) $item->object_id === $page_id ) {
					wp_delete_post( $item->ID, true );
				}
			}
		}
	}

	/**
	 * Zwraca URL pod-strony (lub '' gdy brak).
	 *
	 * @return string
	 */
	public static function url() {
		$page_id = (int) get_option( self::OPTION );
		return $page_id ? (string) get_permalink( $page_id ) : '';
	}
}
