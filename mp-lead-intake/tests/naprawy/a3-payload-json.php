<?php
/**
 * Grupa A po audycie 31.07.2026 — A3: wynik gotowy tylko wtedy, gdy da sie go zakodowac.
 *
 * Uruchamianie: wp eval-file tests/naprawy/a3-payload-json.php
 *
 * Agent 10.3 kodowal odpowiedz przez wp_json_encode() i NIE sprawdzal wyniku,
 * a `result_ready` bral wylacznie z `$r['success']`. Gdy kodowanie zawiodlo,
 * pipeline melodwal sukces, a `response_json` byl wartoscia `false` — czyli
 * odpowiedzia, ktorej nie da sie wyslac. Ta sama rodzina bledow co A1 i A2:
 * brak potwierdzenia to nie jest potwierdzenie.
 *
 * wp_json_encode() zwraca `false` m.in. przy wartosciach INF/NAN (WordPressowy
 * _wp_json_sanity_check() naprawia tylko kodowanie znakow) oraz przy strukturze
 * glebszej niz 512 poziomow.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_a3'] = array(
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
function a3_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_a3']['pass'];
		$GLOBALS['mp_a3']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_a3']['fail'];
	$GLOBALS['mp_a3']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function a3_dump() {
	if ( empty( $GLOBALS['mp_a3']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_a3'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-p1-a3.txt' : '/tmp/mp-p1-a3.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_a3']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'a3_dump' );

/**
 * Uruchamia agenta 10.3 na podanej odpowiedzi.
 *
 * @param array $response Odpowiedz zbudowana przez wczesniejsze agenty dzialu 10.
 * @return array Dane wyniku agenta.
 */
function a3_finalize( array $response ) {
	$context = new MP_Context( array( 'response' => $response ) );
	$agent   = new MP_D10_Agent_Finalize();

	return (array) $agent->run( $context )->get_data();
}

/**
 * Buduje tablice zagniezdzona ponad limit glebokosci JSON-a.
 *
 * @param int $depth Liczba poziomow.
 * @return array
 */
function a3_deep( $depth ) {
	$node = array( 'koniec' => true );

	for ( $i = 0; $i < $depth; $i++ ) {
		$node = array( 'poziom' => $node );
	}

	return $node;
}

$GLOBALS['mp_a3']['lines'][] = '=== A3 — finalizacja odpowiedzi (agent 10.3) ===';

// Przypadek normalny: odpowiedz kodowalna, sukces zadeklarowany.
$dane = a3_finalize(
	array(
		'success' => true,
		'lead_id' => 123,
		'status'  => 'new',
		'message' => 'Lead utworzony pomyślnie',
	)
);

a3_ok(
	! empty( $dane['result_ready'] ),
	'poprawna odpowiedz -> wynik gotowy'
);
a3_ok(
	is_string( $dane['response_json'] ) && false !== strpos( $dane['response_json'], '"lead_id":123' ),
	'poprawna odpowiedz -> JSON zawiera dane leada',
	is_string( $dane['response_json'] ) ? $dane['response_json'] : var_export( $dane['response_json'], true ) // phpcs:ignore
);

// Sukces zadeklarowany, ale odpowiedzi NIE DA SIE zakodowac (wartosc INF).
$dane = a3_finalize(
	array(
		'success' => true,
		'lead_id' => 124,
		'status'  => 'new',
		'score'   => INF,
	)
);

a3_ok(
	empty( $dane['result_ready'] ),
	'odpowiedz niekodowalna (INF) -> wynik NIE jest gotowy',
	var_export( $dane['result_ready'], true ) // phpcs:ignore
);
a3_ok(
	is_string( $dane['response_json'] ),
	'response_json zostaje LANCUCHEM, nigdy wartoscia false',
	var_export( $dane['response_json'], true ) // phpcs:ignore
);

// To samo dla struktury glebszej niz limit JSON-a.
$dane = a3_finalize(
	array(
		'success' => true,
		'lead_id' => 125,
		'status'  => 'new',
		'drzewo'  => a3_deep( 600 ),
	)
);

a3_ok(
	empty( $dane['result_ready'] ),
	'odpowiedz niekodowalna (przekroczona glebokosc) -> wynik NIE jest gotowy',
	var_export( $dane['result_ready'], true ) // phpcs:ignore
);

// Bramka jakosci dzialu 10 ma odrzucic taki przebieg, a nie przepuscic go dalej.
$context = new MP_Context(
	array(
		'response'     => array( 'success' => true ),
		'result_ready' => false,
		'lead_id'      => 126,
	)
);
$qa      = new MP_D10_QA_Agent();
$wynik   = $qa->run( $context );

a3_ok(
	! $wynik->is_ok(),
	'bramka QA10 odrzuca przebieg z niegotowym wynikiem'
);

// Brak sukcesu zostaje brakiem sukcesu takze wtedy, gdy kodowanie sie udalo.
$dane = a3_finalize(
	array(
		'success' => false,
		'lead_id' => 0,
		'status'  => 'error',
	)
);

a3_ok(
	empty( $dane['result_ready'] ),
	'brak sukcesu -> wynik niegotowy mimo poprawnego JSON-a'
);
