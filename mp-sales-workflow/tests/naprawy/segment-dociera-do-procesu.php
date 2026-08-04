<?php
/**
 * Segment klienta ginął w drodze z wtyczki 1 do wtyczki 3.
 *
 * Uruchamianie: wp eval-file tests/naprawy/segment-dociera-do-procesu.php
 *
 * Formularz zapisywał `segment` do `wp_mp_leads` poprawnie, Dział 2 odczytywał
 * go z tej tabeli razem z krajem i adresem, Dział 7 wstawiał do szablonu
 * powiadomienia — a Dział 8 przy budowaniu wiersza procesu uzupełniał `lang`,
 * `country`, `client_name` i `client_email` i **pomijał `segment`**. Kolumna
 * `wp_mp_sw_flow.segment` była więc pusta zawsze, dla każdego procesu, a
 * handlowiec dostawał maila z wierszem „Segment: " i niczym po dwukropku.
 *
 * Nie widział tego żaden test ani krytyk. Krytyk 7.2 pilnuje znaczników
 * NIEPODMIENIONYCH, a ten był podmieniony — na pusty ciąg. Dokładnie tę pułapkę
 * opisuje komentarz nad naprawianym kodem, dla pola `offer_number`:
 * „znacznik BYŁ podmieniony, tyle że na pusty ciąg, więc krytyk 7.2 tego nie
 * widział". Naprawa objęła wtedy numer oferty i nie objęła sąsiedniego pola.
 *
 * Wykryte na artefakcie: wiersz procesu na świeżej instalacji miał NULL
 * w `segment`, choć lead w BD-3 miał `B2B`.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_sg'] = array(
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
function sg_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_sg']['pass'];
		$GLOBALS['mp_sg']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_sg']['fail'];
	$GLOBALS['mp_sg']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function sg_koniec() {
	if ( empty( $GLOBALS['mp_sg']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_sg'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_sg']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'sg_koniec' );

/**
 * Zakłada leada w BD-3 i puszcza zdarzenie, na które reaguje wtyczka 3.
 *
 * @param int    $lead_id Identyfikator leada.
 * @param string $segment Segment do zapisania (pusty = kolumna zostaje pusta).
 * @return void
 */
function sg_zglos( $lead_id, $segment ) {
	global $wpdb;

	if ( class_exists( 'MP_Lead_Intake_DB' ) ) {
		$wpdb->insert( // phpcs:ignore WordPress.DB
			MP_Lead_Intake_DB::leads_table(),
			array(
				'id'           => $lead_id,
				'company_name' => 'Segmentowa ' . $lead_id . ' Sp. z o.o.',
				'nip'          => '777' . str_pad( (string) ( $lead_id % 10000000 ), 7, '0', STR_PAD_LEFT ),
				'email'        => 'segment+' . $lead_id . '@example.test',
				'country'      => 'PL',
				'segment'      => $segment,
				'status'       => 'new',
			)
		);
	}

	do_action(
		'mp_lead_created',
		$lead_id,
		array(
			'lead_id'      => $lead_id,
			'company_name' => 'Segmentowa ' . $lead_id . ' Sp. z o.o.',
			'email'        => 'segment+' . $lead_id . '@example.test',
			'country'      => 'PL',
			'lang'         => 'pl',
		)
	);
}

/**
 * Wiersz procesu dla leada.
 *
 * @param int $lead_id Identyfikator leada.
 * @return array|null
 */
function sg_proces( $lead_id ) {
	global $wpdb;

	$t = MP_Sales_Workflow_DB::flow_table();

	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE lead_id = %d", $lead_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

/**
 * Sprząta po leadzie.
 *
 * @param int $lead_id Identyfikator leada.
 * @return void
 */
function sg_sprzataj( $lead_id ) {
	global $wpdb;

	$flow_t = MP_Sales_Workflow_DB::flow_table();
	$flow   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$flow_t} WHERE lead_id = %d", $lead_id ) ); // phpcs:ignore WordPress.DB

	if ( $flow > 0 ) {
		$wpdb->delete( MP_Sales_Workflow_DB::activity_table(), array( 'flow_id' => $flow ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( MP_Sales_Workflow_DB::notifications_table(), array( 'flow_id' => $flow ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( $flow_t, array( 'id' => $flow ) ); // phpcs:ignore WordPress.DB
	}

	$wpdb->delete( MP_Sales_Workflow_DB::events_table(), array( 'lead_id' => $lead_id ) ); // phpcs:ignore WordPress.DB

	if ( class_exists( 'MP_Lead_Intake_DB' ) ) {
		$wpdb->delete( MP_Lead_Intake_DB::leads_table(), array( 'id' => $lead_id ) ); // phpcs:ignore WordPress.DB
	}
}

$baza    = 920000 + wp_rand( 1, 9000 );
$z_segm  = $baza;
$bez_seg = $baza + 1;

sg_sprzataj( $z_segm );
sg_sprzataj( $bez_seg );

/* ==================================================================== A */

$GLOBALS['mp_sg']['lines'][] = '=== A. segment z BD-3 dociera do wiersza procesu ===';

sg_zglos( $z_segm, 'B2B' );
$proces = sg_proces( $z_segm );

if ( ! sg_ok( is_array( $proces ), 'proces powstal' ) ) {
	sg_sprzataj( $z_segm );
	sg_sprzataj( $bez_seg );
	return;
}

sg_ok(
	'B2B' === (string) $proces['segment'],
	'wiersz procesu ma segment z BD-3',
	'segment=' . var_export( $proces['segment'], true )
);

/* ==================================================================== B */

$GLOBALS['mp_sg']['lines'][] = '';
$GLOBALS['mp_sg']['lines'][] = '=== B. i widzi go handlowiec w powiadomieniu ===';

$body = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB
	$wpdb->prepare(
		'SELECT body FROM ' . MP_Sales_Workflow_DB::notifications_table() . ' WHERE flow_id = %d ORDER BY id DESC LIMIT 1',
		(int) $proces['id']
	)
);

if ( '' === $body ) {
	$GLOBALS['mp_sg']['lines'][] = '  [ .. ] brak powiadomienia (brak skonfigurowanego handlowca) — pomijam';
} else {
	sg_ok(
		false !== strpos( $body, 'Segment: B2B' ),
		'powiadomienie niesie segment, a nie pusty wiersz',
		'body=' . str_replace( "\n", ' | ', mb_substr( $body, 0, 160 ) )
	);
	sg_ok(
		false === strpos( $body, "Segment: \n" ) && ! preg_match( '~Segment:\s*$~m', $body ),
		'w tresci nie zostal pusty wiersz „Segment:"'
	);
}

/* ==================================================================== C */

$GLOBALS['mp_sg']['lines'][] = '';
$GLOBALS['mp_sg']['lines'][] = '=== C. kontr-asercje: nic innego sie nie zmienilo ===';

sg_ok( 'PL' === (string) $proces['country'], 'kraj nadal dociera', 'country=' . $proces['country'] );
sg_ok( '' !== (string) $proces['client_name'], 'nazwa klienta nadal dociera' );
sg_ok( '' !== (string) $proces['client_email'], 'adres klienta nadal dociera' );
sg_ok( 'pl' === (string) $proces['lang'], 'jezyk nadal dociera', 'lang=' . $proces['lang'] );

// Lead bez segmentu nie może wywrócić przebiegu ani wpisać śmiecia.
sg_zglos( $bez_seg, '' );
$bez = sg_proces( $bez_seg );

sg_ok( is_array( $bez ), 'lead BEZ segmentu tez zaklada proces' );
sg_ok(
	is_array( $bez ) && ( null === $bez['segment'] || '' === (string) $bez['segment'] ),
	'i zostawia kolumne pusta, zamiast wpisywac cokolwiek',
	is_array( $bez ) ? 'segment=' . var_export( $bez['segment'], true ) : ''
);

/* ==================================================================== D */

$GLOBALS['mp_sg']['lines'][] = '';
$GLOBALS['mp_sg']['lines'][] = '=== D. zdarzenie bez danych leada nie kasuje segmentu ===';

// Cron i zdarzenia systemowe nie niosą danych klienta. Gdyby uzupełnianie
// nadpisywało kolumnę bezwarunkowo, pierwszy taki przebieg wyczyściłby segment
// zapisany przy zakładaniu procesu — ta sama pułapka, którą opisuje komentarz
// przy `client_name` i `client_email`.
if ( class_exists( 'MP_Lead_Intake_DB' ) ) {
	$wpdb->update( // phpcs:ignore WordPress.DB
		MP_Lead_Intake_DB::leads_table(),
		array( 'segment' => '' ),
		array( 'id' => $z_segm )
	);
}

do_action(
	'mp_lead_created',
	$z_segm,
	array(
		'lead_id' => $z_segm,
		'country' => 'PL',
		'lang'    => 'pl',
	)
);

$po = sg_proces( $z_segm );

sg_ok(
	is_array( $po ) && 'B2B' === (string) $po['segment'],
	'segment zapisany wczesniej przetrwal zdarzenie bez danych',
	is_array( $po ) ? 'segment=' . var_export( $po['segment'], true ) : 'brak procesu'
);

sg_sprzataj( $z_segm );
sg_sprzataj( $bez_seg );
