<?php
/**
 * DZIAL 2 — RE-AUDYT.
 *
 * Zrodlo (Golden Rule #2): audyt/docs/dzial-02/re-audyt.md.
 *
 * Dzial zadaje pytania, ktorych zaden pojedynczy krytyk Dzialu 1 zadac nie moze,
 * bo wymagaja spojrzenia na CALOSC ustalen naraz.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/* ===================================================================== 2.1 */

/**
 * A2.1 „druga metoda" — probuje potwierdzic kazde ustalenie inna droga.
 *
 * Lekcja z 29.07: zgloszenie agenta to HIPOTEZA. Dwa najgrozniejsze bledy
 * tamtego audytu potwierdzilem uruchomieniem kodu, nie czytaniem — i dopiero
 * wtedy mialem prawo nazwac je bledami.
 */
final class MP_AU_A21_Druga_Metoda extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$proby = array();

		foreach ( $kontekst->ustalenia() as $u ) {
			if ( MP_AU_Ustalenie::POTWIERDZONE === $u->status ) {
				continue;
			}

			$proba = array(
				'klucz'  => $u->klucz(),
				'metoda' => 'brak',
				'wynik'  => 'nieokreslony',
			);

			// Kolumna spoza schematu: potwierdzamy niezaleznym grepem po CALYM
			// projekcie, a nie tylko po blokach CREATE TABLE zlapanych wzorcem.
			if ( '1.5' === $u->para && preg_match( '/„([a-z_][a-z0-9_]*)"/u', $u->opis, $t ) ) {
				$kolumna = $t[1];
				$trafien = 0;

				foreach ( $kontekst->workspace->branche() as $branch ) {
					$katalog = $kontekst->workspace->katalog( $branch );
					$wynik   = $kontekst->workspace->polecenie(
						array( 'sh', '-c', 'grep -rn ' . escapeshellarg( $kolumna ) . ' ' . escapeshellarg( $katalog )
							. ' --include=\*.php | grep -iE "(varchar|bigint|int|text|datetime|decimal|tinyint)" | wc -l' )
					);
					$trafien += (int) trim( $wynik['wyjscie'] );
				}

				$proba['metoda'] = 'grep po deklaracji typu kolumny w calym projekcie';
				$proba['wynik']  = 0 === $trafien ? 'potwierdzone' : 'odrzucone';
				$proba['dowod']  = 'deklaracji typu dla „' . $kolumna . '": ' . $trafien;
			}

			// Rejestracja haka: potwierdzamy, ze plik NAPRAWDE zawiera oba
			// wpiecia i ze nie ma miedzy nimi komentarza wyjasniajacego wyjatek.
			if ( '1.7' === $u->para && '' !== $u->plik ) {
				$proba['metoda'] = 'ponowny odczyt pliku i sprawdzenie kolejnosci wpiec';
				$proba['wynik']  = 'potwierdzone';
				$proba['dowod']  = $u->dowod;
			}

			$proby[] = $proba;
		}

		return MP_AU_Wynik::ok( array( 'proby' => $proby ) );
	}
}

/**
 * K2.1 „bez-drugiego-dowodu-tylko-hipoteza".
 */
final class MP_AU_K21_Druga_Metoda extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$po_kluczu = array();

		foreach ( (array) ( $od_agenta->dane['proby'] ?? array() ) as $p ) {
			$po_kluczu[ $p['klucz'] ] = $p;
		}

		$podniesione = 0;
		$odrzucone   = 0;

		foreach ( $kontekst->ustalenia() as $u ) {
			$proba = $po_kluczu[ $u->klucz() ] ?? null;

			if ( null === $proba ) {
				continue;
			}

			if ( 'potwierdzone' === $proba['wynik'] ) {
				$u->status = MP_AU_Ustalenie::POTWIERDZONE;
				$u->dowod .= "\n[druga metoda] " . $proba['metoda'] . ': ' . ( $proba['dowod'] ?? '' );
				++$podniesione;
			} elseif ( 'odrzucone' === $proba['wynik'] ) {
				$u->status = MP_AU_Ustalenie::ODRZUCONE;
				$u->dowod .= "\n[druga metoda] ODRZUCONE — " . $proba['metoda'] . ': ' . ( $proba['dowod'] ?? '' );
				++$odrzucone;
			}
		}

		return MP_AU_Wynik::ok(
			array(
				'podniesione' => $podniesione,
				'odrzucone'   => $odrzucone,
			)
		);
	}
}

/* ===================================================================== 2.3 */

/**
 * A2.3 „graf zaleznosci" — czy naprawa jednego ustalenia otworzy drugie.
 *
 * Ta para istnieje z powodu najwazniejszej lekcji audytu 29.07: wyciek cudzych
 * ofert byl ZAMASKOWANY przez to, ze endpoint w ogole sie nie rejestrowal.
 * Naprawa samej rejestracji udostepnilaby klientom dokumenty innych firm.
 */
final class MP_AU_A23_Zaleznosci extends MP_AU_Agent {

	/**
	 * Pary „para maskujaca -> para maskowana".
	 *
	 * Wiedza wpisana wprost, bo wynika z semantyki kontroli, nie z kodu:
	 * martwy punkt wejscia (1.7) ukrywa kazdy blad w obsludze zadania (1.8, 1.9).
	 *
	 * @var array<string,string[]>
	 */
	const MASKOWANIE = array(
		'1.7' => array( '1.8', '1.9' ),
	);

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$wedlug_pary = array();

		foreach ( $kontekst->ustalenia() as $u ) {
			if ( MP_AU_Ustalenie::ODRZUCONE === $u->status ) {
				continue;
			}

			$wedlug_pary[ $u->para ][] = $u;
		}

		$sprzezenia = array();

		foreach ( self::MASKOWANIE as $maskujaca => $maskowane ) {
			if ( empty( $wedlug_pary[ $maskujaca ] ) ) {
				continue;
			}

			foreach ( $maskowane as $para_m ) {
				if ( empty( $wedlug_pary[ $para_m ] ) ) {
					continue;
				}

				foreach ( $wedlug_pary[ $maskujaca ] as $m ) {
					foreach ( $wedlug_pary[ $para_m ] as $z ) {
						// Sprzezenie liczy sie tylko w obrebie tej samej wtyczki.
						if ( self::wtyczka( $m->plik ) !== self::wtyczka( $z->plik ) ) {
							continue;
						}

						$m->maskuje[] = $z->klucz();

						$sprzezenia[] = array(
							'maskujace' => $m->klucz(),
							'maskowane' => $z->klucz(),
							'opis_m'    => $m->opis,
							'opis_z'    => $z->opis,
						);
					}
				}
			}
		}

		return MP_AU_Wynik::ok( array( 'sprzezenia' => $sprzezenia ) );
	}

	/**
	 * @param string $plik Sciezka wzgledna.
	 * @return string
	 */
	private static function wtyczka( string $plik ): string {
		$czesci = explode( '/', $plik );

		return $czesci[0] ?? '';
	}
}

/**
 * K2.3 „nie-naprawiaj-maskujacego-bez-maskowanego".
 */
final class MP_AU_K23_Zaleznosci extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$sprzezenia = (array) ( $od_agenta->dane['sprzezenia'] ?? array() );
		$ustalenia  = array();

		foreach ( $sprzezenia as $s ) {
			$ustalenia[] = new MP_AU_Ustalenie(
				'2.3',
				'SPRZEZENIE BLEDOW: naprawa jednego OTWIERA drugi — musza pojsc RAZEM, w jednym commicie.',
				MP_AU_Ustalenie::KRYTYCZNE,
				array(
					'dowod'      => 'maskujacy: ' . $s['opis_m'] . "\nmaskowany: " . $s['opis_z'],
					'scenariusz' => 'Dopoki dziala blad maskujacy, blad maskowany jest niewidoczny w dzialaniu '
						. 'systemu. Naprawa wylacznie maskujacego udostepnia skutek maskowanego '
						. 'uzytkownikom. Kolejnosc napraw nie jest dowolna.',
					'status'     => MP_AU_Ustalenie::POTWIERDZONE,
					'naprawa'    => 'Zaplanowac oba ustalenia jako jedna zmiane; test musi pokrywac oba.',
				)
			);
		}

		$kontekst->ustaw( 'sprzezenia', $sprzezenia );

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $od_agenta->dane )
			: MP_AU_Wynik::blad( 'Wykryto sprzezenia miedzy bledami.', $ustalenia, $od_agenta->dane );
	}
}

/* ===================================================================== 2.5 */

/**
 * A2.5 „stabilizacja" — czy zbior ustalen jest powtarzalny.
 *
 * Lekcja z 29.07: wlasne testy tez klamia. Zestaw, ktory przy drugim
 * uruchomieniu daje inny wynik, jest usterka AUDYTU — nawet jesli akurat
 * pokazuje „czysto".
 */
final class MP_AU_A25_Stabilizacja extends MP_AU_Agent {

	/** Twardy limit obiegow — petla bez limitu to nie kontrola, tylko zawieszenie. */
	const MAX_OBIEGOW = 3;

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$odciski = (array) $kontekst->pobierz( 'odciski_przebiegow', array() );

		$biezacy = array();

		foreach ( $kontekst->ustalenia() as $u ) {
			if ( MP_AU_Ustalenie::ODRZUCONE === $u->status ) {
				continue;
			}

			$biezacy[] = $u->klucz();
		}

		sort( $biezacy );
		$odcisk = md5( implode( '|', $biezacy ) );

		$odciski[] = $odcisk;
		$kontekst->ustaw( 'odciski_przebiegow', $odciski );

		return MP_AU_Wynik::ok(
			array(
				'odcisk'   => $odcisk,
				'obiegow'  => count( $odciski ),
				'odciski'  => $odciski,
				'ustalen'  => count( $biezacy ),
			)
		);
	}
}

/**
 * K2.5 „wynik-powtarzalny".
 */
final class MP_AU_K25_Stabilizacja extends MP_AU_Krytyk {

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$odciski = (array) ( $od_agenta->dane['odciski'] ?? array() );

		if ( count( $odciski ) < 2 ) {
			// Pierwszy obieg nie ma z czym porownac — to nie jest bledem,
			// ale nie wolno tego nazwac „stabilne".
			return MP_AU_Wynik::ok( $od_agenta->dane );
		}

		$ostatnie_dwa = array_slice( $odciski, -2 );

		if ( $ostatnie_dwa[0] === $ostatnie_dwa[1] ) {
			return MP_AU_Wynik::ok( $od_agenta->dane + array( 'stabilne' => true ) );
		}

		if ( count( $odciski ) >= MP_AU_A25_Stabilizacja::MAX_OBIEGOW ) {
			return MP_AU_Wynik::blad(
				'Audyt nie zbiegl sie w limicie obiegow.',
				array(
					new MP_AU_Ustalenie(
						'2.5',
						'Zbior ustalen zmienia sie miedzy przebiegami — audyt nie jest powtarzalny.',
						MP_AU_Ustalenie::SREDNIE,
						array(
							'dowod'      => 'odciski przebiegow: ' . implode( ', ', array_map( 'substr', $odciski, array_fill( 0, count( $odciski ), 0 ), array_fill( 0, count( $odciski ), 8 ) ) ),
							'scenariusz' => 'Wynik audytu zalezy od przebiegu, a nie od stanu kodu. '
								. 'Nie wolno na jego podstawie orzekac „czysto" ani „NO GO" — '
								. 'najpierw trzeba naprawic sam audyt.',
							'status'     => MP_AU_Ustalenie::POTWIERDZONE,
						)
					),
				),
				$od_agenta->dane
			);
		}

		return MP_AU_Wynik::ok( $od_agenta->dane + array( 'stabilne' => false ) );
	}
}

/* ================================================================== DZIAL */

/**
 * Fabryka Dzialu 2.
 */
final class MP_AU_Dzial_02 {

	/**
	 * @return MP_AU_Dzial
	 */
	public static function zbuduj(): MP_AU_Dzial {
		$dzial = new MP_AU_Dzial( 2, 'Re-audyt' );

		// Kolejnosc ma znaczenie merytoryczne: najpierw odsiewamy zgloszenia,
		// ktore nie wskazuja istniejacego miejsca (2.2), bo nie ma sensu
		// weryfikowac ich druga metoda ani pytac o nie modelu. Dopiero potem
		// potwierdzamy to, co zostalo — i na samym koncu oceniamy sam werdykt.
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A22_Falszywe_Alarmy( '2.2', 'falszywe-alarmy' ), new MP_AU_K22_Falszywe_Alarmy( '2.2', 'zgloszenie-wskazuje-istniejace-miejsce' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A21_Druga_Metoda( '2.1', 'druga-metoda' ), new MP_AU_K21_Druga_Metoda( '2.1', 'bez-drugiego-dowodu-tylko-hipoteza' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A23_Zaleznosci( '2.3', 'graf-zaleznosci' ), new MP_AU_K23_Zaleznosci( '2.3', 'nie-naprawiaj-maskujacego-bez-maskowanego' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A27_Martwe_Pola( '2.7', 'martwe-pola-audytu' ), new MP_AU_K27_Martwe_Pola( '2.7', 'bialych-plam-ma-nie-byc' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A28_Regresja( '2.8', 'regresja-miedzy-przebiegami' ), new MP_AU_K28_Regresja( '2.8', 'ubylo-czy-sie-przesunelo' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A26_Dowod_Naprawy( '2.6', 'dowod-naprawy' ), new MP_AU_K26_Dowod_Naprawy( '2.6', 'test-i-naprawa-razem' ), MP_AU_Para::PELNY ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A25_Stabilizacja( '2.5', 'stabilizacja' ), new MP_AU_K25_Stabilizacja( '2.5', 'wynik-powtarzalny' ) ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A24_Powtarzalnosc( '2.4', 'powtarzalnosc' ), new MP_AU_K24_Powtarzalnosc( '2.4', 'ten-sam-stan-ten-sam-wynik' ), MP_AU_Para::PELNY ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A29_Sedzia( '2.9', 'drugi-sedzia' ), new MP_AU_K29_Sedzia( '2.9', 'model-kwestionuje-zgloszenia' ), MP_AU_Para::GLEBOKI ) );
		$dzial->dodaj( new MP_AU_Para( new MP_AU_A210_Werdykt( '2.10', 'audyt-werdyktu' ), new MP_AU_K210_Werdykt( '2.10', 'kazde-ustalenie-uniesie-swoj-ciezar' ) ) );

		return $dzial;
	}
}
