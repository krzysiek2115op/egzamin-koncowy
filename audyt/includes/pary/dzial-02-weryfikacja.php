<?php
/**
 * Dzial 2, pary 2.2, 2.4, 2.6, 2.7, 2.8, 2.9, 2.10.
 *
 * Dzial 2 nie szuka bledow w projekcie. Szuka bledow W AUDYCIE. To rozroznienie
 * jest cala jego wartoscia: w pierwszym przebiegu Dzial 1 zglosil 14 ustalen
 * krytycznych, z ktorych Dzial 2 odrzucil 14. Raport bez tego dzialu straszylby
 * czternastoma nieistniejacymi bledami — i po drugim takim raporcie nikt by juz
 * zadnego nie czytal.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/* ===================================================================== 2.2 */

/**
 * A2.2 „falszywe alarmy" — czy ustalenie w ogole wskazuje istniejace miejsce.
 *
 * Najtansza i najskuteczniejsza kontrola calego re-audytu. Sprawdza rzeczy,
 * ktorych zaden agent Dzialu 1 nie sprawdza o sobie: czy plik istnieje, czy
 * linia miesci sie w pliku, czy dowod da sie w tym pliku odnalezc.
 */
final class MP_AU_A22_Falszywe_Alarmy extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$sprawdzenia = array();
		$widziane    = array();

		foreach ( $kontekst->ustalenia() as $u ) {
			if ( MP_AU_Ustalenie::ODRZUCONE === $u->status ) {
				continue;
			}

			$wynik = array(
				'klucz'    => $u->klucz(),
				'werdykt'  => 'nieokreslony',
				'powod'    => '',
			);

			// (a) Duplikat: to samo miejsce zgloszone przez kilka par.
			$odcisk = $u->plik . ':' . $u->linia . ':' . substr( $u->opis, 0, 40 );

			if ( '' !== $u->plik && $u->linia > 0 && isset( $widziane[ $odcisk ] ) ) {
				$wynik['werdykt'] = 'duplikat';
				$wynik['powod']   = 'To samo miejsce zglosila juz para ' . $widziane[ $odcisk ] . '.';
				$sprawdzenia[]    = $wynik;
				continue;
			}

			$widziane[ $odcisk ] = $u->para;

			if ( '' === $u->plik ) {
				$wynik['werdykt'] = 'bez-pliku';
				$wynik['powod']   = 'Ustalenie ogolne, nie wskazuje pliku — nie da sie sprawdzic ta metoda.';
				$sprawdzenia[]    = $wynik;
				continue;
			}

			$pelna = $this->pelna_sciezka( $kontekst, $u->plik );

			if ( '' === $pelna ) {
				$wynik['werdykt'] = 'plik-nie-istnieje';
				$wynik['powod']   = 'Wskazanego pliku nie ma na czubku zadnej z galezi.';
				$sprawdzenia[]    = $wynik;
				continue;
			}

			$tresc  = $kontekst->workspace->tresc( $pelna, $kontekst );
			$wierszy = substr_count( $tresc, "\n" ) + 1;

			if ( $u->linia > $wierszy ) {
				$wynik['werdykt'] = 'linia-poza-plikiem';
				$wynik['powod']   = 'Linia ' . $u->linia . ' przy ' . $wierszy . ' wierszach pliku.';
				$sprawdzenia[]    = $wynik;
				continue;
			}

			// (b) Czy zglaszane miejsce to komentarz? Wzorzec, ktory trafil
			// w komentarz, opisuje zdanie po polsku, a nie kod. To byl realny
			// blad tego narzedzia: asercja testu trafila we wlasny komentarz.
			$wiersze = explode( "\n", $tresc );
			$wiersz  = $wiersze[ max( 0, $u->linia - 1 ) ] ?? '';

			if ( $u->linia > 0 && preg_match( '/^\s*(\*|\/\/|\/\*)/', $wiersz ) ) {
				$wynik['werdykt'] = 'komentarz';
				$wynik['powod']   = 'Wskazana linia jest komentarzem: ' . MP_AU_Pomoc::skrot( $wiersz, 80 );
				$sprawdzenia[]    = $wynik;
				continue;
			}

			$wynik['werdykt'] = 'miejsce-istnieje';
			$wynik['powod']   = 'Plik i linia potwierdzone niezaleznym odczytem.';
			$sprawdzenia[]    = $wynik;
		}

		return MP_AU_Wynik::ok( array( 'sprawdzenia' => $sprawdzenia ) );
	}

	/**
	 * Zamienia sciezke wzgledna z raportu na sciezke w worktree.
	 *
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @param string         $wzgledna Sciezka wzgledna.
	 * @return string
	 */
	private function pelna_sciezka( MP_AU_Kontekst $kontekst, string $wzgledna ): string {
		foreach ( $kontekst->workspace->branche() as $branch ) {
			$katalog = $kontekst->workspace->katalog( $branch );

			if ( '' === $katalog ) {
				continue;
			}

			// Sciezka w raporcie ma postac „branch/reszta" (katalog worktree
			// i katalog wtyczki nazywaja sie tak samo, wiec powtorzenie jest
			// zwijane przy zapisie). Odtwarzamy oba warianty, bo raporty
			// z poprzednich wersji narzedzia niosa jeszcze ten stary zapis.
			$reszta = preg_replace( '#^' . preg_quote( $branch, '#' ) . '/#', '', $wzgledna );

			$kandydaci = array(
				$katalog . '/' . $reszta,
				dirname( $katalog ) . '/' . $reszta,
				dirname( dirname( $katalog ) ) . '/' . $wzgledna,
				$katalog . '/' . $wzgledna,
			);

			foreach ( $kandydaci as $kandydat ) {
				if ( is_file( $kandydat ) ) {
					return $kandydat;
				}
			}
		}

		return '';
	}
}

/**
 * K2.2 „zgloszenie-musi-wskazywac-istniejace-miejsce".
 */
final class MP_AU_K22_Falszywe_Alarmy extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$po_kluczu = array();

		foreach ( (array) ( $od_agenta->dane['sprawdzenia'] ?? array() ) as $s ) {
			$po_kluczu[ $s['klucz'] ] = $s;
		}

		/*
		 * BEZPIECZNIK. Ta para ma prawo odrzucac ustalenia — ale gdy odrzuca
		 * niemal WSZYSTKO z powodu „pliku nie ma", to prawie na pewno zepsulo sie
		 * odwzorowanie sciezek w narzedziu, a nie caly Dzial 1 naraz. Bez tego
		 * bezpiecznika audyt raportowalby uspokajajaca cisze przy kompletnie
		 * niedzialajacej weryfikacji. Ten przypadek WYSTAPIL podczas budowy tej
		 * pary i kosztowal 77 skasowanych ustalen.
		 */
		$brakujacych = 0;
		$wszystkich  = count( $po_kluczu );

		foreach ( $po_kluczu as $s ) {
			if ( 'plik-nie-istnieje' === $s['werdykt'] ) {
				++$brakujacych;
			}
		}

		if ( $wszystkich > 4 && $brakujacych / $wszystkich > 0.5 ) {
			return MP_AU_Wynik::blad(
				'Weryfikacja sciezek nie dziala — para 2.2 nie odrzuca niczego.',
				array(
					new MP_AU_Ustalenie(
						'2.2',
						'AWARIA NARZEDZIA: para 2.2 nie potrafi odnalezc ' . $brakujacych . ' z ' . $wszystkich . ' zglaszanych plikow.',
						MP_AU_Ustalenie::KRYTYCZNE,
						array(
							'dowod'      => 'Odsetek nieodnalezionych plikow: ' . round( 100 * $brakujacych / $wszystkich ) . '%.',
							'scenariusz' => 'Re-audyt odrzucilby prawie kazde ustalenie Dzialu 1 jako falszywy '
								. 'alarm i wydal uspokajajacy werdykt na podstawie wlasnej awarii. '
								. 'Zadne ustalenie NIE zostalo w tym przebiegu odrzucone — wszystkie '
								. 'wymagaja recznego przejrzenia.',
							'status'     => MP_AU_Ustalenie::POTWIERDZONE,
							'naprawa'    => 'Poprawic odwzorowanie sciezek w MP_AU_A22_Falszywe_Alarmy::pelna_sciezka().',
						)
					),
				)
			);
		}

		$licznik = array(
			'odrzucone'   => 0,
			'potwierdzone_miejsce' => 0,
		);

		foreach ( $kontekst->ustalenia() as $u ) {
			$s = $po_kluczu[ $u->klucz() ] ?? null;

			if ( null === $s ) {
				continue;
			}

			if ( in_array( $s['werdykt'], array( 'plik-nie-istnieje', 'linia-poza-plikiem', 'komentarz', 'duplikat' ), true ) ) {
				$u->status = MP_AU_Ustalenie::ODRZUCONE;
				$u->dowod .= "\n[2.2 falszywy alarm] " . $s['werdykt'] . ': ' . $s['powod'];
				++$licznik['odrzucone'];
				continue;
			}

			if ( 'miejsce-istnieje' === $s['werdykt'] ) {
				$u->dowod .= "\n[2.2] miejsce potwierdzone niezaleznym odczytem pliku.";
				++$licznik['potwierdzone_miejsce'];
			}
		}

		return MP_AU_Wynik::ok( $licznik );
	}
}

/* ===================================================================== 2.4 */

/**
 * A2.4 „powtarzalnosc" — czy drugi przebieg da ten sam wynik.
 *
 * Audyt, ktorego wynik zmienia sie bez zmiany kodu, nie nadaje sie do niczego:
 * nie da sie na jego podstawie stwierdzic, czy naprawa pomogla. Ta para
 * uruchamia pary Dzialu 1 DRUGI RAZ na tym samym stanie i porownuje klucze.
 *
 * Uwaga: ta sama choroba dotknela nasze testy (wpis TEST-F2) — staly numer
 * oferty w danych testowych sprawial, ze drugie uruchomienie dawalo 10 FAIL
 * bez jednej zmiany w kodzie.
 */
final class MP_AU_A24_Powtarzalnosc extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$dzial = $kontekst->pobierz( 'dzial_1' );

		if ( ! $dzial instanceof MP_AU_Dzial ) {
			return MP_AU_Wynik::nieocenione(
				'Brak referencji do Dzialu 1 w kontekscie — nie da sie powtorzyc jego przebiegu.'
			);
		}

		// Drugi przebieg w OSOBNYM kontekscie, zeby nie dopisac ustalen po raz
		// drugi do raportu. Model wylaczony: pytamy o powtarzalnosc REGUL,
		// a odpowiedz modelu z natury nie jest deterministyczna.
		$osobny = new MP_AU_Kontekst(
			$kontekst->workspace,
			new MP_AU_Model_Client( $kontekst->workspace, sys_get_temp_dir() . '/mp-au-powtorka', MP_AU_Model_Client::TRYB_BRAK ),
			'powtorka',
			MP_AU_Para::PELNY
		);

		$przebieg = $dzial->uruchom( $osobny );

		$pierwszy = array();
		$drugi    = array();

		/*
		 * Porownujemy WYLACZNIE ustalenia Dzialu 1 — bo tylko jego przebieg
		 * powtarzamy. Wciaganie do porownania ustalen Dzialu 2 dawalo obraz
		 * absurdalny: „zniknelo ustalenie 2.8", ktore w powtorce nie mialo prawa
		 * powstac. I nie odsiewamy odrzuconych: dla powtarzalnosci liczy sie to,
		 * co agent ZNALAZL, a nie to, co krytyk potem z tym zrobil.
		 */
		foreach ( $kontekst->ustalenia() as $u ) {
			if ( 0 !== strpos( $u->para, '1.' ) ) {
				continue;
			}

			$pierwszy[ $u->klucz() ] = $u->opis;
		}

		foreach ( $osobny->ustalenia() as $u ) {
			if ( 0 !== strpos( $u->para, '1.' ) ) {
				continue;
			}

			$drugi[ $u->klucz() ] = $u->opis;
		}

		return MP_AU_Wynik::ok(
			array(
				'w_pierwszym'  => count( $pierwszy ),
				'w_drugim'     => count( $drugi ),
				'tylko_pierwszy' => array_slice( array_diff_key( $pierwszy, $drugi ), 0, 10, true ),
				'tylko_drugi'    => array_slice( array_diff_key( $drugi, $pierwszy ), 0, 10, true ),
				'nieocenione_w_powtorce' => (int) $przebieg['nieocenione'],
			)
		);
	}
}

/**
 * K2.4 „ten-sam-stan-ten-sam-wynik".
 */
final class MP_AU_K24_Powtarzalnosc extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();
		$tylko_1   = (array) ( $od_agenta->dane['tylko_pierwszy'] ?? array() );
		$tylko_2   = (array) ( $od_agenta->dane['tylko_drugi'] ?? array() );

		// Ustalenia z par modelowych i z par wymagajacych narzedzi zewnetrznych
		// nie musza sie powtorzyc w przebiegu PELNYM — to nie jest niestabilnosc.
		$istotne_1 = array_filter(
			$tylko_1,
			static function ( $opis, $klucz ) {
				return 0 !== strpos( (string) $klucz, '1.25:' )
					&& 0 !== strpos( (string) $klucz, '1.26:' )
					&& 0 !== strpos( (string) $klucz, '1.3:' );
			},
			ARRAY_FILTER_USE_BOTH
		);

		foreach ( array_slice( $istotne_1, 0, 5, true ) as $klucz => $opis ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'2.4',
				'Ustalenie zniknelo w powtorzonym przebiegu: ' . MP_AU_Pomoc::skrot( (string) $opis, 90 ),
				MP_AU_Ustalenie::SREDNIE,
				array(
					'dowod'      => 'klucz ' . $klucz . ' obecny w przebiegu 1, nieobecny w przebiegu 2',
					'scenariusz' => 'Kontrola zalezy od czegos poza kodem projektu (kolejnosc plikow, czas, '
						. 'stan srodowiska). Na jej podstawie nie da sie stwierdzic, czy naprawa dziala.',
					'status'     => MP_AU_Ustalenie::POTWIERDZONE,
					'naprawa'    => 'Usunac z agenta zaleznosc od kolejnosci albo od stanu zewnetrznego.',
				)
			);
		}

		foreach ( array_slice( $tylko_2, 0, 5, true ) as $klucz => $opis ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'2.4',
				'Ustalenie pojawilo sie dopiero w powtorzonym przebiegu: ' . MP_AU_Pomoc::skrot( (string) $opis, 90 ),
				MP_AU_Ustalenie::SREDNIE,
				array(
					'dowod'      => 'klucz ' . $klucz . ' nieobecny w przebiegu 1, obecny w przebiegu 2',
					'scenariusz' => 'Audyt zglasza rzeczy losowo. Czesc problemow pozostaje niewykryta '
						. 'w dowolnym pojedynczym przebiegu.',
					'status'     => MP_AU_Ustalenie::POTWIERDZONE,
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Audyt nie jest w pelni powtarzalny.', $ustalenia, $od_agenta->dane );
	}
}

/* ===================================================================== 2.6 */

/**
 * A2.6 „czy naprawa ma dowod" — archeologia gitowa zamiast psucia repozytorium.
 *
 * Wzorcowy dowod, ze test naprawde lapie blad, wyglada tak: cofnij naprawe,
 * uruchom test, zobacz FAIL, przywroc naprawe. Robilismy to recznie przez
 * `git stash` przy kazdej z osmiu napraw.
 *
 * Automat tego NIE ROBI i nie zrobi. Powod jest twardy: cofanie naprawy
 * w repozytorium, na ktorym ktos pracuje, to operacja niszczaca, a testy tego
 * projektu wymagaja zywego WordPressa z baza — czego audyt nie ma prawa zakladac.
 * Zamiast udawac taka weryfikacje, para sprawdza SLAD PO NIEJ w historii gita:
 * czy test przyszedl razem z naprawa, w jednym commicie.
 *
 * Co to udowadnia: ze autor mial oba pliki na biurku naraz.
 * Czego NIE udowadnia: ze test faktycznie FAIL-owal przed naprawa.
 * Ta granica jest wypisana wprost, bo audyt, ktory obiecuje wiecej niz daje,
 * jest gorszy od audytu, ktorego nie ma.
 */
final class MP_AU_A26_Dowod_Naprawy extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$sciezka = MP_AU_A115_Rejestr::sciezka_rejestru();

		if ( ! is_readable( $sciezka ) ) {
			return MP_AU_Wynik::nieocenione( 'Brak rejestru znanych bledow — nie ma czego weryfikowac.' );
		}

		$kontekst->policz_odczyt();
		$rejestr = json_decode( (string) file_get_contents( $sciezka ), true );
		$wyniki  = array();

		foreach ( (array) ( $rejestr['bledy'] ?? array() ) as $blad ) {
			$test    = trim( (string) preg_replace( '/\s*\(.*\)$/', '', (string) ( $blad['test'] ?? '' ) ) );
			$zrodlo  = (string) ( $blad['plik'] ?? '' );
			$wtyczka = (string) ( $blad['wtyczka'] ?? '' );
			$katalog = $kontekst->workspace->katalog( $wtyczka );

			if ( '' === $test || '' === $zrodlo || '' === $katalog ) {
				continue;
			}

			// Commit, ktory wprowadzil plik testu.
			$log = $kontekst->workspace->polecenie(
				array( 'git', '-C', $katalog, 'log', '--diff-filter=A', '--format=%H', '--', $test )
			);

			$commit = trim( strtok( trim( $log['wyjscie'] ), "\n" ) ?: '' );

			if ( '' === $commit ) {
				$wyniki[] = array(
					'id'      => (string) ( $blad['id'] ?? '?' ),
					'werdykt' => 'brak-historii',
					'dowod'   => 'Nie znaleziono commita dodajacego plik ' . $test . '.',
				);
				continue;
			}

			$pliki = $kontekst->workspace->polecenie(
				array( 'git', '-C', $katalog, 'show', '--name-only', '--format=', $commit )
			);

			$lista = array_filter( array_map( 'trim', explode( "\n", $pliki['wyjscie'] ) ) );
			$razem = false;

			foreach ( $lista as $plik ) {
				if ( false !== strpos( $plik, $zrodlo ) ) {
					$razem = true;
					break;
				}
			}

			$wyniki[] = array(
				'id'      => (string) ( $blad['id'] ?? '?' ),
				'wtyczka' => $wtyczka,
				'test'    => $test,
				'zrodlo'  => $zrodlo,
				'commit'  => substr( $commit, 0, 7 ),
				'werdykt' => $razem ? 'test-z-naprawa' : 'test-osobno',
				'dowod'   => 'commit ' . substr( $commit, 0, 7 ) . ' obejmuje ' . count( $lista ) . ' plikow'
					. ( $razem ? ', w tym poprawiane zrodlo' : ', bez poprawianego zrodla' ),
			);
		}

		return MP_AU_Wynik::ok( array( 'wyniki' => $wyniki ) );
	}
}

/**
 * K2.6 „test-i-naprawa-w-jednym-commicie".
 */
final class MP_AU_K26_Dowod_Naprawy extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['wyniki'] ?? array() ) as $w ) {
			if ( 'test-z-naprawa' === $w['werdykt'] ) {
				continue;
			}

			$ustalenia[] = new MP_AU_Ustalenie(
				'2.6',
				'Test dla bledu ' . $w['id'] . ' nie przyszedl w jednym commicie z naprawa.',
				MP_AU_Ustalenie::DROBNE,
				array(
					'plik'       => ( $w['wtyczka'] ?? '' ) . '/' . ( $w['test'] ?? '' ),
					'dowod'      => (string) $w['dowod'],
					'scenariusz' => 'Nie ma sladu, ze ten test kiedykolwiek FAIL-owal na kodzie sprzed '
						. 'naprawy. Moze wiec sprawdzac cos innego niz sadzimy — a wtedy nawrot bledu '
						. 'przejdzie przez niego bez zatrzymania.',
					'naprawa'    => 'Przy nastepnej naprawie: `git stash` zrodla, uruchomic test, potwierdzic '
						. 'FAIL, `git stash pop`, i dopiero wtedy commitowac oba pliki razem.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Naprawy bez sladu weryfikacji.', $ustalenia, $od_agenta->dane );
	}
}

/* ===================================================================== 2.7 */

/**
 * A2.7 „martwe pola audytu" — do ktorych plikow nie zajrzala zadna para.
 *
 * Pytanie, ktorego audyt zwykle sobie nie zadaje: czego NIE sprawdzilem.
 * Bez tej pary „zero ustalen w pliku X" i „nikt nie otworzyl pliku X" wygladaja
 * w raporcie identycznie.
 */
final class MP_AU_A27_Martwe_Pola extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$odczytane = array_flip( $kontekst->workspace->odczytane() );
		$pominiete = array();
		$wszystkich = 0;

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, false ) as $plik ) {
				++$wszystkich;

				if ( ! isset( $odczytane[ $plik ] ) ) {
					$pominiete[] = $kontekst->workspace->wzgledna( $plik );
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'wszystkich' => $wszystkich,
				'odczytanych' => $wszystkich - count( $pominiete ),
				'pominiete'  => $pominiete,
			)
		);
	}
}

/**
 * K2.7 „bialych-plam-ma-nie-byc".
 */
final class MP_AU_K27_Martwe_Pola extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$pominiete = (array) ( $od_agenta->dane['pominiete'] ?? array() );

		if ( empty( $pominiete ) ) {
			return MP_AU_Wynik::ok( $od_agenta->dane );
		}

		$ustalenia = array();

		foreach ( array_slice( $pominiete, 0, 25 ) as $plik ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'2.7',
				'Zadna para nie otworzyla tego pliku.',
				MP_AU_Ustalenie::OBSERWACJA,
				array(
					'plik'       => (string) $plik,
					'dowod'      => 'Plik nieobecny w rejestrze odczytow przebiegu.',
					'scenariusz' => 'Brak ustalen dla tego pliku NIE oznacza, ze jest poprawny — oznacza, '
						. 'ze nikt do niego nie zajrzal. Raport bez tej informacji wprowadzalby w blad.',
					'status'     => MP_AU_Ustalenie::POTWIERDZONE,
				)
			);
		}

		if ( count( $pominiete ) > 25 ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'2.7',
				'Plikow nieodczytanych w tym przebiegu: ' . count( $pominiete ) . '.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'dowod'      => 'odczytanych ' . $od_agenta->dane['odczytanych'] . ' z ' . $od_agenta->dane['wszystkich'],
					'scenariusz' => 'Pokrycie audytu jest czesciowe, a werdykt dotyczy tylko tej czesci.',
					'status'     => MP_AU_Ustalenie::POTWIERDZONE,
				)
			);
		}

		return MP_AU_Wynik::blad( 'Audyt ma biale plamy.', $ustalenia, $od_agenta->dane );
	}
}

/* ===================================================================== 2.8 */

/**
 * A2.8 „porownanie z poprzednim przebiegiem".
 *
 * Odpowiada na jedyne pytanie, ktore ma sens po naprawie: czy ubylo, czy tylko
 * sie przesunelo. Czyta `raporty/raport-ostatni.json` PRZED nadpisaniem go
 * biezacym przebiegiem.
 */
final class MP_AU_A28_Regresja extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$sciezka = (string) $kontekst->pobierz( 'poprzedni_raport', '' );

		if ( '' === $sciezka || ! is_readable( $sciezka ) ) {
			return MP_AU_Wynik::ok(
				array(
					'pierwszy_przebieg' => true,
					'nowe'   => array(),
					'znikle' => array(),
				)
			);
		}

		$kontekst->policz_odczyt();
		$poprzedni = json_decode( (string) file_get_contents( $sciezka ), true );

		if ( ! is_array( $poprzedni ) ) {
			return MP_AU_Wynik::nieocenione( 'Poprzedni raport jest nieczytelny: ' . $sciezka );
		}

		/*
		 * Zbior par zmienia sie miedzy wersjami narzedzia. Bez tego filtra kazda
		 * NOWO DODANA para zglaszalaby swoje pierwsze ustalenia jako „regresje
		 * projektu", co jest nieprawda: regresja to zmiana w KODZIE, a nie
		 * w zestawie kontroli.
		 */
		$pary_poprzednio = array();

		foreach ( (array) ( $poprzedni['przebiegi'] ?? array() ) as $p ) {
			foreach ( (array) ( $p['pary'] ?? array() ) as $para ) {
				if ( ! in_array( (string) ( $para['stan'] ?? '' ), array( 'nieocenione', 'pominieta' ), true ) ) {
					$pary_poprzednio[ (string) $para['para'] ] = true;
				}
			}
		}

		$stare = array();

		foreach ( (array) ( $poprzedni['ustalenia'] ?? array() ) as $u ) {
			if ( MP_AU_Ustalenie::ODRZUCONE === ( $u['status'] ?? '' ) ) {
				continue;
			}

			$stare[ (string) $u['klucz'] ] = array(
				'opis' => (string) ( $u['opis'] ?? '' ),
				'waga' => (string) ( $u['waga'] ?? '' ),
			);
		}

		$nowe_wszystkie = array();

		foreach ( $kontekst->ustalenia() as $u ) {
			if ( MP_AU_Ustalenie::ODRZUCONE === $u->status ) {
				continue;
			}

			if ( ! empty( $pary_poprzednio ) && ! isset( $pary_poprzednio[ $u->para ] ) ) {
				continue;
			}

			$nowe_wszystkie[ $u->klucz() ] = array(
				'opis' => $u->opis,
				'waga' => $u->waga,
			);
		}

		return MP_AU_Wynik::ok(
			array(
				'pierwszy_przebieg' => false,
				'data_poprzedniego' => (string) ( $poprzedni['data'] ?? '?' ),
				'werdykt_poprzedni' => (string) ( $poprzedni['werdykt'] ?? '?' ),
				'bylo'   => count( $stare ),
				'jest'   => count( $nowe_wszystkie ),
				'nowe'   => array_diff_key( $nowe_wszystkie, $stare ),
				'znikle' => array_diff_key( $stare, $nowe_wszystkie ),
			)
		);
	}
}

/**
 * K2.8 „ubylo-czy-sie-przesunelo".
 */
final class MP_AU_K28_Regresja extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		if ( ! empty( $od_agenta->dane['pierwszy_przebieg'] ) ) {
			return MP_AU_Wynik::ok( $od_agenta->dane );
		}

		$ustalenia = array();

		foreach ( array_slice( (array) ( $od_agenta->dane['nowe'] ?? array() ), 0, 15, true ) as $klucz => $u ) {
			if ( MP_AU_Ustalenie::KRYTYCZNE !== $u['waga'] && MP_AU_Ustalenie::SREDNIE !== $u['waga'] ) {
				continue;
			}

			$ustalenia[] = new MP_AU_Ustalenie(
				'2.8',
				'REGRESJA wzgledem poprzedniego przebiegu: ' . MP_AU_Pomoc::skrot( (string) $u['opis'], 90 ),
				MP_AU_Ustalenie::SREDNIE,
				array(
					'dowod'      => 'Ustalenie nieobecne w raporcie z ' . ( $od_agenta->dane['data_poprzedniego'] ?? '?' )
						. ', obecne dzis (klucz ' . $klucz . ').',
					'scenariusz' => 'To jest problem WPROWADZONY miedzy dwoma przebiegami. Wiadomo, ze powstal '
						. 'w zmianach z tego okresu — to najtansza w calym projekcie wskazowka, gdzie szukac.',
					'status'     => MP_AU_Ustalenie::POTWIERDZONE,
				)
			);
		}

		$dane = $od_agenta->dane;
		$dane['podsumowanie'] = 'bylo ' . $dane['bylo'] . ', jest ' . $dane['jest']
			. ', nowych ' . count( (array) $dane['nowe'] ) . ', zniklo ' . count( (array) $dane['znikle'] );

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $dane )
			: MP_AU_Wynik::blad( 'Regresja wzgledem poprzedniego przebiegu.', $ustalenia, $dane );
	}
}

/* ===================================================================== 2.9 */

/**
 * A2.9 „dossier ustalen dla drugiego sedziego".
 *
 * Dzial 1 mial krytyka modelowego, ktory ZGLASZAL. Tutaj model wystepuje
 * w odwrotnej roli: ma zakwestionowac cudze zgloszenia. Ten sam mechanizm,
 * przeciwny kierunek — i dlatego ma szanse zlapac to, czego tamten nie widzial.
 */
final class MP_AU_A29_Sedzia extends MP_AU_Agent {

	/** Ile ustalen w jednym dossier. */
	const PACZKA = 8;

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$do_oceny = array();

		foreach ( $kontekst->ustalenia() as $u ) {
			if ( MP_AU_Ustalenie::ODRZUCONE === $u->status || MP_AU_Ustalenie::POTWIERDZONE === $u->status ) {
				continue;
			}

			if ( MP_AU_Ustalenie::OBSERWACJA === $u->waga ) {
				continue;
			}

			$do_oceny[] = array(
				'klucz'      => $u->klucz(),
				'para'       => $u->para,
				'opis'       => $u->opis,
				'waga'       => $u->waga,
				'plik'       => $u->plik,
				'linia'      => $u->linia,
				'dowod'      => MP_AU_Pomoc::skrot( $u->dowod, 400 ),
				'scenariusz' => MP_AU_Pomoc::skrot( $u->scenariusz, 400 ),
			);
		}

		return MP_AU_Wynik::ok(
			array(
				'paczki' => array_chunk( $do_oceny, self::PACZKA ),
				'ile'    => count( $do_oceny ),
			)
		);
	}
}

/**
 * K2.9 „drugi-sedzia".
 */
final class MP_AU_K29_Sedzia extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		if ( ! $kontekst->model->dostepny() ) {
			return MP_AU_Wynik::nieocenione( 'Drugi sedzia wymaga modelu: ' . $kontekst->model->powod_niedostepnosci() );
		}

		$paczki = (array) ( $od_agenta->dane['paczki'] ?? array() );

		if ( empty( $paczki ) ) {
			return MP_AU_Wynik::ok( array( 'oceniono' => 0 ) );
		}

		$werdykty = array();

		// Ten sam limit, co dla par modelowych Dzialu 1 — inaczej przy stu
		// ustaleniach drugi sedzia sam decydowalby o czasie calego przebiegu.
		$paczki = array_slice( $paczki, 0, max( 1, (int) ceil( (int) $kontekst->pobierz( 'limit_modelu', 40 ) / MP_AU_A29_Sedzia::PACZKA ) ) );

		foreach ( $paczki as $paczka ) {
			$pytanie = "Jestes DRUGIM sedzia w audycie. Ponizej ustalenia zgloszone przez pierwszego. "
				. "Twoim zadaniem jest ZAKWESTIONOWAC te, ktore nie bronia sie wlasnym dowodem.\n\n"
				. "Dla kazdego ustalenia zwroc werdykt:\n"
				. "  „trzyma\" — dowod i scenariusz sa spojne, problem jest realny,\n"
				. "  „watpliwe\" — scenariusz nie wynika z dowodu,\n"
				. "  „odrzuc\" — to nie jest problem (np. celowa konstrukcja, wzorzec trafil w cos innego).\n\n"
				. "ODPOWIEDZ WYLACZNIE JSON-em:\n"
				. '{"werdykty":[{"klucz":"","werdykt":"trzyma|watpliwe|odrzuc","uzasadnienie":""}]}' . "\n\n"
				. "=== USTALENIA ===\n" . json_encode( $paczka, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			$odpowiedz = $kontekst->model->zapytaj( $pytanie );

			foreach ( (array) ( $odpowiedz['werdykty'] ?? array() ) as $w ) {
				if ( empty( $w['klucz'] ) ) {
					continue;
				}

				$werdykty[ (string) $w['klucz'] ] = array(
					'werdykt'      => (string) ( $w['werdykt'] ?? '' ),
					'uzasadnienie' => (string) ( $w['uzasadnienie'] ?? '' ),
				);
			}
		}

		$licznik = array(
			'trzyma'   => 0,
			'watpliwe' => 0,
			'odrzuc'   => 0,
		);

		foreach ( $kontekst->ustalenia() as $u ) {
			$w = $werdykty[ $u->klucz() ] ?? null;

			if ( null === $w ) {
				continue;
			}

			$u->dowod .= "\n[2.9 drugi sedzia: " . $w['werdykt'] . '] ' . $w['uzasadnienie'];

			if ( 'odrzuc' === $w['werdykt'] ) {
				// Sam model NIE ma prawa skasowac ustalenia — obniza je do
				// obserwacji, zeby zostalo widoczne dla czlowieka. Ocena modelu
				// jest hipoteza takze wtedy, gdy brzmi jak uniewinnienie.
				$u->waga = MP_AU_Ustalenie::OBSERWACJA;
				++$licznik['odrzuc'];
			} elseif ( 'watpliwe' === $w['werdykt'] ) {
				++$licznik['watpliwe'];
			} else {
				++$licznik['trzyma'];
			}
		}

		return MP_AU_Wynik::ok( $licznik );
	}
}

/* ==================================================================== 2.10 */

/**
 * A2.10 „audyt werdyktu" — czy wniosek wynika z ustalen.
 *
 * Ostatnia para calego pipeline'u. Nie patrzy juz na projekt, tylko na to, co
 * audyt zamierza powiedziec — i sprawdza, czy ma do tego podstawy.
 */
final class MP_AU_A210_Werdykt extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$bez_dowodu     = array();
		$bez_scenariusza = array();
		$wagi           = array();

		foreach ( $kontekst->ustalenia() as $u ) {
			if ( MP_AU_Ustalenie::ODRZUCONE === $u->status ) {
				continue;
			}

			$wagi[ $u->waga ] = ( $wagi[ $u->waga ] ?? 0 ) + 1;

			if ( '' === trim( $u->dowod ) ) {
				$bez_dowodu[] = $u->klucz();
			}

			if ( '' === trim( $u->scenariusz ) ) {
				$bez_scenariusza[] = $u->klucz();
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'wagi'            => $wagi,
				'bez_dowodu'      => $bez_dowodu,
				'bez_scenariusza' => $bez_scenariusza,
			)
		);
	}
}

/**
 * K2.10 „kazde-ustalenie-uniesie-swoj-ciezar".
 */
final class MP_AU_K210_Werdykt extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$bez_dowodu = array_flip( (array) ( $od_agenta->dane['bez_dowodu'] ?? array() ) );
		$bez_scen   = array_flip( (array) ( $od_agenta->dane['bez_scenariusza'] ?? array() ) );
		$obnizone   = 0;

		foreach ( $kontekst->ustalenia() as $u ) {
			if ( MP_AU_Ustalenie::ODRZUCONE === $u->status ) {
				continue;
			}

			if ( MP_AU_Ustalenie::KRYTYCZNE !== $u->waga ) {
				continue;
			}

			if ( ! isset( $bez_dowodu[ $u->klucz() ] ) && ! isset( $bez_scen[ $u->klucz() ] ) ) {
				continue;
			}

			// Ustalenie krytyczne bez dowodu albo bez scenariusza blokowaloby
			// wydanie na podstawie samego swojego brzmienia. Na to nie ma zgody:
			// obniżamy je i mowimy dlaczego.
			$u->waga   = MP_AU_Ustalenie::SREDNIE;
			$u->dowod .= "\n[2.10] Waga obnizona z krytycznej: zgloszenie nie niesie "
				. ( isset( $bez_dowodu[ $u->klucz() ] ) ? 'dowodu' : 'scenariusza awarii' ) . '.';
			++$obnizone;
		}

		return MP_AU_Wynik::ok(
			array(
				'obnizone' => $obnizone,
				'wagi'     => $od_agenta->dane['wagi'] ?? array(),
			)
		);
	}
}
