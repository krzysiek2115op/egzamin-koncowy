<?php
/**
 * Test: para 2.2 potrafi potwierdzic miejsce, ktore jest KATALOGIEM.
 *
 * Uruchamianie: php audyt/tests/weryfikacja-widzi-katalogi.php
 *
 * `MP_AU_A22_Falszywe_Alarmy::pelna_sciezka()` sprawdzalo kandydatow przez
 * `is_file()`. Katalog nigdy nie jest plikiem, wiec kazde ustalenie wskazujace
 * KATALOG albo korzen wtyczki bylo z gory nie do potwierdzenia — para 2.2
 * odrzucala je werdyktem „plik-nie-istnieje", a raport pomija odrzucone.
 *
 * Trafialo to cale rodziny kontroli, ktore z natury mowia o katalogu:
 *   1.17 dokumentacja  — regula „jeden plik na dzial" bada `docs/dzial-NN/`,
 *   1.11 RODO          — zasieg liczony dla calej wtyczki,
 *   1.5  kod-kontra-DDL — miejsca wskazywane korzeniem wtyczki.
 *
 * Skutek byl gorszy niz utrata tych ustalen: raport wygladal CZYSCIEJ, niz
 * bylo. W przebiegu z 01.08.2026 z tego powodu odpadlo 20 z 48 zgloszen.
 *
 * Bezpiecznik na taki wlasnie wypadek w narzedziu JEST — podnosi alarm, gdy
 * para 2.2 nie odnajduje plikow. Ma prog `> 0.5` i nie odpalil sie przy 42%.
 * Straz, ktora nie zadziala przy 42% odrzuconych z jednego powodu, nie jest
 * straza; test pilnuje wiec samej przyczyny, a nie progu.
 *
 * @package MP_Audyt
 */

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/kontrakty.php';
require_once $korzen . '/includes/pomoc.php';
require_once $korzen . '/includes/class-mp-au-workspace.php';
require_once $korzen . '/includes/class-mp-au-model-client.php';
require_once $korzen . '/includes/pary/dzial-02-weryfikacja.php';

$pass = 0;
$fail = 0;

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $info    Kontekst przy porazce.
 * @return void
 */
function wk_ok( bool $warunek, string $opis, string $info = '' ): void {
	global $pass, $fail;

	if ( $warunek ) {
		++$pass;
		echo "  [PASS] {$opis}\n";
		return;
	}

	++$fail;
	echo "  [FAIL] {$opis}" . ( '' !== $info ? ' -- ' . $info : '' ) . "\n";
}

$worktree = dirname( $korzen );
$baza     = sys_get_temp_dir() . '/mp-audyt-katalogi-' . getmypid();

$ws = new MP_AU_Workspace( $worktree, $baza, 'refs/heads/main' );
$ws->wystaw();

$kontekst = new MP_AU_Kontekst( $ws, new MP_AU_Model_Client( $ws, sys_get_temp_dir(), 'bez-modelu' ) );

$agent   = new MP_AU_A22_Falszywe_Alarmy( '2.2', 'falszywe-alarmy' );
$metoda  = new ReflectionMethod( 'MP_AU_A22_Falszywe_Alarmy', 'pelna_sciezka' );
$metoda->setAccessible( true );

/**
 * Rozwiazuje sciezke wzgledna przez badana metode.
 *
 * @param string $wzgledna Sciezka z ustalenia.
 * @return string
 */
function wk_sciezka( string $wzgledna ): string {
	global $metoda, $agent, $kontekst;

	return (string) $metoda->invoke( $agent, $kontekst, $wzgledna );
}

echo "== A. katalogi sa miejscami tak samo dobrymi jak pliki ==\n";

foreach ( array(
	'mp-lead-intake/docs/dzial-01/',
	'mp-lead-intake/docs/dzial-03/',
	'mp-offer-builder/docs/dzial-02/',
) as $katalog ) {
	$znaleziony = wk_sciezka( $katalog );
	wk_ok(
		'' !== $znaleziony && is_dir( $znaleziony ),
		'katalog dzialu jest odnajdywany: ' . $katalog,
		'oddano=' . ( '' === $znaleziony ? 'PUSTE' : $znaleziony )
	);
}

foreach ( array( 'mp-lead-intake', 'mp-offer-builder', 'mp-sales-workflow' ) as $wtyczka ) {
	$znaleziony = wk_sciezka( $wtyczka );
	wk_ok(
		'' !== $znaleziony,
		'korzen wtyczki jest odnajdywany: ' . $wtyczka,
		'oddano=' . ( '' === $znaleziony ? 'PUSTE' : $znaleziony )
	);
}

echo "\n== B. kontr-asercje: rozluznienie nie zamienia sie w „wszystko istnieje\" ==\n";

/*
 * Bez tych dwoch asercji „naprawa" mogloby znaczyc: przestan cokolwiek
 * odrzucac. Wtedy para 2.2 straciłaby caly sens, a falszywe alarmy wrocilyby
 * do raportu — dokladnie to, przed czym ta para broni.
 */
$plik = wk_sciezka( 'mp-lead-intake/includes/db/class-mp-db.php' );
wk_ok(
	'' !== $plik && is_file( $plik ),
	'zwykly plik nadal jest odnajdywany jako plik',
	'oddano=' . ( '' === $plik ? 'PUSTE' : $plik )
);

wk_ok(
	'' === wk_sciezka( 'mp-lead-intake/includes/nie-ma-takiego-pliku-xyz.php' ),
	'nieistniejacy plik NADAL nie jest odnajdywany'
);
wk_ok(
	'' === wk_sciezka( 'mp-lead-intake/docs/nie-ma-takiego-katalogu-xyz/' ),
	'nieistniejacy katalog NADAL nie jest odnajdywany'
);

$ws->sprzataj();

echo "\n== PODSUMOWANIE ==\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
echo ( 0 === $fail ) ? "WYNIK: PASS\n" : "WYNIK: FAIL\n";

exit( 0 === $fail ? 0 : 1 );
