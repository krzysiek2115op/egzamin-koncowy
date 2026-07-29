<?php
/**
 * Test na ZYWYM WordPressie: anonimizacja RODO naprawde czysci dane klienta.
 *
 * Uruchamianie: wp eval-file tests/koncowe/rodo-anonimizacja.php
 *
 * Do wersji 1.2.1 czyszczenie kolejki powiadomien filtrowalo po kolumnie
 * `audience`, ktorej NIGDY nie bylo w schemacie — istnieje tylko w tablicy
 * wiadomosci w pamieci Dzialu 7. Zapytanie padalo, `$wpdb->update()` zwracalo
 * `false`, a `(int) false` to 0 — czyli bez jednego sygnalu bledu. Zadanie
 * usuniecia danych konczylo sie „sukcesem", podczas gdy w bazie zostawal adres
 * klienta, nazwa firmy w temacie i pelna tresc wiadomosci.
 *
 * Test sprawdza SKUTEK w bazie, nie kod zwrotny — bo to wlasnie kod zwrotny
 * klamal.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_r'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function mr_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_r']['pass'];
		$GLOBALS['mp_r']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_r']['fail'];
	$GLOBALS['mp_r']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function mr_dump() {
	if ( empty( $GLOBALS['mp_r']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_r'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	if ( is_dir( '/scr' ) ) {
		file_put_contents( '/scr/mp-rodo.txt', $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	$GLOBALS['mp_r']['lines'] = array();
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
register_shutdown_function( 'mr_dump' );

global $wpdb;

$flow_t  = MP_Sales_Workflow_DB::flow_table();
$notif_t = MP_Sales_Workflow_DB::notifications_table();
$now     = current_time( 'mysql', true );
$lead_id = 700000 + (int) substr( (string) time(), -5 );
$adres   = 'rodo' . $lead_id . '@example.test';

$GLOBALS['mp_r']['lines'][] = '=== PRZYGOTOWANIE: proces z powiadomieniem do klienta i do pracownika ===';

$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$flow_t,
	array(
		'lead_id'      => $lead_id,
		'status'       => MP_Sales_Workflow_DB::STATUS_NEW,
		'client_name'  => 'Firma Do Usuniecia Sp. z o.o.',
		'client_email' => $adres,
		'offer_number' => 'OF/2999/R' . $lead_id,
		'lock_version' => 1,
		'created_at'   => $now,
		'updated_at'   => $now,
	)
);
$flow_id = (int) $wpdb->insert_id;
mr_ok( $flow_id > 0, 'proces testowy zalozony', $wpdb->last_error );

$pracownik = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Powiadomienie do KLIENTA — `recipient_user_id` NULL, tak jak buduje je Dzial 7.
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$notif_t,
	array(
		'flow_id'           => $flow_id,
		'template'          => MP_SW_Templates::TPL_OFFER_SENT,
		'template_version'  => '1.0.0',
		'lang'              => 'pl',
		'recipient'         => $adres,
		'recipient_user_id' => null,
		'subject'           => 'Oferta OF/2999/R' . $lead_id . ' dla Firma Do Usuniecia Sp. z o.o.',
		'body'              => 'Dzien dobry, w zalaczeniu oferta dla Firma Do Usuniecia Sp. z o.o., kontakt: ' . $adres,
		'status'            => 'sent',
		'attempts'          => 1,
		'created_at'        => $now,
		'updated_at'        => $now,
	)
);
$n_klient = (int) $wpdb->insert_id;

// Powiadomienie do PRACOWNIKA — ma zostac nietkniete (to nie sa dane klienta).
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$notif_t,
	array(
		'flow_id'           => $flow_id,
		'template'          => MP_SW_Templates::TPL_LEAD_ASSIGNED,
		'template_version'  => '1.0.0',
		'lang'              => 'pl',
		'recipient'         => 'handlowiec' . $lead_id . '@firma.test',
		'recipient_user_id' => $pracownik > 0 ? $pracownik : 1,
		'subject'           => 'Nowy lead do obslugi',
		'body'              => 'Przydzielono Ci nowy proces sprzedazowy.',
		'status'            => 'sent',
		'attempts'          => 1,
		'created_at'        => $now,
		'updated_at'        => $now,
	)
);
$n_pracownik = (int) $wpdb->insert_id;

mr_ok( $n_klient > 0 && $n_pracownik > 0, 'dwa powiadomienia w kolejce (klient + pracownik)', $wpdb->last_error );

$GLOBALS['mp_r']['lines'][] = '';
$GLOBALS['mp_r']['lines'][] = '=== ANONIMIZACJA ===';

$zmienione = MP_SW_Privacy::anonymize_lead( $lead_id );
mr_ok( $zmienione > 0, 'anonimizacja zglasza zmienione wiersze', (string) $zmienione );

$proces = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE id = %d", $flow_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
mr_ok( is_array( $proces ) && '' === (string) $proces['client_name'], 'nazwa firmy usunieta z procesu', is_array( $proces ) ? $proces['client_name'] : '?' );
mr_ok( is_array( $proces ) && $adres !== (string) $proces['client_email'], 'adres usuniety z procesu', is_array( $proces ) ? $proces['client_email'] : '?' );

$kl = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$notif_t} WHERE id = %d", $n_klient ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( mr_ok( is_array( $kl ), 'wiersz powiadomienia do klienta nadal istnieje (zostaje slad wysylki)' ) ) {
	mr_ok( $adres !== (string) $kl['recipient'], 'adres klienta usuniety z kolejki', (string) $kl['recipient'] );
	mr_ok( false === strpos( (string) $kl['subject'], 'Firma Do Usuniecia' ), 'nazwa firmy usunieta z tematu', (string) $kl['subject'] );
	mr_ok( false === strpos( (string) $kl['body'], $adres ), 'adres usuniety z tresci wiadomosci', substr( (string) $kl['body'], 0, 80 ) );
	mr_ok( false === strpos( (string) $kl['body'], 'Firma Do Usuniecia' ), 'nazwa firmy usunieta z tresci wiadomosci' );
	mr_ok( 'sent' === (string) $kl['status'], 'status wysylki zachowany (kryt. 5.5 — historia zostaje)', (string) $kl['status'] );
}

$pr = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$notif_t} WHERE id = %d", $n_pracownik ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
mr_ok(
	is_array( $pr ) && 'handlowiec' . $lead_id . '@firma.test' === (string) $pr['recipient'],
	'powiadomienie do PRACOWNIKA nietkniete (to nie sa dane klienta)',
	is_array( $pr ) ? $pr['recipient'] : '?'
);
mr_ok( is_array( $pr ) && 'Nowy lead do obslugi' === (string) $pr['subject'], 'temat powiadomienia wewnetrznego nietkniety' );

// Sprzatanie — test nie ma prawa zostawiac smieci w bazie klienta.
$wpdb->delete( $notif_t, array( 'flow_id' => $flow_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->delete( $flow_t, array( 'id' => $flow_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

mr_dump();
