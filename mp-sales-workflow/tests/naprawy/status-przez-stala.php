<?php
/**
 * Test: status zdarzenia zapisywany przez stala, nie przez napis.
 *
 * Ustalenie audytu [1.23]. Dzial 8 wpisywal do tabeli zdarzen
 * `'status' => 'done'` wprost. Audyt wskazal na istniejaca stala
 * `MP_SW_D6_Scheduler::STATUS_DONE` — i tu jest pulapka, bo to stala
 * z INNEGO slownika:
 *
 *   - `MP_SW_D6_Scheduler::STATUS_*` opisuje cykl zycia ZADANIA follow-up
 *     (tabela zadan, DEFAULT 'pending': pending → done / cancelled / undelivered);
 *   - kolumna `status` w tabeli ZDARZEN ma DEFAULT 'done' i mowi o tym,
 *     ze zdarzenie zostalo przetworzone.
 *
 * Te slowniki tylko przypadkiem dziela slowo „done". Podpiecie Dzialu 8 pod
 * stala Dzialu 6 zwiazaloby ze soba dwie niezalezne tabele: zmiana nazwy
 * statusu zadania po cichu przepisalaby wiersze zdarzen. Dlatego wlascicielem
 * tej wartosci jest klasa, ktora wlada schematem — `MP_Sales_Workflow_DB`.
 *
 * Uruchamianie: wp eval-file tests/naprawy/status-przez-stala.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-D1  Status wiersza zdarzenia wpisywany napisem 'done'
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mp_katalog          = dirname( __DIR__, 2 );
$GLOBALS['mp_oks']   = 0;
$GLOBALS['mp_fails'] = 0;

/**
 * Wypisuje wynik pojedynczej asercji i zlicza pass/fail.
 *
 * @param bool   $warunek Czy asercja przeszla.
 * @param string $opis    Opis asercji.
 * @return bool
 */
function mp_sw_status_ok( $warunek, $opis ) {
	if ( $warunek ) {
		++$GLOBALS['mp_oks'];
		echo "  OK   {$opis}\n";
	} else {
		++$GLOBALS['mp_fails'];
		echo "  FAIL {$opis}\n";
	}

	return (bool) $warunek;
}

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
			$mp_slownik[ $mp_trafienie[2] ][] = $mp_trafienie[1];
		}
	}
}

mp_sw_status_ok( count( $mp_zrodla ) > 10, 'zrodla wtyczki sa widoczne (plikow: ' . count( $mp_zrodla ) . ')' );
mp_sw_status_ok( isset( $mp_slownik['done'] ), 'slownik zna „done"' );
mp_sw_status_ok( isset( $mp_slownik['new'] ), 'slownik zna „new" (status procesu)' );

echo "\n== B. brak napisow tam, gdzie jest stala ==\n";

$mp_zle = array();

foreach ( $mp_zrodla as $mp_plik ) {
	$mp_linie = file( $mp_plik, FILE_IGNORE_NEW_LINES );

	foreach ( (array) $mp_linie as $mp_nr => $mp_linia ) {
		if ( ! preg_match( '/\'status\'\s*=>\s*\'([a-z0-9_]+)\'/', $mp_linia, $mp_m ) ) {
			continue;
		}

		if ( isset( $mp_slownik[ $mp_m[1] ] ) ) {
			$mp_zle[] = str_replace( $mp_katalog . '/', '', $mp_plik ) . ':' . ( $mp_nr + 1 )
				. " => '" . $mp_m[1] . "'";
		}
	}
}

foreach ( $mp_zle as $mp_opis ) {
	echo "       {$mp_opis}\n";
}

mp_sw_status_ok( empty( $mp_zle ), 'zaden zapis statusu nie uzywa napisu zamiast stalej (znaleziono: ' . count( $mp_zle ) . ')' );

echo "\n== C. dwa slowniki zostaja osobno ==\n";

$mp_ma_zdarzenia = defined( 'MP_Sales_Workflow_DB::EVENT_STATUS_DONE' );
$mp_ma_zadania   = defined( 'MP_SW_D6_Scheduler::STATUS_DONE' );

mp_sw_status_ok( $mp_ma_zdarzenia, 'tabela zdarzen ma wlasna stala MP_Sales_Workflow_DB::EVENT_STATUS_DONE' );
mp_sw_status_ok( $mp_ma_zadania, 'tabela zadan ma wlasna stala MP_SW_D6_Scheduler::STATUS_DONE' );

if ( $mp_ma_zdarzenia && $mp_ma_zadania ) {
	mp_sw_status_ok(
		MP_Sales_Workflow_DB::EVENT_STATUS_DONE === MP_SW_D6_Scheduler::STATUS_DONE,
		'obie maja dzis te sama wartosc — dlatego pomylka byla niewidoczna'
	);
}

// Dzial 8 pisze do OBU tabel. Sprawdzamy, ze siega po wlasciwa stala do wlasciwej.
$mp_d8 = (string) file_get_contents( $mp_katalog . '/includes/pipeline/departments/class-mp-sw-department-08.php' );

mp_sw_status_ok(
	false !== strpos( $mp_d8, 'MP_Sales_Workflow_DB::EVENT_STATUS_DONE' ),
	'Dzial 8: wiersz zdarzenia bierze stala ze slownika zdarzen'
);
mp_sw_status_ok(
	false !== strpos( $mp_d8, 'MP_SW_D6_Scheduler::STATUS_DONE' ),
	'Dzial 8: wynik zadania nadal bierze stala ze slownika zadan'
);

echo "\n== PODSUMOWANIE ==\n";
echo "OK: {$GLOBALS['mp_oks']}  FAIL: {$GLOBALS['mp_fails']}\n";
echo ( 0 === $GLOBALS['mp_fails'] ) ? "WYNIK: PASS\n" : "WYNIK: FAIL\n";
