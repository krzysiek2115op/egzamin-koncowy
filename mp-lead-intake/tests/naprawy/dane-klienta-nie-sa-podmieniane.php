<?php
/**
 * Trzy ustalenia audytu z wtyczki 1 — wszystkie o tym samym: dane przechodzą
 * przez kod, który je po cichu zmienia albo w ogóle ich nie dotyka.
 *
 * Uruchamianie: wp eval-file tests/naprawy/dane-klienta-nie-sa-podmieniane.php
 *
 * A. ADRES PODMIENIONY BEZ SŁOWA. Agent 2.2 normalizował e-mail przez
 *    `sanitize_email()`, a agent 2.3 sprawdzał `is_email()` na wartości JUŻ
 *    przepisanej — nie na tej, którą wpisał klient. `sanitize_email()` usuwa
 *    znaki spoza swojego węższego zbioru i oddaje adres, który przechodzi
 *    walidację. Zmierzone wprost:
 *
 *      zażółć@firma.pl  ->  za@firma.pl        (is_email: tak)
 *      Jan Kowalski@x.pl -> JanKowalski@x.pl   (is_email: tak)
 *
 *    Cały proces kończył się sukcesem, a w BD-3 lądował INNY adres. Oferta
 *    z wtyczki 2 szła na skrzynkę, której klient nigdy nie podał, i nikt tego
 *    nie widział — ani klient, ani handlowiec, ani dziennik.
 *
 *    Apostrof jest przy tym legalny (`o'brien@firma.pl` przechodzi bez zmian) —
 *    ta część zgłoszenia była nieprawdziwa i test to utrwala.
 *
 * B. POLA OPISOWE BEZ NORMALIZACJI. Agent 2.2 sanityzuje `company_name`,
 *    `email`, `nip` i `phone`. `segment` i `est_volume` mają tylko limit
 *    długości — do BD-3 szła wartość surowa, z tagami i złamaniami linii,
 *    mimo że opis działu deklaruje „normalizację danych oficjalnymi funkcjami
 *    WordPressa". Ta sama wartość idzie potem do oferty i do PDF-a we wtyczce 2.
 *
 * C. PUSTY ŚLAD ZNACZY „STRONA JEST". Ślad po nieudanym utworzeniu strony
 *    zapisywał `$page_id->get_error_message()` bez sprawdzenia, czy komunikat
 *    nie jest pusty — a pusta wartość `OPTION_PAGE_ERROR` znaczy w tej klasie
 *    dokładnie „strona istnieje" (tak mówi docblock stałej). WP_Error z samym
 *    kodem, bez treści, dawał więc ślad nieodróżnialny od powodzenia.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_dk'] = array(
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
function dk_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_dk']['pass'];
		$GLOBALS['mp_dk']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_dk']['fail'];
	$GLOBALS['mp_dk']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function dk_koniec() {
	if ( empty( $GLOBALS['mp_dk']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_dk'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_dk']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'dk_koniec' );

/**
 * Uruchamia agenta 2.3 na kopercie po normalizacji agenta 2.2 — dokładnie tak,
 * jak robi to pipeline.
 *
 * @param array $pola Pola zgłoszenia.
 * @return array Błędy zgłoszone przez 2.3.
 */
function dk_walidacja( array $pola ) {
	$kontekst = new MP_Context(
		array_merge(
			array(
				'company_name' => 'Firma Testowa',
				'email'        => 'kontakt@firma.pl',
				'nip'          => '5260001246',
				'country'      => 'PL',
				'phone'        => '123456789',
			),
			$pola
		)
	);

	$norm = ( new MP_D2_Agent_Normalize() )->run( $kontekst );
	foreach ( (array) $norm->get_data() as $klucz => $wartosc ) {
		$kontekst->set( $klucz, $wartosc );
	}

	$wynik = ( new MP_D2_Agent_Validate_Formats() )->run( $kontekst );
	$dane  = (array) $wynik->get_data();

	return array(
		'errors'  => (array) ( $dane['errors'] ?? array() ),
		'email'   => (string) $kontekst->get( 'email', '' ),
		'segment' => (string) $kontekst->get( 'segment', '' ),
		'wolumen' => (string) $kontekst->get( 'est_volume', '' ),
	);
}

/* ==================================================================== A */

$GLOBALS['mp_dk']['lines'][] = '=== A. adres e-mail nie jest po cichu podmieniany ===';

$dk_podmieniane = array(
	'zażółć@firma.pl'      => 'polskie znaki w czesci lokalnej',
	'Jan Kowalski@firma.pl' => 'spacja w czesci lokalnej',
	'użytkownik@firma.pl'  => 'jeden znak diakrytyczny',
);

foreach ( $dk_podmieniane as $dk_adres => $dk_opis ) {
	$dk_po = sanitize_email( $dk_adres );
	$dk_w  = dk_walidacja( array( 'email' => $dk_adres ) );

	dk_ok(
		$dk_adres !== $dk_po && is_email( $dk_po ),
		'A-pomiar (' . $dk_opis . '): sanitize_email ZMIENIA adres na taki, ktory przechodzi is_email',
		$dk_adres . ' -> ' . $dk_po
	);
	dk_ok(
		isset( $dk_w['errors']['email'] ),
		'A-odmowa (' . $dk_opis . '): zgloszenie jest ODRZUCANE, a nie ciche',
		'bledy=' . wp_json_encode( $dk_w['errors'] )
	);
}

$dk_komunikat = (string) ( dk_walidacja( array( 'email' => 'zażółć@firma.pl' ) )['errors']['email'] ?? '' );

dk_ok(
	false !== mb_stripos( $dk_komunikat, 'adres' ),
	'A: komunikat mowi o adresie, wiec klient wie, co poprawic',
	'komunikat=' . $dk_komunikat
);
dk_ok(
	false === mb_strpos( $dk_komunikat, 'za@firma.pl' ),
	'A: i NIE pokazuje klientowi wersji przepisanej jako jego adresu'
);

$dk_poprawne = array(
	"o'brien@firma.pl"          => 'apostrof jest legalny — ta czesc zgloszenia byla nieprawdziwa',
	'jan.kowalski+tag@firma.pl' => 'plus i kropka',
	'kontakt@firma.pl'          => 'zwykly adres',
	'  kontakt@firma.pl  '      => 'adres z bialymi znakami dokola',
);

foreach ( $dk_poprawne as $dk_adres => $dk_opis ) {
	dk_ok(
		! isset( dk_walidacja( array( 'email' => $dk_adres ) )['errors']['email'] ),
		'A-kontr-asercja (' . $dk_opis . '): przechodzi jak dotad',
		'bledy=' . wp_json_encode( dk_walidacja( array( 'email' => $dk_adres ) )['errors'] )
	);
}

/* ==================================================================== B */

$GLOBALS['mp_dk']['lines'][] = '';
$GLOBALS['mp_dk']['lines'][] = '=== B. pola opisowe przechodza normalizacje ===';

$dk_b = dk_walidacja(
	array(
		'segment'    => "Handel\n<b>hurt</b>\t",
		'est_volume' => '  <script>alert(1)</script> 500 ton  ',
	)
);

dk_ok(
	false === mb_strpos( $dk_b['segment'], '<b>' ) && false === mb_strpos( $dk_b['segment'], "\n" ),
	'B1: segment bez znacznikow HTML i bez zlamania linii',
	'segment=' . wp_json_encode( $dk_b['segment'] )
);
dk_ok(
	false === mb_strpos( $dk_b['wolumen'], '<script' ),
	'B2: wolumen bez znacznikow',
	'wolumen=' . wp_json_encode( $dk_b['wolumen'] )
);
dk_ok(
	false !== mb_strpos( $dk_b['segment'], 'Handel' ) && false !== mb_strpos( $dk_b['segment'], 'hurt' ),
	'B3: tresc podana przez klienta zostaje — normalizacja, nie kasowanie',
	'segment=' . wp_json_encode( $dk_b['segment'] )
);
dk_ok(
	'500 ton' === trim( $dk_b['wolumen'] ) || false !== mb_strpos( $dk_b['wolumen'], '500 ton' ),
	'B4: wolumen zachowuje liczbe i jednostke',
	'wolumen=' . wp_json_encode( $dk_b['wolumen'] )
);

$dk_b2 = dk_walidacja( array( 'segment' => 'Przetwórstwo spożywcze', 'est_volume' => '1 200 t/rok' ) );

dk_ok(
	'Przetwórstwo spożywcze' === $dk_b2['segment'] && '1 200 t/rok' === $dk_b2['wolumen'],
	'B5: KONTR-ASERCJA — zwykla tresc z polskimi znakami przechodzi bez zmian',
	'segment=' . $dk_b2['segment'] . ' wolumen=' . $dk_b2['wolumen']
);
dk_ok(
	empty( $dk_b2['errors'] ),
	'B6: KONTR-ASERCJA — i nie powstaje z tego blad'
);

/* ==================================================================== C */

$GLOBALS['mp_dk']['lines'][] = '';
$GLOBALS['mp_dk']['lines'][] = '=== C. slad po awarii nigdy nie jest pusty ===';

/*
 * `OPTION_PAGE_ERROR` z pusta wartoscia znaczy w tej klasie „strona jest" —
 * tak mowi docblock stalej. WP_Error z samym kodem, bez tresci, dawal wiec slad
 * nieodroznialny od powodzenia: administrator nie widzial ani strony, ani
 * powodu jej braku.
 */
$dk_metoda = new ReflectionMethod( 'MP_Lead_Intake_Page', 'powod_awarii' );
$dk_metoda->setAccessible( true );

$dk_przypadki = array(
	'WP_Error z trescia'     => new WP_Error( 'zablokowane', 'Wtyczka bezpieczenstwa zablokowala zapis' ),
	'WP_Error bez tresci'    => new WP_Error( 'zablokowane', '' ),
	'WP_Error bez wiadomosci' => new WP_Error(),
	'wartosc nie-bledowa'    => 0,
);

foreach ( $dk_przypadki as $dk_opis => $dk_wartosc ) {
	$dk_slad = (string) $dk_metoda->invoke( null, $dk_wartosc );

	dk_ok(
		'' !== trim( $dk_slad ),
		'C (' . $dk_opis . '): slad NIE jest pusty',
		'slad=' . wp_json_encode( $dk_slad )
	);
}

dk_ok(
	false !== mb_strpos( (string) $dk_metoda->invoke( null, new WP_Error( 'zablokowane', 'Wtyczka bezpieczenstwa zablokowala zapis' ) ), 'Wtyczka bezpieczenstwa' ),
	'C: KONTR-ASERCJA — gdy WordPress podal powod, to on trafia do sladu'
);
dk_ok(
	false !== mb_strpos( (string) $dk_metoda->invoke( null, new WP_Error( 'zablokowane_przez_wtyczke', '' ) ), 'zablokowane_przez_wtyczke' ),
	'C: przy pustej tresci do sladu idzie przynajmniej KOD bledu — jedyna wskazowka, jaka zostala'
);
