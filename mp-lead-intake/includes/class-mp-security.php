<?php
/**
 * Warstwa hardeningu wtyczki MP Lead Intake.
 *
 * Zasada: wtyczka lead-intake NIE narzuca polityki bezpieczeństwa całej witrynie.
 *  - Nagłówki bezpieczeństwa na WŁASNYCH odpowiedziach AJAX są zawsze (bezpieczne —
 *    dotyczą tylko naszej odpowiedzi, nie mogą zepsuć motywu/panelu).
 *  - Pełny zestaw nagłówków + restrykcyjny CSP na CAŁĄ witrynę jest OPT-IN
 *    (domyślnie OFF), włączany świadomie stałą MP_LEAD_INTAKE_SECURITY_HEADERS
 *    lub filtrem 'mp_lead_intake_send_security_headers'. Zestaw i CSP są filtrowalne.
 *  - Weryfikacja Origin/Referer i correlation ID wspierają twardnienie endpointu AJAX.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moduł hardeningu (nagłówki, Origin, correlation ID).
 */
class MP_Lead_Intake_Security {

	/**
	 * Rejestruje hooki. Globalne nagłówki tylko po jawnym opt-in właściciela witryny.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::global_headers_enabled() ) {
			add_action( 'send_headers', array( __CLASS__, 'send_global_headers' ) );
		}
		self::harden_wp();
	}

	/**
	 * Wybrane przez właściciela utwardzenie WordPressa. Każde zachowanie jest
	 * FILTROWALNE (domyślnie włączone) — można wyłączyć pojedynczo, np.
	 * add_filter( 'mp_lead_intake_disable_xmlrpc', '__return_false' ).
	 *
	 * @return void
	 */
	public static function harden_wp() {
		// 1) Brak edytora plików w panelu — admin nie wgra PHP przez kokpit (CWE-94).
		if ( apply_filters( 'mp_lead_intake_disallow_file_edit', true ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		// 2) Ukrycie wersji WordPressa (mniej fingerprintingu pod exploity).
		if ( apply_filters( 'mp_lead_intake_hide_wp_version', true ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}

		// 3) Wyłączenie XML-RPC (brute-force amplification przez system.multicall,
		// pingback DDoS/SSRF). Uwaga: może kolidować z apką mobilną WP / Jetpack.
		if ( apply_filters( 'mp_lead_intake_disable_xmlrpc', true ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'strip_pingback_methods' ) );
			add_filter( 'wp_headers', array( __CLASS__, 'remove_pingback_header' ) );
		}
	}

	/**
	 * Usuwa metody pingback z XML-RPC.
	 *
	 * @param array $methods Metody XML-RPC.
	 * @return array
	 */
	public static function strip_pingback_methods( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return (array) $methods;
	}

	/**
	 * Usuwa nagłówek X-Pingback.
	 *
	 * @param array $headers Nagłówki odpowiedzi.
	 * @return array
	 */
	public static function remove_pingback_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return (array) $headers;
	}

	/**
	 * Czy wysyłać globalne nagłówki (opt-in: stała lub filtr, domyślnie false).
	 *
	 * @return bool
	 */
	public static function global_headers_enabled() {
		$on = defined( 'MP_LEAD_INTAKE_SECURITY_HEADERS' ) && MP_LEAD_INTAKE_SECURITY_HEADERS;
		return (bool) apply_filters( 'mp_lead_intake_send_security_headers', $on );
	}

	/**
	 * Nagłówki bezpieczeństwa na odpowiedzi AJAX wtyczki (scoped, zawsze bezpieczne).
	 *
	 * @return void
	 */
	public static function send_ajax_headers() {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/**
	 * OPT-IN: pełny zestaw nagłówków + CSP na całą witrynę. Filtrowalny.
	 *
	 * @return void
	 */
	public static function send_global_headers() {
		if ( headers_sent() ) {
			return;
		}
		$headers = apply_filters( 'mp_lead_intake_security_header_list', self::default_global_headers() );
		foreach ( (array) $headers as $name => $value ) {
			if ( '' !== (string) $value ) {
				header( $name . ': ' . $value );
			}
		}
	}

	/**
	 * Domyślny, restrykcyjny (ale zdroworozsądkowy) zestaw nagłówków globalnych.
	 *
	 * @return array<string,string>
	 */
	public static function default_global_headers() {
		return array(
			'X-Content-Type-Options'       => 'nosniff',
			'X-Frame-Options'              => 'SAMEORIGIN',
			'Referrer-Policy'              => 'strict-origin-when-cross-origin',
			'Permissions-Policy'           => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
			'Cross-Origin-Opener-Policy'   => 'same-origin',
			'Cross-Origin-Resource-Policy' => 'same-origin',
			'Origin-Agent-Cluster'         => '?1',
			// X-XSS-Protection celowo 0 — stary auditor bywał sam wektorem; CSP go zastępuje.
			'X-XSS-Protection'             => '0',
			'Strict-Transport-Security'    => is_ssl() ? 'max-age=31536000; includeSubDomains' : '',
			'Content-Security-Policy'      => apply_filters( 'mp_lead_intake_csp', self::default_csp() ),
		);
	}

	/**
	 * Domyślna, restrykcyjna polityka CSP. Filtrowalna ('mp_lead_intake_csp').
	 * 'unsafe-inline' w style-src bo motywy WP powszechnie używają stylów inline;
	 * skrypty są 'self' (bez inline). Do zaostrzenia pod konkretny motyw.
	 *
	 * @return string
	 */
	public static function default_csp() {
		return implode(
			'; ',
			array(
				"default-src 'self'",
				"base-uri 'self'",
				"form-action 'self'",
				"frame-ancestors 'self'",
				"object-src 'none'",
				"script-src 'self'",
				"style-src 'self' 'unsafe-inline'",
				"img-src 'self' data:",
				"font-src 'self'",
				"connect-src 'self'",
				"manifest-src 'self'",
				"worker-src 'self'",
				'upgrade-insecure-requests',
			)
		);
	}

	/**
	 * Weryfikacja Origin/Referer dla żądań zmieniających stan (defense-in-depth vs CSRF).
	 * Gdy oba nagłówki są nieobecne (część proxy/przeglądarek je usuwa) — nie blokujemy
	 * twardo; główną ochroną pozostaje nonce.
	 *
	 * @return bool True gdy pochodzenie zgodne z witryną lub nieustalone.
	 */
	public static function verify_origin() {
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		$src = '';
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$src = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$src = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		} else {
			return true; // Brak obu — nonce pozostaje ochroną.
		}

		$src_host = wp_parse_url( $src, PHP_URL_HOST );
		return is_string( $src_host ) && strtolower( $src_host ) === $site_host;
	}

	/**
	 * Correlation ID żądania — do korelacji logów bez ujawniania szczegółów klientowi.
	 *
	 * @return string
	 */
	public static function request_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return md5( uniqid( (string) wp_rand(), true ) );
	}
}
