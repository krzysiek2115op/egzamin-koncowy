<?php
/**
 * Dzial 5 pipeline LP.3 — MASZYNA STATUSÓW.
 *
 * Zakres wg diagramu: pkt 2: statusy procesu
 *
 * Operacje dzialu:
 *  - Słownik dozwolonych przejść (wersjonowany)
 *  - Skutki przejścia wyliczone jawnie
 *
 * Zrodlo (Golden Rule #2): docs/dzial-05/maszyna-statusow.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: maszyna stanow wg zlecenia · kryt. 5.5. Sam slownik
 * przejsc pochodzi ze zlecenia, ale dokumentacja dzialu opisuje NARZEDZIA,
 * ktorymi jest realizowany: sprawdzenie w trybie scislym (in_array ze strict —
 * `match` wymaga PHP 8, a wtyczka deklaruje 7.4), current_time() (jedno zrodlo
 * czasu w GMT dla SLA) i wpdb::update().
 *
 * Dzial NICZEGO nie zapisuje — wylicza tylko, czy przejscie jest legalne i
 * jakie ma skutki. Zapis nalezy do Dzialu 8.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slownik przejsc i skutkow.
 */
class MP_SW_D5_Machine {

	/** Wersja słownika przejść — trafia do dziennika razem z decyzją. */
	const MACHINE_VERSION = 'v1';

	/** Skutek: zaplanuj zadania follow-up d+3 i d+7. */
	const EFFECT_SCHEDULE_FOLLOWUPS = 'schedule_followups';

	/** Skutek: powiadom klienta. */
	const EFFECT_NOTIFY_CLIENT = 'notify_client';

	/** Skutek: powiadom handlowca. */
	const EFFECT_NOTIFY_SALESMAN = 'notify_salesman';

	/** Skutek: zamknij oczekujące zadania procesu. */
	const EFFECT_CLOSE_TASKS = 'close_tasks';

	/** Skutek: ustaw termin pierwszego kontaktu. */
	const EFFECT_SET_SLA = 'set_sla';

	/**
	 * Dozwolone przejścia: status źródłowy => lista docelowych.
	 *
	 * Statusy końcowe (`won`, `lost`) mają pustą listę — z nich nie wychodzi
	 * już żadne przejście.
	 *
	 * @return array<string,string[]>
	 */
	public static function transitions() {
		return array(
			/*
			 * `new -> lost` MUSI BYC. Kazdy inny status nieterminalny ma wyjscie
			 * do „przegrany", a `new` go nie mial — mimo ze `target_status()`
			 * ma JAWNA galaz zostawiajaca proces w tym statusie, gdy Dzial 4 nie
			 * wskazal wlasciciela (brak handlowcow albo zaden nie przyjmuje).
			 * Takiego procesu nie dalo sie zamknac inaczej niz przez `assigned`,
			 * czyli przez przypisanie go komus na niby tylko po to, zeby zaraz
			 * oznaczyc go jako przegrany.
			 *
			 * To zawęża tez sprawe otwarta OTW-2: przejscie przez `assigned`
			 * przestaje byc jedyna droga wyjscia ze statusu `new`.
			 */
			MP_Sales_Workflow_DB::STATUS_NEW         => array( MP_Sales_Workflow_DB::STATUS_ASSIGNED, MP_Sales_Workflow_DB::STATUS_LOST ),
			MP_Sales_Workflow_DB::STATUS_ASSIGNED    => array( MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT, MP_Sales_Workflow_DB::STATUS_LOST ),
			MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT => array( MP_Sales_Workflow_DB::STATUS_OFFER_SENT, MP_Sales_Workflow_DB::STATUS_LOST ),
			MP_Sales_Workflow_DB::STATUS_OFFER_SENT  => array( MP_Sales_Workflow_DB::STATUS_NEGOTIATION, MP_Sales_Workflow_DB::STATUS_WON, MP_Sales_Workflow_DB::STATUS_LOST ),
			MP_Sales_Workflow_DB::STATUS_NEGOTIATION => array( MP_Sales_Workflow_DB::STATUS_WON, MP_Sales_Workflow_DB::STATUS_LOST ),
			MP_Sales_Workflow_DB::STATUS_WON         => array(),
			MP_Sales_Workflow_DB::STATUS_LOST        => array(),
		);
	}

	/**
	 * Skutki wejścia w dany status — jedyne źródło prawdy dla Działów 6 i 7.
	 *
	 * @param string $to Status docelowy.
	 * @return string[]
	 */
	public static function effects_for( $to ) {
		$map = array(
			MP_Sales_Workflow_DB::STATUS_ASSIGNED    => array( self::EFFECT_SET_SLA, self::EFFECT_NOTIFY_SALESMAN ),
			MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT => array(),
			MP_Sales_Workflow_DB::STATUS_OFFER_SENT  => array( self::EFFECT_SCHEDULE_FOLLOWUPS, self::EFFECT_NOTIFY_CLIENT ),
			MP_Sales_Workflow_DB::STATUS_NEGOTIATION => array( self::EFFECT_CLOSE_TASKS, self::EFFECT_NOTIFY_SALESMAN ),
			MP_Sales_Workflow_DB::STATUS_WON         => array( self::EFFECT_CLOSE_TASKS, self::EFFECT_NOTIFY_SALESMAN ),
			MP_Sales_Workflow_DB::STATUS_LOST        => array( self::EFFECT_CLOSE_TASKS ),
		);

		return isset( $map[ $to ] ) ? $map[ $to ] : array();
	}

	/**
	 * Skutki należne WEJŚCIU w status — jedna reguła dla agenta 5.2 i krytyka K5.2.
	 *
	 * Wejście jest dwojakie: zmiana statusu albo ponowne wejście w ten sam status
	 * ze zdarzeniem, które niesie nowy fakt (`reentry_events()`). Warunek stał
	 * wcześniej przepisany w dwóch miejscach i pytał wyłącznie o zmianę statusu;
	 * trzymamy go tutaj, żeby nie dało się poprawić jednego miejsca i zapomnieć
	 * o drugim — wtedy krytyk zgłaszałby skutek nadmiarowy albo brakujący za
	 * każdym ponownym zatwierdzeniem oferty.
	 *
	 * @param bool   $changes_status Czy przejście zmienia status.
	 * @param bool   $repeat_entry   Czy to ponowne wejście niosące nowy fakt.
	 * @param string $to             Status, w który proces wchodzi.
	 * @return string[]
	 */
	public static function effects_for_entry( $changes_status, $repeat_entry, $to ) {
		return ( $changes_status || $repeat_entry ) ? self::effects_for( (string) $to ) : array();
	}

	/**
	 * Status docelowy wynikający z typu zdarzenia.
	 *
	 * @param string $type    Typ zdarzenia.
	 * @param array  $context_data Dane koperty (dla status.change).
	 * @return string Pusty ciąg, gdy zdarzenie nie zmienia statusu.
	 */
	public static function target_status( $type, array $context_data ) {
		if ( MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED === $type ) {
			$assignment = isset( $context_data['assignment'] ) ? (array) $context_data['assignment'] : array();

			/*
			 * „Przypisany" bez przypisanego to zdanie nieprawdziwe. Gdy Dział 4 nie
			 * wskazał właściciela — bo handlowców jeszcze nie ma albo żaden nie
			 * przyjmuje procesów — proces zostaje w statusie „nowy" i czeka na
			 * ręczne przydzielenie. Przy okazji nie odpalają się skutki przejścia
			 * do „przypisany": SLA liczone od przypisania i powiadomienie
			 * handlowca, którego nie ma komu wysłać.
			 */
			return empty( $assignment['user_id'] )
				? MP_Sales_Workflow_DB::STATUS_NEW
				: MP_Sales_Workflow_DB::STATUS_ASSIGNED;
		}

		if ( MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED === $type ) {
			return MP_Sales_Workflow_DB::STATUS_OFFER_SENT;
		}

		if ( MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE === $type ) {
			$to = isset( $context_data['to_status'] ) ? (string) $context_data['to_status'] : '';
			return trim( $to );
		}

		// `task.due` i `dashboard.view` statusu nie ruszają.
		return '';
	}

	/**
	 * Typy zdarzeń, którym wolno nie mieć statusu docelowego.
	 *
	 * Lista zamknięta i wyliczona wprost. Wcześniej „brak statusu docelowego"
	 * rozpoznawało się po pustym wyniku `target_status()`, więc `status.change`
	 * z pustym `to_status` wyglądał dokładnie tak samo jak podgląd pulpitu —
	 * i przechodził jako zdarzenie, które statusu nie rusza.
	 *
	 * @return string[]
	 */
	public static function statusless_events() {
		return array(
			MP_SW_Pipeline_Factory::EVENT_TASK_DUE,
			MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW,
		);
	}

	/**
	 * Typy zdarzeń, dla których PONOWNE wejście w ten sam status jest nowym
	 * faktem, a nie potwierdzeniem stanu.
	 *
	 * Rozróżnienie, którego brakowało i które kosztowało cichą utratę wysyłki.
	 * Gałąź „przejście w to samo miejsce" traktowała jednakowo dwie różne rzeczy:
	 *
	 *  - powtórkę TEGO SAMEGO zdarzenia — od tego jest `event_id` i token
	 *    blokady, a odpowiedzią ma być ciche potwierdzenie stanu bez skutków;
	 *  - NOWE zdarzenie prowadzące w ten sam status — czyli druga, poprawiona
	 *    oferta zatwierdzona dla procesu, który już jest w `offer_sent`.
	 *
	 * W drugim przypadku skutki muszą się wykonać: klient ma dostać powiadomienie
	 * o nowej ofercie. Bez tego P2 widziało HTTP 200 i uznawało, że ofertę
	 * wysłano, podczas gdy nie poszło nic. Duplikatów zadań to nie tworzy —
	 * broni przed nimi A6.2 kluczem `open_key` (jedno otwarte zadanie typu).
	 *
	 * `status.change` na ten sam status świadomie NIE jest tu wymieniony: to
	 * ręczne potwierdzenie stanu, który już obowiązuje, a nie nowy fakt.
	 *
	 * @return string[]
	 */
	public static function reentry_events() {
		return array( MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED );
	}

	/**
	 * Czy przejście jest dozwolone.
	 *
	 * Porównanie w trybie ścisłym: bez `$strict` PHP przed wersją 8.0 uznałby
	 * niektóre wartości za równe mimo różnych typów (patrz dokumentacja działu),
	 * a status ma być dopasowany co do znaku.
	 *
	 * @param string $from Status źródłowy.
	 * @param string $to   Status docelowy.
	 * @return bool
	 */
	public static function is_allowed( $from, $to ) {
		$transitions = self::transitions();

		if ( ! isset( $transitions[ $from ] ) ) {
			return false;
		}

		return in_array( $to, $transitions[ $from ], true );
	}
}

/**
 * A5.1 „przejście" — sprawdza przejście w słowniku.
 */
class MP_SW_D5_Agent_Transition extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$event = (array) $context->get( 'event', array() );
		$type  = isset( $event['type'] ) ? (string) $event['type'] : '';
		$flow  = (array) $context->get( 'flow', array() );

		$from = isset( $flow['row']['status'] ) && '' !== (string) $flow['row']['status']
			? (string) $flow['row']['status']
			: MP_Sales_Workflow_DB::STATUS_NEW;

		$to = MP_SW_D5_Machine::target_status( $type, $context->all() );

		/*
		 * Pusty status docelowy jest LEGALNY tylko dla zdarzeń, które statusu nie
		 * ruszają z definicji. Dla `status.change` oznacza koperta niekompletną:
		 * ktoś prosi o zmianę statusu, nie mówiąc na jaki.
		 *
		 * Wcześniej taka koperta szła gałęzią poniżej — z `allowed = true`.
		 * Wywołujący dostawał sukces, a proces mimo to był zapisywany: token
		 * blokady rósł i zdarzenie lądowało w rejestrze. Czyli nie tylko cisza
		 * zamiast błędu, ale i zużyty klucz idempotencji, przez który POPRAWIONA
		 * próba z tym samym `event_id` odbiłaby się jako powtórka.
		 */
		if ( '' === $to && ! in_array( $type, MP_SW_D5_Machine::statusless_events(), true ) ) {
			/*
			 * DWIE RÓŻNE PRZYCZYNY PUSTEGO STATUSU, DWA RÓŻNE ADRESATY.
			 *
			 * Jeden komunikat obsługiwał oba przypadki i w drugim z nich opisywał
			 * coś, co się nie stało. Dla `status.change` pusty `to_status` to
			 * naprawdę niekompletna koperta — wina po stronie żądania, HTTP 400,
			 * pole błędu wskazuje, co uzupełnić. Ale dla KAŻDEGO innego typu
			 * wywołujący o żadną zmianę statusu nie prosił: wina jest po naszej
			 * stronie, bo do fabryki doszedł typ, którego nikt nie dopisał ani do
			 * `target_status()`, ani do `statusless_events()`. Odsyłanie go wtedy
			 * do pola `to_status` kierowało diagnostykę na treść żądania zamiast
			 * na brakujący wpis w liście — dokładnie ten sam błąd, który naprawiono
			 * w K5.1, tylko w drugą stronę.
			 *
			 * Kod `unsupported_event_type` celowo NIE trafia do słownika
			 * `MP_SW_Errors::map()` i nie niesie `http_status`: to inwariant
			 * wewnętrzny i ma wychodzić jako MP3-E500, tak samo jak
			 * `transition_not_from_event`.
			 */
			if ( MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE !== $type ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: typ zdarzenia z koperty. */
						__( 'Typ zdarzenia „%s" nie ma reguły w maszynie statusów — nie wiadomo, czy ma zmieniać status, czy nie.', 'mp-sales-workflow' ),
						'' !== $type ? $type : '—'
					),
					array( 'errors' => array( 'event.type' ) ),
					'unsupported_event_type'
				);
			}

			return MP_SW_Result::fail(
				__( 'Zmiana statusu bez statusu docelowego.', 'mp-sales-workflow' ),
				array(
					'errors'      => array( 'to_status' ),
					'http_status' => 400,
				),
				'missing_target_status'
			);
		}

		/*
		 * Zdarzenie, które nie rusza statusu, jest legalne z definicji — nie ma
		 * przejścia do sprawdzenia.
		 *
		 * `known_status` i `repeat_entry` mimo to trafiają do wyniku: docblock
		 * gałęzi „w to samo miejsce" nazywa komplet pól inwariantem NIEZALEŻNYM
		 * od gałęzi, a ta jedna go łamała. Odbiorca musiałby wtedy rozróżniać,
		 * z której gałęzi przyszła tablica — czyli dokładnie to, przed czym
		 * inwariant chroni. Status źródłowy sprawdzamy w słowniku tak samo jak
		 * docelowy w gałęzi głównej.
		 */
		if ( '' === $to ) {
			return MP_SW_Result::ok(
				array(
					'transition' => array(
						'from'            => $from,
						'to'              => $from,
						'allowed'         => true,
						'changes_status'  => false,
						'known_status'    => in_array( $from, MP_Sales_Workflow_DB::statuses(), true ),
						'repeat_entry'    => false,
						'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
					),
				)
			);
		}

		$known   = in_array( $to, MP_Sales_Workflow_DB::statuses(), true );
		$allowed = $known && MP_SW_D5_Machine::is_allowed( $from, $to );

		/*
		 * Przejście „w to samo miejsce" (np. powtórzone zatwierdzenie oferty)
		 * nie jest błędem — status po prostu się nie zmienia. Rozróżnienie jest
		 * istotne: inaczej ponowione zdarzenie kończyłoby się odmową zamiast
		 * cichym potwierdzeniem stanu, który już obowiązuje.
		 *
		 * WARUNEK `$known` JEST TU KONIECZNY. Bez niego gałąź potwierdzała
		 * sukcesem status, którego w słowniku nie ma: wiersz zapisany przez
		 * starszą wersję maszyny (stała `MACHINE_VERSION` istnieje właśnie
		 * dlatego, że słownik jest wersjonowany) albo poprawiony ręcznie w bazie
		 * dostawał na `status.change` w ten sam status „stan potwierdzony"
		 * zamiast odmowy, którą to samo żądanie dostaje dla każdego innego
		 * wiersza. Zablokowany proces nigdy nie zgłaszał się jako zepsuty.
		 * `$known` był policzony linijkę wyżej i tylko w tej gałęzi ignorowany.
		 *
		 * `known_status` idzie do wyniku tak samo jak w gałęzi głównej — K5.1
		 * i dziennik mają wtedy komplet danych niezależnie od gałęzi.
		 */
		if ( ! $allowed && $known && $from === $to ) {
			return MP_SW_Result::ok(
				array(
					'transition' => array(
						'from'            => $from,
						'to'              => $to,
						'allowed'         => true,
						'changes_status'  => false,
						'known_status'    => $known,
						'repeat_entry'    => in_array( $type, MP_SW_D5_Machine::reentry_events(), true ),
						'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
					),
				)
			);
		}

		return MP_SW_Result::ok(
			array(
				'transition' => array(
					'from'            => $from,
					'to'              => $to,
					'allowed'         => $allowed,
					'changes_status'  => $allowed,
					'known_status'    => $known,
					// Zawsze `false`: ponowne wejscie to osobna galaz wyzej. Klucz
					// jest tu po to, zeby WSZYSTKIE galezie oddawaly ten sam komplet
					// pol — odbiorca nie ma rozpoznawac galezi po brakujacym kluczu.
					'repeat_entry'    => false,
					'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
				),
			)
		);
	}
}

/**
 * K5.1 „legalność-przejścia" — przejście spoza słownika kończy się odmową.
 */
class MP_SW_D5_Critic_Legality extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data       = $agent_result->get_data();
		$transition = isset( $data['transition'] ) ? (array) $data['transition'] : array();

		if ( empty( $transition['allowed'] ) ) {
			$from = isset( $transition['from'] ) ? $transition['from'] : '?';
			$to   = isset( $transition['to'] ) ? $transition['to'] : '?';

			/*
			 * DWA RÓŻNE BŁĘDY, DWA RÓŻNE ZDANIA.
			 *
			 * Agent 5.1 liczy `known_status` — czy status docelowy w ogóle
			 * istnieje w słowniku — a krytyk tej wartości nie czytał i każdą
			 * odmowę opisywał jako nielegalne PRZEJŚCIE. Literówka w nazwie
			 * statusu („wygrny" zamiast „wygrany") dostawała więc komunikat
			 * kierujący uwagę na regułę przejścia, podczas gdy naprawa jest
			 * gdzie indziej: w treści żądania. Rozróżnienie było policzone
			 * i wyrzucane.
			 *
			 * Kod odmowy zostaje jeden (`illegal_transition`) — wywołujący
			 * i tak ma zareagować tak samo, a zmiana kodu byłaby zmianą
			 * kontraktu tam, gdzie zepsuta jest wyłącznie treść komunikatu.
			 */
			$nieznany = array_key_exists( 'known_status', $transition ) && ! $transition['known_status'];

			$komunikat = $nieznany
				? sprintf(
					/* translators: %s: status docelowy z żądania. */
					__( 'Status „%s" nie istnieje w słowniku statusów — stan bez zmian.', 'mp-sales-workflow' ),
					$to
				)
				: sprintf(
					/* translators: 1: status źródłowy, 2: status docelowy. */
					__( 'Przejście %1$s → %2$s spoza słownika — stan bez zmian.', 'mp-sales-workflow' ),
					$from,
					$to
				);

			// Stan zostaje bez zmian — odmowa jest jedyną reakcją, żadnego
			// „przybliżonego" przejścia do najbliższego dozwolonego statusu.
			return MP_SW_Result::fail(
				$komunikat,
				array(
					'errors'      => array( 'transition' ),
					'from'        => $from,
					'to'          => $to,
					'http_status' => 409,
				),
				'illegal_transition'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A5.2 „skutki" — jawna lista następstw przejścia.
 */
class MP_SW_D5_Agent_Effects extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$transition = (array) $context->get( 'transition', array() );

		/*
		 * Skutki należą się także PONOWNEMU WEJŚCIU w ten sam status — patrz
		 * `MP_SW_D5_Machine::reentry_events()`. Warunek pytał wyłącznie o zmianę
		 * statusu, więc druga (poprawiona) oferta zatwierdzona dla procesu już
		 * w `offer_sent` kończyła się sukcesem z pustą listą skutków: klient bez
		 * powiadomienia, follow-upy nieplanowane, a P2 z odpowiedzią „przyjęte".
		 */
		$effects = MP_SW_D5_Machine::effects_for_entry(
			! empty( $transition['changes_status'] ),
			! empty( $transition['repeat_entry'] ),
			isset( $transition['to'] ) ? (string) $transition['to'] : ''
		);

		$sla_due_at = '';

		if ( in_array( MP_SW_D5_Machine::EFFECT_SET_SLA, $effects, true ) ) {
			/*
			 * Jedno źródło czasu w GMT — mieszanie stref dawało w poprzednich
			 * wtyczkach realne rozjazdy terminów.
			 *
			 * Strefa dopisana WPROST, bo `current_time( 'mysql', true )` oddaje
			 * GMT bez oznaczenia strefy, a `strtotime()` czyta taki łańcuch
			 * strefą domyślną PHP. Sam `gmdate()` tego nie ratuje: zamienia
			 * z powrotem na GMT moment, który został policzony gdzie indziej.
			 * Bez ' UTC' termin SLA przesuwał się o przesunięcie strefy, a przy
			 * zmianie czasu na letni — o dodatkową godzinę. WordPress ustawia
			 * UTC sam, więc na typowym serwerze wychodziło dobrze; jedna wtyczka
			 * wołająca `date_default_timezone_set()` wystarczy, żeby przestało.
			 * Harmonogram (`class-mp-sw-cron.php`) liczył to tak od początku.
			 */
			$sla_due_at = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) . ' UTC +1 day' ) );
		}

		return MP_SW_Result::ok(
			array(
				'effects'    => $effects,
				'sla_due_at' => $sla_due_at,
			)
		);
	}
}

/**
 * K5.2 „komplet-skutków" — każdy skutek ma pokrycie w regule przejścia.
 */
class MP_SW_D5_Critic_Effects extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data       = $agent_result->get_data();
		$effects    = isset( $data['effects'] ) ? (array) $data['effects'] : array();
		$transition = (array) $context->get( 'transition', array() );

		/*
		 * Status docelowy odczytany NIEZALEŻNIE od tablicy `transition`. Wcześniej
		 * `$expected` powstawało z dokładnie tego samego wyrażenia, co `$effects`
		 * u agenta 5.2 — `effects_for( $transition['to'] )` na tym samym kontekście.
		 * Porównanie zawsze wychodziło na zero, więc krytyk sprawdzał tylko WYNIK
		 * agenta (to zostaje, patrz niżej), a WEJŚCIA nie sprawdzał nikt: koperta
		 * z podmienionym `to` szła przez parę bez słowa, a Działy 6 i 7 wykonywały
		 * skutki NIE TEGO przejścia.
		 */
		$event    = (array) $context->get( 'event', array() );
		$type     = isset( $event['type'] ) ? (string) $event['type'] : '';
		$to_agent = isset( $transition['to'] ) ? (string) $transition['to'] : '';
		$to_event = MP_SW_D5_Machine::target_status( $type, $context->all() );

		/*
		 * Sprawdzenie idzie w OBIE STRONY. Wcześniej pytaliśmy o zgodność wyłącznie
		 * wtedy, gdy koperta sama przyznawała, że zmienia status — a przypadek
		 * odwrotny (`changes_status` fałszywe albo tablicy `transition` nie ma
		 * wcale) nie był badany przez nikogo: `$expected` stawało się pustą listą,
		 * agent 5.2 też oddawał pustą listę i para kończyła się sukcesem, choć
		 * zdarzenie żądało zmiany statusu. Wywołujący dostawał „przyjęte", a status
		 * zostawał na miejscu, bo Działy 6 i 7 nie miały czego zapisać.
		 *
		 * Brak zmiany statusu jest zgodny ze zdarzeniem w dwóch przypadkach i tylko
		 * w nich: zdarzenie statusu nie rusza (`task.due`, `dashboard.view` — wtedy
		 * `$to_event` jest puste) albo żądany status JUŻ obowiązuje (powtórzone
		 * zatwierdzenie oferty, lead bez handlowca zostający w „nowy").
		 */
		$zgodne_ze_zdarzeniem = empty( $transition['changes_status'] )
			? ( '' === $to_event || $to_event === $to_agent )
			: $to_event === $to_agent;

		/*
		 * Kod `transition_not_from_event` celowo NIE trafia do słownika
		 * `MP_SW_Errors::map()` i celowo nie niesie `http_status`: taka koperta nie
		 * powstaje z żadnego żądania użytkownika, tylko z błędu w kodzie albo
		 * podmiany danych między działami. To inwariant wewnętrzny i ma wychodzić
		 * jako MP3-E500, tak samo jak `multiple_writes`.
		 */
		if ( ! $zgodne_ze_zdarzeniem ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: status żądany przez zdarzenie, 2: status z tablicy przejścia. */
					__( 'Przejście prowadzi gdzie indziej niż żądanie zdarzenia — zdarzenie: %1$s, przejście: %2$s.', 'mp-sales-workflow' ),
					'' !== $to_event ? $to_event : '—',
					'' !== $to_agent ? $to_agent : '—'
				),
				array( 'errors' => array( 'transition.to' ) ),
				'transition_not_from_event'
			);
		}

		/*
		 * `repeat_entry` też jest odczytywany NIEZALEŻNIE od tablicy `transition`
		 * — z samego typu zdarzenia, tak jak `$to_event`. Gdyby krytyk wziął flagę
		 * z wyniku agenta, podmieniona koperta wymuszałaby skutki tam, gdzie się
		 * nie należą, a porównanie i tak wychodziłoby na zero.
		 */
		$powtorne_wejscie = empty( $transition['changes_status'] )
			&& in_array( $type, MP_SW_D5_Machine::reentry_events(), true );

		$expected = MP_SW_D5_Machine::effects_for_entry(
			! empty( $transition['changes_status'] ),
			$powtorne_wejscie,
			$to_event
		);

		/*
		 * Porównanie w obie strony. Skutek nadmiarowy to dokładnie to „przy
		 * okazji", którego zabrania kryterium — np. wysyłka do klienta doklejona
		 * do przejścia, które jej nie przewiduje. Skutek brakujący jest równie
		 * groźny: follow-up, który nigdy nie powstanie.
		 */
		$extra   = array_values( array_diff( $effects, $expected ) );
		$missing = array_values( array_diff( $expected, $effects ) );

		if ( ! empty( $extra ) || ! empty( $missing ) ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: skutki nadmiarowe, 2: skutki brakujące. */
					__( 'Skutki niezgodne z regułą przejścia — nadmiarowe: %1$s, brakujące: %2$s', 'mp-sales-workflow' ),
					$extra ? implode( ', ', $extra ) : '—',
					$missing ? implode( ', ', $missing ) : '—'
				),
				array( 'errors' => array_merge( $extra, $missing ) ),
				'effects_mismatch'
			);
		}

		if ( in_array( MP_SW_D5_Machine::EFFECT_SET_SLA, $effects, true ) && '' === (string) $data['sla_due_at'] ) {
			return MP_SW_Result::fail(
				__( 'Skutek set_sla bez wyliczonego terminu.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'sla_due_at' ) ),
				'sla_not_calculated'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * QA5 — bramka jakości: legalność przejścia i komplet skutków.
 */
class MP_SW_D5_QA_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$transition = (array) $context->get( 'transition', array() );

		return MP_SW_Result::ok(
			array(
				'qa_allowed' => ! empty( $transition['allowed'] ),
				'qa_version' => isset( $transition['machine_version'] ) ? (string) $transition['machine_version'] : '',
				'qa_effects' => (array) $context->get( 'effects', array() ),
			)
		);
	}
}

/**
 * QA5 krytyk — stan zmienia się tylko przez maszynę.
 */
class MP_SW_D5_QA_Critic extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( empty( $data['qa_allowed'] ) ) {
			return MP_SW_Result::fail(
				__( 'Przejście nierozstrzygnięte przez maszynę statusów.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'transition' ) ),
				'transition_not_resolved'
			);
		}

		if ( '' === $data['qa_version'] ) {
			// Bez wersji słownika nie da się później odtworzyć, wg jakich reguł
			// zapadła decyzja — a dziennik ma to pokazywać (kryterium 5.5).
			return MP_SW_Result::fail(
				__( 'Decyzja bez wersji słownika przejść.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'machine_version' ) ),
				'missing_machine_version'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * MASZYNA STATUSÓW.
 */
class MP_SW_Department_05 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 5;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'maszyna-statusow';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_D5_Agent_Transition( '5.1', 'przejście', 'Sprawdza przejście w słowniku: nowy → przypisany → oferta_robocza → oferta_wyslana → negocjacje → wygrany / przegrany' ),
				'critic' => new MP_SW_D5_Critic_Legality( 'K5.1', 'legalność-przejścia', 'Przejście spoza słownika = odmowa z kodem, stan bez zmian' ),
			),
			array(
				'agent'  => new MP_SW_D5_Agent_Effects( '5.2', 'skutki', 'Wylicza skutki przejścia: nowe SLA, zamknięcie oczekujących zadań, powiadomienia do wysłania' ),
				'critic' => new MP_SW_D5_Critic_Effects( 'K5.2', 'komplet-skutków', 'Każdy skutek ma pokrycie w regule przejścia — nic „przy okazji”' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_D5_QA_Agent( 'QA5', 'bramka jakosci D5', 'legalność-przejścia + komplet skutków' ),
			new MP_SW_D5_QA_Critic( 'QA5.K', 'krytyk bramki D5', 'stan zmienia się tylko przez maszynę, nigdy wprost' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'MASZYNA STATUSÓW',
			'pkt 2: statusy procesu',
			$pairs,
			$gate
		);
	}
}
