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

	/** Filtr wtyczki 1: kto ma poprowadzić tego leada. */
	const FILTER_ASSIGN_SALESMAN = 'mp_lead_assign_salesman';

	/**
	 * Wpina nasłuch.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK_LEAD_CREATED, array( __CLASS__, 'on_lead_created' ), 10, 2 );
		add_action( self::HOOK_OFFER_CREATED, array( __CLASS__, 'on_offer_created' ), 10, 2 );
		add_action( self::HOOK_OFFER_APPROVED, array( __CLASS__, 'on_offer_approved' ), 10, 2 );
		add_filter( self::FILTER_ASSIGN_SALESMAN, array( __CLASS__, 'answer_owner' ), 10, 2 );
	}

	/**
	 * Odpowiedź na pytanie wtyczki 1: kto ma poprowadzić tego leada.
	 *
	 * Przypisanie handlowca należy do tej wtyczki — zlecenie umieszcza je w jej
	 * zakresie, a BD-1 istnieje właśnie po to, żeby powiązać użytkownika
	 * z krajem, zespołem, językiem i zakresem obsługi. Wtyczka 1 wybierała
	 * dotąd sama, haszem numeru NIP, i jeden lead kończył się dwoma różnymi
	 * handlowcami: innym w BD-3, innym w BD-1.
	 *
	 * Odpowiadamy TYM SAMYM doborem, którego użyje potem Dział 4 dla procesu
	 * (`MP_SW_D4_Assigner::decide`), na tych samych danych wejściowych. Proces
	 * jeszcze nie istnieje, więc obciążenie liczy się przed jego założeniem —
	 * dokładnie tak, jak policzy je Dział 4 chwilę później, bo pomiędzy tymi
	 * dwoma momentami nie przybywa procesów.
	 *
	 * Gdy nie ma kogo wskazać, oddajemy wartość wejściową nietkniętą. Wtyczka 1
	 * zapisze wtedy pustą kolumnę i to jest odpowiedź prawdziwa.
	 *
	 * @param int|null $current Wartość dotychczasowa (zwykle null).
	 * @param array    $lead    Dane leada istotne dla doboru.
	 * @return int|null
	 */
	public static function answer_owner( $current, $lead = array() ) {
		if ( ! class_exists( 'MP_SW_D2_Reader' ) || ! class_exists( 'MP_SW_D4_Assigner' ) ) {
			return $current;
		}

		$lead    = is_array( $lead ) ? $lead : array();
		$country = isset( $lead['country'] ) ? strtoupper( trim( (string) $lead['country'] ) ) : '';
		$lang    = isset( $lead['lang'] ) ? strtolower( trim( (string) $lead['lang'] ) ) : '';

		// Ten sam domyślny język co w kopercie zdarzenia (MP_SW_Events) — inaczej
		// odpowiedź i późniejszy dobór Działu 4 szłyby z różnych założeń.
		if ( '' === $lang ) {
			$lang = 'pl';
		}

		try {
			$inputs = MP_SW_D2_Reader::assignment_inputs();

			$decision = MP_SW_D4_Assigner::decide(
				isset( $inputs['salesmen']['complete'] ) ? (array) $inputs['salesmen']['complete'] : array(),
				isset( $inputs['workload'] ) ? (array) $inputs['workload'] : array(),
				$country,
				$lang
			);
		} catch ( \Throwable $e ) {
			/*
			 * Odpowiadamy na filtr w środku CUDZEGO pipeline'u. Wyjątek stąd
			 * wywróciłby zakładanie leada — czyli rzecz główną — z powodu
			 * niepowodzenia rzeczy pomocniczej.
			 */
			MP_SW_Log::notice( 'assign.filter_failed', array( 'message' => $e->getMessage() ) );

			return $current;
		}

		return empty( $decision['user_id'] ) ? $current : (int) $decision['user_id'];
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
			$dispatched = MP_SW_Events::from_hook( $type, $envelope );
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
