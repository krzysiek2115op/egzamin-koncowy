<?php
/**
 * Fabryka pipeline — buduje 11 działów LP.1 wraz z agentami, krytykami
 * i bramkami jakości.
 *
 * TO JEST MAPA CAŁEGO PIPELINE w jednym miejscu (do przeglądu spójności).
 * Wszystkie 11 działów ma realną logikę (klasy MP_Department_01..11), każdy z
 * własnym plikiem kodu i dokumentacją źródeł w docs/dzial-NN/.
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
		$pipeline->add_department( MP_Department_10::build() );
		$pipeline->add_department( MP_Department_11::build() );

		return $pipeline;
	}
}
