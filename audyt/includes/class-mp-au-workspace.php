<?php
/**
 * Dostep do kodu trzech wtyczek naraz.
 *
 * Problem, ktory ta klasa rozwiazuje: kazdy branch projektu zawiera TYLKO swoja
 * wtyczke. `mp-lead-intake` nie widzi `mp-offer-builder`, a audyt calosci musi
 * widziec wszystkie trzy jednoczesnie — bo najgrozniejsze bledy tego projektu
 * leza dokladnie NA GRANICY miedzy wtyczkami (niejawny COMMIT cudzej
 * transakcji, dane RODO wracajace z sasiedniej bazy, wspoldzielone role).
 *
 * Rozwiazanie: `git worktree`. Narzedzie samo wystawia czubki trzech branchy
 * w katalogu tymczasowym i audytuje TO, CO JEST W REPO — nie to, co akurat lezy
 * na dysku dewelopera. Roznica jest istotna: audyt ma odpowiadac za stan, ktory
 * trafi do klienta.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/**
 * Wystawia i sprzata worktree trzech branchy.
 */
final class MP_AU_Workspace {

	/** Branch -> katalog wtyczki w tym branchu. */
	const WTYCZKI = array(
		'mp-lead-intake'    => 'mp-lead-intake',
		'mp-offer-builder'  => 'mp-offer-builder',
		'mp-sales-workflow' => 'mp-sales-workflow',
	);

	/** @var string Korzen repozytorium. */
	private $repo;

	/** @var string Katalog na worktree. */
	private $baza;

	/** @var array<string,string> Branch -> sciezka katalogu wtyczki. */
	private $sciezki = array();

	/** @var array<string,string> Branch -> SHA czubka. */
	private $czubki = array();

	/** @var string|null Wspolny ref dla wszystkich wtyczek albo null. */
	private $ref = null;

	/** @var bool */
	private $wystawione = false;

	/** @var string[] Cache tresci plikow — jeden odczyt na plik w calym przebiegu. */
	private $cache = array();

	/**
	 * @param string      $repo Korzen repozytorium.
	 * @param string      $baza Katalog na worktree (tworzony, gdy nie istnieje).
	 * @param string|null $ref  Jeden ref jako zrodlo wszystkich trzech wtyczek
	 *                          (np. `refs/heads/main`). Bez tego kazda wtyczka
	 *                          pochodzi z wlasnej galezi — tak dzialalo, zanim
	 *                          galezie zostaly scalone.
	 */
	public function __construct( string $repo, string $baza, ?string $ref = null ) {
		$this->repo = rtrim( $repo, '/' );
		$this->baza = rtrim( $baza, '/' );
		$this->ref  = $ref;
	}

	/**
	 * Wystawia worktree trzech branchy — albo trzy razy ten sam ref, gdy
	 * wskazano go w konstruktorze (po scaleniu kod produkcyjny lezy na `main`,
	 * a galezie wtyczek opisuja juz tylko stan historyczny).
	 *
	 * @return array Raport z wystawienia (branch -> sha albo blad).
	 */
	public function wystaw(): array {
		$raport = array();

		if ( ! is_dir( $this->baza ) && ! mkdir( $this->baza, 0777, true ) && ! is_dir( $this->baza ) ) {
			return array( 'blad' => 'Nie udalo sie utworzyc katalogu roboczego: ' . $this->baza );
		}

		foreach ( self::WTYCZKI as $branch => $katalog ) {
			$cel = $this->baza . '/' . $branch;

			// Worktree z poprzedniego przebiegu: usuwamy, zeby audytowac
			// AKTUALNY czubek, a nie stan sprzed tygodnia.
			if ( is_dir( $cel ) ) {
				$this->polecenie( array( 'git', '-C', $this->repo, 'worktree', 'remove', '--force', $cel ) );
			}

			$zrodlo = null === $this->ref ? 'refs/heads/' . $branch : $this->ref;

			$wynik = $this->polecenie(
				array( 'git', '-C', $this->repo, 'worktree', 'add', '--detach', $cel, $zrodlo )
			);

			if ( 0 !== $wynik['kod'] ) {
				$raport[ $branch ] = array( 'blad' => trim( $wynik['wyjscie'] ) );
				continue;
			}

			$sha = $this->polecenie( array( 'git', '-C', $cel, 'rev-parse', 'HEAD' ) );

			$this->sciezki[ $branch ] = $cel . '/' . $katalog;
			$this->czubki[ $branch ]  = trim( $sha['wyjscie'] );

			$raport[ $branch ] = array(
				'sha'      => substr( $this->czubki[ $branch ], 0, 7 ),
				'katalog'  => $this->sciezki[ $branch ],
				'istnieje' => is_dir( $this->sciezki[ $branch ] ),
			);
		}

		$this->wystawione = true;

		return $raport;
	}

	/**
	 * Sprzata worktree — audyt nie ma prawa zostawiac smieci w repozytorium.
	 *
	 * @return void
	 */
	public function sprzataj(): void {
		if ( ! $this->wystawione ) {
			return;
		}

		foreach ( array_keys( self::WTYCZKI ) as $branch ) {
			$cel = $this->baza . '/' . $branch;

			if ( is_dir( $cel ) ) {
				$this->polecenie( array( 'git', '-C', $this->repo, 'worktree', 'remove', '--force', $cel ) );
			}
		}

		$this->polecenie( array( 'git', '-C', $this->repo, 'worktree', 'prune' ) );
	}

	/**
	 * @return string[] Nazwy branchy.
	 */
	public function branche(): array {
		return array_keys( $this->sciezki );
	}

	/**
	 * @param string $branch Branch.
	 * @return string Sciezka katalogu wtyczki.
	 */
	public function katalog( string $branch ): string {
		return $this->sciezki[ $branch ] ?? '';
	}

	/**
	 * Korzen drzewa, na ktorym pracuje ten przebieg.
	 *
	 * W trybie jednego refa katalogi wtyczek sa podkatalogami wspolnego
	 * wystawienia, wiec ich rodzic jest korzeniem repozytorium. W trybie trzech
	 * galezi kazda wtyczka ma osobne wystawienie i wspolnego korzenia nie ma —
	 * zwracamy wtedy pusty lancuch, zeby wolajacy wiedzial, ze sciezki od
	 * korzenia repo nie mozna rozwiazac, zamiast dostac katalog przypadkowej
	 * wtyczki.
	 *
	 * @return string
	 */
	public function korzen(): string {
		if ( null === $this->ref ) {
			return '';
		}

		$pierwszy = reset( $this->sciezki );

		return false === $pierwszy ? '' : dirname( (string) $pierwszy );
	}

	/**
	 * @param string $branch Branch.
	 * @return string
	 */
	public function czubek( string $branch ): string {
		return $this->czubki[ $branch ] ?? '';
	}

	/**
	 * Pliki PHP wtyczki, z pominieciem `vendor/` i `node_modules/`.
	 *
	 * `vendor/` pomijamy swiadomie: to cudzy kod na wlasnych licencjach, ktorego
	 * nie audytujemy ani nie poprawiamy. Kontrola licencji zaleznosci to osobna
	 * para (1.14) i patrzy na `composer.json`, nie na zrodla.
	 *
	 * @param string $branch    Branch.
	 * @param bool   $bez_testow Czy pominac katalog `tests/`.
	 * @return string[] Sciezki bezwzgledne.
	 */
	public function pliki_php( string $branch, bool $bez_testow = false ): array {
		$korzen = $this->katalog( $branch );

		if ( '' === $korzen || ! is_dir( $korzen ) ) {
			return array();
		}

		$znalezione = array();
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $korzen, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $plik ) {
			$sciezka = $plik->getPathname();

			if ( 'php' !== strtolower( $plik->getExtension() ) ) {
				continue;
			}

			if ( false !== strpos( $sciezka, '/vendor/' ) || false !== strpos( $sciezka, '/node_modules/' ) ) {
				continue;
			}

			if ( $bez_testow && false !== strpos( $sciezka, '/tests/' ) ) {
				continue;
			}

			$znalezione[] = $sciezka;
		}

		sort( $znalezione );

		return $znalezione;
	}

	/**
	 * Tresc pliku — z pamiecia podreczna.
	 *
	 * Pary czytaja te same pliki po kilka razy (inwentarz, SQL, haki, RODO).
	 * Bez cache audyt calosci robilby kilkanascie tysiecy odczytow dysku.
	 *
	 * @param string              $sciezka  Sciezka.
	 * @param MP_AU_Kontekst|null $kontekst Kontekst (do liczenia odczytow).
	 * @return string
	 */
	public function tresc( string $sciezka, ?MP_AU_Kontekst $kontekst = null ): string {
		if ( ! isset( $this->cache[ $sciezka ] ) ) {
			$this->cache[ $sciezka ] = is_readable( $sciezka ) ? (string) file_get_contents( $sciezka ) : '';

			if ( null !== $kontekst ) {
				$kontekst->policz_odczyt();
			}
		}

		return $this->cache[ $sciezka ];
	}

	/**
	 * Sciezki plikow, ktore w tym przebiegu w ogole ktos przeczytal.
	 *
	 * Sluzy parze 2.7: plik, do ktorego nie zajrzala ZADNA para, jest martwym
	 * polem audytu. Raport milczacy o takim pliku nie mowi „jest dobrze", tylko
	 * „nie wiem" — i musi to powiedziec wprost.
	 *
	 * @return string[]
	 */
	public function odczytane(): array {
		return array_keys( $this->cache );
	}

	/**
	 * Sciezka wzgledna wobec korzenia worktree — do raportu.
	 *
	 * @param string $sciezka Sciezka bezwzgledna.
	 * @return string
	 */
	public function wzgledna( string $sciezka ): string {
		$wzgledna = ltrim( str_replace( $this->baza, '', $sciezka ), '/' );

		// Katalog worktree i katalog wtyczki nazywaja sie tak samo, wiec sciezka
		// wychodzila jako „mp-offer-builder/mp-offer-builder/includes/...".
		// Powtorzenie nie niesie zadnej informacji, a wyglada jak blad narzedzia
		// — i pierwsze, co robi czytajacy, to podwaza caly raport.
		$czesci = explode( '/', $wzgledna );

		if ( count( $czesci ) > 1 && $czesci[0] === $czesci[1] ) {
			array_shift( $czesci );
		}

		return implode( '/', $czesci );
	}

	/**
	 * Uruchamia polecenie bez powloki (brak ryzyka wstrzykniecia).
	 *
	 * @param string[] $argumenty Argumenty.
	 * @return array{kod:int,wyjscie:string}
	 */
	public function polecenie( array $argumenty ): array {
		$deskryptory = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$proces = proc_open( $argumenty, $deskryptory, $rury );

		if ( ! is_resource( $proces ) ) {
			return array(
				'kod'     => 127,
				'wyjscie' => 'Nie udalo sie uruchomic: ' . implode( ' ', $argumenty ),
			);
		}

		$stdout = (string) stream_get_contents( $rury[1] );
		$stderr = (string) stream_get_contents( $rury[2] );

		fclose( $rury[1] );
		fclose( $rury[2] );

		return array(
			'kod'     => proc_close( $proces ),
			'wyjscie' => $stdout . $stderr,
		);
	}
}
