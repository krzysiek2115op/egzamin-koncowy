<?php
/**
 * Test pary 2.9 — drugi sedzia ma zaczynac od ustalen NAJCIEZSZYCH.
 *
 * Uruchamianie: php audyt/tests/sedzia-bierze-najciezsze.php
 *
 * PRZYCZYNA. Agent 2.9 sklada dossier w kolejnosci NAPLYWANIA ustalen, a krytyk
 * bierze z niego tylko tyle paczek, ile pozwala `limit_modelu` (domyslnie 6,
 * czyli przy paczce po 8 sztuk — JEDNA paczke). Do drugiej oceny trafialo wiec
 * pierwszych osiem zgloszen przebiegu, bez wzgledu na wage.
 *
 * DLACZEGO TO BOLI AKURAT TU. Ustalenia ciezkie pochodza z par MODELOWYCH
 * (1.25, 1.26), a te chodza na koncu Dzialu 1. Ustalenie KRYTYCZNE jest wiec
 * strukturalnie ostatnie w kolejce do drugiej oceny — a to wlasnie ono samo
 * jedno przewraca bramke calego audytu na NO GO. Przebieg z 01.08.2026 (kod
 * 1.3.5) skonczyl sie werdyktem NO GO z jednego ustalenia krytycznego, ktorego
 * drugi sedzia w ogole nie widzial; sonda w kodzie pokazala potem, ze bylo
 * falszywym alarmem (Agent 7.2 wtyczki 3 odsiewa takich odbiorcow do
 * `skipped_notifications`, obie sciezki — efekt i follow-up).
 *
 * Kolejnosc w obrebie tej samej wagi zostaje bez zmian: sortowanie ma byc
 * STABILNE, zeby raport dalej dalo sie porownywac miedzy przebiegami.
 *
 * @package MP_Audyt
 */

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/kontrakty.php';
require_once $korzen . '/includes/pomoc.php';
require_once $korzen . '/includes/class-mp-au-workspace.php';
require_once $korzen . '/includes/class-mp-au-model-client.php';
require_once $korzen . '/includes/pary/dzial-02-weryfikacja.php';

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
function sbn_ok( bool $warunek, string $opis, string $info = '' ): void {
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
 * Ustalenie testowe.
 *
 * @param string $para Para zglaszajaca.
 * @param string $opis Opis.
 * @param string $waga Waga.
 * @return MP_AU_Ustalenie
 */
function sbn_ustalenie( string $para, string $opis, string $waga ): MP_AU_Ustalenie {
	$u = new MP_AU_Ustalenie(
		$para,
		$opis,
		$waga,
		array(
			'plik'       => 'mp-sales-workflow/includes/x.php',
			'linia'      => 10,
			'dowod'      => 'dowod ' . $opis,
			'scenariusz' => 'scenariusz ' . $opis,
		)
	);

	return $u;
}

$worktree = dirname( $korzen );
$baza     = sys_get_temp_dir() . '/mp-audyt-2-9-' . getmypid();

$ws = new MP_AU_Workspace( $worktree, $baza, 'refs/heads/main' );
$ws->wystaw();

$kontekst = new MP_AU_Kontekst( $ws, new MP_AU_Model_Client( $ws, sys_get_temp_dir(), 'bez-modelu' ) );

/*
 * Odtwarzamy uklad z realnego przebiegu: najpierw dwanascie ustalen srednich
 * i drobnych z par statycznych, DOPIERO POTEM ustalenie krytyczne z pary
 * modelowej 1.25. Paczka ma osiem sztuk, wiec bez sortowania krytyczne ladowalo
 * w paczce drugiej — czyli poza limitem.
 */
$wsad = array();
for ( $i = 1; $i <= 8; $i++ ) {
	$wsad[] = sbn_ustalenie( '1.3', 'statyczne srednie nr ' . $i, MP_AU_Ustalenie::SREDNIE );
}
for ( $i = 1; $i <= 4; $i++ ) {
	$wsad[] = sbn_ustalenie( '1.7', 'statyczne drobne nr ' . $i, MP_AU_Ustalenie::DROBNE );
}
$krytyczne = sbn_ustalenie( '1.25', 'ustalenie krytyczne z pary modelowej', MP_AU_Ustalenie::KRYTYCZNE );
$wsad[]    = $krytyczne;

$kontekst->dopisz_ustalenia( $wsad );

$agent    = new MP_AU_A29_Sedzia( '2.9', 'dossier-sedziego' );
$zebrane  = $agent->zbierz( $kontekst );
$paczki   = (array) ( $zebrane->dane['paczki'] ?? array() );
$pierwsza = (array) ( $paczki[0] ?? array() );

$klucze_pierwszej = array();
foreach ( $pierwsza as $poz ) {
	$klucze_pierwszej[] = (string) $poz['klucz'];
}

echo "== A. ustalenie krytyczne trafia do PIERWSZEJ paczki ==\n";

sbn_ok(
	in_array( $krytyczne->klucz(), $klucze_pierwszej, true ),
	'krytyczne jest w paczce, ktora na pewno pojdzie do modelu',
	'paczek: ' . count( $paczki ) . ', w pierwszej: '
		. implode( ', ', array_map( static fn( $p ) => $p['para'] . '/' . $p['waga'], $pierwsza ) )
);

sbn_ok(
	! empty( $pierwsza ) && MP_AU_Ustalenie::KRYTYCZNE === (string) $pierwsza[0]['waga'],
	'krytyczne stoi na POCZATKU dossier, nie gdzies w srodku',
	'pierwsze w paczce: ' . ( $pierwsza ? $pierwsza[0]['waga'] . ' (' . $pierwsza[0]['para'] . ')' : 'brak' )
);

echo "\n== B. kolejnosc wewnatrz jednej wagi zostaje nietknieta ==\n";

/*
 * Bez tego sortowanie moglo by byc dowolna permutacja — a raport porownywany
 * miedzy przebiegami przestalby sie dac czytac diffem.
 */
$srednie_opisy = array();
foreach ( $pierwsza as $poz ) {
	if ( MP_AU_Ustalenie::SREDNIE === (string) $poz['waga'] ) {
		$srednie_opisy[] = (string) $poz['opis'];
	}
}

$oczekiwane = array();
for ( $i = 1; $i <= count( $srednie_opisy ); $i++ ) {
	$oczekiwane[] = 'statyczne srednie nr ' . $i;
}

sbn_ok(
	$srednie_opisy === $oczekiwane,
	'srednie zachowuja kolejnosc naplywania (sortowanie jest stabilne)',
	'jest: ' . implode( ' | ', $srednie_opisy )
);

echo "\n== C. kontr-asercje: dobor nie gubi i nie dopuszcza za duzo ==\n";

$wszystkie = array();
foreach ( $paczki as $p ) {
	foreach ( $p as $poz ) {
		$wszystkie[] = (string) $poz['klucz'];
	}
}

sbn_ok(
	count( $wszystkie ) === count( $wsad ),
	'zadne ustalenie nie ginie po drodze — sortowanie to nie filtr',
	'na wejsciu ' . count( $wsad ) . ', w paczkach ' . count( $wszystkie )
);
sbn_ok(
	count( $wszystkie ) === count( array_unique( $wszystkie ) ),
	'zadne ustalenie nie zostaje zdublowane'
);
sbn_ok(
	(int) ( $zebrane->dane['ile'] ?? -1 ) === count( $wsad ),
	'licznik `ile` zgadza sie z zawartoscia paczek',
	'ile=' . (int) ( $zebrane->dane['ile'] ?? -1 )
);

/*
 * Obserwacje nadal maja byc pomijane: model juz raz je obnizyl albo czlowiek
 * ma je rozstrzygnac sam. Sortowanie nie moze wciagnac ich z powrotem.
 */
$kontekst->dopisz_ustalenia( array( sbn_ustalenie( '1.9', 'obserwacja do decyzji czlowieka', MP_AU_Ustalenie::OBSERWACJA ) ) );
$po_obserwacji = $agent->zbierz( $kontekst );

sbn_ok(
	(int) ( $po_obserwacji->dane['ile'] ?? -1 ) === count( $wsad ),
	'obserwacja nie wchodzi do dossier drugiego sedziego',
	'ile=' . (int) ( $po_obserwacji->dane['ile'] ?? -1 )
);

$ws->sprzataj();

echo "\n== PODSUMOWANIE ==\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
echo ( 0 === $fail ) ? "WYNIK: PASS\n" : "WYNIK: FAIL\n";

exit( 0 === $fail ? 0 : 1 );
