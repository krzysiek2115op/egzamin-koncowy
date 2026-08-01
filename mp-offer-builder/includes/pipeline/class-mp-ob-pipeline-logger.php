<?php
/**
 * Logger błędów pipeline.
 *
 * Zgodnie z zasadą: gdy Krytyk/Bramka wykryje błąd — STOP + log błędu do BD-2
 * (wp_mp_ob_offer_activity_log) + powiadomienie administratora.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zapisuje błędy pipeline do logu aktywności i powiadamia administratora.
 */
class MP_OB_Pipeline_Logger {

	/**
	 * Nazwa miejsca awarii dla CZŁOWIEKA.
	 *
	 * Docblock mówił „0 = nieznany", ale ta sama wartość szła wprost do `%d`
	 * w temacie maila, w treści maila i w opisie wpisu w dzienniku. Powstawał
	 * „dział 0" — numer, którego w pipeline nie ma i którego czytelnik nie ma
	 * jak odróżnić od prawdziwego. Administrator szukał działu numer 0 zamiast
	 * dowiedzieć się, że miejsce awarii jest NIEUSTALONE.
	 *
	 * @param int $dept_num Numer działu (0 = nieustalony).
	 * @return string
	 */
	protected function department_label( $dept_num ) {
		$dept_num = (int) $dept_num;

		return $dept_num > 0
			? sprintf( 'dziale %d', $dept_num )
			: __( 'nieustalonym miejscu pipeline\'u', 'mp-offer-builder' );
	}

	/**
	 * Dlaczego alarm nie doszedł — bez zmyślania przyczyny.
	 *
	 * Komunikat twierdził „serwer poczty odrzucił wiadomość", choć kod niczego
	 * takiego nie sprawdził: `wp_mail()` zwraca false także wtedy, gdy do serwera
	 * poczty w ogóle nie doszło (filtr `pre_wp_mail` innej wtyczki, niepoprawny
	 * adres w `admin_email`, wyjątek PHPMailera). Pracownik czytał o awarii SMTP
	 * i sprawdzał konfigurację, która działa poprawnie.
	 *
	 * @return string
	 */
	protected function delivery_failure_note() {
		return __( 'wp_mail() zgłosiło niepowodzenie; przyczyny kod nie zna — sprawdź konfigurację poczty, adres w ustawieniach witryny i wtyczki podpięte pod wysyłkę.', 'mp-offer-builder' );
	}

	/**
	 * Stopka alarmu: identyfikatory zdarzenia i informacja o wyciszeniu.
	 *
	 * @param MP_OB_Context $context Kontekst pipeline.
	 * @return string
	 */
	protected function alert_footer( MP_OB_Context $context ) {
		$offer_id   = (int) $context->get( 'offer_id' );
		$request_id = (string) $context->get( 'request_id', '' );

		return sprintf(
			"\n%s\n%s\n%s\n",
			$offer_id > 0
				? sprintf( 'Oferta (offer_id): %d', $offer_id )
				: 'Oferta (offer_id): jeszcze nie powstała — awaria wystąpiła przed zapisem.',
			'' !== $request_id
				? sprintf( 'Identyfikator żądania: %s', $request_id )
				: 'Identyfikator żądania: brak w kontekście.',
			'UWAGA: przez najbliższe 15 minut kolejne alarmy z tego samego miejsca są wyciszone. Ta wiadomość może więc dotyczyć WIĘKSZEJ liczby ofert — sprawdź dziennik aktywności, zanim uznasz sprawę za zamkniętą.'
		);
	}

	/**
	 * Loguje porażkę działu do BD-2 i powiadamia administratora.
	 *
	 * @param MP_OB_Department $department Dział, w którym wystąpił błąd.
	 * @param MP_OB_Result     $result     Wynik z błędami.
	 * @param MP_OB_Context    $context    Kontekst pipeline.
	 * @return void
	 */
	public function log_failure( MP_OB_Department $department, MP_OB_Result $result, MP_OB_Context $context ) {
		global $wpdb;

		$table = MP_Offer_Builder_DB::activity_log_table();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'offer_id'    => $context->get( 'offer_id' ),
				'action'      => 'pipeline_error',
				'description' => sprintf(
					'Błąd w dziale %d (%s), kod: %s',
					$department->get_number(),
					$department->get_key(),
					$result->get_code()
				),
				'user_id'     => get_current_user_id() ? get_current_user_id() : null,
				'meta_json'   => wp_json_encode(
					array(
						'request_id' => $context->get( 'request_id' ),
						'department' => $department->get_number(),
						'code'       => $result->get_code(),
						'errors'     => $result->get_errors(),
						'data'       => $result->get_data(),
					)
				),
			)
		);

		$this->notify_admin( $department, $result, $context );
	}

	/**
	 * Loguje NIEOCZEKIWANY wyjątek/błąd PHP w trakcie pipeline'u i powiadamia
	 * administratora. W przeciwieństwie do log_failure() (kontrolowany STOP
	 * krytyka/bramki, opisany przez MP_OB_Result) — to ścieżka awaryjna:
	 * Throwable przerwał wykonanie w sposób, którego MP_OB_Result nie mógł opisać.
	 *
	 * @param \Throwable    $e        Przechwycony wyjątek/błąd.
	 * @param MP_OB_Context $context  Kontekst pipeline w chwili awarii.
	 * @param int           $dept_num Numer działu, w którym doszło do awarii (0 = nieznany).
	 * @return void
	 */
	public function log_exception( \Throwable $e, MP_OB_Context $context, $dept_num ) {
		global $wpdb;

		$table = MP_Offer_Builder_DB::activity_log_table();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'offer_id'    => $context->get( 'offer_id' ),
				'action'      => 'pipeline_exception',
				'description' => sprintf( 'Nieoczekiwany wyjątek w %s: %s', $this->department_label( $dept_num ), $e->getMessage() ),
				'user_id'     => get_current_user_id() ? get_current_user_id() : null,
				'meta_json'   => wp_json_encode(
					array(
						'request_id' => $context->get( 'request_id' ),
						'department' => (int) $dept_num,
						'exception'  => get_class( $e ),
						'message'    => $e->getMessage(),
					)
				),
			)
		);

		/*
		 * Ogranicznik OSOBNY DLA KAŻDEGO DZIAŁU — bo dokładnie to obiecuje stopka
		 * alarmu: „kolejne alarmy z tego samego miejsca są wyciszone".
		 *
		 * Jeden wspólny klucz na całą wtyczkę sprawiał, że wyjątek w Dziale 4
		 * uciszał na kwadrans wyjątki z Działu 10. Druga, zupełnie niezależna
		 * awaria nie dawała znaku życia, a administrator czytał w stopce, że
		 * wyciszone jest tylko to jedno miejsce — czyli ten sam błąd, przed
		 * którym ta stopka miała bronić: tekst dla człowieka twierdził więcej,
		 * niż kod robił.
		 *
		 * Tempo nadal jest ograniczone: powtórka z tego samego działu milczy, a
		 * górną granicą jest jedna wiadomość na dział na kwadrans. Ścieżka błędu
		 * działu (`notify_admin()`) liczy się tak samo od zawsze.
		 */
		$throttle_key = 'mp_ob_notify_exception_' . (int) $dept_num;
		if ( get_transient( $throttle_key ) ) {
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
		 * przyjmował, że administrator został powiadomiony.
		 */
		if ( ! $to ) {
			$this->log_alert_failure(
				sprintf(
					'Alarm o wyjątku w %s NIE został wysłany — w ustawieniach witryny nie ma adresu administratora (admin_email).',
					$this->department_label( $dept_num )
				),
				array(
					'alert'      => 'pipeline_exception',
					'department' => (int) $dept_num,
					'reason'     => 'brak_admin_email',
				),
				$context
			);

			return;
		}

		$sent = wp_mail(
			$to,
			sprintf( '[MP Offer Builder] Nieoczekiwany wyjątek w pipeline (%s)', $this->department_label( $dept_num ) ),
			sprintf(
				"Pipeline przerwany wyjątkiem w %s.\nTyp: %s\nKomunikat: %s\n%s",
				$this->department_label( $dept_num ),
				get_class( $e ),
				$e->getMessage(),
				$this->alert_footer( $context )
			)
		);

		if ( ! $sent ) {
			$this->log_alert_failure(
				sprintf(
					'Alarm o wyjątku w %s NIE został wysłany — %s',
					$this->department_label( $dept_num ),
					$this->delivery_failure_note()
				),
				array(
					'alert'      => 'pipeline_exception',
					'department' => (int) $dept_num,
				),
				$context
			);
		}
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
	 * którym da się dojść, KTÓREJ oferty sprawa dotyczyła. Bez tego wpis
	 * `admin_alert_failed` był jedynym zdarzeniem w historii, którego nie dało
	 * się przypisać do dokumentu — a jest to dokładnie ten moment, w którym coś
	 * poszło nie tak.
	 *
	 * `offer_id` zostaje pusty, gdy oferty jeszcze nie ma (awaria przed zapisem).
	 * Wpisanie tam zera albo liczby zastępczej byłoby gorsze niż brak: pusto
	 * znaczy „nie powstała", a `request_id` w `meta_json` i tak wskazuje żądanie.
	 *
	 * @param string        $description Co dokładnie nie doszło.
	 * @param array         $meta        Dodatkowe fakty do `meta_json`.
	 * @param MP_OB_Context $context     Kontekst pipeline (identyfikatory); null, gdy niedostępny.
	 * @return void
	 */
	protected function log_alert_failure( $description, array $meta = array(), MP_OB_Context $context = null ) {
		global $wpdb;

		$offer_id = null;

		if ( $context instanceof MP_OB_Context ) {
			$offer_id = (int) $context->get( 'offer_id' ) > 0 ? (int) $context->get( 'offer_id' ) : null;

			$request_id = (string) $context->get( 'request_id', '' );
			if ( '' !== $request_id && ! isset( $meta['request_id'] ) ) {
				$meta['request_id'] = $request_id;
			}
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MP_Offer_Builder_DB::activity_log_table(),
			array(
				'offer_id'    => $offer_id,
				'action'      => 'admin_alert_failed',
				'description' => (string) $description,
				// Bez adresu administratora: dziennik ma mowic, CO nie doszlo,
				// a nie do kogo mialo pojsc.
				'user_id'     => null,
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
	 * @param MP_OB_Department $department Dział.
	 * @param MP_OB_Result     $result     Wynik.
	 * @param MP_OB_Context    $context    Kontekst pipeline (identyfikatory do treści).
	 * @return void
	 */
	protected function notify_admin( MP_OB_Department $department, MP_OB_Result $result, MP_OB_Context $context ) {
		$throttle_key = 'mp_ob_notify_' . $department->get_key();
		if ( get_transient( $throttle_key ) ) {
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

			return;
		}

		$subject = sprintf( '[MP Offer Builder] Błąd w dziale %d (%s)', $department->get_number(), $department->get_key() );

		/*
		 * `wp_json_encode()` zwraca false przy danych, których nie da się
		 * zakodować (niepoprawny UTF-8 z zewnętrznego API, zasób).
		 * `sprintf( '%s', false )` daje pusty ciąg, więc mail kończył się linią
		 * „Błędy: " — komunikat wyglądający na kompletny, bez ani jednego
		 * szczegółu awarii.
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
	}
}
