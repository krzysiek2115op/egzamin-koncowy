<?php
/**
 * Dzial 1 pipeline LP.3 — BRAMA I KONTRAKT ZDARZENIA.
 *
 * Zakres wg diagramu: zdarzenie procesu · tylko JSON
 *
 * Operacje dzialu:
 *  - Słownik typów zdarzeń
 *  - Uprawnienia przy wywołaniu ręcznym
 *  - Klucz idempotencji na zdarzenie
 *
 * Zrodlo (Golden Rule #2): docs/dzial-01/brama-i-kontrakt-zdarzenia.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: JSON Schema 2020-12 · WP REST permission_callback · Nonces
 *
 * Podzial pracy w parze jest tu konsekwentny: AGENT parsuje i normalizuje
 * (nigdy nie przerywa na pierwszym bledzie), a KRYTYK dopiero odrzuca calosc z
 * kodem. Dzieki temu wywolujacy dostaje KOMPLET brakow w jednej odpowiedzi —
 * tego wprost wymaga krytyk K1.1 ("komplet błędów naraz").
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stale i slowniki Dzialu 1 — wspolne dla agentow, krytykow i wywolujacych.
 */
class MP_SW_D1 {

	/** Zrodlo: zdarzenie systemowe (hook z wtyczki 1 lub 2). */
	const SOURCE_SYSTEM = 'system';

	/** Zrodlo: timer (harmonogram zadan follow-up). */
	const SOURCE_CRON = 'cron';

	/** Zrodlo: wywolanie reczne przez handlowca lub managera. */
	const SOURCE_MANUAL = 'manual';

	/** Akcja nonce'a dla wywolan recznych. */
	const NONCE_ACTION = 'mp_sw_event';

	/** Uprawnienie wymagane przy wywolaniu recznym. */
	const CAP_MANUAL = 'mp_sw_handle_event';

	/** Dopuszczalne jezyki procesu. */
	const LANGS = array( 'pl', 'en' );

	/**
	 * Slownik zrodel zdarzenia.
	 *
	 * @return string[]
	 */
	public static function sources() {
		return array( self::SOURCE_SYSTEM, self::SOURCE_CRON, self::SOURCE_MANUAL );
	}

	/**
	 * Pola encji wymagane dla danego typu zdarzenia.
	 *
	 * `dashboard.view` celowo nie wymaga encji: to przeglad listy procesow w
	 * zakresie roli aktora (Dzial 3), a nie operacja na jednym procesie.
	 *
	 * @param string $type Typ zdarzenia.
	 * @return string[]
	 */
	public static function required_entity_fields( $type ) {
		$map = array(
			MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED   => array( 'lead_id' ),
			MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED => array( 'lead_id', 'offer_id' ),
			MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE  => array( 'lead_id' ),

			/*
			 * `task.due` niesie RÓWNIEŻ lead_id, choć sam identyfikator zadania
			 * wystarczyłby do jego odnalezienia. Powód jest w Dziale 2: proces
			 * czytany jest po `lead_id`, a odczyt jest dokładnie jeden. Bez leada w
			 * kopercie trzeba by najpierw przeczytać zadanie, żeby dowiedzieć się,
			 * którego procesu dotyczy — czyli wykonać drugi strzał.
			 */
			MP_SW_Pipeline_Factory::EVENT_TASK_DUE       => array( 'lead_id', 'task_id' ),
			MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW => array(),
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : array();
	}

	/**
	 * Pola encji DOZWOLONE, ale nie wymagane dla danego typu zdarzenia.
	 *
	 * Koperta budowana jest od zera z zamknietej listy pol — inaczej wywolujacy
	 * wstrzyknalby do niej dowolny klucz. Sama lista wymaganych nie wystarcza:
	 * `mp_offer_created` z wtyczki 2 przychodzi jako `status.change` i niesie
	 * `offer_id`, ktore przy filtrowaniu po samych wymaganych ginelo. Proces
	 * przechodzil wtedy do statusu „oferta w przygotowaniu", nie zapamietujac,
	 * KTOREJ oferty dotyczy — a `offer.approved`, ktore uzupelnialoby ten brak,
	 * w tej wersji nie jest przez nikogo emitowane. Wykryte w tescie koncowym
	 * S4/10 na zywym WordPressie.
	 *
	 * @param string $type Typ zdarzenia.
	 * @return string[]
	 */
	public static function optional_entity_fields( $type ) {
		$map = array(
			MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE => array( 'offer_id' ),
			MP_SW_Pipeline_Factory::EVENT_TASK_DUE      => array( 'offer_id' ),
			MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED  => array( 'offer_id' ),
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : array();
	}
}

/**
 * A1.1 „kontrakt" — waliduje i normalizuje kopertę zdarzenia.
 */
class MP_SW_D1_Agent_Contract extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$errors = array();

		// --- Typ ze slownika ---
		$type = $context->get( 'type', '' );
		$type = is_string( $type ) ? trim( $type ) : '';

		if ( ! in_array( $type, MP_SW_Pipeline_Factory::event_types(), true ) ) {
			$errors[] = 'type';
		}

		// --- Encja: pola wymagane zaleza od typu ---
		$raw_entity = $context->get( 'entity', array() );
		$raw_entity = is_array( $raw_entity ) ? $raw_entity : array();
		$entity     = array();

		foreach ( MP_SW_D1::required_entity_fields( $type ) as $field ) {
			$value = isset( $raw_entity[ $field ] ) ? $raw_entity[ $field ] : 0;

			// Identyfikatory sa dodatnimi liczbami calkowitymi; 0 znaczy "brak".
			if ( ! is_numeric( $value ) || (int) $value <= 0 ) {
				$errors[] = 'entity.' . $field;
				continue;
			}

			$entity[ $field ] = (int) $value;
		}

		// Pola nieobowiazkowe: przepisywane TYLKO gdy sa i tylko z listy dla tego
		// typu. Brak takiego pola nie jest bledem — puste `offer_id` przy recznej
		// zmianie statusu to normalny przypadek.
		foreach ( MP_SW_D1::optional_entity_fields( $type ) as $field ) {
			if ( isset( $entity[ $field ] ) || ! isset( $raw_entity[ $field ] ) ) {
				continue;
			}

			$value = $raw_entity[ $field ];

			if ( is_numeric( $value ) && (int) $value > 0 ) {
				$entity[ $field ] = (int) $value;
			}
		}

		// --- Aktor ---
		$raw_actor = $context->get( 'actor', array() );
		$raw_actor = is_array( $raw_actor ) ? $raw_actor : array();

		if ( ! array_key_exists( 'user_id', $raw_actor ) || ! is_numeric( $raw_actor['user_id'] ) || (int) $raw_actor['user_id'] < 0 ) {
			// Zero jest dopuszczalne — tak oznaczamy aktora systemowego (hook, cron).
			$errors[] = 'actor.user_id';
			$actor    = array( 'user_id' => 0 );
		} else {
			$actor = array( 'user_id' => (int) $raw_actor['user_id'] );
		}

		// --- Jezyk procesu ---
		$lang = $context->get( 'lang', 'pl' );
		$lang = is_string( $lang ) ? strtolower( trim( $lang ) ) : '';

		if ( ! in_array( $lang, MP_SW_D1::LANGS, true ) ) {
			// Jezyk spoza slownika nie blokuje zdarzenia: proces dostaje jezyk
			// domyslny, a jezyk ODBIORCY ustala Dzial 7 z danych procesu.
			$lang = 'pl';
		}

		/*
		 * --- Dane klienta ---
		 *
		 * Nieobowiązkowe: zdarzenia crona i zmiany statusu ich nie niosą, bo
		 * proces ma je już zapisane. Przy zdarzeniach przychodzących z wtyczki 1
		 * to jedyne miejsce, którym adres klienta wchodzi do procesu — treść
		 * powiadomienia (Dział 7) nie ma skąd go wziąć, jeśli nie przyjdzie tutaj.
		 *
		 * Adres niepoprawny NIE jest przemilczany: taki wiersz trafiłby do kolejki
		 * i odbił się dopiero przy wysyłce, długo po odpowiedzi na żądanie.
		 */
		$raw_client = $context->get( 'client', array() );
		$raw_client = is_array( $raw_client ) ? $raw_client : array();
		$client     = array(
			'name'  => '',
			'email' => '',
		);

		if ( isset( $raw_client['name'] ) ) {
			$client['name'] = sanitize_text_field( (string) $raw_client['name'] );
		}

		if ( isset( $raw_client['email'] ) && '' !== trim( (string) $raw_client['email'] ) ) {
			$email = sanitize_email( (string) $raw_client['email'] );

			if ( ! is_email( $email ) ) {
				$errors[] = 'client.email';
			} else {
				$client['email'] = $email;
			}
		}

		return MP_SW_Result::ok(
			array(
				'event'           => array(
					'type'   => $type,
					'entity' => $entity,
					'actor'  => $actor,
					'lang'   => $lang,
				),
				'client'          => $client,
				'contract_errors' => $errors,
			)
		);
	}
}

/**
 * K1.1 „schemat-zdarzenia" — odrzuca niekompletną kopertę kodem 422.
 */
class MP_SW_D1_Critic_Schema extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data   = $agent_result->get_data();
		$errors = isset( $data['contract_errors'] ) ? (array) $data['contract_errors'] : array();

		if ( ! empty( $errors ) ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: lista pól niezgodnych z kontraktem zdarzenia. */
					__( 'Zdarzenie niezgodne z kontraktem — pola: %s', 'mp-sales-workflow' ),
					implode( ', ', $errors )
				),
				array(
					'errors'      => $errors,
					'http_status' => 422,
				),
				'invalid_event'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A1.2 „źródło" — ustala, kto woła, i weryfikuje wywołanie ręczne.
 */
class MP_SW_D1_Agent_Source extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$source = $context->get( 'source', '' );
		$source = is_string( $source ) ? strtolower( trim( $source ) ) : '';

		/*
		 * Zrodlo nieznane albo nieprzekazane traktujemy jak RECZNE, czyli
		 * najostrzej: z wymogiem nonce'a i uprawnienia. Odwrotne domyslne
		 * ustawienie ("skoro nie wiadomo, to pewnie system") pozwalaloby ominac
		 * bramke przez samo POMINIECIE pola w zadaniu.
		 */
		if ( ! in_array( $source, MP_SW_D1::sources(), true ) ) {
			$source = MP_SW_D1::SOURCE_MANUAL;
		}

		$auth = array(
			'nonce_ok' => true,
			'cap_ok'   => true,
			'user_id'  => 0,
		);

		if ( MP_SW_D1::SOURCE_MANUAL === $source ) {
			$nonce = $context->get( 'nonce', '' );
			$nonce = is_string( $nonce ) ? $nonce : '';

			$auth['nonce_ok'] = (bool) wp_verify_nonce( $nonce, MP_SW_D1::NONCE_ACTION );
			$auth['cap_ok']   = current_user_can( MP_SW_D1::CAP_MANUAL );
			$auth['user_id']  = get_current_user_id();

			/*
			 * Sam PODGLĄD nonce'a nie wymaga i jest to decyzja świadoma. Token
			 * broni przed wymuszeniem AKCJI przez obcą witrynę (CSRF) — a ścieżka
			 * `dashboard.view` żadnej akcji nie wykonuje: fabryka buduje dla niej
			 * pipeline bez transakcji i bez Działu 8, więc `db_writes = 0` wynika
			 * z budowy, nie z dobrej woli. Wymóg tokenu przy renderze podstrony
			 * dawał tylko pozór kontroli: strona nie ma skąd wziąć cudzego tokenu,
			 * więc wystawiałaby go sobie sama i sama sprawdzała.
			 *
			 * Uprawnienie zostaje sprawdzone tak samo ostro jak przy każdym innym
			 * wywołaniu ręcznym — to ono decyduje, kto widzi procesy.
			 */
			if ( MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW === (string) $context->get( 'type', '' ) ) {
				$auth['nonce_ok']       = true;
				$auth['nonce_required'] = false;
			}
		}

		if ( ! isset( $auth['nonce_required'] ) ) {
			$auth['nonce_required'] = MP_SW_D1::SOURCE_MANUAL === $source;
		}

		return MP_SW_Result::ok(
			array(
				'source' => $source,
				'auth'   => $auth,
			)
		);
	}
}

/**
 * K1.2 „kto-woła" — 403 dla wywołania ręcznego bez nonce'a lub uprawnienia.
 */
class MP_SW_D1_Critic_Caller extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data   = $agent_result->get_data();
		$source = isset( $data['source'] ) ? $data['source'] : '';
		$auth   = isset( $data['auth'] ) ? (array) $data['auth'] : array();

		if ( MP_SW_D1::SOURCE_MANUAL !== $source ) {
			return MP_SW_Result::ok( $data );
		}

		$errors = array();

		if ( empty( $auth['nonce_ok'] ) ) {
			$errors[] = 'nonce';
		}

		if ( empty( $auth['cap_ok'] ) ) {
			$errors[] = 'capability';
		}

		if ( empty( $auth['user_id'] ) ) {
			$errors[] = 'actor.user_id';
		}

		if ( ! empty( $errors ) ) {
			// Odmowa zapada w Dziale 1 — PRZED jakąkolwiek pracą pipeline'u:
			// bez odczytu bazy, przypisania handlowca i kolejki powiadomień.
			return MP_SW_Result::fail(
				__( 'Brak uprawnień do ręcznego wywołania zdarzenia.', 'mp-sales-workflow' ),
				array(
					'errors'      => $errors,
					'http_status' => 403,
				),
				'forbidden'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A1.3 „idempotencja" — nadaje klucz zdarzenia i identyfikator śladu.
 */
class MP_SW_D1_Agent_Idempotency extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$event_id = $context->get( 'event_id', '' );
		$event_id = is_string( $event_id ) ? trim( $event_id ) : '';
		$type     = $context->get( 'type', '' );

		$generated = false;
		$trace_id  = $context->get( 'trace_id', '' );
		$trace_id  = is_string( $trace_id ) && wp_is_uuid( $trace_id, 4 ) ? $trace_id : wp_generate_uuid4();

		if ( ! wp_is_uuid( $event_id, 4 ) ) {
			/*
			 * Zdarzenie z timera MUSI przyjść z gotowym kluczem, nadanym w
			 * chwili PLANOWANIA zadania. Gdyby klucz powstawał tutaj, każde
			 * uruchomienie crona dostawałoby nowy UUID i wymóg "timer d+3
			 * wykonany dokładnie raz" przestałby cokolwiek znaczyć: ponowione
			 * zadanie wyglądałoby na zupełnie nowe zdarzenie.
			 */
			if ( MP_SW_Pipeline_Factory::EVENT_TASK_DUE === $type ) {
				return MP_SW_Result::ok(
					array(
						'event_id'           => '',
						'trace_id'           => $trace_id,
						'event_id_generated' => false,
					)
				);
			}

			/*
			 * Rozróżnienie, które chroni idempotencję: BRAK klucza to normalna
			 * sytuacja (kliknięcie w panelu żadnego nie ma), ale klucz PODANY i
			 * uszkodzony to błąd wywołującego. Ciche podmienienie go na nowy
			 * sprawiłoby, że ponowione zdarzenie wyglądałoby na zupełnie inne —
			 * czyli dokładnie to, przed czym idempotencja ma bronić.
			 */
			if ( '' !== $event_id ) {
				return MP_SW_Result::ok(
					array(
						'event_id'           => '',
						'trace_id'           => $trace_id,
						'event_id_generated' => false,
						'event_id_malformed' => true,
					)
				);
			}

			$event_id  = wp_generate_uuid4();
			$generated = true;
		}

		return MP_SW_Result::ok(
			array(
				'event_id'           => $event_id,
				'trace_id'           => $trace_id,
				'event_id_generated' => $generated,
				'event_id_malformed' => false,
			)
		);
	}
}

/**
 * K1.3 „klucz-idempotencji" — bez poprawnego klucza zdarzenie nie rusza dalej.
 */
class MP_SW_D1_Critic_Idempotency_Key extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data     = $agent_result->get_data();
		$event_id = isset( $data['event_id'] ) ? $data['event_id'] : '';

		if ( ! wp_is_uuid( $event_id, 4 ) ) {
			$malformed = ! empty( $data['event_id_malformed'] );

			return MP_SW_Result::fail(
				$malformed
					? __( 'Klucz idempotencji podany, ale nie jest UUID v4 — nie podmieniamy go po cichu.', 'mp-sales-workflow' )
					: __( 'Zdarzenie bez poprawnego klucza idempotencji (UUID v4).', 'mp-sales-workflow' ),
				array(
					'errors'      => array( 'event_id' ),
					'http_status' => 422,
				),
				$malformed ? 'malformed_idempotency_key' : 'missing_idempotency_key'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * QA1 — bramka jakości Działu 1: komplet zdarzenia (typ + encja + aktor).
 */
class MP_SW_D1_QA_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$event   = (array) $context->get( 'event', array() );
		$missing = array();

		$type = isset( $event['type'] ) ? $event['type'] : '';

		if ( ! in_array( $type, MP_SW_Pipeline_Factory::event_types(), true ) ) {
			$missing[] = 'event.type';
		}

		$entity = isset( $event['entity'] ) ? (array) $event['entity'] : array();

		foreach ( MP_SW_D1::required_entity_fields( $type ) as $field ) {
			if ( empty( $entity[ $field ] ) ) {
				$missing[] = 'event.entity.' . $field;
			}
		}

		if ( ! isset( $event['actor']['user_id'] ) ) {
			$missing[] = 'event.actor.user_id';
		}

		if ( ! wp_is_uuid( (string) $context->get( 'event_id', '' ), 4 ) ) {
			$missing[] = 'event_id';
		}

		if ( ! in_array( (string) $context->get( 'source', '' ), MP_SW_D1::sources(), true ) ) {
			$missing[] = 'source';
		}

		return MP_SW_Result::ok( array( 'qa_missing' => $missing ) );
	}
}

/**
 * QA1 krytyk — brak któregokolwiek elementu kończy się 422 z listą pól.
 */
class MP_SW_D1_QA_Critic extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data    = $agent_result->get_data();
		$missing = isset( $data['qa_missing'] ) ? (array) $data['qa_missing'] : array();

		if ( ! empty( $missing ) ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: lista brakujących elementów zdarzenia. */
					__( 'Niekompletne zdarzenie — brakuje: %s', 'mp-sales-workflow' ),
					implode( ', ', $missing )
				),
				array(
					'errors'      => $missing,
					'http_status' => 422,
				),
				'incomplete_event'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * BRAMA I KONTRAKT ZDARZENIA.
 */
class MP_SW_Department_01 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 1;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'brama-i-kontrakt-zdarzenia';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_D1_Agent_Contract( '1.1', 'kontrakt', 'Waliduje JSON zdarzenia: typ ze słownika (lead.created · offer.approved · status.change · task.due · dashboard.view), encja, aktor' ),
				'critic' => new MP_SW_D1_Critic_Schema( 'K1.1', 'schemat-zdarzenia', 'Typ spoza słownika = 422; komplet błędów naraz' ),
			),
			array(
				'agent'  => new MP_SW_D1_Agent_Source( '1.2', 'źródło', 'Zdarzenie systemowe (hook), timer (cron) albo ręczne od handlowca / managera — ręczne z nonce i uprawnieniem' ),
				'critic' => new MP_SW_D1_Critic_Caller( 'K1.2', 'kto-woła', 'Wywołanie ręczne bez uprawnień = 403 przed pracą' ),
			),
			array(
				'agent'  => new MP_SW_D1_Agent_Idempotency( '1.3', 'idempotencja', 'event_id (UUID) na zdarzenie; timer d+3 wykonany dokładnie raz' ),
				'critic' => new MP_SW_D1_Critic_Idempotency_Key( 'K1.3', 'klucz-idempotencji', 'Ten sam event_id nigdy nie obsłuży zdarzenia dwa razy' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_D1_QA_Agent( 'QA1', 'bramka jakosci D1', 'komplet zdarzenia: typ + encja + aktor' ),
			new MP_SW_D1_QA_Critic( 'QA1.K', 'krytyk bramki D1', 'brak któregokolwiek = 422 z listą pól' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'BRAMA I KONTRAKT ZDARZENIA',
			'zdarzenie procesu · tylko JSON',
			$pairs,
			$gate
		);
	}
}
