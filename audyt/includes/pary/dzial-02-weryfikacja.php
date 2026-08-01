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

			/*
			 * Miejsce moze byc KATALOGIEM — regula „jeden plik na dzial" (1.17)
			 * bada `docs/dzial-NN/`, zasieg RODO (1.11) cala wtyczke. Katalog
			 * potwierdza sie samym istnieniem: nie ma w nim linii, wiec obie
			 * kontrole ponizej (numer linii, komentarz) nie maja do czego sie
			 * odniesc i wolno je pominac, a nie uznac miejsca za nieistniejace.
			 */
			if ( is_dir( $pelna ) ) {
				$wynik['werdykt'] = 'miejsce-istnieje';
				$wynik['powod']   = 'Katalog potwierdzony niezaleznym odczytem.';
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

			/*
			 * `file_exists()`, nie `is_file()`. Katalog nigdy nie jest plikiem,
			 * wiec przy `is_file()` kazde ustalenie wskazujace KATALOG albo
			 * korzen wtyczki bylo z gory nie do potwierdzenia i szlo na odrzut
			 * jako „plik-nie-istnieje". Kasowalo to cale rodziny kontroli, ktore
			 * z natury mowia o katalogu: regule „jeden plik na dzial" (1.17),
			 * zasieg RODO (1.11), miejsca wskazywane korzeniem wtyczki (1.5).
			 *
			 * Skutek byl gorszy niz utrata tych ustalen: raport wygladal
			 * CZYSCIEJ, niz bylo naprawde. W przebiegu z 01.08.2026 odpadlo tak
			 * 20 z 48 zgloszen, a bezpiecznik ponizej tego nie zlapal, bo ma
			 * prog > 50%, a wyszlo 42%.
			 */
			foreach ( $kandydaci as $kandydat ) {
				if ( file_exists( $kandydat ) ) {
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
 *
 * ZAWEZENIE (31.07.2026). Pierwsza wersja pytala o commit, ktory plik testu
 * UTWORZYL. To dawalo falszywy alarm wszedzie tam, gdzie jeden plik testu
 * pilnuje kilku bledow — a tak jest w tym projekcie, bo testy sa grupowane
 * tematycznie, nie po jednym na blad. Przykladem P2-K2: test
 * `zatwierdzenie-oferty.php` powstal przy naprawie P2-K1 (commit 7478b20),
 * a sekcje dla P2-K2 dopisano w commicie 8f650d3 — razem z poprawka Dzialu 10.
 * Slad istnial, para patrzyla nie w ten commit. Przy takim ukladzie DRUGI
 * i kazdy kolejny blad w pliku byl z gory skazany na ustalenie, niezaleznie
 * od tego, jak starannie go naprawiono.
 *
 * Teraz para przeglada WSZYSTKIE commity dotykajace pliku testu i szuka
 * takiego, ktory dotyka rowniez poprawianego zrodla. Sila dowodu sie nie
 * zmienia — to nadal tylko „oba pliki byly na biurku naraz" — ale pytanie
 * jest zadane o wlasciwa zmiane.
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

			// Wszystkie commity dotykajace pliku testu — od najnowszego.
			$log = $kontekst->workspace->polecenie(
				array( 'git', '-C', $katalog, 'log', '--format=%H', '--', $test )
			);

			$commity = array_filter( array_map( 'trim', explode( "\n", trim( $log['wyjscie'] ) ) ) );

			if ( empty( $commity ) ) {
				$wyniki[] = array(
					'id'      => (string) ( $blad['id'] ?? '?' ),
					'wtyczka' => $wtyczka,
					'test'    => $test,
					'zrodlo'  => $zrodlo,
					'werdykt' => 'brak-historii',
					'dowod'   => 'Nie znaleziono commita dotykajacego pliku ' . $test . '.',
				);
				continue;
			}

			$historia = array();

			foreach ( $commity as $commit ) {
				$pliki = $kontekst->workspace->polecenie(
					array( 'git', '-C', $katalog, 'show', '--name-only', '--format=', $commit )
				);

				$historia[] = array(
					'commit' => $commit,
					'pliki'  => array_values(
						array_filter( array_map( 'trim', explode( "\n", $pliki['wyjscie'] ) ) )
					),
				);
			}

			$wyniki[] = array(
				'id'      => (string) ( $blad['id'] ?? '?' ),
				'wtyczka' => $wtyczka,
				'test'    => $test,
				'zrodlo'  => $zrodlo,
			) + self::dopasuj_commit( $historia, $zrodlo );
		}

		return MP_AU_Wynik::ok( array( 'wyniki' => $wyniki ) );
	}

	/**
	 * Szuka w historii pliku testu commita, ktory dotyka takze poprawianego zrodla.
	 *
	 * Wydzielone z `zbierz()` swiadomie: to jedyny kawalek tej pary, ktory da sie
	 * sprawdzic testem bez zywego repozytorium i bez rejestru. Dostaje gotowa
	 * historie (od najnowszego commita) i oddaje werdykt razem z dowodem.
	 *
	 * @param array  $historia Lista array( 'commit' => sha, 'pliki' => string[] ).
	 * @param string $zrodlo   Sciezka poprawianego zrodla (fragment).
	 * @return array array( 'commit', 'werdykt', 'dowod' )
	 */
	public static function dopasuj_commit( array $historia, string $zrodlo ): array {
		if ( empty( $historia ) ) {
			return array(
				'commit'  => '',
				'werdykt' => 'brak-historii',
				'dowod'   => 'Historia pliku testu jest pusta.',
			);
		}

		foreach ( $historia as $wpis ) {
			foreach ( (array) ( $wpis['pliki'] ?? array() ) as $plik ) {
				if ( '' !== $zrodlo && false !== strpos( (string) $plik, $zrodlo ) ) {
					return array(
						'commit'  => substr( (string) $wpis['commit'], 0, 7 ),
						'werdykt' => 'test-z-naprawa',
						'dowod'   => 'commit ' . substr( (string) $wpis['commit'], 0, 7 ) . ' obejmuje '
							. count( (array) $wpis['pliki'] ) . ' plikow, w tym poprawiane zrodlo',
					);
				}
			}
		}

		return array(
			'commit'  => substr( (string) $historia[0]['commit'], 0, 7 ),
			'werdykt' => 'test-osobno',
			'dowod'   => 'zaden z ' . count( $historia ) . ' commitow dotykajacych testu nie ruszal '
				. 'poprawianego zrodla',
		);
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

		/*
		 * NAJCIEZSZE IDA PIERWSZE — bo do modelu pojdzie tylko poczatek listy.
		 *
		 * Krytyk bierze tyle paczek, ile pozwala `limit_modelu` (domyslnie 6,
		 * czyli JEDNA paczka po osiem sztuk). Dossier skladane w kolejnosci
		 * naplywania dawalo wiec drugiemu sedziemu osiem PIERWSZYCH zgloszen
		 * przebiegu — a ustalenia ciezkie pochodza z par MODELOWYCH (1.25, 1.26),
		 * ktore chodza na koncu Dzialu 1. Ustalenie krytyczne bylo przez to
		 * strukturalnie ostatnie w kolejce do drugiej oceny, mimo ze samo jedno
		 * przewraca bramke calego audytu na NO GO.
		 *
		 * Tak wlasnie skonczyl sie przebieg na kodzie 1.3.5 (01.08.2026): werdykt
		 * NO GO z jednego ustalenia krytycznego, ktorego drugi sedzia nie widzial,
		 * a ktore sonda w kodzie obalila w calosci.
		 *
		 * Sortowanie jest STABILNE (dekoracja indeksem), zeby kolejnosc w obrebie
		 * jednej wagi zostala bez zmian i raporty dalo sie dalej porownywac.
		 */
		$kolejnosc = array(
			MP_AU_Ustalenie::KRYTYCZNE => 0,
			MP_AU_Ustalenie::SREDNIE   => 1,
			MP_AU_Ustalenie::DROBNE    => 2,
		);

		$pozycje = array_keys( $do_oceny );
		usort(
			$pozycje,
			static function ( $a, $b ) use ( $do_oceny, $kolejnosc ) {
				$wa = $kolejnosc[ $do_oceny[ $a ]['waga'] ] ?? 3;
				$wb = $kolejnosc[ $do_oceny[ $b ]['waga'] ] ?? 3;

				return $wa === $wb ? $a <=> $b : $wa <=> $wb;
			}
		);

		$posortowane = array();
		foreach ( $pozycje as $poz ) {
			$posortowane[] = $do_oceny[ $poz ];
		}

		return MP_AU_Wynik::ok(
			array(
				'paczki' => array_chunk( $posortowane, self::PACZKA ),
				'ile'    => count( $posortowane ),
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
			/*
			 * Paczka MUSI sie zakodowac, zanim pojdzie w pytanie. `json_encode()`
			 * oddaje `false` przy najmniejszym nieprawidlowym bajcie, a `false`
			 * sklejone ze stringiem to pusty ciag — pytanie wychodzilo wtedy
			 * z naglowkiem „=== USTALENIA ===" i niczym pod nim. Model uczciwie
			 * odpowiadal pusta lista, warunek nizej czytal ja jako „ocena nie
			 * zostala wykonana" i bramka Dzialu 2 zabierala werdykt CALEMU
			 * przebiegowi (01.08.2026, pokrycie 91%). Zgloszenie ma nazywac
			 * przyczyne po stronie narzedzia, a nie zrzucac wine na model.
			 */
			$ustalenia_json = json_encode( $paczka, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false === $ustalenia_json ) {
				return MP_AU_Wynik::nieocenione(
					'Nie udalo sie zakodowac paczki ustalen do JSON (' . json_last_error_msg()
					. ') — pytanie NIE zostalo wyslane. Blad narzedzia, nie modelu.'
				);
			}

			$pytanie = "Jestes DRUGIM sedzia w audycie. Ponizej ustalenia zgloszone przez pierwszego. "
				. "Twoim zadaniem jest ZAKWESTIONOWAC te, ktore nie bronia sie wlasnym dowodem.\n\n"
				. "Dla kazdego ustalenia zwroc werdykt:\n"
				. "  „trzyma\" — dowod i scenariusz sa spojne, problem jest realny,\n"
				. "  „watpliwe\" — scenariusz nie wynika z dowodu,\n"
				. "  „odrzuc\" — to nie jest problem (np. celowa konstrukcja, wzorzec trafil w cos innego).\n\n"
				. "ODPOWIEDZ WYLACZNIE JSON-em:\n"
				. '{"werdykty":[{"klucz":"","werdykt":"trzyma|watpliwe|odrzuc","uzasadnienie":""}]}' . "\n\n"
				. "=== USTALENIA ===\n" . $ustalenia_json;

			$odpowiedz = $kontekst->model->zapytaj( $pytanie, true );

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

		if ( empty( $werdykty ) ) {
			return MP_AU_Wynik::nieocenione(
				'Drugi sedzia nie zwrocil zadnego werdyktu — ocena NIE zostala wykonana.'
			);
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

/* ==================================================================== 2.11 */

/**
 * A2.11 „potwierdzenie ustalen modelu" — dossier do DRUGIEJ, niezaleznej oceny.
 *
 * Po co ta para istnieje. Ocena modelu wchodzi do raportu zawsze jako
 * `prawdopodobne` i nic w Dziale 2 nie potrafilo jej podniesc: pary 2.1 i 2.2
 * sprawdzaja rzeczy mechaniczne (czy plik istnieje, czy kolumna wystepuje
 * w DDL), a zdania w rodzaju „ten status klamie o tym, co sie stalo" nie maja
 * mechanicznego odpowiednika. Ustalenie trafne i ustalenie zmyslone wygladaly
 * wiec w raporcie tak samo.
 *
 * Ta para daje im drugi klucz. Agent wycina z pliku FRAGMENT wokol wskazanej
 * linii i sprawdza mechanicznie, czy dowod cytowany przez model naprawde tam
 * jest. Krytyk pokazuje ten sam fragment drugiemu modelowi — BEZ rozumowania
 * pierwszego — i zadaje pytanie zamkniete.
 */
final class MP_AU_A211_Potwierdzenie extends MP_AU_Agent {

	/** Pary, ktorych ustalenia pochodza od modelu. */
	const PARY_MODELOWE = array( '1.25', '1.26' );

	/** Ile wierszy kontekstu wokol wskazanej linii. */
	const KONTEKST = 30;

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$do_potwierdzenia = array();

		foreach ( $kontekst->ustalenia() as $u ) {
			if ( ! in_array( $u->para, self::PARY_MODELOWE, true ) ) {
				continue;
			}

			if ( MP_AU_Ustalenie::PRAWDOPODOBNE !== $u->status || '' === $u->plik ) {
				continue;
			}

			$pelna = $this->pelna_sciezka( $kontekst, $u->plik );

			if ( '' === $pelna ) {
				continue;
			}

			$tresc   = $kontekst->workspace->tresc( $pelna, $kontekst );
			$wiersze = explode( "\n", $tresc );
			$srodek  = $u->linia > 0 ? $u->linia - 1 : 0;
			$od      = max( 0, $srodek - self::KONTEKST );
			$ile     = min( count( $wiersze ) - $od, 2 * self::KONTEKST + 1 );

			$fragment = '';

			foreach ( array_slice( $wiersze, $od, $ile ) as $indeks => $wiersz ) {
				$fragment .= str_pad( (string) ( $od + $indeks + 1 ), 5, ' ', STR_PAD_LEFT ) . ': ' . $wiersz . "\n";
			}

			$do_potwierdzenia[] = array(
				'klucz'    => $u->klucz(),
				'opis'     => $u->opis,
				'plik'     => $u->plik,
				'linia'    => $u->linia,
				'fragment' => $fragment,
				// Klucz pierwszy, mechaniczny: czy cytat z dowodu naprawde
				// wystepuje w pliku. Model potrafi zacytowac kod, ktorego nie ma.
				'dowod_znaleziony' => $this->cytat_wystepuje( $u->dowod, $tresc ),
			);
		}

		return MP_AU_Wynik::ok( array( 'do_potwierdzenia' => $do_potwierdzenia ) );
	}

	/**
	 * Czy dowod (albo jego istotny fragment) wystepuje w tresci pliku.
	 *
	 * Porownanie po znormalizowanych bialych znakach — model przepisuje kod
	 * z wlasnym wcieciem, a to nie jest powod, zeby uznac cytat za zmyslony.
	 *
	 * @param string $dowod Dowod z ustalenia.
	 * @param string $tresc Tresc pliku.
	 * @return bool
	 */
	private function cytat_wystepuje( string $dowod, string $tresc ): bool {
		$dowod = trim( (string) preg_replace( '/\[\d\.\d+[^\]]*\][^\n]*/u', '', $dowod ) );

		if ( strlen( $dowod ) < 12 ) {
			return false;
		}

		$plik_n = (string) preg_replace( '/\s+/', ' ', $tresc );

		// Bierzemy najdluzszy ciag wygladajacy na kod: nazwy funkcji, zmienne,
		// wywolania. Zdanie po polsku nie jest cytatem i nie ma go w pliku.
		if ( preg_match_all( '/[\$A-Za-z_][A-Za-z0-9_]*\s*(?:\(|->|::)[^,;\n]{0,60}/', $dowod, $t ) ) {
			foreach ( $t[0] as $kandydat ) {
				$kandydat = trim( (string) preg_replace( '/\s+/', ' ', $kandydat ) );

				if ( strlen( $kandydat ) >= 8 && false !== strpos( $plik_n, $kandydat ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
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

			$reszta = (string) preg_replace( '#^' . preg_quote( $branch, '#' ) . '/#', '', $wzgledna );

			foreach ( array( $katalog . '/' . $reszta, dirname( $katalog ) . '/' . $reszta ) as $kandydat ) {
				if ( is_file( $kandydat ) ) {
					return $kandydat;
				}
			}
		}

		return '';
	}
}

/**
 * K2.11 „dwa-klucze-albo-zostaje-hipoteza".
 *
 * ZASADY, KTORYCH TA PARA NIE MOZE ZLAMAC:
 *
 * 1. Awans dotyczy WYLACZNIE statusu weryfikacji. Waga ustalenia nie zmienia sie
 *    nigdy — drugi model nie ma prawa uczynic czegos krytycznym.
 * 2. Zaprzeczenie drugiego modelu NIE odrzuca ustalenia. Jedno „nie" nie jest
 *    mocniejsze od cudzego „tak"; odrzucac wolno tylko na podstawie sprawdzenia
 *    mechanicznego (para 2.2). Zaprzeczenie zostaje odnotowane w dowodzie.
 * 3. Awans wymaga OBU kluczy naraz: cytat musi byc odnaleziony w pliku przez PHP
 *    ORAZ drugi model musi potwierdzic, podajac cytat, ktory da sie odnalezc
 *    w pokazanym fragmencie. Sam werdykt „tak" bez cytatu nie wystarcza.
 * 4. Nic w tym pipeline nie zmienia kodu projektu. Zmienia sie wylacznie
 *    wiarygodnosc zdania w raporcie.
 */
final class MP_AU_K211_Potwierdzenie extends MP_AU_Krytyk {

	/** Ile ustalen w jednym pytaniu — fragmenty sa duze, wiec malo. */
	const PACZKA = 3;

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$lista = (array) ( $od_agenta->dane['do_potwierdzenia'] ?? array() );

		if ( empty( $lista ) ) {
			return MP_AU_Wynik::ok( array( 'do_oceny' => 0 ) );
		}

		if ( ! $kontekst->model->dostepny() ) {
			return MP_AU_Wynik::nieocenione(
				'Potwierdzenie ustalen modelu wymaga drugiej oceny: ' . $kontekst->model->powod_niedostepnosci()
			);
		}

		$paczki = array_chunk( $lista, self::PACZKA );
		$limit  = max( 1, (int) ceil( (int) $kontekst->pobierz( 'limit_modelu', 6 ) / self::PACZKA ) );
		$paczki = array_slice( $paczki, 0, $limit );

		$werdykty = array();

		foreach ( $paczki as $paczka ) {
			$odpowiedz = $kontekst->model->zapytaj( $this->pytanie( $paczka ), true );

			foreach ( (array) ( $odpowiedz['werdykty'] ?? array() ) as $w ) {
				if ( ! empty( $w['klucz'] ) ) {
					$werdykty[ (string) $w['klucz'] ] = $w;
				}
			}
		}

		// Zero werdyktow przy niepustej paczce znaczy, ze model odpowiedzial
		// w innym ksztalcie albo nie odpowiedzial wcale. To NIE jest wynik
		// „nie ma czego potwierdzac" — to brak wyniku, i tak musi wygladac.
		if ( empty( $werdykty ) ) {
			return MP_AU_Wynik::nieocenione(
				'Model nie zwrocil zadnego werdyktu dla ' . count( $paczki ) . ' paczek pary 2.11.'
			);
		}

		$fragmenty = array();

		foreach ( $lista as $pozycja ) {
			$fragmenty[ $pozycja['klucz'] ] = $pozycja;
		}

		$licznik = array(
			'ocenianych'   => count( $lista ),
			'podniesione'  => 0,
			'zakwestionowane' => 0,
			'bez_zmiany'   => 0,
		);

		foreach ( $kontekst->ustalenia() as $u ) {
			$w = $werdykty[ $u->klucz() ] ?? null;

			if ( null === $w ) {
				continue;
			}

			$pozycja = $fragmenty[ $u->klucz() ] ?? array();
			$cytat   = trim( (string) ( $w['cytat'] ?? '' ) );

			if ( empty( $w['potwierdzam'] ) ) {
				$u->dowod .= "\n[2.11 druga ocena: NIE POTWIERDZA] " . MP_AU_Pomoc::skrot( (string) ( $w['uzasadnienie'] ?? '' ), 240 )
					. '\n[2.11] Ustalenie ZOSTAJE jako hipoteza: jedno zaprzeczenie nie uniewaznia cudzego potwierdzenia.';
				++$licznik['zakwestionowane'];
				continue;
			}

			// Drugi klucz: cytat podany przez model musi dac sie odnalezc
			// w pokazanym fragmencie. Bez tego „potwierdzam" jest samym slowem.
			$cytat_w_kodzie = '' !== $cytat
				&& strlen( $cytat ) >= 8
				&& false !== strpos(
					(string) preg_replace( '/\s+/', ' ', (string) ( $pozycja['fragment'] ?? '' ) ),
					(string) preg_replace( '/\s+/', ' ', $cytat )
				);

			if ( ! empty( $pozycja['dowod_znaleziony'] ) && $cytat_w_kodzie ) {
				$u->status = MP_AU_Ustalenie::POTWIERDZONE;
				$u->dowod .= "\n[2.11 POTWIERDZONE dwoma kluczami] cytat odnaleziony w pliku przez PHP; "
					. "druga, niezalezna ocena potwierdza i wskazuje: " . MP_AU_Pomoc::skrot( $cytat, 160 );
				++$licznik['podniesione'];
				continue;
			}

			$u->dowod .= "\n[2.11] Druga ocena potwierdza, ale brakuje drugiego klucza ("
				. ( empty( $pozycja['dowod_znaleziony'] ) ? 'cytatu z dowodu nie ma w pliku' : 'cytat oceny nie wystepuje we fragmencie' )
				. ') — zostaje hipoteza.';
			++$licznik['bez_zmiany'];
		}

		return MP_AU_Wynik::ok( $licznik );
	}

	/**
	 * Pytanie zamkniete o paczke ustalen.
	 *
	 * Drugi oceniajacy NIE dostaje rozumowania pierwszego — tylko sam zarzut
	 * i kod. Pokazanie mu uzasadnienia zamienialoby niezalezna ocene w zgode.
	 *
	 * @param array $paczka Paczka ustalen z fragmentami.
	 * @return string
	 */
	private function pytanie( array $paczka ): string {
		$tekst = "Sprawdzasz ZARZUTY wobec kodu. Dla kazdego masz sam zarzut i fragment kodu.\n"
			. "Nie masz uzasadnienia autora zarzutu — masz ocenic SAMODZIELNIE.\n\n"
			. "Dla kazdego zarzutu odpowiedz:\n"
			. "  potwierdzam: true  — TYLKO gdy widzisz to w pokazanym kodzie,\n"
			. "  potwierdzam: false — gdy tego nie widac albo zarzut jest nietrafny,\n"
			. "  cytat: DOSLOWNY fragment z pokazanego kodu, ktory to pokazuje\n"
			. "         (przy false zostaw pusty; przy true cytat jest OBOWIAZKOWY\n"
			. "          i musi wystepowac w kodzie znak w znak).\n\n"
			. "ODPOWIEDZ WYLACZNIE JSON-em:\n"
			. '{"werdykty":[{"klucz":"","potwierdzam":true,"cytat":"","uzasadnienie":""}]}' . "\n";

		foreach ( $paczka as $pozycja ) {
			$tekst .= "\n=== ZARZUT ===\nklucz: " . $pozycja['klucz'] . "\nplik: " . $pozycja['plik']
				. "\nlinia: " . $pozycja['linia'] . "\ntresc: " . $pozycja['opis']
				. "\n--- KOD ---\n" . $pozycja['fragment'] . "\n";
		}

		return $tekst;
	}
}
