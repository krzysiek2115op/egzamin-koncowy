<?php
/**
 * Dzial 1, grupa DANE: pary 1.12, 1.15, 1.16, 1.19.
 *
 * Grupa o tym, co system LICZY i co robi, gdy cos nie wyjdzie. Para 1.15 jest tu
 * najwazniejsza i jednoczesnie najmniej typowa: audytuje nie kod, tylko zwiazek
 * miedzy rejestrem dawnych bledow a testami. Odpowiada na pytanie „czy blad,
 * ktory juz raz nas kosztowal, wroci niezauwazony".
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/* ==================================================================== 1.12 */

/**
 * A1.12 „arytmetyka pieniedzy".
 *
 * Blad P2-K3 kosztowal klienta 23% ceny i przeszedl przez KOMPLET bramek QA,
 * bo suma byla wewnetrznie spojna: netto + VAT = brutto zgadzalo sie idealnie.
 * Tylko punkt wyjscia byl zly — cena brutto potraktowana jak netto. Zadna
 * kontrola spojnosci tego nie zlapie; trzeba pytac o JEDNOSTKE.
 */
final class MP_AU_A112_Pieniadze extends MP_AU_Agent {

	/** Fragmenty nazw, po ktorych rozpoznajemy zmienna pieniezna. */
	const NAZWY = array( 'price', 'amount', 'total', 'net', 'gross', 'kwota', 'cena', 'netto', 'brutto', 'subtotal' );

	/*
	 * STAWKA to nie kwota. `(float) $tax_rates` jest poprawne — 23% naprawde jest
	 * ulamkiem i nie ma sensu trzymac go w groszach. Pierwsza wersja tej pary
	 * zglaszala kazda stawke jako blad arytmetyki pieniedzy, czyli uczyla
	 * ignorowac wlasne zgloszenia.
	 */
	const NIE_KWOTY = array( 'rate', 'rates', 'percent', 'stawka', 'procent' );

	/**
	 * Czy rzutowanie jest krokiem przeliczenia kwoty NA GROSZE.
	 *
	 * Wzorzec `(int) round( (float) $cena * 100 )` to zalecana naprawa, a nie
	 * blad: float zyje przez jedno mnozenie i konczy jako liczba calkowita,
	 * wiec nie ma gdzie sie rozjechac. W tym projekcie jest to dodatkowo
	 * sciezka ZAPASOWA — uzywana tylko tam, gdzie nie ma rozszerzenia BCMath,
	 * wiec nie da sie jej zastapic niczym lepszym.
	 *
	 * Patrzymy na okolice trafienia, bo `round()` stoi PRZED rzutowaniem,
	 * a mnozenie przez 100 — po nim.
	 *
	 * @param string $tresc  Kod pliku.
	 * @param int    $offset Pozycja trafienia.
	 * @return bool
	 */
	private function na_grosze( string $tresc, int $offset ): bool {
		$przed = substr( $tresc, max( 0, $offset - 60 ), min( 60, $offset ) );
		$po    = substr( $tresc, $offset, 120 );

		if ( ! preg_match( '/\bround\s*\(|\bintval\s*\(|\(\s*int\s*\)/i', $przed ) ) {
			return false;
		}

		return (bool) preg_match( '/\*\s*100\b|\*\s*self::[A-Z_]*GROSZ/i', $po );
	}

	/**
	 * Czy trafienie dotyczy stawki, a nie kwoty.
	 *
	 * @param string $fragment Fragment kodu.
	 * @return bool
	 */
	private function stawka( string $fragment ): bool {
		foreach ( self::NIE_KWOTY as $slowo ) {
			if ( false !== stripos( $fragment, $slowo ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$zmiennoprzecinkowe = array();
		$ceny_bez_jednostki = array();
		$kolumny_float      = array();

		$nazwy = implode( '|', self::NAZWY );

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$surowa = $kontekst->workspace->tresc( $plik, $kontekst );
				$tresc  = MP_AU_Pomoc::kod( $surowa );
				$wzgl   = $kontekst->workspace->wzgledna( $plik );

				// (a) rzutowanie kwoty na float.
				if ( preg_match_all( '/\(\s*float\s*\)\s*\$[a-z_]*(?:' . $nazwy . ')[a-z0-9_]*/i', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[0] as $trafienie ) {
						$linia = MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] );

						if ( MP_AU_Pomoc::wyciszone( $surowa, $linia ) || $this->stawka( (string) $trafienie[0] ) ) {
							continue;
						}

						// Przejscie na grosze — `(int) round( (float) $cena * 100 )`.
						// To jest WLASNIE naprawa zalecana przez te pare, a nie blad:
						// float zyje przez jedno mnozenie i konczy jako liczba
						// calkowita. Zgloszenie kazaloby poprawiac kod juz poprawny.
						if ( $this->na_grosze( $tresc, (int) $trafienie[1] ) ) {
							continue;
						}

						$zmiennoprzecinkowe[] = array(
							'plik'     => $wzgl,
							'linia'    => $linia,
							'fragment' => MP_AU_Pomoc::skrot( (string) $trafienie[0], 80 ),
							'rodzaj'   => 'rzutowanie na float',
						);
					}
				}

				// (b) mnozenie kwoty przez ulamek — klasyczne liczenie VAT na float.
				if ( preg_match_all( '/\$[a-z_]*(?:' . $nazwy . ')[a-z0-9_]*\s*[*\/]\s*[01]?\.\d+/i', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[0] as $trafienie ) {
						$linia = MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] );

						if ( MP_AU_Pomoc::wyciszone( $surowa, $linia ) ) {
							continue;
						}

						$zmiennoprzecinkowe[] = array(
							'plik'     => $wzgl,
							'linia'    => $linia,
							'fragment' => MP_AU_Pomoc::skrot( (string) $trafienie[0], 80 ),
							'rodzaj'   => 'dzialanie zmiennoprzecinkowe na kwocie',
						);
					}
				}

				// (c) cena z WooCommerce bez ustalenia, czy jest netto czy brutto.
				if ( preg_match_all( '/->get_price\s*\(|wc_get_price_including_tax|get_regular_price\s*\(/i', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					$wie = false !== strpos( $tresc, 'wc_prices_include_tax' )
						|| false !== strpos( $tresc, 'wc_get_price_excluding_tax' );

					if ( ! $wie ) {
						foreach ( $t[0] as $trafienie ) {
							$ceny_bez_jednostki[] = array(
								'plik'     => $wzgl,
								'linia'    => MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] ),
								'fragment' => MP_AU_Pomoc::skrot( (string) $trafienie[0], 60 ),
							);
						}
					}
				}

				// (d) kolumny pieniezne w DDL jako float/double.
				if ( preg_match_all( '/CREATE TABLE\s+([^ (]{1,80})\s*\((.+?)\)\s*[^;()]{0,120};/is', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						foreach ( explode( ',', $trafienie[2] ) as $wiersz ) {
							if ( preg_match( '/^\s*`?([a-z_]*(?:' . $nazwy . ')[a-z0-9_]*)`?\s+(float|double)/i', trim( $wiersz ), $k ) ) {
								$kolumny_float[] = array(
									'plik'    => $wzgl,
									'linia'   => MP_AU_Pomoc::linia( $tresc, $trafienie[0] ),
									'kolumna' => $k[1],
									'typ'     => $k[2],
								);
							}
						}
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'float'              => $zmiennoprzecinkowe,
				'ceny_bez_jednostki' => $ceny_bez_jednostki,
				'kolumny_float'      => $kolumny_float,
			)
		);
	}
}

/**
 * K1.12 „kwota-w-jednej-jednostce-i-bez-float".
 */
final class MP_AU_K112_Pieniadze extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['float'] ?? array() ) as $f ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.12',
				'Kwota liczona zmiennoprzecinkowo (' . $f['rodzaj'] . ').',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $f['plik'],
					'linia'      => (int) $f['linia'],
					'dowod'      => (string) $f['fragment'],
					'scenariusz' => 'Typ float nie umie zapisac 0,10 dokladnie. Po kilku pozycjach suma '
						. 'rozjedzie sie o grosz — i to grosz, ktorego nie da sie wytlumaczyc klientowi '
						. 'ani ksiegowej. Pieniadze trzyma sie w groszach jako liczbe calkowita.',
					'naprawa'    => 'Przeliczyc na grosze (int) i wykonywac dzialania na liczbach calkowitych.',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['ceny_bez_jednostki'] ?? array() ) as $c ) {
			// Wartosc krytyczna tylko w PIPELINE, bo to on wylicza kwote, ktora
			// trafia do oferty i na fakture. W ekranie administratora ta sama
			// pomylka jest widoczna od razu i nikogo nie obciazy — to nie ta
			// sama szkoda i nie ma powodu podnosic z jej powodu alarmu.
			$w_pipeline = false !== strpos( (string) $c['plik'], '/pipeline/' );

			$ustalenia[] = new MP_AU_Ustalenie(
				'1.12',
				'Cena z WooCommerce pobierana bez ustalenia, czy zawiera VAT'
					. ( $w_pipeline ? '.' : ' (ekran administratora).' ),
				$w_pipeline ? MP_AU_Ustalenie::KRYTYCZNE : MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $c['plik'],
					'linia'      => (int) $c['linia'],
					'dowod'      => $c['fragment'] . ' — w pliku brak wc_prices_include_tax() '
						. 'i brak wc_get_price_excluding_tax()',
					'scenariusz' => 'W sklepie skonfigurowanym na ceny BRUTTO ta wartosc juz zawiera VAT. '
						. 'Doliczenie VAT-u drugi raz zawyza oferte o pelna stawke — u nas o 23% (blad P2-K3). '
						. 'Zadna bramka tego nie zlapala, bo suma pozostawala wewnetrznie spojna.',
					'naprawa'    => 'Sprowadzic do netto przez wc_get_price_excluding_tax() po sprawdzeniu '
						. 'wc_prices_include_tax(); przy braku WooCommerce — twardy blad, nie domysl.',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['kolumny_float'] ?? array() ) as $k ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.12',
				'Kolumna pieniezna „' . $k['kolumna'] . '" typu ' . strtoupper( $k['typ'] ) . '.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $k['plik'],
					'linia'      => (int) $k['linia'],
					'dowod'      => $k['kolumna'] . ' ' . $k['typ'],
					'scenariusz' => 'Baza zaokragli kwote przy zapisie i odczycie. Suma pozycji przestanie '
						. 'zgadzac sie z suma oferty, a roznica bedzie zalezala od kolejnosci dodawania.',
					'naprawa'    => 'BIGINT w groszach albo DECIMAL(10,2).',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Usterki arytmetyki pieniedzy.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.15 */

/**
 * A1.15 „rejestr znanych bledow kontra testy".
 *
 * Najmocniejsza para calego dzialu, bo nie opiera sie na wzorcu, tylko na
 * FAKTACH z historii tego projektu. Wszystkie osiem bledow krytycznych z 29.07
 * przechodzilo przez komplet testow. Pytanie tej pary brzmi: czy dzis kazdy
 * z nich ma test, ktory go wykryje, gdyby wrocil.
 */
final class MP_AU_A115_Rejestr extends MP_AU_Agent {

	/**
	 * Sciezka rejestru.
	 *
	 * @return string
	 */
	public static function sciezka_rejestru(): string {
		return dirname( __DIR__, 2 ) . '/rejestr/znane-bledy.json';
	}

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$sciezka = self::sciezka_rejestru();

		if ( ! is_readable( $sciezka ) ) {
			return MP_AU_Wynik::nieocenione(
				'Brak rejestru znanych bledow: ' . $sciezka . '. Bez niego nie da sie sprawdzic, '
					. 'czy dawne bledy maja dzis testy.'
			);
		}

		$kontekst->policz_odczyt();
		$rejestr = json_decode( (string) file_get_contents( $sciezka ), true );

		if ( ! is_array( $rejestr ) || empty( $rejestr['bledy'] ) ) {
			return MP_AU_Wynik::nieocenione( 'Rejestr znanych bledow jest pusty albo nieczytelny.' );
		}

		$wyniki = array();

		foreach ( (array) $rejestr['bledy'] as $blad ) {
			$wtyczka = (string) ( $blad['wtyczka'] ?? '' );
			$test    = (string) ( $blad['test'] ?? '' );
			$katalog = $kontekst->workspace->katalog( $wtyczka );

			$wpis = array(
				'id'          => (string) ( $blad['id'] ?? '?' ),
				'tytul'       => (string) ( $blad['tytul'] ?? '' ),
				'klasa'       => (string) ( $blad['klasa'] ?? '' ),
				'wtyczka'     => $wtyczka,
				'para'        => (string) ( $blad['para'] ?? '' ),
				'status'      => (string) ( $blad['status'] ?? 'naprawione' ),
				'test'        => $test,
				'test_istnieje' => false,
				'test_opisuje'  => false,
			);

			if ( '' !== $test ) {
				/*
				 * POLE „test" JEST ZDANIEM, NIE SCIEZKA.
				 *
				 * W rejestrze zapisuje sie tam takie rzeczy: „tests/naprawy/x.php",
				 * „mp-offer-builder/tests/naprawy/y.php (sekcja F)", „a.php + b.php",
				 * „a.php, sekcja L", „a.py + istnienie buduj-zipy.py". Kontrola
				 * obcinala wylacznie nawias, wiec 41 wpisow ze 121 zglaszalo sie
				 * jako „test nie istnieje" — czyli jako brak strazy tam, gdzie
				 * straz stoi. Zarzut o falszywe poczucie pokrycia trafial w audyt,
				 * nie w projekt.
				 *
				 * Zamiast zgadywac separatory, wyciagamy wszystkie zetony
				 * wygladajace na sciezke pliku. Wystarczy, ze JEDEN sie rozwiaze.
				 */
				preg_match_all( '#[A-Za-z0-9_./-]+\.(?:php|py|sh)#', $test, $zetony );
				$sciezki = array_values( array_unique( (array) ( $zetony[0] ?? array() ) ) );

				/*
				 * Wpisy o samym narzedziu audytujacym wskazuja testy lezace w JEGO
				 * repozytorium, a ten przebieg oglada repozytorium produktu. Brak
				 * pliku nie znaczy tu braku testu, tylko inny zakres — i tak trzeba
				 * to opisac, zamiast liczyc jako ustalenie.
				 */
				$wlasne = array();

				foreach ( $sciezki as $kandydat ) {
					if ( 0 === strpos( $kandydat, 'audyt/' ) ) {
						$wlasne[] = $kandydat;
					}
				}

				if ( $sciezki && count( $wlasne ) === count( $sciezki ) ) {
					$wpis['test_istnieje'] = true;
					$wpis['test_opisuje']  = true;
					$wpis['poza_zakresem'] = true;
				} else {
					$korzen = $kontekst->workspace->korzen();

					foreach ( $sciezki as $czysta ) {
						$kandydaci = array();

						if ( '' !== $katalog ) {
							$kandydaci[] = $katalog . '/' . $czysta;
						}

						if ( '' !== $korzen ) {
							$kandydaci[] = $korzen . '/' . $czysta;
						}

						foreach ( $kandydaci as $kandydat ) {
							if ( ! is_readable( $kandydat ) ) {
								continue;
							}

							$wpis['test_istnieje'] = true;
							$tresc = $kontekst->workspace->tresc( $kandydat, $kontekst );

							// Czy test WIE, czego pilnuje? Szukamy identyfikatora bledu
							// albo slow kluczowych z jego opisu.
							if ( false !== strpos( $tresc, $wpis['id'] ) || $this->opisuje( $tresc, (string) ( $blad['dowod'] ?? '' ) ) ) {
								$wpis['test_opisuje'] = true;
							}

							$wpis['rozmiar'] = strlen( $tresc );
							break 2;
						}
					}
				}
			}

			$wyniki[] = $wpis;
		}

		return MP_AU_Wynik::ok(
			array(
				'wpisy'  => $wyniki,
				'zrodlo' => (string) ( $rejestr['zrodlo'] ?? '' ),
			)
		);
	}

	/**
	 * Czy tresc testu odnosi sie do dowodu bledu.
	 *
	 * @param string $tresc Tresc testu.
	 * @param string $dowod Dowod z rejestru.
	 * @return bool
	 */
	private function opisuje( string $tresc, string $dowod ): bool {
		if ( '' === $dowod ) {
			return false;
		}

		$slowa = array_filter(
			preg_split( '/[^a-z_]+/i', $dowod ) ?: array(),
			static function ( $slowo ) {
				return strlen( (string) $slowo ) > 5;
			}
		);

		foreach ( $slowa as $slowo ) {
			if ( false !== stripos( $tresc, (string) $slowo ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * K1.15 „kazdy-dawny-blad-ma-swoj-test".
 */
final class MP_AU_K115_Rejestr extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['wpisy'] ?? array() ) as $w ) {
			if ( '' === $w['test'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.15',
					'Znany blad ' . $w['id'] . ' („' . MP_AU_Pomoc::skrot( $w['tytul'], 70 ) . '") nie ma testu regresji.',
					'otwarte' === $w['status'] ? MP_AU_Ustalenie::SREDNIE : MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => (string) $w['wtyczka'],
						'dowod'      => 'Rejestr: pole „test" puste. Klasa bledu: ' . $w['klasa'] . '.',
						'scenariusz' => 'otwarte' === $w['status']
							? 'Blad jest ZNANY i NIENAPRAWIONY, a zadna kontrola nie pilnuje, kiedy sie ujawni.'
							: 'Blad byl naprawiony recznie. Nic nie stoi na przeszkodzie, zeby wrocil przy '
								. 'najblizszym refaktorze — i znowu nikt tego nie zauwazy.',
						'naprawa'    => 'Napisac test, ktory FAIL-uje na kodzie sprzed naprawy (metoda `git stash`).',
					)
				);
				continue;
			}

			if ( ! $w['test_istnieje'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.15',
					'Test wskazany dla bledu ' . $w['id'] . ' nie istnieje w repozytorium.',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => $w['wtyczka'] . '/' . $w['test'],
						'dowod'      => 'Rejestr wskazuje plik, ktorego nie ma na czubku galezi.',
						'scenariusz' => 'Rejestr twierdzi, ze blad jest pilnowany. Nie jest. To gorsze niz '
							. 'brak wpisu, bo daje falszywe poczucie pokrycia.',
						'naprawa'    => 'Poprawic sciezke w rejestrze albo odtworzyc test.',
					)
				);
				continue;
			}

			if ( ! $w['test_opisuje'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.15',
					'Test dla bledu ' . $w['id'] . ' istnieje, ale nie widac w nim sladu tego bledu.',
					MP_AU_Ustalenie::DROBNE,
					array(
						'plik'       => $w['wtyczka'] . '/' . $w['test'],
						'dowod'      => 'W tresci testu brak identyfikatora bledu i slow z jego dowodu.',
						'scenariusz' => 'Prawdopodobnie test sprawdza cos innego w tym samym obszarze. '
							. 'Przy nastepnym refaktorze ktos go uprosci, nie wiedzac, czego pilnowal.',
						'naprawa'    => 'Dopisac w naglowku testu, ktory blad z rejestru on zabezpiecza.',
					)
				);
			}
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Dawne bledy bez pokrycia testami.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.16 */

/**
 * A1.16 „zapytania w petli" — narzut liniowy czy N+1.
 *
 * Nie mierzymy czasu (to zalezy od maszyny), tylko KSZTALT: czy liczba zapytan
 * rosnie razem z liczba pozycji oferty. Oferta na 40 pozycji to nie jest
 * przypadek skrajny, tylko normalne zamowienie hurtowe.
 */
final class MP_AU_A116_Wydajnosc extends MP_AU_Agent {

	/**
	 * Czy liczba obiegow petli jest ustalona W KODZIE, a nie przez dane.
	 *
	 * Narzut liniowy ma sens jako zarzut wtedy, gdy „liniowy" znaczy „rosnacy
	 * razem z danymi". Petla po trzech nazwach tabel przy deinstalacji albo po
	 * dwoch jezykach szablonu wykona dokladnie tyle zapytan, ile zapisal
	 * programista — i zadne `WHERE ... IN` tego nie zmieni, bo to sa operacje
	 * na ROZNYCH obiektach, nie odczyty tego samego ksztaltu.
	 *
	 * Rozpoznajemy dwa zapisy: tablice wprost w naglowku petli oraz wywolanie
	 * metody z tego samego pliku, ktora zwraca tablice bez zmiennych.
	 *
	 * @param string $naglowek     Naglowek petli, np. "foreach ( self::tables() as $t".
	 * @param string $tresc        Tresc calego pliku (kod bez komentarzy).
	 * @param string $cialo_metody Cialo metody, w ktorej petla stoi.
	 * @return bool
	 */
	private static function liczba_obiegow_z_kodu( string $naglowek, string $tresc, string $cialo_metody = '' ): bool {
		// (1) Lista wypisana wprost w naglowku.
		if ( preg_match( '/\b(?:foreach|for)\s*\(\s*(?:array\s*\(|\[)/i', $naglowek ) ) {
			return true;
		}

		// (2) `for` z granica ze stalej albo z liczby — tyle obiegow, ile wpisano.
		if ( preg_match( '/\bfor\s*\(/i', $naglowek )
			&& preg_match( '/[<>]=?\s*(?:\d+|(?:self|static|[A-Z_][A-Z0-9_]*)::[A-Z_][A-Z0-9_]*)\s*;/', $naglowek ) ) {
			return true;
		}

		// (3) Metoda z tego samego pliku zwracajaca liste wypisana wprost.
		if ( preg_match( '/\bforeach\s*\(\s*(?:self|static)::([a-z_][a-z0-9_]*)\s*\(\s*\)/i', $naglowek, $t ) ) {
			return self::lista_wprost( MP_AU_Pomoc::cialo_funkcji( $tresc, (string) $t[1] ) );
		}

		// (4) Zmienna, do ktorej tuz obok przypisano liste wypisana wprost.
		if ( '' !== $cialo_metody && preg_match( '/\bforeach\s*\(\s*(\$[a-z_][a-z0-9_]*)\s+as\b/i', $naglowek, $t ) ) {
			$wzorzec = '/' . preg_quote( (string) $t[1], '/' ) . '\s*=\s*(array\s*\(.*?\)\s*;|\[.*?\]\s*;)/s';

			if ( preg_match( $wzorzec, $cialo_metody, $przypisanie ) ) {
				return self::lista_wprost( (string) $przypisanie[1] );
			}
		}

		return false;
	}

	/**
	 * Czy tresc to lista wypisana wprost, bez udzialu danych.
	 *
	 * Tablica zbudowana z parametru albo z odczytu bazy NIE jest ustalona
	 * w kodzie — liczy sie tylko lista, ktorej dlugosc widac w pliku.
	 *
	 * @param string $tresc Cialo metody albo prawa strona przypisania.
	 * @return bool
	 */
	private static function lista_wprost( string $tresc ): bool {
		if ( '' === $tresc ) {
			return false;
		}

		if ( false === strpos( $tresc, 'array(' ) && false === strpos( $tresc, 'array (' ) && false === strpos( $tresc, '[' ) ) {
			return false;
		}

		return ! preg_match( '/\$(?!this\b)[a-z_][a-z0-9_]*/i', $tresc ) && false === strpos( $tresc, 'get_results' );
	}

	/**
	 * Czy petla wykonuje WYLACZNIE zapisy.
	 *
	 * N+1 to problem ODCZYTU: zamiast jednego zapytania o komplet lecą zapytania
	 * o kazdy element z osobna. Zapis jest inny — kazdy wiersz to inne dane,
	 * wiec instrukcji musi byc tyle, ile wierszy. Zalecenie tej pary („pobrac
	 * komplet jednym zapytaniem, WHERE ... IN") do zapisu po prostu nie pasuje.
	 *
	 * W tym projekcie doszlo do tego drugie: kontrola wyniku POJEDYNCZEGO zapisu
	 * jest celowa i opisana w kodzie (bez niej bramka jakosci sprawdzalaby sama
	 * siebie, bo `affected_rows` roslby niezaleznie od tego, czy wiersz powstal).
	 * Zamiana na jeden INSERT wielowierszowy odebralaby te kontrole — czyli rada
	 * bylaby nie tylko nietrafiona, ale szkodliwa.
	 *
	 * Wystarczy JEDEN odczyt w petli, zeby ustalenie zostalo.
	 *
	 * @param string $cialo_petli Cialo petli (bez podpetli).
	 * @return bool
	 */
	private static function tylko_zapisy( string $cialo_petli ): bool {
		if ( preg_match( '/\$wpdb->(?:get_var|get_row|get_col|get_results)\s*\(|get_post_meta\s*\(|wc_get_product\s*\(|\$wpdb->query\s*\(\s*[\'"]?\s*SELECT/i', $cialo_petli ) ) {
			return false;
		}

		return (bool) preg_match( '/\$wpdb->(?:insert|update|delete|replace)\s*\(|\$wpdb->query\s*\(/', $cialo_petli );
	}

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$w_petli = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$surowa = $kontekst->workspace->tresc( $plik, $kontekst );
				$tresc  = MP_AU_Pomoc::kod( $surowa );
				$wzgl   = $kontekst->workspace->wzgledna( $plik );

				if ( ! preg_match_all( '/\b(foreach|for|while)\s*\(/', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}

				foreach ( $t[0] as $trafienie ) {
					$blok = MP_AU_Pomoc::blok( $tresc, (int) $trafienie[1] );

					if ( '' === $blok ) {
						continue;
					}

					$start    = (int) $trafienie[1];
					$klamra   = strpos( $tresc, '{', $start );
					$naglowek = false === $klamra ? '' : substr( $tresc, $start, $klamra - $start );

					$poz_metody   = strrpos( substr( $tresc, 0, $start ), 'function ' );
					$cialo_metody = false === $poz_metody ? '' : MP_AU_Pomoc::blok( $tresc, (int) $poz_metody );

					// Liczba obiegow wpisana w kod (lista tabel, dwa jezyki szablonu,
					// licznik prob) nie rosnie z danymi — a tylko o taki wzrost tu chodzi.
					if ( self::liczba_obiegow_z_kodu( $naglowek, $tresc, $cialo_metody ) ) {
						continue;
					}

					// Petla zagniezdzona liczylaby sie dwa razy; bierzemy tylko te,
					// w ktorych zapytanie stoi bezposrednio, a nie w podpetli.
					$bez_podpetli = preg_replace( '/\b(?:foreach|for|while)\s*\(.*$/s', '', $blok ) ?? $blok;

					// COMMIT/ROLLBACK w petli dzialow pipeline'u to STEROWANIE
					// TRANSAKCJA, a nie zapytanie o dane. Liczenie go jako N+1
					// bylo falszywym alarmem w kazdym z trzech pipeline'ow.
					$bez_podpetli = (string) preg_replace(
						'/\$wpdb->query\s*\(\s*[\'"](?:COMMIT|ROLLBACK|START TRANSACTION|SET |BEGIN)[^\'"]*[\'"]\s*\)/i',
						'',
						$bez_podpetli
					);

					if ( ! preg_match_all( '/\$wpdb->(get_var|get_row|get_col|get_results|query|insert|update|delete)\s*\(|get_post_meta\s*\(|wc_get_product\s*\(/', $bez_podpetli, $t2, PREG_OFFSET_CAPTURE ) ) {
						continue;
					}

					// Petla samych zapisow nie jest N+1 — patrz opis metody.
					if ( self::tylko_zapisy( $bez_podpetli ) ) {
						continue;
					}

					$linia = MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] );

					if ( MP_AU_Pomoc::wyciszone( $surowa, $linia ) ) {
						continue;
					}

					$w_petli[] = array(
						'plik'     => $wzgl,
						'linia'    => $linia,
						'petla'    => (string) $trafienie[0],
						'zapytan'  => count( $t2[0] ),
						'fragment' => MP_AU_Pomoc::skrot( (string) $t2[0][0][0], 60 ),
					);
				}
			}
		}

		return MP_AU_Wynik::ok( array( 'w_petli' => $w_petli ) );
	}
}

/**
 * K1.16 „narzut-liniowy-nie-N-plus-1".
 */
final class MP_AU_K116_Wydajnosc extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['w_petli'] ?? array() ) as $p ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.16',
				'Zapytanie do bazy wewnatrz petli (' . $p['zapytan'] . ' w jednym obiegu).',
				$p['zapytan'] > 1 ? MP_AU_Ustalenie::SREDNIE : MP_AU_Ustalenie::DROBNE,
				array(
					'plik'       => (string) $p['plik'],
					'linia'      => (int) $p['linia'],
					'dowod'      => $p['petla'] . ' … ' . $p['fragment'],
					'scenariusz' => 'Liczba zapytan rosnie z liczba pozycji. Oferta na 40 pozycji wykona '
						. ( 40 * (int) $p['zapytan'] ) . ' zapytan zamiast jednego. Przy generowaniu PDF-a '
						. 'w tle konczy sie to przekroczeniem limitu czasu, a uzytkownik widzi tylko '
						. 'oferte, ktora „sie nie wygenerowala".',
					'naprawa'    => 'Pobrac komplet jednym zapytaniem (WHERE ... IN) przed petla.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Zapytania w petlach.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.19 */

/**
 * A1.19 „obsluga bledow" — co system robi, gdy cos nie wyjdzie.
 *
 * Sciga jeden wzorzec ponad wszystkie: zapis, ktory MELDUJE SUKCES, choc go nie
 * osiagnal. Blad P2-S4 dokladnie tak wyglada — sciezka do PDF-a zapisana zanim
 * plik naprawde powstal, wiec rekord twierdzi, ze dokument istnieje, a link
 * prowadzi do 404.
 */
final class MP_AU_A119_Obsluga_Bledow extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$puste_catch = array();
		$wyciszenia  = array();
		$zapisy      = array();
		$maile       = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$surowa = $kontekst->workspace->tresc( $plik, $kontekst );
				$tresc  = MP_AU_Pomoc::kod( $surowa );
				$wzgl   = $kontekst->workspace->wzgledna( $plik );

				if ( preg_match_all( '/catch\s*\([^)]*\)\s*\{\s*\}/', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[0] as $trafienie ) {
						$puste_catch[] = array(
							'plik'  => $wzgl,
							'linia' => MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] ),
						);
					}
				}

				if ( preg_match_all( '/(?<![\'"])@(?:file_|unlink|mkdir|fopen|copy|rename|readfile|json_)/', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[0] as $trafienie ) {
						$wyciszenia[] = array(
							'plik'     => $wzgl,
							'linia'    => MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] ),
							'fragment' => MP_AU_Pomoc::skrot( (string) $trafienie[0], 40 ),
						);
					}
				}

				// Zapis pliku bez sprawdzenia wyniku, a obok zapis sciezki do bazy.
				if ( preg_match_all( '/^\s*(?:file_put_contents|copy|rename)\s*\(/mi', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					$sciezka_do_bazy = (bool) preg_match( '/[\'"](?:pdf_path|file_path|path)[\'"]\s*=>/', $tresc );

					foreach ( $t[0] as $trafienie ) {
						$zapisy[] = array(
							'plik'       => $wzgl,
							'linia'      => MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] ),
							'fragment'   => MP_AU_Pomoc::skrot( (string) $trafienie[0], 60 ),
							'zapis_sciezki' => $sciezka_do_bazy,
						);
					}
				}

				if ( preg_match_all( '/^\s*wp_mail\s*\(/mi', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[0] as $trafienie ) {
						$maile[] = array(
							'plik'  => $wzgl,
							'linia' => MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] ),
						);
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'puste_catch' => $puste_catch,
				'wyciszenia'  => $wyciszenia,
				'zapisy'      => $zapisy,
				'maile'       => $maile,
			)
		);
	}
}

/**
 * K1.19 „porazka-musi-byc-widoczna".
 */
final class MP_AU_K119_Obsluga_Bledow extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['puste_catch'] ?? array() ) as $c ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.19',
				'Pusty blok `catch` — wyjatek znika bez sladu.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $c['plik'],
					'linia'      => (int) $c['linia'],
					'dowod'      => 'catch (...) {}',
					'scenariusz' => 'Operacja sie nie udala, a kod plynie dalej tak, jakby sie udala. '
						. 'W logach nie ma niczego, wiec diagnoza zaczyna sie od zera.',
					'naprawa'    => 'Zapisac wyjatek do dziennika albo przekazac dalej.',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['wyciszenia'] ?? array() ) as $w ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.19',
				'Operacja na plikach z wyciszeniem bledow (`@`).',
				MP_AU_Ustalenie::DROBNE,
				array(
					'plik'       => (string) $w['plik'],
					'linia'      => (int) $w['linia'],
					'dowod'      => (string) $w['fragment'],
					'scenariusz' => 'Brak uprawnien albo brak miejsca na dysku nie zglosi sie w zaden sposob.',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['zapisy'] ?? array() ) as $z ) {
			if ( ! $z['zapis_sciezki'] ) {
				continue;
			}

			$ustalenia[] = new MP_AU_Ustalenie(
				'1.19',
				'Zapis pliku bez sprawdzenia wyniku, a w tym samym pliku sciezka trafia do bazy.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $z['plik'],
					'linia'      => (int) $z['linia'],
					'dowod'      => (string) $z['fragment'],
					'scenariusz' => 'Blad P2-S4: rekord dostaje sciezke do pliku, ktory nie powstal. Klient '
						. 'klika w link do oferty i dostaje 404, a system twierdzi, ze dokument jest gotowy.',
					'naprawa'    => 'Sprawdzic wynik zapisu i dopiero potem zapisac sciezke; przy porazce '
						. 'ustawic status bledu.',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['maile'] ?? array() ) as $m ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.19',
				'Wynik `wp_mail()` nie jest sprawdzany.',
				MP_AU_Ustalenie::OBSERWACJA,
				array(
					'plik'       => (string) $m['plik'],
					'linia'      => (int) $m['linia'],
					'dowod'      => 'wp_mail() wolane jako instrukcja, bez przypisania wyniku.',
					'scenariusz' => 'Odrzucenie przez serwer poczty przejdzie niezauwazone. Handlowiec bedzie '
						. 'przekonany, ze oferta poszla do klienta.',
					'naprawa'    => 'Zapisac wynik i odnotowac porazke w dzienniku wysylek.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Bledy, ktore nikogo nie informuja.', $ustalenia, $od_agenta->dane );
	}
}
