<?php
/**
 * U-12 — zasada „jeden plik na dzial" obowiazywala w jednej wtyczce z trzech.
 *
 * Uruchamianie: wp eval-file tests/naprawy/dokumentacja-jeden-plik.php
 *
 * Zasada jest klienta i wtyczka 3 wypisuje ja w naglowku KAZDEGO pliku
 * dokumentacji: „Jeden plik na dzial (zasada projektu)." Stosowala sie do niej
 * tylko ona. Wtyczka 1 trzymala w `docs/dzial-03/` piec osobnych plikow, wtyczka 2
 * po trzy w dwoch dzialach — razem 22 pliki tam, gdzie zasada przewiduje 10.
 *
 * SPROSTOWANIE DO WLASNEGO USTALENIA. Pierwsza wersja tego ustalenia zarzucala
 * wtyczce 3, ze deklaruje zasade i sama jej lamie. Bylo to nieprawda i wzielo sie
 * z bledu w liczeniu: `ls | wc -l` liczyl takze `index.php` — strazniki katalogow
 * WordPressa, nie dokumentacje. Wtyczka 3 ma dokladnie jeden plik `.md` na dzial
 * od poczatku. Zarzut byl wiec odwrotny do prawdy: to ona jedyna zasady
 * przestrzegala. Test liczy tylko `.md`, zeby ta pomylka nie wrocila.
 *
 * SCALENIE NIE GUBI ZRODEL. Kazdy scalony plik zachowal wlasny naglowek
 * ŹRÓDŁA OFICJALNE w calosci — Golden Rule #2 zada zrodla przy kazdym module,
 * a scalanie dokumentow bez zachowania ich deklaracji zamienialoby jedno
 * naruszenie na gorsze.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['djp'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $info    Kontekst przy porazce.
 * @return void
 */
function djp_ok( $warunek, $opis, $info = '' ) {
	if ( $warunek ) {
		++$GLOBALS['djp']['pass'];
		$GLOBALS['djp']['lines'][] = '[PASS] ' . $opis;
		return;
	}

	++$GLOBALS['djp']['fail'];
	$GLOBALS['djp']['lines'][] = '[FAIL] ' . $opis . ( '' !== $info ? ' -- ' . $info : '' );
}

$wtyczki = array( 'mp-lead-intake', 'mp-offer-builder', 'mp-sales-workflow' );

/* ==================================================================== A */
// Jeden plik .md na dzial — we wszystkich trzech wtyczkach.

foreach ( $wtyczki as $wtyczka ) {
	$dzialy = glob( WP_PLUGIN_DIR . '/' . $wtyczka . '/docs/dzial-*', GLOB_ONLYDIR );

	djp_ok( ! empty( $dzialy ), 'A0: ' . $wtyczka . ' ma katalogi dzialow w docs/' );

	$nadmiarowe = array();

	foreach ( (array) $dzialy as $dzial ) {
		// Liczymy WYLACZNIE .md. `index.php` to straznik katalogu WordPressa,
		// a nie dokumentacja — na tej pomylce oparte bylo pierwotne ustalenie.
		$md = glob( $dzial . '/*.md' );

		if ( count( (array) $md ) > 1 ) {
			$nadmiarowe[] = basename( $dzial ) . ' (' . count( $md ) . ')';
		}
	}

	djp_ok(
		empty( $nadmiarowe ),
		'A1: ' . $wtyczka . ' — jeden plik dokumentacji na dzial',
		'dzialy z wieloma plikami: ' . implode( ', ', $nadmiarowe )
	);
}

/* ==================================================================== B */
// KONTR-ASERCJA: scalenie nie moglo zgubic zadnego zrodla oficjalnego.

foreach ( $wtyczki as $wtyczka ) {
	$bez_zrodla = array();

	foreach ( (array) glob( WP_PLUGIN_DIR . '/' . $wtyczka . '/docs/dzial-*/*.md' ) as $plik ) {
		$tresc = (string) file_get_contents( $plik ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === stripos( $tresc, 'ŹRÓDŁ' ) ) {
			$bez_zrodla[] = basename( dirname( $plik ) ) . '/' . basename( $plik );
		}
	}

	djp_ok(
		empty( $bez_zrodla ),
		'B1: KONTR-ASERCJA — ' . $wtyczka . ': kazdy plik dzialu deklaruje zrodlo',
		'bez deklaracji: ' . implode( ', ', $bez_zrodla )
	);
}

/* ==================================================================== C */
// Odwolania z kodu prowadza do plikow, ktore ISTNIEJA.
//
// Golden Rule #2 zada zrodla oficjalnego przy kazdym module. Odwolanie do pliku,
// ktorego nie ma, spelnia te zasade na papierze i nie spelnia jej wcale — a przy
// scalaniu dokumentacji jest to pierwsza rzecz, ktora sie psuje.

$wiszace = array();

foreach ( $wtyczki as $wtyczka ) {
	$katalog = WP_PLUGIN_DIR . '/' . $wtyczka;
	$iter    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $katalog, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iter as $plik ) {
		$sciezka = $plik->getPathname();

		if ( ! preg_match( '/\.php$/', $sciezka ) || false !== strpos( $sciezka, '/vendor/' ) ) {
			continue;
		}

		preg_match_all( '#docs/dzial-[0-9]+/[a-z0-9._-]+\.md#', (string) file_get_contents( $sciezka ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		foreach ( array_unique( $m[0] ) as $ref ) {
			$jest = false;

			foreach ( $wtyczki as $gdzie ) {
				if ( is_file( WP_PLUGIN_DIR . '/' . $gdzie . '/' . $ref ) ) {
					$jest = true;
					break;
				}
			}

			if ( ! $jest ) {
				$wiszace[] = str_replace( WP_PLUGIN_DIR . '/', '', $sciezka ) . ' → ' . $ref;
			}
		}
	}
}

djp_ok(
	empty( $wiszace ),
	'C1: kazde odwolanie do dokumentacji z kodu prowadzi do istniejacego pliku',
	implode( ' | ', array_slice( array_unique( $wiszace ), 0, 5 ) )
);

echo implode( "\n", $GLOBALS['djp']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['djp']['pass'], $GLOBALS['djp']['fail'] );
echo ( 0 === $GLOBALS['djp']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
