<?php
/**
 * Dzial 4 pipeline LP.3 — PRZYPISANIE HANDLOWCA.
 *
 * Zakres wg diagramu: pkt 2: przypisanie handlowca · aut. 4.2
 *
 * Operacje dzialu:
 *  - Dobór: kraj × język × zakres
 *  - Rotacja po ostatnim przypisaniu
 *  - Fallback zespołu przy braku kandydata
 *
 * Zrodlo (Golden Rule #2): docs/dzial-04/przypisanie-handlowca.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: reguły wg zlecenia + usermeta mp_* (konfiguracja)
 *
 * Dzial jest CZYSTA FUNKCJA nad snapshotem z Dzialu 2 — nie czyta bazy i nic
 * nie zapisuje. Wybor musi byc DETERMINISTYCZNY: te same dane wejsciowe zawsze
 * daja to samo przypisanie, bo inaczej krytyk K4.3 nie mialby czego sprawdzic,
 * a ponowione zdarzenie przydzielilo by leada komu innemu.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Algorytm doboru i rotacji — wydzielony, zeby krytyk mogl go przeliczyc.
 */
class MP_SW_D4_Assigner {

	/** Dopasowanie pełne: kraj i język. */
	const MATCH_EXACT = 'exact';

	/** Dopasowanie po kraju, z pominięciem języka. */
	const MATCH_COUNTRY = 'country';

	/** Dopasowanie po języku, z pominięciem kraju. */
	const MATCH_LANG = 'lang';

	/** Ostatnia deska ratunku: dowolny aktywny handlowiec. */
	const MATCH_ANY = 'any';

	/**
	 * Kandydaci pasujący do kraju i języka procesu.
	 *
	 * Zwraca cztery kręgi dopasowania — od najlepszego do awaryjnego. Dzięki
	 * temu „fallback zamiast pustki" (K4.2) jest jawnym stopniem, a nie
	 * przypadkiem: widać, na którym poziomie znaleziono właściciela.
	 *
	 * @param array  $salesmen Sekcja `salesmen.complete` ze snapshotu.
	 * @param string $country  Kraj procesu (ISO-2).
	 * @param string $lang     Język procesu.
	 * @return array<string,array>
	 */
	public static function candidate_tiers( array $salesmen, $country, $lang ) {
		$tiers = array(
			self::MATCH_EXACT   => array(),
			self::MATCH_COUNTRY => array(),
			self::MATCH_LANG    => array(),
			self::MATCH_ANY     => array(),
		);

		foreach ( $salesmen as $salesman ) {
			if ( empty( $salesman['active'] ) ) {
				continue;
			}

			$has_country = ( '' !== $country && strtoupper( $salesman['country'] ) === strtoupper( $country ) );
			$has_lang    = ( '' !== $lang && in_array( strtolower( $lang ), array_map( 'strtolower', (array) $salesman['langs'] ), true ) );

			$tiers[ self::MATCH_ANY ][] = $salesman;

			if ( $has_country && $has_lang ) {
				$tiers[ self::MATCH_EXACT ][] = $salesman;
			}

			if ( $has_country ) {
				$tiers[ self::MATCH_COUNTRY ][] = $salesman;
			}

			if ( $has_lang ) {
				$tiers[ self::MATCH_LANG ][] = $salesman;
			}
		}

		return $tiers;
	}

	/**
	 * Wybiera właściciela procesu spośród kandydatów.
	 *
	 * Kolejność rozstrzygania: najmniejsze obciążenie, potem najdawniejsze
	 * przypisanie, na końcu najniższy identyfikator. Ostatni warunek nie jest
	 * ozdobnikiem — bez niego dwaj handlowcy o identycznym obciążeniu i bez
	 * historii dawaliby wynik zależny od kolejności zwróconej przez bazę, a
	 * wtedy „to samo zdarzenie daje to samo przypisanie" (K4.3) przestaje być
	 * prawdą.
	 *
	 * @param array $candidates Lista kandydatów.
	 * @param array $workload   Mapa obciążenia ze snapshotu.
	 * @return array|null
	 */
	public static function pick( array $candidates, array $workload ) {
		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			static function ( $a, $b ) use ( $workload ) {
				$a_id = (int) $a['user_id'];
				$b_id = (int) $b['user_id'];

				$a_load = isset( $workload[ $a_id ]['open_count'] ) ? (int) $workload[ $a_id ]['open_count'] : 0;
				$b_load = isset( $workload[ $b_id ]['open_count'] ) ? (int) $workload[ $b_id ]['open_count'] : 0;

				if ( $a_load !== $b_load ) {
					return $a_load < $b_load ? -1 : 1;
				}

				$a_last = isset( $workload[ $a_id ]['last_assigned_at'] ) ? (string) $workload[ $a_id ]['last_assigned_at'] : '';
				$b_last = isset( $workload[ $b_id ]['last_assigned_at'] ) ? (string) $workload[ $b_id ]['last_assigned_at'] : '';

				if ( $a_last !== $b_last ) {
					// Pusty ciąg (nigdy nie przypisano) wypada najwcześniej — i dobrze:
					// nowy handlowiec ma pierwszeństwo w rotacji.
					return strcmp( $a_last, $b_last );
				}

				return $a_id < $b_id ? -1 : 1;
			}
		);

		return $candidates[0];
	}

	/**
	 * Pełna decyzja o przypisaniu wraz z uzasadnieniem.
	 *
	 * @param array  $salesmen Sekcja `salesmen.complete`.
	 * @param array  $workload Mapa obciążenia.
	 * @param string $country  Kraj procesu.
	 * @param string $lang     Język procesu.
	 * @return array
	 */
	public static function decide( array $salesmen, array $workload, $country, $lang ) {
		$tiers = self::candidate_tiers( $salesmen, $country, $lang );

		foreach ( array( self::MATCH_EXACT, self::MATCH_COUNTRY, self::MATCH_LANG, self::MATCH_ANY ) as $tier ) {
			$picked = self::pick( $tiers[ $tier ], $workload );

			if ( null === $picked ) {
				continue;
			}

			$id = (int) $picked['user_id'];

			return array(
				'user_id'    => $id,
				'match'      => $tier,
				'fallback'   => self::MATCH_EXACT !== $tier,
				'country'    => $country,
				'lang'       => $lang,
				'open_count' => isset( $workload[ $id ]['open_count'] ) ? (int) $workload[ $id ]['open_count'] : 0,
				'last'       => isset( $workload[ $id ]['last_assigned_at'] ) ? (string) $workload[ $id ]['last_assigned_at'] : '',
				'pool'       => count( $tiers[ $tier ] ),
			);
		}

		return array(
			'user_id'    => 0,
			'match'      => '',
			'fallback'   => true,
			'country'    => $country,
			'lang'       => $lang,
			'open_count' => 0,
			'last'       => '',
			'pool'       => 0,
		);
	}
}

/**
 * A4.1 „dobór" — kandydaci wg kraju, języka i gotowości.
 */
class MP_SW_D4_Agent_Match extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$salesmen = isset( $snapshot['salesmen']['complete'] ) ? (array) $snapshot['salesmen']['complete'] : array();
		$flow     = (array) $context->get( 'flow', array() );
		$event    = (array) $context->get( 'event', array() );

		// Kraj i język biorą się z istniejącego procesu, a gdy go nie ma — z
		// danych zdarzenia przekazanych przez wtyczkę 1.
		$country = isset( $flow['row']['country'] ) && '' !== (string) $flow['row']['country']
			? (string) $flow['row']['country']
			: (string) $context->get( 'country', '' );

		$lang = isset( $flow['row']['lang'] ) && '' !== (string) $flow['row']['lang']
			? (string) $flow['row']['lang']
			: ( isset( $event['lang'] ) ? (string) $event['lang'] : 'pl' );

		$tiers = MP_SW_D4_Assigner::candidate_tiers( $salesmen, $country, $lang );

		return MP_SW_Result::ok(
			array(
				'match_country'    => $country,
				'match_lang'       => $lang,
				'candidate_counts' => array_map( 'count', $tiers ),
				'candidate_ids'    => array_map(
					static function ( $tier ) {
						return array_map( 'intval', wp_list_pluck( $tier, 'user_id' ) );
					},
					$tiers
				),
			)
		);
	}
}

/**
 * K4.1 „dopasowanie-kandydata" — kandydat musi pochodzić ze snapshotu.
 */
class MP_SW_D4_Critic_Candidate extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data     = $agent_result->get_data();
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$known    = array_map( 'intval', wp_list_pluck( (array) $snapshot['salesmen']['complete'], 'user_id' ) );

		$unknown = array();

		foreach ( (array) $data['candidate_ids'] as $ids ) {
			foreach ( (array) $ids as $id ) {
				if ( ! in_array( (int) $id, $known, true ) ) {
					$unknown[] = (int) $id;
				}
			}
		}

		if ( ! empty( $unknown ) ) {
			// Kandydat spoza snapshotu oznaczałby, że dobór sięgnął po dane z
			// innego źródła niż jeden strzał odczytu Działu 2.
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: lista identyfikatorów spoza snapshotu. */
					__( 'Kandydaci spoza snapshotu: %s', 'mp-sales-workflow' ),
					implode( ', ', array_unique( $unknown ) )
				),
				array( 'errors' => array_values( array_unique( $unknown ) ) ),
				'candidate_not_in_snapshot'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A4.2 „rotacja" — wybór właściciela wraz z awaryjnym rozszerzeniem puli.
 */
class MP_SW_D4_Agent_Rotation extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$salesmen = isset( $snapshot['salesmen']['complete'] ) ? (array) $snapshot['salesmen']['complete'] : array();
		$workload = (array) $context->get( 'workload', array() );
		$flow     = (array) $context->get( 'flow', array() );

		$current = isset( $flow['row']['assigned_user_id'] ) ? (int) $flow['row']['assigned_user_id'] : 0;

		/*
		 * Proces, który ma już właściciela, NIE jest przydzielany ponownie przy
		 * każdym zdarzeniu — inaczej zmiana statusu albo zatwierdzenie oferty
		 * przerzucałyby klienta między handlowcami w trakcie rozmów.
		 */
		if ( $current > 0 ) {
			return MP_SW_Result::ok(
				array(
					'assignment' => array(
						'user_id'    => $current,
						'match'      => 'kept',
						'fallback'   => false,
						'country'    => (string) $context->get( 'match_country', '' ),
						'lang'       => (string) $context->get( 'match_lang', '' ),
						'open_count' => isset( $workload[ $current ]['open_count'] ) ? (int) $workload[ $current ]['open_count'] : 0,
						'last'       => isset( $workload[ $current ]['last_assigned_at'] ) ? (string) $workload[ $current ]['last_assigned_at'] : '',
						'pool'       => 1,
					),
					'reassigned' => false,
				)
			);
		}

		$decision = MP_SW_D4_Assigner::decide(
			$salesmen,
			$workload,
			(string) $context->get( 'match_country', '' ),
			(string) $context->get( 'match_lang', '' )
		);

		return MP_SW_Result::ok(
			array(
				'assignment' => $decision,
				'reassigned' => $decision['user_id'] > 0,
			)
		);
	}
}

/**
 * K4.2 „pokrycie" — proces nigdy nie zostaje bez właściciela.
 */
class MP_SW_D4_Critic_Coverage extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data       = $agent_result->get_data();
		$assignment = isset( $data['assignment'] ) ? (array) $data['assignment'] : array();

		if ( empty( $assignment['user_id'] ) ) {
			$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
			$total    = isset( $snapshot['salesmen']['total'] ) ? (int) $snapshot['salesmen']['total'] : 0;

			return MP_SW_Result::fail(
				0 === $total
					? __( 'Brak jakiegokolwiek handlowca — nie ma komu przypisać procesu.', 'mp-sales-workflow' )
					: __( 'Nie wybrano właściciela procesu mimo dostępnych handlowców.', 'mp-sales-workflow' ),
				array(
					'errors' => array( 'assignment.user_id' ),
					'fatal'  => true,
				),
				'no_owner'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A4.3 „uzasadnienie" — czytelny zapis, dlaczego wybrano tego handlowca.
 */
class MP_SW_D4_Agent_Reason extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$assignment = (array) $context->get( 'assignment', array() );

		$parts = array(
			'match=' . ( isset( $assignment['match'] ) ? $assignment['match'] : '' ),
			'country=' . ( isset( $assignment['country'] ) ? $assignment['country'] : '' ),
			'lang=' . ( isset( $assignment['lang'] ) ? $assignment['lang'] : '' ),
			'open=' . ( isset( $assignment['open_count'] ) ? (int) $assignment['open_count'] : 0 ),
			'pool=' . ( isset( $assignment['pool'] ) ? (int) $assignment['pool'] : 0 ),
		);

		if ( ! empty( $assignment['fallback'] ) ) {
			$parts[] = 'fallback=1';
		}

		return MP_SW_Result::ok(
			array(
				'assign_reason'   => implode( ' ', $parts ),
				'assign_fallback' => ! empty( $assignment['fallback'] ),
			)
		);
	}
}

/**
 * K4.3 „odtwarzalność-decyzji" — ten sam snapshot musi dać ten sam wynik.
 */
class MP_SW_D4_Critic_Reproducible extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data       = $agent_result->get_data();
		$assignment = (array) $context->get( 'assignment', array() );

		if ( '' === trim( (string) $data['assign_reason'] ) ) {
			return MP_SW_Result::fail(
				__( 'Przypisanie bez uzasadnienia.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'assign_reason' ) ),
				'missing_reason'
			);
		}

		// Proces z zachowanym właścicielem nie przechodził przez dobór — nie ma
		// czego przeliczać.
		if ( isset( $assignment['match'] ) && 'kept' === $assignment['match'] ) {
			return MP_SW_Result::ok( $data );
		}

		/*
		 * Krytyk nie wierzy agentowi na słowo: przelicza decyzję drugi raz na
		 * tych samych danych. Jakakolwiek losowość albo zależność od kolejności
		 * zwróconej przez bazę wyszłaby tutaj jako rozjazd identyfikatorów.
		 */
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$again    = MP_SW_D4_Assigner::decide(
			isset( $snapshot['salesmen']['complete'] ) ? (array) $snapshot['salesmen']['complete'] : array(),
			(array) $context->get( 'workload', array() ),
			(string) $context->get( 'match_country', '' ),
			(string) $context->get( 'match_lang', '' )
		);

		if ( (int) $again['user_id'] !== (int) $assignment['user_id'] ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: identyfikator z pierwszego przebiegu, 2: z powtórnego. */
					__( 'Decyzja nieodtwarzalna: %1$d vs %2$d.', 'mp-sales-workflow' ),
					(int) $assignment['user_id'],
					(int) $again['user_id']
				),
				array( 'errors' => array( 'assignment.user_id' ) ),
				'non_deterministic_assignment'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * QA4 — bramka jakości: zawsze ktoś przypisany.
 */
class MP_SW_D4_QA_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$assignment = (array) $context->get( 'assignment', array() );

		return MP_SW_Result::ok(
			array(
				'qa_owner'  => isset( $assignment['user_id'] ) ? (int) $assignment['user_id'] : 0,
				'qa_reason' => (string) $context->get( 'assign_reason', '' ),
			)
		);
	}
}

/**
 * QA4 krytyk — pusty właściciel to błąd krytyczny.
 */
class MP_SW_D4_QA_Critic extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( empty( $data['qa_owner'] ) ) {
			return MP_SW_Result::fail(
				__( 'Proces bez właściciela po Dziale 4.', 'mp-sales-workflow' ),
				array(
					'errors' => array( 'assignment.user_id' ),
					'fatal'  => true,
				),
				'empty_owner'
			);
		}

		if ( '' === trim( (string) $data['qa_reason'] ) ) {
			return MP_SW_Result::fail(
				__( 'Przypisanie bez zapisanego uzasadnienia.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'assign_reason' ) ),
				'empty_reason'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * PRZYPISANIE HANDLOWCA.
 */
class MP_SW_Department_04 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 4;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'przypisanie-handlowca';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_D4_Agent_Match( '4.1', 'dobór', 'Kandydaci wg kraju, języka i gotowości do przyjmowania procesów' ),
				'critic' => new MP_SW_D4_Critic_Candidate( 'K4.1', 'dopasowanie-kandydata', 'Kandydat musi istnieć w snapshocie i mieć rolę mp_handlowiec' ),
			),
			array(
				'agent'  => new MP_SW_D4_Agent_Rotation( '4.2', 'rotacja', 'Wybór właściciela: najmniejsze obciążenie, potem najdawniejsze przypisanie' ),
				'critic' => new MP_SW_D4_Critic_Coverage( 'K4.2', 'pokrycie', 'Proces nigdy nie zostaje bez właściciela — fallback zamiast pustki' ),
			),
			array(
				'agent'  => new MP_SW_D4_Agent_Reason( '4.3', 'uzasadnienie', 'Zapis czynników decyzji: dopasowanie, kraj, język, obciążenie, pula' ),
				'critic' => new MP_SW_D4_Critic_Reproducible( 'K4.3', 'odtwarzalność-decyzji', 'To samo zdarzenie i ten sam snapshot dają to samo przypisanie' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_D4_QA_Agent( 'QA4', 'bramka jakosci D4', 'pokrycie-przypisania: zawsze ktoś przypisany' ),
			new MP_SW_D4_QA_Critic( 'QA4.K', 'krytyk bramki D4', 'pusty właściciel = FAIL_FATAL' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'PRZYPISANIE HANDLOWCA',
			'pkt 2: przypisanie handlowca · aut. 4.2',
			$pairs,
			$gate
		);
	}
}
