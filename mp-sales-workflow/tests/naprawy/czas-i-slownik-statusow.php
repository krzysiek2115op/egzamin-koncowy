<?php
/**
 * Dwa ustalenia audytu z pluginu 3: termin liczony strefą PHP i dane, których
 * nikt nie czyta.
 *
 * Uruchamianie: wp eval-file tests/naprawy/czas-i-slownik-statusow.php
 *
 * A. TERMIN. Agent 5.2 i `MP_SW_D6_Scheduler::due_at()` liczyły przyszłą datę
 *    tak: `gmdate( ..., strtotime( '<data GMT>' . ' +1 day' ) )`. `strtotime()`
 *    nie wie, że dostał GMT — czyta łańcuch STREFĄ DOMYŚLNĄ PHP, a wynik wraca
 *    przez `gmdate()`, czyli znowu do GMT. Przy strefie innej niż UTC termin
 *    przesuwa się o jej przesunięcie, a przy przejściu na czas letni — o godzinę
 *    dodatkowo, bo doba lokalna ma wtedy 23 godziny. Ten sam plik liczy czas
 *    w harmonogramie POPRAWNIE (`class-mp-sw-cron.php` dokleja ' UTC'), więc
 *    jedna wtyczka miała dwie różne odpowiedzi na to samo pytanie.
 *
 *    Nie: „WordPress i tak ustawia UTC". Ustawia — i dlatego to ustalenie jest
 *    drobne, a nie krytyczne. Ale wystarczy jedna wtyczka albo jeden hosting
 *    wołający `date_default_timezone_set()`, żeby SLA całego procesu zjechało
 *    o dwie godziny bez śladu w logach. Kod, który sam ogłasza w komentarzu
 *    „jedno źródło czasu w GMT", ma to spełniać niezależnie od otoczenia.
 *
 * B. SŁOWNIK. Agent 5.1 liczył `known_status` — czy status docelowy w ogóle
 *    istnieje w słowniku — i wkładał go do wyniku. Krytyk K5.1 tej wartości
 *    NIE CZYTAŁ: każdą odmowę opisywał jednym zdaniem „Przejście X → Y spoza
 *    słownika". Literówka w statusie („wygrny") dostawała więc komunikat
 *    mówiący o nielegalnym przejściu, a nie o nieistniejącym statusie — czyli
 *    kod policzył rozróżnienie i wyrzucił je do kosza.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_cs'] = array(
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
function cs_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_cs']['pass'];
		$GLOBALS['mp_cs']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_cs']['fail'];
	$GLOBALS['mp_cs']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik i przywraca strefę domyślną.
 *
 * @return void
 */
function cs_koniec() {
	if ( empty( $GLOBALS['mp_cs']['lines'] ) ) {
		return;
	}

	if ( ! empty( $GLOBALS['mp_cs_strefa'] ) ) {
		date_default_timezone_set( $GLOBALS['mp_cs_strefa'] ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
	}

	$r    = $GLOBALS['mp_cs'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_cs']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'cs_koniec' );

$GLOBALS['mp_cs_strefa'] = date_default_timezone_get(); // phpcs:ignore WordPress.DateTime.RestrictedFunctions

/**
 * Sekunda GMT z łańcucha datetime — z JAWNĄ strefą, żeby sam test nie miał
 * błędu, którego szuka.
 *
 * @param string $gmt Data w GMT.
 * @return int
 */
function cs_sekunda( $gmt ) {
	return (int) strtotime( $gmt . ' UTC' );
}

/** Strefy, w których liczymy to samo. +05:45 łapie przesunięcia niepełnogodzinne. */
$cs_strefy = array( 'UTC', 'Europe/Warsaw', 'America/Los_Angeles', 'Asia/Kathmandu' );

/* ==================================================================== A */

$GLOBALS['mp_cs']['lines'][] = '=== A. SLA to doba, nie doba lokalna ===';

$cs_agent   = new MP_SW_D5_Agent_Effects( '5.2', 'skutki', 'test' );
$cs_kontekst = new MP_SW_Context(
	array(
		'transition' => array(
			'from'           => MP_Sales_Workflow_DB::STATUS_NEW,
			'to'             => MP_Sales_Workflow_DB::STATUS_ASSIGNED,
			'allowed'        => true,
			'changes_status' => true,
		),
	)
);

foreach ( $cs_strefy as $cs_tz ) {
	date_default_timezone_set( $cs_tz ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions

	$cs_wynik = $cs_agent->run( $cs_kontekst );
	$cs_dane  = $cs_wynik->get_data();
	$cs_sla   = isset( $cs_dane['sla_due_at'] ) ? (string) $cs_dane['sla_due_at'] : '';
	$cs_teraz = current_time( 'mysql', true );
	$cs_roz   = '' === $cs_sla ? -1 : cs_sekunda( $cs_sla ) - cs_sekunda( $cs_teraz );

	cs_ok(
		abs( $cs_roz - DAY_IN_SECONDS ) <= 2,
		'SLA w strefie ' . $cs_tz . ' wypada dokladnie dobe pozniej',
		'sla=' . $cs_sla . ' teraz=' . $cs_teraz . ' roznica=' . $cs_roz . 's'
	);
}

date_default_timezone_set( $GLOBALS['mp_cs_strefa'] ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions

/* ==================================================================== B */

$GLOBALS['mp_cs']['lines'][] = '';
$GLOBALS['mp_cs']['lines'][] = '=== B. termin zadania przez zmiane czasu na letni ===';

/*
 * 29 marca 2026 to ostatnia niedziela marca — w Europie zegar idzie wtedy o
 * godzinę do przodu. Doba lokalna ma 23 godziny, więc „+3 dni" liczone strefą
 * warszawską przesuwa termin o godzinę wstecz w GMT. Termin zadania nie ma
 * prawa zależeć od tego, jaką strefę ustawił ktoś inny.
 */
$cs_od       = '2026-03-27 01:30:00';
$cs_oczekiwany = '2026-03-30 01:30:00';

foreach ( $cs_strefy as $cs_tz ) {
	date_default_timezone_set( $cs_tz ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions

	$cs_due = MP_SW_D6_Scheduler::due_at( 3, $cs_od );

	cs_ok(
		$cs_oczekiwany === $cs_due,
		'due_at(+3d) w strefie ' . $cs_tz . ' daje ' . $cs_oczekiwany,
		'otrzymano ' . $cs_due
	);
}

date_default_timezone_set( $GLOBALS['mp_cs_strefa'] ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions

cs_ok(
	'2026-01-05 08:00:00' === MP_SW_D6_Scheduler::due_at( 3, '2026-01-02 08:00:00' ),
	'kontr-asercja: zwykly termin bez zmiany czasu liczy sie jak dotad'
);
cs_ok(
	'2026-01-02 08:00:00' === MP_SW_D6_Scheduler::due_at( 0, '2026-01-02 08:00:00' ),
	'kontr-asercja: zero dni to ten sam moment'
);

/* ==================================================================== C */

$GLOBALS['mp_cs']['lines'][] = '';
$GLOBALS['mp_cs']['lines'][] = '=== C. status spoza slownika mowi o STATUSIE, nie o przejsciu ===';

/**
 * Uruchamia parę 5.1 dla zadanego przejścia.
 *
 * @param string $z  Status źródłowy.
 * @param string $na Status docelowy.
 * @return array {agent: MP_SW_Result, krytyk: MP_SW_Result, dane: array}
 */
function cs_para( $z, $na ) {
	$kontekst = new MP_SW_Context(
		array(
			'event'     => array( 'type' => MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE ),
			'to_status' => $na,
			'flow'      => array( 'row' => array( 'status' => $z ) ),
		)
	);

	$agent  = new MP_SW_D5_Agent_Transition( '5.1', 'przejscie', 'test' );
	$krytyk = new MP_SW_D5_Critic_Legality( 'K5.1', 'legalnosc', 'test' );
	$wynik  = $agent->run( $kontekst );

	return array(
		'agent'  => $wynik,
		'krytyk' => $krytyk->review( $wynik, $kontekst ),
		'dane'   => (array) $wynik->get_data(),
	);
}

$cs_nieznany = cs_para( MP_Sales_Workflow_DB::STATUS_NEW, 'wygrny' );
$cs_przejscie = (array) ( $cs_nieznany['dane']['transition'] ?? array() );

cs_ok(
	array_key_exists( 'known_status', $cs_przejscie ) && false === $cs_przejscie['known_status'],
	'agent 5.1 melduje, ze statusu docelowego nie ma w slowniku',
	'known_status=' . var_export( $cs_przejscie['known_status'] ?? null, true )
);
cs_ok(
	! $cs_nieznany['krytyk']->is_ok(),
	'krytyk K5.1 odmawia'
);

$cs_komunikaty = implode( ' ', (array) $cs_nieznany['krytyk']->get_errors() );

cs_ok(
	false !== mb_strpos( $cs_komunikaty, 'wygrny' ),
	'komunikat nazywa status, ktorego nie ma',
	'komunikat=' . $cs_komunikaty
);
cs_ok(
	false !== mb_stripos( $cs_komunikaty, 'status' ) && false === mb_stripos( $cs_komunikaty, 'Przejście' ),
	'komunikat mowi o NIEISTNIEJACYM STATUSIE, a nie o nielegalnym przejsciu',
	'komunikat=' . $cs_komunikaty
);

/* ==================================================================== D */

$GLOBALS['mp_cs']['lines'][] = '';
$GLOBALS['mp_cs']['lines'][] = '=== D. kontr-asercje: znane statusy dzialaja jak dotad ===';

$cs_znany = cs_para( MP_Sales_Workflow_DB::STATUS_WON, MP_Sales_Workflow_DB::STATUS_NEW );
$cs_tp    = (array) ( $cs_znany['dane']['transition'] ?? array() );
$cs_km    = implode( ' ', (array) $cs_znany['krytyk']->get_errors() );

cs_ok(
	true === ( $cs_tp['known_status'] ?? null ),
	'oba statusy sa w slowniku'
);
cs_ok(
	! $cs_znany['krytyk']->is_ok() && 'illegal_transition' === $cs_znany['krytyk']->get_code(),
	'nielegalne przejscie nadal ma kod illegal_transition',
	'kod=' . $cs_znany['krytyk']->get_code()
);
cs_ok(
	false !== mb_stripos( $cs_km, 'przejście' ),
	'i nadal opisuje PRZEJSCIE, bo statusy istnieja',
	'komunikat=' . $cs_km
);
cs_ok(
	'illegal_transition' === $cs_nieznany['krytyk']->get_code(),
	'kontr-asercja: status spoza slownika tez zostaje przy kodzie illegal_transition'
);

$cs_legalne = cs_para( MP_Sales_Workflow_DB::STATUS_NEW, MP_Sales_Workflow_DB::STATUS_ASSIGNED );

cs_ok(
	$cs_legalne['krytyk']->is_ok(),
	'kontr-asercja: legalne przejscie nadal przechodzi'
);
