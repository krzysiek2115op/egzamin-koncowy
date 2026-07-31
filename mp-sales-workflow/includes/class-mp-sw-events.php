<?php
/**
 * Punkt wejscia zdarzen — jedyne miejsce, ktore uruchamia pipeline.
 *
 * AJAX, cron i haki wtyczek 1/2 nie budują koperty każde po swojemu: wszystkie
 * wołają `dispatch()`. Dzięki temu Dział 1 dostaje zawsze ten sam kształt
 * danych, a różnice między wywołaniami sprowadzają się do pola `source` —
 * którego pilnuje krytyk K1.2 („kto woła").
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uruchamianie pipeline dla pojedynczego zdarzenia.
 */
class MP_SW_Events {

	/**
	 * Uruchamia pipeline dla zdarzenia.
	 *
	 * @param string $type   Typ zdarzenia ze słownika fabryki.
	 * @param array  $data   Koperta: entity, actor, lang, client, to_status...
	 * @param string $source Źródło wywołania: system / cron / manual.
	 * @return array{result:MP_SW_Result,context:MP_SW_Context}
	 */
	public static function dispatch( $type, array $data, $source = 'system' ) {
		/*
		 * Liczniki są statyczne i przeżywają jedno wywołanie PHP, a cron potrafi
		 * przetworzyć kilkadziesiąt zdarzeń pod rząd. Bez wyzerowania drugie
		 * zdarzenie odziedziczyłoby stan pierwszego i krytyk K7.3 zgłosiłby
		 * wysyłkę, której w tym przebiegu nie było.
		 */
		MP_SW_D7_Notifier::reset_mail_attempts();

		$envelope = array_merge(
			array(
				'type'     => $type,
				'source'   => $source,
				'entity'   => array(),
				'actor'    => array( 'user_id' => get_current_user_id() ),
				'lang'     => 'pl',
				'event_id' => wp_generate_uuid4(),

				/*
				 * Ślad nadajemy TUTAJ, a nie w Dziale 1, bo odmowa pochodzenia
				 * zapada przed pipeline'em — a i ona musi dać się połączyć z wpisem
				 * w dzienniku technicznym. Dział 1 istniejący ślad zachowuje.
				 */
				'trace_id' => wp_generate_uuid4(),
			),
			$data
		);

		// Zawsze nasze, nigdy z $data: wywołujący nie nadaje sobie pochodzenia.
		$envelope['type']   = (string) $type;
		$envelope['source'] = (string) $source;

		$context = new MP_SW_Context( $envelope );
		$origin  = MP_SW_Origin::check( $type, $source, $envelope );

		if ( ! $origin['ok'] ) {
			return self::refuse( $origin, $context, $envelope );
		}

		$pipeline = MP_SW_Pipeline_Factory::make( $type );
		$result   = $pipeline->run( $context );

		return array(
			'result'  => $result,
			'context' => $context,
		);
	}

	/**
	 * Zdarzenie z haka wtyczki 1 lub 2 (wywołanie wewnątrz procesu).
	 *
	 * @param string $type Typ zdarzenia.
	 * @param array  $data Koperta.
	 * @return array{result:MP_SW_Result,context:MP_SW_Context}
	 */
	public static function from_hook( $type, array $data ) {
		return self::dispatch( $type, $data, MP_SW_D1::SOURCE_SYSTEM );
	}

	/**
	 * Zdarzenie z harmonogramu.
	 *
	 * @param string $type Typ zdarzenia.
	 * @param array  $data Koperta.
	 * @return array{result:MP_SW_Result,context:MP_SW_Context}
	 */
	public static function from_cron( $type, array $data ) {
		return self::dispatch( $type, $data, MP_SW_D1::SOURCE_CRON );
	}

	/**
	 * Zdarzenie z żądania HTTP zalogowanego użytkownika.
	 *
	 * @param string $type Typ zdarzenia.
	 * @param array  $data Koperta.
	 * @return array{result:MP_SW_Result,context:MP_SW_Context}
	 */
	public static function from_http( $type, array $data ) {
		return self::dispatch( $type, $data, MP_SW_D1::SOURCE_MANUAL );
	}

	/**
	 * Odmowa pochodzenia — 403 przed jakąkolwiek pracą pipeline'u.
	 *
	 * Nie powstaje żaden kontekst zapisu, nie rusza odczyt, nie ma transakcji.
	 * Ślad zostaje wyłącznie w dzienniku technicznym (log PHP), więc scenariusz
	 * „zero zmian w bazie" jest spełniony dosłownie.
	 *
	 * @param array         $origin   Wynik kontroli pochodzenia.
	 * @param MP_SW_Context $context  Kontekst.
	 * @param array         $envelope Koperta.
	 * @return array{result:MP_SW_Result,context:MP_SW_Context}
	 */
	private static function refuse( array $origin, MP_SW_Context $context, array $envelope ) {
		$entity = isset( $envelope['entity'] ) ? (array) $envelope['entity'] : array();

		/*
		 * Zakres widoku NIE moze przezyc odmowy.
		 *
		 * Kontekst powstaje z koperty, a koperta wolno niesie `scope` — to
		 * ZADANIE zakresu, nie zakres. Przy udanym przebiegu Dzial 3 nadpisuje
		 * ten klucz wartoscia policzona z uprawnien (surowa zostaje osobno, jako
		 * `scope_requested`, i pilnuje jej krytyk „scope_from_request"). Przy
		 * odmowie Dzial 3 nie rusza — wiec bez tego czyszczenia w kontekscie
		 * zostawalaby wartosc wprost z zadania. Kazdy kod czytajacy `scope`
		 * z kontekstu bez sprawdzenia, czy zdarzenie sie powiodlo, dostalby
		 * zakres podany przez wywolujacego.
		 */
		$context->set( 'scope', '' );
		$context->set( 'scope_members', array() );

		MP_SW_Log::security(
			MP_SW_Errors::code( $origin['code'] ),
			array(
				'code'     => MP_SW_Errors::code( $origin['code'] ),
				'type'     => (string) $envelope['type'],
				'source'   => (string) $envelope['source'],
				'reason'   => (string) $origin['reason'],
				'event_id' => (string) $envelope['event_id'],
				'trace_id' => (string) $envelope['trace_id'],
				'user_id'  => get_current_user_id(),
				'lead_id'  => isset( $entity['lead_id'] ) ? (int) $entity['lead_id'] : 0,
				'offer_id' => isset( $entity['offer_id'] ) ? (int) $entity['offer_id'] : 0,
				'ip_hash'  => MP_SW_Log::ip_hash(),
			)
		);

		/*
		 * Komunikat bierzemy ze slownika kodow, a nie na sztywno: ta sama droga
		 * odmowy obsluguje dzis dwa powody — zly kanal i aktora niezgodnego
		 * z zalogowanym uzytkownikiem. Zdanie „nie moze pochodzic z tego kanalu"
		 * przy tym drugim mowiloby nieprawde i wysylaloby szukajacego w zla strone.
		 */
		$result = MP_SW_Result::fail(
			MP_SW_Errors::message( MP_SW_Errors::code( $origin['code'] ) ),
			array(
				'errors'      => array( '' !== (string) $origin['reason'] ? (string) $origin['reason'] : 'source' ),
				'http_status' => 403,
			),
			$origin['code']
		);

		return array(
			'result'  => $result,
			'context' => $context,
		);
	}

	/**
	 * Buduje POWTARZALNY klucz idempotencji z treści zdarzenia.
	 *
	 * Te same składniki dają zawsze ten sam UUID, więc powtórzone zdarzenie o tej
	 * samej treści (wtyczka wystawiła hak dwa razy, cron wrócił po to samo
	 * zadanie) odbija się o UNIQUE w rejestrze zdarzeń zamiast przejść ścieżkę
	 * drugi raz i wysłać drugi e-mail.
	 *
	 * Wynik ma postać UUID v4 nie dla ozdoby: taki kształt wymusza kontrakt
	 * Działu 1 i kolumna `char(36)` w BD-1.
	 *
	 * @param string $kind  Rodzaj zdarzenia (np. 'lead.created').
	 * @param array  $parts Składniki wyróżniające zdarzenie.
	 * @return string
	 */
	public static function derive_event_id( $kind, array $parts ) {
		$hash = md5( 'mp_sw:' . $kind . ':' . implode( ':', $parts ) );

		return sprintf(
			'%s-%s-4%s-%s%s-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			substr( $hash, 13, 3 ),
			// Wariant UUID: pierwszy znak czwartej grupy musi być 8, 9, a albo b.
			dechex( hexdec( substr( $hash, 16, 1 ) ) % 4 + 8 ),
			substr( $hash, 17, 3 ),
			substr( $hash, 20, 12 )
		);
	}

	/**
	 * Kod HTTP wynikający z odmowy.
	 *
	 * Krytycy ustawiają go przy odrzuceniu (403 / 409 / 422); brak wskazania
	 * oznacza błąd po naszej stronie, nie po stronie wywołującego.
	 *
	 * @param MP_SW_Result $result Wynik przebiegu.
	 * @return int
	 */
	public static function http_status( MP_SW_Result $result ) {
		if ( $result->is_ok() ) {
			return 200;
		}

		$data = $result->get_data();

		return isset( $data['http_status'] ) ? (int) $data['http_status'] : 500;
	}

	/**
	 * Treść odpowiedzi dla wywołującego.
	 *
	 * Przy powodzeniu oddajemy odpowiedź zbudowaną przez Dział 9 — przyciętą
	 * białą listą kluczy, więc snapshot firmy nie ma jak wyjść na zewnątrz.
	 *
	 * @param MP_SW_Result  $result  Wynik przebiegu.
	 * @param MP_SW_Context $context Kontekst przebiegu.
	 * @return array
	 */
	public static function payload( MP_SW_Result $result, MP_SW_Context $context ) {
		if ( $result->is_ok() ) {
			$response = (array) $context->get( 'response', array() );

			return empty( $response ) ? array( 'ok' => true ) : $response;
		}

		$data     = $result->get_data();
		$code     = MP_SW_Errors::code( $result->get_code() );
		$trace_id = (string) $context->get( 'trace_id', '' );

		/*
		 * Szczegółowy komunikat krytyka trafia do dziennika, nie do odpowiedzi.
		 * Wśród tych komunikatów są takie, które cytują wartość z żądania — przy
		 * odmowie „bez poprawnego adresu odbiorcy (x@y)" oznaczałoby to odesłanie
		 * adresu klienta wywołującemu. Powiązanie daje `trace_id`.
		 */
		MP_SW_Log::notice(
			$code,
			array(
				'code'     => $code,
				'type'     => (string) $context->get( 'type', '' ),
				'source'   => (string) $context->get( 'source', '' ),
				'event_id' => (string) $context->get( 'event_id', '' ),
				'trace_id' => $trace_id,
				'user_id'  => get_current_user_id(),
			)
		);

		return array(
			'ok'       => false,
			'code'     => $code,
			'message'  => MP_SW_Errors::message( $code ),
			'fields'   => isset( $data['errors'] ) ? array_values( (array) $data['errors'] ) : array(),
			'trace_id' => $trace_id,
		);
	}
}
