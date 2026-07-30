<?php
/**
 * Rdzen pipeline'u audytowego: ustalenie, wynik, kontekst.
 *
 * Trzy male obiekty wartosci trzymamy w jednym pliku SWIADOMIE. Zasada „jedna
 * klasa = jeden plik" ma sens dla klas z logika; dla dwudziestowierszowych
 * nosnikow danych rozbicie na trzy pliki byloby szumem, a nie porzadkiem.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/**
 * Pojedyncze USTALENIE audytu.
 *
 * Celowo niesie wiecej niz „co jest zle". Doswiadczenie z audytu 29.07.2026
 * pokazalo, ze zgloszenie bez `dowod` i bez `scenariusz` jest bezwartosciowe —
 * nie da sie go zweryfikowac ani ocenic wagi, wiec albo sie je przyjmuje na
 * wiare (zle), albo odrzuca bez sprawdzenia (jeszcze gorzej).
 */
final class MP_AU_Ustalenie {

	/** Waga: system nie dziala albo wycieka dane. */
	const KRYTYCZNE = 'krytyczne';

	/** Waga: dziala, ale zle — bledny wynik, luka w odtwarzalnosci. */
	const SREDNIE = 'srednie';

	/** Waga: usterka jakosci, nie zagraza dzialaniu. */
	const DROBNE = 'drobne';

	/** Waga: obserwacja do decyzji czlowieka, nie blad. */
	const OBSERWACJA = 'obserwacja';

	/** Status weryfikacji: sprawdzone drugą metodą. */
	const POTWIERDZONE = 'potwierdzone';

	/** Status weryfikacji: jedna metoda, brak potwierdzenia. */
	const PRAWDOPODOBNE = 'prawdopodobne';

	/** Status weryfikacji: Dzial 2 uznal za falszywy alarm. */
	const ODRZUCONE = 'odrzucone';

	/** @var string Identyfikator pary, ktora zglosila (np. „1.5"). */
	public $para;

	/** @var string Krotki, jednozdaniowy opis problemu. */
	public $opis;

	/** @var string Waga. */
	public $waga;

	/** @var string Plik, ktorego dotyczy (wzgledny wobec korzenia wtyczki). */
	public $plik;

	/** @var int Numer linii; 0 gdy nie dotyczy. */
	public $linia;

	/** @var string Dowod: fragment kodu, wynik zapytania, wyjscie polecenia. */
	public $dowod;

	/** @var string Scenariusz awarii: konkretne wejscie -> konkretny zly skutek. */
	public $scenariusz;

	/** @var string Status weryfikacji. */
	public $status;

	/** @var string[] Identyfikatory ustalen, ktore TO ustalenie maskuje. */
	public $maskuje;

	/** @var string Proponowana naprawa (opis albo diff); pusty gdy brak. */
	public $naprawa;

	/**
	 * @param string $para       Identyfikator pary.
	 * @param string $opis       Opis problemu.
	 * @param string $waga       Waga.
	 * @param array  $dodatkowe  Pola opcjonalne.
	 */
	public function __construct( string $para, string $opis, string $waga, array $dodatkowe = array() ) {
		$this->para       = $para;
		$this->opis       = $opis;
		$this->waga       = $waga;
		$this->plik       = (string) ( $dodatkowe['plik'] ?? '' );
		$this->linia      = (int) ( $dodatkowe['linia'] ?? 0 );
		$this->dowod      = (string) ( $dodatkowe['dowod'] ?? '' );
		$this->scenariusz = (string) ( $dodatkowe['scenariusz'] ?? '' );
		$this->status     = (string) ( $dodatkowe['status'] ?? self::PRAWDOPODOBNE );
		$this->maskuje    = (array) ( $dodatkowe['maskuje'] ?? array() );
		$this->naprawa    = (string) ( $dodatkowe['naprawa'] ?? '' );
	}

	/**
	 * Stabilny identyfikator ustalenia — ten sam problem w kolejnym przebiegu
	 * musi dostac ten sam klucz, inaczej porownanie przebiegow nic nie da.
	 *
	 * @return string
	 */
	public function klucz(): string {
		return $this->para . ':' . md5( $this->plik . '|' . $this->linia . '|' . $this->opis );
	}

	/**
	 * @return array
	 */
	public function do_tablicy(): array {
		return array(
			'klucz'      => $this->klucz(),
			'para'       => $this->para,
			'opis'       => $this->opis,
			'waga'       => $this->waga,
			'plik'       => $this->plik,
			'linia'      => $this->linia,
			'dowod'      => $this->dowod,
			'scenariusz' => $this->scenariusz,
			'status'     => $this->status,
			'maskuje'    => $this->maskuje,
			'naprawa'    => $this->naprawa,
		);
	}
}

/**
 * Wynik pracy agenta albo krytyka.
 *
 * Trzy stany, nie dwa. `NIEOCENIONE` istnieje, bo kontrola, ktorej nie dalo sie
 * wykonac (brak narzedzia, brak srodowiska, brak modelu), NIE MOZE raportowac
 * sukcesu. Falszywe „zielone" zamyka temat i jest grozniejsze niz brak wyniku —
 * w tym projekcie jeden test przechodzil FALSZYWIE, bo straznik zwracal zera,
 * a test wlasnie zer oczekiwal.
 */
final class MP_AU_Wynik {

	const OK           = 'ok';
	const BLAD         = 'blad';
	const NIEOCENIONE  = 'nieocenione';

	/** @var string */
	public $stan;

	/** @var string Powod — wymagany przy BLAD i NIEOCENIONE. */
	public $powod;

	/** @var MP_AU_Ustalenie[] */
	public $ustalenia;

	/** @var array Dowolne dane robocze przekazywane dalej. */
	public $dane;

	/**
	 * @param string $stan      Stan.
	 * @param string $powod     Powod.
	 * @param array  $ustalenia Ustalenia.
	 * @param array  $dane      Dane robocze.
	 */
	private function __construct( string $stan, string $powod, array $ustalenia, array $dane ) {
		$this->stan      = $stan;
		$this->powod     = $powod;
		$this->ustalenia = $ustalenia;
		$this->dane      = $dane;
	}

	/**
	 * @param array $dane      Dane robocze.
	 * @param array $ustalenia Ustalenia.
	 * @return MP_AU_Wynik
	 */
	public static function ok( array $dane = array(), array $ustalenia = array() ): MP_AU_Wynik {
		return new self( self::OK, '', $ustalenia, $dane );
	}

	/**
	 * @param string $powod     Powod.
	 * @param array  $ustalenia Ustalenia.
	 * @param array  $dane      Dane robocze.
	 * @return MP_AU_Wynik
	 */
	public static function blad( string $powod, array $ustalenia = array(), array $dane = array() ): MP_AU_Wynik {
		return new self( self::BLAD, $powod, $ustalenia, $dane );
	}

	/**
	 * Kontrola niewykonana — z podaniem, CZEGO zabraklo.
	 *
	 * @param string $powod Czego zabraklo.
	 * @return MP_AU_Wynik
	 */
	public static function nieocenione( string $powod ): MP_AU_Wynik {
		return new self( self::NIEOCENIONE, $powod, array(), array() );
	}

	/**
	 * @return bool
	 */
	public function czy_ok(): bool {
		return self::OK === $this->stan;
	}
}

/**
 * Kontekst przebiegu — wspolna tablica dla wszystkich par.
 *
 * Liczy tez odczyty, zeby dalo sie wykazac, ze agent nie skanuje repozytorium
 * po raz dziesiaty (ta sama dyscyplina, co „jeden strzal odczytu" w Dziale 2
 * wtyczki 3).
 */
final class MP_AU_Kontekst {

	/** @var array */
	private $dane = array();

	/** @var MP_AU_Ustalenie[] */
	private $ustalenia = array();

	/** @var int */
	private $odczyty = 0;

	/** @var MP_AU_Workspace */
	public $workspace;

	/** @var MP_AU_Model_Client */
	public $model;

	/** @var string Tryb przebiegu: „audyt" albo „weryfikacja"/„potwierdzenie". */
	public $tryb;

	/**
	 * @param MP_AU_Workspace    $workspace Dostep do kodu trzech wtyczek.
	 * @param MP_AU_Model_Client $model     Adapter modelu.
	 * @param string             $tryb      Tryb przebiegu.
	 */
	public function __construct( MP_AU_Workspace $workspace, MP_AU_Model_Client $model, string $tryb = 'audyt' ) {
		$this->workspace = $workspace;
		$this->model     = $model;
		$this->tryb      = $tryb;
	}

	/**
	 * @param string $klucz    Klucz.
	 * @param mixed  $domyslna Wartosc domyslna.
	 * @return mixed
	 */
	public function pobierz( string $klucz, $domyslna = null ) {
		return $this->dane[ $klucz ] ?? $domyslna;
	}

	/**
	 * @param string $klucz   Klucz.
	 * @param mixed  $wartosc Wartosc.
	 * @return void
	 */
	public function ustaw( string $klucz, $wartosc ): void {
		$this->dane[ $klucz ] = $wartosc;
	}

	/**
	 * @param MP_AU_Ustalenie[] $ustalenia Ustalenia do dopisania.
	 * @return void
	 */
	public function dopisz_ustalenia( array $ustalenia ): void {
		foreach ( $ustalenia as $u ) {
			$this->ustalenia[ $u->klucz() ] = $u;
		}
	}

	/**
	 * @return MP_AU_Ustalenie[]
	 */
	public function ustalenia(): array {
		return array_values( $this->ustalenia );
	}

	/**
	 * @return void
	 */
	public function policz_odczyt(): void {
		++$this->odczyty;
	}

	/**
	 * @return int
	 */
	public function odczyty(): int {
		return $this->odczyty;
	}
}
