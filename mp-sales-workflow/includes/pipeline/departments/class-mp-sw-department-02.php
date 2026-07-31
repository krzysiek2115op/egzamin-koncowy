<?php
/**
 * Dzial 2 pipeline LP.3 — STRZAŁ ODCZYTU — BD-1.
 *
 * Zakres wg diagramu: BD-1 (pkt 3): role · kraj · zespół · język
 *
 * Operacje dzialu:
 *  - Jeden strzał: rdzeń WP + strefa wtyczki
 *  - Zamrożenie snapshotu w pamięci
 *  - Działy 3–7 = czyste funkcje
 *
 * Zrodlo (Golden Rule #2): docs/dzial-02/strzal-odczytu-bd-1.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: WP_User_Query (meta_query) · Working with User Metadata
 *
 * CALY odczyt bazy w zadaniu dzieje sie TUTAJ i tylko raz. Piec par nie czyta
 * bazy kazda z osobna — czytnik wykonuje jeden strzal, zamraza wynik w
 * kopercie, a agenci wyjmuja z niego swoje sekcje. Dzieki temu dzialy 3-7 sa
 * czystymi funkcjami: dostaja dane wejsciowe i nie siegaja nigdzie indziej.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Czytnik snapshotu — jedyne miejsce w pipeline, ktore siega do bazy po odczyt.
 */
class MP_SW_D2_Reader {

	/** Klucz snapshotu w kopercie. */
	const SNAPSHOT_KEY = 'snapshot';

	/** Meta handlowca: obsługiwany kraj (ISO-2). */
	const META_COUNTRY = 'mp_sw_country';

	/** Meta handlowca: obsługiwane języki (lista rozdzielona przecinkami). */
	const META_LANGS = 'mp_sw_langs';

	/** Meta handlowca: zespół. */
	const META_TEAM = 'mp_sw_team';

	/** Meta handlowca: czy przyjmuje nowe procesy. */
	const META_ACTIVE = 'mp_sw_active';

	/** Statusy, w których proces nadal obciąża handlowca. */
	const OPEN_STATUSES = array( 'new', 'assigned', 'offer_draft', 'offer_sent', 'negotiation' );

	/**
	 * Sekcje, ktore snapshot MUSI zawierac (bramka QA2).
	 *
	 * @return string[]
	 */
	public static function sections() {
		return array( 'salesmen', 'roles', 'flow', 'workload', 'templates' );
	}

	/**
	 * Zwraca zamrożony snapshot; przy pierwszym wywołaniu wykonuje odczyt.
	 *
	 * Snapshot trzymany jest w KOPERCIE, a nie w statycznym polu klasy: jedno
	 * uruchomienie crona przetwarza wiele zdarzeń po kolei i statyczna pamięć
	 * podałaby drugiemu zdarzeniu dane pierwszego.
	 *
	 * @param MP_SW_Context $context Kontekst.
	 * @return array
	 */
	public static function snapshot( MP_SW_Context $context ) {
		$snapshot = $context->get( self::SNAPSHOT_KEY );

		if ( is_array( $snapshot ) ) {
			return $snapshot;
		}

		/*
		 * Licznik rośnie DOKŁADNIE raz na żądanie — to jest ten "jeden strzał"
		 * z bramki QA2. Pod spodem pada kilka zapytań SQL (użytkownicy z meta,
		 * proces, obciążenie), bo jednym SELECT-em nie da się pobrać danych z
		 * rdzenia WordPressa i ze strefy wtyczki naraz. Liczy się faza: po
		 * wyjściu z Działu 2 nikt już do bazy po odczyt nie sięga.
		 */
		$context->count_db_read();

		// Proces czytany osobno i PRZED ofertą: gdy koperta nie niesie numeru
		// oferty, bierzemy ten, który proces ma już zapisany.
		$flow = self::read_flow( $context );

		$snapshot = array(
			'salesmen'  => self::read_salesmen(),
			'roles'     => self::read_roles( $context ),
			'flow'      => $flow,
			'lead'      => self::read_lead( $context ),
			'offer'     => self::read_offer( $context, $flow ),
			'workload'  => self::read_workload(),
			'templates' => self::read_templates(),
		);

		$context->set( self::SNAPSHOT_KEY, $snapshot );

		return $snapshot;
	}

	/**
	 * Handlowcy wraz z konfiguracją; niekompletni trafiają na osobną listę.
	 *
	 * @return array
	 */
	private static function read_salesmen() {
		$query = new WP_User_Query(
			array(
				'role'    => MP_SW_Roles::ROLE_SALESMAN,
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
				'number'  => -1,
				'orderby' => 'ID',
			)
		);

		$complete   = array();
		$incomplete = array();
		$total      = 0;

		foreach ( (array) $query->get_results() as $user ) {
			++$total;

			$id      = (int) $user->ID;
			$country = strtoupper( trim( (string) get_user_meta( $id, self::META_COUNTRY, true ) ) );
			$langs   = (string) get_user_meta( $id, self::META_LANGS, true );
			$team    = trim( (string) get_user_meta( $id, self::META_TEAM, true ) );
			$active  = get_user_meta( $id, self::META_ACTIVE, true );

			$langs = array_values(
				array_filter(
					array_map(
						static function ( $lang ) {
							return strtolower( trim( $lang ) );
						},
						explode( ',', $langs )
					)
				)
			);

			$entry = array(
				'user_id' => $id,
				'name'    => (string) $user->display_name,
				'email'   => (string) $user->user_email,
				'country' => $country,
				'langs'   => $langs,
				'team'    => $team,
				'active'  => ( '' === $active ) ? true : (bool) $active,
			);

			$missing = array();

			if ( '' === $country ) {
				$missing[] = self::META_COUNTRY;
			}

			if ( empty( $langs ) ) {
				$missing[] = self::META_LANGS;
			}

			if ( '' === $team ) {
				$missing[] = self::META_TEAM;
			}

			if ( empty( $missing ) ) {
				$complete[] = $entry;
			} else {
				// Krytyk K2.1: handlowiec z niepełną konfiguracją ma być WIDOCZNY
				// jako brak do uzupełnienia, a nie po cichu pominięty przy doborze.
				$entry['missing'] = $missing;
				$incomplete[]     = $entry;
			}
		}

		return array(
			'complete'   => $complete,
			'incomplete' => $incomplete,
			'total'      => $total,
		);
	}

	/**
	 * Obecność ról wymaganych przez kryterium odbioru oraz rola AKTORA.
	 *
	 * Dane aktora czytane są tutaj, a nie w Dziale 3, bo tamten ma być czystą
	 * funkcją: „Działy 3–7 = czyste funkcje" (operacje Działu 2 wg diagramu).
	 * Gdyby Dział 3 sam wołał `get_userdata()`, zasada jednego strzału odczytu
	 * byłaby złamana bez śladu w liczniku.
	 *
	 * @param MP_SW_Context $context Kontekst.
	 * @return array
	 */
	private static function read_roles( MP_SW_Context $context ) {
		$missing = MP_SW_Roles::missing_roles();

		$event   = (array) $context->get( 'event', array() );
		$user_id = isset( $event['actor']['user_id'] ) ? (int) $event['actor']['user_id'] : 0;

		$actor = array(
			'user_id'       => $user_id,
			'roles'         => array(),
			'manage_all'    => false,
			'view_team'     => false,
			'assign'        => false,
			'change_status' => false,
			'team'          => '',
			'team_members'  => array(),
			'exists'        => false,
		);

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );

			if ( $user instanceof WP_User ) {
				$actor['exists'] = true;
				$actor['roles']  = array_values( (array) $user->roles );

				/*
				 * `manage_all` znaczy „widzi wszystko". Stara stała zostaje w
				 * warunku dla instalacji, które jeszcze się nie przemigrowały —
				 * ale nowym źródłem prawdy jest `mp_sw_view_all`.
				 */
				$actor['manage_all']    = user_can( $user, MP_SW_Roles::CAP_VIEW_ALL ) || user_can( $user, MP_SW_Roles::CAP_MANAGE_ALL );
				$actor['view_team']     = user_can( $user, MP_SW_Roles::CAP_VIEW_TEAM );
				$actor['assign']        = user_can( $user, MP_SW_Roles::CAP_ASSIGN );
				$actor['change_status'] = user_can( $user, MP_SW_Roles::CAP_CHANGE_STATUS );
				$actor['handles']       = user_can( $user, MP_SW_Roles::CAP_HANDLE_EVENT );
				$actor['team']          = (string) get_user_meta( $user_id, self::META_TEAM, true );
				$actor['team_members']  = self::read_team_members( $actor );
			}
		}

		if ( ! isset( $actor['handles'] ) ) {
			$actor['handles'] = false;
		}

		return array(
			'required' => MP_SW_Roles::required_roles(),
			'missing'  => $missing,
			'ok'       => empty( $missing ),
			'actor'    => $actor,
		);
	}

	/**
	 * Oferta ze zdarzenia — istnienie, przynależność do leada i uchwyt do pliku.
	 *
	 * Bez tego odczytu zdarzenie `offer.approved` z haka było przyjmowane na
	 * słowo: nikt nie sprawdzał, czy oferta w ogóle istnieje ani czy dotyczy
	 * TEGO leada. Para identyfikatorów podana przez obcą wtyczkę wystarczała,
	 * żeby wysłać klientowi A dokument klienta B.
	 *
	 * @param MP_SW_Context $context Kontekst.
	 * @param array         $flow    Wiersz procesu wczytany chwilę wcześniej.
	 * @return array
	 */
	private static function read_offer( MP_SW_Context $context, array $flow = array() ) {
		global $wpdb;

		$event    = (array) $context->get( 'event', array() );
		$entity   = isset( $event['entity'] ) ? (array) $event['entity'] : array();
		$offer_id = isset( $entity['offer_id'] ) ? (int) $entity['offer_id'] : 0;
		$lead_id  = isset( $entity['lead_id'] ) ? (int) $entity['lead_id'] : 0;

		/*
		 * Gdy koperta nie niesie oferty, bierzemy tę, którą proces ma już
		 * zapisaną. Ręczna zmiana statusu z pulpitu podaje sam `lead_id` —
		 * handlowiec zatwierdza PROCES, nie wpisuje numeru oferty z pamięci.
		 * Bez tego zatwierdzenie kończyło się odmową „brak dokumentu", mimo że
		 * proces od dawna wiedział, której oferty dotyczy.
		 *
		 * Kolejność jest istotna: koperta ma pierwszeństwo, bo zdarzenie z wtyczki
		 * 2 może dotyczyć NOWSZEJ oferty niż ta zapisana w procesie. Wiersz procesu
		 * jest już w tym miejscu wczytany — to nie jest dodatkowy strzał do bazy.
		 */
		// Skad wziety identyfikator ma znaczenie dla bramki QA2: z koperty jest
		// wektorem podszycia i wymaga twardej walidacji, z wiersza procesu jest
		// tylko wskazowka, ktora moze sie zdezaktualizowac.
		$from_event = ( $offer_id > 0 );

		if ( $offer_id < 1 ) {
			$row      = isset( $flow['row'] ) ? (array) $flow['row'] : $flow;
			$offer_id = isset( $row['offer_id'] ) ? (int) $row['offer_id'] : 0;
		}

		$empty = array(
			'exists'        => false,
			'offer_id'      => $offer_id,
			'belongs'       => false,
			'handle'        => '',
			'offer_number'  => '',
			'status'        => '',
			'table_present' => false,
			'from_event'    => $from_event,
		);

		if ( $offer_id < 1 ) {
			return $empty;
		}

		/**
		 * Pozwala wtyczce 2 rozwiazac oferte wlasnym kodem.
		 *
		 * Zaczep istnieje z tego samego powodu co przy linku pobrania: LP.3 czyta
		 * cudza tabele tylko dlatego, ze inaczej nie ma jak potwierdzic oferty.
		 * Gdy LP.2 zechce podac te dane sama, przestaniemy siegac po jej schemat.
		 *
		 * @param array|null $offer    Dane oferty albo null.
		 * @param int        $offer_id Identyfikator oferty.
		 * @param int        $lead_id  Identyfikator leada.
		 */
		$override = apply_filters( 'mp_sw_read_offer', null, $offer_id, $lead_id );

		if ( is_array( $override ) ) {
			return array_merge( $empty, $override );
		}

		$table = $wpdb->prefix . 'mp_ob_offers';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $found !== $table ) {
			// Wtyczka 2 nieaktywna: nie mamy jak potwierdzić oferty, więc mówimy o
			// tym wprost zamiast udawać, że sprawdzenie wypadło pomyślnie.
			return $empty;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, lead_id, request_id, offer_number, status FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$offer_id
			),
			ARRAY_A
		);

		$empty['table_present'] = true;

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		$request_id = isset( $row['request_id'] ) ? (string) $row['request_id'] : '';

		return array(
			'exists'        => true,
			'offer_id'      => $offer_id,
			'belongs'       => $lead_id > 0 && (int) $row['lead_id'] === $lead_id,

			/*
			 * Uchwyt do podpisanego linku. `request_id` jest UUID-em, więc z adresu
			 * nie da się odczytać, ile ofert wystawiła firma; identyfikator to
			 * wariant zapasowy dla ofert sprzed jego wprowadzenia — bezpieczny, bo
			 * o dostępie i tak decyduje podpis, nie uchwyt.
			 */
			'handle'        => '' !== $request_id ? $request_id : (string) $offer_id,
			'offer_number'  => isset( $row['offer_number'] ) ? (string) $row['offer_number'] : '',
			'status'        => isset( $row['status'] ) ? (string) $row['status'] : '',
			'table_present' => true,
			'from_event'    => $from_event,
		);
	}

	/**
	 * Dane klienta CZYTANE PO `lead_id` z tabeli leadów wtyczki 1.
	 *
	 * To jest realizacja inwariantu I-3: adres odbiorcy nigdy nie pochodzi z
	 * koperty zdarzenia. Wcześniej pochodził — hak `mp_lead_created` przynosił
	 * `client.email`, a Dział 8 zapisywał go do procesu. Wystarczyłoby, żeby
	 * dowolna wtyczka na tej instalacji wywołała ten sam hak z własnym adresem,
	 * a firmowa oferta poszłaby pod adres podstawiony przez nią, nie pod adres z
	 * bazy leadów. Koperta niesie od teraz wyłącznie IDENTYFIKATORY.
	 *
	 * Odczyt idzie w tym samym strzale co reszta snapshotu, więc licznik
	 * `db_reads` się nie zmienia (I-5).
	 *
	 * @param MP_SW_Context $context Kontekst.
	 * @return array
	 */
	private static function read_lead( MP_SW_Context $context ) {
		global $wpdb;

		$event   = (array) $context->get( 'event', array() );
		$entity  = isset( $event['entity'] ) ? (array) $event['entity'] : array();
		$lead_id = isset( $entity['lead_id'] ) ? (int) $entity['lead_id'] : 0;

		$empty = array(
			'exists'  => false,
			'lead_id' => $lead_id,
			'email'   => '',
			'name'    => '',
			'country' => '',
			'segment' => '',
		);

		if ( $lead_id < 1 ) {
			return $empty;
		}

		$table = $wpdb->prefix . 'mp_leads';

		// Wtyczka 1 może być nieaktywna (LP.3 działa też samodzielnie) — brak
		// tabeli nie jest wtedy błędem, tylko brakiem danych do uzupełnienia.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $found !== $table ) {
			return $empty;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT email, company_name, country, segment FROM {$table} WHERE id = %d AND deleted_at IS NULL LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lead_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'exists'  => true,
			'lead_id' => $lead_id,
			'email'   => sanitize_email( (string) $row['email'] ),
			'name'    => sanitize_text_field( (string) $row['company_name'] ),
			'country' => sanitize_text_field( (string) $row['country'] ),
			'segment' => sanitize_text_field( (string) $row['segment'] ),
		);
	}

	/**
	 * Skład zespołu aktora — podstawa zakresu „procesy zespołu" (I-4).
	 *
	 * Lista powstaje TUTAJ, w jedynym strzale odczytu, a nie w Dziale 3 przy
	 * liczeniu zakresu ani w pulpicie przy budowie zapytania. Gdyby powstawała
	 * tam, zakres brałby się z zapytania wykonanego już PO decyzji o dostępie —
	 * a wtedy „zespół" znaczyłoby co innego przy sprawdzaniu, a co innego przy
	 * wyświetlaniu.
	 *
	 * @param array $actor Dane aktora zebrane do tej pory.
	 * @return int[]
	 */
	private static function read_team_members( array $actor ) {
		$team = isset( $actor['team'] ) ? (string) $actor['team'] : '';

		if ( '' === $team || empty( $actor['view_team'] ) ) {
			return array();
		}

		$ids = get_users(
			array(
				'meta_key'   => self::META_TEAM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $team, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
				'number'     => 500,
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Stan procesu dla leada ze zdarzenia — czytany dokładnie raz.
	 *
	 * @param MP_SW_Context $context Kontekst.
	 * @return array
	 */
	private static function read_flow( MP_SW_Context $context ) {
		global $wpdb;

		$event   = (array) $context->get( 'event', array() );
		$entity  = isset( $event['entity'] ) ? (array) $event['entity'] : array();
		$lead_id = isset( $entity['lead_id'] ) ? (int) $entity['lead_id'] : 0;

		$empty = array(
			'exists'       => false,
			'row'          => null,
			'lock_version' => 0,
			'lead_id'      => $lead_id,
			'open_tasks'   => array(),
		);

		if ( $lead_id <= 0 ) {
			return $empty;
		}

		$table = MP_Sales_Workflow_DB::flow_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE lead_id = %d", $lead_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'exists'       => true,
			'row'          => $row,
			// Token blokady optymistycznej idzie do koperty i wraca do Działu 8
			// przy zapisie — to on rozstrzyga, czy ktoś zmienił wiersz w międzyczasie.
			'lock_version' => isset( $row['lock_version'] ) ? (int) $row['lock_version'] : 0,
			'lead_id'      => $lead_id,
			// Oczekujące zadania follow-up wchodzą do TEGO SAMEGO strzału odczytu.
			// Dział 6 musi wiedzieć, co już jest otwarte (deduplikacja K6.2) i który
			// wartownik obowiązuje (kryterium 4.5), a sam do bazy sięgnąć nie może —
			// działy 3–7 są czystymi funkcjami.
			'open_tasks'   => self::read_open_tasks( (int) $row['id'] ),
		);
	}

	/**
	 * Oczekujące zadania danego procesu.
	 *
	 * @param int $flow_id Identyfikator procesu.
	 * @return array
	 */
	private static function read_open_tasks( $flow_id ) {
		global $wpdb;

		if ( $flow_id <= 0 ) {
			return array();
		}

		$table = MP_Sales_Workflow_DB::tasks_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, due_at, guard_status, status, event_id FROM {$table} WHERE flow_id = %d AND status = %s ORDER BY due_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$flow_id,
				MP_SW_D6_Scheduler::STATUS_PENDING
			),
			ARRAY_A
		);

		$tasks = array();

		foreach ( (array) $rows as $task ) {
			$tasks[] = array(
				'id'           => (int) $task['id'],
				'type'         => (string) $task['type'],
				'due_at'       => (string) $task['due_at'],
				'guard_status' => (string) $task['guard_status'],
				'status'       => (string) $task['status'],
				'event_id'     => (string) $task['event_id'],
			);
		}

		return $tasks;
	}

	/**
	 * Obciążenie handlowców liczone Z DANYCH, nie z licznika w pamięci.
	 *
	 * @return array
	 */
	private static function read_workload() {
		global $wpdb;

		$table        = MP_Sales_Workflow_DB::flow_table();
		$statuses     = self::OPEN_STATUSES;
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "SELECT assigned_user_id, COUNT(*) AS open_count, MAX(assigned_at) AS last_assigned_at
			FROM {$table}
			WHERE assigned_user_id IS NOT NULL AND status IN ({$placeholders})
			GROUP BY assigned_user_id";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $statuses ), ARRAY_A );

		$workload = array();

		foreach ( (array) $rows as $row ) {
			$workload[ (int) $row['assigned_user_id'] ] = array(
				'open_count'       => (int) $row['open_count'],
				'last_assigned_at' => (string) $row['last_assigned_at'],
			);
		}

		return $workload;
	}

	/**
	 * Szablony powiadomień wraz z wykazem braków wersji/języka.
	 *
	 * @return array
	 */
	private static function read_templates() {
		$set = MP_SW_Templates::all();

		return array(
			'set'  => $set,
			'gaps' => MP_SW_Templates::find_gaps( $set, MP_SW_D1::LANGS ),
		);
	}
}

/**
 * A2.1 „handlowcy" — mapa handlowców z konfiguracją.
 */
class MP_SW_D2_Agent_Salesmen extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot = MP_SW_D2_Reader::snapshot( $context );

		return MP_SW_Result::ok( array( 'salesmen' => $snapshot['salesmen'] ) );
	}
}

/**
 * K2.1 „kompletność-mapy" — nikt nie może zniknąć po cichu.
 */
class MP_SW_D2_Critic_Map extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data     = $agent_result->get_data();
		$salesmen = isset( $data['salesmen'] ) ? (array) $data['salesmen'] : array();

		foreach ( array( 'complete', 'incomplete', 'total' ) as $key ) {
			if ( ! isset( $salesmen[ $key ] ) ) {
				return MP_SW_Result::fail(
					__( 'Mapa handlowców bez wymaganej sekcji.', 'mp-sales-workflow' ),
					array( 'errors' => array( 'salesmen.' . $key ) ),
					'incomplete_map'
				);
			}
		}

		$counted = count( $salesmen['complete'] ) + count( $salesmen['incomplete'] );

		/*
		 * Sedno kryterium: suma obu list musi zgadzać się z liczbą znalezionych
		 * handlowców. Gdyby czytnik gdziekolwiek "odsiał" niekompletnego, ta
		 * równość by pękła — i o to właśnie chodzi.
		 */
		if ( (int) $salesmen['total'] !== $counted ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: liczba znalezionych handlowców, 2: liczba ujętych w mapie. */
					__( 'Mapa handlowców gubi pozycje: znaleziono %1$d, ujęto %2$d.', 'mp-sales-workflow' ),
					(int) $salesmen['total'],
					$counted
				),
				array( 'errors' => array( 'salesmen.total' ) ),
				'map_lost_entries'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A2.2 „role" — obecność ról wymaganych przez kryterium odbioru.
 */
class MP_SW_D2_Agent_Roles extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot = MP_SW_D2_Reader::snapshot( $context );

		return MP_SW_Result::ok( array( 'roles' => $snapshot['roles'] ) );
	}
}

/**
 * K2.2 „role-istnieją" — brak którejkolwiek roli zatrzymuje pipeline.
 */
class MP_SW_D2_Critic_Roles extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data  = $agent_result->get_data();
		$roles = isset( $data['roles'] ) ? (array) $data['roles'] : array();

		if ( empty( $roles['ok'] ) ) {
			$missing = isset( $roles['missing'] ) ? (array) $roles['missing'] : array();

			/*
			 * Błąd krytyczny, nie ostrzeżenie: bez ról Dział 3 nie ma czego
			 * egzekwować, więc kryterium odbioru o działających rolach byłoby
			 * spełnione tylko pozornie.
			 */
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: lista brakujących ról. */
					__( 'Brak wymaganych ról: %s', 'mp-sales-workflow' ),
					implode( ', ', $missing )
				),
				array(
					'errors'      => $missing,
					'fatal'       => true,
					'http_status' => 500,
				),
				'roles_missing'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A2.3 „stan procesu" — bieżący wiersz procesu wraz z tokenem blokady.
 */
class MP_SW_D2_Agent_Flow_State extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot = MP_SW_D2_Reader::snapshot( $context );
		$flow     = $snapshot['flow'];

		return MP_SW_Result::ok(
			array(
				'flow'         => $flow,
				'lock_version' => $flow['lock_version'],
			)
		);
	}
}

/**
 * K2.3 „świeżość-stanu" — stan czytany raz, token blokady w kopercie.
 */
class MP_SW_D2_Critic_Freshness extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( ! isset( $data['flow']['exists'] ) || ! array_key_exists( 'lock_version', $data ) ) {
			return MP_SW_Result::fail(
				__( 'Stan procesu bez tokenu blokady — Dział 8 nie wykryłby równoległej zmiany.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'lock_version' ) ),
				'missing_lock_version'
			);
		}

		if ( $context->get_db_reads() > 1 ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %d: liczba wykonanych odczytów. */
					__( 'Stan czytany więcej niż raz (odczytów: %d).', 'mp-sales-workflow' ),
					$context->get_db_reads()
				),
				array( 'errors' => array( 'db_reads' ) ),
				'multiple_reads'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A2.4 „obciążenie" — liczba otwartych procesów i data ostatniego przypisania.
 */
class MP_SW_D2_Agent_Workload extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot = MP_SW_D2_Reader::snapshot( $context );
		$workload = $snapshot['workload'];

		/*
		 * Handlowiec bez ani jednego procesu nie pojawi się w wyniku grupowania,
		 * a rotacja musi widzieć go jako kandydata z zerowym obciążeniem —
		 * inaczej nowy pracownik nigdy nie dostałby pierwszego leada.
		 */
		foreach ( $snapshot['salesmen']['complete'] as $salesman ) {
			$id = (int) $salesman['user_id'];

			if ( ! isset( $workload[ $id ] ) ) {
				$workload[ $id ] = array(
					'open_count'       => 0,
					'last_assigned_at' => '',
				);
			}
		}

		return MP_SW_Result::ok( array( 'workload' => $workload ) );
	}
}

/**
 * K2.4 „sprawiedliwość-rotacji" — obciążenie policzone dla każdego kandydata.
 */
class MP_SW_D2_Critic_Rotation extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data     = $agent_result->get_data();
		$workload = isset( $data['workload'] ) ? (array) $data['workload'] : array();
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$salesmen = isset( $snapshot['salesmen']['complete'] ) ? (array) $snapshot['salesmen']['complete'] : array();

		$without = array();

		foreach ( $salesmen as $salesman ) {
			$id = (int) $salesman['user_id'];

			if ( ! isset( $workload[ $id ]['open_count'] ) || ! array_key_exists( 'last_assigned_at', $workload[ $id ] ) ) {
				$without[] = $id;
			}
		}

		if ( ! empty( $without ) ) {
			/*
			 * Brak pozycji w mapie obciążenia oznaczałby, że rotacja opiera się
			 * na czymś innym niż dane z bazy — a tego zabrania kryterium K2.4.
			 */
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: lista identyfikatorów handlowców. */
					__( 'Obciążenie nie policzone dla handlowców: %s', 'mp-sales-workflow' ),
					implode( ', ', $without )
				),
				array( 'errors' => $without ),
				'workload_incomplete'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A2.5 „szablony" — zestaw szablonów powiadomień wraz z wersjami.
 */
class MP_SW_D2_Agent_Templates extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot = MP_SW_D2_Reader::snapshot( $context );

		return MP_SW_Result::ok( array( 'templates' => $snapshot['templates'] ) );
	}
}

/**
 * K2.5 „wersja-szablonu" — szablon bez wersji jest nieodtwarzalny.
 */
class MP_SW_D2_Critic_Template_Version extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();
		$gaps = isset( $data['templates']['gaps'] ) ? (array) $data['templates']['gaps'] : array();

		if ( ! empty( $gaps ) ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: lista szablonów bez wersji lub bez wersji językowej. */
					__( 'Szablony niekompletne (brak wersji lub języka): %s', 'mp-sales-workflow' ),
					implode( ', ', $gaps )
				),
				array( 'errors' => $gaps ),
				'template_gaps'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * QA2 — bramka jakości: jeden odczyt i pięć sekcji snapshotu.
 */
class MP_SW_D2_QA_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$missing  = array();

		foreach ( MP_SW_D2_Reader::sections() as $section ) {
			if ( ! isset( $snapshot[ $section ] ) ) {
				$missing[] = $section;
			}
		}

		/*
		 * WALIDACJA DOMENOWA OFERTY (BLOK A).
		 *
		 * Zdarzenie z haka jest tak samo niesprawdzone jak żądanie HTTP — inna
		 * wtyczka na tej samej instalacji może wywołać `mp_offer_approved` z
		 * dowolną parą identyfikatorów. Wcześniej para była przyjmowana na słowo:
		 * nikt nie pytał, czy oferta istnieje ani czy dotyczy TEGO leada, więc
		 * podanie cudzego `offer_id` wysyłało klientowi A dokument klienta B.
		 *
		 * Sprawdzamy tylko wtedy, gdy tabela ofert w ogóle jest — bez wtyczki 2
		 * nie ma czego weryfikować, a LP.3 ma działać także samodzielnie.
		 */
		$offer      = isset( $snapshot['offer'] ) ? (array) $snapshot['offer'] : array();
		$offer_fail = '';

		/*
		 * Sprawdzamy WYŁĄCZNIE ofertę podaną w kopercie. Numer wzięty z wiersza
		 * procesu przeszedł tę kontrolę wtedy, gdy tam trafiał, i nie jest już
		 * wektorem podszycia — a bywa nieaktualny, bo ofertę można w module
		 * ofertowym usunąć. Traktowanie go tak samo ostro zamrażałoby proces:
		 * handlowiec nie mógłby nawet oznaczyć sprzedaży jako przegranej, bo
		 * pipeline odbijałby się o nieistniejący dokument.
		 */
		if ( ! empty( $offer['offer_id'] ) && ! empty( $offer['table_present'] ) && ! empty( $offer['from_event'] ) ) {
			if ( empty( $offer['exists'] ) ) {
				$offer_fail = 'offer_missing';
			} elseif ( empty( $offer['belongs'] ) ) {
				$offer_fail = 'offer_foreign';
			}
		}

		return MP_SW_Result::ok(
			array(
				'qa_missing_sections' => $missing,
				'qa_db_reads'         => $context->get_db_reads(),
				'qa_offer_fail'       => $offer_fail,
			)
		);
	}
}

/**
 * QA2 krytyk — brak sekcji albo więcej niż jeden odczyt zatrzymuje pipeline.
 */
class MP_SW_D2_QA_Critic extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data    = $agent_result->get_data();
		$missing = isset( $data['qa_missing_sections'] ) ? (array) $data['qa_missing_sections'] : array();
		$reads   = isset( $data['qa_db_reads'] ) ? (int) $data['qa_db_reads'] : 0;
		$offer   = isset( $data['qa_offer_fail'] ) ? (string) $data['qa_offer_fail'] : '';

		if ( '' !== $offer ) {
			// 404, nie 422: „oferta nie należy do tego leada" potwierdzałoby, że
			// oferta o tym numerze istnieje. Tak samo jak przy cudzym procesie.
			return MP_SW_Result::fail(
				__( 'Nie znaleziono oferty wskazanej w zdarzeniu.', 'mp-sales-workflow' ),
				array(
					'errors'      => array( 'entity.offer_id' ),
					'http_status' => 404,
				),
				'flow_not_found'
			);
		}

		if ( ! empty( $missing ) ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: lista brakujących sekcji snapshotu. */
					__( 'Snapshot bez sekcji: %s', 'mp-sales-workflow' ),
					implode( ', ', $missing )
				),
				array(
					'errors' => $missing,
					'fatal'  => true,
				),
				'snapshot_incomplete'
			);
		}

		if ( 1 !== $reads ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %d: liczba odczytów bazy w żądaniu. */
					__( 'Naruszona zasada jednego odczytu (db_reads = %d).', 'mp-sales-workflow' ),
					$reads
				),
				array(
					'errors' => array( 'db_reads' ),
					'fatal'  => true,
				),
				'read_budget_exceeded'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * STRZAŁ ODCZYTU — BD-1.
 */
class MP_SW_Department_02 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 2;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'strzal-odczytu-bd-1';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_D2_Agent_Salesmen( '2.1', 'handlowcy', 'Mapa handlowców: kraj, języki, zespół, gotowość do przyjmowania procesów' ),
				'critic' => new MP_SW_D2_Critic_Map( 'K2.1', 'kompletność-mapy', 'Handlowiec bez kompletu meta trafia na listę braków, nie znika po cichu' ),
			),
			array(
				'agent'  => new MP_SW_D2_Agent_Roles( '2.2', 'role', 'Sprawdza obecność ról: administrator, manager sprzedaży, handlowiec' ),
				'critic' => new MP_SW_D2_Critic_Roles( 'K2.2', 'role-istnieją', 'Brak którejkolwiek roli = FAIL_FATAL — kryterium 5.4 nie zadziała' ),
			),
			array(
				'agent'  => new MP_SW_D2_Agent_Flow_State( '2.3', 'stan procesu', 'Bieżący wiersz procesu dla leada wraz z tokenem blokady' ),
				'critic' => new MP_SW_D2_Critic_Freshness( 'K2.3', 'świeżość-stanu', 'Stan czytany raz; wersja wiersza (updated_at) idzie do koperty' ),
			),
			array(
				'agent'  => new MP_SW_D2_Agent_Workload( '2.4', 'obciążenie', 'Liczba otwartych procesów i data ostatniego przypisania per handlowiec' ),
				'critic' => new MP_SW_D2_Critic_Rotation( 'K2.4', 'sprawiedliwość-rotacji', 'Rotacja liczona z danych, nie z pamięci procesu' ),
			),
			array(
				'agent'  => new MP_SW_D2_Agent_Templates( '2.5', 'szablony', 'Zestaw szablonów powiadomień wraz z wersjami i wariantami językowymi' ),
				'critic' => new MP_SW_D2_Critic_Template_Version( 'K2.5', 'wersja-szablonu', 'Szablon bez wersji jest nieodtwarzalny w dzienniku wysyłek' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_D2_QA_Agent( 'QA2', 'bramka jakosci D2', 'jeden-odczyt: db_reads = 1, pięć sekcji snapshotu' ),
			new MP_SW_D2_QA_Critic( 'QA2.K', 'krytyk bramki D2', 'brak sekcji = FAIL_FATAL' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'STRZAŁ ODCZYTU — BD-1',
			'BD-1 (pkt 3): role · kraj · zespół · język',
			$pairs,
			$gate
		);
	}
}
