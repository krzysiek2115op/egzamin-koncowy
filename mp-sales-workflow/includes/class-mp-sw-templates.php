<?php
/**
 * Szablony powiadomień e-mail wraz z wersjami.
 *
 * Krytyk K2.5 diagramu LP.3: "Szablon bez wersji jest nieodtwarzalny w
 * dzienniku wysyłek". Wersja jest więc częścią definicji szablonu, a nie
 * dodatkiem — trafia do kolumny `template_version` w kolejce powiadomień, żeby
 * po miesiącach dało się powiedzieć, NA JAKIEJ treści powstała dana wysyłka.
 *
 * Wersjonowanie jest per szablon, nie globalne: poprawka w jednym powiadomieniu
 * nie może unieważniać historii pozostałych.
 *
 * Zrodlo (Golden Rule #2): docs/dzial-07/powiadomienia-e-mail.md
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejestr szablonów powiadomień.
 */
class MP_SW_Templates {

	/** Powiadomienie do handlowca o przypisanym procesie. */
	const TPL_LEAD_ASSIGNED = 'lead_assigned';

	/** Powiadomienie do klienta o wysłanej ofercie. */
	const TPL_OFFER_SENT = 'offer_sent';

	/** Powiadomienie do handlowca o zmianie statusu. */
	const TPL_STATUS_CHANGED = 'status_changed';

	/** Przypomnienie follow-up (d+3 / d+7) do handlowca. */
	const TPL_FOLLOWUP_DUE = 'followup_due';

	/** Filtr pozwalający witrynie nadpisać zestaw szablonów. */
	const FILTER = 'mp_sw_templates';

	/**
	 * Wbudowany zestaw szablonów.
	 *
	 * Struktura: klucz => array( 'version' => string, 'pl' => array( subject,
	 * body ), 'en' => array( subject, body ) ).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			self::TPL_LEAD_ASSIGNED  => array(
				'version' => '1.0',
				'pl'      => array(
					'subject' => 'Nowy lead: {{client_name}}',
					'body'    => "Przypisano Ci nowy proces sprzedażowy.\n\nKlient: {{client_name}}\nKraj: {{country}}\nSegment: {{segment}}\nTermin pierwszego kontaktu: {{sla_due_at}}\n\nSzczegóły: {{link}}",
				),
				'en'      => array(
					'subject' => 'New lead: {{client_name}}',
					'body'    => "A new sales process has been assigned to you.\n\nClient: {{client_name}}\nCountry: {{country}}\nSegment: {{segment}}\nFirst contact due: {{sla_due_at}}\n\nDetails: {{link}}",
				),
			),
			self::TPL_OFFER_SENT     => array(
				'version' => '1.0',
				'pl'      => array(
					'subject' => 'Oferta {{offer_number}}',
					'body'    => "Dzień dobry,\n\nw załączeniu przesyłamy ofertę nr {{offer_number}}.\n\nDokument: {{link}}\n\nPozdrawiamy\n{{salesman_name}}",
				),
				'en'      => array(
					'subject' => 'Offer {{offer_number}}',
					'body'    => "Hello,\n\nplease find our offer no. {{offer_number}}.\n\nDocument: {{link}}\n\nBest regards\n{{salesman_name}}",
				),
			),
			self::TPL_STATUS_CHANGED => array(
				'version' => '1.0',
				'pl'      => array(
					'subject' => 'Zmiana statusu: {{client_name}}',
					'body'    => "Status procesu zmienił się z {{status_from}} na {{status_to}}.\n\nKlient: {{client_name}}\nSzczegóły: {{link}}",
				),
				'en'      => array(
					'subject' => 'Status changed: {{client_name}}',
					'body'    => "The process status changed from {{status_from}} to {{status_to}}.\n\nClient: {{client_name}}\nDetails: {{link}}",
				),
			),
			self::TPL_FOLLOWUP_DUE   => array(
				'version' => '1.0',
				'pl'      => array(
					'subject' => 'Przypomnienie: {{client_name}}',
					'body'    => "Minęło {{days}} dni od wysłania oferty, a status procesu się nie zmienił.\n\nKlient: {{client_name}}\nOferta: {{offer_number}}\nSzczegóły: {{link}}",
				),
				'en'      => array(
					'subject' => 'Reminder: {{client_name}}',
					'body'    => "It has been {{days}} days since the offer was sent and the status has not changed.\n\nClient: {{client_name}}\nOffer: {{offer_number}}\nDetails: {{link}}",
				),
			),
		);
	}

	/**
	 * Pełny zestaw szablonów po uwzględnieniu nadpisań witryny.
	 *
	 * @return array
	 */
	public static function all() {
		$templates = apply_filters( self::FILTER, self::defaults() );

		return is_array( $templates ) ? $templates : self::defaults();
	}

	/**
	 * Klucze szablonów wymaganych do działania procesu.
	 *
	 * @return string[]
	 */
	public static function required_keys() {
		return array(
			self::TPL_LEAD_ASSIGNED,
			self::TPL_OFFER_SENT,
			self::TPL_STATUS_CHANGED,
			self::TPL_FOLLOWUP_DUE,
		);
	}

	/**
	 * Szablony bez wersji albo bez wersji językowej.
	 *
	 * Zwracana lista trafia do snapshotu Działu 2 i jest podstawą decyzji
	 * krytyka K2.5 — braki mają być widoczne, a nie ciche.
	 *
	 * @param array    $templates Zestaw do sprawdzenia.
	 * @param string[] $langs     Wymagane języki.
	 * @return string[] Lista opisów braków.
	 */
	public static function find_gaps( array $templates, array $langs ) {
		$gaps = array();

		foreach ( self::required_keys() as $key ) {
			if ( ! isset( $templates[ $key ] ) || ! is_array( $templates[ $key ] ) ) {
				$gaps[] = $key;
				continue;
			}

			$tpl = $templates[ $key ];

			if ( empty( $tpl['version'] ) ) {
				$gaps[] = $key . '.version';
			}

			foreach ( $langs as $lang ) {
				if ( empty( $tpl[ $lang ]['subject'] ) || empty( $tpl[ $lang ]['body'] ) ) {
					$gaps[] = $key . '.' . $lang;
				}
			}
		}

		return $gaps;
	}
}
