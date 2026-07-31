<?php
/**
 * P3-G4 — odmowy 4xx wychodzily jako MP3-E500 „blad wewnetrzny".
 *
 * Uruchamianie: wp eval-file tests/naprawy/kody-odmowy.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-G4  Kody odmowy poza slownikiem: illegal_transition, duplicate_event
 *
 * Slownik `MP_SW_Errors::map()` tlumaczy nazwe reguly krytyka na kod publiczny.
 * Kod spoza slownika dostaje MP3-E500 i to jest ZAMIERZONE — nazwa wewnetrznej
 * reguly nie ma prawa wyciec do odpowiedzi HTTP, a inwarianty bramek (np.
 * `multiple_writes`, `journal_incomplete`) sa naprawde bledami wewnetrznymi.
 *
 * Zamierzone przestaje byc wtedy, gdy odmowa niesie WLASNY `http_status` z
 * rodziny 4xx. Taka odmowa jest odpowiedzia DLA WYWOLUJACEGO: „nie wolno",
 * „nie ma", „juz bylo". Wywolujacy dostawal wtedy sprzecznosc — HTTP 409 razem
 * z kodem MP3-E500 i komunikatem „Operacja nie powiodla sie". Zwykla, poprawna
 * odmowa wygladala jak awaria serwera: pracownik zglaszal blad wtyczki, a panel
 * nie mial jak pokazac wlasciwego komunikatu, bo rozpoznaje kod, nie tekst.
 *
 * Dwa takie kody istnialy naprawde:
 *   - `illegal_transition` (Dzial 5) — stala E_TRANSITION (MP3-E160) czekala
 *     w slowniku pod nazwa `invalid_transition`, ktorej nikt nie emitowal.
 *     Zmiana nazwy reguly przeszla po jednej stronie.
 *   - `duplicate_event` (Dzial 8) — powtorka tego samego `event_id`, czyli
 *     dzialajaca idempotencja, meldowala sie jako blad wewnetrzny.
 *
 * Test nie wymienia tych dwoch z nazwy jako jedynego sprawdzenia. Skanuje CALY
 * katalog `includes/` i zada, zeby regula obowiazywala wszedzie — nastepny kod
 * 4xx dodany bez wpisu w slowniku zatrzyma sie tutaj.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_ko'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegol.
 * @return bool
 */
function ko_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_ko']['pass'];
		$GLOBALS['mp_ko']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_ko']['fail'];
	$GLOBALS['mp_ko']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wszystkie wywolania MP_SW_Result::fail() z wlasnym `http_status` 4xx.
 *
 * Czytamy kod TOKENIZEREM PHP, nie wyrazeniem regularnym. Powod jest prosty:
 * wywolania sa wieloliniowe, z zagniezdzonymi tablicami i komentarzami w
 * srodku, a wzorzec tekstowy albo je gubi, albo lapie za duzo. Test, ktory
 * niczego nie znajduje, wyglada tak samo jak test, ktory przechodzi.
 *
 * @param string $katalog Katalog z kodem wtyczki.
 * @return array Lista: array( kod, http_status, plik:linia ).
 */
function ko_odmowy_4xx( $katalog ) {
	$znalezione = array();
	$pliki      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $katalog ) );

	foreach ( $pliki as $plik ) {
		if ( $plik->isDir() || 'php' !== $plik->getExtension() ) {
			continue;
		}

		$tokeny = token_get_all( (string) file_get_contents( $plik->getPathname() ) );
		$ile    = count( $tokeny );

		for ( $i = 0; $i < $ile; $i++ ) {
			if ( ! ko_token_jest( $tokeny, $i, T_STRING, 'fail' ) ) {
				continue;
			}

			if ( ! ko_token_jest( $tokeny, $i - 1, T_DOUBLE_COLON, '::' ) || ! ko_token_jest( $tokeny, $i - 2, T_STRING, 'MP_SW_Result' ) ) {
				continue;
			}

			$wywolanie = ko_argumenty( $tokeny, $i );

			if ( '' === $wywolanie['kod'] || 0 === $wywolanie['http'] ) {
				continue;
			}

			if ( $wywolanie['http'] < 400 || $wywolanie['http'] > 499 ) {
				continue;
			}

			$znalezione[] = array(
				$wywolanie['kod'],
				$wywolanie['http'],
				basename( $plik->getPathname() ) . ':' . (int) $tokeny[ $i ][2],
			);
		}
	}

	return $znalezione;
}

/**
 * Czy token o podanym indeksie jest tym, czego szukamy.
 *
 * @param array  $tokeny Tokeny.
 * @param int    $i      Indeks.
 * @param int    $typ    Typ tokenu.
 * @param string $tekst  Tresc tokenu.
 * @return bool
 */
function ko_token_jest( array $tokeny, $i, $typ, $tekst ) {
	return isset( $tokeny[ $i ] ) && is_array( $tokeny[ $i ] ) && $typ === $tokeny[ $i ][0] && $tekst === $tokeny[ $i ][1];
}

/**
 * Trzeci argument wywolania (kod bledu) oraz `http_status` z drugiego.
 *
 * @param array $tokeny Tokeny pliku.
 * @param int   $start  Indeks tokenu `fail`.
 * @return array array( 'kod' => string, 'http' => int )
 */
function ko_argumenty( array $tokeny, $start ) {
	$i = $start;

	while ( isset( $tokeny[ $i ] ) && '(' !== $tokeny[ $i ] ) {
		++$i;
	}

	$glebokosc = 0;
	$argument  = 0;
	$ostatni   = '';
	$kod       = '';
	$http      = 0;
	$plaskie   = array();

	for ( ; isset( $tokeny[ $i ] ); $i++ ) {
		$token = $tokeny[ $i ];

		if ( '(' === $token || '[' === $token ) {
			++$glebokosc;

			if ( 1 === $glebokosc ) {
				continue;
			}
		} elseif ( ')' === $token || ']' === $token ) {
			--$glebokosc;

			if ( 0 === $glebokosc ) {
				if ( 2 === $argument ) {
					$kod = $ostatni;
				}
				break;
			}
		} elseif ( ',' === $token && 1 === $glebokosc ) {
			if ( 2 === $argument ) {
				$kod = $ostatni;
			}
			++$argument;
			$ostatni = '';
			continue;
		}

		if ( ! is_array( $token ) ) {
			continue;
		}

		if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$plaskie[] = $token;

		if ( 1 === $glebokosc && 2 === $argument ) {
			$ostatni = T_CONSTANT_ENCAPSED_STRING === $token[0] ? trim( $token[1], "'\"" ) : '';
		}
	}

	// `http_status` siedzi w zagniezdzonej tablicy danych, wiec szukamy go
	// w splaszczonej liscie tokenow calego wywolania.
	$ilu = count( $plaskie );

	for ( $j = 0; $j + 2 < $ilu; $j++ ) {
		if ( T_CONSTANT_ENCAPSED_STRING !== $plaskie[ $j ][0] || 'http_status' !== trim( $plaskie[ $j ][1], "'\"" ) ) {
			continue;
		}

		if ( T_DOUBLE_ARROW === $plaskie[ $j + 1 ][0] && T_LNUMBER === $plaskie[ $j + 2 ][0] ) {
			$http = (int) $plaskie[ $j + 2 ][1];
			break;
		}
	}

	return array(
		'kod'  => (string) $kod,
		'http' => (int) $http,
	);
}

$katalog = dirname( dirname( __DIR__ ) ) . '/mp-sales-workflow/includes';
$katalog = is_dir( $katalog ) ? $katalog : dirname( __DIR__ ) . '/../includes';

$GLOBALS['mp_ko']['lines'][] = '=== A. kazda odmowa 4xx ma kod w slowniku ===';

$odmowy  = ko_odmowy_4xx( $katalog );
$slownik = MP_SW_Errors::map();
$luki    = array();

foreach ( $odmowy as $odmowa ) {
	if ( ! isset( $slownik[ $odmowa[0] ] ) ) {
		$luki[] = $odmowa[0] . ' (' . $odmowa[1] . ', ' . $odmowa[2] . ')';
	}
}

ko_ok(
	count( $odmowy ) >= 10,
	'skan znalazl odmowy 4xx w kodzie zrodlowym',
	'znaleziono=' . count( $odmowy ) . ' w ' . $katalog
);
ko_ok(
	array() === $luki,
	'zadna odmowa 4xx nie wychodzi jako MP3-E500',
	'poza slownikiem: ' . implode( '; ', $luki )
);

$GLOBALS['mp_ko']['lines'][] = '';
$GLOBALS['mp_ko']['lines'][] = '=== B. dwa kody, ktore luke tworzyly ===';

ko_ok(
	MP_SW_Errors::E_TRANSITION === MP_SW_Errors::code( 'illegal_transition' ),
	'illegal_transition → MP3-E160 (niedozwolone przejscie)',
	'jest=' . MP_SW_Errors::code( 'illegal_transition' )
);
ko_ok(
	MP_SW_Errors::E_INTERNAL !== MP_SW_Errors::code( 'duplicate_event' ),
	'duplicate_event nie jest bledem wewnetrznym',
	'jest=' . MP_SW_Errors::code( 'duplicate_event' )
);
ko_ok(
	MP_SW_Errors::message( MP_SW_Errors::code( 'duplicate_event' ) ) !== MP_SW_Errors::message( MP_SW_Errors::E_INTERNAL ),
	'powtorka zdarzenia ma wlasny komunikat, nie „Operacja nie powiodla sie"',
	'komunikat=' . MP_SW_Errors::message( MP_SW_Errors::code( 'duplicate_event' ) )
);

$GLOBALS['mp_ko']['lines'][] = '';
$GLOBALS['mp_ko']['lines'][] = '=== C. KONTR-ASERCJE: slownik nie moze stac sie przepustka ===';

/*
 * Bez tej czesci „naprawa" moglaby polegac na wpisaniu do slownika wszystkich
 * 86 kodow albo na oddawaniu nazwy reguly wprost. Sekcja A przeszlaby, a
 * wtyczka zaczelaby wypuszczac na zewnatrz nazwy wlasnych regul i pokazywac
 * awarie wewnetrzna jako zwykla odmowe.
 */
ko_ok(
	MP_SW_Errors::E_INTERNAL === MP_SW_Errors::code( 'kod_ktorego_nie_ma_w_slowniku' ),
	'nieznany kod nadal daje MP3-E500',
	'jest=' . MP_SW_Errors::code( 'kod_ktorego_nie_ma_w_slowniku' )
);
ko_ok(
	MP_SW_Errors::E_INTERNAL === MP_SW_Errors::code( 'multiple_writes' ),
	'inwariant bramki (multiple_writes) zostaje bledem wewnetrznym',
	'jest=' . MP_SW_Errors::code( 'multiple_writes' )
);
ko_ok(
	MP_SW_Errors::E_INTERNAL === MP_SW_Errors::code( 'journal_incomplete' ),
	'inwariant dziennika (journal_incomplete) zostaje bledem wewnetrznym',
	'jest=' . MP_SW_Errors::code( 'journal_incomplete' )
);

$puste = array();

foreach ( MP_SW_Errors::map() as $wewnetrzny => $publiczny ) {
	if ( $wewnetrzny === $publiczny || '' === trim( (string) MP_SW_Errors::message( $publiczny ) ) ) {
		$puste[] = $wewnetrzny;
	}
}

ko_ok(
	array() === $puste,
	'kazdy wpis slownika ma kod publiczny i komunikat',
	'bez komunikatu: ' . implode( ', ', $puste )
);

echo implode( "\n", $GLOBALS['mp_ko']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_ko']['pass'], $GLOBALS['mp_ko']['fail'] );
echo ( 0 === $GLOBALS['mp_ko']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
