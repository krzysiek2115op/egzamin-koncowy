<?php
/**
 * Motyw pokazowy mówił przeglądarce dwie nieprawdy.
 *
 * Uruchamianie: wp eval-file /demo/tests/demo-nie-klamie-przegladarce.php
 *
 * A. JĘZYK I KODOWANIE NA SZTYWNO. `header.php` zaczynał się od `<html lang="pl">`
 *    i `<meta charset="UTF-8">` wpisanych wprost. Na instalacji postawionej po
 *    angielsku strona ogłaszała czytnikom ekranu i wyszukiwarkom polski, a przy
 *    innym kodowaniu witryny nagłówek przeczyłby temu, co WordPress naprawdę
 *    wysyła. Motyw pokazowy jest dla egzaminatora przykładem, jak się pisze motyw
 *    — a `language_attributes()` i `bloginfo( 'charset' )` to sposób standardowy.
 *
 * B. ODNOŚNIKI DONIKĄD. Sekcja kontaktu miała trzy ikony społecznościowe opakowane
 *    w odnośniki prowadzące do samego krzyżyka. Kliknięcie nie robiło nic, ale
 *    czytnik ekranu zapowiadał je jako odnośniki i pozwalał na nie wejść tabulatorem
 *    — użytkownik klawiatury dostawał trzy przystanki bez celu. Firma „Kredyt
 *    Kompas" jest fikcyjna, więc nie ma dokąd tych odnośników skierować; zostały
 *    same ikony, oznaczone jako dekoracja.
 *
 * @package Kredyt_Kompas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_dk2'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegół przy porażce.
 * @return bool
 */
function dk2_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_dk2']['pass'];
		$GLOBALS['mp_dk2']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_dk2']['fail'];
	$GLOBALS['mp_dk2']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function dk2_koniec() {
	if ( empty( $GLOBALS['mp_dk2']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_dk2'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_dk2']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'dk2_koniec' );

$dk2_motyw = get_template_directory();

/* ==================================================================== A */

$GLOBALS['mp_dk2']['lines'][] = '=== A. jezyk i kodowanie pochodza z WordPressa ===';

$dk2_header = (string) file_get_contents( $dk2_motyw . '/header.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

if ( ! dk2_ok( '' !== $dk2_header, 'header.php sie czyta', $dk2_motyw ) ) {
	return;
}

dk2_ok(
	false === strpos( $dk2_header, '<html lang="' ),
	'nie ma jezyka wpisanego wprost w znaczniku html'
);
dk2_ok(
	false !== strpos( $dk2_header, 'language_attributes()' ),
	'jezyk bierze sie z language_attributes()'
);
dk2_ok(
	0 === preg_match( '~<meta\s+charset="[A-Za-z0-9-]+"~', $dk2_header ),
	'nie ma kodowania wpisanego wprost'
);
dk2_ok(
	false !== strpos( $dk2_header, "bloginfo( 'charset' )" ),
	'kodowanie bierze sie z bloginfo( charset )'
);

/* ==================================================================== B */

$GLOBALS['mp_dk2']['lines'][] = '';
$GLOBALS['mp_dk2']['lines'][] = '=== B. zaden fragment strony nie prowadzi donikad ===';

$dk2_pliki = glob( $dk2_motyw . '/parts/*.html' );

dk2_ok( is_array( $dk2_pliki ) && count( $dk2_pliki ) > 0, 'sa fragmenty tresci do sprawdzenia' );

$dk2_martwe = array();

foreach ( (array) $dk2_pliki as $dk2_plik ) {
	$dk2_tresc = (string) file_get_contents( $dk2_plik ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	// Odnosnik do samego krzyzyka albo pusty. Kotwica `#sekcja` jest w porzadku —
	// prowadzi do miejsca na stronie, wiec nie wchodzi do wzorca.
	if ( preg_match_all( '~<a[^>]*href="(#|)"~', $dk2_tresc, $dk2_trafienia ) ) {
		$dk2_martwe[] = basename( $dk2_plik ) . ' (' . count( $dk2_trafienia[0] ) . ')';
	}
}

dk2_ok(
	array() === $dk2_martwe,
	'zaden fragment nie ma odnosnika prowadzacego donikad',
	implode( ', ', $dk2_martwe )
);

/* ==================================================================== C */

$GLOBALS['mp_dk2']['lines'][] = '';
$GLOBALS['mp_dk2']['lines'][] = '=== C. kontr-asercje: strona nadal ma tresc i dziala ===';

$dk2_kontakt = (string) file_get_contents( $dk2_motyw . '/parts/kontakt.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

dk2_ok(
	3 === substr_count( $dk2_kontakt, 'social-ikona' ),
	'trzy ikony spolecznosciowe nadal sa na stronie',
	'znaleziono ' . substr_count( $dk2_kontakt, 'social-ikona' )
);
dk2_ok(
	false !== strpos( $dk2_kontakt, 'aria-hidden="true"' ),
	'i sa oznaczone jako dekoracja, wiec czytnik ekranu je pomija'
);
dk2_ok(
	false !== strpos( $dk2_kontakt, 'href="tel:' ) && false !== strpos( $dk2_kontakt, 'href="mailto:' ),
	'prawdziwe odnosniki (telefon, e-mail) zostaly nietkniete'
);

$dk2_style = (string) file_get_contents( $dk2_motyw . '/assets/styles.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

dk2_ok(
	false !== strpos( $dk2_style, '.social-ikona' ),
	'ikony maja swoj styl, wiec sekcja kontaktu nie rozjechala sie wizualnie'
);
dk2_ok(
	false !== strpos( $dk2_header, '<!DOCTYPE html>' ),
	'kontr-asercja: naglowek nadal zaczyna sie deklaracja typu dokumentu'
);
