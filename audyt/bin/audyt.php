<?php
/**
 * Punkt wejscia audytu calego projektu.
 *
 * Uruchomienie:
 *   php audyt/bin/audyt.php [--repo=SCIEZKA] [--bez-modelu] [--json]
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
require_once $korzen . '/includes/class-mp-au-workspace.php';
require_once $korzen . '/includes/class-mp-au-model-client.php';
require_once $korzen . '/includes/class-mp-au-raport.php';
require_once $korzen . '/includes/departments/class-mp-au-department-01.php';
require_once $korzen . '/includes/departments/class-mp-au-department-02.php';

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

$repo = au_arg( 'repo', dirname( dirname( $korzen ) ) );

if ( ! is_dir( $repo . '/.git' ) ) {
	fwrite( STDERR, "Nie znaleziono repozytorium w: {$repo}\nUzyj --repo=SCIEZKA.\n" );
	exit( 2 );
}

$katalog_roboczy = sys_get_temp_dir() . '/mp-audyt-' . getmypid();
$katalog_raportu = $korzen . '/raporty';

echo "=== AUDYT PROJEKTU: 3 wtyczki, 3 bazy danych ===\n";
echo 'repozytorium: ' . $repo . "\n";

$workspace = new MP_AU_Workspace( $repo, $katalog_roboczy );
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

echo 'model: ' . $model->tryb();
echo $model->dostepny() ? "\n" : ' (' . $model->powod_niedostepnosci() . ")\n";
echo "\n";

$kontekst = new MP_AU_Kontekst( $workspace, $model, 'weryfikacja' );
$przebiegi = array();

$dzialy = array( MP_AU_Dzial_01::zbuduj(), MP_AU_Dzial_02::zbuduj() );

foreach ( $dzialy as $dzial ) {
	echo '--- Dzial ' . $dzial->numer() . ': ' . $dzial->nazwa() . ' (' . $dzial->ile_par() . " par) ---\n";

	$przebieg    = $dzial->uruchom( $kontekst );
	$przebiegi[] = $przebieg;

	foreach ( $przebieg['pary'] as $para ) {
		$znacznik = 'ok' === $para['stan'] ? ' OK ' : ( 'nieocenione' === $para['stan'] ? 'NIE?' : 'BLAD' );
		printf( "  [%s] %-6s %-42s %s\n", $znacznik, $para['para'], $para['nazwa'], 0 === $para['ustalenia'] ? '' : $para['ustalenia'] . ' ustalen' );

		if ( 'nieocenione' === $para['stan'] ) {
			echo '         powod: ' . $para['powod'] . "\n";
		}
	}

	printf(
		"  bramka: %d/%d par wykonanych, pokrycie %.0f%% -> %s\n\n",
		$przebieg['bramka']['par_wykonanych'],
		$przebieg['bramka']['par_wszystkich'],
		$przebieg['bramka']['pokrycie'] * 100,
		$przebieg['bramka']['zaliczona'] ? 'ZALICZONA' : 'NIEZALICZONA'
	);
}

$raport = new MP_AU_Raport( $kontekst, $przebiegi, $wystawione );
$raport->zapisz( $katalog_raportu );

echo $raport->podsumowanie_tekstowe();

$workspace->sprzataj();

// Kod wyjscia dla CI: 1 gdy sa ustalenia krytyczne, 0 w przeciwnym razie.
exit( $raport->ma_krytyczne() ? 1 : 0 );
