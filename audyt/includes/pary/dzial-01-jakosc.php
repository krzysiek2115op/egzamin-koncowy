<?php
/**
 * Dzial 1, grupa JAKOSC: pary 1.3, 1.17, 1.20, 1.22, 1.24.
 *
 * Grupa zajmuje sie tym, co nie wywala systemu od razu, ale decyduje o tym, czy
 * za pol roku ktokolwiek bedzie umial ten kod bezpiecznie zmienic. Jedna z par
 * (1.24) audytuje SAME TESTY — bo w tym projekcie test potrafil przechodzic
 * falszywie, a to gorsze niz brak testu.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/* ===================================================================== 1.3 */

/**
 * A1.3 „PHPCS/WPCS" — uruchamia PRAWDZIWY analizator, nie wlasny regex.
 *
 * Wlasny wzorzec wykrywa to, co autor wzorca przewidzial. PHPCS z WPCS wykrywa
 * kilkaset rzeczy, ktorych nikt tu nie przewidzial — i wlasnie po to jest.
 *
 * Uwaga historyczna wbudowana w te pare: 29.07 wlasny plik regul pokazal 16
 * falszywych bledow, bo mial domene tekstowa innej wtyczki (wpis NARZ-F1
 * w rejestrze). Dlatego agent NIE bierze pierwszego lepszego rulesetu — sklada
 * dla kazdej wtyczki nakladke, ktora nadpisuje `text_domain` slugiem TEJ wtyczki.
 */
final class MP_AU_A13_Phpcs extends MP_AU_Agent {

	/**
	 * Szuka binarki `phpcs`.
	 *
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return string Sciezka albo pusty napis.
	 */
	private function binarka( MP_AU_Kontekst $kontekst ): string {
		$jawna = (string) getenv( 'MP_AU_PHPCS' );

		if ( '' !== $jawna && is_executable( $jawna ) ) {
			return $jawna;
		}

		$wynik = $kontekst->workspace->polecenie( array( 'sh', '-c', 'command -v phpcs' ) );

		if ( 0 === $wynik['kod'] && '' !== trim( $wynik['wyjscie'] ) ) {
			return trim( $wynik['wyjscie'] );
		}

		return '';
	}

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$phpcs = $this->binarka( $kontekst );

		if ( '' === $phpcs ) {
			// Uczciwe „nie wiem". Nie ma tu miejsca na „skoro nie mozemy
			// sprawdzic, to pewnie jest dobrze".
			return MP_AU_Wynik::nieocenione(
				'Brak PHPCS. Ustaw MP_AU_PHPCS=/sciezka/do/phpcs albo dodaj phpcs do PATH '
					. '(instalacja przenosna: composer require --dev wp-coding-standards/wpcs).'
			);
		}

		$wyniki = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			$katalog = $kontekst->workspace->katalog( $branch );

			if ( '' === $katalog || ! is_dir( $katalog ) ) {
				continue;
			}

			$ruleset = $this->ruleset( $kontekst, $branch, $katalog );

			$wynik = $kontekst->workspace->polecenie(
				array(
					'sh',
					'-c',
					// `-q` jest konieczne: bez niego PHPCS drukuje pasek postepu
					// PRZED JSON-em, a wtedy odpowiedz nie daje sie zdekodowac
					// i para raportuje „PHPCS nie zwrocil czytelnego raportu"
					// zamiast prawdziwego wyniku.
					escapeshellarg( $phpcs ) . ' -q --no-colors --standard=' . escapeshellarg( $ruleset )
						. ' --report=json --runtime-set ignore_warnings_on_exit 1 '
						. escapeshellarg( $katalog ) . ' 2>/dev/null',
				)
			);

			$dane = json_decode( $this->wylowione( $wynik['wyjscie'] ), true );

			if ( ! is_array( $dane ) || ! isset( $dane['files'] ) ) {
				$wyniki[ $branch ] = array( 'blad' => MP_AU_Pomoc::skrot( $wynik['wyjscie'], 300 ) );
				continue;
			}

			$bledy = array();

			foreach ( (array) $dane['files'] as $plik => $info ) {
				foreach ( (array) ( $info['messages'] ?? array() ) as $komunikat ) {
					$bledy[] = array(
						'plik'    => $kontekst->workspace->wzgledna( (string) $plik ),
						'linia'   => (int) ( $komunikat['line'] ?? 0 ),
						'typ'     => (string) ( $komunikat['type'] ?? 'ERROR' ),
						'zrodlo'  => (string) ( $komunikat['source'] ?? '' ),
						'tresc'   => (string) ( $komunikat['message'] ?? '' ),
					);
				}
			}

			$wyniki[ $branch ] = array(
				'bledy'       => $bledy,
				'ruleset'     => $ruleset,
				'sum_bledow'  => (int) ( $dane['totals']['errors'] ?? 0 ),
				'sum_ostrzez' => (int) ( $dane['totals']['warnings'] ?? 0 ),
			);
		}

		return MP_AU_Wynik::ok( array( 'phpcs' => $wyniki ) );
	}

	/**
	 * Wylawia JSON z wyjscia, ktore moze zawierac tekst dookola.
	 *
	 * @param string $wyjscie Wyjscie polecenia.
	 * @return string
	 */
	private function wylowione( string $wyjscie ): string {
		$od = strpos( $wyjscie, '{' );
		$do = strrpos( $wyjscie, '}' );

		return ( false === $od || false === $do || $do <= $od )
			? ''
			: substr( $wyjscie, $od, $do - $od + 1 );
	}

	/**
	 * Sklada nakladke na `.phpcs.xml.dist` projektu z poprawna domena tekstowa.
	 *
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @param string         $branch   Branch (rowny slugowi wtyczki).
	 * @param string         $katalog  Katalog wtyczki.
	 * @return string Sciezka rulesetu.
	 */
	private function ruleset( MP_AU_Kontekst $kontekst, string $branch, string $katalog ): string {
		$bazowy = dirname( $katalog ) . '/.phpcs.xml.dist';
		$plik   = sys_get_temp_dir() . '/mp-au-phpcs-' . $branch . '-' . getmypid() . '.xml';

		$xml  = '<?xml version="1.0"?>' . "\n";
		$xml .= '<ruleset name="MP audyt ' . $branch . '">' . "\n";
		$xml .= is_readable( $bazowy )
			? "\t" . '<rule ref="' . $bazowy . '"/>' . "\n"
			: "\t" . '<rule ref="WordPress"/>' . "\n";

		// Domena tekstowa ZAWSZE ze sluga tej wtyczki — patrz NARZ-F1.
		$xml .= "\t" . '<rule ref="WordPress.WP.I18n">' . "\n";
		$xml .= "\t\t" . '<properties><property name="text_domain" type="array">' . "\n";
		$xml .= "\t\t\t" . '<element value="' . $branch . '"/>' . "\n";
		$xml .= "\t\t" . '</property></properties>' . "\n";
		$xml .= "\t" . '</rule>' . "\n";
		$xml .= "\t" . '<exclude-pattern>*/vendor/*</exclude-pattern>' . "\n";
		$xml .= '</ruleset>' . "\n";

		file_put_contents( $plik, $xml );
		$kontekst->policz_odczyt();

		return $plik;
	}
}

/**
 * K1.3 „zero-bledow-standardu".
 */
final class MP_AU_K13_Phpcs extends MP_AU_Krytyk {

	/** Ile pojedynczych bledow wypisac zanim zaczniemy grupowac. */
	const PROG_SZCZEGOLOW = 12;

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['phpcs'] ?? array() ) as $branch => $wynik ) {
			if ( isset( $wynik['blad'] ) ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.3',
					'PHPCS nie zwrocil czytelnego raportu dla wtyczki „' . $branch . '".',
					MP_AU_Ustalenie::DROBNE,
					array(
						'dowod'      => (string) $wynik['blad'],
						'scenariusz' => 'Zgodnosc ze standardem kodowania tej wtyczki NIE zostala sprawdzona.',
					)
				);
				continue;
			}

			$bledy = array_values(
				array_filter(
					(array) $wynik['bledy'],
					static function ( $b ) {
						return 'ERROR' === $b['typ'];
					}
				)
			);

			// Grupujemy po zrodle reguly. Sto zgloszen „brakuje spacji" to JEDEN
			// problem powtorzony sto razy, a nie sto problemow — raport, ktory
			// tego nie rozroznia, jest nieczytelny i konczy w koszu.
			$wg_zrodla = array();

			foreach ( $bledy as $b ) {
				$wg_zrodla[ $b['zrodlo'] ][] = $b;
			}

			arsort( $wg_zrodla );

			foreach ( $wg_zrodla as $zrodlo => $grupa ) {
				$pierwszy = $grupa[0];

				$ustalenia[] = new MP_AU_Ustalenie(
					'1.3',
					'PHPCS: ' . MP_AU_Pomoc::skrot( $pierwszy['tresc'], 110 )
						. ( count( $grupa ) > 1 ? ' (wystapien: ' . count( $grupa ) . ')' : '' ),
					MP_AU_Ustalenie::DROBNE,
					array(
						'plik'       => (string) $pierwszy['plik'],
						'linia'      => (int) $pierwszy['linia'],
						'dowod'      => $zrodlo . ' — ' . count( $grupa ) . ' wystapien we wtyczce ' . $branch,
						'scenariusz' => 'Kod odbiega od standardu przyjetego w projekcie; przy przegladzie '
							. 'i przy pracy nastepnej osoby kosztuje to czas i pomylki.',
						'naprawa'    => 'phpcbf --standard=' . ( $wynik['ruleset'] ?? '.phpcs.xml.dist' ),
					)
				);
			}
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'PHPCS zglosil bledy standardu.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.17 */

/**
 * A1.17 „dokumentacja dzialow" — zasada projektu: JEDEN plik na dzial.
 *
 * Sprawdza tez to, co odroznia dokumentacje od notatki: czy zrodlo ma URL
 * i date pobrania. Cytat bez adresu i daty jest niesprawdzalny.
 */
final class MP_AU_A117_Dokumentacja extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$dzialy = array();
		$doki   = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			$katalog = $kontekst->workspace->katalog( $branch );

			if ( '' === $katalog || ! is_dir( $katalog ) ) {
				continue;
			}

			// Dzialy pipeline'u rozpoznajemy po plikach klas dzialow.
			foreach ( glob( $katalog . '/includes/pipeline/departments/*.php' ) ?: array() as $plik ) {
				if ( preg_match( '/-(\d{2})\.php$/', $plik, $t ) ) {
					$dzialy[ $branch ][] = (int) $t[1];
				}
			}

			foreach ( glob( $katalog . '/docs/*' ) ?: array() as $sciezka ) {
				if ( is_dir( $sciezka ) ) {
					$pliki = glob( $sciezka . '/*.md' ) ?: array();

					$doki[ $branch ][ basename( $sciezka ) ] = array_map( 'basename', $pliki );

					foreach ( $pliki as $md ) {
						$tresc = $kontekst->workspace->tresc( $md, $kontekst );

						$doki[ $branch ][ basename( $sciezka ) . ':meta' ][] = array(
							'plik'    => $kontekst->workspace->wzgledna( $md ),
							'url'     => (int) preg_match_all( '#https?://#', $tresc ),
							'data'    => (int) preg_match_all( '/(?:Pobrano|pobrano|Data pobrania)\s*:?\s*\d{4}-\d{2}-\d{2}/u', $tresc ),
							'rozmiar' => strlen( $tresc ),
						);
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'dzialy' => $dzialy,
				'doki'   => $doki,
			)
		);
	}
}

/**
 * K1.17 „jeden-plik-na-dzial-i-zrodlo-sprawdzalne".
 */
final class MP_AU_K117_Dokumentacja extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();
		$dzialy    = (array) ( $od_agenta->dane['dzialy'] ?? array() );
		$doki      = (array) ( $od_agenta->dane['doki'] ?? array() );

		foreach ( $dzialy as $branch => $numery ) {
			$numery = array_unique( $numery );
			sort( $numery );

			foreach ( $numery as $numer ) {
				$klucz = 'dzial-' . str_pad( (string) $numer, 2, '0', STR_PAD_LEFT );
				$pliki = (array) ( $doki[ $branch ][ $klucz ] ?? array() );

				if ( empty( $pliki ) ) {
					$ustalenia[] = new MP_AU_Ustalenie(
						'1.17',
						'Dzial ' . $numer . ' wtyczki „' . $branch . '" nie ma pliku dokumentacji.',
						MP_AU_Ustalenie::SREDNIE,
						array(
							'plik'       => $branch . '/docs/' . $klucz . '/',
							'dowod'      => 'Katalog dokumentacji pusty albo nieobecny.',
							'scenariusz' => 'Agenci tego dzialu nie maja z czego korzystac; przy zmianie '
								. 'wymagan nikt nie odtworzy, na jakiej podstawie podjeto decyzje.',
						)
					);
					continue;
				}

				/*
				 * LICZBA PLIKOW W KATALOGU DZIALU NIE JEST USTALENIEM.
				 *
				 * Stala tu kontrola „ma X plikow zamiast jednego", oparta na zle
				 * odczytanej zasadzie klienta. Zasada brzmi: JEDEN PLIK NA ZRODLO
				 * — jedna dokumentacja z jednego oryginalnego zrodla. Katalog
				 * `docs/dzial-03/` z piecioma plikami (Biala lista, algorytm NIP,
				 * VIES REST, WP-Cron, wp_remote_get) jest wiec wzorcowy, a nie
				 * wadliwy. W przebiegu z 01.08.2026 kontrola dala 10 ustalen
				 * i wszystkie byly falszywe.
				 *
				 * Nie zostala zastapiona inna, bo zaden mechaniczny niezmiennik
				 * nie przezyl zderzenia z danymi:
				 *   - „kazdy plik musi cytowac URL" — 5 z 47 plikow to notatki
				 *     DECYZYJNE (rodo-zgody.md, scoring-przypisanie.md i inne),
				 *     ktore zadnego zrodla zewnetrznego nie maja i miec nie musza;
				 *   - „jeden host na plik" — 10 plikow cytuje dwa hosty i kazdy
				 *     przypadek jest uprawniony: dwa opracowania tego samego
				 *     algorytmu NIP, `example.com` w przykladzie kodu,
				 *     dokumentacja WordPressa obok dokumentacji MySQL.
				 *
				 * Reguly, ktorej nie da sie postawic bez falszywych alarmow, nie
				 * stawiamy na sile. Para pilnuje dwoch rzeczy, ktore sie bronia:
				 * dzialu BEZ dokumentacji (wyzej) i cytatu bez daty (nizej).
				 * Patrz audyt/tests/regula-1-17.php.
				 */
			}
		}

		foreach ( $doki as $branch => $katalogi ) {
			foreach ( $katalogi as $nazwa => $zawartosc ) {
				if ( ! preg_match( '/:meta$/', (string) $nazwa ) ) {
					continue;
				}

				foreach ( (array) $zawartosc as $meta ) {
					if ( $meta['url'] > 0 && 0 === $meta['data'] ) {
						$ustalenia[] = new MP_AU_Ustalenie(
							'1.17',
							'Dokumentacja cytuje zrodlo z adresem, ale bez daty pobrania.',
							MP_AU_Ustalenie::DROBNE,
							array(
								'plik'       => (string) $meta['plik'],
								'dowod'      => 'adresow: ' . $meta['url'] . ', dat pobrania: 0',
								'scenariusz' => 'Strona zrodlowa zmieni sie i nie da sie ustalic, czy cytat '
									. 'byl wierny w momencie pisania. Cytat bez daty jest niesprawdzalny.',
								'naprawa'    => 'Dopisac „Pobrano: RRRR-MM-DD" przy kazdym adresie.',
							)
						);
					}
				}
			}
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Braki w dokumentacji dzialow.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.20 */

/**
 * A1.20 „i18n" — czy napisy da sie przetlumaczyc.
 *
 * Trzy wtyczki w jednej instalacji maja trzy rozne domeny tekstowe. Pomylka
 * w domenie nie wywala niczego — po prostu tlumaczenie nie dziala i nikt nie
 * wie dlaczego. To jest dokladnie ten rodzaj bledu, ktory zyje latami.
 */
final class MP_AU_A120_I18n extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$obce      = array();
		$zmienne   = array();
		$ladowanie = array();

		$funkcje = '__|_e|_x|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|_ex';

		foreach ( $kontekst->workspace->branche() as $branch ) {
			$ladowanie[ $branch ] = false;

			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$surowa = $kontekst->workspace->tresc( $plik, $kontekst );
				$tresc  = MP_AU_Pomoc::kod( $surowa );

				if ( false !== strpos( $tresc, 'load_plugin_textdomain' ) ) {
					$ladowanie[ $branch ] = true;
				}

				// Domena jako literal — ostatni argument w apostrofach.
				if ( preg_match_all( '/\b(?:' . $funkcje . ')\s*\(([^()]*)\)/', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[1] as $indeks => $argumenty ) {
						$tekst = (string) $argumenty[0];

						if ( ! preg_match( '/[\'"]([a-z0-9-]+)[\'"]\s*$/i', trim( $tekst ), $d ) ) {
							// Ostatni argument nie jest literalem — albo brak domeny,
							// albo domena ze zmiennej. Jedno i drugie jest bledem.
							$zmienne[] = array(
								'plik'     => $kontekst->workspace->wzgledna( $plik ),
								'linia'    => MP_AU_Pomoc::linia_offsetu( $tresc, (int) $t[0][ $indeks ][1] ),
								'fragment' => MP_AU_Pomoc::skrot( (string) $t[0][ $indeks ][0], 120 ),
							);
							continue;
						}

						if ( $d[1] !== $branch ) {
							$obce[] = array(
								'plik'     => $kontekst->workspace->wzgledna( $plik ),
								'linia'    => MP_AU_Pomoc::linia_offsetu( $tresc, (int) $t[0][ $indeks ][1] ),
								'domena'   => $d[1],
								'oczekiwana' => $branch,
								'fragment' => MP_AU_Pomoc::skrot( (string) $t[0][ $indeks ][0], 120 ),
							);
						}
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'obce'      => $obce,
				'zmienne'   => $zmienne,
				'ladowanie' => $ladowanie,
			)
		);
	}
}

/**
 * K1.20 „domena-zgodna-ze-slugiem".
 */
final class MP_AU_K120_I18n extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['obce'] ?? array() ) as $o ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.20',
				'Napis oznaczony domena „' . $o['domena'] . '" w kodzie wtyczki „' . $o['oczekiwana'] . '".',
				MP_AU_Ustalenie::DROBNE,
				array(
					'plik'       => (string) $o['plik'],
					'linia'      => (int) $o['linia'],
					'dowod'      => (string) $o['fragment'],
					'scenariusz' => 'Tlumaczenie tego napisu nigdy sie nie zaladuje — WordPress szuka go '
						. 'w katalogu innej wtyczki. Bledu nie widac: napis po prostu zostaje po angielsku.',
					'naprawa'    => 'Zmienic domene na „' . $o['oczekiwana'] . '".',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['zmienne'] ?? array() ) as $z ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.20',
				'Funkcja tlumaczaca bez domeny tekstowej podanej wprost.',
				MP_AU_Ustalenie::DROBNE,
				array(
					'plik'       => (string) $z['plik'],
					'linia'      => (int) $z['linia'],
					'dowod'      => (string) $z['fragment'],
					'scenariusz' => 'Napis trafi do domeny domyslnej WordPressa albo nigdzie; narzedzia '
						. 'do ekstrakcji tlumaczen go nie zbiora.',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['ladowanie'] ?? array() ) as $branch => $ma ) {
			if ( ! $ma ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.20',
					'Wtyczka „' . $branch . '" nie wola `load_plugin_textdomain()`.',
					MP_AU_Ustalenie::OBSERWACJA,
					array(
						'plik'       => $branch,
						'dowod'      => 'Brak wywolania w calym kodzie wtyczki.',
						'scenariusz' => 'Przy tlumaczeniach spoza katalogu wp-content/languages napisy '
							. 'zostana nieprzetlumaczone. Dla wtyczek z repozytorium WP.org od 4.6 '
							. 'ladowanie bywa automatyczne — stad obserwacja, nie blad.',
					)
				);
			}
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Usterki i18n.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.22 */

/**
 * A1.22 „kod nieuzywany" — metody prywatne, ktorych nikt nie wola.
 *
 * Swiadomie ograniczone do metod PRYWATNYCH i do jednego pliku. Metoda publiczna
 * moze byc wolana z drugiej wtyczki albo z motywu, wiec „nikt jej nie wola"
 * bylo by falszywym alarmem. Prywatna widoczna jest tylko tutaj — jesli nikt jej
 * nie wola TU, nie wola jej nikt.
 */
final class MP_AU_A122_Martwy_Kod extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$martwe = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$tresc = MP_AU_Pomoc::kod( $kontekst->workspace->tresc( $plik, $kontekst ) );

				if ( ! preg_match_all( '/\bprivate\s+(?:static\s+)?function\s+([a-z_][a-z0-9_]*)\s*\(/i', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}

				foreach ( $t[1] as $trafienie ) {
					$nazwa = (string) $trafienie[0];

					// Liczymy wystapienia nazwy jako WYWOLANIA: po `->`, po `::`
					// albo w tablicy callable. Sama deklaracja sie nie liczy.
					$wywolan = preg_match_all(
						'/(?:->|::)\s*' . preg_quote( $nazwa, '/' ) . '\s*\(|[\'"]' . preg_quote( $nazwa, '/' ) . '[\'"]\s*\)/',
						$tresc
					);

					if ( 0 === $wywolan ) {
						$martwe[] = array(
							'plik'   => $kontekst->workspace->wzgledna( $plik ),
							'linia'  => MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] ),
							'metoda' => $nazwa,
						);
					}
				}
			}
		}

		return MP_AU_Wynik::ok( array( 'martwe' => $martwe ) );
	}
}

/**
 * K1.22 „kod-ktory-nikogo-nie-obchodzi".
 */
final class MP_AU_K122_Martwy_Kod extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['martwe'] ?? array() ) as $m ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.22',
				'Metoda prywatna „' . $m['metoda'] . '" nie jest nigdzie wolana.',
				MP_AU_Ustalenie::DROBNE,
				array(
					'plik'       => (string) $m['plik'],
					'linia'      => (int) $m['linia'],
					'dowod'      => 'Zero wywolan w pliku, w ktorym jest zadeklarowana.',
					'scenariusz' => 'Albo zostala po refaktorze i myli czytajacego, albo mial ja wolac '
						. 'kod, ktory tego nie robi — a wtedy brakuje kroku, ktory ktos zaprojektowal.',
					'naprawa'    => 'Usunac albo dopiac wywolanie; jedno z dwojga.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Kod bez wywolan.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.24 */

/**
 * A1.24 „jakosc testow" — audyt SAMYCH TESTOW.
 *
 * Najwazniejsza para tej grupy. Wszystkie osiem bledow krytycznych z 29.07
 * przechodzilo przez komplet testow, a jeden punkt testow przechodzil FALSZYWIE:
 * straznik zwracal zera z powodu awarii, a asercja wlasnie zer oczekiwala
 * (wpis TEST-F1 w rejestrze). Test, ktory nie moze nie przejsc, jest gorszy niz
 * brak testu, bo zamyka temat.
 */
final class MP_AU_A124_Jakosc_Testow extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$pliki        = array();
		$tautologie   = array();
		$oczekuje_zer = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, false ) as $plik ) {
				if ( ! MP_AU_Pomoc::czy_test( $plik ) ) {
					continue;
				}

				// `index.php` (zaslepka „Silence is golden"), stuby WordPressa
				// i rusztowanie uruchomieniowe NIE SA testami i nie maja prawa
				// byc oceniane jak testy. Pierwszy przebieg zglosil dziesiec
				// takich plikow jako „test bez asercji" — czyli dziesiec zgloszen
				// o niczym, w ktorych utonelyby dwa prawdziwe.
				$nazwa_pliku = basename( $plik );

				if ( 'index.php' === $nazwa_pliku
					|| false !== strpos( $plik, '/process-harness/' )
					|| false !== strpos( $nazwa_pliku, 'stub' )
					|| false !== strpos( $nazwa_pliku, 'bootstrap' ) ) {
					continue;
				}

				$surowa = $kontekst->workspace->tresc( $plik, $kontekst );
				$tresc  = MP_AU_Pomoc::kod( $surowa );
				$wzgl   = $kontekst->workspace->wzgledna( $plik );

				// Funkcja asercji tego pliku: ta, ktora liczy pass/fail.
				$asercja = '';

				if ( preg_match_all( '/function\s+([a-z_][a-z0-9_]*)\s*\(/i', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[1] as $indeks => $nazwa ) {
						$od    = (int) $t[0][ $indeks ][1];
						$cialo = substr( $tresc, $od, 900 );

						/*
						 * Kazdy z trzech pipeline'ow ma wlasna konwencje asercji
						 * (`ml_ok`, `chk`, liczniki w tablicy globalnej). Rozpoznajemy
						 * je po tym, co robia: rozgalezienie na sukces i porazke.
						 *
						 * Slownik musi obejmowac obie strony konwencji, nie tylko
						 * slowo „pass": test bramki integracyjnej zbiera wyniki
						 * w `$GLOBALS['mp_oks']` i `$GLOBALS['mp_fails']`, wiec
						 * wersja szukajaca wylacznie „pass" uznawala plik z 23
						 * asercjami za plik BEZ ANI JEDNEJ. To najgorszy mozliwy
						 * rodzaj pomylki w tej parze — zarzuca testowi dokladnie to,
						 * czego para ma pilnowac.
						 */
						$ma_pass = false !== stripos( $cialo, 'pass' )
							|| preg_match( '/[\'"$_](?:oks?|sukces|success|passed)\b/i', $cialo );
						$ma_fail = false !== stripos( $cialo, 'fail' )
							|| preg_match( '/[\'"$_](?:bled(?:y|ow)?|blad|errors?)\b/i', $cialo );

						if ( $ma_pass && $ma_fail ) {
							$asercja = (string) $nazwa[0];
							break;
						}
					}
				}

				$wywolan = '' === $asercja
					? 0
					: preg_match_all( '/(?<![a-z0-9_>:])' . preg_quote( $asercja, '/' ) . '\s*\(/i', $tresc ) - 1;

				$pliki[] = array(
					'plik'    => $wzgl,
					'asercja' => $asercja,
					'asercji' => max( 0, $wywolan ),
				);

				if ( '' === $asercja ) {
					continue;
				}

				// (a) Asercja tautologiczna: obie strony porownania identyczne.
				if ( preg_match_all( '/' . preg_quote( $asercja, '/' ) . '\s*\(\s*(.{3,80}?)\s*===\s*(.{3,80}?)\s*,/s', $tresc, $t2, PREG_SET_ORDER ) ) {
					foreach ( $t2 as $trafienie ) {
						if ( trim( $trafienie[1] ) === trim( $trafienie[2] ) ) {
							$tautologie[] = array(
								'plik'     => $wzgl,
								'linia'    => MP_AU_Pomoc::linia( $tresc, $trafienie[0] ),
								'fragment' => MP_AU_Pomoc::skrot( $trafienie[0], 120 ),
							);
						}
					}
				}

				// (b) Asercja oczekujaca zera od wywolania funkcji — dokladnie
				// ksztalt TEST-F1. Zero bywa poprawnym wynikiem, ale bywa tez
				// wartoscia zwracana przy awarii, i wtedy test klamie.
				if ( preg_match_all( '/' . preg_quote( $asercja, '/' ) . '\s*\(\s*0\s*===\s*([a-z_][a-z0-9_]*(?:::|->)[a-z0-9_]+\([^)]*\))/i', $tresc, $t3, PREG_SET_ORDER ) ) {
					foreach ( $t3 as $trafienie ) {
						$oczekuje_zer[] = array(
							'plik'     => $wzgl,
							'linia'    => MP_AU_Pomoc::linia( $tresc, $trafienie[0] ),
							'fragment' => MP_AU_Pomoc::skrot( $trafienie[0], 120 ),
						);
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'pliki'        => $pliki,
				'tautologie'   => $tautologie,
				'oczekuje_zer' => $oczekuje_zer,
			)
		);
	}
}

/**
 * K1.24 „test-musi-umiec-nie-przejsc".
 */
final class MP_AU_K124_Jakosc_Testow extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['pliki'] ?? array() ) as $p ) {
			if ( 0 === (int) $p['asercji'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.24',
					'Plik testu bez ani jednej asercji.',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => (string) $p['plik'],
						'dowod'      => '' === $p['asercja']
							? 'Nie znaleziono funkcji liczacej pass/fail.'
							: 'Funkcja „' . $p['asercja'] . '" zadeklarowana, ale nigdy nie wolana.',
						'scenariusz' => 'Ten plik przechodzi ZAWSZE, takze wtedy, gdy sprawdzana funkcja '
							. 'jest calkowicie zepsuta. W podsumowaniu wyglada jak dowod poprawnosci.',
					)
				);
			}
		}

		foreach ( (array) ( $od_agenta->dane['tautologie'] ?? array() ) as $t ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.24',
				'Asercja porownuje wyrazenie samo ze soba.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $t['plik'],
					'linia'      => (int) $t['linia'],
					'dowod'      => (string) $t['fragment'],
					'scenariusz' => 'Warunek jest prawdziwy zawsze — asercja nie sprawdza niczego, '
						. 'a zwieksza licznik PASS, wiec podnosi pozorna jakosc pokrycia.',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['oczekuje_zer'] ?? array() ) as $z ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.24',
				'Asercja oczekuje zera od wywolania — sprawdz, czy zero nie jest tez wynikiem awarii.',
				MP_AU_Ustalenie::OBSERWACJA,
				array(
					'plik'       => (string) $z['plik'],
					'linia'      => (int) $z['linia'],
					'dowod'      => (string) $z['fragment'],
					'scenariusz' => 'Dokladnie ten ksztalt dal falszywy PASS w tym projekcie (TEST-F1): '
						. 'straznik zwrocil zera, bo sie wywrocil, a test wlasnie zer oczekiwal. '
						. 'Test powinien najpierw potwierdzic, ze sprawdzana droga w ogole sie wykonala.',
					'naprawa'    => 'Dolozyc asercje pozytywna (np. licznik wywolan > 0) przed asercja na zero.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Testy, ktore moga klamac.', $ustalenia, $od_agenta->dane );
	}
}
