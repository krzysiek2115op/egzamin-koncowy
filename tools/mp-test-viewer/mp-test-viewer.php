<?php
/**
 * Plugin Name:       MP Test Viewer (TYLKO DO TESTÓW — nie wysyłać klientowi)
 * Description:       Podgląd tabel wp_mp_leads / wp_mp_activity_log / wp_mp_offers
 *                     oraz zaplanowanych zadań WP-Cron pluginu MP Lead Intake.
 *                     Wyłącznie do ręcznych testów na WordPress Playground —
 *                     read-only, brak zapisu, brak formularzy.
 * Version:            1.0.0
 * Requires Plugins:   mp-lead-intake
 *
 * @package MP_Test_Viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'mp_test_viewer_menu' );

function mp_test_viewer_menu() {
	add_menu_page(
		'MP Test Viewer',
		'MP Test Viewer',
		'manage_options',
		'mp-test-viewer',
		'mp_test_viewer_render',
		'dashicons-visibility',
		99
	);
}

function mp_test_viewer_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$leads_table  = $wpdb->prefix . 'mp_leads';
	$log_table    = $wpdb->prefix . 'mp_activity_log';
	$offers_table = $wpdb->prefix . 'mp_offers';

	echo '<div class="wrap"><h1>MP Test Viewer — podgląd danych (read-only)</h1>';
	echo '<p style="color:#a00;">Narzędzie tylko do testów. Nie instalować na produkcji, nie wysyłać klientowi.</p>';

	// --- Leady ---
	echo '<h2>wp_mp_leads (ostatnie 30)</h2>';
	$leads = $wpdb->get_results( "SELECT * FROM $leads_table ORDER BY id DESC LIMIT 30", ARRAY_A ); // phpcs:ignore
	mp_test_viewer_table(
		$leads,
		array( 'id', 'company_name', 'nip', 'email', 'country', 'segment', 'status', 'vat_status', 'vat_valid', 'company_status', 'score', 'salesman_id', 'vat_attempts', 'created_at', 'updated_at', 'deleted_at' )
	);

	// --- Log aktywności ---
	echo '<h2>wp_mp_activity_log (ostatnie 50)</h2>';
	$log = $wpdb->get_results( "SELECT * FROM $log_table ORDER BY id DESC LIMIT 50", ARRAY_A ); // phpcs:ignore
	mp_test_viewer_table(
		$log,
		array( 'id', 'lead_id', 'action', 'description', 'user_id', 'ip_address', 'created_at' )
	);

	// --- Oferty (plugin 2 — tabela istnieje już dziś w BD-3, zwykle pusta) ---
	echo '<h2>wp_mp_offers (ostatnie 20)</h2>';
	$offers = $wpdb->get_results( "SELECT * FROM $offers_table ORDER BY id DESC LIMIT 20", ARRAY_A ); // phpcs:ignore
	mp_test_viewer_table( $offers, array( 'id', 'lead_id', 'offer_number', 'status', 'total_amount', 'currency', 'created_at' ) );

	// --- Zaplanowane zadania WP-Cron pluginu ---
	echo '<h2>Zaplanowane zadania WP-Cron (mp_lead_intake_*)</h2>';
	mp_test_viewer_cron();

	echo '</div>';
}

/**
 * Renderuje prostą tabelę HTML z tablicy wierszy (lub komunikat "brak").
 *
 * @param array $rows    Wiersze (assoc).
 * @param array $columns Kolumny do wyświetlenia (w tej kolejności).
 */
function mp_test_viewer_table( $rows, $columns ) {
	if ( empty( $rows ) ) {
		echo '<p><em>Brak wierszy.</em></p>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr>';
	foreach ( $columns as $col ) {
		echo '<th>' . esc_html( $col ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		echo '<tr>';
		foreach ( $columns as $col ) {
			$val = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
			if ( strlen( $val ) > 80 ) {
				$val = substr( $val, 0, 80 ) . '…';
			}
			echo '<td>' . esc_html( $val ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
}

/**
 * Wypisuje zaplanowane zdarzenia WP-Cron dla hooków pluginu MP Lead Intake.
 */
function mp_test_viewer_cron() {
	$hooks = array(
		'mp_lead_intake_ip_retention',
		'mp_lead_intake_vat_reconcile',
		'mp_lead_intake_verify_vat',
	);

	$crons = _get_cron_array();
	if ( empty( $crons ) ) {
		echo '<p><em>Brak zaplanowanych zadań WP-Cron w ogóle.</em></p>';
		return;
	}

	$found = array();
	foreach ( $crons as $timestamp => $hooks_at_ts ) {
		foreach ( $hooks_at_ts as $hook => $events ) {
			if ( ! in_array( $hook, $hooks, true ) ) {
				continue;
			}
			foreach ( $events as $event ) {
				$found[] = array(
					'hook'      => $hook,
					'args'      => wp_json_encode( isset( $event['args'] ) ? $event['args'] : array() ),
					'timestamp' => gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC',
				);
			}
		}
	}

	mp_test_viewer_table( $found, array( 'hook', 'args', 'timestamp' ) );
}
