<?php
/**
 * Dzial 1, grupa INTEGRACJA: pary 1.6, 1.18, 1.21, 1.23.
 *
 * Cala integracja trzech wtyczek stoi na hakach i na wspolnych rekordach. Nie ma
 * tu wspolnego kodu, ktory by cokolwiek wymusil — sa tylko UMOWY. Ta grupa
 * sprawdza, czy obie strony kazdej umowy nadal ja rozumieja tak samo.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/* ===================================================================== 1.6 */

/**
 * A1.6 „kontrakty hakow" — kto wystawia zdarzenie i kto go slucha.
 *
 * Zbiera obie strony granicy naraz. To jedyne miejsce w calym projekcie, gdzie
 * da sie to zrobic: pojedynczy branch widzi tylko swoja wtyczke, wiec rozjazd
 * kontraktu jest tam NIEWYKRYWALNY z definicji.
 */
final class MP_AU_A16_Kontrakty_Hakow extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$emisje    = array();
		$nasluchy  = array();
		$callbacki = array();
		$cronowe   = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$tresc = MP_AU_Pomoc::kod( $kontekst->workspace->tresc( $plik, $kontekst ) );
				$wzgl  = $kontekst->workspace->wzgledna( $plik );

				// Emisje: do_action( 'nazwa', arg, arg ).
				if ( preg_match_all( '/do_action\s*\(\s*[\'"](mp_[a-z0-9_]+)[\'"]([^;]{0,300}?)\)\s*;/s', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$ogon = trim( $trafienie[2] );

						$emisje[ $trafienie[1] ][] = array(
							'branch'   => $branch,
							'plik'     => $wzgl,
							'linia'    => MP_AU_Pomoc::linia( $tresc, $trafienie[0] ),
							'argumenty' => '' === $ogon ? 0 : substr_count( $ogon, ',' ),
						);
					}
				}

				// Nasluchy: add_action( 'nazwa', callback, priorytet, ile_argumentow ).
				if ( preg_match_all( '/add_action\s*\(\s*[\'"](mp_[a-z0-9_]+)[\'"]\s*,(.{0,200}?)\)\s*;/s', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$ogon  = $trafienie[2];
						$czesci = array_map( 'trim', explode( ',', preg_replace( '/array\s*\([^)]*\)/', 'CALLABLE', $ogon ) ?? $ogon ) );

						$nasluchy[ $trafienie[1] ][] = array(
							'branch'    => $branch,
							'plik'      => $wzgl,
							'linia'     => MP_AU_Pomoc::linia( $tresc, $trafienie[0] ),
							'przyjmuje' => isset( $czesci[2] ) && is_numeric( $czesci[2] ) ? (int) $czesci[2] : 1,
							'callback'  => MP_AU_Pomoc::skrot( $czesci[0], 80 ),
							'metoda'    => preg_match( '/[\'"]([a-z_][a-z0-9_]*)[\'"]\s*$/i', $ogon, $m ) ? $m[1] : '',
						);
					}
				}

				// Haki crona: WordPress odpala je z harmonogramu, nie przez
				// do_action w kodzie. Brak emisji NIE jest tu bledem — to byl
				// falszywy alarm pierwszego przebiegu tej pary.
				if ( preg_match_all( '/wp_(?:schedule_event|schedule_single_event|next_scheduled|clear_scheduled_hook|unschedule_hook)\s*\([^;]{0,200}?[\'"]([a-z0-9_]+)[\'"]/i', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$cronowe[ $trafienie[1] ] = true;
					}
				}

				// Sygnatury metod — do sprawdzenia, ile parametrow WYMAGAJA.
				if ( preg_match_all( '/function\s+([a-z_][a-z0-9_]*)\s*\(([^)]*)\)/i', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$parametry = trim( $trafienie[2] );

						if ( '' === $parametry ) {
							$callbacki[ $branch ][ $trafienie[1] ] = 0;
							continue;
						}

						$wszystkie = explode( ',', $parametry );
						$wymagane  = 0;

						foreach ( $wszystkie as $parametr ) {
							if ( false === strpos( $parametr, '=' ) ) {
								++$wymagane;
							}
						}

						$callbacki[ $branch ][ $trafienie[1] ] = $wymagane;
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'emisje'    => $emisje,
				'nasluchy'  => $nasluchy,
				'callbacki' => $callbacki,
				'cronowe'   => $cronowe,
			)
		);
	}
}

/**
 * K1.6 „obie-strony-umowy-zgodne".
 */
final class MP_AU_K16_Kontrakty_Hakow extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();
		$emisje    = (array) ( $od_agenta->dane['emisje'] ?? array() );
		$nasluchy  = (array) ( $od_agenta->dane['nasluchy'] ?? array() );
		$callbacki = (array) ( $od_agenta->dane['callbacki'] ?? array() );

		$cronowe = (array) ( $od_agenta->dane['cronowe'] ?? array() );

		foreach ( $nasluchy as $hak => $lista ) {
			if ( ! isset( $emisje[ $hak ] ) && ! isset( $cronowe[ $hak ] ) ) {
				$przyklad = $lista[0];

				$ustalenia[] = new MP_AU_Ustalenie(
					'1.6',
					'Nasluch na zdarzenie „' . $hak . '", ktorego nikt w projekcie nie wystawia.',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => (string) $przyklad['plik'],
						'linia'      => (int) $przyklad['linia'],
						'dowod'      => 'add_action w ' . $przyklad['branch'] . ', zero do_action w calym projekcie',
						'scenariusz' => 'Ta czesc integracji nie wykona sie NIGDY. Nic o tym nie poinformuje: '
							. 'brak zdarzenia wyglada identycznie jak brak danych do przetworzenia.',
						'naprawa'    => 'Sprawdzic nazwe zdarzenia po obu stronach (literowka) albo dopiac emisje.',
					)
				);
			}
		}

		foreach ( $emisje as $hak => $lista ) {
			if ( ! isset( $nasluchy[ $hak ] ) ) {
				$przyklad = $lista[0];

				$ustalenia[] = new MP_AU_Ustalenie(
					'1.6',
					'Zdarzenie „' . $hak . '" wystawiane, ale nikt go nie slucha w projekcie.',
					MP_AU_Ustalenie::OBSERWACJA,
					array(
						'plik'       => (string) $przyklad['plik'],
						'linia'      => (int) $przyklad['linia'],
						'dowod'      => 'do_action w ' . $przyklad['branch'] . ', zero add_action',
						'scenariusz' => 'Albo to swiadomy punkt rozszerzen dla integratora (wtedy w porzadku '
							. 'i powinno byc opisane w dokumentacji), albo odbiorca zginal przy refaktorze.',
					)
				);
				continue;
			}

			$max_emisji = 0;

			foreach ( $lista as $e ) {
				$max_emisji = max( $max_emisji, (int) $e['argumenty'] );
			}

			foreach ( $nasluchy[ $hak ] as $n ) {
				// (a) subskrybent deklaruje wiecej argumentow, niz emitent wysyla.
				if ( (int) $n['przyjmuje'] > $max_emisji && $max_emisji > 0 ) {
					$ustalenia[] = new MP_AU_Ustalenie(
						'1.6',
						'Nasluch „' . $hak . '" deklaruje ' . $n['przyjmuje'] . ' argumentow, a emitent wysyla ' . $max_emisji . '.',
						MP_AU_Ustalenie::SREDNIE,
						array(
							'plik'       => (string) $n['plik'],
							'linia'      => (int) $n['linia'],
							'dowod'      => 'add_action(..., ' . $n['przyjmuje'] . ') vs do_action z ' . $max_emisji . ' argumentami',
							'scenariusz' => 'Brakujace argumenty przyjda jako null albo wywolanie skonczy sie '
								. 'ArgumentCountError — zaleznie od sygnatury. Bledu nie widac az do wywolania.',
						)
					);
				}

				// (b) metoda wymaga wiecej parametrow, niz hak jej poda.
				$wymagane = $callbacki[ $n['branch'] ][ $n['metoda'] ] ?? null;

				if ( null !== $wymagane && '' !== $n['metoda'] && $wymagane > (int) $n['przyjmuje'] ) {
					$ustalenia[] = new MP_AU_Ustalenie(
						'1.6',
						'Metoda „' . $n['metoda'] . '" wymaga ' . $wymagane . ' parametrow, a hak „' . $hak . '" poda ' . $n['przyjmuje'] . '.',
						MP_AU_Ustalenie::KRYTYCZNE,
						array(
							'plik'       => (string) $n['plik'],
							'linia'      => (int) $n['linia'],
							'dowod'      => 'accepted_args=' . $n['przyjmuje'] . ', parametrow bez wartosci domyslnej: ' . $wymagane,
							'scenariusz' => 'PHP rzuci ArgumentCountError w momencie wystawienia zdarzenia. '
								. 'Skutek: biala strona albo przerwany zapis w polowie — zaleznie od tego, '
								. 'w ktorym miejscu emitent wystawil hak.',
							'naprawa'    => 'Podniesc czwarty argument add_action() do ' . $wymagane . '.',
						)
					);
				}
			}
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Rozjazdy kontraktow hakow.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.18 */

/**
 * A1.18 „idempotencja i blokada optymistyczna".
 *
 * Trzy realne bledy tego projektu naraz: zatwierdzenie bez podbicia
 * `lock_version` (P2-K1), UPDATE bez wartownika statusu (P2-K2) i kolejka maili
 * bez atomowego przejmowania zadania (P3-S1). Wszystkie trzy wygladaja tak samo:
 * zapis, ktory nie sprawdza, czy swiat nadal jest taki, jak w chwili odczytu.
 */
final class MP_AU_A118_Idempotencja extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$bez_lock     = array();
		$bez_statusu  = array();
		$klucze_bez_unique = array();
		$unikalne     = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$surowa = $kontekst->workspace->tresc( $plik, $kontekst );
				$tresc  = MP_AU_Pomoc::kod( $surowa );
				$wzgl   = $kontekst->workspace->wzgledna( $plik );

				$zna_lock = false !== strpos( $tresc, 'lock_version' );

				// $wpdb->update( tabela, dane, warunek ) — bierzemy caly tekst wywolania.
				if ( preg_match_all( '/\$wpdb->update\s*\((.{0,900}?)\)\s*;/s', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$linia = MP_AU_Pomoc::linia( $tresc, $trafienie[0] );

						if ( MP_AU_Pomoc::wyciszone( $surowa, $linia ) ) {
							continue;
						}

						// Sztuczka na jednoznacznosc: „status" wystepujacy RAZ
						// znaczy, ze jest tylko w danych, a nie w warunku. Dwa
						// wystapienia to komplet: ustawiam i sprawdzam.
						$ile_status = preg_match_all( '/[\'"]status[\'"]/', $trafienie[1] );

						if ( 1 === $ile_status ) {
							$bez_statusu[] = array(
								'plik'     => $wzgl,
								'linia'    => $linia,
								'fragment' => MP_AU_Pomoc::skrot( $trafienie[0], 200 ),
								'rodzaj'   => 'wpdb-update',
							);
						}

						if ( $zna_lock && false === strpos( $trafienie[1], 'lock_version' ) && $ile_status > 0 ) {
							$bez_lock[] = array(
								'plik'     => $wzgl,
								'linia'    => $linia,
								'fragment' => MP_AU_Pomoc::skrot( $trafienie[0], 200 ),
							);
						}
					}
				}

				// Surowy UPDATE ... SET status ... bez statusu w WHERE.
				if ( preg_match_all( '/UPDATE\s+[^;]{0,400}?SET\s+(.{0,300}?)WHERE\s+(.{0,200}?)[\'"]/is', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						if ( false === stripos( $trafienie[1], 'status' ) || false !== stripos( $trafienie[2], 'status' ) ) {
							continue;
						}

						$linia = MP_AU_Pomoc::linia( $tresc, $trafienie[0] );

						if ( MP_AU_Pomoc::wyciszone( $surowa, $linia ) ) {
							continue;
						}

						$bez_statusu[] = array(
							'plik'     => $wzgl,
							'linia'    => $linia,
							'fragment' => MP_AU_Pomoc::skrot( $trafienie[0], 200 ),
							'rodzaj'   => 'surowy-SQL',
						);
					}
				}

				// Kolumny idempotencji bez indeksu UNIQUE w tym samym DDL.
				if ( preg_match_all( '/CREATE TABLE\s+([^ (]{1,80})\s*\((.+?)\)\s*[^;()]{0,120};/is', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$cialo = $trafienie[2];

						foreach ( array( 'event_id', 'request_id', 'idempotency_key' ) as $kolumna ) {
							if ( false === strpos( $cialo, $kolumna ) ) {
								continue;
							}

							if ( preg_match( '/UNIQUE\s+KEY[^,]{0,80}\(\s*`?' . $kolumna . '`?/i', $cialo ) ) {
								$unikalne[ $kolumna ] = true;
								continue;
							}

							{
								$klucze_bez_unique[] = array(
									'plik'    => $wzgl,
									'linia'   => MP_AU_Pomoc::linia( $tresc, $trafienie[0] ),
									'tabela'  => trim( $trafienie[1], " `\t\n{}$" ),
									'kolumna' => $kolumna,
								);
							}
						}
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'bez_lock'          => $bez_lock,
				'bez_statusu'       => $bez_statusu,
				// Idempotencja bywa pilnowana przez OSOBNA tabele rejestru zdarzen
				// (tak jest w tym projekcie: `mp_sw_events` z UNIQUE na event_id).
				// Kolumna bez UNIQUE w tabeli roboczej nie jest wtedy bledem, tylko
				// odnosnikiem. Krytyk odsiewa te przypadki po tej liscie.
				'unikalne_gdzie_indziej' => $unikalne,
				'klucze_bez_unique' => $klucze_bez_unique,
			)
		);
	}
}

/**
 * K1.18 „zapis-sprawdza-stan-z-chwili-odczytu".
 */
final class MP_AU_K118_Idempotencja extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['bez_statusu'] ?? array() ) as $b ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.18',
				'Zapis zmienia status, ale nie sprawdza, jaki status zastal.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $b['plik'],
					'linia'      => (int) $b['linia'],
					'dowod'      => (string) $b['fragment'],
					'scenariusz' => 'Ponowny przebieg albo drugi rownolegly proces nadpisze stan, ktory '
						. 'ktos juz zmienil. W tym projekcie tak wlasnie cofala sie zatwierdzona oferta '
						. 'do wersji roboczej (P2-K2), a kolejka maili wysylala ten sam mail dwa razy (P3-S1).',
					'naprawa'    => 'Dolozyc do WHERE oczekiwany status („wartownik") i sprawdzic liczbe '
						. 'zmienionych wierszy — zero znaczy, ze ktos byl szybszy.',
				)
			);
		}

		foreach ( (array) ( $od_agenta->dane['bez_lock'] ?? array() ) as $b ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'1.18',
				'Aktualizacja rekordu z `lock_version`, ktora tej wersji nie podbija.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $b['plik'],
					'linia'      => (int) $b['linia'],
					'dowod'      => (string) $b['fragment'],
					'scenariusz' => 'Blokada optymistyczna przestaje dzialac po cichu: kolejny zapis oparty '
						. 'na starej wersji zostanie przyjety. Dokladnie blad P2-K1 — dwoch handlowcow '
						. 'zatwierdzalo te sama oferte i nikt nie dostawal odmowy.',
					'naprawa'    => "Dopisac 'lock_version' => (int) \$rekord['lock_version'] + 1.",
				)
			);
		}

		$unikalne = (array) ( $od_agenta->dane['unikalne_gdzie_indziej'] ?? array() );

		foreach ( (array) ( $od_agenta->dane['klucze_bez_unique'] ?? array() ) as $k ) {
			if ( isset( $unikalne[ $k['kolumna'] ] ) ) {
				continue;
			}

			$ustalenia[] = new MP_AU_Ustalenie(
				'1.18',
				'Kolumna „' . $k['kolumna'] . '" w tabeli „' . $k['tabela'] . '" bez indeksu UNIQUE.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $k['plik'],
					'linia'      => (int) $k['linia'],
					'dowod'      => 'W tym samym CREATE TABLE nie ma UNIQUE KEY na tej kolumnie.',
					'scenariusz' => 'Idempotencja oparta na tej kolumnie jest pozorna. Przy dwoch rownoleglych '
						. 'zadaniach oba sprawdza „czy juz jest", oba dostana „nie" i oba zapisza.',
					'naprawa'    => 'Dodac UNIQUE KEY; baza rozstrzygnie wyscig, ktorego kod rozstrzygnac nie moze.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Zapisy bez ochrony przed wyscigiem.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.21 */

/**
 * A1.21 „cykl zycia wtyczki" — instalacja, deaktywacja, odinstalowanie.
 *
 * Deaktywacja jest najgrozniejszym momentem zycia wtyczki, bo wykonuje sie
 * rzadko i nikt jej nie testuje. W tym projekcie kasowala role wspoldzielona
 * z dwiema pozostalymi wtyczkami (P1-K2): wylaczenie jednej wtyczki odbieralo
 * handlowcom uprawnienia nadane przez dwie inne.
 */
final class MP_AU_A121_Cykl_Zycia extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$fakty = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			$stan = array(
				'aktywacja'     => false,
				'deaktywacja'   => false,
				'odinstalowanie' => false,
				'dbdelta'       => false,
				'tworzy_tabele' => false,
				'harmonogram'   => array(),
				'czyszczenie'   => array(),
				'remove_role'   => array(),
				'drop_table'    => array(),
			);

			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$surowa = $kontekst->workspace->tresc( $plik, $kontekst );
				$tresc  = MP_AU_Pomoc::kod( $surowa );
				$wzgl   = $kontekst->workspace->wzgledna( $plik );

				$stan['aktywacja']      = $stan['aktywacja'] || false !== strpos( $tresc, 'register_activation_hook' );
				$stan['deaktywacja']    = $stan['deaktywacja'] || false !== strpos( $tresc, 'register_deactivation_hook' );
				$stan['odinstalowanie'] = $stan['odinstalowanie']
					|| false !== strpos( $tresc, 'register_uninstall_hook' )
					|| 'uninstall.php' === basename( $plik );
				$stan['dbdelta']        = $stan['dbdelta'] || false !== strpos( $tresc, 'dbDelta' );
				$stan['tworzy_tabele']  = $stan['tworzy_tabele'] || false !== stripos( $tresc, 'CREATE TABLE' );

				foreach ( array( 'wp_schedule_event' => 'harmonogram', 'wp_schedule_single_event' => 'harmonogram' ) as $funkcja => $kubelek ) {
					if ( preg_match_all( '/' . $funkcja . '\s*\([^;]{0,200}?[\'"]([a-z0-9_]+)[\'"]/i', $tresc, $t, PREG_SET_ORDER ) ) {
						foreach ( $t as $trafienie ) {
							$stan[ $kubelek ][] = $trafienie[1];
						}
					}
				}

				if ( preg_match_all( '/wp_(?:clear_scheduled_hook|unschedule_event|unschedule_hook)\s*\([^;]{0,200}?[\'"]([a-z0-9_]+)[\'"]/i', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$stan['czyszczenie'][] = $trafienie[1];
					}
				}

				if ( preg_match_all( '/remove_role\s*\(/', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[0] as $trafienie ) {
						$linia = MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] );

						// Czy w poblizu jest sprawdzenie, ze rola nie ma juz zadnych
						// uprawnien? Taka ostroznosc to wlasnie naprawa P1-K2.
						$okolica = substr( $tresc, max( 0, (int) $trafienie[1] - 900 ), 900 );

						$stan['remove_role'][] = array(
							'plik'      => $wzgl,
							'linia'     => $linia,
							'ostrozne'  => (bool) preg_match( '/capabilities|remove_cap|empty\s*\(/i', $okolica ),
						);
					}
				}

				if ( preg_match_all( '/DROP\s+TABLE/i', $tresc, $t, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $t[0] as $trafienie ) {
						$stan['drop_table'][] = array(
							'plik'  => $wzgl,
							'linia' => MP_AU_Pomoc::linia_offsetu( $tresc, (int) $trafienie[1] ),
							'przy_deaktywacji' => false !== strpos( $tresc, 'register_deactivation_hook' ),
						);
					}
				}
			}

			$fakty[ $branch ] = $stan;
		}

		return MP_AU_Wynik::ok( array( 'fakty' => $fakty ) );
	}
}

/**
 * K1.21 „wylaczenie-nie-niszczy-sasiada".
 */
final class MP_AU_K121_Cykl_Zycia extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();

		foreach ( (array) ( $od_agenta->dane['fakty'] ?? array() ) as $branch => $stan ) {
			foreach ( (array) $stan['remove_role'] as $r ) {
				if ( $r['ostrozne'] ) {
					continue;
				}

				$ustalenia[] = new MP_AU_Ustalenie(
					'1.21',
					'`remove_role()` bez sprawdzenia, czy rola jest jeszcze uzywana przez inna wtyczke.',
					MP_AU_Ustalenie::KRYTYCZNE,
					array(
						'plik'       => (string) $r['plik'],
						'linia'      => (int) $r['linia'],
						'dowod'      => 'W okolicy wywolania brak odwolania do capabilities/remove_cap.',
						'scenariusz' => 'Rola jest zasobem WSPOLNYM calej instalacji WordPressa. Wylaczenie '
							. 'tej jednej wtyczki odbierze uprawnienia nadane przez dwie pozostale — '
							. 'dokladnie blad P1-K2. Uzytkownik traci dostep do funkcji, ktorych ta '
							. 'wtyczka nigdy nie dodawala.',
						'naprawa'    => 'Zdjac WLASNE uprawnienia, a role usuwac tylko, gdy zostala pusta.',
					)
				);
			}

			foreach ( (array) $stan['drop_table'] as $d ) {
				if ( ! $d['przy_deaktywacji'] ) {
					continue;
				}

				$ustalenia[] = new MP_AU_Ustalenie(
					'1.21',
					'`DROP TABLE` w pliku obslugujacym deaktywacje.',
					MP_AU_Ustalenie::KRYTYCZNE,
					array(
						'plik'       => (string) $d['plik'],
						'linia'      => (int) $d['linia'],
						'dowod'      => 'DROP TABLE w tym samym pliku co register_deactivation_hook.',
						'scenariusz' => 'Deaktywacja to nie odinstalowanie. Administrator wylaczajacy wtyczke '
							. 'na czas diagnostyki traci wszystkie dane bezpowrotnie.',
						'naprawa'    => 'Kasowanie danych wylacznie w `uninstall.php`.',
					)
				);
			}

			$nieposprzatane = array_diff( array_unique( (array) $stan['harmonogram'] ), (array) $stan['czyszczenie'] );

			foreach ( $nieposprzatane as $zadanie ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.21',
					'Zadanie cron „' . $zadanie . '" planowane, ale nigdzie nieusuwane.',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => $branch,
						'dowod'      => 'wp_schedule_event bez odpowiadajacego wp_clear_scheduled_hook.',
						'scenariusz' => 'Po wylaczeniu wtyczki WordPress dalej probuje odpalac zadanie, '
							. 'ktorego obslugi juz nie ma. W logach rosnie smiec, a przy ponownej '
							. 'aktywacji moga powstac duplikaty harmonogramu.',
						'naprawa'    => 'wp_clear_scheduled_hook( „' . $zadanie . '" ) przy deaktywacji.',
					)
				);
			}

			if ( $stan['tworzy_tabele'] && ! $stan['dbdelta'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.21',
					'Wtyczka „' . $branch . '" tworzy tabele bez `dbDelta()`.',
					MP_AU_Ustalenie::SREDNIE,
					array(
						'plik'       => $branch,
						'dowod'      => 'CREATE TABLE obecne, dbDelta nieobecne.',
						'scenariusz' => 'Przy aktualizacji wtyczki schemat nie zostanie zmigrowany — nowe '
							. 'kolumny nie powstana, a kod bedzie ich uzywal.',
					)
				);
			}

			if ( $stan['tworzy_tabele'] && ! $stan['odinstalowanie'] ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.21',
					'Wtyczka „' . $branch . '" tworzy tabele, ale nie ma sciezki odinstalowania.',
					MP_AU_Ustalenie::OBSERWACJA,
					array(
						'plik'       => $branch,
						'dowod'      => 'Brak uninstall.php i register_uninstall_hook.',
						'scenariusz' => 'Po usunieciu wtyczki dane osobowe zostaja w bazie. Przy zadaniu '
							. 'RODO „usuncie wszystko" nikt nie bedzie wiedzial, ze tam sa.',
					)
				);
			}
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Usterki cyklu zycia.', $ustalenia, $od_agenta->dane );
	}
}

/* ==================================================================== 1.23 */

/**
 * A1.23 „slownik statusow" — czy kod i baza mowia tymi samymi slowami.
 *
 * Status jest umowa miedzy kodem, baza i druga wtyczka. Literowka w napisie nie
 * wywala niczego: warunek po prostu nigdy nie jest prawdziwy, a rekord zostaje
 * w stanie, w ktorym nikt go nie szuka.
 */
final class MP_AU_A123_Statusy extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$slownik = array();
		$uzycia  = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			$slownik[ $branch ] = array();

			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$tresc = MP_AU_Pomoc::kod( $kontekst->workspace->tresc( $plik, $kontekst ) );

				if ( preg_match_all( '/const\s+STATUS_[A-Z0-9_]+\s*=\s*[\'"]([a-z0-9_-]+)[\'"]/', $tresc, $t ) ) {
					foreach ( $t[1] as $wartosc ) {
						$slownik[ $branch ][ $wartosc ] = true;
					}
				}
			}

			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$surowa = $kontekst->workspace->tresc( $plik, $kontekst );
				$tresc  = MP_AU_Pomoc::kod( $surowa );

				// Status podawany literalem tam, gdzie istnieje slownik stalych.
				if ( preg_match_all( '/[\'"]status[\'"]\s*=>\s*[\'"]([a-z0-9_-]+)[\'"]/', $tresc, $t, PREG_SET_ORDER ) ) {
					foreach ( $t as $trafienie ) {
						$uzycia[] = array(
							'branch'   => $branch,
							'plik'     => $kontekst->workspace->wzgledna( $plik ),
							'linia'    => MP_AU_Pomoc::linia( $tresc, $trafienie[0] ),
							'wartosc'  => $trafienie[1],
							'fragment' => MP_AU_Pomoc::skrot( $trafienie[0], 90 ),
						);
					}
				}
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'slownik' => $slownik,
				'uzycia'  => $uzycia,
			)
		);
	}
}

/**
 * K1.23 „status-ze-slownika".
 */
final class MP_AU_K123_Statusy extends MP_AU_Krytyk {

	/** Statusy nalezace do WordPressa i WooCommerce, nie do nas. */
	const WORDPRESSOWE = array( 'publish', 'private', 'future', 'trash', 'auto-draft', 'inherit', 'any', 'active', 'inactive', 'enabled', 'disabled', 'all' );

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$ustalenia = array();
		$slownik   = (array) ( $od_agenta->dane['slownik'] ?? array() );

		foreach ( (array) ( $od_agenta->dane['uzycia'] ?? array() ) as $u ) {
			$wlasny = (array) ( $slownik[ $u['branch'] ] ?? array() );

			if ( empty( $wlasny ) ) {
				// Wtyczka nie prowadzi slownika stalych — nie ma z czym porownac.
				continue;
			}

			// Statusy natywne WordPressa/WooCommerce trafiaja do argumentow
			// WP_Query i get_posts pod tym samym kluczem „status". To nie sa
			// nasze statusy i nie maja prawa byc w naszym slowniku.
			if ( in_array( $u['wartosc'], self::WORDPRESSOWE, true ) ) {
				continue;
			}

			if ( isset( $wlasny[ $u['wartosc'] ] ) ) {
				$ustalenia[] = new MP_AU_Ustalenie(
					'1.23',
					'Status „' . $u['wartosc'] . '" wpisany literalem, choc istnieje dla niego stala.',
					MP_AU_Ustalenie::DROBNE,
					array(
						'plik'       => (string) $u['plik'],
						'linia'      => (int) $u['linia'],
						'dowod'      => (string) $u['fragment'],
						'scenariusz' => 'Zmiana wartosci stalej ominie to miejsce. Rekord zostanie w stanie, '
							. 'ktorego reszta kodu juz nie rozpoznaje.',
						'naprawa'    => 'Uzyc stalej STATUS_* zamiast napisu.',
					)
				);
				continue;
			}

			$ustalenia[] = new MP_AU_Ustalenie(
				'1.23',
				'Status „' . $u['wartosc'] . '" spoza slownika stalych tej wtyczki.',
				MP_AU_Ustalenie::SREDNIE,
				array(
					'plik'       => (string) $u['plik'],
					'linia'      => (int) $u['linia'],
					'dowod'      => $u['fragment'] . ' — slownik: ' . implode( ', ', array_keys( $wlasny ) ),
					'scenariusz' => 'Albo literowka, albo status wprowadzony bez uzupelnienia slownika. '
						. 'W obu przypadkach warunki szukajace znanych statusow ten rekord POMINA, '
						. 'a on sam nie zglosi zadnego bledu — po prostu zniknie z obiegu.',
				)
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Rozjazd slownika statusow.', $ustalenia, $od_agenta->dane );
	}
}
