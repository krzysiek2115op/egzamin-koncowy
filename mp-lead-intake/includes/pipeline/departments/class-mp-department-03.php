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
		// \A..\z (nie ^..$) — inaczej PCRE dopuściłoby końcowy \n po 10 cyfrach.
		if ( ! preg_match( '/\A\d{10}\z/', (string) $nip ) ) {
			return false;
		}
		// Odrzucamy oczywiste placeholdery (wszystkie cyfry jednakowe, np. 0000000000),
		// które formalnie przechodzą sumę kontrolną, a nie są realnym NIP-em.
		if ( preg_match( '/\A(\d)\1{9}\z/', (string) $nip ) ) {
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
	 * Powód odrzucenia NIP-u — słowami, którymi da się coś zrobić.
	 *
	 * Agent zwracał jeden komunikat „Niepoprawna suma kontrolna NIP" dla KAŻDEGO
	 * powodu: pustego pola, złej długości i wartości zastępczej. W trzech z tych
	 * czterech przypadków sumy kontrolnej nawet nie liczono, a komunikat twierdził
	 * co innego. Człowiek czytał, że pomylił się w cyfrze kontrolnej numeru,
	 * którego nie podał — albo „poprawiał" numer wpisany celowo jako zastępczy.
	 *
	 * Kolejność sprawdzeń jest ta sama, co w `checksum_valid()`, bo to ta sama
	 * decyzja, tylko opisana słowami. Żaden komunikat nie mówi nic ponad to, co
	 * naprawdę sprawdzono.
	 *
	 * Kod kraju służy WYŁĄCZNIE doborowi słów — reguła zostaje polska niezależnie
	 * od niego, bo to decyzja zakresu, nie przeoczenie. Sedno: numer VAT Słowacji
	 * ma dokładnie dziesięć cyfr, więc przechodzi kontrolę długości w Dziale 2
	 * i dociera tutaj, gdzie odrzuca go polska suma kontrolna. Zdanie
	 * „Niepoprawna suma kontrolna NIP" myliło wtedy podwójnie: numer jest
	 * poprawny, tylko liczony inną regułą, a człowiek szukał błędu rachunkowego
	 * tam, gdzie go nie ma.
	 *
	 * @param string $nip     Wartość z kontekstu (po normalizacji Działu 2).
	 * @param string $country Kod kraju z formularza; pusty albo „PL" = treść bez zmian.
	 * @return string Pusty ciąg, gdy NIP jest poprawny.
	 */
	public static function rejection_reason( $nip, $country = '' ) {
		$nip     = (string) $nip;
		$country = strtoupper( trim( (string) $country ) );

		// Kod kraju trafia do treści tylko w kształcie ISO 3166-1 — komunikat nie
		// ma być kanałem na cudzy tekst.
		$obcy  = '' !== $country && 'PL' !== $country;
		$nazwa = $obcy && preg_match( '/^[A-Z]{2}$/', $country ) ? sprintf( ' z kraju %s', $country ) : '';

		if ( '' === $nip ) {
			return 'NIP jest wymagany';
		}

		if ( ! preg_match( '/\A\d{10}\z/', $nip ) ) {
			return $obcy
				? sprintf( 'Sprawdzamy wyłącznie polski NIP (10 cyfr) — numer VAT%s ma inny format.', $nazwa )
				: 'NIP powinien mieć 10 cyfr';
		}

		if ( preg_match( '/\A(\d)\1{9}\z/', $nip ) ) {
			return 'NIP z samych powtórzonych cyfr nie jest prawdziwym numerem';
		}

		if ( self::checksum_valid( $nip ) ) {
			return '';
		}

		return $obcy
			? sprintf( 'Sprawdzamy wyłącznie polski NIP — numer%s nie przechodzi polskiej sumy kontrolnej.', $nazwa )
			: 'Niepoprawna suma kontrolna NIP';
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$nip   = (string) $context->get( 'nip', '' );
		$valid = self::checksum_valid( $nip );
		$powod = self::rejection_reason( $nip, (string) $context->get( 'country', '' ) );

		return MP_Result::ok(
			array(
				'nip_valid' => $valid,
				'errors'    => $valid ? array() : array( 'nip' => $powod ),
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
	 * Czy weryfikacja VAT/statusu ma iść w TLE (async, poza ścieżką żądania).
	 * Domyślnie TAK; filtr pozwala wrócić do zachowania synchronicznego (m.in. testy).
	 *
	 * @return bool
	 */
	public static function async_enabled() {
		return (bool) apply_filters( 'mp_lead_intake_async_verification', true );
	}

	/**
	 * Klucz cache (transient) wyniku VIES dla pary kraj+NIP. Jedno źródło formatu
	 * klucza dla ścieżki synchronicznej i weryfikatora w tle.
	 *
	 * @param string $country Kod kraju (np. PL).
	 * @param string $nip     NIP (same cyfry).
	 * @return string
	 */
	public static function vies_cache_key( $country, $nip ) {
		return 'mp_vies_' . $country . '_' . $nip;
	}

	/**
	 * PEŁNE rozstrzygnięcie VAT w VIES: cache-albo-HTTP (z zapisem do cache i łagodnym
	 * fallbackiem). To jedyne miejsce z kosztownym wywołaniem sieciowym VIES — wołane
	 * synchronicznie tylko przy async OFF, a w trybie async wyłącznie przez weryfikator
	 * w tle (poza żądaniem klienta).
	 *
	 * @param string $country Kod kraju.
	 * @param string $nip     NIP.
	 * @return array Kształt: vat_valid (bool|null), vat_checked (bool), [vat_source|vat_name|vat_error].
	 */
	public static function resolve_vies( $country, $nip ) {
		$nip     = preg_replace( '/\D+/', '', (string) $nip );
		$country = strtoupper( (string) $country );
		if ( '' === $country ) {
			$country = 'PL';
		}
		if ( '' === $nip ) {
			return array(
				'vat_valid'   => null,
				'vat_checked' => false,
			);
		}

		$cache_key = self::vies_cache_key( $country, $nip );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return array(
				'vat_valid'   => (bool) $cached,
				'vat_checked' => true,
				'vat_source'  => 'cache',
			);
		}

		$url  = sprintf( 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s', rawurlencode( $country ), rawurlencode( $nip ) );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			// Łagodny fallback — nie zatrzymujemy pipeline z powodu awarii VIES.
			return array(
				'vat_valid'   => null,
				'vat_checked' => false,
				'vat_error'   => $resp->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return array(
				'vat_valid'   => null,
				'vat_checked' => false,
			);
		}

		/*
		 * Brak pola `isValid` to NIE jest „numer nieważny". Dokumentacja VIES
		 * (docs/dzial-03/vies-rest-api.md) wymienia `isValid` i `userError` jako
		 * osobne pola i nigdzie nie mówi, że nieobecność pierwszego coś rozstrzyga.
		 *
		 * Bez tego warunku odpowiedź 200 bez `isValid` i bez `userError` szła
		 * dalej: `! empty( null )` dawało false, łagodny fallback poniżej jej nie
		 * łapał (bo wymaga niepustego `$user_err`), więc kod zapisywał do cache
		 * ZERO na 24 h i zwracał `vat_checked => true`. Krytyk 3.2 zamienia taki
		 * wynik w twardy STOP `vat_invalid` — lead był odrzucany, i to przez całą
		 * dobę, bo werdykt siedział w cache.
		 *
		 * Ta sama zasada co w agencie 3.3 przy Białej liście: „nie ustalono" to
		 * nie to samo co „ustalono, że nie".
		 *
		 * Pytamy o WERDYKT, nie o obecność klucza. Pierwsza wersja tej straży
		 * sprawdzała samo `array_key_exists()` — a `isValid: null` klucz ma, więc
		 * przechodziła i lądowała dokładnie tam, przed czym broni: `! empty( null )`
		 * dawało `false`, łagodny fallback wymaga niepustego `userError`, i pusta
		 * odpowiedź kończyła jako „numer nieważny" z zerem w cache na dobę.
		 *
		 * Użyteczny werdykt VIES to wartość logiczna. Gdyby API zaczęło kiedyś
		 * oddawać 1/0 zamiast true/false, ten warunek zdegraduje się do „nie
		 * ustalono" i ponowi próbę — czyli w stronę bezpieczną, a nie w stronę
		 * odrzucenia leada.
		 */
		if ( ! array_key_exists( 'isValid', $body ) || ! is_bool( $body['isValid'] ) ) {
			return array(
				'vat_valid'   => null,
				'vat_checked' => false,
			);
		}

		$is_valid = ! empty( $body['isValid'] );
		$user_err = isset( $body['userError'] ) ? strtoupper( (string) $body['userError'] ) : '';

		// VIES zwraca isValid=false także gdy państwo członkowskie chwilowo nie
		// odpowiada (np. MS_UNAVAILABLE/SERVICE_UNAVAILABLE/TIMEOUT). Tylko jawne
		// „INVALID" (lub brak userError) traktujemy jako realnie błędny VAT — inaczej
		// odrzucalibyśmy (i cache'owali na 24h) legalne leady w czasie awarii VIES.
		if ( ! $is_valid && '' !== $user_err && 'INVALID' !== $user_err ) {
			return array(
				'vat_valid'   => null,
				'vat_checked' => false,
				'vat_error'   => $user_err,
			);
		}

		$valid = $is_valid;
		set_transient( $cache_key, $valid ? 1 : 0, DAY_IN_SECONDS );

		return array(
			'vat_valid'   => $valid,
			'vat_checked' => true,
			'vat_source'  => 'vies',
			'vat_name'    => isset( $body['name'] ) ? $body['name'] : null,
		);
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
			return MP_Result::ok(
				array(
					'vat_valid'   => null,
					'vat_checked' => false,
				)
			);
		}

		// Tryb async (domyślny): w ścieżce żądania NIE wykonujemy HTTP. Cache-hit
		// wykorzystujemy (szybko; zachowany szybki reject cached-invalid przez K3.2),
		// a cache-miss ODKŁADAMY do weryfikatora w tle (vat_pending).
		if ( self::async_enabled() ) {
			$cached = get_transient( self::vies_cache_key( $country, $nip ) );
			if ( false !== $cached ) {
				return MP_Result::ok(
					array(
						'vat_valid'   => (bool) $cached,
						'vat_checked' => true,
						'vat_source'  => 'cache',
					)
				);
			}
			return MP_Result::ok(
				array(
					'vat_valid'   => null,
					'vat_checked' => false,
					'vat_pending' => true,
				)
			);
		}

		// Tryb synchroniczny (opt-out): pełne rozstrzygnięcie tu i teraz.
		return MP_Result::ok( self::resolve_vies( $country, $nip ) );
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
	 * Klucz cache (transient) statusu firmy z Białej listy dla NIP na dany dzień.
	 *
	 * @param string $nip  NIP (same cyfry).
	 * @param string $date Data 'Y-m-d' (domyślnie dziś, UTC).
	 * @return string
	 */
	public static function wl_cache_key( $nip, $date = '' ) {
		if ( '' === $date ) {
			// Data w strefie WITRYNY, nie w UTC. Biala lista zwraca status NA DANY DZIEN,
			// a w polskiej strefie (UTC+1/UTC+2) gmdate() miedzy polnoca a 1:00/2:00
			// wskazuje jeszcze dzien poprzedni. Firma zarejestrowana jako podatnik VAT
			// czynny od dzis dostawala wtedy status 'Niezarejestrowany' — stan na wczoraj —
			// i taki wynik byl zapisywany jako sprawdzony, razem z kluczem cache na zla dobe.
			$date = current_time( 'Y-m-d' );
		}
		return 'mp_wl_' . $nip . '_' . $date;
	}

	/**
	 * PEŁNE rozstrzygnięcie statusu firmy w Białej liście VAT: cache-albo-HTTP.
	 * Jedyne miejsce z kosztownym wywołaniem sieciowym MF — wołane synchronicznie
	 * tylko przy async OFF, a w trybie async wyłącznie przez weryfikator w tle.
	 *
	 * @param string $nip NIP.
	 * @return array Kształt: company_status (string|null), company_status_checked (bool), [source].
	 */
	public static function resolve_wl( $nip ) {
		$nip = preg_replace( '/\D+/', '', (string) $nip );
		if ( '' === $nip ) {
			return array(
				'company_status'         => null,
				'company_status_checked' => false,
			);
		}

		// Ta sama doba co w zapytaniu wyzej — inaczej klucz cache i pytanie do API
		// dotyczylyby roznych dni.
		$date      = current_time( 'Y-m-d' );
		$cache_key = self::wl_cache_key( $nip, $date );
		$cached    = get_transient( $cache_key );

		// Pusta wartość w cache to NIE trafienie. Poza tym, że kod już jej tam
		// nie wpisuje, wpisy z poprzedniej wersji mogą siedzieć w bazie jeszcze
		// przez 12 h po aktualizacji — i bez tego warunku dalej udawałyby status.
		if ( false !== $cached && '' !== trim( (string) $cached ) ) {
			return array(
				'company_status'         => $cached,
				'company_status_checked' => true,
				'company_status_source'  => 'cache',
			);
		}

		$url  = sprintf( 'https://wl-api.mf.gov.pl/api/search/nip/%s?date=%s', rawurlencode( $nip ), rawurlencode( $date ) );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return array(
				'company_status'         => null,
				'company_status_checked' => false,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return array(
				'company_status'         => null,
				'company_status_checked' => false,
			);
		}

		$status = isset( $body['result']['subject']['statusVat'] )
			? trim( (string) $body['result']['subject']['statusVat'] )
			: '';

		/*
		 * „Nie ustalono" to nie to samo co „ustalono, że nie". Dokumentacja API
		 * (docs/dzial-03/biala-lista-vat-api.md) wylicza trzy wartości
		 * `statusVat`: „Czynny", „Zwolniony" i „Niezarejestrowany" — NIP spoza
		 * wykazu dostaje więc WŁASNY status, a nie pustą odpowiedź. Brak
		 * `subject` przy HTTP 200 nie jest rozstrzygnięciem, tylko odpowiedzią
		 * niepełną, i tak trzeba ją traktować.
		 *
		 * Poprzednia wersja zwracała `checked => true` przy `status => null`
		 * i wpisywała ten null do cache na 12 h. `set_transient( key, null )`
		 * zapisuje pusty ciąg, więc `get_transient()` oddaje `''`, a warunek
		 * `false !== $cached` uznaje to za TRAFIENIE: przez pół doby każdy lead
		 * z tym NIP-em dostawał „status sprawdzony" bez statusu i nigdy nie
		 * trafiał do weryfikatora w tle. Ta sama klasa błędu co P1-C1 —
		 * wartość domyślna twierdząca więcej, niż wiemy.
		 */
		if ( '' === $status ) {
			return array(
				'company_status'         => null,
				'company_status_checked' => false,
			);
		}

		set_transient( $cache_key, $status, 12 * HOUR_IN_SECONDS );

		return array(
			'company_status'         => $status,
			'company_status_checked' => true,
			'company_status_source'  => 'wl',
		);
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$nip = preg_replace( '/\D+/', '', (string) $context->get( 'nip', '' ) );
		if ( '' === $nip ) {
			return MP_Result::ok(
				array(
					'company_status'         => null,
					'company_status_checked' => false,
				)
			);
		}

		// Tryb async (domyślny): cache-hit wykorzystujemy, miss ODKŁADAMY do tła.
		if ( MP_D3_Agent_Vat::async_enabled() ) {
			// Ten sam warunek co w resolve_wl(): pusty wpis to nie trafienie.
			$cached = get_transient( self::wl_cache_key( $nip ) );
			if ( false !== $cached && '' !== trim( (string) $cached ) ) {
				return MP_Result::ok(
					array(
						'company_status'         => $cached,
						'company_status_checked' => true,
						'company_status_source'  => 'cache',
					)
				);
			}
			return MP_Result::ok(
				array(
					'company_status'         => null,
					'company_status_checked' => false,
					'company_status_pending' => true,
				)
			);
		}

		// Tryb synchroniczny (opt-out): pełne rozstrzygnięcie tu i teraz.
		return MP_Result::ok( self::resolve_wl( $nip ) );
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
