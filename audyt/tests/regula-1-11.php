<?php
/**
 * NARZ-F5 — para 1.11 zadala „sygnalu anonimizacji" tam, gdzie nie ma co rozpoznawac.
 *
 * Uruchamianie: php audyt/tests/regula-1-11.php
 *
 * Para 1.11 pilnuje RODO. Jedna z jej regul wywodzi sie wprost z bledu P3-K3:
 * po anonimizacji kolejka powiadomien dalej probowala wysylac maile na adres
 * zastepczy `deleted+N@invalid`, bo nikt jej nie powiedzial, ze adresata juz
 * nie ma. Naprawa polegala na wystawieniu `is_anonymized()` i pytaniu o nia
 * PRZED wysylka. Regula sprawdzala jednak sam brak takiej funkcji — u kazdej
 * wtyczki majacej kasownik RODO.
 *
 * Szkoda z P3-K3 bierze sie z tego, ze anonimizacja PODSTAWIA adres zastepczy.
 * Wiersz wyglada dalej na kompletny: ma adres, wiec kolejka probuje na niego
 * wysylac, a dopiero `is_anonymized()` pozwala go odroznic od prawdziwego.
 *
 * Wtyczka „mp-offer-builder" kolumne adresu ZERUJE (`client_email => null`).
 * Nie zostaje po niej nic, co dalo by sie wziac za prawdziwy adres, wiec nie ma
 * tez czego rozpoznawac — a para zglaszala ustalenie sredniej wagi w kazdym
 * przebiegu.
 *
 * OS ZAWEZENIA WYBRANA ZA DRUGIM RAZEM. Pierwsza wersja tej poprawki pytala,
 * czy wtyczka w ogole wysyla poczte — na blednym zalozeniu, ze „mp-offer-builder"
 * nie wysyla. Wysyla: alarmy do administratora, przez `wp_mail()`. Nigdy jednak
 * na adres z anonimizowanej kolumny, i to jest roznica, ktora tu decyduje.
 * Zalozenie upadlo dopiero na pelnym przebiegu audytu, bo test byl zbudowany
 * na tym samym bledzie co poprawka — sam siebie potwierdzal.
 *
 * @package MP_Audyt
 */

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/kontrakty.php';
require_once $korzen . '/includes/pomoc.php';
require_once $korzen . '/includes/class-mp-au-workspace.php';
require_once $korzen . '/includes/class-mp-au-model-client.php';
require_once $korzen . '/includes/pary/dzial-01-bezpieczenstwo.php';

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
function r111_ok( bool $warunek, string $opis, string $info = '' ): void {
	global $pass, $fail;

	if ( $warunek ) {
		++$pass;
		echo "  [PASS] {$opis}\n";
		return;
	}

	++$fail;
	echo "  [FAIL] {$opis}" . ( '' !== $info ? ' -- ' . $info : '' ) . "\n";
}

/**
 * Fakty o jednej wtyczce w ksztalcie, w jakim oddaje je Agent 1.11.
 *
 * @param bool $sygnal_stop  Czy wtyczka wystawia rozpoznanie anonimizacji.
 * @param bool $zeruje_adres Czy anonimizacja zeruje kolumne adresu (zamiast podstawiac).
 * @return array
 */
function r111_stan( bool $sygnal_stop, bool $zeruje_adres ): array {
	return array(
		'kolumny'      => array( 'client_email' => 'x/includes/db.php' ),
		'eraser'       => true,
		'exporter'     => true,
		'czyszczone'   => array( 'client_email' => true ),
		'sygnal_stop'  => $sygnal_stop,
		'zeruje_adres' => $zeruje_adres,
		'pliki_rodo'   => array( 'x/includes/privacy.php' ),
	);
}

/**
 * Ustalenia o brakujacym sygnale anonimizacji, jakie zwroci krytyk.
 *
 * @param array $fakty Fakty per wtyczka.
 * @return string[] Nazwy wtyczek, ktorym krytyk zarzucil brak sygnalu.
 */
function r111_zgloszone( array $fakty ): array {
	$ws       = new MP_AU_Workspace( dirname( dirname( __DIR__ ) ), sys_get_temp_dir() . '/mp-au-1-11-' . getmypid(), 'refs/heads/main' );
	$kontekst = new MP_AU_Kontekst( $ws, new MP_AU_Model_Client( $ws, sys_get_temp_dir(), 'bez-modelu' ) );

	$krytyk = new MP_AU_K111_Rodo( '1.11', 'dane-znikaja-po-obu-stronach' );
	$wynik  = $krytyk->ocen( MP_AU_Wynik::ok( array( 'fakty' => $fakty ) ), $kontekst );

	$nazwy = array();
	foreach ( $wynik->ustalenia as $ustalenie ) {
		if ( false !== strpos( $ustalenie->opis, 'sygnalu' ) ) {
			$nazwy[] = $ustalenie->plik;
		}
	}

	return $nazwy;
}

echo "=== A. zerowanie kolumny nie zostawia nic do rozpoznania ===\n";

$zeruje = r111_zgloszone( array( 'mp-offer-builder' => r111_stan( false, true ) ) );

r111_ok(
	array() === $zeruje,
	'A1: wtyczka ZERUJACA kolumne adresu nie potrzebuje sygnalu anonimizacji',
	'zgloszono: ' . implode( ', ', $zeruje )
);

echo "=== B. to, po co ta regula powstala, dalej dziala ===\n";

$podstawia = r111_zgloszone( array( 'mp-sales-workflow' => r111_stan( false, false ) ) );

r111_ok(
	array( 'mp-sales-workflow' ) === $podstawia,
	'B1: KONTR-ASERCJA — wtyczka PODSTAWIAJACA adres zastepczy bez sygnalu to nadal blad P3-K3',
	'zgloszono: ' . implode( ', ', $podstawia )
);

$z_sygnalem = r111_zgloszone( array( 'mp-sales-workflow' => r111_stan( true, false ) ) );

r111_ok(
	array() === $z_sygnalem,
	'B2: KONTR-ASERCJA — podstawiajaca, ale z sygnalem, jest czysta',
	'zgloszono: ' . implode( ', ', $z_sygnalem )
);

echo "=== C. zawezenie dotyczy JEDNEJ reguly, nie calej pary ===\n";

$bez_eraseru                 = r111_stan( false, true );
$bez_eraseru['eraser']       = false;
$bez_eraseru['exporter']     = false;
$bez_eraseru['czyszczone']   = array();

$ws       = new MP_AU_Workspace( dirname( dirname( __DIR__ ) ), sys_get_temp_dir() . '/mp-au-1-11c-' . getmypid(), 'refs/heads/main' );
$kontekst = new MP_AU_Kontekst( $ws, new MP_AU_Model_Client( $ws, sys_get_temp_dir(), 'bez-modelu' ) );
$krytyk   = new MP_AU_K111_Rodo( '1.11', 'dane-znikaja-po-obu-stronach' );
$wynik    = $krytyk->ocen( MP_AU_Wynik::ok( array( 'fakty' => array( 'mp-lead-intake' => $bez_eraseru ) ) ), $kontekst );

$opisy = array();
foreach ( $wynik->ustalenia as $ustalenie ) {
	$opisy[] = $ustalenie->opis;
}

r111_ok(
	3 === count( $wynik->ustalenia ),
	'C1: KONTR-ASERCJA — brak eraseru, eksportera i nieczyszczona kolumna nadal daja trzy ustalenia',
	'ustalen=' . count( $wynik->ustalenia ) . ': ' . implode( ' | ', $opisy )
);

$krytyczne = 0;
foreach ( $wynik->ustalenia as $ustalenie ) {
	if ( MP_AU_Ustalenie::KRYTYCZNE === $ustalenie->waga ) {
		++$krytyczne;
	}
}

r111_ok(
	1 === $krytyczne,
	'C2: KONTR-ASERCJA — brak kasownika RODO zostaje ustaleniem KRYTYCZNYM',
	'krytycznych=' . $krytyczne
);

echo "\n----- PASS: {$pass} / FAIL: {$fail} -----\n";
echo 0 === $fail ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
