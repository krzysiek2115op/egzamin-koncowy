<?php
/**
 * Dzial 1, grupa MODEL: pary 1.25, 1.26.
 *
 * Tu konczy sie to, co da sie zapisac regula. Zdania „ta cena jest liczona drugi
 * raz", „ten status klamie o tym, co sie stalo", „klient zobaczy pusty numer
 * oferty" wymagaja SADU, nie wzorca. Agent zbiera dossier w PHP, model wydaje
 * werdykt, a Dzial 2 ma prawo ten werdykt odrzucic.
 *
 * To rowniez tutaj przebieg zaczyna trwac naprawde dlugo — kilkadziesiat pytan
 * po kilkadziesiat sekund kazde. To jest ta czesc audytu, ktorej nie da sie
 * przyspieszyc bez utraty tego, po co istnieje.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/**
 * Krytyk zadajacy modelowi WIELE pytan — po jednym na dossier.
 *
 * Jedno wielkie pytanie o caly projekt daje jedna ogolnikowa odpowiedz. Dossier
 * na plik daje odpowiedzi, ktore wskazuja linie. Roznica jest taka sama jak
 * miedzy „kod wyglada ok" a „w linii 214 cena jest doliczana drugi raz".
 */
abstract class MP_AU_Krytyk_Modelowy_Seryjny extends MP_AU_Krytyk {

	/** Ile najwyzej pytan zada ten krytyk w jednym przebiegu. */
	const LIMIT_PYTAN = 40;

	/**
	 * Domyslna waga ustalen tego krytyka, gdy model jej nie poda.
	 *
	 * @return string
	 */
	protected function waga_domyslna(): string {
		return MP_AU_Ustalenie::SREDNIE;
	}

	/**
	 * @param MP_AU_Wynik    $od_agenta Wynik agenta.
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function ocen( MP_AU_Wynik $od_agenta, MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		if ( ! $kontekst->model->dostepny() ) {
			return MP_AU_Wynik::nieocenione(
				'Krytyk ' . $this->nazwa . ' wymaga oceny modelu: ' . $kontekst->model->powod_niedostepnosci()
			);
		}

		$dossiery = (array) ( $od_agenta->dane['dossiery'] ?? array() );

		if ( empty( $dossiery ) ) {
			return MP_AU_Wynik::nieocenione( 'Agent ' . $this->para . ' nie zebral zadnego dossier do oceny.' );
		}

		$limit = (int) $kontekst->pobierz( 'limit_modelu', self::LIMIT_PYTAN );
		$ustalenia = array();
		$zadane    = 0;
		$bez_odpowiedzi = 0;

		foreach ( $dossiery as $dossier ) {
			if ( $zadane >= $limit ) {
				break;
			}

			++$zadane;
			$odpowiedz = $kontekst->model->zapytaj( $this->pytanie( $dossier ) );

			if ( null === $odpowiedz ) {
				++$bez_odpowiedzi;
				continue;
			}

			foreach ( (array) ( $odpowiedz['ustalenia'] ?? array() ) as $u ) {
				if ( empty( $u['opis'] ) || empty( $u['scenariusz'] ) ) {
					// Zasada z obudowy pytania: ustalenie bez scenariusza awarii
					// nie jest ustaleniem. Odrzucamy je po naszej stronie, zeby
					// nie polegac na tym, ze model sie do zasady zastosowal.
					continue;
				}

				$ustalenia[] = new MP_AU_Ustalenie(
					$this->para,
					(string) $u['opis'],
					(string) ( $u['waga'] ?? $this->waga_domyslna() ),
					array(
						'plik'       => (string) ( $u['plik'] ?? ( $dossier['plik'] ?? '' ) ),
						'linia'      => (int) ( $u['linia'] ?? 0 ),
						'dowod'      => (string) ( $u['dowod'] ?? '' ),
						'scenariusz' => (string) $u['scenariusz'],
						'status'     => MP_AU_Ustalenie::PRAWDOPODOBNE,
						'naprawa'    => (string) ( $u['naprawa'] ?? '' ),
					)
				);
			}
		}

		$dane = $od_agenta->dane;
		$dane['pytan_zadanych']  = $zadane;
		$dane['bez_odpowiedzi']  = $bez_odpowiedzi;
		$dane['dossierow_lacznie'] = count( $dossiery );

		// Cisza modelu przy WSZYSTKICH pytaniach to nie jest wynik „czysto".
		if ( $zadane > 0 && $bez_odpowiedzi === $zadane ) {
			return MP_AU_Wynik::nieocenione(
				'Model nie odpowiedzial na zadne z ' . $zadane . ' pytan pary ' . $this->para . '.'
			);
		}

		return empty( $ustalenia )
			? MP_AU_Wynik::ok( $dane )
			: MP_AU_Wynik::blad( 'Model zglosil ' . count( $ustalenia ) . ' ustalen.', $ustalenia, $dane );
	}

	/**
	 * Pytanie na podstawie jednego dossier.
	 *
	 * @param array $dossier Dossier.
	 * @return string
	 */
	abstract protected function pytanie( array $dossier ): string;
}

/* ==================================================================== 1.25 */

/**
 * A1.25 „semantyka dzialow pipeline'u".
 *
 * Bierze po kolei kazdy dzial kazdej z trzech wtyczek i przygotowuje dossier:
 * kod dzialu plus informacja, co ten dzial ma robic wg wlasnej dokumentacji.
 * Pytanie do modelu brzmi zawsze tak samo: czy kod robi to, co deklaruje.
 */
final class MP_AU_A125_Semantyka extends MP_AU_Agent {

	/** Gorny limit znakow jednego dossier — dluzsze i tak nie poprawia oceny. */
	const LIMIT_ZNAKOW = 14000;

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$dossiery = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			$katalog = $kontekst->workspace->katalog( $branch );

			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				if ( false === strpos( $plik, '/departments/' ) ) {
					continue;
				}

				$kod = $kontekst->workspace->tresc( $plik, $kontekst );

				if ( '' === $kod ) {
					continue;
				}

				$numer = preg_match( '/-(\d{2})\.php$/', $plik, $t ) ? (int) $t[1] : 0;
				$opis  = '';

				// Dokumentacja dzialu — to ona mowi, CO dzial ma robic. Bez niej
				// model ocenialby kod wzgledem wlasnych wyobrazen.
				foreach ( glob( $katalog . '/docs/dzial-' . str_pad( (string) $numer, 2, '0', STR_PAD_LEFT ) . '/*.md' ) ?: array() as $md ) {
					$opis .= substr( $kontekst->workspace->tresc( $md, $kontekst ), 0, 6000 );
				}

				$dossiery[] = array(
					'etykieta' => $branch . ' / dzial ' . $numer,
					'plik'     => $kontekst->workspace->wzgledna( $plik ),
					'kod'      => substr( $kod, 0, self::LIMIT_ZNAKOW ),
					'opis'     => $opis,
				);
			}
		}

		/*
		 * Budzet pytan jest skonczony, wiec KOLEJNOSC decyduje o tym, co zostanie
		 * ocenione. Sortujemy malejaco wg ryzyka: pieniadze, zapisy do bazy,
		 * zdarzenia miedzy wtyczkami i wysylka do klienta. Sortowanie jest
		 * deterministyczne (przy remisie decyduje sciezka), bo para 2.4 sprawdza
		 * powtarzalnosc calego Dzialu 1 i losowa kolejnosc psulaby ten pomiar.
		 */
		usort(
			$dossiery,
			static function ( array $a, array $b ) {
				$roznica = self::ryzyko( $b['kod'] ) <=> self::ryzyko( $a['kod'] );

				return 0 !== $roznica ? $roznica : strcmp( $a['plik'], $b['plik'] );
			}
		);

		return MP_AU_Wynik::ok( array( 'dossiery' => $dossiery ) );
	}

	/**
	 * Zgrubna miara ryzyka dzialu — im wyzsza, tym wczesniej pytamy o niego model.
	 *
	 * @param string $kod Kod dzialu.
	 * @return int
	 */
	private static function ryzyko( string $kod ): int {
		$wagi = array(
			'/wc_get_price|get_price|prices_include_tax|vat|netto|brutto/i' => 5,
			'/\$wpdb->(?:update|insert|delete|query)/'                      => 3,
			'/do_action|apply_filters/'                                     => 2,
			'/wp_mail|notification|recipient/i'                             => 2,
			'/lock_version|status|transaction/i'                            => 1,
		);

		$suma = 0;

		foreach ( $wagi as $wzorzec => $waga ) {
			$suma += $waga * preg_match_all( $wzorzec, $kod );
		}

		return $suma;
	}
}

/**
 * K1.25 „kod-robi-to-co-deklaruje".
 */
final class MP_AU_K125_Semantyka extends MP_AU_Krytyk_Modelowy_Seryjny {

	/**
	 * @param array $dossier Dossier.
	 * @return string
	 */
	protected function pytanie( array $dossier ): string {
		return "OBSZAR: " . $dossier['etykieta'] . "\nPLIK: " . $dossier['plik'] . "\n\n"
			. "Pytanie: czy ten dzial robi to, co deklaruje jego dokumentacja, i czy jego wynik "
			. "jest prawdziwy? Szukaj w szczegolnosci:\n"
			. "- wartosci liczonej drugi raz albo w zlej jednostce,\n"
			. "- statusu, ktory nie odpowiada temu, co naprawde sie stalo,\n"
			. "- galezi, ktora konczy sie sukcesem mimo nieudanej operacji,\n"
			. "- warunku, ktory nigdy nie bedzie prawdziwy,\n"
			. "- danych przekazywanych dalej niekompletnie.\n\n"
			. ( '' === $dossier['opis'] ? "(Dokumentacja dzialu niedostepna — oceniaj sam kod.)\n" : "=== DOKUMENTACJA DZIALU ===\n" . $dossier['opis'] . "\n" )
			. "\n=== KOD ===\n" . $dossier['kod'];
	}
}

/* ==================================================================== 1.26 */

/**
 * A1.26 „to, co widzi czlowiek".
 *
 * Zbiera miejsca, w ktorych system mowi do uzytkownika albo do klienta: tematy
 * i tresci maili, komunikaty bledow, etykiety w panelu. Blad P3-K3 byl dokladnie
 * z tej rodziny — do klienta szedl mail z PUSTYM numerem oferty w temacie.
 * Technicznie wszystko dzialalo.
 */
final class MP_AU_A126_Komunikaty extends MP_AU_Agent {

	/**
	 * @param MP_AU_Kontekst $kontekst Kontekst.
	 * @return MP_AU_Wynik
	 */
	public function zbierz( MP_AU_Kontekst $kontekst ): MP_AU_Wynik {
		$dossiery = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, true ) as $plik ) {
				$kod = $kontekst->workspace->tresc( $plik, $kontekst );

				$mowi_do_ludzi = preg_match( '/wp_mail|subject|admin_notice|WP_Error|sprintf\s*\(\s*(?:__|esc_html__)/i', $kod );

				if ( ! $mowi_do_ludzi ) {
					continue;
				}

				$dossiery[] = array(
					'etykieta' => $branch . ' / ' . basename( $plik ),
					'plik'     => $kontekst->workspace->wzgledna( $plik ),
					'kod'      => substr( $kod, 0, 12000 ),
				);
			}
		}

		return MP_AU_Wynik::ok( array( 'dossiery' => $dossiery ) );
	}
}

/**
 * K1.26 „klient-nie-zobaczy-pustego-miejsca".
 */
final class MP_AU_K126_Komunikaty extends MP_AU_Krytyk_Modelowy_Seryjny {

	/**
	 * @return string
	 */
	protected function waga_domyslna(): string {
		return MP_AU_Ustalenie::DROBNE;
	}

	/**
	 * @param array $dossier Dossier.
	 * @return string
	 */
	protected function pytanie( array $dossier ): string {
		return "OBSZAR: " . $dossier['etykieta'] . "\nPLIK: " . $dossier['plik'] . "\n\n"
			. "Pytanie: czy ktorykolwiek komunikat widziany przez CZLOWIEKA (klienta albo pracownika) "
			. "moze wyjsc niekompletny lub mylacy? Szukaj:\n"
			. "- wartosci wstawianej w tekst bez sprawdzenia, czy nie jest pusta (np. pusty numer oferty w temacie maila),\n"
			. "- komunikatu bledu, ktory nie mowi, co uzytkownik ma zrobic,\n"
			. "- komunikatu twierdzacego, ze cos sie udalo, w galezi obslugujacej porazke,\n"
			. "- danych osobowych albo szczegolow technicznych wyciekajacych do tresci dla klienta.\n\n"
			. "Zglaszaj tylko to, co realnie moze zobaczyc czlowiek. Nie zglaszaj stylu jezyka.\n\n"
			. "=== KOD ===\n" . $dossier['kod'];
	}
}
