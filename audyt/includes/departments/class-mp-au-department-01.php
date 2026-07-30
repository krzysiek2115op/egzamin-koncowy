<?php
/**
 * DZIAL 1 — AUDYT CALEGO PROJEKTU.
 *
 * Zrodlo (Golden Rule #2): audyt/docs/dzial-01/audyt.md — jeden plik na dzial,
 * czytany przez agentow i krytykow tego dzialu.
 *
 * Kazda para pilnuje JEDNEJ wlasciwosci. Gdy para zglasza problem, od razu
 * wiadomo czego dotyczy — dlatego par jest duzo, a nie kilka wielozadaniowych.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/* ===================================================================== 1.1 */

/**
 * A1.1 „inwentarz" — co w ogole jest w projekcie.
 *
 * Audyt bez inwentarza nie wie, czego NIE sprawdzil. Ten agent buduje spis,
 * z ktorego korzystaja pozostale pary, i on jeden czyta wszystkie pliki.
 */
final class MP_AU_A11_Inwentarz extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$spis = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			// BEZ `tests/`. Harness testowy deklaruje ATRAPY klas rdzenia
			// WordPressa (WP_Error, WP_User), zeby dalo sie uruchomic pipeline
			// poza WordPressem. Liczenie ich jako „klas wtyczki" dawalo falszywa
			// kolizje `WP_Error` miedzy wtyczkami — narzedzie krzyczalo na
			// wlasne rusztowanie testowe.
			$pliki = $kontekst->workspace->pliki_php( $branch, true );
			$wpis  = array(
				'plikow'     => count( $pliki ),
				'pliki'      => $pliki,
				'klasy'      => array(),
				'tabele'     => array(),
				'haki_out'   => array(),
				'haki_in'    => array(),
				'opcje'      => array(),
				'uprawnienia'=> array(),
				'cron'       => array(),
				'tworzone'   => array(),
			);

			foreach ( $pliki as $plik ) {
				$tresc = $kontekst->workspace->tresc( $plik, $kontekst );

				if ( preg_match_all( '/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/mi', $tresc, $t ) ) {
					foreach ( $t[1] as $klasa ) {
						// Tylko WLASNE klasy. Wszystko, co nie ma naszego prefiksu,
						// nalezy do WordPressa albo do biblioteki — nie odpowiadamy
						// za te nazwy i nie mozemy ich zglaszac jako kolizji.
						if ( 0 === stripos( $klasa, 'MP_' ) ) {
							$wpis['klasy'][] = $klasa;
						}
					}
				}

				if ( preg_match_all( '/[\'"]([a-z0-9_]*mp_[a-z0-9_]+)[\'"]\s*;?\s*$/mi', $tresc, $t ) ) {
					$wpis['opcje'] = array_merge( $wpis['opcje'], $t[1] );
				}

				if ( preg_match_all( '/\$wpdb->prefix\s*\.\s*[\'"]([a-z0-9_]+)[\'"]/i', $tresc, $t ) ) {
					$wpis['tabele'] = array_merge( $wpis['tabele'], $t[1] );
				}

				/*
				 * Tabele TWORZONE przez te wtyczke — to one moga kolidowac.
				 * Sama obecnosc nazwy w kodzie nic nie znaczy: wtyczka 3 czyta
				 * `wp_mp_ob_offers` wtyczki 2, a wtyczka 2 czyta `wp_mp_leads`
				 * wtyczki 1 i tak ma byc. Kolizja to dwie wtyczki zakladajace
				 * TE SAMA tabele, bo wtedy nadpisuja sobie schemat.
				 */
				if ( preg_match_all( '/CREATE TABLE\s+\{?\$([a-z_][a-z0-9_]*)\}?/i', $tresc, $t ) ) {
					foreach ( $t[1] as $zmienna ) {
						if ( preg_match( '/\$' . preg_quote( $zmienna, '/' ) . '\s*=\s*\$wpdb->prefix\s*\.\s*[\'"]([a-z0-9_]+)[\'"]/i', $tresc, $nazwa ) ) {
							$wpis['tworzone'][] = $nazwa[1];
						}
					}
				}

				if ( preg_match_all( '/CREATE TABLE\s+\{?\$wpdb->prefix\s*\.\s*[\'"]([a-z0-9_]+)[\'"]/i', $tresc, $t ) ) {
					$wpis['tworzone'] = array_merge( $wpis['tworzone'], $t[1] );
				}

				if ( preg_match_all( '/do_action\(\s*[\'"]([a-z0-9_]+)[\'"]/i', $tresc, $t ) ) {
					$wpis['haki_out'] = array_merge( $wpis['haki_out'], $t[1] );
				}

				if ( preg_match_all( '/add_action\(\s*[\'"]([a-z0-9_]+)[\'"]/i', $tresc, $t ) ) {
					$wpis['haki_in'] = array_merge( $wpis['haki_in'], $t[1] );
				}

				if ( preg_match_all( '/(?:add_cap|remove_cap|current_user_can)\(\s*[\'"]([a-z0-9_]+)[\'"]/i', $tresc, $t ) ) {
					$wpis['uprawnienia'] = array_merge( $wpis['uprawnienia'], $t[1] );
				}

				if ( preg_match_all( '/wp_(?:schedule_event|schedule_single_event|next_scheduled|clear_scheduled_hook)\(\s*[^,]*[\'"]([a-z0-9_]+)[\'"]/i', $tresc, $t ) ) {
					$wpis['cron'] = array_merge( $wpis['cron'], $t[1] );
				}
			}

			foreach ( array( 'klasy', 'tabele', 'haki_out', 'haki_in', 'opcje', 'uprawnienia', 'cron', 'tworzone' ) as $k ) {
				$wpis[ $k ] = array_values( array_unique( $wpis[ $k ] ) );
				sort( $wpis[ $k ] );
			}

			$spis[ $branch ] = $wpis;
		}

		$kontekst->ustaw( 'inwentarz', $spis );

		return MP_AU_Wynik::ok( array( 'inwentarz' => $spis ) );
	}
}

/**
 * K1.1 „pokrycie" — czy inwentarz obejmuje wszystkie trzy wtyczki.
 */
final class MP_AU_K11_Pokrycie extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$spis      = (array) ( $od_agenta->dane['inwentarz'] ?? array() );
		$ustalenia = array();

		if ( count( $spis ) < count( MP_AU_Workspace::WTYCZKI ) ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.1',
				'Audyt nie objal wszystkich trzech wtyczek.',
				MP_AU_Ustalenie::KRYTYCZNE,
				array(
					'dowod'      => 'wystawione: ' . implode( ', ', array_keys( $spis ) ),
					'scenariusz' => 'Werdykt audytu dotyczy CZESCI projektu, choc brzmi jak ocena calosci.',
				)
			);
		}

		foreach ( $spis as $branch => $wpis ) {
			if ( (int) $wpis['plikow'] < 5 ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.1',
					'Wtyczka ' . $branch . ' ma podejrzanie malo plikow PHP — worktree moze byc pusty.',
					MP_AU_Ustalenie::KRYTYCZNE,
					array(
						'dowod'      => 'plikow: ' . $wpis['plikow'],
						'scenariusz' => 'Pary ponizej „nie znajduja bledow", bo nie maja czego czytac.',
					)
				);
			}
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Inwentarz niekompletny.', $ustalenia, $od_agenta->dane );
	}
}

/* ===================================================================== 1.2 */

/**
 * A1.2 „skladnia" — `php -l` na kazdym pliku.
 */
final class MP_AU_A12_Skladnia extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$bledy     = array();
		$sprawdzone = 0;

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch ) as $plik ) {
				$wynik = $kontekst->workspace->polecenie( array( PHP_BINARY, '-l', $plik ) );
				++$sprawdzone;

				if ( 0 !== $wynik['kod'] ) {
					$bledy[] = array(
						'plik'    => $kontekst->workspace->wzgledna( $plik ),
						'wyjscie' => trim( $wynik['wyjscie'] ),
					);
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'sprawdzone' => $sprawdzone,
				'bledy'      => $bledy,
			)
		);
	}
}

/**
 * K1.2 „zero-bledow-skladni".
 */
final class MP_AU_K12_Skladnia extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['bledy'] ?? array() ) as $blad ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.2',
				'Blad skladni PHP.',
				MP_AU_Ustalenie::KRYTYCZNE,
				array(
					'plik'       => (string) $blad['plik'],
					'dowod'      => (string) $blad['wyjscie'],
					'scenariusz' => 'Wtyczka nie da sie aktywowac — biala strona po stronie klienta.',
					'status'     => MP_AU_Ustalenie::POTWIERDZONE,
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Bledy skladni.', $ustalenia, $od_agenta->dane );
	}
}

/* ===================================================================== 1.4 */

/**
 * A1.4 „kolizje" — nazwy wspoldzielone miedzy wtyczkami.
 */
final class MP_AU_A14_Kolizje extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$spis      = (array) $kontekst->pobierz( 'inwentarz', array() );
		$przeciecia = array();

		foreach ( array( 'klasy', 'tworzone', 'cron' ) as $rodzaj ) {
			$branche = array_keys( $spis );

			for ( $i = 0; $i < count( $branche ); $i++ ) {
				for ( $j = $i + 1; $j < count( $branche ); $j++ ) {
					$a = $spis[ $branche[ $i ] ][ $rodzaj ] ?? array();
					$b = $spis[ $branche[ $j ] ][ $rodzaj ] ?? array();
					$wspolne = array_values( array_intersect( $a, $b ) );

					if ( ! empty( $wspolne ) ) {
						$przeciecia[] = array(
							'rodzaj'  => $rodzaj,
							'branche' => $branche[ $i ] . ' + ' . $branche[ $j ],
							'nazwy'   => $wspolne,
						);
					}
				}
			}
		}

		return MP_AU_Wynik::ok( array( 'przeciecia' => $przeciecia ) );
	}
}

/**
 * K1.4 „przeciecia-puste".
 *
 * Uprawnienia i role sa CELOWO wspoldzielone (handlowiec obsluguje wszystkie
 * trzy moduly), wiec ich tu nie zglaszamy — pilnuje ich para 1.11 od strony
 * deinstalacji. Klasy, tabele i haki crona wspolne to zawsze blad.
 */
final class MP_AU_K14_Kolizje extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['przeciecia'] ?? array() ) as $p ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.4',
				'Kolizja nazw miedzy wtyczkami (' . $p['rodzaj'] . '): ' . implode( ', ', $p['nazwy'] ),
				MP_AU_Ustalenie::KRYTYCZNE,
				array(
					'dowod'      => $p['branche'] . ' -> ' . implode( ', ', $p['nazwy'] ),
					'scenariusz' => 'klasy' === $p['rodzaj']
						? 'Aktywacja obu wtyczek naraz konczy sie bledem krytycznym „cannot redeclare class".'
						: ( 'tworzone' === $p['rodzaj']
							? 'Dwie wtyczki ZAKLADAJA te sama tabele — druga aktywacja nadpisuje schemat pierwszej.'
							: 'Dwie wtyczki rejestruja ten sam hak crona i odbieraja sobie zadania.' ),
					'status'     => MP_AU_Ustalenie::POTWIERDZONE,
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Kolizje nazw.', $ustalenia, $od_agenta->dane );
	}
}

/* ===================================================================== 1.5 */

/**
 * A1.5 „kod kontra DDL" — kolumny uzywane w kodzie vs. kolumny w schemacie.
 *
 * Ta para istnieje z powodu konkretnego bledu: anonimizacja RODO filtrowala po
 * kolumnie `audience`, ktorej NIGDY nie bylo w DDL. Zapytanie padalo po cichu,
 * `$wpdb->update()` zwracalo `false`, `(int) false` dawalo 0 — i zadanie
 * usuniecia danych konczylo sie „sukcesem", zostawiajac adres klienta w bazie.
 */
final class MP_AU_A15_Kod_Kontra_DDL extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$schematy = array();
		$uzycia   = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$tresc = $kontekst->workspace->tresc( $plik, $kontekst );

				// DDL: bloki CREATE TABLE w plikach schematu.
				if ( preg_match_all( '/CREATE TABLE\s+[^(]{0,80}\((.+?)\)\s*[^;()]{0,120};/is', $tresc, $bloki ) ) {
					foreach ( $bloki[1] as $blok ) {
						if ( preg_match_all( '/^\s*([a-z_][a-z0-9_]*)\s+(?:bigint|int|tinyint|smallint|varchar|char|text|longtext|datetime|timestamp|date|decimal|float|double|enum|json)/mi', $blok, $kol ) ) {
							foreach ( $kol[1] as $kolumna ) {
								$schematy[ strtolower( $kolumna ) ] = true;
							}
						}
					}
				}

				// Uzycia: klucze tablic w $wpdb->update()/insert()/delete().
				if ( preg_match_all( '/\$wpdb->(update|insert|delete)\s*\((.{0,1200}?)\)\s*;/is', $tresc, $wywolania, PREG_SET_ORDER ) ) {
					foreach ( $wywolania as $w ) {
						if ( preg_match_all( '/[\'"]([a-z_][a-z0-9_]*)[\'"]\s*=>/i', $w[2], $klucze ) ) {
							foreach ( $klucze[1] as $klucz ) {
								$uzycia[] = array(
									'kolumna' => strtolower( $klucz ),
									'plik'    => $kontekst->workspace->wzgledna( $plik ),
									'metoda'  => $w[1],
									'linia'   => self::linia( $tresc, $w[0] ),
								);
							}
						}
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'kolumny_ddl' => array_keys( $schematy ),
				'uzycia'      => $uzycia,
			)
		);
	}

	/**
	 * Numer linii, w ktorej zaczyna sie fragment.
	 *
	 * @param string $tresc    Tresc pliku.
	 * @param string $fragment Fragment.
	 * @return int
	 */
	public static function linia( string $tresc, string $fragment ): int {
		$pozycja = strpos( $tresc, $fragment );

		return false === $pozycja ? 0 : substr_count( $tresc, "\n", 0, $pozycja ) + 1;
	}
}

/**
 * K1.5 „kolumna-istnieje".
 */
final class MP_AU_K15_Kod_Kontra_DDL extends MP_AU_Krytyk {

	/**
	 * Klucze, ktore nie sa kolumnami — argumenty formatujace i pola pomocnicze.
	 *
	 * @var string[]
	 */
	const NIE_KOLUMNY = array( '%s', '%d', '%f' );

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ddl = (array) ( $od_agenta->dane['kolumny_ddl'] ?? array() );

		if ( empty( $ddl ) ) {
			// Bez schematu nie ma z czym porownywac — mowimy to wprost,
			// zamiast raportowac „wszystko sie zgadza".
			return MP_AU_Wynik::nieocenione( 'Nie udalo sie odczytac zadnego CREATE TABLE — brak punktu odniesienia.' );
		}

		$ustalenia = array();
		$zgloszone = array();

		foreach ( (array) ( $od_agenta->dane['uzycia'] ?? array() ) as $u ) {
			$kolumna = (string) $u['kolumna'];

			if ( in_array( $kolumna, self::NIE_KOLUMNY, true ) || in_array( $kolumna, $ddl, true ) ) {
				continue;
			}

			$klucz = $kolumna . '|' . $u['plik'];

			if ( isset( $zgloszone[ $klucz ] ) ) {
				continue;
			}

			$zgloszone[ $klucz ] = true;

			$ustalenia[] = new MP_AU_Ustalenie(
				'1.5',
				'Kolumna „' . $kolumna . '" uzywana w kodzie NIE ISTNIEJE w zadnym CREATE TABLE projektu.',
				MP_AU_Ustalenie::KRYTYCZNE,
				array(
					'plik'       => (string) $u['plik'],
					'linia'      => (int) $u['linia'],
					'dowod'      => '$wpdb->' . $u['metoda'] . '() z kluczem ' . $kolumna,
					'scenariusz' => 'Zapytanie pada po cichu, metoda zwraca false, a `(int) false` daje 0 — '
						. 'operacja raportuje „zero zmienionych wierszy" zamiast bledu. Dokladnie tak '
						. 'anonimizacja RODO udawala sukces, zostawiajac dane klienta w bazie.',
					'status'     => MP_AU_Ustalenie::PRAWDOPODOBNE,
					'naprawa'    => 'Poprawic nazwe kolumny albo dodac ja do schematu i podbic wersje schematu.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Kod uzywa kolumn spoza schematu.', $ustalenia, $od_agenta->dane );
	}
}

/* ===================================================================== 1.7 */

/**
 * A1.7 „rejestracja hakow" — gdzie i z jakim priorytetem wpinane sa callbacki.
 *
 * Para istnieje z powodu bledu, ktory zabil krok 4 zlecenia: `boot()` wisial na
 * `init` z priorytetem 10 i dopinal w srodku `maybe_serve` na `init` z
 * priorytetem 5. Wg dokumentacji WordPressa (docs dzialu, zrodlo 1) nizszy
 * numer = wczesniejsze wykonanie — czyli moment tamtego callbacka juz minal.
 * Handler nie wykonal sie NIGDY, a klient klikal martwy link z e-maila.
 */
final class MP_AU_A17_Rejestracja_Hakow extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$podejrzane = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$tresc  = $kontekst->workspace->tresc( $plik, $kontekst );
				$wiersze = explode( "\n", $tresc );

				// Krok 1: funkcje/metody wpiete w haki wraz z ich priorytetem.
				$zewnetrzne = array();

				if ( preg_match_all(
					'/add_action\(\s*[\'"]([a-z0-9_]+)[\'"]\s*,\s*[\'"]?([A-Za-z0-9_\\\\]+)?[\'"]?[^;]*?(?:,\s*(\d+))?\s*\)\s*;/i',
					$tresc,
					$trafienia,
					PREG_SET_ORDER
				) ) {
					foreach ( $trafienia as $t ) {
						$zewnetrzne[] = array(
							'hak'       => strtolower( $t[1] ),
							'cel'       => $t[2] ?? '',
							'priorytet' => isset( $t[3] ) && '' !== $t[3] ? (int) $t[3] : 10,
							'linia'     => MP_AU_A15_Kod_Kontra_DDL::linia( $tresc, $t[0] ),
						);
					}
				}

				// Krok 2: ktore z tych wpiec siedza WEWNATRZ funkcji, ktora sama
				// jest callbackiem tego samego haka.
				foreach ( $zewnetrzne as $wpis ) {
					$wewnatrz = self::funkcja_otaczajaca( $wiersze, $wpis['linia'] );

					if ( '' === $wewnatrz ) {
						continue;
					}

					foreach ( $zewnetrzne as $rodzic ) {
						if ( $rodzic['hak'] !== $wpis['hak'] ) {
							continue;
						}

						if ( false === strpos( $rodzic['cel'], $wewnatrz ) && $rodzic['cel'] !== $wewnatrz ) {
							continue;
						}

						if ( $wpis['priorytet'] < $rodzic['priorytet'] ) {
							$podejrzane[] = array(
								'plik'             => $kontekst->workspace->wzgledna( $plik ),
								'linia'            => $wpis['linia'],
								'hak'              => $wpis['hak'],
								'priorytet_wpisu'  => $wpis['priorytet'],
								'priorytet_rodzica'=> $rodzic['priorytet'],
								'funkcja'          => $wewnatrz,
							);
						}
					}
				}
			}
		}

		return MP_AU_Wynik::ok( array( 'podejrzane' => $podejrzane ) );
	}

	/**
	 * Nazwa funkcji, wewnatrz ktorej lezy dana linia.
	 *
	 * Prosty skan w gore po deklaracji funkcji — wystarczajacy, bo szukamy
	 * wzorca „rejestracja z wnetrza callbacka", a nie pelnej analizy zasiegu.
	 *
	 * @param string[] $wiersze Wiersze pliku.
	 * @param int      $linia   Numer linii (1-indeksowany).
	 * @return string
	 */
	private static function funkcja_otaczajaca( array $wiersze, int $linia ): string {
		for ( $i = $linia - 1; $i >= 0; $i-- ) {
			if ( ! isset( $wiersze[ $i ] ) ) {
				continue;
			}

			if ( preg_match( '/^\s*(?:public |private |protected |static )*function\s+([A-Za-z0-9_]+)\s*\(/', $wiersze[ $i ], $t ) ) {
				return $t[1];
			}
		}

		return '';
	}
}

/**
 * K1.7 „hak-ma-szanse-sie-wykonac".
 */
final class MP_AU_K17_Rejestracja_Hakow extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['podejrzane'] ?? array() ) as $p ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.7',
				'Callback dopinany do „' . $p['hak'] . '" na priorytecie ' . $p['priorytet_wpisu']
					. ' z wnetrza callbacka tego samego haka o priorytecie ' . $p['priorytet_rodzica']
					. ' — nie wykona sie nigdy.',
				MP_AU_Ustalenie::KRYTYCZNE,
				array(
					'plik'       => (string) $p['plik'],
					'linia'      => (int) $p['linia'],
					'dowod'      => 'rejestracja wewnatrz ' . $p['funkcja'] . '(), priorytet '
						. $p['priorytet_wpisu'] . ' < ' . $p['priorytet_rodzica'],
					'scenariusz' => 'Hak odpala sie raz na zadanie, a moment priorytetu '
						. $p['priorytet_wpisu'] . ' juz minal, gdy rejestracja nastepuje. '
						. 'Funkcja wyglada na wpieta, a nie wykonuje sie ani razu.',
					'status'     => MP_AU_Ustalenie::PRAWDOPODOBNE,
					'naprawa'    => 'Przeniesc rejestracje na poziom ladowania pliku wtyczki '
						. 'albo nadac priorytet wyzszy niz priorytet callbacka otaczajacego.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Haki bez szansy na wykonanie.', $ustalenia, $od_agenta->dane );
	}
}

/* ===================================================================== 1.8 */

/**
 * A1.8 „wzorce SQL" — przygotowanie zapytan i KSZTALT warunkow.
 *
 * `prepare()` chroni przed wstrzyknieciem, ale nie przed bledna logika warunku.
 * Realny przypadek: `WHERE request_id = %s OR id = %d` z tym samym uchwytem
 * podstawionym pod oba symbole. Uchwyt byl UUID-em, `(int) '120f3e8a…'` dawalo
 * 120 — i klient z waznym, podpisanym linkiem dostawal dokument innej firmy.
 */
final class MP_AU_A18_Wzorce_SQL extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$alternatywy = array();
		$bez_prepare = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$tresc    = $kontekst->workspace->tresc( $plik, $kontekst );
				$wzgledna = $kontekst->workspace->wzgledna( $plik );

				// (a) WHERE z alternatywa mieszajaca typy symboli.
				/*
				 * Wzorzec musi opisywac DOKLADNIE ten blad, ktory scigamy:
				 * ten sam uchwyt porownywany raz jako tekst, raz jako liczba,
				 * w jednej alternatywie. Wczesniejsza, luzniejsza wersja lapala
				 * kazde zapytanie, w ktorym gdziekolwiek bylo %s, potem OR,
				 * a na koncu `LIMIT %d` — czyli zglaszala poprawny kod jako blad
				 * krytyczny. Falszywy alarm w audycie bezpieczenstwa jest
				 * kosztowny: uczy ignorowac zgloszenia.
				 */
				if ( preg_match_all( '/=\s*%s[^;"\']{0,60}?\bOR\b[^;"\']{0,40}?=\s*%d/i', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[0] as $trafienie ) {
						$alternatywy[] = array(
							'plik'     => $wzgledna,
							'linia'    => substr_count( $tresc, "\n", 0, (int) $trafienie[1] ) + 1,
							'fragment' => trim( (string) $trafienie[0] ),
						);
					}
				}

				// (b) zapytanie ze zmienna, ale bez prepare() w poblizu.
				if ( preg_match_all( '/\$wpdb->(?:get_var|get_row|get_col|get_results|query)\s*\(\s*(["\'])(.{0,400}?)\1\s*\)/is', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						if ( preg_match( '/\$(?!wpdb->prefix)[a-z_][a-z0-9_]*/i', $trafienie[2] )
							&& false === strpos( $trafienie[0], 'prepare' ) ) {
							$bez_prepare[] = array(
								'plik'     => $wzgledna,
								'linia'    => MP_AU_A15_Kod_Kontra_DDL::linia( $tresc, $trafienie[0] ),
								'fragment' => trim( substr( $trafienie[0], 0, 160 ) ),
							);
						}
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'alternatywy' => $alternatywy,
				'bez_prepare' => $bez_prepare,
			)
		);
	}
}

/**
 * K1.8 „warunek-jednoznaczny".
 */
final class MP_AU_K18_Wzorce_SQL extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['alternatywy'] ?? array() ) as $a ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.8',
				'Warunek WHERE laczy alternatywa symbol tekstowy i liczbowy — ten sam uchwyt trafia w dwa rozne wiersze.',
				MP_AU_Ustalenie::KRYTYCZNE,
				array(
					'plik'       => (string) $a['plik'],
					'linia'      => (int) $a['linia'],
					'dowod'      => (string) $a['fragment'],
					'scenariusz' => 'Uchwyt UUID rzutowany na int daje wiodace cyfry, wiec `OR id = %d` '
						. 'trafia w CUDZY wiersz. Podpis broni przed podrobieniem uchwytu, nie przed '
						. 'rzutowaniem typu po stronie SQL. Klient dostaje dokument innej firmy.',
					'status'     => MP_AU_Ustalenie::PRAWDOPODOBNE,
					'naprawa'    => 'Rozdzielic na dwie galezie po `ctype_digit()` — nigdy dwa warunki naraz.',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['bez_prepare'] ?? array() ) as $b ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.8',
				'Zapytanie ze zmienna bez `prepare()`.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $b['plik'],
					'linia'      => (int) $b['linia'],
					'dowod'      => (string) $b['fragment'],
					'scenariusz' => 'Wartosc sterowana przez uzytkownika trafia do zapytania bez przygotowania (CWE-89).',
					'status'     => MP_AU_Ustalenie::PRAWDOPODOBNE,
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Podejrzane wzorce SQL.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.10 */

/**
 * A1.10 „granice transakcji" — czy zdarzenie wychodzi w otwartej transakcji.
 *
 * Wg dokumentacji MySQL (docs dzialu, zrodlo 2) `START TRANSACTION` robi
 * NIEJAWNY COMMIT transakcji juz otwartej. Subskrybent haka, ktory otwiera
 * wlasna transakcje, zatwierdza wiec cudza — i gwarancja atomowosci emitenta
 * przestaje obowiazywac bez zadnego sygnalu.
 */
final class MP_AU_A110_Granice_Transakcji extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$wewnatrz = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$tresc = $kontekst->workspace->tresc( $plik, $kontekst );

				if ( false === stripos( $tresc, 'START TRANSACTION' ) ) {
					continue;
				}

				$wiersze  = explode( "\n", $tresc );
				$otwarta  = false;
				$linia_ot = 0;

				foreach ( $wiersze as $nr => $wiersz ) {
					if ( preg_match( '/[\'"]START TRANSACTION[\'"]/i', $wiersz ) ) {
						$otwarta  = true;
						$linia_ot = $nr + 1;
						continue;
					}

					if ( preg_match( '/[\'"](?:COMMIT|ROLLBACK)[\'"]/i', $wiersz ) ) {
						$otwarta = false;
						continue;
					}

					if ( $otwarta && preg_match( '/do_action\(\s*[\'"]([a-z0-9_]+)[\'"]/i', $wiersz, $t ) ) {
						$wewnatrz[] = array(
							'plik'     => $kontekst->workspace->wzgledna( $plik ),
							'linia'    => $nr + 1,
							'hak'      => $t[1],
							'od_linii' => $linia_ot,
						);
					}
				}
			}
		}

		return MP_AU_Wynik::ok( array( 'wewnatrz' => $wewnatrz ) );
	}
}

/**
 * K1.10 „zdarzenie-po-COMMIT".
 */
final class MP_AU_K110_Granice_Transakcji extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['wewnatrz'] ?? array() ) as $w ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.10',
				'Zdarzenie „' . $w['hak'] . '" wystawiane WEWNATRZ otwartej transakcji.',
				MP_AU_Ustalenie::KRYTYCZNE,
				array(
					'plik'       => (string) $w['plik'],
					'linia'      => (int) $w['linia'],
					'dowod'      => 'START TRANSACTION w linii ' . $w['od_linii'] . ', do_action w linii ' . $w['linia'],
					'scenariusz' => 'Subskrybent otwierajacy wlasna transakcje robi NIEJAWNY COMMIT tej '
						. 'transakcji (MySQL: transakcje nie sa zagniezdzone). Gwarancja atomowosci '
						. 'przestaje obowiazywac, a ROLLBACK emitenta kasuje wiersze w CUDZEJ bazie. '
						. 'Wyjatek subskrybenta niszczy zapis, ktory byl poprawny.',
					'status'     => MP_AU_Ustalenie::PRAWDOPODOBNE,
					'naprawa'    => 'Zamknac transakcje przed emisja (prog `transactional_until`) '
						. 'i opakowac nasluchy w try/catch.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Zdarzenia w otwartej transakcji.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.13 */

/**
 * A1.13 „spojnosc wersji" — numer wersji zyje w czterech miejscach naraz.
 */
final class MP_AU_A113_Wersje extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$wersje = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			$katalog = $kontekst->workspace->katalog( $branch );
			$glowny  = $katalog . '/' . basename( $katalog ) . '.php';
			$readme  = $katalog . '/readme.txt';

			$wpis = array(
				'naglowek'  => '',
				'stala'     => '',
				'stable'    => '',
				'changelog' => '',
			);

			if ( is_readable( $glowny ) ) {
				$tresc = $kontekst->workspace->tresc( $glowny, $kontekst );

				if ( preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/mi', $tresc, $t ) ) {
					$wpis['naglowek'] = $t[1];
				}

				if ( preg_match( '/define\(\s*[\'"]MP_[A-Z_]*VERSION[\'"]\s*,\s*[\'"]([0-9.]+)[\'"]/', $tresc, $t ) ) {
					$wpis['stala'] = $t[1];
				}
			}

			if ( is_readable( $readme ) ) {
				$tresc = $kontekst->workspace->tresc( $readme, $kontekst );

				if ( preg_match( '/^Stable tag:\s*([0-9.]+)/mi', $tresc, $t ) ) {
					$wpis['stable'] = $t[1];
				}

				if ( preg_match( '/^=\s*([0-9.]+)\s*=/m', $tresc, $t ) ) {
					$wpis['changelog'] = $t[1];
				}
			}

			$wersje[ $branch ] = $wpis;
		}

		return MP_AU_Wynik::ok( array( 'wersje' => $wersje ) );
	}
}

/**
 * K1.13 „cztery-miejsca-zgodne".
 */
final class MP_AU_K113_Wersje extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['wersje'] ?? array() ) as $branch => $w ) {
			$wartosci = array_filter( array( $w['naglowek'], $w['stala'], $w['stable'] ) );

			if ( count( $wartosci ) < 3 ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.13',
					'Wtyczka ' . $branch . ': nie udalo sie odczytac numeru wersji ze wszystkich trzech miejsc.',
					MP_AU_Ustalenie::DROBNE,
					array(
						'dowod'      => json_encode( $w, JSON_UNESCAPED_UNICODE ),
						'scenariusz' => 'Brak jednego zrodla prawdy o wersji utrudnia wsparcie po wdrozeniu.',
					)
				);
				continue;
			}

			if ( count( array_unique( $wartosci ) ) > 1 ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.13',
					'Wtyczka ' . $branch . ': numer wersji rozjechal sie miedzy miejscami.',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'dowod'      => 'naglowek=' . $w['naglowek'] . ' stala=' . $w['stala'] . ' stable=' . $w['stable'],
						'scenariusz' => 'WordPress pokazuje jedna wersje, kod raportuje inna — '
							. 'diagnoza zgloszenia od klienta zaczyna sie od falszywej informacji.',
						'status'     => MP_AU_Ustalenie::POTWIERDZONE,
						'naprawa'    => 'Zsynchronizowac Version, stala MP_*_VERSION i Stable tag.',
					)
				);
			}

			if ( '' !== $w['changelog'] && $w['changelog'] !== $w['naglowek'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.13',
					'Wtyczka ' . $branch . ': najnowszy wpis changelogu (' . $w['changelog']
						. ') nie odpowiada wersji wtyczki (' . $w['naglowek'] . ').',
					MP_AU_Ustalenie::DROBNE,
					array(
						'scenariusz' => 'Wydanie bez wpisu w changelogu — klient nie wie, co sie zmienilo.',
						'status'     => MP_AU_Ustalenie::POTWIERDZONE,
					)
				);
			}
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Niespojnosc wersji.', $ustalenia, $od_agenta->dane );
	}
}

/* ================================================================== DZIAL */

/**
 * Fabryka Dzialu 1.
 */
final class MP_AU_Dzial_01 {

	/**
	 * Sklada komplet 17 par.
	 *
	 * Kolejnosc nie jest przypadkowa: najpierw inwentarz i skladnia (bez nich
	 * reszta ocenia niepelny albo niepoprawny kod), potem kontrole strukturalne,
	 * na koncu te, ktore wymagaja narzedzi zewnetrznych i modelu. Dzieki temu
	 * przebieg przerwany w polowie zdazyl juz powiedziec rzeczy najwazniejsze.
	 *
	 * @return MP_AU_Dzial
	 */
	public static function zbuduj(): MP_AU_Dzial {
		$dzial = new MP_AU_Dzial( 1, 'Audyt calego projektu' );

		// --- Fundament: co w ogole mamy i czy sie parsuje.
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A11_Inwentarz( '1.1', 'inwentarz' ), new MP_AU_K11_Pokrycie( '1.1', 'pokrycie' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A12_Skladnia( '1.2', 'skladnia' ), new MP_AU_K12_Skladnia( '1.2', 'zero-bledow-skladni' ), MP_AU_Para::PELNY ) );

		// --- Struktura i granice miedzy wtyczkami.
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A14_Kolizje( '1.4', 'kolizje-nazw' ), new MP_AU_K14_Kolizje( '1.4', 'przeciecia-puste' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A15_Kod_Kontra_DDL( '1.5', 'kod-kontra-DDL' ), new MP_AU_K15_Kod_Kontra_DDL( '1.5', 'kolumna-istnieje' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A16_Kontrakty_Hakow( '1.6', 'kontrakty-hakow' ), new MP_AU_K16_Kontrakty_Hakow( '1.6', 'obie-strony-umowy-zgodne' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A17_Rejestracja_Hakow( '1.7', 'rejestracja-hakow' ), new MP_AU_K17_Rejestracja_Hakow( '1.7', 'hak-ma-szanse-sie-wykonac' ) ) );

		// --- Dane, zapisy, wyscigi.
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A18_Wzorce_SQL( '1.8', 'wzorce-SQL' ), new MP_AU_K18_Wzorce_SQL( '1.8', 'warunek-jednoznaczny' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A110_Granice_Transakcji( '1.10', 'granice-transakcji' ), new MP_AU_K110_Granice_Transakcji( '1.10', 'zdarzenie-po-COMMIT' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A118_Idempotencja( '1.18', 'idempotencja-i-blokada' ), new MP_AU_K118_Idempotencja( '1.18', 'zapis-sprawdza-stan-z-chwili-odczytu' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A123_Statusy( '1.23', 'slownik-statusow' ), new MP_AU_K123_Statusy( '1.23', 'status-ze-slownika' ) ) );

		// --- Szkoda dla uzytkownika: dostep, dane osobowe, pieniadze.
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A19_Bezpieczenstwo( '1.9', 'punkty-wejscia' ), new MP_AU_K19_Bezpieczenstwo( '1.9', 'kazdy-punkt-wejscia-broniony' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A111_Rodo( '1.11', 'RODO' ), new MP_AU_K111_Rodo( '1.11', 'dane-znikaja-po-obu-stronach' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A112_Pieniadze( '1.12', 'arytmetyka-pieniedzy' ), new MP_AU_K112_Pieniadze( '1.12', 'kwota-w-jednej-jednostce' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A119_Obsluga_Bledow( '1.19', 'obsluga-bledow' ), new MP_AU_K119_Obsluga_Bledow( '1.19', 'porazka-musi-byc-widoczna' ) ) );

		// --- Utrzymanie: wersje, cykl zycia, licencje, dokumentacja, i18n.
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A113_Wersje( '1.13', 'spojnosc-wersji' ), new MP_AU_K113_Wersje( '1.13', 'cztery-miejsca-zgodne' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A121_Cykl_Zycia( '1.21', 'cykl-zycia' ), new MP_AU_K121_Cykl_Zycia( '1.21', 'wylaczenie-nie-niszczy-sasiada' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A114_Licencje( '1.14', 'licencje' ), new MP_AU_K114_Licencje( '1.14', 'zgodna-z-zaleznosciami' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A117_Dokumentacja( '1.17', 'dokumentacja' ), new MP_AU_K117_Dokumentacja( '1.17', 'jeden-plik-na-dzial' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A120_I18n( '1.20', 'i18n' ), new MP_AU_K120_I18n( '1.20', 'domena-zgodna-ze-slugiem' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A122_Martwy_Kod( '1.22', 'kod-nieuzywany' ), new MP_AU_K122_Martwy_Kod( '1.22', 'kod-ktory-nikogo-nie-obchodzi' ) ) );

		// --- Audyt samych testow i dawnych bledow.
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A124_Jakosc_Testow( '1.24', 'jakosc-testow' ), new MP_AU_K124_Jakosc_Testow( '1.24', 'test-musi-umiec-nie-przejsc' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A115_Rejestr( '1.15', 'rejestr-kontra-testy' ), new MP_AU_K115_Rejestr( '1.15', 'kazdy-dawny-blad-ma-test' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A116_Wydajnosc( '1.16', 'zapytania-w-petli' ), new MP_AU_K116_Wydajnosc( '1.16', 'narzut-liniowy' ) ) );

		// --- Narzedzia zewnetrzne i model: najdrozsze, wiec na koncu.
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A13_Phpcs( '1.3', 'PHPCS-WPCS' ), new MP_AU_K13_Phpcs( '1.3', 'zero-bledow-standardu' ), MP_AU_Para::PELNY ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A125_Semantyka( '1.25', 'semantyka-dzialow' ), new MP_AU_K125_Semantyka( '1.25', 'kod-robi-to-co-deklaruje' ), MP_AU_Para::GLEBOKI ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A126_Komunikaty( '1.26', 'komunikaty-dla-czlowieka' ), new MP_AU_K126_Komunikaty( '1.26', 'klient-nie-zobaczy-pustego-miejsca' ), MP_AU_Para::GLEBOKI ) );

		return $dzial;
	}
}
