<?php
/**
 * Test: status dokumentu zapisywany przez stala, nie przez napis.
 *
 * Ustalenie audytu [1.23]. Slownik statusow dokumentu oferty mieszka
 * w `MP_Offer_Builder_DB` (STATUS_DRAFT, STATUS_APPROVED), ale szesc miejsc
 * wpisywalo `'status' => 'draft'` wprost. Dopoki wartosci sa zgodne, nic sie
 * nie dzieje — problem wychodzi dopiero przy zmianie stalej: te szesc miejsc
 * zostaje przy starym napisie i tworzy rekordy w stanie, ktorego reszta kodu
 * (w tym wartownik `status = STATUS_DRAFT` w Dziale 10) juz nie rozpoznaje.
 *
 * Test jest statyczny — czyta zrodla, nie potrzebuje bazy. Sprawdza trzy rzeczy:
 *
 *   A. slownik stalych daje sie odczytac i zawiera „draft" (bez tego reszta
 *      testu przechodzilaby na pusto, cokolwiek by bylo w kodzie);
 *   B. zaden zapis `'status' => '...'` nie uzywa napisu, dla ktorego stala
 *      istnieje;
 *   C. test NIE siega tam, gdzie stalej nie ma — `'status' => 'publish'`
 *      (status wpisu WordPressa) i `'status' => 'active'` (szablon oferty)
 *      maja zostac nietkniete.
 *
 * Uruchamianie: wp eval-file tests/naprawy/status-przez-stala.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-D1  Status dokumentu wpisywany napisem 'draft' w szesciu miejscach
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mp_katalog = dirname( __DIR__, 2 );
$mp_oks     = 0;
$mp_fails   = 0;

/**
 * Wypisuje wynik pojedynczej asercji.
 *
 * @param bool   $warunek Czy asercja przeszla.
 * @param string $opis    Opis asercji.
 * @return bool
 */
$mp_sprawdz = function ( $warunek, $opis ) use ( &$mp_oks, &$mp_fails ) {
	if ( $warunek ) {
		++$mp_oks;
		echo "  OK   {$opis}\n";
	} else {
		++$mp_fails;
		echo "  FAIL {$opis}\n";
	}

	return (bool) $warunek;
};

/**
 * Zwraca liste plikow .php w katalogu (rekurencyjnie).
 *
 * @param string $katalog Katalog.
 * @return array
 */
$mp_pliki = function ( $katalog ) {
	$wynik = array();

	if ( ! is_dir( $katalog ) ) {
		return $wynik;
	}

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $katalog ) );

	foreach ( $iterator as $plik ) {
		if ( $plik->isFile() && 'php' === strtolower( $plik->getExtension() ) ) {
			$wynik[] = $plik->getPathname();
		}
	}

	sort( $wynik );

	return $wynik;
};

$mp_zrodla = $mp_pliki( $mp_katalog . '/includes' );

echo "== A. slownik stalych statusu ==\n";

$mp_slownik = array();

foreach ( $mp_zrodla as $mp_plik ) {
	$mp_tresc = (string) file_get_contents( $mp_plik );

	if ( preg_match_all( '/const\s+([A-Z0-9_]*STATUS[A-Z0-9_]*)\s*=\s*\'([a-z0-9_]+)\'/', $mp_tresc, $mp_m, PREG_SET_ORDER ) ) {
		foreach ( $mp_m as $mp_trafienie ) {
			$mp_slownik[ $mp_trafienie[2] ] = $mp_trafienie[1];
		}
	}
}

$mp_sprawdz( count( $mp_zrodla ) > 10, 'zrodla wtyczki sa widoczne (plikow: ' . count( $mp_zrodla ) . ')' );
$mp_sprawdz( isset( $mp_slownik['draft'] ), 'slownik zna „draft" (stala: ' . ( $mp_slownik['draft'] ?? '-' ) . ')' );
$mp_sprawdz( isset( $mp_slownik['approved'] ), 'slownik zna „approved"' );

echo "\n== B. brak napisow tam, gdzie jest stala ==\n";

$mp_napisy = array();

foreach ( $mp_zrodla as $mp_plik ) {
	$mp_linie = file( $mp_plik, FILE_IGNORE_NEW_LINES );

	foreach ( (array) $mp_linie as $mp_nr => $mp_linia ) {
		if ( ! preg_match( '/\'status\'\s*=>\s*\'([a-z0-9_]+)\'/', $mp_linia, $mp_m ) ) {
			continue;
		}

		$mp_napisy[] = array(
			'plik'    => str_replace( $mp_katalog . '/', '', $mp_plik ),
			'linia'   => $mp_nr + 1,
			'wartosc' => $mp_m[1],
		);
	}
}

$mp_zle = array();

foreach ( $mp_napisy as $mp_n ) {
	if ( isset( $mp_slownik[ $mp_n['wartosc'] ] ) ) {
		$mp_zle[] = $mp_n['plik'] . ':' . $mp_n['linia'] . " => '" . $mp_n['wartosc'] . "'";
	}
}

foreach ( $mp_zle as $mp_opis ) {
	echo "       {$mp_opis}\n";
}

$mp_sprawdz( empty( $mp_zle ), 'zaden zapis statusu nie uzywa napisu zamiast stalej (znaleziono: ' . count( $mp_zle ) . ')' );

echo "\n== C. test nie siega poza slownik ==\n";

$mp_poza = array();

foreach ( $mp_napisy as $mp_n ) {
	if ( ! isset( $mp_slownik[ $mp_n['wartosc'] ] ) ) {
		$mp_poza[] = $mp_n['wartosc'];
	}
}

$mp_sprawdz( in_array( 'publish', $mp_poza, true ), '„publish" (status wpisu WP) zostaje napisem — poza slownikiem wtyczki' );
$mp_sprawdz( in_array( 'active', $mp_poza, true ), '„active" (szablon oferty) zostaje napisem — nie ma dla niego stalej' );

echo "\n== PODSUMOWANIE ==\n";
echo "OK: {$mp_oks}  FAIL: {$mp_fails}\n";
echo ( 0 === $mp_fails ) ? "WYNIK: PASS\n" : "WYNIK: FAIL\n";
