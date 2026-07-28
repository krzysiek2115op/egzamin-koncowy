<?php
/**
 * Warstwa wysylki: nadawca, higiena naglowkow i bezpiecznik kolejki.
 *
 * LP.3 jest jedyna wtyczka w systemie, ktora wykonuje ruch WYCHODZACY. To
 * przesadza o wadze tego pliku: bledy w pozostalych warstwach koncza sie zla
 * odpowiedzia dla jednego zadania, bledy tutaj koncza sie korespondencja
 * wyslana z domeny klienta do jego kontrahentow.
 *
 * Dwie rzeczy sa tu pilnowane osobno, bo psuja sie osobno:
 *  - TRESC naglowka. Nazwa firmy pochodzi z publicznego formularza LP.1, wiec
 *    jest tekstem od anonima. Wpisana w temat razem ze znakami konca linii
 *    dokleja do wiadomosci wlasne naglowki (`Bcc:`), czyli zamienia
 *    powiadomienie o ofercie w przesylke do adresatow atakujacego.
 *  - ILOSC. Zalew zdarzen (petla w innej wtyczce, ponowiony import) zamienia
 *    sklep w nadawce masowej poczty, co konczy sie wpisem domeny na czarne
 *    listy — szkoda nieodwracalna w praktyce, bo reputacji domeny nie da sie
 *    "cofnac".
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nadawca, naglowki i bezpiecznik.
 */
class MP_SW_Mailer {

	/** Opcja: znacznik zatrzymania kolejki przez bezpiecznik. */
	const OPTION_HALTED = 'mp_sw_queue_halted';

	/** Transient: okno licznika wysylek. */
	const WINDOW_KEY = 'mp_sw_mail_window';

	/** Ile wiadomosci na godzine uznajemy za gorna granice normalnej pracy. */
	const MAX_PER_HOUR = 200;

	/** Filtr progu bezpiecznika. */
	const FILTER_MAX = 'mp_sw_mail_max_per_hour';

	/** Filtr adresu nadawcy. */
	const FILTER_FROM = 'mp_sw_mail_from';

	/** Transient pilnujacy, zeby alarm do administratora poszedl raz. */
	const ALERT_KEY = 'mp_sw_breaker_alerted';

	/**
	 * Wpina filtry nadawcy.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_address' ), 20 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 20 );
	}

	/**
	 * Adres nadawcy — staly, z domeny witryny.
	 *
	 * Domyslne `wordpress@<host>` bywa adresem, ktorego nie obejmuje rekord SPF
	 * ani podpis DKIM; wiadomosc z takiego adresu laduje w spamie albo jest
	 * odrzucana. Adres ustalamy wiec jawnie i dokumentujemy go w wymaganiach
	 * wdrozeniowych (BLOK J).
	 *
	 * @param string $from Adres domyslny.
	 * @return string
	 */
	public static function from_address( $from ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : '';

		$address = '' !== (string) $host ? 'oferty@' . $host : (string) $from;

		/**
		 * Adres nadawcy powiadomien.
		 *
		 * @param string $address Adres wyliczony z domeny witryny.
		 */
		$address = (string) apply_filters( self::FILTER_FROM, $address );

		return is_email( $address ) ? $address : (string) $from;
	}

	/**
	 * Nazwa nadawcy.
	 *
	 * @param string $name Nazwa domyslna.
	 * @return string
	 */
	public static function from_name( $name ) {
		$blog = self::header_safe( get_bloginfo( 'name' ) );

		return '' !== $blog ? $blog : (string) $name;
	}

	/**
	 * Czysci wartosc przeznaczona do naglowka wiadomosci.
	 *
	 * Usuwane sa CR, LF i bajt zerowy — trzy znaki, ktorymi konczy sie jeden
	 * naglowek, a zaczyna nastepny. Nie zastepujemy ich spacja "dla czytelnosci":
	 * chodzi o to, zeby wartosc pozostala JEDNA linia, a nie zeby ladnie
	 * wygladala.
	 *
	 * @param string $value Wartosc.
	 * @return string
	 */
	public static function header_safe( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\r", "\n", "\0" ), ' ', $value );
		$value = sanitize_text_field( $value );

		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Czy wartosc zawiera proba wstrzykniecia naglowka.
	 *
	 * Sprawdzane osobno od czyszczenia, zeby krytyk mogl ODRZUCIC wiadomosc,
	 * zamiast po cichu wyslac wersje oczyszczona. Cicha naprawa ukrylaby fakt,
	 * ze ktos probowal — a to jest informacja, ktora chcemy zobaczyc.
	 *
	 * @param string $value Wartosc.
	 * @return bool
	 */
	public static function has_injection( $value ) {
		return (bool) preg_match( '/[\r\n\0]/', (string) $value );
	}

	/**
	 * Prog bezpiecznika.
	 *
	 * @return int
	 */
	public static function limit() {
		return max( 1, (int) apply_filters( self::FILTER_MAX, self::MAX_PER_HOUR ) );
	}

	/**
	 * Czy kolejka jest zatrzymana przez bezpiecznik.
	 *
	 * @return bool
	 */
	public static function halted() {
		return (int) get_option( self::OPTION_HALTED, 0 ) > 0;
	}

	/**
	 * Odnotowuje wysylke i sprawdza prog.
	 *
	 * @return bool Czy wolno wyslac kolejna wiadomosc.
	 */
	public static function allow_send() {
		if ( self::halted() ) {
			return false;
		}

		$window = get_transient( self::WINDOW_KEY );
		$count  = is_array( $window ) && isset( $window['count'] ) ? (int) $window['count'] : 0;

		if ( $count >= self::limit() ) {
			self::trip( $count );
			return false;
		}

		set_transient( self::WINDOW_KEY, array( 'count' => $count + 1 ), HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Zatrzymuje kolejke i zawiadamia administratora.
	 *
	 * @param int $count Licznik, przy ktorym bezpiecznik zadzialal.
	 * @return void
	 */
	public static function trip( $count ) {
		update_option( self::OPTION_HALTED, time(), false );

		MP_SW_Log::security(
			MP_SW_Errors::E_QUEUE_BREAKER,
			array(
				'code'   => MP_SW_Errors::E_QUEUE_BREAKER,
				'reason' => 'rate',
				'count'  => (int) $count,
			)
		);

		/**
		 * Bezpiecznik kolejki zadzialal.
		 *
		 * @param int $count Liczba wiadomosci w oknie.
		 */
		do_action( 'mp_sw_queue_halted', (int) $count );

		if ( get_transient( self::ALERT_KEY ) ) {
			return;
		}

		set_transient( self::ALERT_KEY, 1, HOUR_IN_SECONDS );

		$admin = get_option( 'admin_email' );

		if ( ! is_email( $admin ) ) {
			return;
		}

		/*
		 * Alarm idzie `wp_mail()` z pominieciem kolejki — celowo. Gdyby przechodzil
		 * ta sama droga, zostalby zatrzymany przez bezpiecznik, ktory wlasnie
		 * zglasza. Jedna wiadomosc na godzine pilnuje transient powyzej.
		 */
		wp_mail(
			$admin,
			self::header_safe( __( 'MP Sales Workflow: kolejka powiadomień zatrzymana', 'mp-sales-workflow' ) ),
			sprintf(
				/* translators: %d: liczba wiadomości w oknie godzinnym. */
				__( "Bezpiecznik zatrzymał kolejkę powiadomień po %d wiadomościach w ciągu godziny.\n\nSprawdź, czy nie zapętliło się źródło zdarzeń, i wznów kolejkę na stronie „Procesy sprzedażowe”.", 'mp-sales-workflow' ),
				(int) $count
			),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}

	/**
	 * Wznawia kolejke po interwencji administratora.
	 *
	 * @return void
	 */
	public static function resume() {
		delete_option( self::OPTION_HALTED );
		delete_transient( self::WINDOW_KEY );
		delete_transient( self::ALERT_KEY );
	}
}
