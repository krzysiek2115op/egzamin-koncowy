<?php
/**
 * Demo nie wysyla danych do innych firm bez zgody i nie klamie o tym w polityce.
 *
 * Uruchamianie: wp eval-file /demo/tests/demo-nie-lamie-wlasnej-polityki.php
 *
 * Strona pokazowa ma demonstrowac produkt, ktorego osia sprzedazowa jest RODO:
 * zgody w formularzu, anonimizacja, wzor polityki prywatnosci w materialach.
 * Sama strona osadzala tymczasem ramke Google Maps przy KAZDYM wejsciu na
 * „Kontakt" — a jej polityka prywatnosci deklarowala „wylacznie niezbedne pliki
 * cookies". Deklaracja byla nieprawdziwa na stronie, ktora ja glosila. Google
 * Fonts i zdjecia z Unsplash tez wychodzily na zewnatrz i tez nie byly nigdzie
 * wymienione.
 *
 * Mierzymy PLIKI ZRODLOWE MOTYWU, nie tresc z bazy. Zasiew (`kk_seed_content()`)
 * pomija strony, ktore juz istnieja, wiec baza w dlugo zyjacym srodowisku trzyma
 * tresc sprzed poprawki — a Playground i tak stawia demo za kazdym razem od zera
 * wlasnie z tych plikow.
 *
 * @package Kredyt_Kompas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_dp'] = array(
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
function dp_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_dp']['pass'];
		$GLOBALS['mp_dp']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_dp']['fail'];
	$GLOBALS['mp_dp']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function dp_koniec() {
	if ( empty( $GLOBALS['mp_dp']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_dp'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_dp']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'dp_koniec' );

$motyw = get_theme_root() . '/kredyt-kompas';

if ( ! is_dir( $motyw ) ) {
	dp_ok( false, 'motyw strony pokazowej jest na miejscu', $motyw );
	return;
}

/* ==================================================================== A */

$GLOBALS['mp_dp']['lines'][] = '=== A. nic nie idzie do Google bez kliknięcia ===';

$fragmenty = glob( $motyw . '/parts/*.html' );
$z_ramka    = array();

foreach ( (array) $fragmenty as $plik ) {
	$tresc = (string) file_get_contents( $plik );

	if ( preg_match( '~<iframe[^>]*(google\.com/maps|youtube|facebook)~i', $tresc ) ) {
		$z_ramka[] = basename( $plik );
	}
}

dp_ok(
	empty( $z_ramka ),
	'zadna podstrona nie osadza ramki obcego serwisu przy wczytaniu',
	implode( ', ', $z_ramka )
);

$kontakt = (string) file_get_contents( $motyw . '/parts/kontakt.html' );

dp_ok(
	false !== strpos( $kontakt, 'data-map-src' ) && false !== strpos( $kontakt, 'map-consent-btn' ),
	'strona Kontakt ma mapę wczytywaną dopiero po kliknięciu'
);

$skrypt = (string) file_get_contents( $motyw . '/assets/script.js' );

dp_ok(
	false !== strpos( $skrypt, 'map-consent-btn' ) && false !== strpos( $skrypt, 'data-map-src' ),
	'i skrypt, który po kliknięciu tę mapę naprawdę wstawia'
);

/* ==================================================================== B */

$GLOBALS['mp_dp']['lines'][] = '';
$GLOBALS['mp_dp']['lines'][] = '=== B. polityka prywatności mówi, co strona naprawdę robi ===';

$polityka = (string) file_get_contents( $motyw . '/parts/polityka-prywatnosci.html' );

foreach ( array( 'Google Fonts', 'Unsplash', 'Mapy Google' ) as $usluga ) {
	dp_ok(
		false !== strpos( $polityka, $usluga ),
		'polityka wymienia „' . $usluga . '"'
	);
}

dp_ok(
	false !== strpos( $polityka, 'adres IP' ),
	'i mówi wprost, że te firmy dostają adres IP odwiedzającego'
);

/*
 * Nie szukamy braku konkretnej frazy, tylko obecnosci ZASTRZEZENIA. Zdanie
 * „wylacznie niezbedne pliki cookies" jest prawdziwe o samej witrynie i moze
 * zostac — nieprawdziwe bylo dopiero jako opis calej strony, ktora dogrywa
 * mape Google. Asercja na brak frazy mierzylaby brzmienie, a nie tresc.
 */
preg_match( '~<h2>Pliki cookies</h2>.*?</li>~s', $polityka, $m_cookies );
$karta_cookies = isset( $m_cookies[0] ) ? $m_cookies[0] : '';

dp_ok(
	'' !== $karta_cookies
		&& ( false !== mb_stripos( $karta_cookies, 'podmiotów trzecich' )
			|| false !== mb_stripos( $karta_cookies, 'Google' ) ),
	'karta o cookies zastrzega pliki podmiotów trzecich, zamiast twierdzić, że ich nie ma',
	'karta=' . wp_strip_all_tags( $karta_cookies )
);

/* ==================================================================== C */

$GLOBALS['mp_dp']['lines'][] = '';
$GLOBALS['mp_dp']['lines'][] = '=== C. mapa pokazuje adres, który strona podaje ===';

$stopka = (string) file_get_contents( $motyw . '/footer.php' );
$ma_adres = preg_match( '~ul\. Przyk[^<]*13/3~u', $stopka );

dp_ok( 1 === $ma_adres, 'stopka podaje adres biura' );

dp_ok(
	false !== strpos( rawurldecode( $kontakt ), 'Przykładowa 13/3' ),
	'a mapa wskazuje TEN adres, a nie ogolne „Warszawa Centrum"',
	'w kontakt.html brak zdekodowanego adresu w data-map-src'
);

dp_ok(
	false === strpos( $kontakt, 'Warszawa%20Centrum' ),
	'stare, ogolne wskazanie znikneło'
);
