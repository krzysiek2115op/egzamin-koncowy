<?php
/**
 * Wspolne narzedzia par — glownie po to, zeby NIE zglaszac falszywych alarmow.
 *
 * Pierwszy przebieg tego audytu zglosil cztery ustalenia krytyczne, z ktorych
 * wszystkie byly bledami samego narzedzia. Trzy z czterech mialy wspolna
 * przyczyne: wzorzec trafial w KOMENTARZ albo w tresc napisu, a nie w kod.
 * Dlatego `kod()` jest tu funkcja pierwszej potrzeby, a nie ozdoba.
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/**
 * Statyczne narzedzia pomocnicze.
 */
final class MP_AU_Pomoc {

	/** @var array<string,string> Pamiec podreczna wyniku `kod()`. */
	private static $cache_kodu = array();

	/**
	 * Zwraca kod PHP z WYGASZONYMI komentarzami, z zachowaniem numeracji linii.
	 *
	 * Komentarz zostaje zastapiony spacjami i tyloma znakami nowej linii, ile
	 * mial — dzieki temu `linia()` i offsety dalej wskazuja prawde, a wzorzec nie
	 * ma szans trafic w zdanie po polsku opisujace blad, ktory wlasnie naprawiono.
	 * Ten przypadek juz wystapil: asercja w tescie trafila we WLASNY komentarz.
	 *
	 * @param string $tresc Tresc pliku.
	 * @return string
	 */
	public static function kod( string $tresc ): string {
		$klucz = md5( $tresc );

		if ( isset( self::$cache_kodu[ $klucz ] ) ) {
			return self::$cache_kodu[ $klucz ];
		}

		$wynik = '';

		foreach ( token_get_all( $tresc ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$wynik .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}

			$wynik .= is_array( $token ) ? $token[1] : $token;
		}

		self::$cache_kodu[ $klucz ] = $wynik;

		return $wynik;
	}

	/**
	 * Numer linii dla przesuniecia bajtowego.
	 *
	 * @param string $tresc  Tresc.
	 * @param int    $offset Przesuniecie.
	 * @return int
	 */
	public static function linia_offsetu( string $tresc, int $offset ): int {
		return substr_count( $tresc, "\n", 0, max( 0, min( $offset, strlen( $tresc ) ) ) ) + 1;
	}

	/**
	 * Numer linii pierwszego wystapienia fragmentu.
	 *
	 * @param string $tresc    Tresc.
	 * @param string $fragment Fragment.
	 * @return int
	 */
	public static function linia( string $tresc, string $fragment ): int {
		$pozycja = strpos( $tresc, $fragment );

		return false === $pozycja ? 0 : self::linia_offsetu( $tresc, $pozycja );
	}

	/**
	 * Skraca dowod do rozmiaru, ktory da sie przeczytac w raporcie.
	 *
	 * Ciecie po ZNAKACH, nie po bajtach. `substr()` na 400. bajcie ladowalo
	 * w srodku znaku wielobajtowego, a dowody ustalen sa pelne polskich liter
	 * i cudzyslowow typograficznych. Skutek byl niewidoczny az do werdyktu:
	 * `json_encode()` na zepsutym UTF-8 zwraca `false`, sklejenie `"..." . false`
	 * daje pusty ciag, wiec pytanie szlo do modelu BEZ danych, model uczciwie
	 * odpowiadal pusta lista, a krytyk czytal ja jako „ocena nie zostala
	 * wykonana". Tak wlasnie para 2.9 zabrala werdykt calemu przebiegowi
	 * z 01.08.2026 — patrz audyt/tests/skrot-utf8.php.
	 *
	 * @param string $tekst Tekst.
	 * @param int    $ile   Limit znakow.
	 * @return string
	 */
	public static function skrot( string $tekst, int $ile = 160 ): string {
		$tekst = trim( preg_replace( '/\s+/', ' ', $tekst ) ?? $tekst );

		return mb_strlen( $tekst, 'UTF-8' ) <= $ile
			? $tekst
			: mb_substr( $tekst, 0, $ile, 'UTF-8' ) . ' […]';
	}

	/**
	 * Czy w poblizu trafienia stoi swiadome wyciszenie (`phpcs:ignore`).
	 *
	 * Wyciszenie z uzasadnieniem to decyzja autora, nie przeoczenie. Audyt, ktory
	 * je ignoruje, kaze poprawiac rzeczy celowo zrobione inaczej — i po trzecim
	 * takim zgloszeniu przestaje byc czytany.
	 *
	 * @param string $tresc  Pelna tresc pliku (z komentarzami!).
	 * @param int    $linia  Numer linii trafienia.
	 * @param int    $wstecz Ile linii wstecz sprawdzic.
	 * @return bool
	 */
	public static function wyciszone( string $tresc, int $linia, int $wstecz = 3 ): bool {
		$wiersze = explode( "\n", $tresc );
		$od      = max( 0, $linia - 1 - $wstecz );

		for ( $i = $od; $i < min( count( $wiersze ), $linia + 1 ); $i++ ) {
			if ( false !== stripos( $wiersze[ $i ], 'phpcs:ignore' )
				|| false !== stripos( $wiersze[ $i ], 'phpcs:disable' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Wszystkie pliki PHP trzech wtyczek jako pary [branch, sciezka].
	 *
	 * @param MP_AU_Kontekst $kontekst   Kontekst.
	 * @param bool           $bez_testow Czy pominac `tests/`.
	 * @return array<int,array{branch:string,plik:string}>
	 */
	public static function wszystkie_pliki( MP_AU_Kontekst $kontekst, bool $bez_testow = true ): array {
		$lista = array();

		foreach ( $kontekst->workspace->branche() as $branch ) {
			foreach ( $kontekst->workspace->pliki_php( $branch, $bez_testow ) as $plik ) {
				$lista[] = array(
					'branch' => $branch,
					'plik'   => $plik,
				);
			}
		}

		return $lista;
	}

	/**
	 * Czy sciezka wskazuje plik testu.
	 *
	 * @param string $sciezka Sciezka.
	 * @return bool
	 */
	public static function czy_test( string $sciezka ): bool {
		return false !== strpos( $sciezka, '/tests/' );
	}

	/**
	 * Blok w klamrach zaczynajacy sie od pierwszej klamry za podana pozycja.
	 *
	 * @param string $kod     Kod z wygaszonymi komentarzami.
	 * @param int    $pozycja Pozycja startowa.
	 * @return string Blok wraz z klamrami albo pusty napis.
	 */
	public static function blok( string $kod, int $pozycja ): string {
		$otwarcie = strpos( $kod, '{', $pozycja );

		if ( false === $otwarcie ) {
			return '';
		}

		$glebia  = 0;
		$dlugosc = strlen( $kod );

		for ( $i = $otwarcie; $i < $dlugosc; $i++ ) {
			if ( '{' === $kod[ $i ] ) {
				++$glebia;
			} elseif ( '}' === $kod[ $i ] ) {
				--$glebia;

				if ( 0 === $glebia ) {
					return substr( $kod, $otwarcie, $i - $otwarcie + 1 );
				}
			}
		}

		return '';
	}

	/**
	 * Cialo funkcji albo metody — po nawiasach klamrowych, nie po odlegloscii.
	 *
	 * Roznica jest istotna dla par bezpieczenstwa: pytanie „czy TA metoda
	 * sprawdza uprawnienia" ma sens tylko wtedy, gdy patrzymy na jej cialo.
	 * Szukanie „w promieniu 500 znakow" znajduje sprawdzenie z SASIEDNIEJ metody
	 * i wystawia czysta ocene handlerowi, ktory nie sprawdza niczego.
	 *
	 * @param string $kod   Kod z wygaszonymi komentarzami.
	 * @param string $nazwa Nazwa funkcji/metody.
	 * @return string Cialo albo pusty napis.
	 */
	public static function cialo_funkcji( string $kod, string $nazwa ): string {
		if ( ! preg_match( '/function\s+' . preg_quote( $nazwa, '/' ) . '\s*\([^)]*\)[^{;]*\{/i', $kod, $t, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}

		$od    = (int) $t[0][1] + strlen( $t[0][0] ) - 1;
		$glebia = 0;
		$dlugosc = strlen( $kod );

		for ( $i = $od; $i < $dlugosc; $i++ ) {
			if ( '{' === $kod[ $i ] ) {
				++$glebia;
			} elseif ( '}' === $kod[ $i ] ) {
				--$glebia;

				if ( 0 === $glebia ) {
					return substr( $kod, $od, $i - $od + 1 );
				}
			}
		}

		return substr( $kod, $od );
	}

	/**
	 * Czy pod wskazana sciezka lezy repozytorium git.
	 *
	 * W zwyklym klonie `.git` jest katalogiem, ale w worktree — PLIKIEM
	 * ze wskaznikiem `gitdir: ...`. Sprawdzanie samego `is_dir()` odrzucalo
	 * wiec dokladnie te forme repozytorium, w ktorej pracuje sie nad kilkoma
	 * galeziami naraz — a wiec i te, z ktorej uruchamia sie audyt.
	 *
	 * @param string $sciezka Katalog do sprawdzenia.
	 * @return bool
	 */
	public static function czy_repozytorium( string $sciezka ): bool {
		$git = rtrim( $sciezka, '/' ) . '/.git';

		return is_dir( $git ) || is_file( $git );
	}
}
