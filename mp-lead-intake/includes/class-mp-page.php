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
				'post_title'   => 'Zapytanie ofertowe',
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
					'menu-item-title'     => 'Zapytanie ofertowe',
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
	 * cichej porażki. Wyłączalne filtrem 'mp_lead_intake_show_menu_notice'.
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
			esc_html__( 'nie udało się automatycznie dodać strony formularza do menu — Twój motyw nie używa standardowego systemu menu WordPressa. Dodaj ręcznie link do:', 'mp-lead-intake' ),
			esc_url( $url ),
			esc_html( $url )
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
