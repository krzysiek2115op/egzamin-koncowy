<?php
/**
 * Test reguly 1.16 — kiedy zapytanie w petli JEST narzutem liniowym.
 *
 * Uruchamianie: php audyt/tests/regula-1-16.php
 *
 * Regula zglaszala kazda petle z zapytaniem w srodku. Na tym projekcie dawalo
 * to ustalenia dla petli, ktorych liczba obiegow jest wpisana w kod (trzy
 * nazwy tabel przy deinstalacji, dwa jezyki szablonu) oraz dla zapisu wiersz
 * po wierszu w jednej transakcji, gdzie kontrola KAZDEGO zapisu jest celowa.
 * Zalecenie „pobierz komplet jednym zapytaniem" bylo tam niewykonalne albo
 * wprost szkodliwe.
 *
 * Ten test pilnuje obu stron: co ma zostac pominiete i co ma byc nadal
 * zgloszone.
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
require_once $korzen . '/includes/pary/dzial-01-dane.php';

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
 * @param string $nazwa Nazwa metody.
 * @param array  $args  Argumenty.
 * @return bool
 */
function au_wywolaj( string $nazwa, array $args ): bool {
	$metoda = new ReflectionMethod( 'MP_AU_A116_Wydajnosc', $nazwa );
	$metoda->setAccessible( true );

	return (bool) $metoda->invokeArgs( null, $args );
}

echo "=== 1.16 — liczba obiegow ustalona W KODZIE (pomijane) ===\n";

$plik_z_lista = '<?php class X {
	public static function tables() {
		return array( self::a_table(), self::b_table(), self::c_table() );
	}
	public static function foreign_keys() {
		return array( "fk_a" => "tabela_a", "fk_b" => "tabela_b" );
	}
	public static function z_bazy() {
		global $wpdb;
		return $wpdb->get_results( "SELECT id FROM t" );
	}
	public static function z_parametru( $lista ) {
		return array( $lista );
	}
}';

au_ok(
	au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( array( \'pl\', \'en\' ) as $lang ', $plik_z_lista ) ),
	'tablica wprost w naglowku petli'
);
au_ok(
	au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( array( self::a_table(), self::b_table() ) as $t ', $plik_z_lista ) ),
	'tablica nazw tabel wprost w naglowku'
);
au_ok(
	au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( self::tables() as $table ', $plik_z_lista ) ),
	'metoda z tego pliku zwracajaca liste wypisana wprost'
);
au_ok(
	au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( self::foreign_keys() as $constraint => $child ', $plik_z_lista ) ),
	'lista wiezow wypisana wprost'
);

$metoda_z_lista = '{ $tables = array( self::a_table(), self::b_table(), self::c_table() ); foreach ( $tables as $table ) { $wpdb->query( "DROP TABLE IF EXISTS $table" ); } }';
$metoda_z_danymi = '{ $rows = $wpdb->get_results( "SELECT id FROM t" ); foreach ( $rows as $row ) { $wpdb->update( $t, $d, $w ); } }';

au_ok(
	au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( $tables as $table ', $plik_z_lista, $metoda_z_lista ) ),
	'zmienna z lista tabel przypisana tuz obok'
);
au_ok(
	au_wywolaj( 'liczba_obiegow_z_kodu', array( 'for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ', $plik_z_lista, '' ) ),
	'`for` z granica ze stalej klasy (licznik prob)'
);
au_ok(
	au_wywolaj( 'liczba_obiegow_z_kodu', array( 'for ( $i = 0; $i < 3; $i++ ', $plik_z_lista, '' ) ),
	'`for` z granica liczbowa'
);

echo "\n=== 1.16 — liczba obiegow zalezna od DANYCH (nadal zglaszane) ===\n";

au_ok(
	! au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( $items as $item ', $plik_z_lista ) ),
	'petla po pozycjach oferty'
);
au_ok(
	! au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( $rows as $row ', $plik_z_lista ) ),
	'petla po wierszach z bazy'
);
au_ok(
	! au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( self::z_bazy() as $r ', $plik_z_lista ) ),
	'metoda czytajaca z bazy NIE jest lista ustalona w kodzie'
);
au_ok(
	! au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( self::z_parametru( $x ) as $r ', $plik_z_lista ) ),
	'lista zbudowana z parametru NIE jest ustalona w kodzie'
);
au_ok(
	! au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( self::nieznana() as $r ', $plik_z_lista ) ),
	'metoda spoza pliku nie daje podstaw do pominiecia'
);
au_ok(
	! au_wywolaj( 'liczba_obiegow_z_kodu', array( 'foreach ( $rows as $row ', $plik_z_lista, $metoda_z_danymi ) ),
	'zmienna wypelniona odczytem z bazy NIE jest lista z kodu'
);
au_ok(
	! au_wywolaj( 'liczba_obiegow_z_kodu', array( 'for ( $i = 0; $i < count( $items ); $i++ ', $plik_z_lista, '' ) ),
	'`for` z granica liczona z danych'
);

echo "\n=== 1.16 — petla samych ZAPISOW (pomijana: N+1 to problem odczytu) ===\n";

au_ok(
	au_wywolaj( 'tylko_zapisy', array( '{ $wpdb->insert( $t, $row ); }' ) ),
	'INSERT wiersza planu'
);
au_ok(
	au_wywolaj( 'tylko_zapisy', array( '{ $wpdb->update( $t, $d, $w ); }' ) ),
	'UPDATE wiersza po wierszu'
);
au_ok(
	au_wywolaj( 'tylko_zapisy', array( '{ $wpdb->update( $t, $d, $w ); $wpdb->insert( $t2, $row ); }' ) ),
	'kilka zapisow w jednym obiegu'
);

echo "\n=== 1.16 — jeden ODCZYT wystarczy, zeby ustalenie zostalo ===\n";

au_ok(
	! au_wywolaj( 'tylko_zapisy', array( '{ $wpdb->get_var( "SELECT 1" ); $wpdb->insert( $t, $row ); }' ) ),
	'odczyt obok zapisu'
);
au_ok(
	! au_wywolaj( 'tylko_zapisy', array( '{ wc_get_product( $id ); }' ) ),
	'odpytanie o produkt WooCommerce'
);
au_ok(
	! au_wywolaj( 'tylko_zapisy', array( '{ get_post_meta( $id, "k", true ); }' ) ),
	'odczyt metadanych wpisu'
);
au_ok(
	! au_wywolaj( 'tylko_zapisy', array( '{ $wpdb->get_results( "SELECT * FROM t WHERE id = 1" ); }' ) ),
	'odczyt kompletu wierszy'
);
au_ok(
	! au_wywolaj( 'tylko_zapisy', array( '{ $suma += $x; }' ) ),
	'petla bez zapytan w ogole nie jest przypadkiem tej reguly'
);

echo "\n----- PASS: {$pass} / FAIL: {$fail} -----\n";
echo 0 === $fail ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

exit( 0 === $fail ? 0 : 1 );
