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

		// Działy z realną logiką (krok 3).
		$pipeline->add_department( MP_Department_01::build() );
		$pipeline->add_department( MP_Department_02::build() );
		$pipeline->add_department( MP_Department_03::build() );
		$pipeline->add_department( MP_Department_04::build() );
		$pipeline->add_department( MP_Department_05::build() );
		$pipeline->add_department( MP_Department_06::build() );
		$pipeline->add_department( MP_Department_07::build() );
		$pipeline->add_department( MP_Department_08::build() );
		$pipeline->add_department( MP_Department_09::build() );

		// Działy 10–11 — na razie zaślepki (kolejne kroki 3).
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
