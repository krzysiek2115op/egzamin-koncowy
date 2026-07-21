<?php
/**
 * Dział 3 — Sprawdzenie NIP / VAT.
 *
 * 3.1 NIP: oficjalny algorytm sumy kontrolnej (offline, deterministycznie).
 * 3.2 VAT UE: oficjalne VIES (REST) przez WP HTTP API, z cache i timeoutem,
 *     łagodny fallback (gdy VIES niedostępne → wynik "nieustalony", bez STOP).
 * 3.3 Status firmy: oficjalna Biała lista VAT (Ministerstwo Finansów), analogicznie.
 *
 * Źródła (oficjalne) — Golden Rule #2. Dokumentacja, którą "czytają" agenci/krytycy:
 *  - docs/dzial-03/nip-algorytm-sumy-kontrolnej.md
 *  - docs/dzial-03/vies-rest-api.md
 *  - docs/dzial-03/biala-lista-vat-api.md
 *  - docs/dzial-03/wordpress-wp_remote_get.md
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 3.1 — weryfikacja NIP oficjalnym algorytmem sumy kontrolnej.
 */
class MP_D3_Agent_Nip extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '3.1', 'Weryfikuje NIP', 'Oficjalny algorytm sumy kontrolnej NIP (wagi 6,5,7,2,3,4,5,6,7 mod 11)' );
	}

	/**
	 * Sprawdza sumę kontrolną NIP.
	 *
	 * @param string $nip 10 cyfr.
	 * @return bool
	 */
	public static function checksum_valid( $nip ) {
		if ( ! preg_match( '/^\d{10}$/', $nip ) ) {
			return false;
		}
		$weights = array( 6, 5, 7, 2, 3, 4, 5, 6, 7 );
		$sum     = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += $weights[ $i ] * (int) $nip[ $i ];
		}
		$control = $sum % 11;
		if ( 10 === $control ) {
			return false;
		}
		return $control === (int) $nip[9];
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$nip   = (string) $context->get( 'nip', '' );
		$valid = self::checksum_valid( $nip );

		return MP_Result::ok(
			array(
				'nip_valid' => $valid,
				'errors'    => $valid ? array() : array( 'nip' => 'Niepoprawna suma kontrolna NIP' ),
			)
		);
	}
}

/**
 * Agent 3.2 — weryfikacja VAT UE w VIES (oficjalne REST API).
 */
class MP_D3_Agent_Vat extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '3.2', 'Weryfikuje VAT UE', 'Oficjalne VIES REST (cache + timeout + łagodny fallback)' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$nip     = preg_replace( '/\D+/', '', (string) $context->get( 'nip', '' ) );
		$country = strtoupper( (string) $context->get( 'country', 'PL' ) );
		if ( '' === $country ) {
			$country = 'PL';
		}
		if ( '' === $nip ) {
			return MP_Result::ok( array( 'vat_valid' => null, 'vat_checked' => false ) );
		}

		$cache_key = 'mp_vies_' . $country . '_' . $nip;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return MP_Result::ok( array( 'vat_valid' => (bool) $cached, 'vat_checked' => true, 'vat_source' => 'cache' ) );
		}

		$url  = sprintf( 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s', rawurlencode( $country ), rawurlencode( $nip ) );
		$resp = wp_remote_get( $url, array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) ) );

		if ( is_wp_error( $resp ) ) {
			// Łagodny fallback — nie zatrzymujemy pipeline z powodu awarii VIES.
			return MP_Result::ok( array( 'vat_valid' => null, 'vat_checked' => false, 'vat_error' => $resp->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return MP_Result::ok( array( 'vat_valid' => null, 'vat_checked' => false ) );
		}

		$valid = ! empty( $body['isValid'] );
		set_transient( $cache_key, $valid ? 1 : 0, DAY_IN_SECONDS );

		return MP_Result::ok(
			array(
				'vat_valid'  => $valid,
				'vat_checked' => true,
				'vat_source' => 'vies',
				'vat_name'   => isset( $body['name'] ) ? $body['name'] : null,
			)
		);
	}
}

/**
 * Agent 3.3 — status firmy z Białej listy VAT (oficjalne API MF).
 */
class MP_D3_Agent_Company_Status extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '3.3', 'Sprawdza status firmy', 'Oficjalna Biała lista VAT (statusVat), cache + timeout + fallback' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$nip = preg_replace( '/\D+/', '', (string) $context->get( 'nip', '' ) );
		if ( '' === $nip ) {
			return MP_Result::ok( array( 'company_status' => null, 'company_status_checked' => false ) );
		}

		$date      = gmdate( 'Y-m-d' );
		$cache_key = 'mp_wl_' . $nip . '_' . $date;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return MP_Result::ok( array( 'company_status' => $cached, 'company_status_checked' => true, 'company_status_source' => 'cache' ) );
		}

		$url  = sprintf( 'https://wl-api.mf.gov.pl/api/search/nip/%s?date=%s', rawurlencode( $nip ), rawurlencode( $date ) );
		$resp = wp_remote_get( $url, array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) ) );

		if ( is_wp_error( $resp ) ) {
			return MP_Result::ok( array( 'company_status' => null, 'company_status_checked' => false ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return MP_Result::ok( array( 'company_status' => null, 'company_status_checked' => false ) );
		}

		$status = isset( $body['result']['subject']['statusVat'] ) ? $body['result']['subject']['statusVat'] : null;
		set_transient( $cache_key, $status, 12 * HOUR_IN_SECONDS );

		return MP_Result::ok( array( 'company_status' => $status, 'company_status_checked' => true, 'company_status_source' => 'wl' ) );
	}
}

/**
 * Krytyk 3.2 — odrzuca tylko, gdy VIES jednoznacznie orzekł, że VAT jest błędny.
 */
class MP_D3_Vat_Critic extends MP_Abstract_Critic {

	/**
	 * @param MP_Result  $agent_result Wynik agenta.
	 * @param MP_Context $context      Kontekst.
	 * @return MP_Result
	 */
	public function review( MP_Result $agent_result, MP_Context $context ) {
		unset( $context );
		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}
		$data = $agent_result->get_data();
		if ( array_key_exists( 'vat_valid', $data ) && false === $data['vat_valid'] ) {
			return MP_Result::fail( 'VAT UE niepoprawny wg VIES', array( 'errors' => array( 'vat' => 'VIES: numer VAT niepoprawny' ) ), 'vat_invalid' );
		}
		return MP_Result::ok( $data );
	}
}

/**
 * QA Agent 3 — wymaga poprawnego NIP (VAT/status są informacyjne).
 */
class MP_D3_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA3', 'QA Agent 3 — kontrola wyniku', 'Wymaga poprawnego NIP; VAT/status informacyjne' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		if ( ! $context->get( 'nip_valid', false ) ) {
			return MP_Result::fail( 'NIP niepoprawny', array( 'errors' => array( 'nip' => 'Niepoprawny NIP' ) ), 'nip_invalid' );
		}
		return MP_Result::ok( array( 'd3_verified' => true ) );
	}
}

/**
 * Budowniczy działu 3.
 */
class MP_Department_03 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D3_Agent_Nip(),
				'critic' => new MP_Flag_Critic( 'K3.1', 'Krytyk 3.1 — weryfikuje NIP', 'nip_valid' ),
			),
			array(
				'agent'  => new MP_D3_Agent_Vat(),
				'critic' => new MP_D3_Vat_Critic( 'K3.2', 'Krytyk 3.2 — weryfikuje VAT (VIES)' ),
			),
			array(
				'agent'  => new MP_D3_Agent_Company_Status(),
				'critic' => new MP_Accept_Critic( 'K3.3', 'Krytyk 3.3 — przyjmuje status firmy' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D3_QA_Agent(),
			new MP_Accept_Critic( 'QAK3', 'QA Krytyk 3 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			3,
			'nip-vat',
			'Sprawdzenie NIP / VAT',
			'Weryfikacja NIP (suma kontrolna), VAT UE (VIES) i statusu firmy (Biała lista).',
			$pairs,
			$gate
		);
	}
}
