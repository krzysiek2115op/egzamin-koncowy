<?php
/**
 * Kanoniczna postac numeru VAT i lokalna kontrola jego formatu.
 *
 * Jedno miejsce na obie te decyzje, bo wczesniej numer byl sprowadzany do
 * samych cyfr w siedmiu miejscach naraz (dzialy 1, 2, 3 i weryfikator w tle),
 * a kazda kopia mowila to samo o Polsce i to samo — bledne — o reszcie UE.
 *
 * PODZIAL ROL. Lokalnie rozstrzygamy tylko to, co da sie rozstrzygnac bez
 * sieci: czy numer w ogole wyglada jak numer. O tym, czy istnieje i czy jest
 * czynny, orzeka VIES (agent 3.2) — dla kazdego kraju UE, takze dla Polski.
 * Swiadomie NIE trzymamy tu tablicy formatow 27 panstw: byloby to drugie,
 * konkurencyjne zrodlo prawdy obok VIES, ktore predzej czy pozniej rozjechaloby
 * sie z rzeczywistoscia (panstwa zmieniaja formaty) i odrzucaloby poprawne
 * numery, zanim ktokolwiek zdazylby o nie zapytac.
 *
 * WYJATEK NA POLSKE. Polska suma kontrolna zostaje, bo to rachunek, nie tablica
 * — nie starzeje sie i pozwala odrzucic literowke bez wychodzenia do sieci.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Numer VAT: normalizacja i lokalna kontrola formatu.
 */
class MP_Vat_Number {

	/**
	 * Kraj, dla ktorego mamy wlasna sume kontrolna.
	 */
	const POLAND = 'PL';

	/**
	 * Najkrotszy i najdluzszy numer lokalny (bez prefiksu kraju), jaki
	 * przepuszczamy poza Polska. Skrajne numery w UE miesza sie w 8–12 znakach;
	 * bierzemy zapas w obie strony, bo to ma byc sanity-check, a nie walidator.
	 */
	const MIN_LEN = 4;
	const MAX_LEN = 14;

	/**
	 * Ile cyfr musi zawierac numer zagraniczny. Kazdy numer VAT w UE jest
	 * w przewazajacej czesci cyfrowy — najmniej cyfr ma irlandzki (siedem).
	 */
	const MIN_DIGITS = 4;

	/**
	 * Kod kraju sprowadzony do dwoch wielkich liter.
	 *
	 * Wszystko, co nie ma ksztaltu ISO 3166-1 (w tym pusty string), traktujemy
	 * jak Polske — bo tak zachowywal sie kazdy z siedmiu kawalkow kodu, ktore ta
	 * klasa zastapila, i tak dziala domyslka w formularzu.
	 *
	 * @param string $kraj Kod kraju.
	 * @return string
	 */
	public static function country( $kraj ) {
		$kraj = strtoupper( trim( (string) $kraj ) );

		return preg_match( '/\A[A-Z]{2}\z/', $kraj ) ? $kraj : self::POLAND;
	}

	/**
	 * Czy numer podlega polskim regulom.
	 *
	 * @param string $kraj Kod kraju.
	 * @return bool
	 */
	public static function is_polish( $kraj ) {
		return self::POLAND === self::country( $kraj );
	}

	/**
	 * Kanoniczna postac numeru dla danego kraju.
	 *
	 * @param string $numer Numer w postaci, w jakiej podal go czlowiek.
	 * @param string $kraj  Kod kraju.
	 * @return string
	 */
	public static function normalize( $numer, $kraj = '' ) {
		$kraj  = self::country( $kraj );
		$numer = strtoupper( trim( (string) $numer ) );

		/*
		 * Czlowiek przepisuje numer z faktury, a tam stoi on z prefiksem kraju
		 * ("DE123456789"). VIES chce numer BEZ prefiksu — kraj jedzie osobno,
		 * w sciezce adresu. Obcinamy wylacznie prefiks ZGODNY z wybranym
		 * krajem: gdy ktos wybral Niemcy, a wpisal numer polski, niezgodnosc ma
		 * zostac widoczna i pojechac do VIES, zamiast zniknac po cichu tutaj.
		 */
		if ( 0 === strpos( $numer, $kraj ) ) {
			$numer = substr( $numer, 2 );
		}

		if ( self::POLAND === $kraj ) {
			// Sciezka polska zostaje co do znaku taka, jaka byla od poczatku.
			return preg_replace( '/\D+/', '', $numer );
		}

		/*
		 * Poza Polska litera bywa CZESCIA numeru, nie smieciem: holenderski ma
		 * "B01" na koncu, irlandzki konczy sie litera lub dwiema. Wycinamy wiec
		 * tylko separatory — spacje, myslniki, kropki — a nie wszystko, co nie
		 * jest cyfra. Stara normalizacja robila z "123456789B01" numer
		 * "12345678901", czyli pytala VIES o cudzy numer.
		 */
		return preg_replace( '/[^A-Z0-9]+/', '', $numer );
	}

	/**
	 * Czy numer to oczywista wartosc zastepcza (wszystkie znaki jednakowe).
	 *
	 * @param string $numer Numer po normalizacji.
	 * @return bool
	 */
	public static function placeholder( $numer ) {
		$numer = (string) $numer;

		return '' !== $numer && (bool) preg_match( '/\A(.)\1+\z/', $numer );
	}

	/**
	 * Lokalna kontrola formatu — tyle, ile da sie orzec bez sieci.
	 *
	 * @param string $numer Numer po normalizacji.
	 * @param string $kraj  Kod kraju.
	 * @return bool
	 */
	public static function format_valid( $numer, $kraj = '' ) {
		$numer = (string) $numer;

		if ( self::is_polish( $kraj ) ) {
			/*
			 * Dokladnie stary warunek. Wartosci zastepczych NIE odrzucamy tutaj,
			 * bo na sciezce polskiej lapie je suma kontrolna w dziale 3 — i ma
			 * je lapac dalej, z wlasnym komunikatem. Przeniesienie tego wyzej
			 * zmieniloby to, co widzi polski klient, a to nie jest przedmiotem
			 * tej zmiany.
			 */
			return 10 === strlen( $numer );
		}

		if ( ! preg_match( '/\A[A-Z0-9]{' . self::MIN_LEN . ',' . self::MAX_LEN . '}\z/', $numer ) ) {
			return false;
		}

		if ( preg_match_all( '/\d/', $numer ) < self::MIN_DIGITS ) {
			return false;
		}

		/*
		 * Za granica sumy kontrolnej nie liczymy, wiec to jedyne miejsce, ktore
		 * moze odrzucic "1111111111". Bez tego wartosc zastepcza szlaby do BD-3
		 * i do VIES jak prawdziwy numer.
		 */
		return ! self::placeholder( $numer );
	}
}
