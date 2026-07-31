<?php
/**
 * Adapter modelu — ocena tam, gdzie regula nie rozstrzyga.
 *
 * Po co to istnieje. Audyt z 29.07.2026 znalazl osiem bledow krytycznych,
 * ale tylko czesc z nich dalo sie zapisac jako regule. Reszta wymagala zdania
 * typu „ta cena jest liczona drugi raz", „ten status klamie o tym, co sie
 * stalo" — czyli SADU, nie wzorca. Kod PHP sam z siebie tego nie powtorzy.
 *
 * Adapter probuje kolejnych drog dostepu do modelu i konczy na uczciwym
 * „niedostepny", zamiast udawac ocene:
 *   1. `claude -p` — lokalna instalacja Claude Code (bez dodatkowych kluczy),
 *   2. Anthropic API — gdy w srodowisku jest ANTHROPIC_API_KEY,
 *   3. tryb reczny — dossier zapisywane do pliku dla czlowieka.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/**
 * Klient modelu.
 */
final class MP_AU_Model_Client {

	/** Tryb: lokalne Claude Code. */
	const TRYB_CLI = 'claude-cli';

	/** Tryb: Anthropic API. */
	const TRYB_API = 'api';

	/** Tryb: zapis dossier do pliku, ocena przez czlowieka. */
	const TRYB_RECZNY = 'reczny';

	/** Tryb: brak dostepu. */
	const TRYB_BRAK = 'brak';

	/*
	 * Limit czasu jednego zapytania. 180 s bylo za malo: dossier dzialu wazacego
	 * 25 kB przechodzi w ~155 s, wiec margines wynosil kilkanascie sekund i kazde
	 * chwilowe spowolnienie konczylo sie „brakiem odpowiedzi".
	 */
	const LIMIT_CZASU = 600;

	/** Ile razy sprobowac zapytania, ktore wrocilo puste albo bledne. */
	const PROBY = 2;

	/*
	 * Plik blokady. Dwa rownolegle wywolania `claude -p` konczyly sie „Execution
	 * error" po obu stronach, a to samo dossier uruchomione samotnie przechodzilo
	 * bez zarzutu. Zapytania ustawiaja sie wiec w kolejce — takze miedzy osobnymi
	 * procesami audytu, bo blokada jest plikiem, nie zmienna.
	 */
	const BLOKADA = '/tmp/mp-au-model.lock';

	/** Najdluzsze dopuszczalne czekanie na zwolnienie blokady (sekundy). */
	const LIMIT_BLOKADY = 900;

	/** @var string */
	private $tryb = self::TRYB_BRAK;

	/** @var string */
	private $powod = '';

	/** @var string Sciezka binarki `claude`. */
	private $binarka = '';

	/** @var string Katalog na dossier w trybie recznym. */
	private $katalog_dossier;

	/** @var int Licznik zapytan — koszt musi byc widoczny w raporcie. */
	private $zapytania = 0;

	/** @var MP_AU_Workspace */
	private $workspace;

	/**
	 * @param MP_AU_Workspace $workspace       Workspace (do uruchamiania polecen).
	 * @param string          $katalog_dossier Katalog na dossier.
	 * @param string          $wymuszony_tryb  Wymuszenie trybu; pusty = wykrywanie.
	 */
	public function __construct( MP_AU_Workspace $workspace, string $katalog_dossier, string $wymuszony_tryb = '' ) {
		$this->workspace       = $workspace;
		$this->katalog_dossier = rtrim( $katalog_dossier, '/' );

		if ( '' !== $wymuszony_tryb ) {
			$this->tryb  = $wymuszony_tryb;
			$this->powod = 'tryb wymuszony przez uruchamiajacego';
			return;
		}

		$this->wykryj();
	}

	/**
	 * Ustala dostepna droge do modelu.
	 *
	 * @return void
	 */
	private function wykryj(): void {
		$szukaj = $this->workspace->polecenie( array( 'sh', '-c', 'command -v claude' ) );

		if ( 0 === $szukaj['kod'] && '' !== trim( $szukaj['wyjscie'] ) ) {
			$this->binarka = trim( $szukaj['wyjscie'] );
			$this->tryb    = self::TRYB_CLI;
			return;
		}

		if ( '' !== (string) getenv( 'ANTHROPIC_API_KEY' ) ) {
			$this->tryb = self::TRYB_API;
			return;
		}

		$this->tryb  = self::TRYB_BRAK;
		$this->powod = 'brak binarki `claude` w PATH i brak ANTHROPIC_API_KEY';
	}

	/**
	 * @return bool
	 */
	public function dostepny(): bool {
		return self::TRYB_BRAK !== $this->tryb;
	}

	/**
	 * @return string
	 */
	public function tryb(): string {
		return $this->tryb;
	}

	/**
	 * @return string
	 */
	public function powod_niedostepnosci(): string {
		return $this->powod;
	}

	/**
	 * @return int
	 */
	public function ile_zapytan(): int {
		return $this->zapytania;
	}

	/**
	 * Zadaje pytanie i zwraca zdekodowana odpowiedz.
	 *
	 * Kontrakt odpowiedzi jest SZTYWNY (JSON o ustalonym ksztalcie), bo krytyk
	 * musi umiec ja zinterpretowac bez zgadywania. Odpowiedz, ktorej nie da sie
	 * zdekodowac, traktujemy jak brak odpowiedzi — nigdy jak „czysto".
	 *
	 * @param string $pytanie Pytanie z dossier.
	 * @return array|null
	 */
	public function zapytaj( string $pytanie, bool $wlasny_format = false ): ?array {
		++$this->zapytania;

		/*
		 * `$wlasny_format` istnieje, bo obudowa narzucala format odpowiedzi
		 * WSZYSTKIM pytaniom — takze tym, ktore pytaja o cos zupelnie innego.
		 * Pary 2.9 i 2.11 zadaja pytania ZAMKNIETE i oczekuja ksztaltu
		 * {"werdykty":[...]}, a dostawaly polecenie zwrocenia {"ustalenia":[...]}.
		 * Model sluchal obudowy, krytyk dostawal zero werdyktow i raportowal OK.
		 * Para „drugi sedzia" nie dzialala ANI RAZU, a wygladala na zaliczona —
		 * czyli dokladnie ten falszywy PASS, ktorego ten pipeline zabrania.
		 */
		$pelne = $wlasny_format ? $pytanie : $this->obudowa( $pytanie );

		switch ( $this->tryb ) {
			case self::TRYB_CLI:
				$odpowiedz = null;

				for ( $proba = 1; $proba <= self::PROBY && null === $odpowiedz; $proba++ ) {
					if ( $proba > 1 ) {
						sleep( 5 );
					}

					$odpowiedz = $this->przez_cli( $pelne );
				}
				break;

			case self::TRYB_API:
				$odpowiedz = $this->przez_api( $pelne );
				break;

			case self::TRYB_RECZNY:
				$this->zapisz_dossier( $pelne );
				return null;

			default:
				return null;
		}

		return null === $odpowiedz ? null : $this->zdekoduj( $odpowiedz );
	}

	/**
	 * Obudowa pytania — instrukcja formatu i zasada uczciwosci.
	 *
	 * @param string $pytanie Pytanie.
	 * @return string
	 */
	private function obudowa( string $pytanie ): string {
		return "Jestes krytykiem w automatycznym audycie kodu. Oceniasz DOSSIER faktow "
			. "zebranych przez agenta — nie masz dostepu do repozytorium poza tym, co ponizej.\n\n"
			. "ZASADY:\n"
			. "1. Zglaszaj TYLKO to, co wynika z dossier. Brak dowodu = brak ustalenia.\n"
			. "2. Kazde ustalenie musi miec konkretny scenariusz awarii: jakie wejscie "
			. "prowadzi do jakiego zlego skutku. Bez tego nie zglaszaj.\n"
			. "3. Nie zglaszaj stylu ani preferencji. Tylko rzeczy, ktore daja zly wynik.\n"
			. "4. Gdy dossier nie wystarcza do oceny — zwroc pusta liste ustalen.\n\n"
			. "ODPOWIEDZ WYLACZNIE JSON-em o ksztalcie:\n"
			. '{"ustalenia":[{"opis":"","waga":"krytyczne|srednie|drobne|obserwacja",'
			. '"plik":"","linia":0,"dowod":"","scenariusz":"","naprawa":""}]}' . "\n"
			. "Zadnego tekstu przed ani po JSON-ie.\n\n"
			. "=== DOSSIER ===\n" . $pytanie;
	}

	/**
	 * Zapytanie przez lokalne Claude Code.
	 *
	 * @param string $pytanie Pytanie.
	 * @return string|null
	 */
	private function przez_cli( string $pytanie ): ?string {
		$plik = $this->zapisz_dossier( $pytanie );
		$out  = $plik . '.odpowiedz';

		/*
		 * Odpowiedz idzie do PLIKU, nie do potoku — i to nie jest kosmetyka.
		 * Wersja z potokiem ZAWIESILA caly przebieg: `timeout` zabija proces
		 * `claude`, ale jego potomek trzyma otwarty koniec potoku, wiec PHP czeka
		 * na EOF, ktory nigdy nie przychodzi. Limit czasu istnial, a mimo to audyt
		 * stal w miejscu — czyli dokladnie ten rodzaj awarii, ktory ten pipeline
		 * ma wykrywac u innych.
		 */
		$wynik = $this->workspace->polecenie(
			array(
				'sh',
				'-c',
				// `-w`: czekanie na blokade tez musi miec koniec. Bez tego jeden
				// zawieszony proces zatrzymuje wszystkie pozostale bez limitu —
				// i dokladnie to sie stalo: jedno wywolanie wisialo 11 godzin,
				// trzymajac kolejke, a `timeout` go nie zdjal, bo maszyna byla
				// uspiona i jego zegar nie chodzil.
				'flock -w ' . self::LIMIT_BLOKADY . ' ' . escapeshellarg( self::BLOKADA )
					. ' timeout --kill-after=10 ' . self::LIMIT_CZASU . ' ' . escapeshellarg( $this->binarka )
					. ' -p --output-format text < ' . escapeshellarg( $plik )
					. ' > ' . escapeshellarg( $out ) . ' 2>/dev/null',
			)
		);

		if ( 0 !== $wynik['kod'] || ! is_readable( $out ) ) {
			return null;
		}

		$odpowiedz = trim( (string) file_get_contents( $out ) );

		// Narzedzie zewnetrzne bywa zawodne z powodow niemajacych nic wspolnego
		// z pytaniem — `claude` potrafi zwrocic samo „Execution error". Pusta
		// odpowiedz i komunikat bledu to NIE jest wynik i nie wolno go liczyc.
		if ( '' === $odpowiedz || false === strpos( $odpowiedz, '{' ) ) {
			return null;
		}

		return $odpowiedz;
	}

	/**
	 * Zapytanie przez Anthropic API.
	 *
	 * @param string $pytanie Pytanie.
	 * @return string|null
	 */
	private function przez_api( string $pytanie ): ?string {
		$ladunek = wp_json_encode_zastepcze(
			array(
				'model'      => 'claude-opus-5',
				'max_tokens' => 4096,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => $pytanie,
					),
				),
			)
		);

		$plik = $this->katalog_dossier . '/zapytanie-' . $this->zapytania . '.json';
		file_put_contents( $plik, $ladunek );

		$wynik = $this->workspace->polecenie(
			array(
				'sh',
				'-c',
				'curl -sS --max-time ' . self::LIMIT_CZASU . ' https://api.anthropic.com/v1/messages '
					. '-H "content-type: application/json" '
					. '-H "anthropic-version: 2023-06-01" '
					. '-H "x-api-key: $ANTHROPIC_API_KEY" '
					. '--data-binary @' . escapeshellarg( $plik ),
			)
		);

		if ( 0 !== $wynik['kod'] ) {
			return null;
		}

		$odpowiedz = json_decode( $wynik['wyjscie'], true );

		return $odpowiedz['content'][0]['text'] ?? null;
	}

	/**
	 * Zapisuje dossier na dysk (tryb reczny oraz slad dla kazdego zapytania).
	 *
	 * Slad jest istotny: bez niego nie da sie odtworzyc, NA JAKIEJ PODSTAWIE
	 * model wydal werdykt, a ocena nie do odtworzenia nie nadaje sie do audytu.
	 *
	 * @param string $pytanie Pytanie.
	 * @return string Sciezka pliku.
	 */
	private function zapisz_dossier( string $pytanie ): string {
		if ( ! is_dir( $this->katalog_dossier ) ) {
			mkdir( $this->katalog_dossier, 0777, true );
		}

		$plik = $this->katalog_dossier . '/dossier-' . str_pad( (string) $this->zapytania, 3, '0', STR_PAD_LEFT ) . '.txt';
		file_put_contents( $plik, $pytanie );

		return $plik;
	}

	/**
	 * Wyluskuje JSON z odpowiedzi modelu.
	 *
	 * @param string $surowa Surowa odpowiedz.
	 * @return array|null
	 */
	private function zdekoduj( string $surowa ): ?array {
		$surowa = trim( $surowa );

		// Model bywa uprzejmy i opakowuje JSON w blok markdown.
		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $surowa, $trafienie ) ) {
			$surowa = $trafienie[1];
		}

		$poczatek = strpos( $surowa, '{' );
		$koniec   = strrpos( $surowa, '}' );

		if ( false === $poczatek || false === $koniec || $koniec <= $poczatek ) {
			return null;
		}

		$dane = json_decode( substr( $surowa, $poczatek, $koniec - $poczatek + 1 ), true );

		return is_array( $dane ) ? $dane : null;
	}
}

/**
 * Zamiennik `wp_json_encode()` — narzedzie dziala poza WordPressem.
 *
 * @param mixed $dane Dane.
 * @return string
 */
function wp_json_encode_zastepcze( $dane ): string {
	return (string) json_encode( $dane, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
