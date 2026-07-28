<?php
/**
 * Dzial 7 pipeline LP.3 — POWIADOMIENIA E-MAIL.
 *
 * Zakres wg diagramu: pkt 2: powiadomienia e-mail · aut. 4.4
 *
 * Operacje dzialu:
 *  - Kolejka: wiersze queued, wysyłka po COMMIT
 *  - Szablony pl/en z wersją
 *  - Zero SMTP w żądaniu
 *
 * Zrodlo (Golden Rule #2): docs/dzial-07/powiadomienia-e-mail.md
 * — jeden plik dokumentacji na dzial, czytany przez agentow i krytykow.
 * Diagram wskazywal: wp_mail() (Code Reference) · wp_mail_content_type
 *
 * Dzial NIE WYSYLA. Powod jest w cytacie z dokumentacji wp_mail(): zwrocenie
 * prawdy "does not automatically mean that the user received the email
 * successfully" — a wysylka i tak trwa tyle, ile odpowiada serwer SMTP, wiec
 * jeden zawieszony serwer zawieszalby cale zadanie AJAX. Dzial buduje wiersze
 * kolejki; wysylke uruchamia Dzial 9, juz po COMMIT.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reguly doboru odbiorcow, szablonow i tresci.
 */
class MP_SW_D7_Notifier {

	/** Odbiorca: klient końcowy. */
	const AUDIENCE_CLIENT = 'client';

	/** Odbiorca: handlowiec prowadzący proces. */
	const AUDIENCE_SALESMAN = 'salesman';

	/** Wiersz czeka w kolejce na wysyłkę. */
	const STATUS_QUEUED = 'queued';

	/**
	 * Język powiadomień do handlowca.
	 *
	 * Wprost z diagramu: "klient (język procesu) po akceptacji oferty;
	 * handlowiec (pl) przy przypisaniu i follow-upie".
	 */
	const LANG_SALESMAN = 'pl';

	/** Wzorzec znacznika w szablonie. */
	const MARKER = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

	/** @var int Liczba prób wysyłki przechwyconych w trakcie żądania. */
	protected static $mail_attempts = 0;

	/**
	 * Wpina strażnika wysyłki — liczy KAŻDE wywołanie wp_mail().
	 *
	 * Bez tego "zero wysyłki w żądaniu" byłoby deklaracją: krytyk K7.3 miałby
	 * do sprawdzenia wyłącznie własne dane, a nie fakt.
	 *
	 * @return void
	 */
	public static function install_guard() {
		add_filter( 'wp_mail', array( __CLASS__, 'count_mail' ), 1 );
	}

	/**
	 * Zlicza wywołanie wysyłki i przepuszcza je bez zmian.
	 *
	 * @param array $args Argumenty wp_mail().
	 * @return array
	 */
	public static function count_mail( $args ) {
		++self::$mail_attempts;
		return $args;
	}

	/**
	 * Licznik prób wysyłki.
	 *
	 * @return int
	 */
	public static function mail_attempts() {
		return self::$mail_attempts;
	}

	/**
	 * Zeruje licznik (używane w testach i przy przetwarzaniu wielu zdarzeń).
	 *
	 * @return void
	 */
	public static function reset_mail_attempts() {
		self::$mail_attempts = 0;
	}

	/**
	 * Szablon dla powiadomienia do handlowca w danym przejściu.
	 *
	 * @param string $to_status Status docelowy.
	 * @return string
	 */
	public static function salesman_template( $to_status ) {
		return MP_Sales_Workflow_DB::STATUS_ASSIGNED === $to_status
			? MP_SW_Templates::TPL_LEAD_ASSIGNED
			: MP_SW_Templates::TPL_STATUS_CHANGED;
	}

	/**
	 * Znaczniki pozostawione bez wartości.
	 *
	 * @param string $text Tekst po podstawieniu.
	 * @return string[]
	 */
	public static function unresolved_markers( $text ) {
		$found = array();

		if ( preg_match_all( self::MARKER, (string) $text, $matches ) ) {
			$found = array_values( array_unique( $matches[1] ) );
		}

		return $found;
	}

	/**
	 * Podstawia zmienne w szablonie.
	 *
	 * @param string $text Szablon.
	 * @param array  $vars Zmienne.
	 * @return string
	 */
	public static function render( $text, array $vars ) {
		$out = (string) $text;

		foreach ( $vars as $key => $value ) {
			$out = str_replace( '{{' . $key . '}}', (string) $value, $out );
		}

		return $out;
	}
}

/**
 * A7.1 „adresaci" — typ zdarzenia rozstrzyga, kto dostaje co i w jakim jezyku.
 */
class MP_SW_D7_Agent_Recipients extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot   = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$flow       = isset( $snapshot['flow'] ) ? (array) $snapshot['flow'] : array();
		$effects    = (array) $context->get( 'effects', array() );
		$transition = (array) $context->get( 'transition', array() );
		$plan       = (array) $context->get( 'tasks_plan', MP_SW_D6_Scheduler::empty_plan() );

		$assignment = (array) $context->get( 'assignment', array() );
		$owner_id   = isset( $assignment['user_id'] ) ? (int) $assignment['user_id'] : 0;

		$salesman  = self::find_salesman( $snapshot, $owner_id );
		$client    = self::client_data( $context, $flow );
		$lang_flow = self::process_lang( $context, $flow );

		$recipients = array();

		if ( in_array( MP_SW_D5_Machine::EFFECT_NOTIFY_SALESMAN, $effects, true ) ) {
			$recipients[] = array(
				'audience'  => MP_SW_D7_Notifier::AUDIENCE_SALESMAN,
				'template'  => MP_SW_D7_Notifier::salesman_template( isset( $transition['to'] ) ? (string) $transition['to'] : '' ),
				'lang'      => MP_SW_D7_Notifier::LANG_SALESMAN,
				'email'     => $salesman['email'],
				'user_id'   => $salesman['user_id'],
				'name'      => $salesman['name'],
				'reason'    => MP_SW_D5_Machine::EFFECT_NOTIFY_SALESMAN,
				'task_type' => '',
			);
		}

		if ( in_array( MP_SW_D5_Machine::EFFECT_NOTIFY_CLIENT, $effects, true ) ) {
			$recipients[] = array(
				'audience'  => MP_SW_D7_Notifier::AUDIENCE_CLIENT,
				'template'  => MP_SW_Templates::TPL_OFFER_SENT,
				// Klient dostaje wiadomość w języku PROCESU, nie w języku witryny —
				// to jego język ustalono przy pozyskaniu leada.
				'lang'      => $lang_flow,
				'email'     => $client['email'],
				'user_id'   => 0,
				'name'      => $client['name'],
				'reason'    => MP_SW_D5_Machine::EFFECT_NOTIFY_CLIENT,
				'task_type' => '',
			);
		}

		/*
		 * Follow-up, który właśnie wypadł i przeszedł wartownika, to jedyny
		 * przypadek, w którym powiadomienie nie wynika ze zmiany statusu —
		 * przeciwnie, powstaje dlatego, że status się NIE zmienił.
		 */
		foreach ( (array) $plan['fire'] as $task ) {
			$recipients[] = array(
				'audience'  => MP_SW_D7_Notifier::AUDIENCE_SALESMAN,
				'template'  => MP_SW_Templates::TPL_FOLLOWUP_DUE,
				'lang'      => MP_SW_D7_Notifier::LANG_SALESMAN,
				'email'     => $salesman['email'],
				'user_id'   => $salesman['user_id'],
				'name'      => $salesman['name'],
				'reason'    => 'followup_due',
				'task_type' => (string) $task['type'],
			);
		}

		return MP_SW_Result::ok( array( 'recipients' => $recipients ) );
	}

	/**
	 * Handlowiec ze snapshotu (bez sięgania do bazy).
	 *
	 * @param array $snapshot Snapshot Działu 2.
	 * @param int   $user_id  Identyfikator właściciela procesu.
	 * @return array
	 */
	private static function find_salesman( array $snapshot, $user_id ) {
		$empty = array(
			'user_id' => $user_id,
			'email'   => '',
			'name'    => '',
		);

		/*
		 * Przeszukujemy OBIE listy. Handlowiec z niepełną konfiguracją nie dostaje
		 * nowych procesów (Dział 4), ale te już prowadzone nadal są jego — i to on
		 * ma dostać powiadomienie o zmianie statusu.
		 */
		$list = array_merge(
			isset( $snapshot['salesmen']['complete'] ) ? (array) $snapshot['salesmen']['complete'] : array(),
			isset( $snapshot['salesmen']['incomplete'] ) ? (array) $snapshot['salesmen']['incomplete'] : array()
		);

		foreach ( $list as $entry ) {
			if ( (int) $entry['user_id'] === $user_id ) {
				return array(
					'user_id' => $user_id,
					'email'   => (string) $entry['email'],
					'name'    => (string) $entry['name'],
				);
			}
		}

		return $empty;
	}

	/**
	 * Dane klienta — WYŁĄCZNIE ze snapshotu (I-3).
	 *
	 * Kolejność źródeł: najpierw tabela leadów wtyczki 1 (odczytana po `lead_id`
	 * w Dziale 2), potem wiersz procesu. Koperta zdarzenia NIE jest już źródłem
	 * adresu i to jest właściwa poprawka, nie tylko porządek: dopóki adres mógł
	 * przyjść w kopercie, dowolna wtyczka na tej instalacji mogła wywołać
	 * `mp_lead_created` z własnym adresem i przekierować firmową ofertę.
	 *
	 * Wiersz procesu zostaje jako źródło zapasowe dla procesów prowadzonych bez
	 * wtyczki 1 — tam nie ma leada do odczytania, a adres wpisał człowiek.
	 *
	 * @param MP_SW_Context $context Kontekst.
	 * @param array         $flow    Sekcja procesu.
	 * @return array
	 */
	private static function client_data( MP_SW_Context $context, array $flow ) {
		$row      = isset( $flow['row'] ) ? (array) $flow['row'] : array();
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$lead     = isset( $snapshot['lead'] ) ? (array) $snapshot['lead'] : array();

		$name  = isset( $lead['name'] ) ? (string) $lead['name'] : '';
		$email = isset( $lead['email'] ) ? (string) $lead['email'] : '';

		if ( '' === $name && isset( $row['client_name'] ) ) {
			$name = (string) $row['client_name'];
		}

		if ( '' === $email && isset( $row['client_email'] ) ) {
			$email = (string) $row['client_email'];
		}

		return array(
			'name'  => $name,
			'email' => $email,
		);
	}

	/**
	 * Język procesu.
	 *
	 * @param MP_SW_Context $context Kontekst.
	 * @param array         $flow    Sekcja procesu.
	 * @return string
	 */
	private static function process_lang( MP_SW_Context $context, array $flow ) {
		if ( isset( $flow['row']['lang'] ) && '' !== (string) $flow['row']['lang'] ) {
			return (string) $flow['row']['lang'];
		}

		$event = (array) $context->get( 'event', array() );

		return isset( $event['lang'] ) ? (string) $event['lang'] : 'pl';
	}
}

/**
 * K7.1 „dobor-szablonu" — brak szablonu w jezyku odbiorcy = FAIL, nie ciche pl.
 */
class MP_SW_D7_Critic_Template extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data       = $agent_result->get_data();
		$recipients = isset( $data['recipients'] ) ? (array) $data['recipients'] : array();
		$snapshot   = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$set        = isset( $snapshot['templates']['set'] ) ? (array) $snapshot['templates']['set'] : array();

		foreach ( $recipients as $recipient ) {
			$key  = (string) $recipient['template'];
			$lang = (string) $recipient['lang'];

			if ( ! isset( $set[ $key ] ) ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: klucz szablonu. */
						__( 'Brak szablonu %s w rejestrze.', 'mp-sales-workflow' ),
						$key
					),
					array( 'errors' => array( 'template.' . $key ) ),
					'template_missing'
				);
			}

			/*
			 * Podmiana na polski „żeby coś poszło" byłaby najgorszym wyjściem:
			 * klient dostałby wiadomość w języku, którego nie zna, i nikt by się o
			 * tym nie dowiedział. Brak wersji językowej zatrzymuje przebieg.
			 */
			if ( empty( $set[ $key ][ $lang ]['subject'] ) || empty( $set[ $key ][ $lang ]['body'] ) ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: 1: klucz szablonu, 2: język odbiorcy. */
						__( 'Szablon %1$s nie ma wersji w języku %2$s.', 'mp-sales-workflow' ),
						$key,
						$lang
					),
					array( 'errors' => array( 'template.' . $key . '.' . $lang ) ),
					'template_lang_missing'
				);
			}

			if ( empty( $set[ $key ]['version'] ) ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: klucz szablonu. */
						__( 'Szablon %s bez wersji — wysyłka byłaby nieodtwarzalna.', 'mp-sales-workflow' ),
						$key
					),
					array( 'errors' => array( 'template.' . $key . '.version' ) ),
					'template_without_version'
				);
			}
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A7.2 „tresc" — podstawia zmienne i zapisuje wersje szablonu.
 */
class MP_SW_D7_Agent_Content extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot   = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$set        = isset( $snapshot['templates']['set'] ) ? (array) $snapshot['templates']['set'] : array();
		$flow       = isset( $snapshot['flow'] ) ? (array) $snapshot['flow'] : array();
		$row        = isset( $flow['row'] ) ? (array) $flow['row'] : array();
		$recipients = (array) $context->get( 'recipients', array() );
		$transition = (array) $context->get( 'transition', array() );
		$event      = (array) $context->get( 'event', array() );
		$envelope   = (array) $context->get( 'client', array() );

		$entity   = isset( $event['entity'] ) ? (array) $event['entity'] : array();
		$lead_id  = isset( $entity['lead_id'] ) ? (int) $entity['lead_id'] : 0;
		$offsets  = MP_SW_D6_Scheduler::plan_types();
		$messages = array();

		foreach ( $recipients as $recipient ) {
			$key  = (string) $recipient['template'];
			$lang = (string) $recipient['lang'];
			$tpl  = isset( $set[ $key ][ $lang ] )
				? (array) $set[ $key ][ $lang ]
				: array(
					'subject' => '',
					'body'    => '',
				);
			$days = isset( $offsets[ $recipient['task_type'] ] ) ? (int) $offsets[ $recipient['task_type'] ] : 0;

			$vars = array(
				'client_name'   => self::pick( $row, 'client_name', isset( $envelope['name'] ) ? $envelope['name'] : '' ),
				'country'       => self::pick( $row, 'country', (string) $context->get( 'country', '' ) ),
				'segment'       => self::pick( $row, 'segment', '' ),
				'offer_number'  => self::pick( $row, 'offer_number', '' ),
				'sla_due_at'    => (string) $context->get( 'sla_due_at', isset( $row['sla_due_at'] ) ? $row['sla_due_at'] : '' ),
				'status_from'   => isset( $transition['from'] ) ? (string) $transition['from'] : '',
				'status_to'     => isset( $transition['to'] ) ? (string) $transition['to'] : '',
				'salesman_name' => (string) $recipient['name'],
				'days'          => $days > 0 ? (string) $days : '',

				/*
				 * Załącznik idzie ADRESEM, nie plikiem. Dokument doklejony do
				 * każdej wiadomości musiałby zostać wczytany do pamięci w pętli
				 * kolejki, a przy ofercie na kilka megabajtów i kilkudziesięciu
				 * powiadomieniach kończy się to wyczerpaniem limitu pamięci.
				 */
				'link'          => self::link_for( $recipient, $snapshot, $lead_id ),
			);

			$subject = MP_SW_D7_Notifier::render( $tpl['subject'], $vars );

			$messages[] = array(
				'audience'         => (string) $recipient['audience'],
				'template'         => $key,
				'template_version' => (string) $set[ $key ]['version'],
				'lang'             => $lang,
				'recipient'        => (string) $recipient['email'],
				'recipient_name'   => (string) $recipient['name'],
				'user_id'          => (int) $recipient['user_id'],

				/*
				 * Temat trafia do NAGŁÓWKA wiadomości, a jego treść pochodzi z pola
				 * `client_name` wypełnionego przez anonima w publicznym formularzu.
				 * Znak końca linii w tym miejscu kończy nagłówek „Subject" i zaczyna
				 * następny — czyli pozwala dopisać własne `Bcc:`. Czyścimy, a próbę
				 * odnotowujemy, żeby krytyk mógł odrzucić całą wiadomość.
				 */
				'subject'          => MP_SW_Mailer::header_safe( $subject ),
				'subject_raw'      => $subject,
				'header_attempt'   => MP_SW_Mailer::has_injection( $subject ) || MP_SW_Mailer::has_injection( $recipient['name'] ),
				'link'             => (string) $vars['link'],
				'body'             => MP_SW_D7_Notifier::render( $tpl['body'], $vars ),
				'reason'           => (string) $recipient['reason'],
			);
		}

		return MP_SW_Result::ok( array( 'messages' => $messages ) );
	}

	/**
	 * Wartość z wiersza procesu albo wartość zastępcza.
	 *
	 * @param array  $row      Wiersz procesu.
	 * @param string $key      Kolumna.
	 * @param string $fallback Wartość zastępcza.
	 * @return string
	 */
	private static function pick( array $row, $key, $fallback ) {
		$value = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';

		return '' !== $value ? $value : (string) $fallback;
	}

	/**
	 * Adres w wiadomości — inny dla klienta, inny dla handlowca.
	 *
	 * To była realna usterka, nie tylko kwestia bezpieczeństwa: klient dostawał
	 * w ofercie adres `wp-admin`, którego nie może otworzyć, bo nie ma konta.
	 * Klientowi należy się PODPISANY link do dokumentu, handlowcowi — podstrona
	 * procesu w panelu.
	 *
	 * @param array $recipient Odbiorca.
	 * @param array $snapshot  Snapshot Działu 2.
	 * @param int   $lead_id   Identyfikator leada.
	 * @return string
	 */
	private static function link_for( array $recipient, array $snapshot, $lead_id ) {
		$audience = isset( $recipient['audience'] ) ? (string) $recipient['audience'] : '';

		if ( MP_SW_D7_Notifier::AUDIENCE_CLIENT !== $audience ) {
			return self::process_link( $lead_id );
		}

		$offer  = isset( $snapshot['offer'] ) ? (array) $snapshot['offer'] : array();
		$handle = isset( $offer['handle'] ) ? (string) $offer['handle'] : '';

		/*
		 * Pusty adres NIE jest tu zastępowany adresem panelu. Znacznik `{{link}}`
		 * zostanie wtedy nierozwiązany, a krytyk K7.2 odrzuci wiadomość — i o to
		 * chodzi: brak klucza podpisu ma zatrzymać wysyłkę, a nie wypuścić do
		 * klienta wiadomość z odnośnikiem, którego nie otworzy.
		 */
		return MP_SW_Download::url( $handle );
	}

	/**
	 * Adres podstrony procesu.
	 *
	 * Funkcja admin_url() korzysta z opcji wczytanych już przy starcie WordPressa —
	 * to formatowanie ciągu, nie kolejny strzał do bazy.
	 *
	 * @param int $lead_id Identyfikator leada.
	 * @return string
	 */
	private static function process_link( $lead_id ) {
		return add_query_arg(
			array(
				'page' => 'mp-sales-workflow',
				'lead' => (int) $lead_id,
			),
			admin_url( 'admin.php' )
		);
	}
}

/**
 * K7.2 „puste-pola" — zaden znacznik nie zostaje pusty.
 */
class MP_SW_D7_Critic_Empty_Fields extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data     = $agent_result->get_data();
		$messages = isset( $data['messages'] ) ? (array) $data['messages'] : array();

		foreach ( $messages as $message ) {
			$unresolved = array_merge(
				MP_SW_D7_Notifier::unresolved_markers( $message['subject'] ),
				MP_SW_D7_Notifier::unresolved_markers( $message['body'] )
			);

			if ( ! empty( $unresolved ) ) {
				// Wiadomość z widocznym „{{offer_number}}" jest gorsza niż jej brak:
				// wychodzi do klienta i nie da się jej cofnąć.
				return MP_SW_Result::fail(
					sprintf(
						/* translators: 1: klucz szablonu, 2: lista znaczników. */
						__( 'Szablon %1$s ma nierozwiązane znaczniki: %2$s', 'mp-sales-workflow' ),
						$message['template'],
						implode( ', ', array_unique( $unresolved ) )
					),
					array( 'errors' => array( 'messages.markers' ) ),
					'unresolved_markers'
				);
			}

			if ( ! empty( $message['header_attempt'] ) ) {
				/*
				 * Odrzucamy, mimo że temat został już oczyszczony. Cicha naprawa
				 * wysłałaby wiadomość i ukryła fakt, że ktoś wpisał w nazwę firmy
				 * znak końca linii — a to jedyny moment, w którym da się to
				 * zauważyć. Wiadomość zatrzymana da się wysłać ponownie; wysłana
				 * nie da się cofnąć.
				 */
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: klucz szablonu. */
						__( 'Powiadomienie %s odrzucone: próba wstrzyknięcia nagłówka.', 'mp-sales-workflow' ),
						$message['template']
					),
					array(
						'errors'      => array( 'messages.header' ),
						'http_status' => 422,
					),
					'header_injection'
				);
			}

			if ( MP_SW_D7_Notifier::AUDIENCE_CLIENT === (string) $message['audience'] && '' === trim( (string) $message['link'] ) ) {
				/*
				 * Oferta bez adresu dokumentu jest dla klienta bezużyteczna, ale
				 * powody bywają dwa i naprawia je KTO INNY. Brak klucza podpisu to
				 * usterka wdrożeniowa — sprawa administratora. Brak samego dokumentu
				 * (oferta wciąż w szkicu, PDF jeszcze nie powstał) to zwykły stan
				 * pracy, który handlowiec usuwa sam, dokańczając ofertę w module
				 * ofertowym. Jeden wspólny komunikat kazał mu szukać winy nie tam,
				 * gdzie trzeba — widać to było dopiero, gdy pulpit dostał przycisk
				 * „Zatwierdź i wyślij ofertę".
				 */
				$brak_klucza = ( '' === MP_SW_Download::key() );

				return MP_SW_Result::fail(
					$brak_klucza
						? __( 'Powiadomienie do klienta bez adresu dokumentu — sprawdź MP_SW_LINK_KEY.', 'mp-sales-workflow' )
						: __( 'Oferta nie ma jeszcze gotowego dokumentu — nie ma czego wysłać klientowi.', 'mp-sales-workflow' ),
					array(
						'errors'      => array( 'messages.link' ),
						'http_status' => $brak_klucza ? 500 : 409,
					),
					$brak_klucza ? 'unresolved_markers' : 'offer_document_missing'
				);
			}

			if ( '' === trim( (string) $message['recipient'] ) || ! is_email( $message['recipient'] ) ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: 1: klucz szablonu, 2: adres odbiorcy. */
						__( 'Powiadomienie %1$s bez poprawnego adresu odbiorcy (%2$s).', 'mp-sales-workflow' ),
						$message['template'],
						'' === $message['recipient'] ? '—' : $message['recipient']
					),
					array( 'errors' => array( 'messages.recipient' ) ),
					'invalid_recipient'
				);
			}

			if ( ! empty( $message['attachments'] ) ) {
				return MP_SW_Result::fail(
					__( 'Powiadomienie z plikiem w załączniku — dokument ma iść adresem.', 'mp-sales-workflow' ),
					array( 'errors' => array( 'messages.attachments' ) ),
					'attachment_not_allowed'
				);
			}

			if ( '' === trim( (string) $message['template_version'] ) ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: klucz szablonu. */
						__( 'Powiadomienie %s bez wersji szablonu.', 'mp-sales-workflow' ),
						$message['template']
					),
					array( 'errors' => array( 'messages.template_version' ) ),
					'missing_template_version'
				);
			}
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * A7.3 „kolejka" — wiersze queued gotowe do zapisu w Dziale 8.
 */
class MP_SW_D7_Agent_Queue extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$snapshot = (array) $context->get( MP_SW_D2_Reader::SNAPSHOT_KEY, array() );
		$flow     = isset( $snapshot['flow'] ) ? (array) $snapshot['flow'] : array();
		$messages = (array) $context->get( 'messages', array() );

		$flow_id  = isset( $flow['row']['id'] ) ? (int) $flow['row']['id'] : 0;
		$event_id = (string) $context->get( 'event_id', '' );
		$now      = current_time( 'mysql', true );

		$queue = array();

		foreach ( $messages as $message ) {
			$queue[] = array(
				'flow_id'           => $flow_id,
				'event_id'          => $event_id,
				'template'          => (string) $message['template'],
				'template_version'  => (string) $message['template_version'],
				'lang'              => (string) $message['lang'],
				'recipient'         => (string) $message['recipient'],
				'recipient_user_id' => (int) $message['user_id'] > 0 ? (int) $message['user_id'] : null,
				'subject'           => (string) $message['subject'],
				'body'              => (string) $message['body'],
				// Jedyny dopuszczalny stan wychodzący z pipeline'u. `sent` mógłby tu
				// powstać wyłącznie po wysyłce w żądaniu, czyli po złamaniu 1 AJAX.
				'status'            => MP_SW_D7_Notifier::STATUS_QUEUED,
				'attempts'          => 0,
				'sent_at'           => null,
				'created_at'        => $now,
				'updated_at'        => $now,
			);
		}

		return MP_SW_Result::ok(
			array(
				'notifications' => $queue,
				'mail_attempts' => MP_SW_D7_Notifier::mail_attempts(),
			)
		);
	}
}

/**
 * K7.3 „zero-wysylki-w-zadaniu" — w pipeline zadnego SMTP.
 */
class MP_SW_D7_Critic_No_Send extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data  = $agent_result->get_data();
		$queue = isset( $data['notifications'] ) ? (array) $data['notifications'] : array();

		/*
		 * Sprawdzenie FAKTU, nie deklaracji: strażnik zlicza każde wywołanie
		 * wp_mail() od startu żądania. Gdyby ktoś kiedyś dołożył wysyłkę
		 * „tymczasowo, na czas testów", zatrzyma się tutaj.
		 */
		if ( MP_SW_D7_Notifier::mail_attempts() > 0 ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: %d: liczba prób wysyłki. */
					__( 'Wysyłka w żądaniu — przechwycono wywołań wp_mail(): %d.', 'mp-sales-workflow' ),
					MP_SW_D7_Notifier::mail_attempts()
				),
				array( 'errors' => array( 'mail_attempts' ) ),
				'send_in_request'
			);
		}

		foreach ( $queue as $row ) {
			if ( MP_SW_D7_Notifier::STATUS_QUEUED !== $row['status'] ) {
				return MP_SW_Result::fail(
					sprintf(
						/* translators: %s: stan wiersza kolejki. */
						__( 'Wiersz kolejki w stanie %s zamiast queued.', 'mp-sales-workflow' ),
						$row['status']
					),
					array( 'errors' => array( 'notifications.status' ) ),
					'unexpected_queue_status'
				);
			}

			if ( 0 !== (int) $row['attempts'] || null !== $row['sent_at'] ) {
				return MP_SW_Result::fail(
					__( 'Wiersz kolejki ze śladem wysyłki przed zapisem.', 'mp-sales-workflow' ),
					array( 'errors' => array( 'notifications.attempts' ) ),
					'premature_send_trace'
				);
			}
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * QA7 — bramka jakosci: szablon + jezyk + zmienne.
 */
class MP_SW_D7_QA_Agent extends MP_SW_Abstract_Agent {

	/**
	 * @param MP_SW_Context $context Kontekst.
	 * @return MP_SW_Result
	 */
	public function run( MP_SW_Context $context ) {
		$queue    = (array) $context->get( 'notifications', array() );
		$messages = (array) $context->get( 'messages', array() );

		$complete = 0;

		foreach ( $messages as $message ) {
			$filled = '' !== trim( (string) $message['subject'] ) && '' !== trim( (string) $message['body'] );

			if ( $filled && '' !== (string) $message['template_version'] && '' !== (string) $message['lang'] ) {
				++$complete;
			}
		}

		return MP_SW_Result::ok(
			array(
				'qa_messages'      => count( $messages ),
				'qa_complete'      => $complete,
				'qa_queued'        => count( $queue ),
				'qa_mail_attempts' => MP_SW_D7_Notifier::mail_attempts(),
			)
		);
	}
}

/**
 * QA7 krytyk — wysylka w zadaniu = FAIL (lamie 1 AJAX).
 */
class MP_SW_D7_QA_Critic extends MP_SW_Abstract_Critic {

	/**
	 * @param MP_SW_Result  $agent_result Wynik agenta.
	 * @param MP_SW_Context $context      Kontekst.
	 * @return MP_SW_Result
	 */
	public function review( MP_SW_Result $agent_result, MP_SW_Context $context ) {
		$data = $agent_result->get_data();

		if ( $data['qa_mail_attempts'] > 0 ) {
			return MP_SW_Result::fail(
				__( 'Powiadomienie wysłane w żądaniu — łamie zasadę 1 AJAX.', 'mp-sales-workflow' ),
				array( 'errors' => array( 'mail_attempts' ) ),
				'send_in_request'
			);
		}

		if ( $data['qa_messages'] !== $data['qa_complete'] ) {
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: liczba powiadomień, 2: liczba kompletnych. */
					__( 'Powiadomienia niekompletne: %1$d, z kompletem szablon+język+zmienne: %2$d.', 'mp-sales-workflow' ),
					$data['qa_messages'],
					$data['qa_complete']
				),
				array( 'errors' => array( 'messages' ) ),
				'incomplete_notifications'
			);
		}

		if ( $data['qa_messages'] !== $data['qa_queued'] ) {
			// Powiadomienie zbudowane, ale niewstawione do kolejki, nigdy by nie
			// wyszło — a dziennik pokazywałby, że zostało przygotowane.
			return MP_SW_Result::fail(
				sprintf(
					/* translators: 1: liczba powiadomień, 2: liczba wierszy kolejki. */
					__( 'Powiadomień %1$d, wierszy kolejki %2$d.', 'mp-sales-workflow' ),
					$data['qa_messages'],
					$data['qa_queued']
				),
				array( 'errors' => array( 'notifications' ) ),
				'queue_mismatch'
			);
		}

		return MP_SW_Result::ok( $data );
	}
}

/**
 * POWIADOMIENIA E-MAIL.
 */
class MP_SW_Department_07 {

	/** Numer dzialu w pipeline. */
	const NUMBER = 7;

	/** Slug dzialu (uzywany w logach i komunikatach bramki). */
	const KEY = 'powiadomienia-e-mail';

	/**
	 * Buduje dzial wraz z parami i bramka jakosci.
	 *
	 * @return MP_SW_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_SW_D7_Agent_Recipients( '7.1', 'adresaci', 'Typ zdarzenia → odbiorcy i szablon: klient (język procesu) po akceptacji oferty; handlowiec (pl) przy przypisaniu i follow-upie' ),
				'critic' => new MP_SW_D7_Critic_Template( 'K7.1', 'dobór-szablonu', 'Brak szablonu w języku odbiorcy = FAIL, nie ciche pl' ),
			),
			array(
				'agent'  => new MP_SW_D7_Agent_Content( '7.2', 'treść', 'Podstawia zmienne (numer oferty, adres PDF ze zdarzenia, terminy); wersja szablonu do dziennika' ),
				'critic' => new MP_SW_D7_Critic_Empty_Fields( 'K7.2', 'puste-pola', 'Żaden znacznik nie zostaje pusty; załącznik jako adres, nie plik w pętli' ),
			),
			array(
				'agent'  => new MP_SW_D7_Agent_Queue( '7.3', 'kolejka', 'Wiersze queued w wp_mp_notifications — wysyłka wp_mail dopiero z kolejki, z ponowieniami i licznikiem prób' ),
				'critic' => new MP_SW_D7_Critic_No_Send( 'K7.3', 'zero-wysyłki-w-żądaniu', 'W pipeline żadnego SMTP; status sent/failed uzupełnia kolejka' ),
			),
		);

		$gate = new MP_SW_Quality_Gate(
			new MP_SW_D7_QA_Agent( 'QA7', 'bramka jakosci D7', 'kompletność-powiadomień: szablon + język + zmienne' ),
			new MP_SW_D7_QA_Critic( 'QA7.K', 'krytyk bramki D7', 'wysyłka w żądaniu = FAIL (łamie 1 AJAX)' )
		);

		return new MP_SW_Department(
			self::NUMBER,
			self::KEY,
			'POWIADOMIENIA E-MAIL',
			'pkt 2: powiadomienia e-mail · aut. 4.4',
			$pairs,
			$gate
		);
	}
}
