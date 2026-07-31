<?php
/**
 * Test reguly 1.8 — rozroznienie NAZWY obiektu bazy od WARTOSCI.
 *
 * Uruchamianie: php audyt/tests/regula-1-8.php
 *
 * Powod istnienia: 31.07.2026 regula zglaszala 7 ustalen na kodzie, w ktorym
 * interpolowana byla wylacznie nazwa tabeli albo wiezu. Zadanie „uzyj
 * prepare()" bylo tam NIEWYKONALNE — `prepare()` podstawia wartosci, a symbol
 * w miejscu nazwy tabeli otoczylby ja cudzyslowem i zapytanie przestaloby
 * dzialac. Regula zostala zawezona, a ten test pilnuje, zeby przy okazji nie
 * stracila zebow: prawdziwe wstrzykniecie ma byc nadal zgloszone.
 *
 * @package MP_Audyt
 */

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/kontrakty.php';
require_once $korzen . '/includes/pomoc.php';
require_once $korzen . '/includes/class-mp-au-workspace.php';
require_once $korzen . '/includes/class-mp-au-model-client.php';
require_once $korzen . '/includes/class-mp-au-raport.php';
require_once $korzen . '/includes/departments/class-mp-au-department-01.php';

$pass = 0;
$fail = 0;

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @return void
 */
function au_ok( bool $warunek, string $opis ): void {
	global $pass, $fail;

	if ( $warunek ) {
		++$pass;
		echo '  [PASS] ' . $opis . "\n";
		return;
	}

	++$fail;
	echo '  [FAIL] ' . $opis . "\n";
}

/**
 * Wywoluje prywatna metode reguly.
 *
 * @param string $sql Zapytanie.
 * @return bool
 */
function au_tylko_identyfikatory( string $sql ): bool {
	$metoda = new ReflectionMethod( 'MP_AU_A18_Wzorce_SQL', 'tylko_identyfikatory' );
	$metoda->setAccessible( true );

	return (bool) $metoda->invoke( null, $sql );
}

echo "=== 1.8 — nazwa obiektu (prepare NIE ma zastosowania) ===\n";

$nazwy = array(
	'ALTER TABLE $table DROP INDEX uq_nip',
	'DROP TABLE IF EXISTS $table',
	'DROP TABLE IF EXISTS {$table}',
	'ALTER TABLE $offers ADD CONSTRAINT fk_offers_lead FOREIGN KEY (lead_id) REFERENCES $leads (id) ON DELETE RESTRICT',
	'ALTER TABLE {$child} ADD CONSTRAINT {$constraint} FOREIGN KEY (flow_id) REFERENCES {$flow} (id) ON DELETE CASCADE',
	'SELECT c.id FROM {$child} c LEFT JOIN {$flow_table} p ON c.flow_id = p.id WHERE p.id IS NULL LIMIT 1',
	'TRUNCATE TABLE {$tabela}',
);

foreach ( $nazwy as $sql ) {
	au_ok( au_tylko_identyfikatory( $sql ), 'nazwa obiektu: ' . substr( $sql, 0, 58 ) );
}

echo "\n=== 1.8 — WARTOSC w zapytaniu (regula ma nadal gryzc) ===\n";

$wartosci = array(
	'SELECT * FROM {$tabela} WHERE id = $id',
	'DELETE FROM {$tabela} WHERE email = \'$email\'',
	'SELECT * FROM {$tabela} WHERE nip LIKE \'%$szukaj%\'',
	'UPDATE {$tabela} SET status = \'$status\' WHERE id = 1',
	'SELECT * FROM {$tabela} ORDER BY $kolumna',
	'SELECT * FROM {$tabela} WHERE id IN ($lista)',
	'SELECT * FROM {$tabela} LIMIT $ile',
);

foreach ( $wartosci as $sql ) {
	au_ok( ! au_tylko_identyfikatory( $sql ), 'wartosc w zapytaniu: ' . substr( $sql, 0, 58 ) );
}

echo "\n=== 1.8 — przypadki mieszane ===\n";

// Nazwa tabeli ORAZ wartosc: decyduje wartosc, bo ta prepare() obsluzy.
au_ok(
	! au_tylko_identyfikatory( 'ALTER TABLE {$tabela} ADD COLUMN x INT DEFAULT $domyslna' ),
	'nazwa + wartosc -> traktowane jak wartosc'
);

// Zapytanie bez zmiennych w ogole nie jest przypadkiem tej reguly.
au_ok(
	! au_tylko_identyfikatory( 'SELECT 1' ),
	'zapytanie bez zmiennych nie przechodzi jako "sama nazwa"'
);

// `$wpdb->prefix` byl wylaczony juz wczesniej i tak zostaje.
au_ok(
	! au_tylko_identyfikatory( 'SELECT * FROM {$wpdb->prefix}posts WHERE id = $id' ),
	'$wpdb->prefix nie przykrywa wartosci w tym samym zapytaniu'
);

echo "\n----- PASS: {$pass} / FAIL: {$fail} -----\n";
echo 0 === $fail ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

exit( 0 === $fail ? 0 : 1 );
