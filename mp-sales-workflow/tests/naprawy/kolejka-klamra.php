<?php
/**
 * P3-S1 — kolejka maili bez atomowego przejmowania zadania.
 *
 * Uruchamianie: wp eval-file tests/naprawy/kolejka-klamra.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-S1  Kolejka maili bez atomowego przejmowania zadania
 *
 * Blad byl w rejestrze od 29.07.2026 z PUSTYM polem `test` — czyli zapisany,
 * zaakceptowany i niepilnowany niczym. Para 1.15 zglaszala to przy kazdym
 * przebiegu audytu.
 *
 * Na czym polegal: `MP_SW_Queue::run()` pobieralo paczke zwyklym SELECT-em,
 * a licznik prob rosl dopiero w `send_one()`. Miedzy jednym a drugim drugi
 * przebieg crona widzial te same wiersze z `attempts = 0` i wysylal je po raz
 * drugi. WP-Cron nie ma pojedynczego procesu, a jego blokada `doing_cron` trwa
 * 60 sekund — przy wolnym SMTP paczka 20 wiadomosci potrafi isc dluzej.
 *
 * Zadania follow-up mialy klamre od poczatku (MP_SW_Cron::claim), kolejka maili
 * nie. Tabela powiadomien nie ma kolumny na token, wiec klamra jest
 * optymistyczna: licznik prob pelni role wersji wiersza, a jego inkrementacja
 * JEST przejeciem zadania. Kto podbil licznik, ten wysyla.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_kk'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegol.
 * @return bool
 */
function kk_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_kk']['pass'];
		$GLOBALS['mp_kk']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_kk']['fail'];
	$GLOBALS['mp_kk']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

$tabela = MP_Sales_Workflow_DB::notifications_table();
$teraz  = current_time( 'mysql', true );

$GLOBALS['mp_kk']['lines'][] = '=== A. klamra na wierszu kolejki ===';

kk_ok(
	method_exists( 'MP_SW_Queue', 'claim' ),
	'MP_SW_Queue::claim() istnieje'
);

if ( ! method_exists( 'MP_SW_Queue', 'claim' ) ) {
	$GLOBALS['mp_kk']['lines'][] = '  (dalsze asercje pominiete — brak metody)';
} else {
	// Wiersz kolejki ma klucz obcy na proces — najpierw proces.
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		MP_Sales_Workflow_DB::flow_table(),
		array(
			'lead_id'      => 999001,
			'status'       => MP_Sales_Workflow_DB::STATUS_NEW,
			'client_name'  => 'Firma Testowa Klamra',
			'client_email' => 'klamra@test.local',
			'offer_number' => 'OF/2999/KK',
			'lock_version' => 1,
			'created_at'   => $teraz,
			'updated_at'   => $teraz,
		)
	);
	$flow_id = (int) $wpdb->insert_id;
	kk_ok( $flow_id > 0, 'proces testowy zalozony', $wpdb->last_error );

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tabela,
		array(
			'flow_id'           => $flow_id,
			'template'          => 'test.klamra',
			'template_version'  => '1.0.0',
			'lang'              => 'pl',
			'recipient'         => 'klamra@test.local',
			'recipient_user_id' => null,
			'subject'           => 'Test klamry',
			'body'              => 'Tresc',
			'status'            => MP_SW_Queue::STATUS_QUEUED,
			'attempts'          => 0,
			'created_at'        => $teraz,
			'updated_at'        => $teraz,
		)
	);
	$id = (int) $wpdb->insert_id;

	kk_ok( $id > 0, 'wiersz testowy w kolejce', $wpdb->last_error );

	// Przebieg A widzi attempts = 0 i przejmuje zadanie.
	$pierwszy = MP_SW_Queue::claim( $id, 0 );
	kk_ok( true === $pierwszy, 'pierwszy przebieg przejmuje zadanie' );

	$po = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$tabela} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	kk_ok( 1 === $po, 'przejecie podbilo licznik prob', 'attempts=' . $po );

	/*
	 * SEDNO. Przebieg B wystartowal rownolegle i ma STARY odczyt: dla niego
	 * wiersz nadal ma attempts = 0. Bez klamry wyslalby te sama wiadomosc
	 * drugi raz. Z klamra warunek `attempts = 0` juz nie trafia.
	 */
	$drugi = MP_SW_Queue::claim( $id, 0 );
	kk_ok( false === $drugi, 'drugi przebieg ze STARYM odczytem nie przejmuje zadania' );

	$po2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$tabela} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	kk_ok( 1 === $po2, 'nieudane przejecie nie zmienilo licznika', 'attempts=' . $po2 );

	/*
	 * KONTR-ASERCJA. Bez niej „naprawa" mogla by polegac na odrzucaniu
	 * wszystkiego — kolejka przestalaby wysylac cokolwiek, a test i tak
	 * bylby zielony. Swiezy odczyt musi przejmowac normalnie.
	 */
	$trzeci = MP_SW_Queue::claim( $id, 1 );
	kk_ok( true === $trzeci, 'przebieg ze SWIEZYM odczytem przejmuje normalnie' );

	kk_ok(
		false === MP_SW_Queue::claim( 0, 0 ),
		'nieistniejacy wiersz nie da sie przejac'
	);

	$wpdb->delete( $tabela, array( 'flow_id' => $flow_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( MP_Sales_Workflow_DB::flow_table(), array( 'id' => $flow_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

$GLOBALS['mp_kk']['lines'][] = '';
$GLOBALS['mp_kk']['lines'][] = '=== B. run() korzysta z klamry ===';

$zrodlo = file_get_contents( dirname( dirname( __DIR__ ) ) . '/includes/class-mp-sw-queue.php' );

kk_ok(
	is_string( $zrodlo ) && false !== strpos( $zrodlo, 'self::claim(' ),
	'run() wola klamre przed wysylka'
);

// Licznik prob ma byc podbijany W klamrze, a nie osobno w send_one() — inaczej
// przejecie i inkrementacja znowu sa dwiema operacjami.
kk_ok(
	is_string( $zrodlo ) && 1 === substr_count( $zrodlo, "'attempts'   => \$attempts," ) + substr_count( $zrodlo, 'attempts = attempts + 1' ),
	'licznik prob podbijany w jednym miejscu'
);

echo implode( "\n", $GLOBALS['mp_kk']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_kk']['pass'], $GLOBALS['mp_kk']['fail'] );
echo ( 0 === $GLOBALS['mp_kk']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
