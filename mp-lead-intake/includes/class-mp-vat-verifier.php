<?php
/**
 * Weryfikacja VIES / Biała lista VAT w TLE (poza ścieżką żądania klienta).
 *
 * Cel (P-1 z DEBUG-RAPORT): kosztowne, synchroniczne calle HTTP działu 3 (VIES +
 * Biała lista, 8 s timeout każdy) trzymały worker PHP-FPM do ~16 s na cache-miss.
 * Tu przenosimy je poza żądanie: dział 3 tworzy leada jako 'pending', a ten moduł
 * dorabia weryfikację i koryguje scoring asynchronicznie (natywny WP-Cron).
 *
 * To NIE jest element pipeline (nie dodaje działu/pary agent-krytyk) — to warstwa
 * infrastruktury zdarzeniowej, analogiczna do retencji IP (RODO).
 *
 * Idempotencja i brak wyścigu: atomowy „claim" leada (pending → verifying) w BD-3.
 * Siatka bezpieczeństwa: dzienny reconcile odblokowuje zawieszone i dokolejkowuje
 * zaległe (gdy WP-Cron nie wystartował lub worker padł w trakcie).
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asynchroniczny weryfikator VAT/statusu firmy.
 */
class MP_Lead_Intake_Vat_Verifier {

	/** Hook pojedynczego zadania weryfikacji (arg: lead_id). */
	const VERIFY_HOOK = 'mp_lead_intake_verify_vat';

	/** Hook cyklicznego reconcile (siatka bezpieczeństwa). */
	const RECONCILE_HOOK = 'mp_lead_intake_vat_reconcile';

	/** Maks. liczba prób weryfikacji zanim status → 'unknown'. */
	const MAX_ATTEMPTS = 5;

	/** Po ilu minutach 'verifying' uznajemy za zawieszone (worker padł). */
	const STUCK_MINUTES = 15;

	/**
	 * Rejestruje hooki: kolejkowanie po utworzeniu leada, wykonanie zadania,
	 * cykliczny reconcile.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'mp_lead_created', array( __CLASS__, 'on_lead_created' ), 10, 2 );
		add_action( self::VERIFY_HOOK, array( __CLASS__, 'run' ), 10, 1 );
		add_action( self::RECONCILE_HOOK, array( __CLASS__, 'reconcile' ) );
	}

	/**
	 * Czy tryb async jest włączony (współdzielony przełącznik z działem 3).
	 *
	 * @return bool
	 */
	public static function async_enabled() {
		return MP_D3_Agent_Vat::async_enabled();
	}

	/**
	 * Reakcja na utworzenie leada: kolejkuje weryfikację tylko dla 'pending'.
	 *
	 * @param int   $lead_id Identyfikator leada.
	 * @param array $payload Wąski payload z działu 11 (zawiera vat_status).
	 * @return void
	 */
	public static function on_lead_created( $lead_id, $payload = array() ) {
		if ( ! self::async_enabled() ) {
			return;
		}
		$lead_id = absint( $lead_id );
		if ( $lead_id <= 0 ) {
			return;
		}

		$vat_status = ( is_array( $payload ) && isset( $payload['vat_status'] ) ) ? (string) $payload['vat_status'] : '';
		if ( '' === $vat_status ) {
			// Fallback, gdyby payload nie niósł statusu — dociągnij z bazy.
			$lead       = MP_Lead_Intake_DB::get_lead( $lead_id );
			$vat_status = $lead && isset( $lead['vat_status'] ) ? (string) $lead['vat_status'] : '';
		}
		if ( 'pending' !== $vat_status ) {
			return;
		}

		self::enqueue( $lead_id );
	}

	/**
	 * Planuje pojedyncze zadanie weryfikacji (dedup — bez duplikatów w kolejce).
	 *
	 * @param int $lead_id Identyfikator leada.
	 * @return void
	 */
	public static function enqueue( $lead_id ) {
		$lead_id = absint( $lead_id );
		if ( $lead_id <= 0 ) {
			return;
		}
		$args = array( $lead_id );
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::VERIFY_HOOK, $args ) ) {
			return;
		}
		wp_schedule_single_event( time() + 1, self::VERIFY_HOOK, $args );
	}

	/**
	 * Wykonuje weryfikację VAT/statusu dla jednego leada (uruchamiane przez WP-Cron).
	 *
	 * @param int $lead_id Identyfikator leada.
	 * @return void
	 */
	public static function run( $lead_id ) {
		$lead_id = absint( $lead_id );
		if ( $lead_id <= 0 ) {
			return;
		}

		// Atomowy claim: pending → verifying. 0 wierszy = ktoś już przejął / nie 'pending'
		// (idempotencja przy równoległych cronach). Kończymy bez efektu ubocznego.
		if ( MP_Lead_Intake_DB::claim_vat_verification( $lead_id ) < 1 ) {
			return;
		}

		$lead = MP_Lead_Intake_DB::get_lead( $lead_id );
		if ( ! $lead || ! empty( $lead['deleted_at'] ) ) {
			return;
		}

		$nip     = preg_replace( '/\D+/', '', (string) ( isset( $lead['nip'] ) ? $lead['nip'] : '' ) );
		$country = strtoupper( (string) ( isset( $lead['country'] ) ? $lead['country'] : 'PL' ) );
		if ( '' === $country ) {
			$country = 'PL';
		}

		// Kosztowne HTTP — teraz POZA ścieżką żądania klienta. Te same fetchery co
		// dział 3 (wspólne cache i łagodny fallback).
		$vies = MP_D3_Agent_Vat::resolve_vies( $country, $nip );
		$wl   = MP_D3_Agent_Company_Status::resolve_wl( $nip );

		$vat_checked = ! empty( $vies['vat_checked'] );
		$vat_valid   = self::scal_vat_valid( $vies, isset( $lead['vat_valid'] ) ? $lead['vat_valid'] : null );
		$wl_checked  = ! empty( $wl['company_status_checked'] );
		// Gdy Biała lista akurat nie odpowie, NIE nadpisujemy wcześniej ustalonego
		// statusu (i jego wkładu w scoring) wartością null — audyt wykazał, że to
		// prowadziło do cichej, trwałej utraty +20 pkt przy VIES-up/WL-down.
		$company_status = $wl_checked
			? ( array_key_exists( 'company_status', $wl ) ? $wl['company_status'] : null )
			: ( isset( $lead['company_status'] ) ? $lead['company_status'] : null );
		$attempts       = (int) ( isset( $lead['vat_attempts'] ) ? $lead['vat_attempts'] : 0 );

		// Re-scoring TYM SAMYM scorerem co dział 7.2 (sygnały z wiersza leada + świeży VAT).
		$score = MP_Lead_Scoring::calculate(
			array(
				'vat_valid'         => $vat_valid,
				'company_status'    => $company_status,
				'phone'             => isset( $lead['phone'] ) ? $lead['phone'] : '',
				'consent_marketing' => isset( $lead['consent_marketing'] ) ? $lead['consent_marketing'] : 0,
				'segment'           => isset( $lead['segment'] ) ? $lead['segment'] : null,
			)
		);

		if ( $vat_checked ) {
			/*
			 * VIES rozstrzygnął jednoznacznie.
			 *
			 * F2 (bramka integracyjna): rozróżniamy POTWIERDZONY ważny numer
			 * ('valid') od sprawdzenia, które ważności nie potwierdziło
			 * ('checked' — np. lead spoza UE, gdzie VIES nie ma czego sprawdzać).
			 * Wcześniej oba przypadki dawały 'checked', więc wtyczka 2 nie miała
			 * jak odróżnić leada z ważnym VAT UE od pozostałych i odwrotne
			 * obciążenie (0% VAT, art. 196 dyrektywy 2006/112/WE) było ze ścieżki
			 * leada NIEOSIĄGALNE — każda oferta dostawała stawkę krajową.
			 *
			 * Odpowiedź „ważny" siedziała w osobnej kolumnie `vat_valid`, ale nie
			 * wychodziła na zewnątrz w statusie; teraz wychodzi.
			 */
			if ( false === $vat_valid ) {
				$new_status = 'invalid';
			} elseif ( true === $vat_valid ) {
				$new_status = 'valid';
			} else {
				$new_status = 'checked';
			}
		} else {
			// Nierozstrzygnięte (VIES/MF niedostępne): ponów do limitu, potem poddaj się.
			$new_status = ( $attempts >= self::MAX_ATTEMPTS ) ? 'unknown' : 'pending';
		}

		// GMT (nie lokalny czas WP) — spójne z updated_at (class-mp-db.php) w tej samej
		// tabeli; mieszanie stref w kolumnach datetime tej samej tabeli jest myłące przy
		// przyszłych porównaniach/raportach (audyt 2026-07-22, częściowy nawrót błędu GMT).
		$fields = array(
			'vat_valid'      => is_null( $vat_valid ) ? null : ( $vat_valid ? 1 : 0 ),
			'company_status' => $company_status,
			'score'          => (int) $score,
			'vat_status'     => $new_status,
			'vat_checked_at' => $vat_checked ? current_time( 'mysql', true ) : null,
		);

		// Zły VAT: domyślnie ZOSTAWIAMY leada + flaga 'invalid' (handlowiec decyduje).
		// Filtr może przełączyć na twarde odrzucenie (soft-delete).
		if ( 'invalid' === $new_status && apply_filters( 'mp_lead_intake_reject_invalid_vat', false, $lead_id, $lead ) ) {
			$fields['deleted_at'] = current_time( 'mysql', true );
		}

		MP_Lead_Intake_DB::update_vat_result( $lead_id, $fields );

		// Log audytowy weryfikacji (bez IP — operacja serwerowa, nie żądanie klienta).
		MP_Lead_Intake_DB::insert_activity(
			array(
				'lead_id'     => $lead_id,
				'action'      => 'vat_verified',
				'description' => sprintf( 'Weryfikacja VAT/statusu w tle: %s', $new_status ),
				'user_id'     => null,
				'ip_address'  => null,
				'meta_json'   => wp_json_encode(
					array(
						'vat_valid'      => $vat_valid,
						'company_status' => $company_status,
						'score'          => (int) $score,
						'vat_source'     => isset( $vies['vat_source'] ) ? $vies['vat_source'] : null,
						'attempts'       => $attempts,
					)
				),
			)
		);

		// Sygnał integracyjny dla pluginów 2/3 (proces formularz → oferta).
		do_action( 'mp_lead_verified', $lead_id, $fields );

		// Nadal nierozstrzygnięte i poniżej limitu → zaplanuj ponowną próbę (WP-Cron
		// naturalnie throttluje częstotliwość).
		if ( 'pending' === $new_status ) {
			self::enqueue( $lead_id );
		}
	}

	/**
	 * Reconcile (cyklicznie): odblokowuje zawieszone weryfikacje i dokolejkowuje
	 * zaległe 'pending'. Siatka bezpieczeństwa na wypadek nieodpalonego WP-Cron
	 * lub workera, który padł w trakcie.
	 *
	 * @return void
	 */
	public static function reconcile() {
		if ( ! self::async_enabled() ) {
			return;
		}
		MP_Lead_Intake_DB::reset_stuck_vat( self::STUCK_MINUTES );

		$rows = MP_Lead_Intake_DB::get_leads_needing_vat( 100 );
		foreach ( $rows as $row ) {
			if ( isset( $row['id'] ) ) {
				self::enqueue( (int) $row['id'] );
			}
		}
	}

	/**
	 * Scala swiezy wynik VIES z tym, co juz wiadomo o ledzie.
	 *
	 * Gdy VIES nie odpowiedzial, wynik jest NIEWIADOMA — a niewiadoma nie ma
	 * prawa skasowac faktu ustalonego wczesniej. Bez tego potwierdzone
	 * `vat_valid = 1` bylo nadpisywane przez `null` przy pierwszej nieudanej
	 * probie w tle: scoring tracil 30 punktow, `vat_checked_at` szlo na NULL,
	 * a po wyczerpaniu prob status ladowal na `unknown`. Wtyczka 2 nigdy nie
	 * zobaczyla juz `valid`, wiec odwrotne obciazenie (art. 196) stawalo sie
	 * nieosiagalne. Cicho i nieodwracalnie.
	 *
	 * Biala lista miala ten fallback od czasu poprzedniego audytu — VIES nie,
	 * mimo ze problem jest dokladnie ten sam. Naprawiajac klase bledu, trzeba
	 * przejsc WSZYSTKIE jej wystapienia.
	 *
	 * @param array $vies      Wynik fetchera VIES.
	 * @param mixed $poprzedni Wartosc `vat_valid` z wiersza leada (moze byc '1'/'0'/null).
	 * @return bool|null
	 */
	public static function scal_vat_valid( array $vies, $poprzedni ) {
		if ( ! empty( $vies['vat_checked'] ) && array_key_exists( 'vat_valid', $vies ) ) {
			return $vies['vat_valid'];
		}

		// Kolumna w bazie trzyma 1/0/NULL, a scoring porownuje SCISLE z `true`.
		if ( null === $poprzedni || '' === $poprzedni ) {
			return null;
		}

		return (bool) (int) $poprzedni;
	}
}
