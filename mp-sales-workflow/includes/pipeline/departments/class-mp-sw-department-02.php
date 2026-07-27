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

		$snapshot = array(
			'salesmen'  => self::read_salesmen(),
			'roles'     => self::read_roles( $context ),
			'flow'      => self::read_flow( $context ),
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
			'user_id'    => $user_id,
			'roles'      => array(),
			'manage_all' => false,
			'team'       => '',
			'exists'     => false,
		);

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );

			if ( $user instanceof WP_User ) {
				$actor['exists']     = true;
				$actor['roles']      = array_values( (array) $user->roles );
				$actor['manage_all'] = user_can( $user, MP_SW_Roles::CAP_MANAGE_ALL );
				$actor['handles']    = user_can( $user, MP_SW_Roles::CAP_HANDLE_EVENT );
				$actor['team']       = (string) get_user_meta( $user_id, self::META_TEAM, true );
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
		);
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

		return MP_SW_Result::ok(
			array(
				'qa_missing_sections' => $missing,
				'qa_db_reads'         => $context->get_db_reads(),
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
