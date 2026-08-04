<?php
/**
 * Logger błędów pipeline.
 *
 * Zgodnie z zasadą: gdy Krytyk/Bramka wykryje błąd — STOP + log błędu do BD-3
 * (wp_mp_activity_log) + powiadomienie administratora.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zapisuje błędy pipeline do logu aktywności i powiadamia admina.
 */
class MP_Pipeline_Logger {

	/**
	 * Nazwa miejsca awarii dla CZŁOWIEKA.
	 *
	 * Docblock mówił „0 = nieznany", ale ta sama wartość szła wprost do `%d`
	 * w temacie maila, w treści maila i w opisie wpisu w dzienniku. Powstawał
	 * „dział 0" — numer, którego w pipeline nie ma (działy są numerowane od 1)
	 * i którego czytelnik nie ma jak odróżnić od prawdziwego. Administrator
	 * szukał działu numer 0 w dokumentacji zamiast dowiedzieć się, że miejsce
	 * awarii jest NIEUSTALONE.
	 *
	 * Gdy działu nie znamy, tożsamością miejsca jest pochodzenie wyjątku — plik
	 * i linia. Kod liczył je od dawna (kubełek wyciszania, patrz log_exception),
	 * ale człowiek ich nie widział: KAŻDA taka awaria przedstawiała się tak samo,
	 * choć wyciszane były osobno. Dwa niezależne wyjątki dawały więc dwa maile
	 * o identycznym temacie, z których drugi czytało się jak duplikat pierwszego
	 * — tym bardziej że stopka obiecuje wyciszenie „z tego samego miejsca".
	 *
	 * Sama ścieżka NIE idzie do tekstu: nazwa pliku wystarczy, żeby wskazać
	 * miejsce, a katalogi serwera nie są niczyją sprawą poza diagnostyką
	 * (pełną ścieżkę zapisuje `meta_json`).
	 *
	 * @param int    $dept_num Numer działu (0 = nieustalony).
	 * @param string $origin   Pochodzenie awarii („plik.php:123"); puste, gdy nieznane.
	 * @return string
	 */
	protected function department_label( $dept_num, $origin = '' ) {
		$dept_num = (int) $dept_num;

		if ( $dept_num > 0 ) {
			return sprintf( 'dziale %d', $dept_num );
		}

		$origin = (string) $origin;

		if ( '' === $origin ) {
			return __( 'nieustalonym miejscu pipeline\'u', 'mp-lead-intake' );
		}

		return sprintf(
			/* translators: %s: plik i linia, w których powstał wyjątek. */
			__( 'nieustalonym miejscu pipeline\'u (%s)', 'mp-lead-intake' ),
			$origin
		);
	}

	/**
	 * Los alarmu zapisany w tym samym wpisie, który ten alarm wywołał.
	 *
	 * Ogranicznik częstotliwości kończył metodę `return`-em bez żadnego zapisu,
	 * więc wpis o awarii wyglądał DOKŁADNIE tak samo jak ten, przy którym alarm
	 * faktycznie poszedł. Dziennik nie pozwalał odróżnić „alarm wysłany" od
	 * „alarm pominięty" — czyli dokładnie tego, przed czym broni log_alert_failure().
	 * Ostrzeżenie o wyciszeniu istniało wyłącznie w stopce maila, a więc w miejscu,
	 * do którego pracownik przeglądający historię po incydencie nie ma dostępu.
	 *
	 * Stan czytamy PRZED zapisem wpisu i bez skutków ubocznych — `get_transient()`
	 * niczego nie ustawia. Osobnego wiersza nie dokładamy: wpis o awarii i tak
	 * powstaje przy każdym zdarzeniu, a przy zalewie błędów drugi wiersz na
	 * zdarzenie podwoiłby dziennik dokładnie wtedy, gdy jest najdłuższy.
	 *
	 * @param string $throttle_key Klucz ogranicznika dla tego miejsca.
	 * @return string „wyciszony" albo „wysylany".
	 */
	protected function alert_state( $throttle_key ) {
		return get_transient( $throttle_key ) ? 'wyciszony' : 'wysylany';
	}

	/**
	 * Czytelna etykieta losu alarmu — do `description`, czyli do pola, które
	 * lista wpisów w panelu naprawdę pokazuje.
	 *
	 * @param string $stan Los alarmu.
	 * @return string
	 */
	protected function etykieta_alarmu( $stan ) {
		switch ( $stan ) {
			case 'wyslany':
				return __( '[alarm: wysłany do administratora]', 'mp-lead-intake' );
			case 'wyciszony':
				return __( '[alarm: wyciszony — z tego samego miejsca poszedł już w ciągu ostatnich 15 minut]', 'mp-lead-intake' );
			case 'nieudany':
				return __( '[alarm: NIE dotarł do administratora]', 'mp-lead-intake' );
		}

		return __( '[alarm: los nieustalony]', 'mp-lead-intake' );
	}

	/**
	 * Dopisuje do wpisu LOS ALARMU — już po próbie dostarczenia.
	 *
	 * Do 1.3.9 wpis niósł PROGNOZĘ: `alert_state()` czytało ogranicznik PRZED
	 * wysyłką i zapisywało „wysylany" także wtedy, gdy poczta zaraz potem
	 * odmówiła. Dziennik twierdził więc coś, czego kod jeszcze nie wiedział, a
	 * cel zapisany w docblocku `alert_state()` — odróżnienie „alarm wysłany" od
	 * „alarm pominięty" — był dla czytającego historię nieosiągalny w obie
	 * strony: prognoza bywała nieprawdziwa, a samo `meta_json` i tak nie jest
	 * pokazywane na liście.
	 *
	 * Dlatego los trafia TAKŻE do `description`. Nowego wiersza nie dokładamy —
	 * ten sam powód co przy poprzedniej naprawie: przy zalewie błędów drugi
	 * wiersz na zdarzenie podwoiłby dziennik dokładnie wtedy, gdy jest najdłuższy.
	 *
	 * @param int    $wpis_id Identyfikator wpisu w dzienniku.
	 * @param string $opis    Opis zapisany przy wstawieniu.
	 * @param array  $meta    Metadane zapisane przy wstawieniu.
	 * @param string $stan    Los alarmu: wyslany / wyciszony / nieudany.
	 * @return void
	 */
	protected function oznacz_los_alarmu( $wpis_id, $opis, array $meta, $stan ) {
		global $wpdb;

		$wpis_id = (int) $wpis_id;

		if ( $wpis_id <= 0 ) {
			return;
		}

		$meta['alarm'] = $stan;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MP_Lead_Intake_DB::activity_log_table(),
			array(
				'description' => $opis . ' ' . $this->etykieta_alarmu( $stan ),
				'meta_json'   => wp_json_encode( $meta ),
			),
			array( 'id' => $wpis_id )
		);
	}

	/**
	 * Klucz ogranicznika dla alarmu o zatrzymaniu działu.
	 *
	 * Jedno miejsce, bo liczą go dwie metody: `log_failure()` (żeby zapisać los
	 * alarmu we wpisie) i `notify_admin()` (żeby ten alarm ograniczyć). Rozjazd
	 * między nimi dawałby wpis mówiący co innego, niż zrobił kod.
	 *
	 * @param MP_Department $department Dział.
	 * @return string
	 */
	protected function failure_throttle_key( MP_Department $department ) {
		return 'mp_notify_' . $department->get_key();
	}

	/**
	 * Dlaczego alarm nie doszedł — bez zmyślania przyczyny.
	 *
	 * Komunikat twierdził wprost „serwer poczty odrzucił wiadomość", choć kod
	 * niczego takiego nie sprawdził: `wp_mail()` zwraca false także wtedy, gdy
	 * do serwera poczty w ogóle nie doszło (filtr `pre_wp_mail` innej wtyczki,
	 * niepoprawny adres w `admin_email`, wyjątek PHPMailera). Pracownik czytał
	 * o awarii SMTP i sprawdzał konfigurację, która działa poprawnie, a prawdziwa
	 * przyczyna zostawała nieznaleziona.
	 *
	 * @return string
	 */
	protected function delivery_failure_note() {
		return __( 'wp_mail() zgłosiło niepowodzenie; przyczyny kod nie zna — sprawdź konfigurację poczty, adres w ustawieniach witryny i wtyczki podpięte pod wysyłkę.', 'mp-lead-intake' );
	}

	/**
	 * Loguje porażkę działu do BD-3 i powiadamia administratora.
	 *
	 * @param MP_Department $department Dział, w którym wystąpił błąd.
	 * @param MP_Result     $result     Wynik z błędami.
	 * @param MP_Context    $context    Kontekst pipeline.
	 * @return void
	 */
	public function log_failure( MP_Department $department, MP_Result $result, MP_Context $context ) {
		global $wpdb;

		$table = MP_Lead_Intake_DB::activity_log_table();

		$opis = sprintf(
			'Błąd w dziale %d (%s), kod: %s',
			$department->get_number(),
			$department->get_key(),
			$result->get_code()
		);

		/*
		 * POWÓD SŁOWAMI, nie tylko kod.
		 *
		 * Do 1.3.10 opis kończył się na numerze działu i kodzie maszynowym,
		 * a czytelne komunikaty z `get_errors()` szły wyłącznie do `meta_json` —
		 * czyli do kolumny, o której ten sam plik dwa razy pisze, że nie jest
		 * pokazywana na liście wpisów w panelu. Administrator oglądał dziennik
		 * pełen kodów, mając powód zapisany obok, w miejscu niewidocznym.
		 *
		 * Bliźniacza `log_exception()` niżej robi to od dawna („Nieoczekiwany
		 * wyjątek w %s: %s"). Ścieżka kontrolowanego STOP-u została wtedy
		 * pominięta.
		 */
		$powody = array_filter(
			array_map(
				static function ( $b ) {
					return trim( (string) $b );
				},
				(array) $result->get_errors()
			),
			static function ( $b ) {
				return '' !== $b;
			}
		);

		if ( $powody ) {
			$opis .= ' — ' . implode( '; ', $powody );
		}

		$meta = array(
			'request_id' => $context->get( 'request_id' ),
			'department' => $department->get_number(),
			'code'       => $result->get_code(),
			'errors'     => $result->get_errors(),
			'data'       => $result->get_data(),
			// Los alarmu jest jeszcze NIEZNANY — wpisujemy go dopiero po próbie
			// dostarczenia. Wcześniej stała tu prognoza z `alert_state()`.
			'alarm'      => 'nieustalony',
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'lead_id'     => $context->get( 'lead_id' ),
				'action'      => 'pipeline_error',
				'description' => $opis,
				'user_id'     => get_current_user_id() ? get_current_user_id() : null,
				'ip_address'  => isset( $_SERVER['REMOTE_ADDR'] ) ? MP_Lead_Intake_DB::anonymize_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : null,
				'meta_json'   => wp_json_encode( $meta ),
			)
		);

		$this->notify_admin( $department, $result, $context, (int) $wpdb->insert_id, $opis, $meta );
	}

	/**
	 * Loguje NIEOCZEKIWANY wyjątek/błąd PHP w trakcie pipeline'u i powiadamia
	 * administratora. W przeciwieństwie do log_failure() (kontrolowany STOP
	 * krytyka/bramki, opisany przez MP_Result) — to ścieżka awaryjna: Throwable
	 * przerwał wykonanie w sposób, którego MP_Result nie mógł opisać (np. wyjątek
	 * w subskrybencie do_action('mp_lead_created') spoza tej wtyczki).
	 *
	 * @param \Throwable $e        Przechwycony wyjątek/błąd.
	 * @param MP_Context $context  Kontekst pipeline w chwili awarii.
	 * @param int        $dept_num Numer działu, w którym doszło do awarii (0 = nieznany).
	 * @return void
	 */
	public function log_exception( \Throwable $e, MP_Context $context, $dept_num ) {
		global $wpdb;

		$table = MP_Lead_Intake_DB::activity_log_table();

		$plik  = (string) $e->getFile();
		$linia = (int) $e->getLine();

		// Pochodzenie dla CZŁOWIEKA — sama nazwa pliku i linia, patrz department_label().
		$origin = '' !== $plik ? basename( $plik ) . ':' . $linia : '';

		/*
		 * `throw new RuntimeException();` daje `getMessage() === ''` — zwyczajny
		 * przypadek u subskrybenta spoza wtyczki, czyli dokładnie tam, gdzie ta
		 * ścieżka jest po to, żeby zadziałać. Opis wpisu urywał się wtedy na
		 * dwukropku („Nieoczekiwany wyjątek w dziale 3:"), a mail miał pustą linię
		 * „Komunikat:". Jedyna rzecz, która cokolwiek mówiła — klasa wyjątku —
		 * zostawała w `meta_json`, którego lista wpisów w panelu nie pokazuje.
		 */
		$komunikat = trim( (string) $e->getMessage() );

		if ( '' === $komunikat ) {
			$komunikat = sprintf(
				/* translators: %s: klasa wyjątku, np. RuntimeException. */
				__( 'wyjątek bez komunikatu (typ: %s)', 'mp-lead-intake' ),
				get_class( $e )
			);
		}

		/*
		 * Dział 0 znaczy „miejsce NIEUSTALONE", a nie „miejsce numer zero". Wszystkie
		 * takie wyjątki dzieliły więc jeden kubełek, czyli dokładnie ten sam błąd
		 * w mniejszej skali: awaria w jednym nieznanym miejscu uciszała na kwadrans
		 * awarię w innym, równie nieznanym. Gdy działu nie znamy, bierzemy za
		 * tożsamość miejsca pochodzenie wyjątku — plik i linię, w których powstał.
		 */
		$miejsce = (int) $dept_num > 0
			? (string) (int) $dept_num
			: 'x' . substr( md5( $plik . ':' . $linia ), 0, 8 );

		$throttle_key = 'mp_notify_exception_' . $miejsce;

		$opis = sprintf( 'Nieoczekiwany wyjątek w %s: %s', $this->department_label( $dept_num, $origin ), $komunikat );

		$meta = array(
			'request_id' => $context->get( 'request_id' ),
			'department' => (int) $dept_num,
			'exception'  => get_class( $e ),
			'message'    => $komunikat,
			// Pełna ścieżka i linia: to material diagnostyczny, nie tekst
			// dla czytelnika. Bez nich dziennik nie pozwalał odróżnić
			// dwóch awarii z dwóch różnych miejsc.
			'file'       => $plik,
			'line'       => $linia,
			// Nieznany do czasu próby dostarczenia — patrz oznacz_los_alarmu().
			'alarm'      => 'nieustalony',
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'lead_id'     => $context->get( 'lead_id' ),
				'action'      => 'pipeline_exception',
				'description' => $opis,
				'user_id'     => get_current_user_id() ? get_current_user_id() : null,
				'ip_address'  => isset( $_SERVER['REMOTE_ADDR'] ) ? MP_Lead_Intake_DB::anonymize_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : null,
				'meta_json'   => wp_json_encode( $meta ),
			)
		);

		$wpis_id = (int) $wpdb->insert_id;

		/*
		 * Ogranicznik OSOBNY DLA KAŻDEGO DZIAŁU — bo dokładnie to obiecuje stopka
		 * alarmu: „kolejne alarmy z tego samego miejsca są wyciszone".
		 *
		 * Jeden wspólny klucz na całą wtyczkę sprawiał, że wyjątek w Dziale 3
		 * uciszał na kwadrans wyjątki z Działu 9. Druga, zupełnie niezależna
		 * awaria nie dawała znaku życia, a administrator czytał w stopce, że
		 * wyciszone jest tylko to jedno miejsce — czyli ten sam błąd, przed
		 * którym ta stopka miała bronić (P1-G10): tekst dla człowieka twierdził
		 * więcej, niż kod robił.
		 *
		 * Tempo nadal jest ograniczone: powtórka z tego samego działu milczy, a
		 * górną granicą jest jedna wiadomość na dział na kwadrans. Ścieżka błędu
		 * działu (`notify_admin()`) liczy się tak samo od zawsze.
		 */

		// Kubełek i jego klucz policzone wyżej — wpis w dzienniku musi mówić
		// o TYM SAMYM ograniczniku, którego za chwilę użyjemy.
		if ( get_transient( $throttle_key ) ) {
			$this->oznacz_los_alarmu( $wpis_id, $opis, $meta, 'wyciszony' );

			return;
		}
		set_transient( $throttle_key, 1, 15 * MINUTE_IN_SECONDS );

		$to = get_option( 'admin_email' );

		/*
		 * Brak adresu administratora to TAKŻE niedostarczony alarm.
		 *
		 * Wyjście po cichu zostawiało dziennik w stanie nie do odróżnienia od
		 * alarmu dostarczonego poprawnie: był wpis o awarii, nie było wpisu
		 * `admin_alert_failed`. Pracownik przeglądający historię po incydencie
		 * przyjmował, że administrator został powiadomiony — a zawiadomienia
		 * nawet nie próbowano wysłać.
		 */
		if ( ! $to ) {
			$this->log_alert_failure(
				sprintf(
					'Alarm o wyjątku w %s NIE został wysłany — w ustawieniach witryny nie ma adresu administratora (admin_email).',
					$this->department_label( $dept_num, $origin )
				),
				array(
					'alert'      => 'pipeline_exception',
					'department' => (int) $dept_num,
					'file'       => $plik,
					'line'       => $linia,
					'reason'     => 'brak_admin_email',
				),
				$context
			);

			$this->oznacz_los_alarmu( $wpis_id, $opis, $meta, 'nieudany' );

			return;
		}

		$sent = wp_mail(
			$to,
			sprintf( '[MP Lead Intake] Nieoczekiwany wyjątek w pipeline (%s)', $this->department_label( $dept_num, $origin ) ),
			sprintf(
				"Pipeline przerwany wyjątkiem w %s.\nTyp: %s\nKomunikat: %s\n%s",
				$this->department_label( $dept_num, $origin ),
				get_class( $e ),
				$komunikat,
				$this->alert_footer( $context )
			)
		);

		if ( ! $sent ) {
			$this->log_alert_failure(
				sprintf(
					'Alarm o wyjątku w %s NIE został wysłany — %s',
					$this->department_label( $dept_num, $origin ),
					$this->delivery_failure_note()
				),
				array(
					'alert'      => 'pipeline_exception',
					'department' => (int) $dept_num,
					'file'       => $plik,
					'line'       => $linia,
				),
				$context
			);
		}

		$this->oznacz_los_alarmu( $wpis_id, $opis, $meta, $sent ? 'wyslany' : 'nieudany' );
	}

	/**
	 * Stopka alarmu: identyfikatory zdarzenia i informacja o wyciszeniu.
	 *
	 * Treść alarmu nie zawierała ŻADNEGO identyfikatora, więc nie dało się
	 * ustalić, którego zgłoszenia dotyczy — a przy tym nie mówiła, że przez
	 * kolejny kwadrans dalsze alarmy z tego samego miejsca są wyciszone.
	 * Administrator czytał ją jak opis pojedynczego, zamkniętego incydentu.
	 * Przy błędzie konfiguracji zatrzymującym 50 zgłoszeń w ciągu kwadransa
	 * obsługiwał jedno i uznawał sprawę za załatwioną.
	 *
	 * @param MP_Context $context Kontekst pipeline.
	 * @return string
	 */
	protected function alert_footer( MP_Context $context ) {
		$lead_id    = (int) $context->get( 'lead_id' );
		$request_id = (string) $context->get( 'request_id', '' );

		return sprintf(
			"\n%s\n%s\n%s\n",
			$lead_id > 0
				? sprintf( 'Zgłoszenie (lead_id): %d', $lead_id )
				: 'Zgłoszenie (lead_id): jeszcze nie powstało — awaria wystąpiła przed zapisem.',
			'' !== $request_id
				? sprintf( 'Identyfikator żądania: %s', $request_id )
				: 'Identyfikator żądania: brak w kontekście.',
			'UWAGA: przez najbliższe 15 minut kolejne alarmy z tego samego miejsca są wyciszone. Ta wiadomość może więc dotyczyć WIĘKSZEJ liczby zgłoszeń — sprawdź dziennik aktywności, zanim uznasz sprawę za zamkniętą.'
		);
	}

	/**
	 * Odnotowuje, że alarm do administratora nie doszedł.
	 *
	 * Alarmu o nieudanym alarmie nie da się wysłać pocztą — a zepsuta poczta
	 * jest najbardziej prawdopodobnym powodem, dla którego pierwszy nie dotarł.
	 * Ślad musi więc zostać tam, gdzie widać go BEZ poczty: w dzienniku, obok
	 * wpisu, który ten alarm wywołał.
	 *
	 * Ogranicznika częstotliwości nie zwalniamy: to limit tempa, a nie znacznik
	 * sukcesu. Przy trwale zepsutym SMTP ponawianie przy każdym błędzie
	 * zamieniłoby jedną wiadomość na kwadrans w lawinę.
	 *
	 * Identyfikatory biorą się z KONTEKSTU, a nie z treści alarmu: treść poszła
	 * do skrzynki, która nie odpowiada, więc dziennik jest jedynym miejscem, po
	 * którym da się dojść, KTÓREGO zgłoszenia sprawa dotyczyła. Bez tego wpis
	 * `admin_alert_failed` był jedynym zdarzeniem w historii, którego nie dało
	 * się przypisać do leada — a jest to dokładnie ten moment, w którym coś
	 * poszło nie tak.
	 *
	 * `lead_id` zostaje pusty, gdy leada jeszcze nie ma (awaria przed zapisem).
	 * Wpisanie tam zera albo liczby zastępczej byłoby gorsze niż brak: pusto
	 * znaczy „nie powstał", a `request_id` w `meta_json` i tak wskazuje żądanie.
	 *
	 * @param string     $description Co dokładnie nie doszło.
	 * @param array      $meta        Dodatkowe fakty do `meta_json`.
	 * @param MP_Context $context     Kontekst pipeline (identyfikatory); null, gdy niedostępny.
	 * @return void
	 */
	protected function log_alert_failure( $description, array $meta = array(), MP_Context $context = null ) {
		global $wpdb;

		$lead_id = null;

		if ( $context instanceof MP_Context ) {
			$lead_id = (int) $context->get( 'lead_id' ) > 0 ? (int) $context->get( 'lead_id' ) : null;

			$request_id = (string) $context->get( 'request_id', '' );
			if ( '' !== $request_id && ! isset( $meta['request_id'] ) ) {
				$meta['request_id'] = $request_id;
			}
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MP_Lead_Intake_DB::activity_log_table(),
			array(
				'lead_id'     => $lead_id,
				'action'      => 'admin_alert_failed',
				'description' => (string) $description,
				// Bez adresu administratora i bez IP: dziennik ma mowic, CO nie
				// doszlo, a nie do kogo mialo pojsc.
				'user_id'     => null,
				'ip_address'  => null,
				'meta_json'   => wp_json_encode( $meta ),
			)
		);
	}

	/**
	 * Powiadomienie administratora o błędzie (e-mail), z ograniczeniem
	 * częstotliwości (max 1 wiadomość na 15 minut na dany dział), by nie spamować.
	 *
	 * Oficjalne API: wp_mail() https://developer.wordpress.org/reference/functions/wp_mail/
	 *
	 * @param MP_Department $department Dział.
	 * @param MP_Result     $result     Wynik.
	 * @param MP_Context    $context    Kontekst pipeline (identyfikatory do treści).
	 * @param int           $wpis_id    Wpis w dzienniku, któremu dopiszemy los alarmu.
	 * @param string        $opis       Opis zapisany przy wstawieniu tego wpisu.
	 * @param array         $meta       Metadane zapisane przy wstawieniu tego wpisu.
	 * @return void
	 */
	protected function notify_admin( MP_Department $department, MP_Result $result, MP_Context $context, $wpis_id = 0, $opis = '', array $meta = array() ) {
		$throttle_key = $this->failure_throttle_key( $department );
		if ( get_transient( $throttle_key ) ) {
			$this->oznacz_los_alarmu( $wpis_id, $opis, $meta, 'wyciszony' );

			return;
		}
		set_transient( $throttle_key, 1, 15 * MINUTE_IN_SECONDS );

		$to = get_option( 'admin_email' );

		// Ten sam powód, co przy wyjątku: brak adresu to niedostarczony alarm,
		// a nie brak alarmu.
		if ( ! $to ) {
			$this->log_alert_failure(
				sprintf(
					'Alarm o zatrzymaniu działu %d (%s) NIE został wysłany — w ustawieniach witryny nie ma adresu administratora (admin_email).',
					$department->get_number(),
					$department->get_key()
				),
				array(
					'alert'      => 'pipeline_error',
					'department' => $department->get_number(),
					'code'       => $result->get_code(),
					'reason'     => 'brak_admin_email',
				),
				$context
			);

			$this->oznacz_los_alarmu( $wpis_id, $opis, $meta, 'nieudany' );

			return;
		}

		$subject = sprintf( '[MP Lead Intake] Błąd w dziale %d (%s)', $department->get_number(), $department->get_key() );

		/*
		 * `wp_json_encode()` zwraca false przy danych, których nie da się zakodować
		 * (niepoprawny UTF-8 z zewnętrznego API, zasób). `sprintf( '%s', false )`
		 * daje pusty ciąg, więc mail kończył się linią „Błędy: " — komunikat
		 * wyglądający na kompletny, bez ani jednego szczegółu awarii.
		 */
		$errors_json = wp_json_encode( $result->get_errors() );

		if ( ! is_string( $errors_json ) ) {
			$errors_json = '[nie udało się zakodować szczegółów do JSON — zajrzyj do dziennika aktywności]';
		}

		$body = sprintf(
			"Pipeline zatrzymany w dziale %d (%s).\nKod: %s\nBłędy: %s\n%s",
			$department->get_number(),
			$department->get_key(),
			$result->get_code(),
			$errors_json,
			$this->alert_footer( $context )
		);

		$sent = wp_mail( $to, $subject, $body );

		if ( ! $sent ) {
			$this->log_alert_failure(
				sprintf(
					'Alarm o zatrzymaniu działu %d (%s) NIE został wysłany — %s',
					$department->get_number(),
					$department->get_key(),
					$this->delivery_failure_note()
				),
				array(
					'alert'      => 'pipeline_error',
					'department' => $department->get_number(),
					'code'       => $result->get_code(),
				),
				$context
			);
		}

		$this->oznacz_los_alarmu( $wpis_id, $opis, $meta, $sent ? 'wyslany' : 'nieudany' );
	}
}
