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
			return; // Strona już istnieje — nie duplikujemy.
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
			wp_delete_post( $page_id, true );
		}
		delete_option( self::OPTION );
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
