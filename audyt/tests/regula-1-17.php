<?php
/**
 * Test reguly 1.17 — dokumentacja to jeden plik na ZRODLO, nie na dzial.
 *
 * Uruchamianie: php audyt/tests/regula-1-17.php
 *
 * Krytyk K1.17 zglaszal „Dzial N ma X plikow dokumentacji zamiast jednego" dla
 * kazdego katalogu z wiecej niz jednym plikiem `.md`. W przebiegu z 01.08.2026
 * dalo to 10 ustalen — i wszystkie byly falszywe. Zasada klienta brzmi: JEDEN
 * PLIK NA ZRODLO, czyli jedna dokumentacja z jednego oryginalnego zrodla.
 * `docs/dzial-03/` z piecioma plikami (Biala lista, algorytm NIP, VIES REST,
 * WP-Cron, wp_remote_get) jest wiec wzorcowy, a nie wadliwy.
 *
 * DLACZEGO KONTROLA ZNIKA, A NIE ZOSTAJE ZASTAPIONA. Szukalem niezmiennika,
 * ktory oddalby „jeden plik na zrodlo" mechanicznie. Zaden nie przezyl zderzenia
 * z danymi:
 *   - „kazdy plik musi cytowac URL" — 5 z 47 plikow to notatki DECYZYJNE
 *     (rodo-zgody.md, scoring-przypisanie.md, limity-koszyka.md i dwie inne),
 *     ktore zadnego zrodla zewnetrznego nie maja i miec nie musza;
 *   - „jeden host na plik" — 10 plikow cytuje dwa hosty i kazdy z tych
 *     przypadkow jest uprawniony: dwa opracowania tego samego algorytmu NIP,
 *     `example.com` w przykladzie kodu, dokumentacja WordPressa obok
 *     dokumentacji MySQL dla jednego tematu.
 *
 * Regula, ktorej nie da sie postawic bez falszywych alarmow, nie ma byc
 * stawiana na sile. Zostaja dwie kontrole, ktore sie bronia: dzial BEZ
 * dokumentacji i cytat z adresem, ale bez daty pobrania.
 *
 * @package MP_Audyt
 */

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/kontrakty.php';
require_once $korzen . '/includes/pomoc.php';
require_once $korzen . '/includes/class-mp-au-workspace.php';
require_once $korzen . '/includes/class-mp-au-model-client.php';
require_once $korzen . '/includes/pary/dzial-01-jakosc.php';

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
function r117_ok( bool $warunek, string $opis, string $info = '' ): void {
	global $pass, $fail;

	if ( $warunek ) {
		++$pass;
		echo "  [PASS] {$opis}\n";
		return;
	}

	++$fail;
	echo "  [FAIL] {$opis}" . ( '' !== $info ? ' -- ' . $info : '' ) . "\n";
}

$worktree = dirname( $korzen );
$baza     = sys_get_temp_dir() . '/mp-audyt-1-17-' . getmypid();

$ws = new MP_AU_Workspace( $worktree, $baza, 'refs/heads/main' );
$ws->wystaw();

$kontekst = new MP_AU_Kontekst( $ws, new MP_AU_Model_Client( $ws, sys_get_temp_dir(), 'bez-modelu' ) );

$agent  = new MP_AU_A117_Dokumentacja( '1.17', 'dokumentacja' );
$krytyk = new MP_AU_K117_Dokumentacja( '1.17', 'zrodlo-sprawdzalne' );

$zebrane = $agent->zbierz( $kontekst );
$wynik   = $krytyk->ocen( $zebrane, $kontekst );

$opisy = array();
foreach ( (array) $wynik->ustalenia as $u ) {
	$opisy[] = $u->opis;
}

echo "== A. liczba plikow w katalogu dzialu NIE jest ustaleniem ==\n";

$o_liczbie = array_values(
	array_filter(
		$opisy,
		static function ( $o ) {
			return false !== strpos( $o, 'plikow dokumentacji zamiast jednego' );
		}
	)
);

r117_ok(
	empty( $o_liczbie ),
	'zaden dzial nie jest zglaszany za liczbe plikow dokumentacji',
	count( $o_liczbie ) . ' zgloszen, np.: ' . ( $o_liczbie ? $o_liczbie[0] : '' )
);

/*
 * Kontrola samego testu: gdyby na main nie bylo ANI JEDNEGO katalogu z wieloma
 * plikami, sekcja A przechodzilaby z powodu braku danych, a nie z powodu
 * poprawnej reguly. Sonda ma padac wtedy, kiedy trzeba — takze sama na siebie.
 */
$doki       = (array) ( $zebrane->dane['doki'] ?? array() );
$wielo      = 0;
$przyklad   = '';
foreach ( $doki as $branch => $katalogi ) {
	foreach ( $katalogi as $nazwa => $pliki ) {
		if ( preg_match( '/:meta$/', (string) $nazwa ) ) {
			continue;
		}
		if ( count( (array) $pliki ) > 1 ) {
			++$wielo;
			if ( '' === $przyklad ) {
				$przyklad = $branch . '/docs/' . $nazwa . ' (' . count( (array) $pliki ) . ' plikow)';
			}
		}
	}
}

r117_ok(
	$wielo > 0,
	'kontrola sondy: na main SA katalogi z wieloma plikami, wiec sekcja A cos znaczy',
	'katalogow wielo-plikowych: ' . $wielo
);

echo "\n== B. kontr-asercje: kontrole, ktore sie bronia, zostaja ==\n";

/*
 * Zawezenie reguly nie moze zamienic sie w jej skasowanie. Obie ponizsze
 * kontrole maja dzialac dalej — sprawdzamy to na danych podstawionych, bo na
 * zdrowym repozytorium zadna z nich nie ma prawa nic zglosic.
 */
$podstawione = MP_AU_Wynik::ok(
	array(
		'dzialy' => array( 'mp-lead-intake' => array( 1 ) ),
		'doki'   => array(
			'mp-lead-intake' => array(
				'dzial-01'      => array(),
				'dzial-01:meta' => array(
					array(
						'plik'    => 'mp-lead-intake/docs/dzial-01/bez-daty.md',
						'url'     => 3,
						'data'    => 0,
						'rozmiar' => 100,
					),
				),
			),
		),
	)
);

$wynik2 = $krytyk->ocen( $podstawione, $kontekst );
$opisy2 = array();
foreach ( (array) $wynik2->ustalenia as $u ) {
	$opisy2[] = $u->opis;
}

$ma = static function ( array $lista, string $fragment ) {
	foreach ( $lista as $o ) {
		if ( false !== strpos( $o, $fragment ) ) {
			return true;
		}
	}
	return false;
};

r117_ok(
	$ma( $opisy2, 'nie ma pliku dokumentacji' ),
	'dzial BEZ dokumentacji nadal jest zglaszany',
	'zgloszenia: ' . implode( ' | ', $opisy2 )
);
r117_ok(
	$ma( $opisy2, 'bez daty pobrania' ),
	'cytat z adresem, ale bez daty pobrania, nadal jest zglaszany',
	'zgloszenia: ' . implode( ' | ', $opisy2 )
);

echo "\n== C. na zdrowym repozytorium para milczy ==\n";

r117_ok(
	empty( $opisy ),
	'para 1.17 nie ma dzis zastrzezen do dokumentacji trzech wtyczek',
	count( $opisy ) . ' zgloszen: ' . implode( ' | ', array_slice( $opisy, 0, 3 ) )
);

$ws->sprzataj();

echo "\n== PODSUMOWANIE ==\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
echo ( 0 === $fail ) ? "WYNIK: PASS\n" : "WYNIK: FAIL\n";

exit( 0 === $fail ? 0 : 1 );
