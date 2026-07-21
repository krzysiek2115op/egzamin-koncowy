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
			self::add_to_menus( $existing );
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
			self::add_to_menus( (int) $page_id );
		}
	}

	/**
	 * Dokłada stronę do KAŻDEJ lokalizacji menu motywu, która ma przypisane
	 * menu (get_nav_menu_locations()) — inaczej podstrona byłaby "niewidoczna"
	 * dla klienta mimo poprawnego utworzenia. Idempotentne: pomija lokalizację,
	 * jeśli strona już jest w danym menu (sprawdzenie po object_id).
	 * Wyłączalne filtrem 'mp_lead_intake_add_page_to_menu' (domyślnie true).
	 *
	 * @param int $page_id ID strony.
	 * @return void
	 */
	private static function add_to_menus( $page_id ) {
		if ( ! apply_filters( 'mp_lead_intake_add_page_to_menu', true ) ) {
			return;
		}

		$locations = get_nav_menu_locations();
		if ( empty( $locations ) ) {
			return;
		}

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
				continue;
			}

			wp_update_nav_menu_item(
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
		}
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
