<?php
/**
 * Zaden plik PHP z paczki instalacyjnej nie moze sie wykonac z przegladarki.
 *
 * Uruchamianie: wp eval-file tests/koncowe/paczka-bez-kodu-uruchamialnego.php
 *
 * Katalog wtyczki jest dostepny publicznie — mowi to wprost PRZECZYTAJ-MNIE.txt
 * w paczce dla klienta, uzasadniajac tym, dlaczego materialow NIE wolno tam
 * wgrywac. Ta sama paczka wnosila jednak do tego katalogu szesc plikow PHP bez
 * zabezpieczenia przed bezposrednim wywolaniem, w tym DWA HARNESSY DZIALAJACE
 * BEZ WORDPRESSA: `tests/process-harness/run-process.php` i `bench.php`.
 * Pliki zalezne od WordPressa przy wejsciu z przegladarki koncza sie bledem
 * krytycznym (a wiec i sladem sciezki na dysku); harness konczy sie GORZEJ —
 * po prostu sie WYKONUJE, bo do dzialania WordPressa nie potrzebuje.
 *
 * A. KAZDY plik .php w trzech wtyczkach ma straznika albo jest zaslepka
 *    „silence is golden" (`index.php` z samym komentarzem).
 *
 * B. Straznik musi rozroznic przegladarke od wiersza polecen. Sam
 *    `if ( ! defined( 'ABSPATH' ) ) exit;` w harnessie zabilby jego jedyny
 *    sposob uruchamiania — regresja i CI wolaja go zwyklym `php plik.php`.
 *    Sprawdzamy wiec, ze harnessy DALEJ startuja spoza WordPressa.
 *
 * C. KONTR-ASERCJA: skan czegos nie znalazl, bo poszedl w zly katalog, wyglada
 *    dokladnie tak samo jak skan, ktory nie znalazl nic zlego. Liczba zbadanych
 *    plikow musi byc sensowna.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_pz'] = array(
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
function pz_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_pz']['pass'];
		$GLOBALS['mp_pz']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_pz']['fail'];
	$GLOBALS['mp_pz']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function pz_koniec() {
	if ( empty( $GLOBALS['mp_pz']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_pz'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_pz']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'pz_koniec' );

/**
 * Czy plik broni sie przed wejsciem z przegladarki.
 *
 * Uznajemy trzy formy, bo pliki maja trzy rozne sposoby uruchamiania:
 *  - `defined( 'ABSPATH' )` / `WPINC` — plik ladowany przez WordPressa,
 *  - `WP_UNINSTALL_PLUGIN`            — uninstall.php,
 *  - `PHP_SAPI` / `php_sapi_name`     — plik wolany takze z wiersza polecen.
 *
 * Szukamy WZORCA straznika w calym pliku, nie golego slowa „ABSPATH" w jego
 * poczatku, i to z dwoch powodow. Pierwszy: `ABSPATH` wystepuje pospolicie takze
 * w tresci (`require_once ABSPATH . 'wp-admin/includes/upgrade.php'`), wiec samo
 * slowo nie dowodzi niczego. Drugi jest praktyczny — pierwsza wersja tej sondy
 * czytala 2000 BAJTOW poczatku pliku i zgloszila jako bezbronny
 * `class-mp-ob-department-02.php`, ktory straznika ma w linii 39: polskie znaki
 * w komentarzu zajmuja po dwa bajty, wiec okno liczone w bajtach konczylo sie
 * przed linia, ktorej szukalo. Sonda mierzyla dlugosc komentarza, nie obecnosc
 * zabezpieczenia.
 *
 * @param string $tresc Cala tresc pliku.
 * @return bool
 */
function pz_ma_straznika( $tresc ) {
	if ( preg_match( '~defined\s*\(\s*[\'"](ABSPATH|WPINC|WP_UNINSTALL_PLUGIN)[\'"]\s*\)~', $tresc ) ) {
		return true;
	}

	return false !== strpos( $tresc, 'PHP_SAPI' ) || false !== strpos( $tresc, 'php_sapi_name' );
}

/**
 * Czy plik jest zaslepka „silence is golden" (sam komentarz, zero kodu).
 *
 * @param string $tresc Cala tresc pliku.
 * @return bool
 */
function pz_zaslepka( $tresc ) {
	$bez_komentarzy = trim( preg_replace( '~//.*|/\*.*?\*/|#.*~s', '', $tresc ) );

	return '<?php' === $bez_komentarzy || '' === $bez_komentarzy;
}

/* ==================================================================== A */

$GLOBALS['mp_pz']['lines'][] = '=== A. kazdy plik PHP paczki broni sie przed przegladarka ===';

$wtyczki = array( 'mp-lead-intake', 'mp-offer-builder', 'mp-sales-workflow' );
$zbadane = 0;
$bezbronne = array();

foreach ( $wtyczki as $wtyczka ) {
	$katalog = WP_PLUGIN_DIR . '/' . $wtyczka;

	if ( ! is_dir( $katalog ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $katalog, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $plik ) {
		if ( ! $plik->isFile() || 'php' !== strtolower( $plik->getExtension() ) ) {
			continue;
		}

		$sciezka = $plik->getPathname();

		// Biblioteki zewnetrzne rzadza sie wlasnymi prawami — nie nasz kod.
		if ( false !== strpos( $sciezka, '/vendor/' ) ) {
			continue;
		}

		++$zbadane;

		$tresc = (string) file_get_contents( $sciezka );

		if ( pz_ma_straznika( $tresc ) || pz_zaslepka( $tresc ) ) {
			continue;
		}

		$bezbronne[] = str_replace( WP_PLUGIN_DIR . '/', '', $sciezka );
	}
}

pz_ok(
	empty( $bezbronne ),
	'zaden plik nie wykona sie po wejsciu wprost z przegladarki',
	count( $bezbronne ) . ' bez straznika: ' . implode( ', ', array_slice( $bezbronne, 0, 8 ) )
);

/* ==================================================================== B */

$GLOBALS['mp_pz']['lines'][] = '';
$GLOBALS['mp_pz']['lines'][] = '=== B. harnessy dalej startuja spoza WordPressa ===';

$harnessy = array(
	'mp-lead-intake/tests/process-harness/run-process.php',
	'mp-offer-builder/tests/process-harness/run-process.php',
);

foreach ( $harnessy as $harness ) {
	$sciezka = WP_PLUGIN_DIR . '/' . $harness;

	if ( ! file_exists( $sciezka ) ) {
		pz_ok( false, 'harness istnieje: ' . $harness );
		continue;
	}

	$tresc = (string) file_get_contents( $sciezka );

	/*
	 * Straznik warunkowany WYLACZNIE obecnoscia ABSPATH zabilby harness pod CLI:
	 * regresja i CI wolaja go zwyklym `php plik.php`, bez WordPressa. Musi wiec
	 * pytac takze o SAPI. Sprawdzamy to na tresci, a nie uruchomieniem — sam
	 * przebieg harnessu jest osobna pozycja regresji.
	 */
	$pyta_o_sapi = false !== strpos( $tresc, 'PHP_SAPI' ) || false !== strpos( $tresc, 'php_sapi_name' );
	$sam_abspath = ! $pyta_o_sapi && false !== strpos( $tresc, 'ABSPATH' );

	pz_ok(
		! $sam_abspath,
		'straznik w ' . $harness . ' rozroznia przegladarke od wiersza polecen',
		'plik pyta tylko o ABSPATH, wiec pod CLI wyszedlby natychmiast'
	);
}

/* ==================================================================== C */

$GLOBALS['mp_pz']['lines'][] = '';
$GLOBALS['mp_pz']['lines'][] = '=== C. KONTR-ASERCJA: skan naprawde czegos szukal ===';

pz_ok(
	$zbadane >= 100,
	'przeskanowano sensowna liczbe plikow PHP',
	'zbadane=' . $zbadane
);

pz_ok(
	is_dir( WP_PLUGIN_DIR . '/mp-lead-intake' )
		&& is_dir( WP_PLUGIN_DIR . '/mp-offer-builder' )
		&& is_dir( WP_PLUGIN_DIR . '/mp-sales-workflow' ),
	'wszystkie trzy katalogi wtyczek byly na miejscu'
);
