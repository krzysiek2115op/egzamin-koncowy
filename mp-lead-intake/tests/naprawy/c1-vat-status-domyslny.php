<?php
/**
 * Grupa C po audycie 31.07.2026 — C1: domyslny `vat_status` w sygnale integracyjnym.
 *
 * Uruchamianie: wp eval-file tests/naprawy/c1-vat-status-domyslny.php
 *
 * Agent 11.3 budowal payload haka `mp_lead_created` z wartoscia zastepcza
 * `'checked'`. „Checked" znaczy „numer sprawdzony" — czyli brak danych byl
 * podawany dalej jako wynik weryfikacji, ktora sie nie odbyla.
 *
 * Dzis Dzial 7 ustawia `vat_status` przy kazdym przebiegu, wiec wartosc
 * zastepcza jest NIEOSIAGALNA sciezka pipeline'u — i wlasnie dlatego jest
 * grozna: nikt jej nie zobaczy, dopoki ktos nie doda galezi, ktora omija
 * Dzial 7. Wtedy wtyczka 2 dostanie „sprawdzone" bez sprawdzenia.
 * Wartosc `'unknown'` jest w slowniku (uzywa jej weryfikator po wyczerpaniu
 * prob) i mowi prawde: nie wiadomo.
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-C1  Domyslny vat_status w sygnale mp_lead_created brzmial 'checked'
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_c1'] = array(
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
function c1_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_c1']['pass'];
		$GLOBALS['mp_c1']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_c1']['fail'];
	$GLOBALS['mp_c1']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function c1_dump() {
	if ( empty( $GLOBALS['mp_c1']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_c1'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-p1-c1.txt' : '/tmp/mp-p1-c1.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_c1']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'c1_dump' );

/*
 * Odpinamy subskrybentow haka: sprawdzamy TRESC sygnalu, a nie to, co robia
 * z nim wtyczki 2 i 3. Bez tego test zakladalby szkic oferty i proces
 * sprzedazowy dla wymyslonego leada. Proces `wp eval-file` jest jednorazowy,
 * wiec odpiecie nie siega poza ten przebieg.
 */
remove_all_actions( 'mp_lead_created' );

$GLOBALS['mp_c1_zlapane'] = array();

add_action(
	'mp_lead_created',
	function ( $lead_id, $payload ) {
		$GLOBALS['mp_c1_zlapane'][] = array(
			'lead_id' => (int) $lead_id,
			'payload' => (array) $payload,
		);
	},
	10,
	2
);

/**
 * Uruchamia agenta 11.3 i zwraca zlapany payload haka.
 *
 * @param array $dane Zawartosc kontekstu.
 * @return array
 */
function c1_payload( array $dane ) {
	$GLOBALS['mp_c1_zlapane'] = array();

	$agent = new MP_D11_Agent_Report();
	$agent->run( new MP_Context( $dane ) );

	$ostatni = end( $GLOBALS['mp_c1_zlapane'] );

	return is_array( $ostatni ) ? (array) $ostatni['payload'] : array();
}

$baza = array(
	'lead_id'      => 990111,
	'company_name' => 'Firma Testowa C1',
	'nip'          => '1234567890',
	'email'        => 'c1@example.test',
	'country'      => 'PL',
	'status'       => 'new',
	'score'        => 42,
);

$GLOBALS['mp_c1']['lines'][] = '=== C1 — wartosc zastepcza vat_status ===';

// Kontekst BEZ `vat_status` — dokladnie ten przypadek, ktorego dotyczy poprawka.
$payload = c1_payload( $baza );

c1_ok( ! empty( $payload ), 'hak mp_lead_created zostal wystawiony' );
c1_ok(
	isset( $payload['vat_status'] ) && 'unknown' === (string) $payload['vat_status'],
	'brak danych o VAT -> "unknown", nie "checked"',
	isset( $payload['vat_status'] ) ? (string) $payload['vat_status'] : 'brak klucza'
);
c1_ok(
	isset( $payload['vat_status'] ) && 'checked' !== (string) $payload['vat_status'],
	'brak weryfikacji NIE jest podawany dalej jako weryfikacja udana'
);

// Wartosci ustalone przez Dzial 7 maja przechodzic bez zmian.
foreach ( array( 'valid', 'checked', 'pending', 'unknown' ) as $stan ) {
	$payload = c1_payload( array_merge( $baza, array( 'vat_status' => $stan ) ) );

	c1_ok(
		isset( $payload['vat_status'] ) && $stan === (string) $payload['vat_status'],
		'status "' . $stan . '" z Dzialu 7 przechodzi nietkniety',
		isset( $payload['vat_status'] ) ? (string) $payload['vat_status'] : 'brak klucza'
	);
}

// Bez identyfikatora leada haka nie ma w ogole — sygnal o niczym byloby gorszy
// niz jego brak.
$payload = c1_payload( array_merge( $baza, array( 'lead_id' => 0 ) ) );
c1_ok( empty( $payload ), 'bez lead_id hak NIE jest wystawiany' );

// „unknown" nalezy do slownika statusow, ktory zna weryfikator w tle.
$plik = dirname( __DIR__, 2 ) . '/includes/class-mp-vat-verifier.php';
c1_ok(
	is_readable( $plik ) && false !== strpos( (string) file_get_contents( $plik ), "'unknown'" ), // phpcs:ignore
	'"unknown" jest wartoscia ze slownika, a nie nowym wymyslonym stanem'
);
