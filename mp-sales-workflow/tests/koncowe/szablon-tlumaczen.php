<?php
/**
 * Kazda wtyczka dostarcza szablon tlumaczen, ktorego szuka WordPress.
 *
 * Uruchamianie: wp eval-file tests/koncowe/szablon-tlumaczen.php
 *
 * Wszystkie trzy wtyczki wolaja `load_plugin_textdomain( …, '/languages' )`
 * i maja razem blisko 400 ciagow w `__()`/`esc_html__()`. Katalogu `languages/`
 * nie bylo w zadnej, pliku `.pot` nie bylo w zadnej, a naglowka `Domain Path`
 * nie deklarowala zadna. WordPress szukal katalogu, ktorego nie dostarczono,
 * a tlumacz nie mial z czym usiasc — mimo ze wtyczki byly „przygotowane do
 * tlumaczenia" w kazdej linii kodu. Przygotowanie konczylo sie na wywolaniach.
 *
 * A. NAGLOWEK. `Domain Path` musi byc i musi wskazywac ten sam katalog, ktory
 *    podaje `load_plugin_textdomain()`. Rozjazd tych dwoch znaczy, ze panel
 *    wtyczek szuka tlumaczen gdzie indziej niz kod.
 *
 * B. SZABLON. `languages/<slug>.pot` istnieje, ma naglowek i realne ciagi.
 *
 * C. AKTUALNOSC. `Project-Id-Version` szablonu zgadza sie z wersja wtyczki.
 *    Szablon starszy od kodu to szablon bez nowych ciagow — czyli dokladnie ta
 *    sama awaria, tylko cichsza. Odswiezenie to jedno polecenie, zapisane
 *    w `docs/TESTY.md` i w README.
 *
 * D. KONTR-ASERCJE: szablon zbiera ciagi z kodu wtyczki, a NIE z jej testow
 *    i dokumentacji — te nie trafiaja do klienta jako interfejs.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_st'] = array(
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
function st_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_st']['pass'];
		$GLOBALS['mp_st']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_st']['fail'];
	$GLOBALS['mp_st']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function st_koniec() {
	if ( empty( $GLOBALS['mp_st']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_st'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_st']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'st_koniec' );

$wtyczki = array(
	'mp-lead-intake'    => 'Zapytanie ofertowe',
	'mp-offer-builder'  => null,
	'mp-sales-workflow' => null,
);

foreach ( $wtyczki as $slug => $probka ) {
	$GLOBALS['mp_st']['lines'][] = '';
	$GLOBALS['mp_st']['lines'][] = '=== ' . $slug . ' ===';

	$glowny = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
	$pot    = WP_PLUGIN_DIR . '/' . $slug . '/languages/' . $slug . '.pot';

	if ( ! st_ok( file_exists( $glowny ), 'plik glowny wtyczki jest na miejscu', $glowny ) ) {
		continue;
	}

	$naglowek = (string) file_get_contents( $glowny );

	/* ------------------------------------------------------------------ A */

	preg_match( '~^\s*\*\s*Domain Path:\s*(\S+)\s*$~m', $naglowek, $m_path );
	$domain_path = isset( $m_path[1] ) ? $m_path[1] : '';

	st_ok( '' !== $domain_path, 'naglowek deklaruje Domain Path', 'brak naglowka' );

	preg_match( "~load_plugin_textdomain\(\s*'[^']+',\s*false,\s*[^,]+\.\s*'(/[^']+)'~", $naglowek, $m_load );
	$sciezka_kodu = isset( $m_load[1] ) ? $m_load[1] : '';

	st_ok(
		'' !== $sciezka_kodu && $sciezka_kodu === $domain_path,
		'i wskazuje ten sam katalog co load_plugin_textdomain()',
		'naglowek=' . $domain_path . ' kod=' . $sciezka_kodu
	);

	/* ------------------------------------------------------------------ B */

	if ( ! st_ok( file_exists( $pot ), 'szablon languages/' . $slug . '.pot istnieje' ) ) {
		continue;
	}

	$tresc = (string) file_get_contents( $pot );
	$ile   = preg_match_all( '~^msgid "~m', $tresc );

	st_ok( $ile > 30, 'szablon ma realne ciagi, a nie sam naglowek', 'msgid=' . $ile );

	st_ok(
		false !== strpos( $tresc, '"Content-Type: text/plain; charset=UTF-8\n"' ),
		'szablon deklaruje UTF-8 — inaczej polskie znaki wracaja z tlumaczen polamane'
	);

	/* ------------------------------------------------------------------ C */

	preg_match( '~^ \* Version:\s+(\S+)~m', $naglowek, $m_wer );
	$wersja = isset( $m_wer[1] ) ? $m_wer[1] : '';

	st_ok(
		'' !== $wersja && false !== strpos( $tresc, $wersja . '\n"' ),
		'szablon jest z TEJ wersji wtyczki (Project-Id-Version)',
		'wersja wtyczki=' . $wersja
	);

	/* ------------------------------------------------------------------ D */

	st_ok(
		false === strpos( $tresc, '/tests/' ),
		'KONTR-ASERCJA: szablon nie zbiera ciagow z testow'
	);

	st_ok(
		false === strpos( $tresc, '/docs/' ),
		'KONTR-ASERCJA: ani z dokumentacji'
	);

	if ( null !== $probka ) {
		st_ok(
			false !== strpos( $tresc, 'msgid "' . $probka . '"' ),
			'w szablonie jest ciag widziany przez klienta („' . $probka . '")'
		);
	}
}
