<?php
/**
 * Fabryka pipeline — buduje 11 działów LP.1 wraz z agentami, krytykami
 * i bramkami jakości.
 *
 * TO JEST MAPA CAŁEGO PIPELINE w jednym miejscu (do przeglądu spójności).
 * Na etapie kroku 2 agenci/krytycy to zaślepki (MP_Stub_*). W kroku 3
 * podmienimy je na konkretne klasy z realnymi zadaniami oraz dopiszemy
 * dokumentację (1 dokument na dział). Nazwy zadań tutaj to wstępna propozycja
 * — dostroimy je w kroku 3, żeby były spójne z dokumentacją.
 *
 * Reguły odwzorowane w strukturze:
 *  - każdy dział ma wielu Agentów, każdy Agent ma 1 Krytyka (para budowana niżej),
 *  - po każdym dziale 1 Bramka Jakości = dokładnie 1 QA Agent + 1 QA Krytyk.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buduje kompletny pipeline LP.1.
 */
class MP_Pipeline_Factory {

	/**
	 * Tworzy gotowy pipeline z 11 działów.
	 *
	 * @return MP_Pipeline
	 */
	public static function make() {
		$pipeline = new MP_Pipeline( new MP_Pipeline_Logger() );

		foreach ( self::definitions() as $def ) {
			$pairs = array();

			foreach ( $def['agents'] as $agent ) {
				// Każdemu Agentowi automatycznie towarzyszy 1 Krytyk.
				$pairs[] = array(
					'agent'  => new MP_Stub_Agent( $agent['id'], $agent['label'], $agent['purpose'] ),
					'critic' => new MP_Stub_Critic(
						'K' . $agent['id'],
						sprintf( 'Krytyk %s — weryfikuje: %s', $agent['id'], $agent['label'] )
					),
				);
			}

			// Bramka jakości po dziale: 1 QA Agent + 1 QA Krytyk.
			$gate = new MP_Quality_Gate(
				new MP_Stub_Agent(
					'QA' . $def['number'],
					sprintf( 'QA Agent %d — kontrola jakości działu', $def['number'] ),
					'Sprawdza kompletność i poprawność wyniku całego działu'
				),
				new MP_Stub_Critic(
					'QAK' . $def['number'],
					sprintf( 'QA Krytyk %d — akceptuje lub odrzuca', $def['number'] )
				)
			);

			$pipeline->add_department(
				new MP_Department( $def['number'], $def['key'], $def['label'], $def['description'], $pairs, $gate )
			);
		}

		return $pipeline;
	}

	/**
	 * Definicje 11 działów LP.1 (numer, klucz, nazwa, opis, agenci).
	 *
	 * @return array
	 */
	private static function definitions() {
		return array(
			array(
				'number'      => 1,
				'key'         => 'fetch-data',
				'label'       => 'Pobranie danych z bazy (BD-3)',
				'description' => 'Pobranie wszystkich niezbędnych danych z BD-3 jednym strzałem (1 AJAX).',
				'agents'      => array(
					array( 'id' => '1.1', 'label' => 'Pobiera leady', 'purpose' => 'Odczyt istniejących leadów z wp_mp_leads' ),
					array( 'id' => '1.2', 'label' => 'Pobiera oferty', 'purpose' => 'Odczyt istniejących ofert z wp_mp_offers' ),
					array( 'id' => '1.3', 'label' => 'Pobiera historię aktywności', 'purpose' => 'Odczyt wpisów z wp_mp_activity_log' ),
				),
			),
			array(
				'number'      => 2,
				'key'         => 'validate-form',
				'label'       => 'Walidacja formularza (wstępna)',
				'description' => 'Sprawdzenie struktury, wymaganych pól i formatów danych.',
				'agents'      => array(
					array( 'id' => '2.1', 'label' => 'Sprawdza wymagane pola', 'purpose' => 'Weryfikacja obecności pól obowiązkowych' ),
					array( 'id' => '2.2', 'label' => 'Normalizuje dane', 'purpose' => 'Trim, ujednolicenie formatu (telefon, NIP)' ),
					array( 'id' => '2.3', 'label' => 'Waliduje formaty', 'purpose' => 'Poprawność e-maila i innych formatów' ),
				),
			),
			array(
				'number'      => 3,
				'key'         => 'nip-vat',
				'label'       => 'Sprawdzenie NIP / VAT',
				'description' => 'Weryfikacja NIP/VAT UE i statusu firmy (unikalność firmy).',
				'agents'      => array(
					array( 'id' => '3.1', 'label' => 'Weryfikuje NIP', 'purpose' => 'Suma kontrolna NIP (offline)' ),
					array( 'id' => '3.2', 'label' => 'Weryfikuje VAT UE', 'purpose' => 'Sprawdzenie w VIES (z cache i timeoutem)' ),
					array( 'id' => '3.3', 'label' => 'Sprawdza status firmy', 'purpose' => 'Status aktywności podatnika' ),
				),
			),
			array(
				'number'      => 4,
				'key'         => 'country-segment',
				'label'       => 'Przypisanie kraju i segmentu',
				'description' => 'Automatyczne lub ręczne przypisanie kraju, segmentu i kategorii klienta.',
				'agents'      => array(
					array( 'id' => '4.1', 'label' => 'Ustala kraj', 'purpose' => 'Kraj na podstawie NIP/VAT/danych' ),
					array( 'id' => '4.2', 'label' => 'Przypisuje segment', 'purpose' => 'Segment wg reguł (słownik branż)' ),
					array( 'id' => '4.3', 'label' => 'Dobiera kategorię klienta', 'purpose' => 'Kategoria (np. B2B) wg reguł' ),
				),
			),
			array(
				'number'      => 5,
				'key'         => 'secure-form',
				'label'       => 'Zabezpieczenie formularza',
				'description' => 'Zabezpieczenia formularza przed nadużyciami.',
				'agents'      => array(
					array( 'id' => '5.1', 'label' => 'Antyspam', 'purpose' => 'Honeypot / heurystyki antyspamowe' ),
					array( 'id' => '5.2', 'label' => 'Sprawdza CSRF', 'purpose' => 'Weryfikacja nonce WordPress' ),
					array( 'id' => '5.3', 'label' => 'Rate limit', 'purpose' => 'Ograniczenie liczby zgłoszeń w czasie' ),
				),
			),
			array(
				'number'      => 6,
				'key'         => 'consents',
				'label'       => 'Zapis zgód',
				'description' => 'Zapisanie wszystkich wymaganych zgód wraz z datą i wersją.',
				'agents'      => array(
					array( 'id' => '6.1', 'label' => 'Zgoda marketingowa', 'purpose' => 'Odczyt/zapis zgody marketingowej' ),
					array( 'id' => '6.2', 'label' => 'Zgoda RODO', 'purpose' => 'Odczyt/zapis zgody RODO' ),
					array( 'id' => '6.3', 'label' => 'Data i wersja zgody', 'purpose' => 'Znacznik czasu + wersja treści zgody' ),
				),
			),
			array(
				'number'      => 7,
				'key'         => 'create-lead',
				'label'       => 'Utworzenie leada',
				'description' => 'Utworzenie leada w wp_mp_leads (BD-3).',
				'agents'      => array(
					array( 'id' => '7.1', 'label' => 'Sprawdza unikalność firmy', 'purpose' => 'Dedup po NIP (brak duplikatów)' ),
					array( 'id' => '7.2', 'label' => 'Przygotowuje dane leada', 'purpose' => 'Zebranie i mapowanie pól leada' ),
					array( 'id' => '7.3', 'label' => 'Tworzy lead w BD-3', 'purpose' => 'INSERT do wp_mp_leads (w transakcji)' ),
				),
			),
			array(
				'number'      => 8,
				'key'         => 'activity-log',
				'label'       => 'Zapis historii aktywności',
				'description' => 'Zapisanie operacji w wp_mp_activity_log (BD-3).',
				'agents'      => array(
					array( 'id' => '8.1', 'label' => 'Przygotowuje wpis logu', 'purpose' => 'Budowa rekordu zdarzenia' ),
					array( 'id' => '8.2', 'label' => 'Dodaje meta informacje', 'purpose' => 'IP, user_id, meta_json' ),
					array( 'id' => '8.3', 'label' => 'Zapisuje log w BD-3', 'purpose' => 'INSERT do wp_mp_activity_log' ),
				),
			),
			array(
				'number'      => 9,
				'key'         => 'start-process',
				'label'       => 'Rozpoczęcie procesu',
				'description' => 'Rozpoczęcie procesu obsługi leada (etap początkowy).',
				'agents'      => array(
					array( 'id' => '9.1', 'label' => 'Inicjuje proces', 'purpose' => 'Nadanie identyfikatora procesu' ),
					array( 'id' => '9.2', 'label' => 'Ustala etap początkowy', 'purpose' => 'Stage = lead_intake' ),
					array( 'id' => '9.3', 'label' => 'Zapisuje stan procesu', 'purpose' => 'Zapis znacznika startu' ),
				),
			),
			array(
				'number'      => 10,
				'key'         => 'return-result',
				'label'       => 'Zwrócenie wyniku do pluginu',
				'description' => 'Zwrócenie ostatecznego wyniku do wtyczki (MP Lead Intake).',
				'agents'      => array(
					array( 'id' => '10.1', 'label' => 'Buduje odpowiedź', 'purpose' => 'Złożenie wyniku (success, lead_id)' ),
					array( 'id' => '10.2', 'label' => 'Dodaje podsumowanie', 'purpose' => 'Status + komunikat dla pluginu' ),
					array( 'id' => '10.3', 'label' => 'Finalizuje payload', 'purpose' => 'Ostateczny JSON zwrotny' ),
				),
			),
			array(
				'number'      => 11,
				'key'         => 'finish',
				'label'       => 'Zakończenie pipeline',
				'description' => 'Zamknięcie pipeline i raport końcowy.',
				'agents'      => array(
					array( 'id' => '11.1', 'label' => 'Zamyka transakcję', 'purpose' => 'Commit/rollback transakcji BD-3' ),
					array( 'id' => '11.2', 'label' => 'Czyści stan tymczasowy', 'purpose' => 'Sprzątanie danych roboczych' ),
					array( 'id' => '11.3', 'label' => 'Generuje raport końcowy', 'purpose' => 'Status pipeline, czas trwania' ),
				),
			),
		);
	}
}
