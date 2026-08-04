<?php
/**
 * Dzial 8 pipeline LP.3 — ZAPIS: JEDNA TRANSAKCJA.
 *
 * Zakres wg diagramu: pkt 2: dziennik aktywności · kryt. 5.5
 *
 * Operacje dzialu:
 *  - Jedna transakcja: flow + zadania + kolejka + dziennik
 *  - Konflikt wersji = ponowienie od Działu 2
 *  - Po ROLLBACK kolejka pusta
 *
 * Zrodlo (Golden Rule #2): docs/dzial-08/zapis-jedna-transakcja.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: dbDelta() · MySQL START TRANSACTION / COMMIT
 *
 * To JEDYNY dzial, ktory zapisuje. Transakcji NIE otwiera on sam — robi to
 * pipeline (transactional_from/until = 8), zeby ROLLBACK zadzialal takze wtedy,
 * gdy dzial przerwie sie w polowie albo padnie blad krytyczny. Wszystkie trzy
 * pary — lacznie z dziennikiem (8.3) — biegna WEWNATRZ tej jednej transakcji,
 * wiec wycofanie zabiera ze soba rowniez wpisy dziennika i kolejke powiadomien.
 * Stad "po ROLLBACK kolejka pusta": e-mail-widmo nie ma jak powstac.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stale i reguly zapisu.
 */
class MP_SW_D8_Writer {

	/** Operacja na wierszu procesu: wstawienie. */
	const OP_INSERT = 'insert';

	/** Operacja na wierszu procesu: aktualizacja. */
	const OP_UPDATE = 'update';

	/** Wpis dziennika: zmiana statusu. */
	const LOG_STATUS = 'status.changed';

	/** Wpis dziennika: przypisanie handlowca. */
	const LOG_ASSIGNED = 'lead.assigned';

	/**
	 * Proces zapisany bez właściciela — z podanym powodem.
	 *
	 * Bez tego wpisu proces założony przy pustej liście handlowców nie zostawiał
	 * w dzienniku ŻADNEGO śladu: status się nie zmieniał (zostaje „nowy"),
	 * przypisania nie było, więc dziennik milczał wbrew kryterium 5.5.
	 */
	const LOG_UNASSIGNED = 'lead.unassigned';

	/** Wpis dziennika: zaplanowanie zadania. */
	const LOG_TASK_PLANNED = 'task.planned';

	/** Wpis dziennika: zamknięcie zadania. */
	const LOG_TASK_CLOSED = 'task.closed';

	/** Wpis dziennika: wykonanie zadania. */
	const LOG_TASK_DONE = 'task.done';

	/** Wpis dziennika: powiadomienie w kolejce. */
	const LOG_NOTIFICATION = 'notification.queued';

	/**
	 * Wpis dziennika: powiadomienie WEWNĘTRZNE pominięte (zły adres odbiorcy).
	 *
	 * Powiadomienie do własnego pracownika bez dostarczalnego adresu nie wywraca
	 * już całego zdarzenia (patrz Agent 7.2) — ale nie ma prawa zniknąć po cichu.
	 * Ten wpis jest dowodem, że wiadomość się należała i dlaczego nie poszła;
	 * bez niego kryterium 5.5 („odtworzenie historii wysyłek") przestałoby być
	 * prawdziwe dokładnie w tych przypadkach, w których jest najbardziej potrzebne.
	 */
	const LOG_NOTIFICATION_SKIPPED = 'notification.skipped';

	/**
	 * Limity długości kolumn tekstowych z DDL (BD-1).
	 *
	 * Sprawdzamy je PRZED zapisem, bo MySQL w trybie nieścisłym po cichu obcina
	 * wartość: numer oferty albo adres klienta zostałby zapisany w kawałku i
	 * nikt by tego nie zauważył aż do wysyłki.
	 *
	 * @return array<string,int>
	 */
	public static function limits() {
		return array(
			'lang'             => 5,
			'country'          => 2,
			'offer_number'     => 32,
			'assign_reason'    => 255,
			'segment'          => 64,
			'client_name'      => 191,
			'client_email'     => 191,
			'status'           => 32,
			'type'             => 32,
			'guard_status'     => 32,
			'open_key'         => 64,
			'template'         => 64,
			'template_version' => 16,
			'recipient'        => 191,
			'subject'          => 255,
			'action'           => 64,
			'entity_ref'       => 64,
		);
	}

	/**
	 * Czy wartość ma format datetime MySQL.
	 *
	 * @param string $value Wartość.
	 * @return bool
	 */
	public static function is_datetime( $value ) {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $value );
	}

	/**
	 * Pusty plan zapisu.
	 *
	 * @return array
	 */
	public static function empty_plan() {
		return array(
			'flow'          => array(
				'op'           => '',
				'data'         => array(),
				'flow_id'      => 0,
				'lock_version' => 0,
				'guard'        => '',
			),
			'tasks_create'  => array(),
			'tasks_close'   => array(),
			'notifications' => array(),
			'event'         => array(),
		);
	}
}

/**
 * A8.1 „plan" — komplet operacji do wykonania w jednej transakcji.
 */
class MP_SW_D8_Agent_Plan extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot   = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$flow       = isset( $snapshot['flow'] ) ? (array) $snapshot['flow'] : array();
		$row        = isset( $flow['row'] ) ? (array) $flow['row'] : array();
		$transition = (array) $context->get( 'transition', array() );
		$assignment = (array) $context->get( 'assignment', array() );
		$tasks      = (array) $context->get( 'tasks_plan', MP_SW_D6_Scheduler::empty_plan() );
		$event      = (array) $context->get( 'event', array() );

		/*
		 * Dane klienta bierzemy ze SNAPSHOTU (odczyt po `lead_id` z tabeli leadów),
		 * a nie z koperty zdarzenia — inwariant I-3. Koperta pozostaje źródłem
		 * zapasowym wyłącznie dla procesów prowadzonych bez wtyczki 1, gdzie nie ma
		 * leada do odczytania; tam adres pochodzi od zalogowanego człowieka, nie z
		 * anonimowego wywołania haka.
		 */
		$lead_row = isset( $snapshot['lead'] ) ? (array) $snapshot['lead'] : array();
		$envelope = (array) $context->get( 'client', array() );
		$client   = array(
			'name'    => ! empty( $lead_row['name'] ) ? (string) $lead_row['name'] : ( isset( $envelope['name'] ) ? (string) $envelope['name'] : '' ),
			'email'   => ! empty( $lead_row['email'] ) ? (string) $lead_row['email'] : ( isset( $envelope['email'] ) ? (string) $envelope['email'] : '' ),
			'segment' => ! empty( $lead_row['segment'] ) ? (string) $lead_row['segment'] : ( isset( $envelope['segment'] ) ? (string) $envelope['segment'] : '' ),
		);

		$entity   = isset( $event['entity'] ) ? (array) $event['entity'] : array();
		$lead_id  = isset( $entity['lead_id'] ) ? (int) $entity['lead_id'] : 0;
		$flow_id  = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$exists   = ! empty( $flow['exists'] );
		$event_id = (string) $context->get( 'event_id', '' );
		$now      = current_time( 'mysql', true );

		$plan                         = MP_SW_D8_Writer::empty_plan();
		$plan['flow']['op']           = $exists ? MP_SW_D8_Writer::OP_UPDATE : MP_SW_D8_Writer::OP_INSERT;
		$plan['flow']['flow_id']      = $flow_id;
		$plan['flow']['lock_version'] = isset( $flow['lock_version'] ) ? (int) $flow['lock_version'] : 0;

		$data = array(
			'updated_at'   => $now,

			/*
			 * Token blokady rośnie BEZWARUNKOWO przy każdym zapisie. Dwa zapisy w
			 * tej samej sekundzie muszą się różnić, a `updated_at` ma rozdzielczość
			 * jednej sekundy — na nim blokada by się rozjechała.
			 */
			'lock_version' => $plan['flow']['lock_version'] + 1,
		);

		if ( ! $exists ) {
			$data['lead_id']    = $lead_id;
			$data['created_at'] = $now;
			$data['status']     = MP_Sales_Workflow_DB::STATUS_NEW;
		}

		if ( ! empty( $transition['changes_status'] ) ) {
			$data['status'] = (string) $transition['to'];
		}

		$owner = isset( $row['assigned_user_id'] ) ? (int) $row['assigned_user_id'] : 0;

		if ( ! empty( $assignment['user_id'] ) && (int) $assignment['user_id'] !== $owner ) {
			$data['assigned_user_id'] = (int) $assignment['user_id'];
			$data['assigned_at']      = $now;
			$data['assign_reason']    = (string) $context->get( 'assign_reason', '' );
			$data['assign_fallback']  = empty( $assignment['fallback'] ) ? 0 : 1;
		}

		$sla = (string) $context->get( 'sla_due_at', '' );

		if ( '' !== $sla ) {
			$data['sla_due_at'] = $sla;
		}

		if ( isset( $entity['offer_id'] ) && (int) $entity['offer_id'] > 0 ) {
			$data['offer_id'] = (int) $entity['offer_id'];
		}

		/*
		 * Numer oferty przepisujemy ze SNAPSHOTU (Dział 2 czyta go po `offer_id`
		 * z tabeli wtyczki 2), bo powiadomienia biorą go z wiersza procesu.
		 * Dopóki go tu nie było, klient dostawał temat „Oferta" i treść
		 * „przesyłamy ofertę nr ." — znacznik BYŁ podmieniony, tyle że na pusty
		 * ciąg, więc krytyk 7.2 (szuka znaczników NIEpodmienionych) tego nie widział.
		 *
		 * Nadpisujemy wyłącznie wartością niepustą: cron i zdarzenia bez oferty
		 * numeru nie niosą, a wyczyszczenie zepsułoby przypomnienia follow-up.
		 */
		$offer        = isset( $snapshot['offer'] ) ? (array) $snapshot['offer'] : array();
		$offer_number = isset( $offer['offer_number'] ) ? trim( (string) $offer['offer_number'] ) : '';

		if ( '' !== $offer_number ) {
			$data['offer_number'] = $offer_number;
		}

		$lang    = isset( $event['lang'] ) ? (string) $event['lang'] : '';
		$country = (string) $context->get( 'country', '' );

		if ( ! $exists && '' !== $lang ) {
			$data['lang'] = $lang;
		}

		if ( ! $exists && '' !== $country ) {
			$data['country'] = strtoupper( $country );
		}

		/*
		 * Dane klienta uzupełniamy tylko wtedy, gdy proces ich jeszcze nie ma:
		 * koperta bywa uboższa niż wiersz — cron danych klienta nie niesie i
		 * nadpisanie wyczyściłoby adres, na który idą powiadomienia.
		 */
		if ( '' === (string) self::pick( $row, 'client_name' ) && ! empty( $client['name'] ) ) {
			$data['client_name'] = (string) $client['name'];
		}

		if ( '' === (string) self::pick( $row, 'client_email' ) && ! empty( $client['email'] ) ) {
			$data['client_email'] = (string) $client['email'];
		}

		/*
		 * Segment tą samą drogą i pod tym samym warunkiem co nazwa i adres.
		 *
		 * Do 1.3.10 tej linii nie było: kolumna `segment` istniała w schemacie,
		 * Dział 2 czytał ją z tabeli leadów razem z krajem, mapa długości wyżej
		 * przewidywała dla niej 64 znaki, a Dział 7 wstawiał ją do szablonu
		 * powiadomienia — tylko nikt jej nigdy nie zapisał. Handlowiec dostawał
		 * maila z wierszem „Segment: " i niczym po dwukropku, przy KAŻDYM procesie.
		 *
		 * Krytyk 7.2 tego nie łapie, bo szuka znaczników NIEPODMIENIONYCH, a ten
		 * był podmieniony — na pusty ciąg. Ta sama pułapka co przy `offer_number`
		 * opisana w komentarzu wyżej; tamta naprawa objęła numer oferty i nie
		 * objęła pola obok.
		 */
		if ( '' === (string) self::pick( $row, 'segment' ) && ! empty( $client['segment'] ) ) {
			$data['segment'] = (string) $client['segment'];
		}

		$plan['flow']['data'] = $data;

		/*
		 * WARTOWNIK SPRAWDZANY PONOWNIE W TRANSAKCJI (TOCTOU).
		 *
		 * Dział 6 sprawdził wartownika na snapshocie — czyli na stanie sprzed
		 * odczytu. Między odczytem a zapisem handlowiec mógł zamknąć sprzedaż
		 * jako wygraną, a przypomnienie „minęły 3 dni od wysłania oferty"
		 * poszłoby mimo to. Token blokady sam by to złapał, bo rośnie przy każdym
		 * zapisie, ale warunek na statusie mówi wprost, CZEGO pilnujemy, i broni
		 * także wtedy, gdyby ktoś kiedyś zapisał status z pominięciem tokenu.
		 */
		$plan['flow']['guard'] = '';

		if ( MP_SW_Pipeline_Factory::EVENT_TASK_DUE === (string) $context->get( 'type', '' ) && ! empty( $tasks['fire'] ) ) {
			$first                 = (array) $tasks['fire'][0];
			$plan['flow']['guard'] = isset( $first['guard_status'] ) ? (string) $first['guard_status'] : '';
		}

		foreach ( (array) $tasks['create'] as $task ) {
			$plan['tasks_create'][] = $task;
		}

		foreach ( (array) $tasks['close'] as $task ) {
			$plan['tasks_close'][] = array(
				'task_id' => (int) $task['task_id'],
				'type'    => (string) $task['type'],
				'status'  => MP_SW_D6_Scheduler::STATUS_CANCELLED,
			);
		}

		/*
		 * Czy przypomnienie faktycznie poszło? Zadanie follow-up wolno domknąć
		 * jako WYKONANE tylko wtedy, gdy powiadomienie trafiło do kolejki.
		 * Gdy jedyny odbiorca miał niedostarczalny adres, Agent 7.2 pomija
		 * wiadomość (żeby nie wywracać całego zdarzenia) — i wtedy „wykonane"
		 * byłoby nieprawdą wobec handlowca, który nic nie dostał.
		 */
		$kolejka = (array) $context->get( 'notifications', array() );
		$poszlo  = false;
		$odpadlo = false;

		foreach ( $kolejka as $wiadomosc ) {
			if ( MP_SW_Templates::TPL_FOLLOWUP_DUE === (string) $wiadomosc['template'] ) {
				$poszlo = true;
				break;
			}
		}

		foreach ( (array) $context->get( 'skipped_notifications', array() ) as $pominieta ) {
			if ( MP_SW_Templates::TPL_FOLLOWUP_DUE === (string) $pominieta['template'] ) {
				$odpadlo = true;
				break;
			}
		}

		/*
		 * Pytamy o DOWÓD WYSŁANIA, nie o dowód porażki.
		 *
		 * Wcześniej warunek brzmiał `( ! $poszlo && $odpadlo )` — czyli „wykonane"
		 * dostawał także trzeci przypadek: powiadomienia nie ma ani w kolejce, ani
		 * na liście pominiętych. Taka luka nie jest hipotetyczna z punktu widzenia
		 * pulpitu: handlowiec nic nie dostał, a zadanie znikało jako zrobione i
		 * nikt do niego nie wracał. Brak potwierdzenia to nie jest potwierdzenie.
		 */
		$status_po_terminie = $poszlo
			? MP_SW_D6_Scheduler::STATUS_DONE
			: MP_SW_D6_Scheduler::STATUS_UNDELIVERED;

		foreach ( (array) $tasks['fire'] as $task ) {
			$plan['tasks_close'][] = array(
				'task_id' => (int) $task['task_id'],
				'type'    => (string) $task['type'],
				'status'  => $status_po_terminie,
			);
		}

		foreach ( (array) $tasks['skip'] as $task ) {
			/*
			 * Zadanie zatrzymane przez wartownika trzeba domknąć, inaczej cron
			 * wracałby po nie w kółko. Pominięcie z powodu „już nieotwarte" nie ma
			 * czego domykać.
			 */
			if ( MP_SW_D6_Scheduler::SKIP_GUARD === (string) $task['reason'] ) {
				$plan['tasks_close'][] = array(
					'task_id' => (int) $task['task_id'],
					'type'    => (string) $task['type'],
					'status'  => MP_SW_D6_Scheduler::STATUS_CANCELLED,
				);
			}
		}

		$plan['notifications'] = (array) $context->get( 'notifications', array() );

		$plan['event'] = array(
			'event_id'   => $event_id,
			'type'       => isset( $event['type'] ) ? (string) $event['type'] : '',
			'lead_id'    => $lead_id,
			'offer_id'   => isset( $entity['offer_id'] ) ? (int) $entity['offer_id'] : null,
			'actor_id'   => isset( $event['actor']['user_id'] ) ? (int) $event['actor']['user_id'] : 0,
			'status'     => MP_Sales_Workflow_DB::EVENT_STATUS_DONE,
			'trace_id'   => (string) $context->get( 'trace_id', $event_id ),
			'created_at' => $now,
			'updated_at' => $now,
		);

		return MP_SW_Result::ok( array( 'write_plan' => $plan ) );
	}

	/**
	 * Wartość z wiersza procesu.
	 *
	 * @param array  $row Wiersz procesu.
	 * @param string $key Kolumna.
	 * @return string
	 */
	private static function pick( array $row, $key ) {
		return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
	}
}

/**
 * K8.1 „zgodnosc-z-DDL" — statusy ze slownika, dlugosci w limitach.
 */
class MP_SW_D8_Critic_DDL extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();
		$plan = isset( $data['write_plan'] ) ? (array) $data['write_plan'] : array();

		$flow_data = isset( $plan['flow']['data'] ) ? (array) $plan['flow']['data'] : array();

		if ( isset( $flow_data['status'] ) && ! in_array( $flow_data['status'], MP_Sales_Workflow_DB::statuses(), true ) ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: status spoza słownika. */
					__( 'Status %s spoza słownika BD-1.', 'mp-sales-workflow' ),
					$flow_data['status']
				),
				array( 'errors' => array( 'flow.status' ) ),
				'status_not_in_dictionary'
			);
		}

		$too_long = self::over_limit( $flow_data );

		foreach ( (array) $plan['tasks_create'] as $task ) {
			$too_long = array_merge( $too_long, self::over_limit( $task ) );

			if ( ! MP_SW_D8_Writer::is_datetime( $task['due_at'] ) ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: wartość terminu. */
						__( 'Termin zadania nie jest datą MySQL: %s', 'mp-sales-workflow' ),
						$task['due_at']
					),
					array( 'errors' => array( 'tasks.due_at' ) ),
					'invalid_datetime'
				);
			}
		}

		foreach ( (array) $plan['notifications'] as $notification ) {
			$too_long = array_merge( $too_long, self::over_limit( $notification ) );
		}

		if ( ! empty( $too_long ) ) {
			// MySQL bez trybu ścisłego obciąłby wartość po cichu — wolimy odmowę
			// niż zapisany kawałek adresu albo numeru oferty.
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %s: lista kolumn przekraczających limit. */
					__( 'Wartości dłuższe niż pozwala schemat: %s', 'mp-sales-workflow' ),
					implode( ', ', array_unique( $too_long ) )
				),
				array( 'errors' => array_values( array_unique( $too_long ) ) ),
				'value_too_long'
			);
		}

		foreach ( array( 'updated_at', 'created_at', 'assigned_at', 'sla_due_at' ) as $column ) {
			if ( isset( $flow_data[ $column ] ) && ! MP_SW_D8_Writer::is_datetime( $flow_data[ $column ] ) ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: 1: kolumna, 2: wartość. */
						__( 'Kolumna %1$s nie jest datą MySQL: %2$s', 'mp-sales-workflow' ),
						$column,
						$flow_data[ $column ]
					),
					array( 'errors' => array( 'flow.' . $column ) ),
					'invalid_datetime'
				);
			}
		}

		if ( '' === (string) $plan['event']['event_id'] ) {
			return MP_SW_Result::fail(
				__( 'Zapis bez identyfikatora zdarzenia — idempotencja nie miałaby na czym stanąć.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'event.event_id' ) ),
				'missing_event_id'
			);
		}

		/*
		 * Każda operacja musi nieść dane, z których da się zbudować wpis dziennika
		 * (para 8.3). Samego dziennika krytyk jeszcze nie widzi — powstaje dopiero
		 * w trzeciej parze tego działu i sprawdza go K8.3.
		 */
		foreach ( (array) $plan['tasks_close'] as $task ) {
			if ( (int) $task['task_id'] <= 0 || '' === (string) $task['status'] ) {
				return MP_SW_Result::fail(
					__( 'Operacja na zadaniu bez danych do dziennika.', 'mp-sales-workflow' ),
					array( 'errors' => array( 'tasks_close' ) ),
					'operation_not_journalable'
				);
			}
		}

		return MP_SW_Result::ok( $data );
	}

	/**
	 * Kolumny przekraczające limit długości.
	 *
	 * @param array $values Wartości do sprawdzenia.
	 * @return string[]
	 */
	private static function over_limit( array $values ) {
		$over = array();

		foreach ( MP_SW_D8_Writer::limits() as $column => $max ) {
			if ( isset( $values[ $column ] ) && is_string( $values[ $column ] ) && strlen( $values[ $column ] ) > $max ) {
				$over[] = $column;
			}
		}

		return $over;
	}
}

/**
 * A8.2 „transakcja" — wykonanie planu wewnatrz jednej transakcji.
 */
class MP_SW_D8_Agent_Transaction extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		global $wpdb;

		$plan = (array) $context->get( 'write_plan', MP_SW_D8_Writer::empty_plan() );

		/*
		 * Licznik rośnie DOKŁADNIE raz. Liczymy transakcję, nie instrukcje: pod
		 * spodem pada kilka INSERT-ów i UPDATE-ów, ale albo utrwalą się wszystkie,
		 * albo żaden. To jest ten „1 zapis" z bramki QA8.
		 */
		$context->count_db_write();

		$flow_id = (int) $plan['flow']['flow_id'];
		$written = array(
			'flow'          => 0,
			'tasks_create'  => 0,
			'tasks_close'   => 0,
			'notifications' => 0,
		);

		if ( MP_SW_D8_Writer::OP_INSERT === $plan['flow']['op'] ) {
			$ok = $wpdb->insert( MP_Sales_Workflow_DB::flow_table(), $plan['flow']['data'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( false === $ok ) {
				return self::db_failure( 'flow.insert', $wpdb->last_error );
			}

			$flow_id         = (int) $wpdb->insert_id;
			$written['flow'] = 1;
		} else {
			/*
			 * Blokada optymistyczna: aktualizacja trafia w wiersz TYLKO wtedy, gdy
			 * token jest wciąż ten, który przeczytał Dział 2. Zero zmienionych
			 * wierszy to jedyny sygnał, że ktoś zapisał w międzyczasie — i dlatego
			 * token rośnie przy każdym zapisie, także gdy reszta kolumn zostaje
			 * bez zmian.
			 */
			$where = array(
				'id'           => $flow_id,
				'lock_version' => (int) $plan['flow']['lock_version'],
			);

			// Wartownik follow-upu w warunku zapisu — patrz komentarz przy budowie
			// planu. Status musi być wciąż ten, dla którego zaplanowano zadanie.
			if ( ! empty( $plan['flow']['guard'] ) ) {
				$where['status'] = (string) $plan['flow']['guard'];
			}

			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				MP_Sales_Workflow_DB::flow_table(),
				$plan['flow']['data'],
				$where
			);

			if ( false === $result ) {
				return self::db_failure( 'flow.update', $wpdb->last_error );
			}

			if ( 1 !== (int) $result ) {
				return MP_SW_Result::fail(
					__( 'Proces zmieniony przez inne żądanie — powtórz od odczytu.', 'mp-sales-workflow' ),
					array(
						'errors'      => array( 'lock_version' ),
						'retry_from'  => 2,
						'http_status' => 409,
					),
					'version_conflict'
				);
			}

			$written['flow'] = 1;
		}

		foreach ( (array) $plan['tasks_create'] as $task ) {
			$row = $task;

			// Identyfikator procesu jest znany dopiero teraz — Dział 6 planował
			// zadania, nie mogąc go znać.
			$row['flow_id']  = $flow_id;
			$row['open_key'] = MP_SW_D6_Scheduler::open_key( $flow_id, (string) $task['type'] );

			$ok = $wpdb->insert( MP_Sales_Workflow_DB::tasks_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( false === $ok ) {
				return self::db_failure( 'tasks.insert', $wpdb->last_error );
			}

			++$written['tasks_create'];
		}

		$now = current_time( 'mysql', true );

		foreach ( (array) $plan['tasks_close'] as $task ) {
			$ok = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				MP_Sales_Workflow_DB::tasks_table(),
				array(
					'status'     => (string) $task['status'],
					// Zwolnienie klucza otwartego zadania: dopiero teraz ten sam typ
					// może zostać zaplanowany ponownie.
					'open_key'   => null,
					'updated_at' => $now,
				),
				array( 'id' => (int) $task['task_id'] )
			);

			if ( false === $ok ) {
				return self::db_failure( 'tasks.update', $wpdb->last_error );
			}

			++$written['tasks_close'];
		}

		foreach ( (array) $plan['notifications'] as $notification ) {
			$row            = $notification;
			$row['flow_id'] = $flow_id;

			$ok = $wpdb->insert( MP_Sales_Workflow_DB::notifications_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( false === $ok ) {
				return self::db_failure( 'notifications.insert', $wpdb->last_error );
			}

			++$written['notifications'];
		}

		/*
		 * Rejestr zdarzeń jest OSTATNI i leży w tej samej transakcji. To on odbija
		 * powtórkę: UNIQUE na `event_id` wstrzymuje równoległe żądanie do COMMIT-u
		 * pierwszego, a potem odrzuca je błędem klucza. Sprawdzenie SELECT-em przed
		 * zapisem przepuściłoby oba wywołania naraz.
		 */
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( MP_Sales_Workflow_DB::events_table(), $plan['event'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( false === $ok ) {
			return MP_SW_Result::fail(
				__( 'Zdarzenie o tym identyfikatorze zostało już obsłużone.', 'mp-sales-workflow' ),
				array(
					'errors'      => array( 'event_id' ),
					'db_error'    => $error,
					'http_status' => 409,
				),
				'duplicate_event'
			);
		}

		return MP_SW_Result::ok(
			array(
				'flow_id'   => $flow_id,
				'written'   => $written,
				'db_writes' => $context->get_db_writes(),
			)
		);
	}

	/**
	 * Wynik nieudanej operacji bazodanowej.
	 *
	 * @param string $where Miejsce błędu.
	 * @param string $error Komunikat bazy.
	 * @return MP_SW_Result
	 */
	private static function db_failure( $where, $error ) {
		return MP_SW_Result::fail(
			sprintf(
				/* translators: 1: operacja, 2: komunikat bazy. */
				__( 'Zapis nie powiódł się (%1$s): %2$s', 'mp-sales-workflow' ),
				$where,
				$error
			),
			array(
				'errors'      => array( $where ),
				'http_status' => 500,
			),
			'write_failed'
		);
	}
}

/**
 * K8.2 „jeden-zapis" — db_writes = 1, wiersze zgodne z planem.
 */
class MP_SW_D8_Critic_Single_Write extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();
		$plan = (array) $context->get( 'write_plan', MP_SW_D8_Writer::empty_plan() );

		if ( 1 !== (int) $context->get_db_writes() ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %d: liczba transakcji zapisu. */
					__( 'Zapisów w żądaniu: %d — dopuszczalny jest dokładnie jeden.', 'mp-sales-workflow' ),
					$context->get_db_writes()
				),
				array( 'errors' => array( 'db_writes' ) ),
				'multiple_writes'
			);
		}

		if ( (int) $data['flow_id'] <= 0 ) {
			return MP_SW_Result::fail(
				__( 'Zapis bez identyfikatora procesu — zadania i powiadomienia wisiałyby w próżni.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'flow_id' ) ),
				'missing_flow_id'
			);
		}

		$expected = array(
			'tasks_create'  => count( (array) $plan['tasks_create'] ),
			'tasks_close'   => count( (array) $plan['tasks_close'] ),
			'notifications' => count( (array) $plan['notifications'] ),
		);

		foreach ( $expected as $key => $count ) {
			if ( (int) $data['written'][ $key ] !== $count ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: 1: rodzaj operacji, 2: liczba z planu, 3: liczba zapisana. */
						__( 'Wykonanie rozjechało się z planem (%1$s: plan %2$d, zapis %3$d).', 'mp-sales-workflow' ),
						$key,
						$count,
						$data['written'][ $key ]
					),
					array( 'errors' => array( 'written.' . $key ) ),
					'plan_mismatch'
				);
			}
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A8.3 „dziennik" — wpisy old_value → new_value w tej samej transakcji.
 */
class MP_SW_D8_Agent_Journal extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		global $wpdb;

		$plan       = (array) $context->get( 'write_plan', MP_SW_D8_Writer::empty_plan() );
		$snapshot   = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$row        = isset( $snapshot['flow']['row'] ) ? (array) $snapshot['flow']['row'] : array();
		$transition = (array) $context->get( 'transition', array() );
		$event      = (array) $context->get( 'event', array() );

		$flow_id  = (int) $context->get( 'flow_id', 0 );
		$event_id = (string) $context->get( 'event_id', '' );
		$actor_id = isset( $event['actor']['user_id'] ) ? (int) $event['actor']['user_id'] : 0;
		$lead_id  = isset( $event['entity']['lead_id'] ) ? (int) $event['entity']['lead_id'] : 0;
		$now      = current_time( 'mysql', true );

		$entries = array();

		if ( ! empty( $transition['changes_status'] ) ) {
			$entries[] = array(
				'action'    => MP_SW_D8_Writer::LOG_STATUS,
				'old_value' => (string) $transition['from'],
				'new_value' => (string) $transition['to'],
			);
		}

		$flow_data = isset( $plan['flow']['data'] ) ? (array) $plan['flow']['data'] : array();

		if ( isset( $flow_data['assigned_user_id'] ) ) {
			$entries[] = array(
				'action'    => MP_SW_D8_Writer::LOG_ASSIGNED,
				'old_value' => isset( $row['assigned_user_id'] ) ? (string) $row['assigned_user_id'] : '',
				'new_value' => (string) $flow_data['assigned_user_id'],
			);
		}

		/*
		 * Proces zakładany bez właściciela ma zostawić ślad OD RAZU — inaczej
		 * powstaje wiersz, o którym dziennik nic nie wie: status się nie zmienia
		 * (zostaje „nowy"), przypisania nie ma, więc żadna z gałęzi wyżej nic nie
		 * dopisuje. Tylko przy zakładaniu procesu, żeby kolejne zdarzenia
		 * nieprzypisanego procesu nie powtarzały tego samego zdania w kółko.
		 */
		$assignment = (array) $context->get( 'assignment', array() );

		if ( empty( $row ) && empty( $assignment['user_id'] ) && ! empty( $assignment['unassigned_reason'] ) ) {
			$entries[] = array(
				'action'    => MP_SW_D8_Writer::LOG_UNASSIGNED,
				'old_value' => '',
				'new_value' => (string) $assignment['unassigned_reason'],
			);
		}

		foreach ( (array) $plan['tasks_create'] as $task ) {
			$entries[] = array(
				'action'    => MP_SW_D8_Writer::LOG_TASK_PLANNED,
				'old_value' => '',
				'new_value' => $task['type'] . ' @ ' . $task['due_at'] . ' (' . $task['guard_status'] . ')',
			);
		}

		foreach ( (array) $plan['tasks_close'] as $task ) {
			$entries[] = array(
				'action'    => MP_SW_D6_Scheduler::STATUS_DONE === $task['status']
					? MP_SW_D8_Writer::LOG_TASK_DONE
					: MP_SW_D8_Writer::LOG_TASK_CLOSED,
				'old_value' => MP_SW_D6_Scheduler::STATUS_PENDING,
				'new_value' => $task['type'] . ' #' . $task['task_id'] . ' → ' . $task['status'],
			);
		}

		foreach ( (array) $plan['notifications'] as $notification ) {
			/*
			 * BEZ ADRESU (BLOK H). Dziennik z kryterium 5.5 nie podlega czyszczeniu
			 * — ma odtwarzać historię także po latach. Adres wpisany tutaj
			 * przeżyłby więc żądanie usunięcia danych: skasowalibyśmy go z kolejki
			 * i z procesu, a on zostałby w dzienniku, którego z założenia nie
			 * ruszamy. Zapisujemy KTO CO dostał w sensie roli i szablonu; komu
			 * dokładnie — mówi kolejka, powiązana tym samym `event_id`, i to ona
			 * podlega anonimizacji.
			 */

			/*
			 * Odbiorcę opisujemy ROLĄ, nie adresem. Rola wynika z szablonu: tylko
			 * „oferta wysłana" idzie do klienta, reszta do handlowca. Wiersz kolejki
			 * nie niesie tej informacji, bo tabela powiadomień nie ma takiej
			 * kolumny — a dokładanie jej wyłącznie na potrzeby dziennika byłoby
			 * duplikowaniem tego, co i tak wynika z szablonu.
			 */
			$audience = MP_SW_Templates::TPL_OFFER_SENT === (string) $notification['template']
				? MP_SW_D7_Notifier::AUDIENCE_CLIENT
				: MP_SW_D7_Notifier::AUDIENCE_SALESMAN;

			$entries[] = array(
				'action'     => MP_SW_D8_Writer::LOG_NOTIFICATION,
				'entity_ref' => 'notification:' . $audience,
				'old_value'  => '',
				'new_value'  => $notification['template'] . ' v' . $notification['template_version']
					. ' [' . $notification['lang'] . '] → ' . $audience,
			);
		}

		/*
		 * Powiadomienia wewnętrzne pominięte z powodu niedostarczalnego adresu.
		 * Idą do dziennika ZAMIAST wywracać zdarzenie — administrator ma z czego
		 * odczytać, że handlowiec nie dostał wiadomości i dlaczego. Bez adresu,
		 * tak samo jak wpisy powyżej.
		 */
		foreach ( (array) $context->get( 'skipped_notifications', array() ) as $pominiete ) {
			$entries[] = array(
				'action'     => MP_SW_D8_Writer::LOG_NOTIFICATION_SKIPPED,
				'entity_ref' => 'notification:' . (string) $pominiete['audience'],
				'old_value'  => '',
				'new_value'  => (string) $pominiete['template'] . ' → ' . (string) $pominiete['audience']
					. ' #' . (int) $pominiete['user_id'] . ' (' . (string) $pominiete['cause'] . ')',
			);
		}

		$written = 0;

		foreach ( $entries as $entry ) {
			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				MP_Sales_Workflow_DB::activity_table(),
				array(
					'event_id'   => $event_id,
					'flow_id'    => $flow_id,
					'entity_ref' => isset( $entry['entity_ref'] ) ? (string) $entry['entity_ref'] : 'lead:' . $lead_id,
					'action'     => $entry['action'],
					'old_value'  => $entry['old_value'],
					'new_value'  => $entry['new_value'],
					'actor_type' => $actor_id > 0 ? 'user' : 'system',
					'actor_id'   => $actor_id > 0 ? $actor_id : null,
					'created_at' => $now,
				)
			);

			if ( false === $ok ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: komunikat bazy. */
						__( 'Nie udało się zapisać dziennika: %s', 'mp-sales-workflow' ),
						$wpdb->last_error
					),
					array( 'errors' => array( 'activity' ) ),
					'journal_failed'
				);
			}

			++$written;
		}

		return MP_SW_Result::ok(
			array(
				'journal'         => $entries,
				'journal_written' => $written,
			)
		);
	}
}

/**
 * K8.3 „odtwarzalnosc" — historie odtwarza sam dziennik (kryterium 5.5).
 */
class MP_SW_D8_Critic_Replayable extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data       = $agent_result->get_data();
		$entries    = isset( $data['journal'] ) ? (array) $data['journal'] : array();
		$plan       = (array) $context->get( 'write_plan', MP_SW_D8_Writer::empty_plan() );
		$transition = (array) $context->get( 'transition', array() );

		if ( count( $entries ) !== (int) $data['journal_written'] ) {
			return MP_SW_Result::fail(
				__( 'Część wpisów dziennika nie została zapisana.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'journal' ) ),
				'journal_incomplete'
			);
		}

		$actions = array();

		foreach ( $entries as $entry ) {
			$actions[] = (string) $entry['action'];
		}

		/*
		 * Nie może istnieć zmiana statusu bez wpisu — to jest kryterium 5.5.
		 * Historia procesu ma dać się odtworzyć z samego dziennika, bez zaglądania
		 * w kod i bez zgadywania, co się wydarzyło.
		 */
		if ( ! empty( $transition['changes_status'] ) && ! in_array( MP_SW_D8_Writer::LOG_STATUS, $actions, true ) ) {
			return MP_SW_Result::fail(
				__( 'Zmiana statusu bez wpisu w dzienniku.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'journal.status' ) ),
				'status_without_journal'
			);
		}

		foreach ( $entries as $entry ) {
			if ( MP_SW_D8_Writer::LOG_STATUS === $entry['action']
				&& ( '' === (string) $entry['old_value'] || '' === (string) $entry['new_value'] ) ) {
				return MP_SW_Result::fail(
					__( 'Wpis o zmianie statusu bez wartości przed i po.', 'mp-sales-workflow' ),
					array( 'errors' => array( 'journal.values' ) ),
					'journal_without_values'
				);
			}
		}

		$expected = count( (array) $plan['notifications'] );
		$logged   = 0;

		foreach ( $actions as $action ) {
			if ( MP_SW_D8_Writer::LOG_NOTIFICATION === $action ) {
				++$logged;
			}
		}

		if ( $logged !== $expected ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: liczba powiadomień, 2: liczba wpisów dziennika. */
					__( 'Powiadomień %1$d, wpisów w dzienniku %2$d — historia wysyłek byłaby niepełna.', 'mp-sales-workflow' ),
					$expected,
					$logged
				),
				array( 'errors' => array( 'journal.notifications' ) ),
				'journal_notifications_mismatch'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * QA8 — bramka jakosci: atomowosc, wiersze = plan.
 */
class MP_SW_D8_QA_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$plan    = (array) $context->get( 'write_plan', MP_SW_D8_Writer::empty_plan() );
		$written = (array) $context->get( 'written', array() );

		$rows = 0;

		foreach ( array( 'tasks_create', 'tasks_close', 'notifications' ) as $key ) {
			$rows += isset( $written[ $key ] ) ? (int) $written[ $key ] : 0;
		}

		return MP_SW_Result::ok(
			array(
				'qa_db_writes'     => $context->get_db_writes(),
				'qa_flow_id'       => (int) $context->get( 'flow_id', 0 ),
				'qa_plan_rows'     => count( (array) $plan['tasks_create'] ) + count( (array) $plan['tasks_close'] ) + count( (array) $plan['notifications'] ),
				'qa_written_rows'  => $rows,
				'qa_notifications' => count( (array) $plan['notifications'] ),
				'qa_journal'       => (int) $context->get( 'journal_written', 0 ),
			)
		);
	}
}

/**
 * QA8 krytyk — zadnych rekordow czesciowych i e-maili-widm.
 */
class MP_SW_D8_QA_Critic extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( 1 !== (int) $data['qa_db_writes'] || (int) $data['qa_flow_id'] <= 0 ) {
			return MP_SW_Result::fail(
				__( 'Zapis niedomknięty — brak procesu albo więcej niż jedna transakcja.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'db_writes', 'flow_id' ) ),
				'write_not_atomic'
			);
		}

		if ( (int) $data['qa_plan_rows'] !== (int) $data['qa_written_rows'] ) {
			/*
			 * Rekord częściowy: część planu utrwalona, część nie. W transakcji nie
			 * powinno się to zdarzyć, ale gdyby liczby się rozjechały, lepiej
			 * wycofać całość niż zostawić proces w stanie pośrednim.
			 */
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: liczba wierszy zaplanowanych, 2: liczba zapisanych. */
					__( 'Rekord częściowy: zaplanowano %1$d wierszy, zapisano %2$d.', 'mp-sales-workflow' ),
					$data['qa_plan_rows'],
					$data['qa_written_rows']
				),
				array( 'errors' => array( 'written' ) ),
				'partial_record'
			);
		}

		if ( 0 < (int) $data['qa_notifications'] && 0 === (int) $data['qa_journal'] ) {
			// E-mail-widmo: powiadomienie w kolejce, po którym nie ma śladu w
			// dzienniku — po wysyłce nikt nie odtworzyłby, skąd się wzięło.
			return MP_SW_Result::fail(
				__( 'Powiadomienia w kolejce bez śladu w dzienniku.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'journal' ) ),
				'ghost_email'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * ZAPIS — JEDNA TRANSAKCJA.
 */
class MP_SW_Department_08 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 8;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'zapis-jedna-transakcja';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_D8_Agent_Plan( '8.1', 'plan', 'Komplet operacji: wp_mp_flow (status + przypisanie), wp_mp_tasks, wp_mp_notifications (queued), wp_mp_activity' ),
				'critic' => new MP_SW_D8_Critic_DDL( 'K8.1', 'zgodność-z-DDL', 'Statusy ze słownika, długości w limitach, każda operacja ma wiersz dziennika' ),
			),
			array(
				'agent'  => new MP_SW_D8_Agent_Transaction( '8.2', 'transakcja', 'START TRANSACTION → operacje → COMMIT; konflikt wersji wiersza (lock_version) → FAIL_RETRY; inny błąd → ROLLBACK' ),
				'critic' => new MP_SW_D8_Critic_Single_Write( 'K8.2', 'jeden-zapis', 'db_writes = 1; nie istnieje status bez wpisu w dzienniku' ),
			),
			array(
				'agent'  => new MP_SW_D8_Agent_Journal( '8.3', 'dziennik', 'Wpisy z old_value → new_value: status.changed · lead.assigned · task.planned · notification.queued' ),
				'critic' => new MP_SW_D8_Critic_Replayable( 'K8.3', 'odtwarzalność', 'Historię statusów i wysyłek odtwarza sam dziennik — kryterium 5.5' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_D8_QA_Agent( 'QA8', 'bramka jakosci D8', 'atomowość: wiersze = plan' ),
			new MP_SW_D8_QA_Critic( 'QA8.K', 'krytyk bramki D8', 'żadnych rekordów częściowych i e-maili-widm' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'ZAPIS — JEDNA TRANSAKCJA',
			'pkt 2: dziennik aktywności · kryt. 5.5',
			$pairs,
			$gate
		);
	}
}
