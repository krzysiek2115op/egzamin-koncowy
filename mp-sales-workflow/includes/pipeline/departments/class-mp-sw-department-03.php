<?php
/**
 * Dzial 3 pipeline LP.3 — UPRAWNIENIA I ZAKRES ROLI.
 *
 * Zakres wg diagramu: kryt. 5.4: role i uprawnienia
 *
 * Operacje dzialu:
 *  - current_user_can na operację
 *  - Zakres danych wg roli i zespołu
 *
 * Zrodlo (Golden Rule #2): docs/dzial-03/uprawnienia-i-zakres-roli.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: Roles and Capabilities · current_user_can()
 *
 * Dzial jest CZYSTA FUNKCJA: pracuje wylacznie na kopercie i snapshocie z
 * Dzialu 2, nie siega do bazy. Rozstrzyga dwie rzeczy — czy aktor w ogole moze
 * wykonac te operacje (K3.1) i jak szeroki ma widok danych (K3.2).
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slowniki operacji i zakresow.
 */
class MP_SW_D3 {

	/** Widok pełny — procesy całej firmy. */
	const SCOPE_ALL = 'all';

	/** Widok zespołu. */
	const SCOPE_TEAM = 'team';

	/** Widok własnych procesów. */
	const SCOPE_OWN = 'own';

	/**
	 * Operacja wymagana przez dany typ zdarzenia.
	 *
	 * @param string $type Typ zdarzenia.
	 * @return string
	 */
	public static function operation_for( $type ) {
		$map = array(
			MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED   => 'process.create',
			MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED => 'process.update',
			MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE  => 'process.update',
			MP_SW_Pipeline_Factory::EVENT_TASK_DUE       => 'task.execute',
			MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW => 'dashboard.read',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}

	/**
	 * Czy zdarzenie pochodzi z automatu (hook albo timer).
	 *
	 * @param string $source Źródło zdarzenia.
	 * @return bool
	 */
	public static function is_automated( $source ) {
		return in_array( $source, array( MP_SW_D1::SOURCE_SYSTEM, MP_SW_D1::SOURCE_CRON ), true );
	}

	/**
	 * Czy właściciel procesu mieści się w zasięgu aktora.
	 *
	 * Kolejność jest istotna: najpierw pełny widok, potem zespół — sprawdzany
	 * po LIŚCIE ze snapshotu, nie po nazwie zespołu właściciela. Porównanie nazw
	 * wymagałoby doczytania `mp_team` właściciela, czyli kolejnego strzału do
	 * bazy poza jedynym odczytem (I-5).
	 *
	 * @param array $actor Dane aktora.
	 * @param int   $owner Właściciel procesu.
	 * @return bool
	 */
	public static function in_reach( array $actor, $owner ) {
		if ( ! empty( $actor['manage_all'] ) ) {
			return true;
		}

		if ( empty( $actor['view_team'] ) ) {
			return false;
		}

		return in_array( (int) $owner, array_map( 'intval', (array) $actor['team_members'] ), true );
	}
}

/**
 * A3.1 „aktor" — kim jest wywołujący i czy wolno mu wykonać tę operację.
 */
class MP_SW_D3_Agent_Actor extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$event     = (array) $context->get( 'event', array() );
		$type      = isset( $event['type'] ) ? (string) $event['type'] : '';
		$source    = (string) $context->get( 'source', '' );
		$operation = MP_SW_D3::operation_for( $type );
		$user_id   = isset( $event['actor']['user_id'] ) ? (int) $event['actor']['user_id'] : 0;

		// Dane aktora pochodzą ze SNAPSHOTU Działu 2 — ten dział nie dotyka bazy.
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$from_db  = isset( $snapshot['roles']['actor'] ) ? (array) $snapshot['roles']['actor'] : array();

		$actor = array(
			'user_id'       => $user_id,
			'roles'         => isset( $from_db['roles'] ) ? (array) $from_db['roles'] : array(),
			'manage_all'    => ! empty( $from_db['manage_all'] ),
			'view_team'     => ! empty( $from_db['view_team'] ),
			'assign'        => ! empty( $from_db['assign'] ),
			'change_status' => ! empty( $from_db['change_status'] ),
			'handles'       => ! empty( $from_db['handles'] ),
			'team'          => isset( $from_db['team'] ) ? (string) $from_db['team'] : '',
			'team_members'  => isset( $from_db['team_members'] ) ? array_map( 'intval', (array) $from_db['team_members'] ) : array(),
			'automated'     => MP_SW_D3::is_automated( $source ),
		);

		/*
		 * Zdarzenia automatyczne (hook z wtyczki 1/2, timer) nie mają aktora z
		 * uprawnieniami — ich prawo do działania rozstrzygnął Dział 1, badając
		 * ŹRÓDŁO. Tu sprawdzamy tylko, czy typ zdarzenia ma w ogóle przypisaną
		 * operację; nieznany typ nie przeszedłby zresztą przez bramę.
		 */
		$allowed = '' !== $operation;
		$reason  = $allowed ? 'operation_known' : 'unknown_operation';

		if ( $allowed && ! $actor['automated'] ) {
			$allowed = ! empty( $actor['handles'] );
			$reason  = $allowed ? 'capability_granted' : 'capability_missing';

			/*
			 * Uprawnienie SZCZEGÓŁOWE dla tego typu zdarzenia. Samo „obsługuje
			 * procesy" odpowiadało na inne pytanie niż to, które trzeba zadać:
			 * przepuszczało handlowca do każdego typu, bo znaczyło tylko tyle, że w
			 * ogóle pracuje w tym module.
			 */
			$needed = MP_SW_Roles::cap_for_event( $type );

			if ( $allowed && '' !== $needed ) {
				$granted = array(
					MP_SW_Roles::CAP_CHANGE_STATUS => ! empty( $actor['change_status'] ),
					MP_SW_Roles::CAP_ASSIGN        => ! empty( $actor['assign'] ),
					MP_SW_Roles::CAP_VIEW_OWN      => ! empty( $actor['handles'] ),
				);

				if ( empty( $granted[ $needed ] ) ) {
					$allowed = false;
					$reason  = 'capability_missing';
				}
			}

			// Operacja na CUDZYM procesie wymaga zakresu, który go obejmuje. Nie ma
			// tu cichego zawężenia "zrobię to, ale tylko na swoim" — albo wolno,
			// albo odmowa z kodem (K3.1).
			if ( $allowed ) {
				$flow  = (array) $context->get( 'flow', array() );
				$owner = isset( $flow['row']['assigned_user_id'] ) ? (int) $flow['row']['assigned_user_id'] : 0;

				if ( $owner > 0 && $owner !== $user_id && ! MP_SW_D3::in_reach( $actor, $owner ) ) {
					$allowed = false;
					$reason  = 'foreign_process';
				}

				/*
				 * Proces NIEISTNIEJĄCY dostaje tę samą odmowę co cudzy — i to jest
				 * cała różnica między 404 a wyrocznią. Gdyby brak procesu kończył
				 * się innym kodem niż cudzy proces (np. 422 „niedozwolone
				 * przejście"), wystarczyłoby przelecieć identyfikatory i po samych
				 * kodach odpowiedzi odczytać, które z nich istnieją. Odmowa musi
				 * wyglądać identycznie w obu przypadkach.
				 *
				 * Podglądu to nie dotyczy: `dashboard.view` nie wskazuje procesu.
				 */
				if ( $allowed && empty( $flow['exists'] ) && MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW !== $type ) {
					$allowed = false;
					$reason  = 'foreign_process';
				}
			}
		}

		return MP_SW_Result::ok(
			array(
				'actor'     => $actor,
				'operation' => $operation,
				'allowed'   => $allowed,
				'reason'    => $reason,
			)
		);
	}
}

/**
 * K3.1 „prawo-do-operacji" — odmowa 403 zamiast cichego zawężenia.
 */
class MP_SW_D3_Critic_Permission extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( empty( $data['allowed'] ) ) {
			$reason = isset( $data['reason'] ) ? (string) $data['reason'] : 'forbidden';

			/*
			 * Cudzy proces to 404, nie 403 — i jest to różnica bezpieczeństwa, nie
			 * kosmetyka. Odpowiedź 403 („nie wolno ci tego procesu") potwierdza, że
			 * proces ISTNIEJE; wystarczy przelecieć identyfikatory i po samych
			 * kodach odpowiedzi da się odczytać, ilu klientów obsługuje konkurencja
			 * i pod jakimi numerami. 404 mówi to samo, co przy numerze zmyślonym.
			 *
			 * Brak uprawnienia jako takiego zostaje przy 403: tam nie ma czego
			 * ukrywać, bo odmowa nie zależy od tego, czy obiekt istnieje.
			 */
			$hidden = 'foreign_process' === $reason;

			return MP_SW_Result::fail(
				$hidden
					? __( 'Nie znaleziono procesu.', 'mp-sales-workflow' )
					: sprintf(
						/* translators: 1: nazwa operacji, 2: powód odmowy. */
						__( 'Operacja %1$s poza uprawnieniami aktora (%2$s).', 'mp-sales-workflow' ),
						isset( $data['operation'] ) ? $data['operation'] : '?',
						$reason
					),
				array(
					'errors'      => array( $hidden ? 'flow' : $reason ),
					'http_status' => $hidden ? 404 : 403,
				),
				$hidden ? 'flow_not_found' : 'operation_forbidden'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A3.2 „zakres" — jak szeroki widok danych przysługuje aktorowi.
 */
class MP_SW_D3_Agent_Scope extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$actor = (array) $context->get( 'actor', array() );

		$automated  = ! empty( $actor['automated'] );
		$manage_all = ! empty( $actor['manage_all'] );
		$view_team  = ! empty( $actor['view_team'] );
		$team       = isset( $actor['team'] ) ? (string) $actor['team'] : '';
		$user_id    = isset( $actor['user_id'] ) ? (int) $actor['user_id'] : 0;

		/*
		 * Zakres zespołu wymaga UPRAWNIENIA, nie samej przynależności. Poprzednia
		 * reguła („ma wpisany mp_team, więc widzi zespół") dawała szerszy widok
		 * każdemu handlowcowi, któremu ktokolwiek ustawił pole zespołu — a to
		 * pole opisuje, GDZIE ktoś pracuje, nie CO wolno mu zobaczyć.
		 */
		if ( $automated || $manage_all ) {
			$scope = MP_SW_D3::SCOPE_ALL;
		} elseif ( $view_team && '' !== $team ) {
			$scope = MP_SW_D3::SCOPE_TEAM;
		} else {
			$scope = MP_SW_D3::SCOPE_OWN;
		}

		/*
		 * Zakres wynika WYŁĄCZNIE z roli i zespołu ze snapshotu. Pole `scope`
		 * przysłane w żądaniu jest tu świadomie ignorowane — gdyby wpływało na
		 * wynik, handlowiec zobaczyłby cudze procesy przez dopisanie jednego
		 * parametru. Zapamiętujemy je tylko po to, żeby krytyk mógł potwierdzić,
		 * że próba rozszerzenia nie odniosła skutku.
		 */
		$requested = $context->get( 'scope', '' );
		$requested = is_string( $requested ) ? $requested : '';

		return MP_SW_Result::ok(
			array(
				'scope'           => $scope,
				'scope_user_id'   => $user_id,
				'scope_team'      => $team,

				/*
				 * Skład zespołu idzie dalej kontekstem, żeby pulpit budował zapytanie
				 * z TEJ listy — tej samej, na której zapadła decyzja o zakresie.
				 * Odczytanie go drugi raz przy wyświetlaniu byłoby drugim strzałem i,
				 * gorzej, drugim źródłem prawdy.
				 */
				'scope_members'   => MP_SW_D3::SCOPE_TEAM === $scope && isset( $actor['team_members'] ) ? array_map( 'intval', (array) $actor['team_members'] ) : array(),
				'scope_requested' => $requested,
			)
		);
	}
}

/**
 * K3.2 „cięcie-zakresu" — parametr żądania nie może rozszerzyć widoku.
 */
class MP_SW_D3_Critic_Scope extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data  = $agent_result->get_data();
		$scope = isset( $data['scope'] ) ? (string) $data['scope'] : '';

		if ( ! in_array( $scope, array( MP_SW_D3::SCOPE_ALL, MP_SW_D3::SCOPE_TEAM, MP_SW_D3::SCOPE_OWN ), true ) ) {
			return MP_SW_Result::fail(
				__( 'Nieznany zakres widoku.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'scope' ) ),
				'unknown_scope'
			);
		}

		$actor      = (array) $context->get( 'actor', array() );
		$manage_all = ! empty( $actor['manage_all'] ) || ! empty( $actor['automated'] );

		// Zakres pełny wolno mieć wyłącznie automatowi albo roli z uprawnieniem
		// managera — niezależnie od tego, co przyszło w żądaniu.
		if ( MP_SW_D3::SCOPE_ALL === $scope && ! $manage_all ) {
			return MP_SW_Result::fail(
				__( 'Zakres pełny bez uprawnienia managera.', 'mp-sales-workflow' ),
				array(
					'errors'      => array( 'scope' ),
					'http_status' => 403,
				),
				'scope_escalation'
			);
		}

		$requested = isset( $data['scope_requested'] ) ? (string) $data['scope_requested'] : '';

		if ( '' !== $requested && ! $manage_all && MP_SW_D3::SCOPE_ALL === $requested && MP_SW_D3::SCOPE_ALL === $scope ) {
			return MP_SW_Result::fail(
				__( 'Parametr żądania rozszerzył zakres widoku.', 'mp-sales-workflow' ),
				array(
					'errors'      => array( 'scope_requested' ),
					'http_status' => 403,
				),
				'scope_from_request'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * QA3 — bramka jakości: operacja i widok dozwolone dla aktora.
 */
class MP_SW_D3_QA_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$missing = array();

		foreach ( array( 'actor', 'operation', 'scope' ) as $key ) {
			if ( null === $context->get( $key ) ) {
				$missing[] = $key;
			}
		}

		$actor      = (array) $context->get( 'actor', array() );
		$manage_all = ! empty( $actor['manage_all'] ) || ! empty( $actor['automated'] );

		return MP_SW_Result::ok(
			array(
				'qa_missing'    => $missing,
				'qa_scope'      => (string) $context->get( 'scope', '' ),
				'qa_manage_all' => $manage_all,
			)
		);
	}
}

/**
 * QA3 krytyk — żaden parametr żądania nie rozszerza zakresu.
 */
class MP_SW_D3_QA_Critic extends MP_SW_Abstract_Critic {

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
					/* translators: %s: lista brakujących elementów zakresu. */
					__( 'Brak rozstrzygnięcia zakresu roli: %s', 'mp-sales-workflow' ),
					implode( ', ', $missing )
				),
				array( 'errors' => $missing ),
				'scope_not_resolved'
			);
		}

		if ( MP_SW_D3::SCOPE_ALL === $data['qa_scope'] && empty( $data['qa_manage_all'] ) ) {
			return MP_SW_Result::fail(
				__( 'Zakres pełny u aktora bez uprawnienia managera.', 'mp-sales-workflow' ),
				array(
					'errors'      => array( 'scope' ),
					'http_status' => 403,
				),
				'scope_escalation'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * UPRAWNIENIA I ZAKRES ROLI.
 */
class MP_SW_Department_03 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 3;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'uprawnienia-i-zakres-roli';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_D3_Agent_Actor( '3.1', 'aktor', 'Ustala rolę wywołującego i sprawdza prawo do wykonania operacji' ),
				'critic' => new MP_SW_D3_Critic_Permission( 'K3.1', 'prawo-do-operacji', 'Operacja spoza uprawnień roli = 403, nigdy ciche zawężenie' ),
			),
			array(
				'agent'  => new MP_SW_D3_Agent_Scope( '3.2', 'zakres', 'Wylicza zakres widoku danych: wszystkie / zespół / własne' ),
				'critic' => new MP_SW_D3_Critic_Scope( 'K3.2', 'cięcie-zakresu', 'Zakres liczony z roli i mp_team ze snapshotu, nie z parametru żądania' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_D3_QA_Agent( 'QA3', 'bramka jakosci D3', 'zakres-roli: operacja i widok dozwolone dla aktora' ),
			new MP_SW_D3_QA_Critic( 'QA3.K', 'krytyk bramki D3', 'parametr żądania nigdy nie rozszerza zakresu' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'UPRAWNIENIA I ZAKRES ROLI',
			'kryt. 5.4: role i uprawnienia',
			$pairs,
			$gate
		);
	}
}
