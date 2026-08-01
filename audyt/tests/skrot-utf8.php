<?php
/**
 * Test: skrot() tnie po ZNAKACH, nie po bajtach — i paczka do modelu nigdy nie
 * wychodzi pusta w milczeniu.
 *
 * Uruchamianie: php audyt/tests/skrot-utf8.php
 *
 * To jest przyczyna, dla ktorej przebieg gleboki z 01.08.2026 nie dostal
 * werdyktu. Lancuch mial piec ogniw i kazde z nich milczalo:
 *
 *   1. `MP_AU_Pomoc::skrot()` tnie `substr( $tekst, 0, $ile )` — po BAJTACH.
 *      Dowody ustalen sa pelne polskich znakow i cudzyslowow typograficznych,
 *      wiec ciecie na 400. bajcie ladowalo w srodku znaku wielobajtowego.
 *   2. `json_encode()` na takim ciagu zwraca `false` („Malformed UTF-8").
 *   3. Sklejenie `"=== USTALENIA ===\n" . false` daje sama naglowek — paczka
 *      znika, a nikt tego nie zauwaza, bo `false` w kontekscie string to ''.
 *   4. Model dostaje pytanie BEZ ustalen i uczciwie odpowiada
 *      `{"werdykty":[]}` — poprawnym JSON-em.
 *   5. Krytyk 2.9 czyta pusta liste jako „ocena NIE zostala wykonana", bramka
 *      Dzialu 2 schodzi do 91% i CALY audyt konczy sie werdyktem
 *      „BRAK WERDYKTU — audyt niekompletny".
 *
 * Jeden `substr` zablokowal werdykt calego przebiegu. Klasa bledu jest ta sama,
 * ktora audyt tropi we wtyczkach: PUSTY WYNIK UZNANY ZA WYNIK.
 *
 * @package MP_Audyt
 */

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/pomoc.php';

$pass = 0;
$fail = 0;

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $info    Kontekst przy porazce.
 * @return void
 */
function su_ok( bool $warunek, string $opis, string $info = '' ): void {
	global $pass, $fail;

	if ( $warunek ) {
		++$pass;
		echo "  [PASS] {$opis}\n";
		return;
	}

	++$fail;
	echo "  [FAIL] {$opis}" . ( '' !== $info ? ' -- ' . $info : '' ) . "\n";
}

echo "== A. skrot() nie rozbija znaku wielobajtowego ==\n";

/*
 * Przemiatamy granice ciecia. Znak wielobajtowy trafia na nia dla czesci
 * przesuniec — pojedynczy przypadek nic by nie dowiodl, bo latwo trafic
 * w przesuniecie, przy ktorym granica wypada miedzy znakami.
 */
$zle_utf8 = array();
$zle_json = array();

for ( $pad = 380; $pad <= 420; $pad++ ) {
	$tekst = str_repeat( 'a', $pad ) . 'ąćęłńóśźż — „cytat” w dowodzie ustalenia';
	$skrot = MP_AU_Pomoc::skrot( $tekst, 400 );

	if ( ! mb_check_encoding( $skrot, 'UTF-8' ) ) {
		$zle_utf8[] = $pad;
	}

	if ( false === json_encode( array( array( 'dowod' => $skrot ) ), JSON_UNESCAPED_UNICODE ) ) {
		$zle_json[] = $pad;
	}
}

su_ok(
	empty( $zle_utf8 ),
	'skrot() na 41 przesunieciach zawsze oddaje poprawny UTF-8',
	'zepsute przy pad=' . implode( ',', $zle_utf8 )
);
su_ok(
	empty( $zle_json ),
	'json_encode() na wyniku skrot() nigdy nie zwraca false',
	'false przy pad=' . implode( ',', $zle_json )
);

/*
 * Kontr-asercja: zaostrzenie nie ma prawa zamienic skrotu w cos innego niz
 * skrot. Krotki tekst wraca bez zmian, dlugi jest realnie skracany i niesie
 * znacznik urwania.
 */
su_ok(
	'krotki tekst' === MP_AU_Pomoc::skrot( 'krotki tekst', 400 ),
	'krotki tekst wraca nietkniety'
);

$dlugi = str_repeat( 'ą', 500 );
$sk    = MP_AU_Pomoc::skrot( $dlugi, 400 );
su_ok(
	mb_strlen( $sk, 'UTF-8' ) < mb_strlen( $dlugi, 'UTF-8' ) && false !== strpos( $sk, '[' ),
	'dlugi tekst jest skracany i oznaczony jako urwany',
	'dlugosc=' . mb_strlen( $sk, 'UTF-8' )
);

/*
 * Tekst zlozony WYLACZNIE ze znakow dwubajtowych to najostrzejszy przypadek:
 * przy cieciu po bajtach granica wypada w srodku znaku dla co drugiego limitu.
 */
$zle_2b = array();
for ( $limit = 100; $limit <= 140; $limit++ ) {
	if ( ! mb_check_encoding( MP_AU_Pomoc::skrot( str_repeat( 'ż', 300 ), $limit ), 'UTF-8' ) ) {
		$zle_2b[] = $limit;
	}
}
su_ok(
	empty( $zle_2b ),
	'tekst z samych znakow dwubajtowych znosi kazdy limit ciecia',
	'zepsute przy limit=' . implode( ',', $zle_2b )
);

echo "\n== B. pusta paczka do modelu nie udaje wyslanej ==\n";

/*
 * Drugie ogniwo lancucha. Nawet gdy skrot() jest juz bezpieczny, jakikolwiek
 * inny nieprawidlowy bajt w danych nadal wywroci json_encode(). Pytanie
 * zbudowane z `false` idzie do modelu jako sekcja BEZ TRESCI i wraca pusta
 * lista — nieodroznialna od uczciwego „nie mam zastrzezen".
 */
$zepsute = array( array( 'dowod' => "bajt spoza UTF-8: \xC4" ) );
$json    = json_encode( $zepsute, JSON_UNESCAPED_UNICODE );

su_ok(
	false === $json,
	'json_encode nadal zglasza porazke na danych realnie zepsutych (kontrola samego testu)'
);
su_ok(
	'' === (string) $json,
	'a sklejenie z false daje PUSTY ciag — dlatego sama sekcja nie wystarcza za dowod wyslania'
);

echo "\n== PODSUMOWANIE ==\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
echo ( 0 === $fail ) ? "WYNIK: PASS\n" : "WYNIK: FAIL\n";

exit( 0 === $fail ? 0 : 1 );
