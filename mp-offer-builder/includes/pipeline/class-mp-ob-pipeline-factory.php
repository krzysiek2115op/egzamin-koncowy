<?php
/**
 * Fabryka pipeline — buduje 11 działów LP.2 wraz z agentami, krytykami
 * i bramkami jakości.
 *
 * TO JEST MAPA CAŁEGO PIPELINE w jednym miejscu (do przeglądu spójności).
 * Krok 2: wszystkie 11 działów to zaślepki (MP_OB_Stub_Agent/Critic) —
 * realna logika (MP_OB_Department_01..11 z klasami biznesowymi) trafi tu
 * dopiero w kroku 3, dział po dziale.
 *
 * Reguły odwzorowane w strukturze:
 *  - każdy dział ma wielu Agentów, każdy Agent ma 1 Krytyka (para budowana niżej),
 *  - po każdym dziale 1 Bramka Jakości = dokładnie 1 QA Agent + 1 QA Krytyk.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buduje kompletny pipeline LP.2.
 */
class MP_OB_Pipeline_Factory {

	/**
	 * Tworzy gotowy pipeline z 11 działów.
	 *
	 * @return MP_OB_Pipeline
	 */
	public static function make() {
		$pipeline = new MP_OB_Pipeline( new MP_OB_Pipeline_Logger() );

		// Zapis (nagłówek + pozycje + wersja + dziennik) dzieje się w dziale 10,
		// jedną transakcją (diagram: "ZAPIS — JEDNA TRANSAKCJA", kryt. 5.1/5.5).
		// set_transactional_until(10): transakcja zamyka się DOKŁADNIE na Dziale 10 —
		// Dział 11 (zdarzenie + odpowiedź) musi widzieć już ZATWIERDZONE dane
		// (patrz docblock MP_OB_Pipeline::set_transactional_until()).
		$pipeline->set_transactional_from( 10 );
		$pipeline->set_transactional_until( 10 );

		$pipeline->add_department( MP_OB_Department_01::build() );
		$pipeline->add_department( MP_OB_Department_02::build() );
		$pipeline->add_department( MP_OB_Department_03::build() );
		$pipeline->add_department( MP_OB_Department_04::build() );
		$pipeline->add_department( MP_OB_Department_05::build() );
		$pipeline->add_department( MP_OB_Department_06::build() );
		$pipeline->add_department( MP_OB_Department_07::build() );
		$pipeline->add_department( MP_OB_Department_08::build() );
		$pipeline->add_department( MP_OB_Department_09::build() );
		$pipeline->add_department( MP_OB_Department_10::build() );
		$pipeline->add_department( MP_OB_Department_11::build() );

		return $pipeline;
	}
}
