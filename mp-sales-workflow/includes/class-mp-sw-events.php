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
			),
			$data
		);

		$context  = new MP_SW_Context( $envelope );
		$pipeline = MP_SW_Pipeline_Factory::make( $type );
		$result   = $pipeline->run( $context );

		return array(
			'result'  => $result,
			'context' => $context,
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

		$data = $result->get_data();

		return array(
			'ok'       => false,
			'code'     => (string) $result->get_code(),
			'message'  => implode( ' ', (array) $result->get_errors() ),
			'fields'   => isset( $data['errors'] ) ? array_values( (array) $data['errors'] ) : array(),
			'trace_id' => (string) $context->get( 'trace_id', '' ),
		);
	}
}
