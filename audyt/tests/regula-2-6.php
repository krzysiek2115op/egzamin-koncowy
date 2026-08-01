<?php
/**
 * Test reguly 2.6 — w ktorym commicie szukac sladu po weryfikacji naprawy.
 *
 * Uruchamianie: php audyt/tests/regula-2-6.php
 *
 * Para 2.6 pyta, czy test przyszedl razem z naprawa. Pierwsza wersja pytala
 * o commit, ktory plik testu UTWORZYL — i przez to kazdy drugi blad pilnowany
 * przez ten sam plik testu byl z gory skazany na ustalenie. Testy tego projektu
 * sa grupowane tematycznie (`zatwierdzenie-oferty.php` pilnuje P2-K1 i P2-K2),
 * wiec sytuacja nie byla wyjatkiem, tylko regula.
 *
 * Ten test pilnuje obu stron:
 *   A. commit rozszerzajacy istniejacy plik testu liczy sie jak commit tworzacy;
 *   B. brak jakiegokolwiek wspolnego commita nadal daje „test-osobno";
 *   C. na zywym repozytorium P2-K2 ma dzis slad, ktorego reguła wczesniej
 *      nie widziala — i to na commicie 8f650d3, a nie 7478b20.
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
require_once $korzen . '/includes/departments/class-mp-au-department-02.php';
require_once $korzen . '/includes/pary/dzial-01-dane.php';
require_once $korzen . '/includes/pary/dzial-02-weryfikacja.php';

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
 * Skrot: historia pliku testu w formacie oczekiwanym przez regule.
 *
 * @param array $pary Lista array( sha, array pliki ).
 * @return array
 */
function au_historia( array $pary ): array {
	$wynik = array();

	foreach ( $pary as $para ) {
		$wynik[] = array(
			'commit' => $para[0],
			'pliki'  => $para[1],
		);
	}

	return $wynik;
}

$test_php = 'tests/koncowe/zatwierdzenie-oferty.php';
$zrodlo   = 'includes/pipeline/departments/class-mp-ob-department-10.php';

echo "== A. commit rozszerzajacy test liczy sie tak samo jak tworzacy ==\n";

// Uklad z tego projektu: plik testu powstal przy naprawie P2-K1 (bez zrodla
// P2-K2), a sekcje dla P2-K2 dopisano pozniej — razem z poprawka Dzialu 10.
$historia_p2k2 = au_historia(
	array(
		array( '8f650d399a10bc55ba6cb46a1bbaf69122bfdca5', array( $test_php, $zrodlo, 'includes/x.php' ) ),
		array( 'b3845b6000000000000000000000000000000000', array( $test_php ) ),
		array( '7478b20000000000000000000000000000000000', array( $test_php, 'includes/class-mp-offer-builder-approval.php' ) ),
	)
);

$wynik = MP_AU_A26_Dowod_Naprawy::dopasuj_commit( $historia_p2k2, $zrodlo );

au_ok( 'test-z-naprawa' === $wynik['werdykt'], 'commit dopisujacy sekcje testu liczy sie jako slad naprawy' );
au_ok( '8f650d3' === $wynik['commit'], 'wskazany commit to ten z naprawa (8f650d3), a nie ten tworzacy plik' );
au_ok( false !== strpos( $wynik['dowod'], 'w tym poprawiane zrodlo' ), 'dowod nazywa to, co znaleziono' );

// Stary uklad musi dzialac dalej: naprawa i test w commicie tworzacym plik.
$wynik = MP_AU_A26_Dowod_Naprawy::dopasuj_commit(
	au_historia(
		array(
			array( '7478b20000000000000000000000000000000000', array( $test_php, 'includes/class-mp-offer-builder-approval.php' ) ),
		)
	),
	'includes/class-mp-offer-builder-approval.php'
);

au_ok( 'test-z-naprawa' === $wynik['werdykt'], 'jeden commit z testem i naprawa nadal przechodzi' );

echo "\n== B. brak wspolnego commita nadal jest ustaleniem ==\n";

$wynik = MP_AU_A26_Dowod_Naprawy::dopasuj_commit(
	au_historia(
		array(
			array( 'aaaaaaa0000000000000000000000000000000000', array( $test_php ) ),
			array( 'bbbbbbb0000000000000000000000000000000000', array( $test_php, 'README.md' ) ),
		)
	),
	$zrodlo
);

au_ok( 'test-osobno' === $wynik['werdykt'], 'test, ktory nigdy nie szedl razem ze zrodlem, zostaje zgloszony' );
au_ok( false !== strpos( $wynik['dowod'], 'zaden z 2 commitow' ), 'dowod mowi, ile commitow przejrzano' );
au_ok( 'aaaaaaa' === $wynik['commit'], 'przy braku trafienia raport pokazuje najnowszy commit testu' );

// Zrodlo o pustej sciezce nie moze pasowac do wszystkiego.
$wynik = MP_AU_A26_Dowod_Naprawy::dopasuj_commit(
	au_historia( array( array( 'ccccccc0000000000000000000000000000000000', array( $test_php ) ) ) ),
	''
);

au_ok( 'test-osobno' === $wynik['werdykt'], 'pusta sciezka zrodla nie jest traktowana jak trafienie' );

$wynik = MP_AU_A26_Dowod_Naprawy::dopasuj_commit( array(), $zrodlo );

au_ok( 'brak-historii' === $wynik['werdykt'], 'pusta historia to osobny werdykt, nie ciche przejscie' );

echo "\n== C. zywe repozytorium: P2-K2 ==\n";

$repo    = getenv( 'MP_AU_REPO' ) ?: dirname( $korzen, 2 );
$katalog = getenv( 'MP_AU_P2' ) ?: '';

if ( '' === $katalog || ! is_dir( $katalog ) ) {
	echo "  [POMINIETO] brak katalogu wtyczki 2 (ustaw MP_AU_P2=/sciezka/do/mp-offer-builder)\n";
} else {
	$log = array();
	exec( 'git -C ' . escapeshellarg( $katalog ) . ' log --format=%H -- ' . escapeshellarg( $test_php ), $log );

	$historia = array();

	foreach ( $log as $sha ) {
		$pliki = array();
		exec( 'git -C ' . escapeshellarg( $katalog ) . ' show --name-only --format= ' . escapeshellarg( $sha ), $pliki );
		$historia[] = array(
			'commit' => trim( $sha ),
			'pliki'  => array_values( array_filter( array_map( 'trim', $pliki ) ) ),
		);
	}

	au_ok( count( $historia ) > 1, 'plik testu ma w historii wiecej niz jeden commit (' . count( $historia ) . ')' );

	$wynik = MP_AU_A26_Dowod_Naprawy::dopasuj_commit( $historia, $zrodlo );

	au_ok( 'test-z-naprawa' === $wynik['werdykt'], 'P2-K2 ma na zywym repozytorium slad naprawy: ' . $wynik['dowod'] );

	$pierwszy = end( $historia );
	$tworzacy = MP_AU_A26_Dowod_Naprawy::dopasuj_commit( array( $pierwszy ), $zrodlo );

	au_ok(
		'test-osobno' === $tworzacy['werdykt'],
		'sam commit tworzacy plik faktycznie nie ma zrodla — stara wersja reguly musiala zglaszac'
	);
}

echo "\n== E. rejestr zapisany dwoma konwencjami — obie musza byc czytelne ==\n";

/*
 * Rejestr ma dwa pola, ktore wypelniane sa niejednolicie:
 *
 *   `plik`  — 82 wpisy bez numeru linii, 2 z numerem (`...php:497`),
 *   `test`  — 80 wpisow wzgledem katalogu wtyczki, 4 z przedrostkiem wtyczki.
 *
 * Para 2.6 czytala tylko wieksza polowe. Numer linii nie wystepuje na liscie
 * plikow commita, wiec wpis z `:497` NIE MIAL SZANS trafic — sprawdzenie bylo
 * niewykonalne, a wynik zawsze brzmial „test-osobno". To gorsze niz brak
 * sprawdzenia: wyglada jak ustalenie o produkcie, a jest ustaleniem o formacie
 * zapisu. Przedrostek wtyczki w `test` dawal to samo z drugiej strony —
 * `git log` uruchamiany w katalogu wtyczki nie widzi sciezki zaczynajacej sie
 * od jej nazwy, wiec historia wychodzila pusta.
 */
$wynik = MP_AU_A26_Dowod_Naprawy::dopasuj_commit( $historia_p2k2, $zrodlo . ':497' );

au_ok(
	'test-z-naprawa' === $wynik['werdykt'],
	'zrodlo z numerem linii trafia w ten sam commit co bez numeru'
);

$sciezka = MP_AU_A26_Dowod_Naprawy::sciezka_testu( 'mp-sales-workflow', 'mp-sales-workflow/tests/naprawy/x.php' );

au_ok(
	'tests/naprawy/x.php' === $sciezka,
	'sciezka testu z przedrostkiem wtyczki sprowadza sie do sciezki wzgledem wtyczki'
);

$sciezka = MP_AU_A26_Dowod_Naprawy::sciezka_testu( 'mp-sales-workflow', 'tests/naprawy/x.php' );

au_ok(
	'tests/naprawy/x.php' === $sciezka,
	'KONTR-ASERCJA: sciezka juz wzgledna zostaje nietknieta'
);

$wynik = MP_AU_A26_Dowod_Naprawy::dopasuj_commit( $historia_p2k2, 'includes/class-mp-czegos-takiego-nie-ma.php:1' );

au_ok(
	'test-osobno' === $wynik['werdykt'],
	'KONTR-ASERCJA: obciecie numeru linii nie zamienia sie w dopasowywanie czegokolwiek'
);

echo "\n== PODSUMOWANIE ==\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
echo ( 0 === $fail ) ? "WYNIK: PASS\n" : "WYNIK: FAIL\n";

exit( 0 === $fail ? 0 : 1 );
