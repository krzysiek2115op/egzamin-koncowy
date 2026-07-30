<?php
/**
 * Punkt wejscia audytu calego projektu.
 *
 * Uruchomienie:
 *   php audyt/bin/audyt.php [--repo=SCIEZKA] [--glebokosc=szybki|pelny|gleboki]
 *                           [--bez-modelu] [--limit-modelu=N]
 *
 * Narzedzie NIE jest wtyczka WordPress i nie wymaga dzialajacej instalacji.
 * Samo wystawia worktree trzech branchy i audytuje ich aktualne czubki.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "Audyt uruchamia sie wylacznie z wiersza polecen.\n" );
	exit( 2 );
}

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/kontrakty.php';
require_once $korzen . '/includes/pomoc.php';
require_once $korzen . '/includes/class-mp-au-workspace.php';
require_once $korzen . '/includes/class-mp-au-model-client.php';
require_once $korzen . '/includes/class-mp-au-raport.php';
require_once $korzen . '/includes/departments/class-mp-au-department-01.php';
require_once $korzen . '/includes/departments/class-mp-au-department-02.php';
require_once $korzen . '/includes/pary/dzial-01-jakosc.php';
require_once $korzen . '/includes/pary/dzial-01-integracja.php';
require_once $korzen . '/includes/pary/dzial-01-bezpieczenstwo.php';
require_once $korzen . '/includes/pary/dzial-01-dane.php';
require_once $korzen . '/includes/pary/dzial-01-model.php';
require_once $korzen . '/includes/pary/dzial-02-weryfikacja.php';

/**
 * Prosty odczyt argumentow.
 *
 * @param string $nazwa    Nazwa argumentu.
 * @param string $domyslna Wartosc domyslna.
 * @return string
 */
function au_arg( string $nazwa, string $domyslna = '' ): string {
	foreach ( $GLOBALS['argv'] as $arg ) {
		if ( 0 === strpos( $arg, '--' . $nazwa . '=' ) ) {
			return substr( $arg, strlen( $nazwa ) + 3 );
		}

		if ( '--' . $nazwa === $arg ) {
			return '1';
		}
	}

	return $domyslna;
}

/**
 * Zamienia nazwe glebokosci na numer.
 *
 * @param string $nazwa Nazwa.
 * @return int
 */
function au_glebokosc( string $nazwa ): int {
	switch ( strtolower( $nazwa ) ) {
		case 'szybki':
			return MP_AU_Para::SZYBKI;

		case 'gleboki':
		case 'głęboki':
			return MP_AU_Para::GLEBOKI;

		default:
			return MP_AU_Para::PELNY;
	}
}

$repo = au_arg( 'repo', dirname( dirname( $korzen ) ) );

if ( ! is_dir( $repo . '/.git' ) ) {
	fwrite( STDERR, "Nie znaleziono repozytorium w: {$repo}\nUzyj --repo=SCIEZKA.\n" );
	exit( 2 );
}

$nazwa_glebokosci = au_arg( 'glebokosc', 'pelny' );
$glebokosc        = au_glebokosc( $nazwa_glebokosci );
$katalog_roboczy  = sys_get_temp_dir() . '/mp-audyt-' . getmypid();
$katalog_raportu  = $korzen . '/raporty';
$start_calosci    = microtime( true );

echo "=== AUDYT PROJEKTU: 3 wtyczki, 3 bazy danych ===\n";
echo 'repozytorium: ' . $repo . "\n";
echo 'glebokosc:    ' . $nazwa_glebokosci . ' (' . $glebokosc . '/3)';
echo MP_AU_Para::GLEBOKI === $glebokosc
	? " — komplet kontroli, z ocena modelu\n"
	: ( MP_AU_Para::PELNY === $glebokosc
		? " — z narzedziami zewnetrznymi, bez oceny modelu\n"
		: " — tylko analiza statyczna\n" );

$workspace  = new MP_AU_Workspace( $repo, $katalog_roboczy );
$wystawione = $workspace->wystaw();

foreach ( $wystawione as $branch => $info ) {
	if ( isset( $info['blad'] ) ) {
		echo "  [BLAD] {$branch}: {$info['blad']}\n";
		continue;
	}

	echo "  {$branch} @ {$info['sha']} -> " . ( $info['istnieje'] ? 'ok' : 'BRAK KATALOGU WTYCZKI' ) . "\n";
}

$tryb_modelu = '1' === au_arg( 'bez-modelu' ) ? MP_AU_Model_Client::TRYB_BRAK : '';
$model       = new MP_AU_Model_Client( $workspace, $katalog_raportu . '/dossier', $tryb_modelu );

echo 'model:        ' . $model->tryb();
echo $model->dostepny() ? "\n" : ' (' . $model->powod_niedostepnosci() . ")\n";
echo "\n";

$kontekst = new MP_AU_Kontekst( $workspace, $model, 'audyt', $glebokosc );
/*
 * Domyslnie 6 pytan na pare modelowa. Jedno pytanie o dzial pipeline'u trwa na
 * tej maszynie okolo 2 minut, wiec 6 + 6 + jedna paczka drugiego sedziego daje
 * przebieg w okolicach POL GODZINY. Kto chce kompletu wszystkich dzialow, podnosi
 * ten limit i placi czasem — narzedzie nie udaje, ze zdazy z tym w minute.
 */
$kontekst->ustaw( 'limit_modelu', (int) au_arg( 'limit-modelu', '6' ) );

// Para 2.8 porownuje sie z POPRZEDNIM raportem — musi poznac jego sciezke,
// zanim biezacy przebieg go nadpisze.
$kontekst->ustaw( 'poprzedni_raport', $katalog_raportu . '/raport-ostatni.json' );

$przebiegi = array();
$dzial_1   = MP_AU_Dzial_01::zbuduj();
$dzial_2   = MP_AU_Dzial_02::zbuduj();

// Para 2.4 powtarza przebieg Dzialu 1, wiec potrzebuje samego obiektu dzialu.
$kontekst->ustaw( 'dzial_1', $dzial_1 );

foreach ( array( $dzial_1, $dzial_2 ) as $dzial ) {
	echo '--- Dzial ' . $dzial->numer() . ': ' . $dzial->nazwa() . ' (' . $dzial->ile_par() . " par) ---\n";

	$przebieg    = $dzial->uruchom( $kontekst );
	$przebiegi[] = $przebieg;

	foreach ( $przebieg['pary'] as $para ) {
		switch ( $para['stan'] ) {
			case 'ok':
				$znacznik = ' OK ';
				break;

			case 'nieocenione':
				$znacznik = 'NIE?';
				break;

			case 'pominieta':
				$znacznik = 'POMI';
				break;

			default:
				$znacznik = 'BLAD';
		}

		printf(
			"  [%s] %-5s %-40s %8s  %s\n",
			$znacznik,
			$para['para'],
			$para['nazwa'],
			$para['czas'] > 0.05 ? number_format( (float) $para['czas'], 1 ) . 's' : '',
			0 === $para['ustalenia'] ? '' : $para['ustalenia'] . ' ustalen'
		);

		if ( in_array( $para['stan'], array( 'nieocenione', 'pominieta' ), true ) ) {
			echo '         powod: ' . $para['powod'] . "\n";
		}
	}

	printf(
		"  bramka: %d/%d par wykonanych%s, pokrycie %.0f%% -> %s   [%.1fs]\n\n",
		$przebieg['bramka']['par_wykonanych'],
		$przebieg['bramka']['par_wszystkich'],
		$przebieg['bramka']['par_pominietych'] > 0 ? ', ' . $przebieg['bramka']['par_pominietych'] . ' pominietych' : '',
		$przebieg['bramka']['pokrycie'] * 100,
		$przebieg['bramka']['zaliczona'] ? 'ZALICZONA' : 'NIEZALICZONA',
		$przebieg['czas']
	);
}

$raport = new MP_AU_Raport( $kontekst, $przebiegi, $wystawione );
$raport->ustaw_przebieg(
	array(
		'glebokosc'  => $nazwa_glebokosci,
		'poziom'     => $glebokosc,
		'czas_total' => round( microtime( true ) - $start_calosci, 1 ),
	)
);
$raport->zapisz( $katalog_raportu );

echo $raport->podsumowanie_tekstowe();

$workspace->sprzataj();

// Kod wyjscia dla CI: 1 gdy sa ustalenia krytyczne, 0 w przeciwnym razie.
exit( $raport->ma_krytyczne() ? 1 : 0 );
