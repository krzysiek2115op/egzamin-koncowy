<?php
/**
 * Nasluch zdarzen wtyczek 1 i 2.
 *
 * Kontrakty (potwierdzone w kodzie tamtych wtyczek):
 *  - `mp_lead_created( $lead_id, $payload )`   — P1, wystawiane po zapisie leada.
 *  - `mp_offer_created( $offer_id, $payload )` — P2, wystawiane PO SQL COMMIT,
 *    payload niesie `status => 'draft'`.
 *
 * Zatwierdzenia oferty P2 na dzis NIE wystawia wlasnym hakiem — dlatego
 * nasluchujemy `mp_offer_approved` warunkowo (jesli kiedys sie pojawi, zadziala
 * bez zmian w kodzie), a rownolegle wystawiamy wlasny, udokumentowany punkt
 * wejscia `MP_SW_Events::dispatch()`. Zaden z tych hakow nie jest wymagany do
 * dzialania wtyczki: proces da sie prowadzic recznie z pulpitu.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mostek miedzy wtyczkami 1/2 a pipeline'em.
 */
class MP_SW_Hooks {

	/** Hak wtyczki 1: powstal nowy lead. */
	const HOOK_LEAD_CREATED = 'mp_lead_created';

	/** Hak wtyczki 2: powstala oferta (status draft). */
	const HOOK_OFFER_CREATED = 'mp_offer_created';

	/** Hak wtyczki 2: oferta zatwierdzona (opcjonalny). */
	const HOOK_OFFER_APPROVED = 'mp_offer_approved';

	/**
	 * Wpina nasłuch.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK_LEAD_CREATED, array( __CLASS__, 'on_lead_created' ), 10, 2 );
		add_action( self::HOOK_OFFER_CREATED, array( __CLASS__, 'on_offer_created' ), 10, 2 );
		add_action( self::HOOK_OFFER_APPROVED, array( __CLASS__, 'on_offer_approved' ), 10, 2 );
	}

	/**
	 * Nowy lead z wtyczki 1 → proces sprzedażowy.
	 *
	 * @param int   $lead_id Identyfikator leada.
	 * @param array $payload Dane leada.
	 * @return void
	 */
	public static function on_lead_created( $lead_id, $payload = array() ) {
		$payload = is_array( $payload ) ? $payload : array();

		$envelope = array(
			'entity'   => array( 'lead_id' => (int) $lead_id ),
			// Zdarzenie z haka nie ma zalogowanego użytkownika nawet wtedy, gdy
			// powstało w panelu: aktorem jest system, a Dział 3 nie sprawdza
			// uprawnień procesu automatycznego.
			'actor'    => array( 'user_id' => 0 ),
			'client'   => array(
				'name'  => isset( $payload['company_name'] ) ? (string) $payload['company_name'] : '',
				'email' => isset( $payload['email'] ) ? (string) $payload['email'] : '',
			),
			'event_id' => MP_SW_Events::derive_event_id( 'lead.created', array( (int) $lead_id ) ),
		);

		if ( isset( $payload['country'] ) ) {
			$envelope['country'] = (string) $payload['country'];
		}

		/*
		 * `salesman_id` z wtyczki 1 to WSKAZANIE, nie rozkaz: jeśli przyjdzie,
		 * Dział 4 zachowa tego właściciela zamiast dobierać nowego. Nie zapisujemy
		 * go tutaj wprost, bo zapis należy wyłącznie do Działu 8.
		 */
		self::dispatch( MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED, $envelope );
	}

	/**
	 * Oferta robocza z wtyczki 2 → przejście w status oferta_robocza.
	 *
	 * @param int   $offer_id Identyfikator oferty.
	 * @param array $payload  Dane oferty.
	 * @return void
	 */
	public static function on_offer_created( $offer_id, $payload = array() ) {
		$payload = is_array( $payload ) ? $payload : array();
		$lead_id = isset( $payload['lead_id'] ) ? (int) $payload['lead_id'] : 0;

		if ( $lead_id <= 0 ) {
			// Bez leada nie wiemy, którego procesu dotyczy oferta. Milczymy
			// zamiast zgadywać — pomyłka przypisałaby ofertę cudzemu klientowi.
			return;
		}

		self::dispatch(
			MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
			array(
				'entity'    => array(
					'lead_id'  => $lead_id,
					'offer_id' => (int) $offer_id,
				),
				'actor'     => array( 'user_id' => 0 ),
				'to_status' => MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT,
				'event_id'  => MP_SW_Events::derive_event_id( 'offer.draft', array( $lead_id, (int) $offer_id ) ),
			)
		);
	}

	/**
	 * Zatwierdzona oferta z wtyczki 2 → wysyłka do klienta i follow-upy.
	 *
	 * @param int   $offer_id Identyfikator oferty.
	 * @param array $payload  Dane oferty.
	 * @return void
	 */
	public static function on_offer_approved( $offer_id, $payload = array() ) {
		$payload = is_array( $payload ) ? $payload : array();
		$lead_id = isset( $payload['lead_id'] ) ? (int) $payload['lead_id'] : 0;

		if ( $lead_id <= 0 ) {
			return;
		}

		self::dispatch(
			MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED,
			array(
				'entity'   => array(
					'lead_id'  => $lead_id,
					'offer_id' => (int) $offer_id,
				),
				'actor'    => array( 'user_id' => 0 ),
				'event_id' => MP_SW_Events::derive_event_id( 'offer.approved', array( $lead_id, (int) $offer_id ) ),
			)
		);
	}

	/**
	 * Uruchamia pipeline i zapisuje odmowę do dziennika PHP.
	 *
	 * Wyjątek NIE może wyjść z haka: przerwałby zapis w tamtej wtyczce, choć to
	 * nasza część zawiodła. Odmowa zostaje odnotowana i lead/oferta zapisują się
	 * u nadawcy normalnie.
	 *
	 * @param string $type     Typ zdarzenia.
	 * @param array  $envelope Koperta.
	 * @return void
	 */
	private static function dispatch( $type, array $envelope ) {
		try {
			$dispatched = MP_SW_Events::dispatch( $type, $envelope, MP_SW_D1::SOURCE_SYSTEM );
			$result     = $dispatched['result'];

			if ( ! $result->is_ok() ) {
				self::log( $type . ': ' . $result->get_code() . ' — ' . implode( '; ', (array) $result->get_errors() ) );
			}
		} catch ( \Throwable $e ) {
			self::log( $type . ': wyjątek — ' . $e->getMessage() );
		}
	}

	/**
	 * Wpis do dziennika PHP (tylko przy włączonym WP_DEBUG).
	 *
	 * @param string $message Komunikat.
	 * @return void
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[MP Sales Workflow] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
