<?php
/**
 * Dwa słowniki, które rozjechały się z kodem, który miał ich używać.
 *
 * Uruchamianie: wp eval-file tests/naprawy/slownik-alarmu-i-krotki-kod.php
 *
 * A. LOS ALARMU. `alert_state()` oddawało łańcuch „wysylany". `etykieta_alarmu()`
 *    rozpoznaje „wyslany" — bez „y" w środku. Etykieta z tej gałęzi nie mogła
 *    więc wypaść NIGDY; każdy wpis opisany prognozą dostawał „[alarm: los
 *    nieustalony]". Wydanie 1.3.9 przestawiło zapis losu na `oznacz_los_alarmu()`
 *    i `alert_state()` zostało bez wywołań — czyli martwy kod z własnym,
 *    niezgodnym z resztą słownictwem, czekający, aż ktoś go użyje.
 *
 *    Naprawa nie polega na poprawieniu literówki. Polega na tym, żeby stan
 *    alarmu przestał być swobodnym łańcuchem: trzy stałe, jeden słownik,
 *    a etykieta wyliczana z tego samego źródła, którego używają wywołania.
 *    Literówka w stałej to błąd krytyczny PHP, a nie cicha „nieustalona" etykieta.
 *
 * B. KRÓTKI KOD. `MP_Lead_Intake_Form::SHORTCODE` istnieje i połowa
 *    `class-mp-page.php` z niego korzysta. Druga połowa wpisywała
 *    „[mp_lead_intake_form]" wprost: treść zakładanej strony i rada dla
 *    administratora, którego strona zniknęła. Zmiana stałej rozjechałaby te
 *    miejsca z resztą — strona powstawałaby z krótkim kodem, którego wtyczka
 *    już nie rejestruje, a `adopt_existing_page()` szukałoby po nowej nazwie
 *    i nie znalazłoby własnej strony.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_sk'] = array(
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
function sk_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_sk']['pass'];
		$GLOBALS['mp_sk']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_sk']['fail'];
	$GLOBALS['mp_sk']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function sk_koniec() {
	if ( empty( $GLOBALS['mp_sk']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_sk'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_sk']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'sk_koniec' );

/**
 * Kod pliku BEZ komentarzy — historia pomyłki ma prawo zostać w docblocku,
 * ale nie w kodzie wykonywanym.
 *
 * @param string $sciezka Ścieżka pliku.
 * @return string
 */
function sk_kod_bez_komentarzy( $sciezka ) {
	$zrodlo = (string) file_get_contents( $sciezka ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$kod    = '';

	foreach ( token_get_all( $zrodlo ) as $token ) {
		if ( is_array( $token ) ) {
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				continue;
			}

			$kod .= $token[1];
			continue;
		}

		$kod .= $token;
	}

	return $kod;
}

$sk_plik_loggera = dirname( __DIR__, 2 ) . '/includes/pipeline/class-mp-pipeline-logger.php';
$sk_plik_strony  = dirname( __DIR__, 2 ) . '/includes/class-mp-page.php';

/* ==================================================================== A */

$GLOBALS['mp_sk']['lines'][] = '=== A. stan alarmu ma jeden slownik ===';

$sk_klasa = new ReflectionClass( 'MP_Pipeline_Logger' );

sk_ok(
	! $sk_klasa->hasMethod( 'alert_state' ),
	'martwa prognoza alert_state() zniknela'
);
sk_ok(
	$sk_klasa->hasMethod( 'stany_alarmu' ),
	'jest jedno miejsce ze slownikiem stanow: stany_alarmu()'
);

$sk_kod_loggera = sk_kod_bez_komentarzy( $sk_plik_loggera );

sk_ok(
	false === strpos( $sk_kod_loggera, 'wysylany' ),
	'w kodzie nie ma juz stanu-widma „wysylany"'
);

if ( ! $sk_klasa->hasMethod( 'stany_alarmu' ) ) {
	return;
}

$sk_stany = (array) MP_Pipeline_Logger::stany_alarmu();

sk_ok(
	3 === count( $sk_stany ) && count( array_unique( $sk_stany ) ) === count( $sk_stany ),
	'slownik ma trzy rozne stany',
	implode( ', ', $sk_stany )
);

$sk_etykieta = $sk_klasa->getMethod( 'etykieta_alarmu' );
$sk_etykieta->setAccessible( true );
$sk_logger = new MP_Pipeline_Logger();

$sk_fallback = (string) $sk_etykieta->invoke( $sk_logger, 'stan-ktorego-nie-ma' );
$sk_podpisy  = array();

foreach ( $sk_stany as $sk_stan ) {
	$sk_podpisy[ $sk_stan ] = (string) $sk_etykieta->invoke( $sk_logger, $sk_stan );

	sk_ok(
		'' !== $sk_podpisy[ $sk_stan ] && $sk_podpisy[ $sk_stan ] !== $sk_fallback,
		'stan „' . $sk_stan . '" ma wlasna etykiete, nie „los nieustalony"',
		$sk_podpisy[ $sk_stan ]
	);
}

sk_ok(
	count( array_unique( $sk_podpisy ) ) === count( $sk_podpisy ),
	'etykiety trzech stanow roznia sie miedzy soba'
);

/*
 * Sedno naprawy: wywołania mają brać stan ze słownika, nie z klawiatury.
 * Dopóki czwarty argument bywa łańcuchem w apostrofach, literówka wraca.
 */
$sk_wywolania = array();
preg_match_all( '/oznacz_los_alarmu\s*\(([^;]*?)\)\s*;/s', $sk_kod_loggera, $sk_wywolania );

sk_ok(
	count( $sk_wywolania[1] ) >= 6,
	'test widzi wszystkie wywolania oznacz_los_alarmu()',
	'znaleziono ' . count( $sk_wywolania[1] )
);

$sk_z_literalem = array();

foreach ( $sk_wywolania[1] as $sk_argumenty ) {
	if ( preg_match( "/'[^']*'/", $sk_argumenty ) ) {
		$sk_z_literalem[] = trim( preg_replace( '/\s+/', ' ', $sk_argumenty ) );
	}
}

sk_ok(
	array() === $sk_z_literalem,
	'zadne wywolanie nie podaje stanu luznym lancuchem',
	implode( ' | ', $sk_z_literalem )
);

/* ==================================================================== B */

$GLOBALS['mp_sk']['lines'][] = '';
$GLOBALS['mp_sk']['lines'][] = '=== B. kontr-asercje: dziennik nadal opisuje los alarmu ===';

sk_ok(
	false !== mb_strpos( $sk_podpisy[ MP_Pipeline_Logger::ALARM_NIEUDANY ] ?? '', 'NIE' ),
	'nieudany alarm nadal krzyczy w opisie'
);
sk_ok(
	'' !== $sk_fallback,
	'nieznany stan nadal dostaje etykiete zastepcza, a nie pustke',
	$sk_fallback
);
sk_ok(
	in_array( 'wyslany', $sk_stany, true ) && in_array( 'wyciszony', $sk_stany, true ) && in_array( 'nieudany', $sk_stany, true ),
	'wartosci stanow nie zmienily sie — stare wpisy w meta_json nadal sie tlumacza'
);

/* ==================================================================== C */

$GLOBALS['mp_sk']['lines'][] = '';
$GLOBALS['mp_sk']['lines'][] = '=== C. krotki kod pochodzi ze stalej ===';

$sk_kod_strony = sk_kod_bez_komentarzy( $sk_plik_strony );

sk_ok(
	false === strpos( $sk_kod_strony, 'mp_lead_intake_form' ),
	'class-mp-page.php nie wpisuje nazwy krotkiego kodu z reki',
	'nadal wpisana ' . substr_count( $sk_kod_strony, 'mp_lead_intake_form' ) . ' raz(y)'
);

/* ==================================================================== D */

$GLOBALS['mp_sk']['lines'][] = '';
$GLOBALS['mp_sk']['lines'][] = '=== D. kontr-asercje: strona i formularz nadal sie spotykaja ===';

$sk_krotki = '[' . MP_Lead_Intake_Form::SHORTCODE . ']';

sk_ok(
	shortcode_exists( MP_Lead_Intake_Form::SHORTCODE ),
	'krotki kod ze stalej jest zarejestrowany w WordPressie'
);

/*
 * Strona zakładana NA ŻYWO, bo tylko wtedy asercja dotyczy `create()`, a nie
 * stanu środowiska. Sprzątamy po sobie: to, co zastaliśmy, wraca na miejsce.
 */
$sk_stara_opcja = get_option( MP_Lead_Intake_Page::OPTION, false );
$sk_stara_id    = (int) $sk_stara_opcja;
$sk_zastana     = $sk_stara_id > 0 ? get_post( $sk_stara_id ) : null;
$sk_nasza       = ! ( $sk_zastana instanceof WP_Post && 'publish' === $sk_zastana->post_status );

if ( $sk_nasza ) {
	delete_option( MP_Lead_Intake_Page::OPTION );
}

MP_Lead_Intake_Page::create();

$sk_strona_id = (int) get_option( MP_Lead_Intake_Page::OPTION );
$sk_strona    = $sk_strona_id > 0 ? get_post( $sk_strona_id ) : null;

if ( sk_ok( $sk_strona instanceof WP_Post, 'create() zaklada strone z formularzem' ) ) {
	sk_ok(
		has_shortcode( (string) $sk_strona->post_content, MP_Lead_Intake_Form::SHORTCODE ),
		'i niesie dokladnie ten krotki kod',
		'tresc=' . mb_substr( (string) $sk_strona->post_content, 0, 60 )
	);
}

if ( $sk_nasza ) {
	MP_Lead_Intake_Page::remove();

	if ( false !== $sk_stara_opcja ) {
		update_option( MP_Lead_Intake_Page::OPTION, $sk_stara_opcja );
	}
}

sk_ok(
	'' !== trim( do_shortcode( $sk_krotki ) ) && $sk_krotki !== trim( do_shortcode( $sk_krotki ) ),
	'krotki kod ze stalej naprawde cos renderuje'
);
