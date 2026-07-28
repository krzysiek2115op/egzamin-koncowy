<?php
/**
 * Macierz pochodzenia zdarzen — ktory typ wolno wywolac ktorym kanalem.
 *
 * To jest odpowiedz na najciezsza luke, jaka miala ta wtyczka: Dzial 1 pytal
 * "czy wywolanie reczne ma nonce i uprawnienie", ale NIGDY "czy ten typ w ogole
 * wolno wywolac recznie". Handlowiec z waznym tokenem i wlasnym uprawnieniem
 * mogl wyslac `type=offer.approved` z dowolnym `lead_id` i wypuscic firmowego
 * e-maila do cudzego klienta — bo LP.3 jest jedyna wtyczka w systemie, ktora
 * wykonuje ruch wychodzacy.
 *
 * Zasada: kanal HTTP obsluguje wylacznie to, co robi CZLOWIEK przy pulpicie.
 * Zdarzenia opisujace fakt z innej wtyczki (`lead.created`, `offer.approved`)
 * albo uplyw czasu (`task.due`) nie maja czego szukac w zadaniu HTTP — nikt ich
 * nie "wykonuje", one po prostu ZASZLY, a jedynym wiarygodnym swiadkiem jest
 * kod, ktory je zaobserwowal.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brama pochodzenia zdarzen.
 */
class MP_SW_Origin {

	/**
	 * Filtr wskazujacy, ze biezacy przebieg to cron.
	 *
	 * Domyslnie odpowiada `wp_doing_cron()`. Filtr istnieje po to, zeby dalo sie
	 * przetestowac OBIE strony reguly w jednym przebiegu — samo `define( 'DOING_CRON' )`
	 * jest jednorazowe i po ustawieniu nie da sie juz sprawdzic odmowy.
	 */
	const FILTER_CRON_CONTEXT = 'mp_sw_is_cron_context';

	/**
	 * Typ zdarzenia recznego przypisania.
	 *
	 * Fabryka takiego pipeline'u dzis nie buduje (przypisanie zachodzi wewnatrz
	 * `lead.created`), ale macierz opisuje go juz teraz: gdy typ powstanie,
	 * zastanie gotowa regule zamiast luki.
	 */
	const EVENT_LEAD_ASSIGN = 'lead.assign';

	/**
	 * Ktore zrodla wolno uzyc dla danego typu.
	 *
	 * @return array<string,string[]>
	 */
	public static function matrix() {
		return array(
			MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED   => array( MP_SW_D1::SOURCE_SYSTEM ),
			MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED => array( MP_SW_D1::SOURCE_SYSTEM ),
			MP_SW_Pipeline_Factory::EVENT_TASK_DUE       => array( MP_SW_D1::SOURCE_CRON ),

			/*
			 * `status.change` jest z zalozenia recznym zdarzeniem czlowieka. Zrodlo
			 * systemowe zostaje dopuszczone WASKO — wylacznie po to, zeby powstanie
			 * szkicu oferty w LP.2 przestawilo proces na `offer_draft`. Zakres tego
			 * wyjatku pilnuje `restrict()`: hak nie moze przestawic procesu nigdzie
			 * indziej, wiec obca wtyczka wolajaca `mp_offer_created` nie zamknie
			 * cudzej sprzedazy jako wygranej.
			 */
			MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE  => array( MP_SW_D1::SOURCE_MANUAL, MP_SW_D1::SOURCE_SYSTEM ),
			MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW => array( MP_SW_D1::SOURCE_MANUAL ),
			self::EVENT_LEAD_ASSIGN                      => array( MP_SW_D1::SOURCE_MANUAL ),
		);
	}

	/**
	 * Zrodla dozwolone dla typu.
	 *
	 * Typ spoza macierzy nie ma ZADNEGO dozwolonego zrodla. Domyslne "przepusc,
	 * skoro nie wiadomo" oznaczaloby, ze kazdy nowy typ zdarzenia rodzi sie
	 * otwarty na wszystkie kanaly i trzeba pamietac, zeby go zamknac.
	 *
	 * @param string $type Typ zdarzenia.
	 * @return string[]
	 */
	public static function sources_for( $type ) {
		$matrix = self::matrix();
		$type   = (string) $type;

		return isset( $matrix[ $type ] ) ? $matrix[ $type ] : array();
	}

	/**
	 * Czy dane zrodlo wolno uzyc dla tego typu.
	 *
	 * @param string $type   Typ zdarzenia.
	 * @param string $source Zrodlo wywolania.
	 * @return bool
	 */
	public static function allowed( $type, $source ) {
		return in_array( (string) $source, self::sources_for( $type ), true );
	}

	/**
	 * Czy biezacy przebieg to cron.
	 *
	 * @return bool
	 */
	public static function is_cron_context() {
		$doing = function_exists( 'wp_doing_cron' ) ? (bool) wp_doing_cron() : ( defined( 'DOING_CRON' ) && DOING_CRON );

		return (bool) apply_filters( self::FILTER_CRON_CONTEXT, $doing );
	}

	/**
	 * Sprawdza pochodzenie zdarzenia.
	 *
	 * @param string $type     Typ zdarzenia.
	 * @param string $source   Zrodlo wywolania.
	 * @param array  $envelope Koperta (do zawezenia wyjatkow).
	 * @return array{ok:bool,code:string,reason:string}
	 */
	public static function check( $type, $source, array $envelope = array() ) {
		if ( ! self::allowed( $type, $source ) ) {
			return array(
				'ok'     => false,
				'code'   => 'origin_forbidden',
				'reason' => 'channel',
			);
		}

		/*
		 * Zdarzenie timera musi RZECZYWISCIE pochodzic z crona, a nie tylko tak sie
		 * przedstawiac. Bez tej kontroli kazdy kod w procesie moglby zawolac
		 * `sweep_tasks()` i wypchnac przypomnienia poza terminem.
		 */
		if ( MP_SW_D1::SOURCE_CRON === (string) $source && ! self::is_cron_context() ) {
			return array(
				'ok'     => false,
				'code'   => 'cron_context_required',
				'reason' => 'context',
			);
		}

		return self::restrict( $type, $source, $envelope );
	}

	/**
	 * Zawezenia w obrebie dozwolonego kanalu.
	 *
	 * @param string $type     Typ zdarzenia.
	 * @param string $source   Zrodlo wywolania.
	 * @param array  $envelope Koperta.
	 * @return array{ok:bool,code:string,reason:string}
	 */
	private static function restrict( $type, $source, array $envelope ) {
		$ok = array(
			'ok'     => true,
			'code'   => '',
			'reason' => '',
		);

		if ( MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE !== (string) $type || MP_SW_D1::SOURCE_SYSTEM !== (string) $source ) {
			return $ok;
		}

		$to = isset( $envelope['to_status'] ) ? (string) $envelope['to_status'] : '';

		if ( MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT !== $to ) {
			return array(
				'ok'     => false,
				'code'   => 'origin_forbidden',
				'reason' => 'system_transition',
			);
		}

		$entity   = isset( $envelope['entity'] ) ? (array) $envelope['entity'] : array();
		$offer_id = isset( $entity['offer_id'] ) ? (int) $entity['offer_id'] : 0;

		if ( $offer_id <= 0 ) {
			// Przejscie na "oferta robocza" bez oferty jest wewnetrznie sprzeczne —
			// a wlasnie tak wygladaloby wywolanie podszywajace sie pod LP.2.
			return array(
				'ok'     => false,
				'code'   => 'origin_forbidden',
				'reason' => 'system_no_offer',
			);
		}

		return $ok;
	}
}
