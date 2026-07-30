<?php
/**
 * Kontrakty pipeline'u: agent, krytyk, para, bramka jakosci, dzial.
 *
 * Uklad odwzorowuje pipeline'y trzech wtyczek (agent zbiera / krytyk ocenia /
 * bramka QA pilnuje calego dzialu), zeby czytajacy ten kod nie musial uczyc sie
 * drugiej konwencji.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/**
 * AGENT — ZBIERA FAKTY. Nigdy nie ocenia.
 *
 * Rozdzial jest scisly i ma powod: agent, ktory sam ocenia wlasna prace, nie ma
 * jak sie pomylic „na glos". Podzial na zbieranie i ocene sprawia, ze bledna
 * ocena jest widoczna jako rozjazd miedzy dowodem a werdyktem.
 */
abstract class MP_AU_Agent {

	/** @var string Identyfikator pary, np. „1.5". */
	protected $para;

	/** @var string Nazwa robocza. */
	protected $nazwa;

	/**
	 * @param string $para  Identyfikator.
	 * @param string $nazwa Nazwa.
	 */
	public function __construct( string $para, string $nazwa ) {
		$this->para  = $para;
		$this->nazwa = $nazwa;
	}

	/**
	 * @return string
	 */
	public function para(): string {
		return $this->para;
	}

	/**
	 * @return string
	 */
	public function nazwa(): string {
		return $this->nazwa;
	}

	/**
	 * Zbiera fakty i odklada je w wyniku. NIE zglasza ustalen.
	 *
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	abstract public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik;
}

/**
 * KRYTYK — OCENIA FAKTY ZEBRANE PRZEZ AGENTA. Nigdy nie zbiera sam.
 */
abstract class MP_AU_Krytyk {

	/** @var string */
	protected $para;

	/** @var string */
	protected $nazwa;

	/**
	 * @param string $para  Identyfikator.
	 * @param string $nazwa Nazwa.
	 */
	public function __construct( string $para, string $nazwa ) {
		$this->para  = $para;
		$this->nazwa = $nazwa;
	}

	/**
	 * @return string
	 */
	public function nazwa(): string {
		return $this->nazwa;
	}

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	abstract public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik;
}

/**
 * KRYTYK MODELOWY — ocena tam, gdzie regula nie rozstrzyga.
 *
 * Agent zbiera dossier w PHP, ten krytyk oddaje je modelowi i prosi o werdykt.
 * Gdy modelu nie ma, zwraca NIEOCENIONE — nigdy PASS. To jest cala roznica
 * miedzy uczciwym a udawanym audytem.
 */
abstract class MP_AU_Krytyk_Modelowy extends MP_AU_Krytyk {

	/**
	 * Pytanie do modelu, zbudowane na podstawie dossier agenta.
	 *
	 * @param MP_AU_Wynik $od_agenta Wynik agenta.
	 * @return string
	 */
	abstract protected function pytanie( MP_AU_Wynik $od_agenta ): string;

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		if ( ! $kontekst->model->dostepny() ) {
			return MP_AU_Wynik::nieocenione(
				'Krytyk ' . $this->nazwa . ' wymaga oceny modelu, a model jest niedostepny: '
					. $kontekst->model->powod_niedostepnosci()
			);
		}

		$odpowiedz = $kontekst->model->zapytaj( $this->pytanie( $od_agenta ) );

		if ( null === $odpowiedz ) {
			return MP_AU_Wynik::nieocenione( 'Model nie zwrocil odpowiedzi dla pary ' . $this->para . '.' );
		}

		return $this->zinterpretuj( $odpowiedz, $od_agenta );
	}

	/**
	 * Zamienia odpowiedz modelu na ustalenia.
	 *
	 * @param array       $odpowiedz Odpowiedz modelu (zdekodowany JSON).
	 * @param MP_AU_Wynik $od_agenta Wynik agenta.
	 * @return MP_AU_Wynik
	 */
	protected function zinterpretuj( array $odpowiedz, MP_AU_Wynik $od_agenta ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $odpowiedz['ustalenia'] ?? array() ) as $u ) {
			if ( empty( $u['opis'] ) ) {
				continue;
			}

			$ustalenia[] = new MP_AU_Ustalenie(
				$this->para,
				(string) $u['opis'],
				(string) ( $u['waga'] ?? MP_AU_Ustalenie::SREDNIE ),
				array(
					'plik'       => (string) ( $u['plik'] ?? '' ),
					'linia'      => (int) ( $u['linia'] ?? 0 ),
					'dowod'      => (string) ( $u['dowod'] ?? '' ),
					'scenariusz' => (string) ( $u['scenariusz'] ?? '' ),
					// Ocena modelu to ZAWSZE hipoteza. Potwierdzic moze ja
					// dopiero Dzial 2, sprawdzajac ustalenie druga metoda.
					'status'     => MP_AU_Ustalenie::PRAWDOPODOBNE,
					'naprawa'    => (string) ( $u['naprawa'] ?? '' ),
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Model zglosil ustalenia: ' . count( $ustalenia ), $ustalenia, $od_agenta->dane );
	}
}

/**
 * PARA agent + krytyk. Nierozlaczna — nie ma agenta bez krytyka.
 */
final class MP_AU_Para {

	/** Poziom: sama analiza statyczna, bez uruchamiania czegokolwiek. */
	const SZYBKI = 1;

	/** Poziom: dodatkowo narzedzia zewnetrzne (php -l, PHPCS, testy, git). */
	const PELNY = 2;

	/** Poziom: dodatkowo ocena modelu i petle powtarzalnosci. */
	const GLEBOKI = 3;

	/** @var MP_AU_Agent */
	public $agent;

	/** @var MP_AU_Krytyk */
	public $krytyk;

	/**
	 * Najnizszy poziom glebokosci, na ktorym ta para ma sens.
	 *
	 * Nie chodzi o „wazniejsze" i „mniej wazne" kontrole — wszystkie sa wazne.
	 * Chodzi o KOSZT: para uruchamiajaca PHPCS na trzech wtyczkach trwa minuty,
	 * a para grepujaca po skladni — sekundy. Przy commicie chcemy odpowiedzi
	 * w minute; przed wydaniem chcemy kompletu, choćby trwal pol godziny.
	 *
	 * @var int
	 */
	public $poziom;

	/**
	 * @param MP_AU_Agent  $agent  Agent.
	 * @param MP_AU_Krytyk $krytyk Krytyk.
	 * @param int          $poziom Najnizszy poziom glebokosci.
	 */
	public function __construct( MP_AU_Agent $agent, MP_AU_Krytyk $krytyk, int $poziom = self::SZYBKI ) {
		$this->agent  = $agent;
		$this->krytyk = $krytyk;
		$this->poziom = $poziom;
	}

	/**
	 * Wykonuje pare i zwraca wynik krytyka.
	 *
	 * Wyjatek agenta NIE przerywa audytu — audyt, ktory sam sie wywraca na
	 * pierwszym nietypowym pliku, jest bezuzyteczny. Zamiast tego powstaje
	 * ustalenie o awarii kontroli.
	 *
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function wykonaj( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		try {
			$zebrane = $this->agent->zbierz( $kontekst );
		} catch ( \Throwable $e ) {
			return MP_AU_Wynik::blad(
				'Agent ' . $this->agent->para() . ' przerwal sie wyjatkiem.',
				array(
					new MP_AU_Ustalenie(
						$this->agent->para(),
						'Kontrola nie zostala wykonana — agent rzucil wyjatek: ' . $e->getMessage(),
						MP_AU_Ustalenie::SREDNIE,
						array(
							'dowod'      => get_class( $e ) . ': ' . $e->getMessage(),
							'scenariusz' => 'Ta wlasciwosc projektu NIE zostala sprawdzona w tym przebiegu.',
						)
					),
				)
			);
		}

		if ( MP_AU_Wynik::NIEOCENIONE === $zebrane->stan ) {
			return $zebrane;
		}

		try {
			return $this->krytyk->ocen( $zebrane, $kontekst );
		} catch ( \Throwable $e ) {
			return MP_AU_Wynik::blad(
				'Krytyk ' . $this->krytyk->nazwa() . ' przerwal sie wyjatkiem.',
				array(
					new MP_AU_Ustalenie(
						$this->agent->para(),
						'Ocena nie zostala wykonana — krytyk rzucil wyjatek: ' . $e->getMessage(),
						MP_AU_Ustalenie::SREDNIE,
						array( 'dowod' => get_class( $e ) . ': ' . $e->getMessage() )
					),
				)
			);
		}
	}
}

/**
 * DZIAL — zestaw par + bramka jakosci na koncu.
 */
class MP_AU_Dzial {

	/** @var int */
	protected $numer;

	/** @var string */
	protected $nazwa;

	/** @var MP_AU_Para[] */
	protected $pary = array();

	/**
	 * @param int    $numer Numer dzialu.
	 * @param string $nazwa Nazwa.
	 */
	public function __construct( int $numer, string $nazwa ) {
		$this->numer = $numer;
		$this->nazwa = $nazwa;
	}

	/**
	 * @param MP_AU_Para $para Para.
	 * @return MP_AU_Dzial
	 */
	public function dodaj( MP_AU_Para $para ): MP_AU_Dzial {
		$this->pary[] = $para;
		return $this;
	}

	/**
	 * @return int
	 */
	public function numer(): int {
		return $this->numer;
	}

	/**
	 * @return string
	 */
	public function nazwa(): string {
		return $this->nazwa;
	}

	/**
	 * @return int
	 */
	public function ile_par(): int {
		return count( $this->pary );
	}

	/**
	 * Uruchamia wszystkie pary dzialu.
	 *
	 * Dzial NIE przerywa sie na pierwszym ustaleniu — inaczej audyt raportowalby
	 * jeden problem naraz i wymagal dziesieciu przebiegow. Bramka jakosci ocenia
	 * dzial dopiero po wykonaniu kompletu par.
	 *
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return array Podsumowanie przebiegu dzialu.
	 */
	public function uruchom( MP_AU_Kontekst $kontekst ): array {
		$przebieg = array(
			'dzial'       => $this->numer,
			'nazwa'       => $this->nazwa,
			'pary'        => array(),
			'nieocenione' => 0,
			'pominiete'   => 0,
			'czas'        => 0.0,
		);

		foreach ( $this->pary as $para ) {
			// Para ponad zadana glebokoscia nie jest „zaliczona" — jest POMINIETA,
			// i raport musi to powiedziec wprost. Skrocony audyt, ktory wyglada
			// jak pelny, to dokladnie ten rodzaj falszywego „zielonego", przed
			// ktorym broni sie caly ten pipeline.
			if ( $para->poziom > $kontekst->glebokosc ) {
				++$przebieg['pominiete'];

				$przebieg['pary'][] = array(
					'para'      => $para->agent->para(),
					'nazwa'     => $para->agent->nazwa(),
					'stan'      => 'pominieta',
					'powod'     => 'wymaga glebokosci ' . $para->poziom . ', przebieg ma ' . $kontekst->glebokosc,
					'ustalenia' => 0,
					'czas'      => 0.0,
				);

				continue;
			}

			$start = microtime( true );
			$wynik = $para->wykonaj( $kontekst );
			$czas  = round( microtime( true ) - $start, 2 );

			$kontekst->dopisz_ustalenia( $wynik->ustalenia );
			$przebieg['czas'] += $czas;

			if ( MP_AU_Wynik::NIEOCENIONE === $wynik->stan ) {
				++$przebieg['nieocenione'];
			}

			$przebieg['pary'][] = array(
				'para'      => $para->agent->para(),
				'nazwa'     => $para->agent->nazwa(),
				'stan'      => $wynik->stan,
				'powod'     => $wynik->powod,
				'ustalenia' => count( $wynik->ustalenia ),
				'czas'      => $czas,
			);
		}

		$przebieg['czas'] = round( $przebieg['czas'], 2 );

		$przebieg['bramka'] = $this->bramka( $kontekst, $przebieg );

		return $przebieg;
	}

	/**
	 * BRAMKA JAKOSCI dzialu — ocenia kompletnosc PRZEBIEGU, nie kodu projektu.
	 *
	 * Pyta o jedno: czy ten audyt wolno uznac za wykonany. Dzial, w ktorym
	 * polowa par nie mogla sie wykonac, nie daje prawa do werdyktu „czysto".
	 *
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @param array          $przebieg Przebieg.
	 * @return array
	 */
	protected function bramka( MP_AU_Kontekst $kontekst, array $przebieg ): array {
		$wszystkie   = count( $przebieg['pary'] );
		$nieocenione = (int) $przebieg['nieocenione'];
		$pominiete   = (int) $przebieg['pominiete'];
		$wykonane    = $wszystkie - $nieocenione - $pominiete;
		$pokrycie    = $wszystkie > 0 ? $wykonane / $wszystkie : 0.0;

		return array(
			'par_wykonanych' => $wykonane,
			'par_pominietych' => $pominiete,
			'par_wszystkich' => $wszystkie,
			'pokrycie'       => round( $pokrycie, 3 ),
			// Prog 1.0 jest celowy: „prawie wszystko sprawdzone" to nie to samo,
			// co „sprawdzone". Nizszy prog zachecalby do ignorowania brakow.
			//
			// Pominiecie z powodu glebokosci NIE psuje bramki — uzytkownik sam
			// zazadal krotszego przebiegu i wie o tym. Psuje za to KOMPLETNOSC,
			// ktora raport pokazuje osobno i ktora blokuje werdykt „GO".
			'zaliczona'      => 0 === $nieocenione,
			'kompletna'      => 0 === $nieocenione && 0 === $pominiete,
		);
	}
}
