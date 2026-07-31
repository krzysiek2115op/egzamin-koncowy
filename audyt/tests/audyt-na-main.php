<?php
/**
 * Test: audyt musi dac sie uruchomic na scalonym `main`, a nie tylko na trzech
 * galeziach wtyczek.
 *
 * Uruchamianie: php audyt/tests/audyt-na-main.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   AU-1  wykrywanie repozytorium odrzucalo worktree
 *   AU-2  workspace wystawial wylacznie trzy galezie wtyczek
 *
 * Skad te dwa bledy. Narzedzie powstalo, gdy kazda wtyczka zyla na wlasnej
 * galezi, i oba zalozenia byly wtedy prawdziwe. Po scaleniu do `main` przestaly
 * byc: kod produkcyjny lezy teraz w jednym miejscu, a audyt trzech galezi
 * zaczal opisywac stan historyczny. Do tego `is_dir( $repo . '/.git' )` odrzucalo
 * kazdy worktree — bo tam `.git` jest PLIKIEM ze wskaznikiem, nie katalogiem —
 * czyli dokladnie te forme repozytorium, w ktorej pracuje sie nad kilkoma
 * galeziami naraz.
 *
 * Test A sprawdza wykrywanie repozytorium w obu formach.
 * Test B sprawdza, ze wskazanie jednego ref-a naprawde zmienia zachowanie:
 * trzy katalogi wtyczek pochodza z tego samego commita. Kontr-asercja pilnuje,
 * zeby „naprawa" nie polegala na zignorowaniu parametru — bez niego czubki
 * trzech galezi musza sie nadal roznic.
 *
 * @package MP_Audyt
 */

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/kontrakty.php';
require_once $korzen . '/includes/pomoc.php';
require_once $korzen . '/includes/class-mp-au-workspace.php';

$pass = 0;
$fail = 0;

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @return void
 */
function au_ok( bool $warunek, string $opis ): void {
	global $pass, $fail;

	if ( $warunek ) {
		++$pass;
		echo '  [PASS] ' . $opis . "\n";
		return;
	}

	++$fail;
	echo '  [FAIL] ' . $opis . "\n";
}

$repo     = dirname( dirname( $korzen ) );
$worktree = dirname( $korzen );

echo "== A. wykrywanie repozytorium ==\n";

au_ok(
	method_exists( 'MP_AU_Pomoc', 'czy_repozytorium' ),
	'MP_AU_Pomoc::czy_repozytorium() istnieje'
);

if ( method_exists( 'MP_AU_Pomoc', 'czy_repozytorium' ) ) {
	au_ok(
		MP_AU_Pomoc::czy_repozytorium( $worktree ),
		'worktree jest rozpoznany jako repozytorium (.git to plik, nie katalog)'
	);

	// Kontrola od drugiej strony: zwykly katalog nadal ma byc odrzucony,
	// zeby „naprawa" nie polegala na przepuszczaniu wszystkiego.
	au_ok(
		! MP_AU_Pomoc::czy_repozytorium( sys_get_temp_dir() ),
		'katalog bez gita nadal odrzucony'
	);

	au_ok(
		is_dir( $repo . '/.git' ) ? MP_AU_Pomoc::czy_repozytorium( $repo ) : true,
		'zwykly korzen repozytorium nadal rozpoznany'
	);
}

echo "\n== B. workspace z jednego ref-a ==\n";

$baza = sys_get_temp_dir() . '/mp-audyt-test-' . getmypid();

$refleksja = new ReflectionMethod( 'MP_AU_Workspace', '__construct' );
au_ok(
	$refleksja->getNumberOfParameters() >= 3,
	'MP_AU_Workspace przyjmuje wskazanie ref-a'
);

if ( $refleksja->getNumberOfParameters() >= 3 ) {
	$ws         = new MP_AU_Workspace( $worktree, $baza . '-main', 'refs/heads/main' );
	$wystawione = $ws->wystaw();

	au_ok( 3 === count( $wystawione ), 'wystawione trzy katalogi wtyczek' );

	$sha_z_maina = array();

	foreach ( $wystawione as $branch => $info ) {
		au_ok(
			isset( $info['istnieje'] ) && $info['istnieje'],
			$branch . ': katalog wtyczki istnieje w main'
		);

		if ( isset( $info['sha'] ) ) {
			$sha_z_maina[] = $info['sha'];
		}
	}

	au_ok(
		3 === count( $sha_z_maina ) && 1 === count( array_unique( $sha_z_maina ) ),
		'wszystkie trzy pochodza z JEDNEGO commita: ' . implode( ', ', array_unique( $sha_z_maina ) )
	);

	$ws->sprzataj();
}

// Kontr-asercja: bez wskazania ref-a zachowanie ma zostac stare — trzy rozne
// czubki. Gdyby parametr byl ignorowany albo gdyby domyslka tez wskazywala
// main, powyzszy test przechodzilby bez zadnej realnej zmiany.
$ws_stary   = new MP_AU_Workspace( $worktree, $baza . '-galezie' );
$wystawione = $ws_stary->wystaw();
$sha_galezi = array();

foreach ( $wystawione as $info ) {
	if ( isset( $info['sha'] ) ) {
		$sha_galezi[] = $info['sha'];
	}
}

au_ok(
	3 === count( $sha_galezi ) && count( array_unique( $sha_galezi ) ) > 1,
	'bez wskazania ref-a nadal audytowane sa osobne galezie'
);

$ws_stary->sprzataj();

echo "\n== PODSUMOWANIE ==\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
echo ( 0 === $fail ) ? "WYNIK: PASS\n" : "WYNIK: FAIL\n";

exit( 0 === $fail ? 0 : 1 );
