<?php
/**
 * Dział 5 — Zabezpieczenie formularza.
 *
 * 5.1 antyspam (honeypot), 5.2 CSRF (wp_verify_nonce), 5.3 rate limit (transient/IP).
 *
 * Źródła (oficjalne) — Golden Rule #2. Dokumentacja czytana przez agentów/krytyków:
 *  - docs/dzial-05/wordpress-wp_verify_nonce.md
 *  - docs/dzial-05/wordpress-transients.md
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 5.1 — antyspam (honeypot).
 */
class MP_D5_Agent_Antispam extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '5.1', 'Antyspam', 'Honeypot: ukryte pole mp_hp musi być puste' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$honeypot = trim( (string) $context->get( 'mp_hp', '' ) );
		$is_spam  = ( '' !== $honeypot );

		return MP_Result::ok(
			array(
				'spam'        => $is_spam,
				'antispam_ok' => ! $is_spam,
			)
		);
	}
}

/**
 * Agent 5.2 — CSRF (weryfikacja nonce WordPress).
 */
class MP_D5_Agent_Csrf extends MP_Abstract_Agent {

	/** Nazwa akcji nonce. */
	const NONCE_ACTION = 'mp_lead_intake';

	public function __construct() {
		parent::__construct( '5.2', 'Sprawdza CSRF', 'wp_verify_nonce dla akcji mp_lead_intake' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$nonce = (string) $context->get( 'mp_nonce', '' );
		$ok    = ( '' !== $nonce ) && (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );

		return MP_Result::ok(
			array(
				'csrf'    => $ok,
				'csrf_ok' => $ok,
			)
		);
	}
}

/**
 * Agent 5.3 — rate limit (transient per adres IP).
 *
 * Inkrement licznika następuje w pre-gate handlera AJAX (class-mp-ajax.php),
 * PRZED uruchomieniem pipeline — inaczej zgłoszenia odrzucone wcześniej w
 * pipeline (np. zła suma kontrolna NIP w dziale 3, PRZED działem 5) nigdy nie
 * trafiałyby do licznika, co pozwalałoby ominąć limit floodem błędnych danych
 * (wykryte w testach manualnych na żywym WP — scenariusz 8/10, docs/TESTY.md).
 * Ten agent w pipeline jest już tylko odczytem (defense-in-depth, gdyby pipeline
 * kiedyś odpalił inny wpis niż ten handler AJAX).
 */
class MP_D5_Agent_Rate_Limit extends MP_Abstract_Agent {

	/** Limit zgłoszeń w oknie czasu. */
	const LIMIT = 5;

	public function __construct() {
		parent::__construct( '5.3', 'Rate limit', 'Limit zgłoszeń na IP w oknie 60 s (transient)' );
	}

	/**
	 * Klucz transientu rate-limit dla danego IP.
	 *
	 * @param string $ip Adres IP.
	 * @return string
	 */
	public static function rate_key( $ip ) {
		return 'mp_rl_' . md5( (string) $ip );
	}

	/**
	 * Bieżące IP klienta. Świadomie z REMOTE_ADDR (nie X-Forwarded-For — spoofing).
	 *
	 * UWAGA WDROŻENIOWA (audyt 2026-07-22, SHOULD FIX — przegląd rate-limitu za
	 * proxy/CDN): jeśli produkcja stanie kiedyś ZA reverse proxy/CDN (Cloudflare,
	 * load balancer), REMOTE_ADDR przestanie być realnym IP klienta — będzie to
	 * adres proxy, WSPÓLNY dla wszystkich odwiedzających, więc limit "na IP" zacznie
	 * liczyć CAŁY ruch strony jako jednego klienta. Na dziś (zwykły hosting bez
	 * CDN) to poprawne zachowanie. Gdy to się zmieni, trzeba świadomie przełączyć
	 * się na zaufany nagłówek proxy (np. CF-Connecting-IP za Cloudflare) — NIE na
	 * gołe X-Forwarded-For, bo bez zweryfikowania, że żądanie faktycznie przeszło
	 * przez zaufany proxy, ten nagłówek klient może dowolnie sfałszować.
	 *
	 * @return string
	 */
	public static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	/**
	 * Czy dane IP osiągnęło już limit — odczyt BEZ inkrementu.
	 *
	 * Używane przez pre-gate w handlerze AJAX, by odrzucić flood ZANIM pipeline
	 * odpali kosztowne zapytania zewnętrzne (dział 3), oraz przez agenta 5.3
	 * poniżej (defense-in-depth).
	 *
	 * @param string $ip Adres IP.
	 * @return bool
	 */
	public static function over_limit( $ip ) {
		return (int) get_transient( self::rate_key( $ip ) ) >= self::LIMIT;
	}

	/**
	 * Zlicza JEDNĄ próbę zgłoszenia dla danego IP (no-op, gdy już nad limitem).
	 *
	 * JEDYNE źródło inkrementu (brak podwójnego liczenia) — wywoływane raz na
	 * żądanie z pre-gate w class-mp-ajax.php, PRZED uruchomieniem pipeline. Dzięki
	 * temu liczy się KAŻDA próba, niezależnie od tego, na którym dziale pipeline
	 * później odpadnie (patrz komentarz klasy powyżej).
	 *
	 * @param string $ip Adres IP.
	 * @return void
	 */
	public static function increment( $ip ) {
		$key   = self::rate_key( $ip );
		$count = (int) get_transient( $key );
		if ( $count < self::LIMIT ) {
			set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		unset( $context );

		$ok = ! self::over_limit( self::client_ip() );

		return MP_Result::ok(
			array(
				'rate_limit' => $ok,
				'rate_ok'    => $ok,
			)
		);
	}
}

/**
 * QA Agent 5 — wszystkie zabezpieczenia muszą przejść.
 */
class MP_D5_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA5', 'QA Agent 5 — kontrola zabezpieczeń', 'antispam + csrf + rate limit OK' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		foreach ( array( 'antispam_ok', 'csrf_ok', 'rate_ok' ) as $flag ) {
			if ( ! $context->get( $flag, false ) ) {
				return MP_Result::fail( 'Zabezpieczenie niespełnione: ' . $flag, array(), 'security_failed' );
			}
		}
		return MP_Result::ok( array( 'd5_secure' => true ) );
	}
}

/**
 * Budowniczy działu 5.
 */
class MP_Department_05 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D5_Agent_Antispam(),
				'critic' => new MP_Flag_Critic( 'K5.1', 'Krytyk 5.1 — weryfikuje antyspam', 'antispam_ok' ),
			),
			array(
				'agent'  => new MP_D5_Agent_Csrf(),
				'critic' => new MP_Flag_Critic( 'K5.2', 'Krytyk 5.2 — weryfikuje CSRF', 'csrf_ok' ),
			),
			array(
				'agent'  => new MP_D5_Agent_Rate_Limit(),
				'critic' => new MP_Flag_Critic( 'K5.3', 'Krytyk 5.3 — weryfikuje limit', 'rate_ok' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D5_QA_Agent(),
			new MP_Accept_Critic( 'QAK5', 'QA Krytyk 5 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			5,
			'secure-form',
			'Zabezpieczenie formularza',
			'Zabezpieczenia przed nadużyciami: antyspam, CSRF, rate limit.',
			$pairs,
			$gate
		);
	}
}
