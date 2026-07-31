<?php
/**
 * Dziennik techniczny — zdarzenia bezpieczenstwa i odmowy.
 *
 * To NIE jest dziennik z kryterium 5.5 (ten jest w tabeli `mp_sw_activity` i
 * dotyczy historii procesu). Tutaj laduja odmowy: podrobione pochodzenie
 * zdarzenia, proba siegniecia po cudzy proces, odrzucony podpis linku.
 *
 * Wpis idzie do logu PHP, a nie do bazy, z dwoch powodow. Po pierwsze odmowa ma
 * zostawic zero sladu w tabelach procesu (scenariusz S1: "zero zmian w bazie").
 * Po drugie zapis do bazy przy kazdym odrzuconym zadaniu robi z odmowy wektor
 * DoS — wystarczy zalac endpoint, zeby zapchac dysk przez tabele.
 *
 * BLOK H (RODO): wpis niesie WYLACZNIE identyfikatory. Bialej listy `FACTS`
 * pilnuje `filter()` — nie da sie przypadkiem zalogowac adresu klienta ani
 * tresci formularza, bo klucz spoza listy jest odrzucany, a nie skracany.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dziennik techniczny wtyczki.
 */
class MP_SW_Log {

	/** Prefiks wpisu w logu PHP. */
	const PREFIX = '[MP Sales Workflow]';

	/** Filtr pozwalajacy witrynie przejac wpisy (np. do SIEM). */
	const FILTER_SINK = 'mp_sw_log_entry';

	/**
	 * Bufor wpisow dla testow — nie zastepuje logu PHP, tylko go dubluje.
	 *
	 * @var array<int,array>
	 */
	private static $buffer = array();

	/**
	 * Pola dopuszczalne we wpisie.
	 *
	 * Lista jest zamknieta CELOWO. Gdyby wpis przyjmowal dowolne klucze, pierwsza
	 * poprawka "dorzucmy jeszcze email, latwiej bedzie szukac" wprowadzalaby dane
	 * osobowe do logu serwera — czyli poza wszystkie mechanizmy usuwania danych,
	 * ktore zbudowalismy w bazie.
	 *
	 * @return string[]
	 */
	public static function facts() {
		return array(
			'code',
			'type',
			'source',
			'channel',
			'event_id',
			'trace_id',
			'user_id',
			'lead_id',
			'offer_id',
			'task_id',
			'flow_id',
			'notification_id',
			'ip_hash',
			'count',
			'reason',
		);
	}

	/**
	 * Odmowa lub zdarzenie bezpieczenstwa — zapisywane ZAWSZE.
	 *
	 * @param string $code  Kod ze slownika MP_SW_Errors.
	 * @param array  $facts Identyfikatory (biala lista).
	 * @return array Wpis po odfiltrowaniu.
	 */
	public static function security( $code, array $facts = array() ) {
		return self::write( 'SECURITY', $code, $facts );
	}

	/**
	 * Informacja techniczna — zapisywana tylko przy wlaczonym WP_DEBUG.
	 *
	 * @param string $code  Kod ze slownika MP_SW_Errors.
	 * @param array  $facts Identyfikatory (biala lista).
	 * @return array Wpis po odfiltrowaniu.
	 */
	public static function notice( $code, array $facts = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return self::filter( $facts );
		}

		return self::write( 'NOTICE', $code, $facts );
	}

	/**
	 * Sklada i zapisuje wpis.
	 *
	 * @param string $level Poziom.
	 * @param string $code  Kod bledu.
	 * @param array  $facts Identyfikatory.
	 * @return array
	 */
	private static function write( $level, $code, array $facts ) {
		$entry = array_merge(
			array(
				'level' => (string) $level,
				'code'  => (string) $code,
				'at'    => gmdate( 'c' ),
			),
			self::filter( $facts )
		);

		self::$buffer[] = $entry;

		/**
		 * Wpis dziennika technicznego.
		 *
		 * @param array $entry Wpis po odfiltrowaniu bialej listy.
		 */
		do_action( self::FILTER_SINK, $entry );

		$parts = array();

		foreach ( $entry as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}

		error_log( self::PREFIX . ' ' . implode( ' ', $parts ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return $entry;
	}

	/**
	 * Zostawia wylacznie pola z bialej listy i sprowadza je do skalarow.
	 *
	 * @param array $facts Dane wejsciowe.
	 * @return array
	 */
	public static function filter( array $facts ) {
		$allowed = self::facts();
		$out     = array();

		foreach ( $facts as $key => $value ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				continue;
			}

			if ( is_scalar( $value ) ) {
				// Nowa linia w wartosci rozbilaby wpis na dwa — a wartosci biora sie
				// miedzy innymi z zadania HTTP.
				$out[ $key ] = str_replace( array( "\r", "\n" ), ' ', (string) $value );
			}
		}

		return $out;
	}

	/**
	 * Pieprz do hashy — ta sama stala, ktorej uzywa wtyczka 1 do `ip_hash`.
	 *
	 * Brak stalej NIE jest cicho zastepowany losowa wartoscia: hash bez pieprza
	 * daje sie odwrocic teczowa tablica (przestrzen adresow IPv4 to 2^32, czyli
	 * kilka minut liczenia), wiec zamiast pozoru anonimizacji wolimy widoczny
	 * brak — instalacja produkcyjna ma stala ustawic (BLOK J).
	 *
	 * @return string
	 */
	public static function pepper() {
		if ( defined( 'MP_HASH_PEPPER' ) && '' !== (string) MP_HASH_PEPPER ) {
			return (string) MP_HASH_PEPPER;
		}

		return '';
	}

	/**
	 * Hash adresu IP zadania.
	 *
	 * Zwraca pusty ciag, gdy nie ma pieprza — wtedy po prostu nie logujemy IP.
	 *
	 * @param string $ip Adres; pusty oznacza "wez z zadania".
	 * @return string
	 */
	public static function ip_hash( $ip = '' ) {
		$pepper = self::pepper();

		if ( '' === $pepper ) {
			return '';
		}

		if ( '' === (string) $ip ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}

		if ( '' === (string) $ip ) {
			return '';
		}

		return hash_hmac( 'sha256', (string) $ip, $pepper );
	}

	/**
	 * Wpisy zebrane w tym przebiegu PHP (testy, diagnostyka).
	 *
	 * @return array<int,array>
	 */
	public static function entries() {
		return self::$buffer;
	}

	/**
	 * Czysci bufor.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$buffer = array();
	}
}
