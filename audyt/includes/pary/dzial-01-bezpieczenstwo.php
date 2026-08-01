<?php
/**
 * Dzial 1, grupa BEZPIECZENSTWO: pary 1.9, 1.11, 1.14.
 *
 * Trzy rodzaje szkody, ktore ta grupa ma wykluczyc: cudzy dostep do danych
 * (1.9), dane osobowe, ktore mialy zniknac i nie znikly (1.11), oraz szkoda
 * prawna z tytulu licencji (1.14). Wszystkie trzy laczy to, ze nie objawiaja
 * sie bledem — system dziala dalej i wyglada zdrowo.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/* ===================================================================== 1.9 */

/**
 * A1.9 „punkty wejscia" — kazde miejsce, w ktorym zadanie z sieci dotyka kodu.
 *
 * Agent nie szuka „w poblizu" — wyciaga CIALO metody obslugujacej i pyta o nie
 * osobno. Szukanie w promieniu N znakow znajduje sprawdzenie uprawnien
 * z sasiedniej metody i wystawia czysta ocene handlerowi, ktory nie sprawdza nic.
 */
final class MP_AU_A19_Bezpieczenstwo extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$wejscia   = array();
		$rest_otw  = array();
		$porownania = array();
		$pliki_bez_realpath = array();
		$superglobalne = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$surowa = $kontekst->workspace->tresc( $plik, $kontekst );
				$tresc  = MP_AU_Pomoc::kod( $surowa );
				$wzgl   = $kontekst->workspace->wzgledna( $plik );

				// (a) Punkty wejscia AJAX / admin-post / init-owe handlery.
				if ( preg_match_all( '/add_action\s*\(\s*[\'"](wp_ajax_[a-z0-9_]+|wp_ajax_nopriv_[a-z0-9_]+|admin_post_[a-z0-9_]+|admin_post_nopriv_[a-z0-9_]+)[\'"]\s*,(.{0,160}?)\)\s*;/s', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$metoda = preg_match( '/[\'"]([a-z_][a-z0-9_]*)[\'"]\s*\)?\s*$/i', trim( $trafienie[2] ), $m ) ? $m[1] : '';
						$cialo  = '' === $metoda ? '' : MP_AU_Pomoc::cialo_funkcji( $tresc, $metoda );

						$wejscia[] = array(
							'plik'        => $wzgl,
							'linia'       => MP_AU_Pomoc::linia( $tresc, $trafienie[0] ),
							'hak'         => $trafienie[1],
							'metoda'      => $metoda,
							'cialo_znane' => '' !== $cialo,
							'nonce'       => (bool) preg_match( '/check_ajax_referer|wp_verify_nonce|check_admin_referer/', $cialo ),
							'uprawnienia' => (bool) preg_match( '/current_user_can|user_can\s*\(/', $cialo ),
							'publiczny'   => false !== strpos( $trafienie[1], 'nopriv' ),
						);
					}
				}

				// (b) Trasy REST otwarte dla wszystkich.
				if ( preg_match_all( '/permission_callback[\'"\s=>]+__return_true/', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[0] as $trafienie ) {
						$rest_otw[] = array(
							'plik'  => $wzgl,
							'linia' => MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] ),
						);
					}
				}

				// (c) Porownanie podpisu operatorem zamiast hash_equals().
				if ( preg_match_all( '/(\$[a-z_][a-z0-9_]*(?:sig|podpis|hash|hmac|token)[a-z0-9_]*)\s*(===|==|!==|!=)\s*(\$[a-z_][a-z0-9_]*)/i', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$porownania[] = array(
							'plik'     => $wzgl,
							'linia'    => MP_AU_Pomoc::linia( $tresc, $trafienie[0] ),
							'fragment' => MP_AU_Pomoc::skrot( $trafienie[0], 90 ),
						);
					}
				}

				// (d) Wydawanie pliku bez sprawdzenia, gdzie ten plik lezy.
				// Wydawanie pliku jest grozne wtedy, gdy sciezke moze ukladac
				// UZYTKOWNIK. Plik operujacy wylacznie na sciezkach z wlasnego
				// pipeline'u nie ma tego problemu — a zgloszenie go jako luki
				// krytycznej byloby falszywym alarmem (i bylo nim w pierwszym
				// przebiegu tej pary).
				$sciezka_z_zadania = (bool) preg_match( '/\$_(GET|POST|REQUEST|COOKIE|FILES)/', $tresc );

				if ( $sciezka_z_zadania && preg_match_all( '/\b(readfile|fpassthru|file_get_contents)\s*\(\s*\$/i', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[0] as $trafienie ) {
						$linia = MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] );

						if ( false !== strpos( $tresc, 'realpath' ) || MP_AU_Pomoc::wyciszone( $surowa, $linia ) ) {
							continue;
						}

						$pliki_bez_realpath[] = array(
							'plik'     => $wzgl,
							'linia'    => $linia,
							'fragment' => MP_AU_Pomoc::skrot( (string) $trafienie[0], 60 ),
						);
					}
				}

				// (e) Superglobalne bez odslaniania i bez odkazania.
				if ( preg_match_all( '/\$_(GET|POST|REQUEST|COOKIE)\s*\[\s*[\'"]([a-z0-9_]+)[\'"]\s*\]/i', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$linia   = MP_AU_Pomoc::linia( $tresc, $trafienie[0] );
						$wiersze = explode( "\n", $tresc );
						$wiersz  = $wiersze[ $linia - 1 ] ?? '';

						if ( preg_match( '/sanitize_|wp_unslash|absint|intval|\(int\)|isset\s*\(|empty\s*\(|array_key_exists/', $wiersz ) ) {
							continue;
						}

						if ( MP_AU_Pomoc::wyciszone( $surowa, $linia ) ) {
							continue;
						}

						$superglobalne[] = array(
							'plik'     => $wzgl,
							'linia'    => $linia,
							'fragment' => MP_AU_Pomoc::skrot( $wiersz, 120 ),
						);
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'wejscia'        => $wejscia,
				'rest_otwarte'   => $rest_otw,
				'porownania'     => $porownania,
				'bez_realpath'   => $pliki_bez_realpath,
				'superglobalne'  => $superglobalne,
			)
		);
	}
}

/**
 * K1.9 „kazdy-punkt-wejscia-broniony".
 */
final class MP_AU_K19_Bezpieczenstwo extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['wejscia'] ?? array() ) as $w ) {
			if ( ! $w['cialo_znane'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.9',
					'Nie udalo sie odczytac ciala handlera „' . $w['hak'] . '" — ochrona NIEZWERYFIKOWANA.',
					MP_AU_Ustalenie::OBSERWACJA,
					array(
						'plik'       => (string) $w['plik'],
						'linia'      => (int) $w['linia'],
						'dowod'      => 'Callback nie jest metoda nazwana w tym samym pliku.',
						'scenariusz' => 'Ten punkt wejscia trzeba sprawdzic recznie; audyt go NIE potwierdzil.',
					)
				);
				continue;
			}

			if ( ! $w['nonce'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.9',
					'Punkt wejscia „' . $w['hak'] . '" bez sprawdzenia nonce.',
					MP_AU_Ustalenie::KRYTYCZNE,
					array(
						'plik'       => (string) $w['plik'],
						'linia'      => (int) $w['linia'],
						'dowod'      => 'W ciele metody „' . $w['metoda'] . '" brak check_ajax_referer/wp_verify_nonce.',
						'scenariusz' => 'CSRF: obca strona odwiedzona przez zalogowanego handlowca moze wykonac '
							. 'te akcje w jego imieniu (zatwierdzic oferte, zmienic status, wyslac maila).',
						'naprawa'    => 'check_ajax_referer( „nazwa_akcji", „_wpnonce" ) na poczatku metody.',
					)
				);
			}

			if ( ! $w['uprawnienia'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.9',
					'Punkt wejscia „' . $w['hak'] . '" bez sprawdzenia uprawnien.',
					MP_AU_Ustalenie::KRYTYCZNE,
					array(
						'plik'       => (string) $w['plik'],
						'linia'      => (int) $w['linia'],
						'dowod'      => 'W ciele metody „' . $w['metoda'] . '" brak current_user_can().',
						'scenariusz' => $w['publiczny']
							? 'Handler jest zarejestrowany takze dla NIEZALOGOWANYCH (nopriv) i nie pyta o nic. '
								. 'Kazdy z internetu wykona te akcje.'
							: 'Kazdy zalogowany uzytkownik — takze subskrybent — wykona akcje przeznaczona '
								. 'dla handlowca. Sam fakt zalogowania nie jest uprawnieniem.',
						'naprawa'    => 'current_user_can( „mp_..." ) i wyjscie z 403 przy braku.',
					)
				);
			}
		}

		foreach ( (array) ( $od_agenta->dane['rest_otwarte'] ?? array() ) as $r ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.9',
				'Trasa REST z `permission_callback => __return_true`.',
				MP_AU_Ustalenie::KRYTYCZNE,
				array(
					'plik'       => (string) $r['plik'],
					'linia'      => (int) $r['linia'],
					'dowod'      => 'permission_callback => __return_true',
					'scenariusz' => 'Trasa jest otwarta dla calego internetu. Jesli zwraca albo zmienia '
						. 'cokolwiek zwiazanego z oferta lub leadem, to jest wyciek albo zdalna edycja.',
					'naprawa'    => 'Zastapic callbackiem sprawdzajacym current_user_can().',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['porownania'] ?? array() ) as $p ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.9',
				'Porownanie podpisu operatorem zamiast `hash_equals()`.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $p['plik'],
					'linia'      => (int) $p['linia'],
					'dowod'      => (string) $p['fragment'],
					'scenariusz' => 'Porownanie napisow konczy sie na pierwszej roznicy, wiec czas odpowiedzi '
						. 'zdradza, ile pierwszych znakow podpisu zgadlo sie poprawnie. Przy dostatecznej '
						. 'liczbie prob podpis da sie odtworzyc znak po znaku.',
					'naprawa'    => 'hash_equals( $oczekiwany, $otrzymany ).',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['bez_realpath'] ?? array() ) as $b ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.9',
				'Wydawanie pliku ze zmiennej bez sprawdzenia sciezki (`realpath`).',
				MP_AU_Ustalenie::KRYTYCZNE,
				array(
					'plik'       => (string) $b['plik'],
					'linia'      => (int) $b['linia'],
					'dowod'      => (string) $b['fragment'],
					'scenariusz' => 'Sciezka ze znakami „../" wyprowadzi poza katalog uploads. W skrajnym '
						. 'przypadku klient pobierze wp-config.php razem z haslem do bazy.',
					'naprawa'    => 'realpath() + sprawdzenie, ze wynik zaczyna sie od katalogu uploads.',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['superglobalne'] ?? array() ) as $s ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.9',
				'Dane z zadania uzyte bez odkazania.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $s['plik'],
					'linia'      => (int) $s['linia'],
					'dowod'      => (string) $s['fragment'],
					'scenariusz' => 'Wartosc sterowana przez uzytkownika wchodzi do logiki bez wp_unslash() '
						. 'i bez sanitize_*. Zaleznie od miejsca uzycia: XSS, wstrzykniecie albo zapis smiecia.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Luki w ochronie punktow wejscia.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.11 */

/**
 * A1.11 „RODO" — czy dane osobowe naprawde znikaja, i to po OBU stronach granicy.
 *
 * Ta para istnieje z powodu dwoch realnych bledow. P3-K2: anonimizacja filtrowala
 * po kolumnie, ktorej nie bylo w schemacie, wiec nie czyscila niczego. P3-K3:
 * po anonimizacji kolejka dalej probowala wysylac maile na adres „...@invalid".
 * Wspolna cecha: obie sciezki KONCZYLY SIE SUKCESEM.
 */
final class MP_AU_A111_Rodo extends MP_AU_Agent {

	/** Kolumny, ktore uznajemy za dane osobowe. */
	/*
	 * Dopasowanie jest DOKLADNE (rowne albo z przyrostkiem `_<cos>`), nie „zawiera".
	 * Wersja z `strpos` zgłaszała `nip` i `description` jako dane osobowe, bo obie
	 * zawieraja „ip". Falszywy alarm w RODO jest szczegolnie kosztowny: kaze
	 * kasowac dane, ktorych kasowac nie wolno (NIP jest daną firmy, nie osoby).
	 */
	const OSOBOWE = array( 'email', 'phone', 'telefon', 'client_name', 'client_email', 'first_name', 'last_name', 'ip_address', 'user_ip', 'recipient', 'address' );

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$fakty = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			$stan = array(
				'kolumny'       => array(),
				'eraser'        => false,
				'exporter'      => false,
				'czyszczone'    => array(),
				'sygnal_stop'   => false,
				'zeruje_adres'  => false,
				'pliki_rodo'    => array(),
			);

			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$tresc = MP_AU_Pomoc::kod( $kontekst->workspace->tresc( $plik, $kontekst ) );
				$wzgl  = $kontekst->workspace->wzgledna( $plik );

				// Kolumny osobowe z DDL.
				if ( preg_match_all( '/CREATE TABLE\s+([^ (]{1,80})\s*\((.+?)\)\s*[^;()]{0,120};/is', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						foreach ( explode( ',', $trafienie[2] ) as $wiersz ) {
							if ( ! preg_match( '/^\s*`?([a-z_][a-z0-9_]*)`?\s+(?:varchar|text|char|tinytext)/i', trim( $wiersz ), $k ) ) {
								continue;
							}

							$nazwa_kolumny = strtolower( $k[1] );

							foreach ( self::OSOBOWE as $wzorzec ) {
								if ( $nazwa_kolumny === $wzorzec
									|| 0 === strpos( $nazwa_kolumny, $wzorzec . '_' )
									|| substr( $nazwa_kolumny, -strlen( '_' . $wzorzec ) ) === '_' . $wzorzec ) {
									$stan['kolumny'][ $k[1] ] = $wzgl;
									break;
								}
							}
						}
					}
				}

				/*
				 * „anonim" wystepuje w tym projekcie WYLACZNIE w polskich
				 * komentarzach, a te sa tu juz wygaszone przez `kod()`. W samym
				 * kodzie stoi angielskie `anonymize_*` — przez „y", nie przez „i".
				 * Skutek byl taki, ze plik z cala anonimizacja IP nie liczyl sie
				 * jako plik RODO i para zglaszala wyczyszczona kolumne jako
				 * niewyczyszczona. Falszywy alarm w RODO jest szczegolnie kosztowny:
				 * kaze poprawiac coś, co dziala, i podwaza zaufanie do reszty raportu.
				 */
				$czy_rodo = false !== strpos( $tresc, 'wp_privacy_personal_data_erasers' )
					|| false !== stripos( $tresc, 'anonim' )
					|| false !== stripos( $tresc, 'anonym' )
					|| false !== strpos( $tresc, 'Privacy' );

				if ( false !== strpos( $tresc, 'wp_privacy_personal_data_erasers' ) ) {
					$stan['eraser'] = true;
				}

				if ( false !== strpos( $tresc, 'wp_privacy_personal_data_exporters' ) ) {
					$stan['exporter'] = true;
				}

				if ( preg_match( '/function\s+is_anonymized|@invalid/', $tresc ) ) {
					$stan['sygnal_stop'] = true;
				}

				/*
				 * Czy anonimizacja ZERUJE kolumne adresu, czy PODSTAWIA w nia
				 * adres zastepczy. To rozroznienie rozstrzyga o regule „sygnalu
				 * anonimizacji" nizej.
				 */
				if ( preg_match( '/[\'"`][a-z_]*email[a-z_]*[\'"`]\s*=>\s*null\b/i', $tresc ) ) {
					$stan['zeruje_adres'] = true;
				}

				if ( $czy_rodo ) {
					$stan['pliki_rodo'][] = $wzgl;

					foreach ( self::OSOBOWE as $wzorzec ) {
						// Cudzyslow przed nazwa jest OPCJONALNY: anonimizacja bywa
						// pisana surowym SQL-em („SET recipient = %s"), a wersja
						// wymagajaca cudzyslowu nie widziala tego i zglaszala
						// wyczyszczona kolumne jako niewyczyszczona.
						if ( preg_match( '/[\'"`\s,(]([a-z_]*' . $wzorzec . '[a-z_]*)[\'"`]?\s*(?:=>|=)/i', $tresc, $m ) ) {
							$stan['czyszczone'][ $m[1] ] = true;
						}
					}
				}
			}

			$fakty[ $branch ] = $stan;
		}

		return MP_AU_Wynik::ok( array( 'fakty' => $fakty ) );
	}
}

/**
 * K1.11 „dane-znikaja-po-obu-stronach".
 */
final class MP_AU_K111_Rodo extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['fakty'] ?? array() ) as $branch => $stan ) {
			if ( empty( $stan['kolumny'] ) ) {
				continue;
			}

			if ( ! $stan['eraser'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.11',
					'Wtyczka „' . $branch . '" trzyma dane osobowe, ale nie rejestruje eraseru RODO.',
					MP_AU_Ustalenie::KRYTYCZNE,
					array(
						'plik'       => (string) reset( $stan['kolumny'] ),
						'dowod'      => 'kolumny osobowe: ' . implode( ', ', array_keys( $stan['kolumny'] ) )
							. '; brak wp_privacy_personal_data_erasers',
						'scenariusz' => 'Zadanie „usuncie moje dane" zlozone przez klienta zostanie wykonane '
							. 'przez WordPressa i przez pozostale wtyczki, a TE dane zostana. Formalnie: '
							. 'niewykonanie zadania z art. 17 RODO.',
						'naprawa'    => 'Zarejestrowac eraser i exporter w tej wtyczce.',
					)
				);
			}

			if ( ! $stan['exporter'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.11',
					'Wtyczka „' . $branch . '" nie rejestruje eksportera danych osobowych.',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => $branch,
						'dowod'      => 'brak wp_privacy_personal_data_exporters',
						'scenariusz' => 'Zadanie dostepu do danych (art. 15 RODO) zwroci komplet BEZ danych '
							. 'z tej wtyczki, a wygladac bedzie na kompletny.',
					)
				);
			}

			$nieczyszczone = array_diff( array_keys( $stan['kolumny'] ), array_keys( (array) $stan['czyszczone'] ) );

			foreach ( $nieczyszczone as $kolumna ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.11',
					'Kolumna osobowa „' . $kolumna . '" nie pojawia sie w kodzie anonimizacji.',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => (string) $stan['kolumny'][ $kolumna ],
						'dowod'      => 'Kolumna w DDL; brak odwolania w plikach RODO: '
							. implode( ', ', array_slice( (array) $stan['pliki_rodo'], 0, 3 ) ),
						'scenariusz' => 'Po anonimizacji ta kolumna zachowa dane osobowe. Rekord bedzie '
							. 'wygladal na zanonimizowany, bo adres e-mail sie zmienil.',
					)
				);
			}

			/*
			 * SYGNAL ANONIMIZACJI — tylko tam, gdzie jest co rozpoznawac.
			 *
			 * Blad P3-K3 wzial sie stad, ze anonimizacja PODSTAWIALA w kolumne
			 * adresu adres zastepczy `deleted+N@invalid`. Taki wiersz wyglada
			 * dalej na kompletny — ma adres, wiec kolejka powiadomien probuje na
			 * niego wysylac. Dopiero `is_anonymized()` pozwala go odroznic.
			 *
			 * Wtyczka, ktora kolumne adresu ZERUJE (`client_email => null`), nie
			 * ma tego problemu: nie ma adresu, ktory dalo by sie wziac za
			 * prawdziwy, wiec nie ma tez czego rozpoznawac. Regula bez tego
			 * rozroznienia zglaszala „mp-offer-builder" w kazdym przebiegu.
			 *
			 * Zawezenie idzie po SPOSOBIE ANONIMIZACJI, nie po obecnosci wysylki:
			 * pierwsza wersja tej poprawki pytala o `wp_mail()` i nadal
			 * zglaszala te wtyczke, bo ta owszem wysyla poczte — ale wylacznie
			 * alarmy do administratora, nigdy na adres z anonimizowanej kolumny.
			 * Test: `tests/regula-1-11.php`.
			 */
			if ( ! $stan['sygnal_stop'] && $stan['eraser'] && empty( $stan['zeruje_adres'] ) ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.11',
					'Wtyczka „' . $branch . '" anonimizuje dane, ale nie wystawia sygnalu „ten rekord jest juz anonimowy".',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => $branch,
						'dowod'      => 'Brak funkcji rozpoznajacej rekord zanonimizowany (np. is_anonymized).',
						'scenariusz' => 'Dokladnie blad P3-K3: kolejka powiadomien dalej probuje wysylac na '
							. 'adres zastepczy, bo nikt jej nie powiedzial, ze adresata juz nie ma.',
						'naprawa'    => 'Publiczna metoda rozpoznajaca anonimizacje + sprawdzenie jej przed wysylka.',
					)
				);
			}
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Braki w obsludze RODO.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.14 */

/**
 * A1.14 „licencje" — wtyczki i ich zaleznosci.
 *
 * Blad licencyjny nie objawia sie nigdy technicznie. Objawia sie pismem.
 */
final class MP_AU_A114_Licencje extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$fakty = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			$katalog = $kontekst->workspace->katalog( $branch );

			if ( '' === $katalog || ! is_dir( $katalog ) ) {
				continue;
			}

			$naglowek = '';
			$glowny   = $katalog . '/' . $branch . '.php';

			if ( is_readable( $glowny ) ) {
				$naglowek = substr( $kontekst->workspace->tresc( $glowny, $kontekst ), 0, 2500 );
			}

			$composer = array();

			if ( is_readable( $katalog . '/composer.json' ) ) {
				$composer = (array) json_decode( $kontekst->workspace->tresc( $katalog . '/composer.json', $kontekst ), true );
			}

			// Licencje zaleznosci: kazdy composer.json w vendor/.
			$zaleznosci = array();
			$wzorzec    = $katalog . '/vendor/*/*/composer.json';

			foreach ( glob( $wzorzec ) ?: array() as $plik ) {
				$dane = (array) json_decode( $kontekst->workspace->tresc( $plik, $kontekst ), true );

				if ( empty( $dane['name'] ) ) {
					continue;
				}

				$zaleznosci[ (string) $dane['name'] ] = implode( ', ', (array) ( $dane['license'] ?? array( 'NIEZNANA' ) ) );
			}

			$fakty[ $branch ] = array(
				'licencja_plik'  => is_readable( $katalog . '/LICENSE' ) || is_readable( $katalog . '/LICENSE.txt' ),
				'naglowek_lic'   => (bool) preg_match( '/^\s*\*?\s*License:\s*(.+)$/mi', $naglowek, $m ),
				'naglowek_tresc' => isset( $m[1] ) ? trim( $m[1] ) : '',
				'naglowek_uri'   => (bool) preg_match( '/License URI:/i', $naglowek ),
				'composer_lic'   => (string) ( $composer['license'] ?? '' ),
				'zaleznosci'     => $zaleznosci,
			);
		}

		return MP_AU_Wynik::ok( array( 'fakty' => $fakty ) );
	}
}

/**
 * K1.14 „licencja-spojna-i-zgodna-z-zaleznosciami".
 */
final class MP_AU_K114_Licencje extends MP_AU_Krytyk {

	/** Licencje wymagajace czlonu „or-later" po stronie GPL-2.0. */
	const WYMAGAJA_OR_LATER = array( 'LGPL-3.0', 'LGPL-3.0-only', 'LGPL-3.0-or-later', 'GPL-3.0', 'GPL-3.0-only', 'GPL-3.0-or-later', 'AGPL-3.0' );

	/**
	 * Sprowadza zapis licencji do postaci porownywalnej.
	 *
	 * @param string $zapis Zapis licencji.
	 * @return string
	 */
	private static function kanoniczna( string $zapis ): string {
		$z = strtolower( $zapis );
		$z = str_replace( array( 'gplv', 'gpl-', 'gpl ' ), 'gpl', $z );
		$z = (string) preg_replace( '/(\d)\.0/', '$1', $z );
		$z = (string) preg_replace( '/or any later version|or-later|or later/', 'orlater', $z );
		$z = (string) preg_replace( '/[^a-z0-9]/', '', $z );

		return $z;
	}

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['fakty'] ?? array() ) as $branch => $stan ) {
			if ( ! $stan['licencja_plik'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.14',
					'Wtyczka „' . $branch . '" nie ma pliku LICENSE.',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => $branch . '/LICENSE',
						'dowod'      => 'Brak LICENSE i LICENSE.txt w katalogu wtyczki.',
						'scenariusz' => 'Odbiorca nie dostaje pelnego tekstu licencji, ktorego wymaga sama GPL. '
							. 'Naglowek z jedna linijka to nie jest tekst licencji.',
					)
				);
			}

			if ( ! $stan['naglowek_lic'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.14',
					'Naglowek wtyczki „' . $branch . '" nie deklaruje licencji.',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => $branch . '/' . $branch . '.php',
						'dowod'      => 'Brak pola „License:" w naglowku.',
						'scenariusz' => 'WordPress i narzedzia dystrybucji nie maja jak ustalic licencji; '
							. 'przy publikacji w repozytorium WP.org to blokada.',
					)
				);
			}

			if ( ! $stan['naglowek_uri'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.14',
					'Naglowek wtyczki „' . $branch . '" bez „License URI".',
					MP_AU_Ustalenie::DROBNE,
					array(
						'plik'       => $branch . '/' . $branch . '.php',
						'dowod'      => 'Brak pola „License URI:".',
						'scenariusz' => 'Brak jednoznacznego wskazania wersji tekstu licencji.',
					)
				);
			}

			$deklarowana = (string) $stan['naglowek_tresc'];
			$composer    = (string) $stan['composer_lic'];

			if ( '' !== $composer && '' !== $deklarowana ) {
				// „GPLv2 or later" i „GPL-2.0-or-later" to TA SAMA licencja zapisana
				// dwiema konwencjami. Porownanie tekstowe uznawalo je za rozne
				// i zglaszalo nieistniejacy rozjazd.
				$zgodne = self::kanoniczna( $deklarowana ) === self::kanoniczna( $composer );

				if ( ! $zgodne ) {
					$ustalenia[] = new MP_AU_Ustalenie(
						'1.14',
						'Licencja w naglowku i w composer.json brzmia inaczej.',
						MP_AU_Ustalenie::DROBNE,
						array(
							'plik'       => $branch . '/composer.json',
							'dowod'      => 'naglowek: „' . $deklarowana . '", composer: „' . $composer . '"',
							'scenariusz' => 'Dwa zrodla prawdy o licencji. Przy sporze nie wiadomo, ktore obowiazuje.',
						)
					);
				}
			}

			$ma_or_later = false !== stripos( $deklarowana . ' ' . $composer, 'or-later' )
				|| false !== stripos( $deklarowana, 'or any later version' );

			foreach ( (array) $stan['zaleznosci'] as $paczka => $licencja ) {
				foreach ( self::WYMAGAJA_OR_LATER as $wymagajaca ) {
					if ( false === stripos( $licencja, $wymagajaca ) ) {
						continue;
					}

					if ( $ma_or_later ) {
						continue 2;
					}

					$ustalenia[] = new MP_AU_Ustalenie(
						'1.14',
						'Zaleznosc „' . $paczka . '" na licencji ' . $licencja . ' przy GPL-2.0 bez czlonu „or-later".',
						MP_AU_Ustalenie::KRYTYCZNE,
						array(
							'plik'       => $branch . '/vendor/' . $paczka . '/composer.json',
							'dowod'      => 'licencja zaleznosci: ' . $licencja . '; licencja wtyczki: „' . $deklarowana . '"',
							'scenariusz' => 'GPL-2.0 (tylko dwa zero) i licencje z rodziny 3.0 sa NIEZGODNE. '
								. 'Dystrybucja calosci narusza warunki jednej ze stron. Czlon „or later" '
								. 'usuwa problem, bo pozwala odbiorcy zastosowac wersje 3.0.',
							'naprawa'    => 'Zadeklarowac „GPL-2.0-or-later" w naglowku i w composer.json.',
						)
					);

					continue 2;
				}
			}
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Usterki licencyjne.', $ustalenia, $od_agenta->dane );
	}
}
