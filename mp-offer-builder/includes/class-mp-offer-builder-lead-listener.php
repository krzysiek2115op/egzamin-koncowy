<?php
/**
 * Subskrybent hooka mp_lead_created (plugin 1) — automatyczny szkic oferty.
 *
 * Plugin 1 (mp-lead-intake/includes/pipeline/departments/class-mp-department-11.php,
 * Agent 11.3) emituje `do_action( 'mp_lead_created', $lead_id, $payload )` PO KAŻDYM
 * udanym przebiegu swojego pipeline'u — w tym przy reaktywacji istniejącego,
 * zarchiwizowanego leada (ten sam $lead_id może więc wystąpić więcej niż raz).
 * $payload = lead_id, company_name, nip, email, phone, country, segment,
 * client_category, score, status, vat_status, salesman_id oraz — od wersji 1.2.2
 * pluginu 1 — products i est_volume.
 *
 * `products` / `est_volume` to WOLNY TEKST z formularza ("Produkty / zakres
 * zainteresowania", "Przewidywany wolumen"), a nie pozycje oferty: nie ma tam
 * product_id ani ilości, które dałoby się wycenić. Dlatego nadal NIE uruchamiamy
 * tu pełnego 11-działowego pipeline'u — zakładamy wiersz-szkic (status=draft) ze
 * snapshotem klienta I z zapisaną prośbą klienta, a handlowiec dokańcza ofertę
 * ręcznie (wybór produktów → MP_Offer_Builder_Ajax → pełny pipeline). Decyzja
 * klienta 2026-07-23, patrz plugin2-architecture w pamięci projektu / plan P-2,
 * Krok 2.5.
 *
 * Oba pola są OPCJONALNE: starsza wersja pluginu 1 ich nie wysyła, a klient nie
 * musi ich wypełnić (nie są wymagane w formularzu). Ich brak nie może przeszkodzić
 * w założeniu szkicu — lead bez opisu produktów jest wciąż leadem.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nasłuch zdarzenia integracyjnego z pluginem 1.
 */
class MP_Offer_Builder_Lead_Listener {

	/** Nazwa hooka emitowanego przez plugin 1. */
	const HOOK = 'mp_lead_created';

	/** Hook wyniku weryfikacji VAT (plugin 1, worker w tle). */
	const HOOK_VERIFIED = 'mp_lead_verified';

	/**
	 * Rejestruje subskrybenta.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'on_lead_created' ), 10, 2 );
		add_action( self::HOOK_VERIFIED, array( __CLASS__, 'on_lead_verified' ), 10, 2 );
	}

	/**
	 * Aktualizuje snapshot VAT w szkicu po weryfikacji w tle.
	 *
	 * F2 (bramka integracyjna). Kolejność zdarzeń jest tu kluczowa i łatwo ją
	 * przeoczyć: dla leada z UE plugin 1 zapisuje `vat_status = 'pending'` i
	 * DOPIERO POTEM, asynchronicznie, odpytuje VIES. Szkic oferty powstaje w
	 * chwili `mp_lead_created`, czyli ZANIM odpowiedź dotrze — i bez tego
	 * nasłuchu zostawał ze statusem „pending" na zawsze. Skutek: Dział 6 nigdy
	 * nie widział ważnego VAT UE i naliczał stawkę krajową zamiast odwrotnego
	 * obciążenia, choć plugin 1 dawno znał odpowiedź.
	 *
	 * Aktualizujemy WYŁĄCZNIE szkice. Oferta zatwierdzona ma stawkę utrwaloną
	 * razem z podstawą prawną i dokumentem PDF — zmiana stawki pod już wysłanym
	 * dokumentem byłaby fałszowaniem historii.
	 *
	 * @param int   $lead_id ID leada.
	 * @param array $fields  Pola zaktualizowane przez weryfikator (m.in. vat_status).
	 * @return bool Czy szkic został zaktualizowany.
	 */
	public static function on_lead_verified( $lead_id, $fields ) {
		global $wpdb;

		$lead_id = (int) $lead_id;
		$fields  = is_array( $fields ) ? $fields : array();

		if ( $lead_id <= 0 || ! isset( $fields['vat_status'] ) ) {
			return false;
		}

		$vat_status = substr( sanitize_text_field( (string) $fields['vat_status'] ), 0, 20 );

		if ( '' === $vat_status ) {
			return false;
		}

		$offers_table = MP_Offer_Builder_DB::offers_table();

		$draft_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$offers_table} WHERE lead_id = %d AND status = 'draft' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lead_id
			)
		);

		if ( $draft_id <= 0 ) {
			// Brak szkicu to normalna sytuacja: handlowiec mógł już dokończyć
			// ofertę albo lead nie doczekał się draftu. Nic do zrobienia.
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$offers_table,
			array( 'client_vat_status' => $vat_status ),
			array(
				'id'     => $draft_id,
				'status' => 'draft',
			)
		);

		if ( ! $updated ) {
			return false;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MP_Offer_Builder_DB::activity_log_table(),
			array(
				'offer_id'    => $draft_id,
				'action'      => 'draft_vat_status_updated',
				'description' => sprintf( 'Status VAT szkicu zaktualizowany po zdarzeniu mp_lead_verified (lead_id=%d, vat_status=%s).', $lead_id, $vat_status ),
			)
		);

		return true;
	}

	/**
	 * Zakłada szkic oferty dla nowo utworzonego/reaktywowanego leada.
	 *
	 * @param int   $lead_id ID leada (BD-3, plugin 1).
	 * @param array $payload Dane leada — patrz kontrakt w docblocku klasy.
	 * @return int|false ID draftu (nowego albo już istniejącego), albo false przy błędzie zapisu.
	 */
	public static function on_lead_created( $lead_id, $payload ) {
		global $wpdb;

		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 ) {
			return false;
		}

		// S2-01: sekcja krytyczna check+insert serializowana per-lead. Tabela ofert ma
		// tylko KEY lead_id (nie UNIQUE) — bez blokady dwa równoległe mp_lead_created dla
		// tego samego leada (np. szybka reaktywacja) mogłyby OBA minąć kontrolę
		// "czy draft istnieje" i OBA wstawić szkic (duplikat). GET_LOCK nazwany, per-lead,
		// serializuje je; przy timeout/awarii blokady kontynuujemy best-effort (sama
		// kontrola i tak łapie zdecydowaną większość duplikatów).
		$lock_name = 'mp_ob_lead_' . $lead_id;
		// SR2-04: zapamiętaj czy blokada REALNIE zdobyta (1). Przy timeoutcie 5s/awarii
		// kontynuujemy best-effort (sam check "czy draft istnieje" łapie większość
		// duplikatów; utrata leada byłaby gorsza niż rzadki duplikat szkicu), ale
		// zwalniamy TYLKO blokadę, którą faktycznie trzymamy.
		$got_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		try {
			return self::create_draft_for_lead( $lead_id, $payload );
		} finally {
			if ( '1' === (string) $got_lock ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	/**
	 * Właściwe założenie szkicu (wywoływane pod blokadą per-lead z on_lead_created()).
	 *
	 * @param int   $lead_id ID leada (>0, już zwalidowane).
	 * @param mixed $payload Dane leada — patrz kontrakt w docblocku klasy.
	 * @return int|false ID draftu (nowego albo istniejącego), albo false przy błędzie zapisu.
	 */
	private static function create_draft_for_lead( $lead_id, $payload ) {
		global $wpdb;

		$offers_table = MP_Offer_Builder_DB::offers_table();

		// Idempotencja: ten sam lead_id może wywołać hook wielokrotnie (reaktywacja
		// zarchiwizowanego leada w pluginie 1 też kończy się przez Dział 11 → ten sam
		// do_action). Bez tej kontroli powstawałby duplikat draftu przy każdej reaktywacji.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$offers_table} WHERE lead_id = %d AND status = 'draft' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lead_id
			)
		);

		$payload = is_array( $payload ) ? $payload : array();
		$request = self::lead_request( $payload );

		if ( $existing ) {
			/*
			 * Szkic już jest, ale zdarzenie przyszło ponownie — to reaktywacja
			 * zarchiwizowanego leada, czyli klient odezwał się DRUGI RAZ i mógł
			 * poprosić o coś innego niż za pierwszym razem. Odświeżamy więc opis
			 * prośby; gdybyśmy tylko zwrócili istniejący szkic (tak było wcześniej),
			 * handlowiec czytałby nieaktualne zapotrzebowanie.
			 *
			 * Odświeżamy WYŁĄCZNIE te dwie kolumny i wyłącznie gdy nowe zdarzenie
			 * coś niesie: pusty payload starszego pluginu 1 nie ma prawa skasować
			 * treści, którą wcześniej dostaliśmy.
			 */
			if ( $request ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$offers_table,
					$request,
					array(
						'id'     => (int) $existing,
						'status' => 'draft',
					)
				);
			}

			return (int) $existing;
		}

		$inserted = $wpdb->insert(
			$offers_table,
			$request + array(
				'status'            => 'draft',
				'lead_id'           => $lead_id,
				// S2-02: każde pole przycięte do limitu kolumny (lustro FIELD_LIMITS w Dziale 10)
				// — za długa wartość z P1 pod strict-mode wywaliłaby INSERT i draft cicho by nie
				// powstał. Kraj dodatkowo do 2 znaków + upper (kolumna char(2), np. "de"->"DE").
				'client_name'       => isset( $payload['company_name'] ) ? substr( sanitize_text_field( $payload['company_name'] ), 0, 191 ) : null,
				'client_email'      => isset( $payload['email'] ) ? substr( sanitize_email( $payload['email'] ), 0, 191 ) : null,
				'client_nip'        => isset( $payload['nip'] ) ? substr( sanitize_text_field( $payload['nip'] ), 0, 20 ) : null,
				'client_country'    => isset( $payload['country'] ) ? strtoupper( substr( sanitize_text_field( $payload['country'] ), 0, 2 ) ) : null,
				// Utrwalony status VAT z VIES (plugin 1) — bez tego korekta oferty
				// UE gubiłaby reverse_charge (Dział 6 czyta client.vat_status po
				// odtworzeniu ze snapshotu w Dziale 1).
				// substr do 20 znaków = limit kolumny client_vat_status: gdyby P1 kiedyś
				// wysłał dłuższą wartość, przy strict-mode INSERT padłby i draft cicho
				// by nie powstał (ścieżka pipeline ma ten sam limit w FIELD_LIMITS).
				'client_vat_status' => isset( $payload['vat_status'] ) ? substr( sanitize_text_field( $payload['vat_status'] ), 0, 20 ) : null,

				/*
				 * F3 (bramka integracyjna): właściciel draftu bierze się z handlowca
				 * przypisanego w pluginie 1. Bez tego `created_by` zostawało NULL i
				 * draft z leada był w UI niedostępny dla wszystkich poza adminem —
				 * pipeline go dopuszczał, ale handlowiec fizycznie nie mógł go
				 * otworzyć, więc automatyczna ścieżka lead→oferta kończyła się
				 * ofertą, której nikt nie widzi.
				 */
				'created_by'        => isset( $payload['salesman_id'] ) && (int) $payload['salesman_id'] > 0
					? (int) $payload['salesman_id']
					: null,
			)
		);

		if ( ! $inserted ) {
			return false;
		}

		$offer_id = (int) $wpdb->insert_id;

		// Dziennik BD-2 — spójny z logowaniem w Dziale 10/11 pipeline'u (kryt. 5.5:
		// historia odtwarzalna z samego dziennika).
		$wpdb->insert(
			MP_Offer_Builder_DB::activity_log_table(),
			array(
				'offer_id'    => $offer_id,
				'action'      => 'draft_created_from_lead',
				'description' => sprintf( 'Automatyczny szkic oferty utworzony po zdarzeniu mp_lead_created (lead_id=%d).', $lead_id ),
				'meta_json'   => wp_json_encode( array( 'lead_id' => $lead_id ) ),
			)
		);

		return $offer_id;
	}

	/**
	 * Wyciąga z payloadu prośbę klienta w postaci gotowej do zapisu.
	 *
	 * Zwraca TYLKO te klucze, które faktycznie przyszły. Pusta tablica znaczy
	 * „zdarzenie nic nie niesie" — a to nie to samo, co „klient nic nie chce":
	 * pierwsze zdarza się przy starszym pluginie 1, drugie przy pustym polu
	 * formularza. Rozróżnienie ma znaczenie przy odświeżaniu istniejącego szkicu,
	 * gdzie nadpisanie pustką skasowałoby treść.
	 *
	 * @param array $payload Dane leada ze zdarzenia.
	 * @return array<string,string> Kolumny do zapisu (możliwie puste).
	 */
	private static function lead_request( array $payload ) {
		$out = array();

		if ( isset( $payload['products'] ) ) {
			$products = sanitize_textarea_field( (string) $payload['products'] );
			if ( '' !== $products ) {
				// Kolumna `text` po obu stronach (plugin 1 też), więc przycięcie jest
				// obroną teoretyczną. Liczymy mimo to w ZNAKACH, nie bajtach: cięcie
				// bajtowe rozłupałoby polski znak i strict-mode odrzuciłby cały INSERT,
				// czyli szkic nie powstałby wcale.
				$out['lead_products'] = function_exists( 'mb_substr' ) ? mb_substr( $products, 0, 16000 ) : substr( $products, 0, 16000 );
			}
		}

		if ( isset( $payload['est_volume'] ) ) {
			$volume = sanitize_text_field( (string) $payload['est_volume'] );
			if ( '' !== $volume ) {
				// 100 znaków = limit kolumny (lustro varchar(100) po obu stronach).
				$out['lead_est_volume'] = function_exists( 'mb_substr' ) ? mb_substr( $volume, 0, 100 ) : substr( $volume, 0, 100 );
			}
		}

		return $out;
	}
}
