<?php
/**
 * Wspólny, deterministyczny scoring leada — jedno źródło prawdy.
 *
 * Używane przez dział 7.2 (scoring przy tworzeniu leada) ORAZ przez weryfikator
 * w tle (re-scoring po asynchronicznym potwierdzeniu VAT / statusu firmy).
 * Wydzielone celowo, aby obie ścieżki liczyły punktację IDENTYCZNIE (DRY).
 *
 * Reguły punktacji (bez zmian względem dotychczasowej logiki działu 7.2):
 *  - VAT UE ważny (VIES) ............ +30
 *  - Status firmy „Czynny" (Biała lista) +20
 *  - Podany telefon ................. +10
 *  - Zgoda marketingowa ............. +10
 *  - Segment {Produkcja, IT, Budownictwo} +15
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kalkulator scoringu leada (statyczny, bezstanowy).
 */
class MP_Lead_Scoring {

	/** Segmenty premiowane dodatkowymi punktami. */
	const BONUS_SEGMENTS = array( 'Produkcja', 'IT', 'Budownictwo' );

	/**
	 * Liczy score na podstawie sygnałów leada.
	 *
	 * Akceptowane klucze $signals: vat_valid (bool|null), company_status (string|null),
	 * phone (string|null), consent_marketing (bool|int), segment (string|null).
	 * Semantyka zgodna 1:1 z dawnym MP_D7_Agent_Prepare::calculate_score().
	 *
	 * @param array $signals Sygnały wejściowe.
	 * @return int Wynik punktowy.
	 */
	public static function calculate( array $signals ) {
		$score = 0;

		if ( array_key_exists( 'vat_valid', $signals ) && true === $signals['vat_valid'] ) {
			$score += 30;
		}
		if ( 'Czynny' === ( isset( $signals['company_status'] ) ? $signals['company_status'] : null ) ) {
			$score += 20;
		}
		if ( '' !== trim( (string) ( isset( $signals['phone'] ) ? $signals['phone'] : '' ) ) ) {
			$score += 10;
		}
		if ( ! empty( $signals['consent_marketing'] ) ) {
			$score += 10;
		}
		if ( in_array( isset( $signals['segment'] ) ? $signals['segment'] : null, self::BONUS_SEGMENTS, true ) ) {
			$score += 15;
		}

		return $score;
	}
}
