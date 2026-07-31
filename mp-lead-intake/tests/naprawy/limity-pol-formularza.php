<?php
/**
 * P1-G3 — pola `segment` i `est_volume` bez limitu dlugosci.
 *
 * Uruchamianie: wp eval-file tests/naprawy/limity-pol-formularza.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-G3  Pola segment i est_volume bez limitu dlugosci
 *
 * Agent 2.3 nakladal limity dlugosci na `company_name` (255) i `phone` (30),
 * z komentarzem mowiacym wprost, po co: „Limity dlugosci zgodne z kolumnami
 * BD-3 (unikamy »Data too long«/obciecia w dziale 7)". Dwie pozostale kolumny
 * tekstowe formularza — `segment` i `est_volume`, obie `varchar(100)` — zostaly
 * z tego pominiete.
 *
 * Skutek nie jest kosmetyczny. `wpdb::process_fields()` przycina wartosc do
 * dlugosci kolumny i gdy wykryje roznice, ZWRACA FALSE zamiast zapisac. Dzial 7
 * dostaje `insert_failed`, transakcja idzie w ROLLBACK, a klient widzi ogolne
 * „Nie udalo sie przetworzyc zgloszenia. Sprawdz dane i sprobuj ponownie."
 * Nie wie, KTORE pole poprawic, wiec kazda kolejna proba z tym samym tekstem
 * konczy sie tak samo. Lead przepada.
 *
 * A tekst przekraczajacy 100 znakow w polu „Przewidywany wolumen" to nie jest
 * przypadek skrajny — wystarczy jedno zdanie opisujace sezonowosc.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_lp'] = array(
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
function lp_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_lp']['pass'];
		$GLOBALS['mp_lp']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_lp']['fail'];
	$GLOBALS['mp_lp']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Uruchamia walidacje formatow na podanym zestawie pol.
 *
 * @param array $pola Pola formularza.
 * @return array Bledy wg pola.
 */
function lp_bledy( array $pola ) {
	$kontekst = new MP_Context(
		array_merge(
			array(
				'company_name' => 'Firma Testowa',
				'email'        => 'kontakt@firma.test',
				'nip'          => '1234563218',
				'country'      => 'PL',
			),
			$pola
		)
	);

	$wynik = ( new MP_D2_Agent_Validate_Formats() )->run( $kontekst );
	$dane  = $wynik->get_data();

	return isset( $dane['errors'] ) ? (array) $dane['errors'] : array();
}

// Kolumny BD-3 sa `varchar(100)` — 101 znakow juz sie nie miesci.
$za_dlugie = str_repeat( 'a', 101 );
$na_styk   = str_repeat( 'a', 100 );

$GLOBALS['mp_lp']['lines'][] = '=== A. segment ===';

$b = lp_bledy( array( 'segment' => $za_dlugie ) );
lp_ok( isset( $b['segment'] ), 'segment ponad 100 znakow jest odrzucany', 'bledy: ' . implode( ',', array_keys( $b ) ) );

/*
 * KONTR-ASERCJA. Bez niej „naprawa" mogla by polegac na odrzucaniu kazdego
 * segmentu — formularz przestalby dzialac, a test i tak bylby zielony.
 */
$b = lp_bledy( array( 'segment' => $na_styk ) );
lp_ok( ! isset( $b['segment'] ), 'segment dokladnie 100 znakow przechodzi' );

$b = lp_bledy( array( 'segment' => 'Produkcja spozywcza' ) );
lp_ok( ! isset( $b['segment'] ), 'typowa wartosc segmentu przechodzi' );

$b = lp_bledy( array() );
lp_ok( ! isset( $b['segment'] ), 'brak segmentu (pole opcjonalne) nie jest bledem' );

$GLOBALS['mp_lp']['lines'][] = '';
$GLOBALS['mp_lp']['lines'][] = '=== B. est_volume ===';

$b = lp_bledy( array( 'est_volume' => $za_dlugie ) );
lp_ok( isset( $b['est_volume'] ), 'est_volume ponad 100 znakow jest odrzucany', 'bledy: ' . implode( ',', array_keys( $b ) ) );

$b = lp_bledy( array( 'est_volume' => $na_styk ) );
lp_ok( ! isset( $b['est_volume'] ), 'est_volume dokladnie 100 znakow przechodzi' );

$b = lp_bledy( array( 'est_volume' => 'okolo 100 szt. miesiecznie' ) );
lp_ok( ! isset( $b['est_volume'] ), 'typowa wartosc wolumenu przechodzi' );

$GLOBALS['mp_lp']['lines'][] = '';
$GLOBALS['mp_lp']['lines'][] = '=== C. limit liczony w ZNAKACH, nie w bajtach ===';

/*
 * Ta sama pulapka, co w P2-G2: kolumna `varchar(100)` w utf8mb4 liczy ZNAKI,
 * wiec 100 polskich znakow z ogonkami miesci sie w kolumnie, mimo ze zajmuje
 * wiecej niz 100 bajtow. Limit mierzony `strlen()` odrzucalby poprawne dane.
 */
$ogonki = str_repeat( 'ą', 100 );

lp_ok( 100 === mb_strlen( $ogonki, 'UTF-8' ), 'wartosc kontrolna ma 100 znakow' );
lp_ok( strlen( $ogonki ) > 100, 'ta sama wartosc ma ponad 100 bajtow', (string) strlen( $ogonki ) );

$b = lp_bledy( array( 'segment' => $ogonki ) );
lp_ok( ! isset( $b['segment'] ), '100 znakow z ogonkami miesci sie w limicie' );

$b = lp_bledy( array( 'est_volume' => $ogonki ) );
lp_ok( ! isset( $b['est_volume'] ), 'to samo dla est_volume' );

echo implode( "\n", $GLOBALS['mp_lp']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_lp']['pass'], $GLOBALS['mp_lp']['fail'] );
echo ( 0 === $GLOBALS['mp_lp']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
