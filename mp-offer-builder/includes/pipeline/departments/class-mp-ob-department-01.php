<?php
/**
 * Dział 1 — Brama i kontrakt żądania.
 *
 * Zawartość pliku (1 plik = 1 dział):
 *  - Agent 1.1 (kontrakt)     — waliduje JSON żądania, whitelist kluczy
 *  - Agent 1.2 (uprawnienie)  — capability handlowca
 *  - Agent 1.3 (idempotencja) — kształt request_id
 *  - QA Agent 1               — kompletność działu
 *  - MP_OB_Department_01      — budowniczy działu
 *
 * WAŻNE (decyzja klienta 2026-07-23, patrz plugin2-architecture w pamięci projektu):
 * ten pipeline (11 działów) ma TYLKO JEDNO wejście — ręczne dokończenie oferty przez
 * handlowca (AJAX). "Automatyczne po leadzie" z blueprintu NIE uruchamia tego pipeline'u
 * wcale — zakłada tylko szkic (MP_Offer_Builder_Lead_Listener, Krok 2.5) poza nim, bo
 * payload z mp_lead_created nie zawiera pozycji. Dlatego Agent 1.2 zawsze egzekwuje
 * ścieżkę "ręczne" (capability), bez gałęzi "automatyczne".
 * Wejście ma DWA tryby: (a) nowa oferta od zera (pełny JSON client+items+wariant+lang),
 * (b) dokończenie istniejącego draftu (JSON zawiera offer_id, client dociągany ze
 * snapshotu draftu w BD-2).
 * Gwarancję "ten sam request_id nigdy nie tworzy drugiej oferty" (idempotencja)
 * egzekwuje pre-gate w class-mp-offer-builder-ajax.php PRZED uruchomieniem pipeline'u
 * (analogicznie do rate-limitu w pluginie 1) oraz UNIQUE(request_id) w Dziale 10 —
 * Agent 1.3 tu waliduje tylko KSZTAŁT (poprawny UUID), nie unikalność.
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - docs/dzial-01/json-schema-2020-12.md
 *  - docs/dzial-01/wordpress-current_user_can.md
 *  - docs/dzial-01/wordpress-check_ajax_referer.md
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 1.1 — waliduje kontrakt żądania (whitelist, dwa tryby wejścia).
 */
class MP_OB_D1_Agent_Contract extends MP_OB_Abstract_Agent {

	/** Dozwolone klucze obiektu 'client' — additionalProperties:false ręcznie (patrz docs). */
	const ALLOWED_CLIENT_KEYS = array( 'name', 'email', 'nip', 'country' );

	/** Dozwolone klucze pojedynczej pozycji. */
	const ALLOWED_ITEM_KEYS = array( 'product_id', 'variation_id', 'qty' );

	/** Dozwolone języki oferty. */
	const ALLOWED_LANGS = array( 'pl', 'en' );

	public function __construct() {
		parent::__construct( '1.1', 'Agent 1.1 — kontrakt', 'Waliduje wejściowy JSON: dane klienta, pozycje, wariant cenowy, język pl/en' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$errors = array();

		$offer_id   = (int) $context->get( 'offer_id', 0 );
		$offer_mode = 'new';
		$client     = is_array( $context->get( 'client' ) ) ? $context->get( 'client' ) : array();

		if ( $offer_id > 0 ) {
			$offer_mode = 'draft';
			$draft      = MP_Offer_Builder_DB::get_offer( $offer_id );
			if ( ! $draft || 'draft' !== $draft['status'] ) {
				$errors[] = array(
					'field'   => 'offer_id',
					'message' => 'Wskazany szkic oferty nie istnieje albo nie jest w statusie draft.',
				);
			} else {
				$client = array(
					'name'    => $draft['client_name'],
					'email'   => $draft['client_email'],
					'nip'     => $draft['client_nip'],
					'country' => $draft['client_country'],
				);
			}
		}

		// Whitelist — pola spoza schematu odrzucone jawnie (komplet błędów naraz, nie fail-fast).
		$client = array_intersect_key( $client, array_flip( self::ALLOWED_CLIENT_KEYS ) );
		foreach ( array( 'name', 'email', 'nip', 'country' ) as $key ) {
			if ( empty( $client[ $key ] ) || '' === trim( (string) $client[ $key ] ) ) {
				$errors[] = array(
					'field'   => "client.$key",
					'message' => "Pole client.$key jest wymagane.",
				);
			}
		}

		$items = is_array( $context->get( 'items' ) ) ? $context->get( 'items' ) : array();
		if ( empty( $items ) ) {
			$errors[] = array(
				'field'   => 'items',
				'message' => 'Lista pozycji nie może być pusta.',
			);
		} else {
			foreach ( $items as $i => $item ) {
				if ( ! is_array( $item ) ) {
					$errors[] = array(
						'field'   => "items.$i",
						'message' => 'Pozycja musi być obiektem.',
					);
					continue;
				}
				$item = array_intersect_key( $item, array_flip( self::ALLOWED_ITEM_KEYS ) );
				if ( empty( $item['product_id'] ) || (int) $item['product_id'] <= 0 ) {
					$errors[] = array(
						'field'   => "items.$i.product_id",
						'message' => 'product_id jest wymagany i musi być dodatnią liczbą.',
					);
				}
				if ( ! isset( $item['qty'] ) || (int) $item['qty'] <= 0 ) {
					$errors[] = array(
						'field'   => "items.$i.qty",
						'message' => 'qty jest wymagane i musi być dodatnią liczbą całkowitą.',
					);
				}
				$items[ $i ] = $item;
			}
		}

		$wariant = trim( (string) $context->get( 'wariant', '' ) );
		if ( '' === $wariant ) {
			$errors[] = array(
				'field'   => 'wariant',
				'message' => 'Pole wariant jest wymagane.',
			);
		}

		$lang = strtolower( trim( (string) $context->get( 'lang', '' ) ) );
		if ( ! in_array( $lang, self::ALLOWED_LANGS, true ) ) {
			$errors[] = array(
				'field'   => 'lang',
				'message' => 'Pole lang musi być "pl" albo "en".',
			);
		}

		if ( $errors ) {
			return MP_OB_Result::fail( 'Nieprawidłowy kontrakt żądania.', array( 'errors' => $errors ), 'invalid_contract' );
		}

		return MP_OB_Result::ok(
			array(
				'client'     => $client,
				'items'      => $items,
				'wariant'    => $wariant,
				'lang'       => $lang,
				'offer_mode' => $offer_mode,
				'offer_id'   => $offer_id > 0 ? $offer_id : null,
			)
		);
	}
}

/**
 * Krytyk 1.1 — weryfikuje strukturę wyniku agenta kontraktu.
 */
class MP_OB_D1_Critic_Contract extends MP_OB_Abstract_Critic {

	/**
	 * @param MP_OB_Result  $agent_result Wynik agenta.
	 * @param MP_OB_Context $context      Kontekst.
	 * @return MP_OB_Result
	 */
	public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
		unset( $context );

		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$data = $agent_result->get_data();
		if ( empty( $data['client'] ) || ! is_array( $data['client'] ) ) {
			return MP_OB_Result::fail( 'client musi być niepustym obiektem.', array(), 'invalid_structure' );
		}
		if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return MP_OB_Result::fail( 'items musi być niepustą listą.', array(), 'invalid_structure' );
		}

		return MP_OB_Result::ok( $data );
	}
}

/**
 * Agent 1.2 — weryfikuje uprawnienie wywołującego (zawsze ścieżka "ręczne od handlowca",
 * patrz uwaga w docblocku pliku).
 */
class MP_OB_D1_Agent_Permission extends MP_OB_Abstract_Agent {

	/** Capability wymagana do budowania ofert (rejestrowana adminowi przy aktywacji). */
	const CAPABILITY = 'mp_offer_builder_manage_offers';

	public function __construct() {
		parent::__construct( '1.2', 'Agent 1.2 — uprawnienie', 'Weryfikuje capability handlowca do budowania ofert' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		unset( $context );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return MP_OB_Result::fail( 'Brak uprawnień do budowania ofert.', array(), 'forbidden' );
		}

		return MP_OB_Result::ok( array( 'caller_authorized' => true ) );
	}
}

/**
 * Agent 1.3 — waliduje kształt request_id (UUID). Sama gwarancja idempotencji
 * (jeden request_id = jedna oferta) jest egzekwowana poza tym agentem — patrz
 * docblock pliku.
 */
class MP_OB_D1_Agent_Idempotency extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( '1.3', 'Agent 1.3 — idempotencja', 'Waliduje kształt request_id (UUID) na całe żądanie' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$request_id = (string) $context->get( 'request_id', '' );
		$valid      = (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $request_id );

		if ( ! $valid ) {
			return MP_OB_Result::fail( 'request_id musi być poprawnym UUID.', array(), 'invalid_request_id' );
		}

		return MP_OB_Result::ok( array( 'request_id' => $request_id ) );
	}
}

/**
 * QA Agent 1 — kontrola kompletności działu (pozycje + wariant + język).
 */
class MP_OB_D1_QA_Agent extends MP_OB_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA1', 'QA Agent 1 — kontrola kompletności', 'Sprawdza komplet wejścia: pozycje + wariant + język' );
	}

	/**
	 * @param MP_OB_Context $context Kontekst.
	 * @return MP_OB_Result
	 */
	public function run( MP_OB_Context $context ) {
		$missing = array();
		if ( empty( $context->get( 'items' ) ) ) {
			$missing[] = 'items';
		}
		if ( '' === trim( (string) $context->get( 'wariant', '' ) ) ) {
			$missing[] = 'wariant';
		}
		if ( '' === trim( (string) $context->get( 'lang', '' ) ) ) {
			$missing[] = 'lang';
		}

		if ( $missing ) {
			return MP_OB_Result::fail(
				'Niekompletne dane działu 1: ' . implode( ', ', $missing ),
				array( 'missing' => $missing ),
				'incomplete'
			);
		}

		return MP_OB_Result::ok( array( 'd1_complete' => true ) );
	}
}

/**
 * Budowniczy działu 1.
 */
class MP_OB_Department_01 {

	/**
	 * @return MP_OB_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_OB_D1_Agent_Contract(),
				'critic' => new MP_OB_D1_Critic_Contract( 'K1.1', 'Krytyk 1.1 — schemat-wejścia' ),
			),
			array(
				'agent'  => new MP_OB_D1_Agent_Permission(),
				'critic' => new MP_OB_Flag_Critic( 'K1.2', 'Krytyk 1.2 — kto-woła', 'caller_authorized' ),
			),
			array(
				'agent'  => new MP_OB_D1_Agent_Idempotency(),
				'critic' => new MP_OB_Field_Critic( 'K1.3', 'Krytyk 1.3 — klucz-idempotencji', 'request_id' ),
			),
		);

		$gate = new MP_OB_Quality_Gate(
			new MP_OB_D1_QA_Agent(),
			new MP_OB_Accept_Critic( 'QAK1', 'QA Krytyk 1 — akceptuje lub odrzuca' )
		);

		return new MP_OB_Department(
			1,
			'gate-contract',
			'Brama i kontrakt żądania',
			'Wejście tylko JSON: walidacja schematu, uprawnień wywołania i klucza idempotencji.',
			$pairs,
			$gate
		);
	}
}
