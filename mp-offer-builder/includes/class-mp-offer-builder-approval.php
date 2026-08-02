<?php
/**
 * Zatwierdzenie oferty — domknięcie kroku 4 zlecenia po stronie modułu ofertowego.
 *
 * Zlecenie opisuje krok 4 jako „oferta zatwierdzona → wysyłka do klienta".
 * Wysyłkę, follow-upy i dziennik prowadzi plugin 3 — i od początku NASŁUCHUJE
 * zdarzenia `mp_offer_approved` (patrz `MP_SW_Hooks::HOOK_OFFER_APPROVED`).
 * Nikt go jednak nie wystawiał: pipeline kończył ofertę w statusie `draft`
 * i na tym się urywało. Ten plik dokłada brakujący akt zatwierdzenia.
 *
 * DLACZEGO STATUS ŻYJE TUTAJ, A NIE W PLUGINIE 3. Decyzja architektoniczna B
 * mówi, że statusami PROCESU sprzedażowego (nowy → kontakt → oferta →
 * negocjacje → sprzedaż) włada plugin 3. To zostaje bez zmian. `draft` →
 * `approved` jest czym innym: to cykl życia DOKUMENTU, którego właścicielem
 * jest ten moduł. Rozróżnienie nie jest formalnością — od niego zależy, czy
 * wolno ofertę jeszcze zmienić:
 *
 *  - Dział 1 pipeline'u przyjmuje `offer_id` WYŁĄCZNIE w statusie draft, więc
 *    zatwierdzenie samo z siebie zamyka ofertę na edycję (bez ani jednej
 *    zmiany w pipelinie);
 *  - `MP_Offer_Builder_Lead_Listener::on_lead_verified()` odświeża snapshot VAT
 *    tylko w szkicach — dopiero teraz ma to jak zadziałać, bo wcześniej ŻADNA
 *    oferta nie wychodziła ze stanu draft.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Przejście oferty w stan zatwierdzony i zdarzenie integracyjne dla pluginu 3.
 */
class MP_Offer_Builder_Approval {

	/** Hak integracyjny: oferta zatwierdzona (konsument: plugin 3, plugin 1). */
	const HOOK = 'mp_offer_approved';

	/** Nazwa akcji `admin_post_*` obsługującej przycisk z listy ofert. */
	const ACTION = 'mp_ob_approve_offer';

	/**
	 * Prefiks transientu z wynikiem ostatniej akcji (doklejany identyfikator użytkownika).
	 *
	 * Komunikat jechał wcześniej w adresie powrotnym (`&mp_ob_approved=ok`) i
	 * `notice()` czytał wyłącznie ten parametr. Adres da się zapisać w zakładce,
	 * zostaje w historii i można go komuś podesłać — a wtedy zielone „Oferta
	 * zatwierdzona" pokazywało się przy każdym wejściu na stronę ofert, choć nic
	 * się nie wydarzyło (`=db_error` tak samo straszył awarią, której nie było).
	 * Nie mówił też, KTÓREJ oferty dotyczy.
	 *
	 * Wynik akcji jest więc stanem po stronie serwera: związanym z użytkownikiem,
	 * jednorazowym (odczyt kasuje) i krótkim — komunikat ma przeżyć jedno
	 * przekierowanie, nie dzień pracy.
	 */
	const NOTICE_TRANSIENT = 'mp_ob_notice_';

	/**
	 * Wpina obsługę akcji z panelu.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Komunikat dla stanu, z którego nie da się zatwierdzić oferty.
	 *
	 * Wracał tu jeden tekst — „Oferta jest w stanie, z którego nie da się jej
	 * zatwierdzić." — nienazywający ani stanu, ani czynności. To jedyny taki
	 * komunikat w tym pliku: pozostałe zawsze podają następny krok („najpierw ją
	 * dokończ i wygeneruj dokument", „spróbuj ponownie za chwilę, a jeśli problem
	 * wraca, zgłoś to administratorowi"). Pracownik dostawał zdanie, po którym
	 * nie wiadomo ani co jest, ani co robić — a wraca ono w DWÓCH różnych
	 * sytuacjach: przy sprawdzeniu przed zapisem i po nieudanym UPDATE.
	 *
	 * Słownik dokumentu zna dwa stany: `draft` i `approved`. Cokolwiek innego
	 * znaczy, że w kolumnie stoi wartość spoza słownika — i to jest dokładnie ta
	 * informacja, z którą trzeba iść do administratora.
	 *
	 * @param string $status Status zapisany przy ofercie.
	 * @return string
	 */
	protected static function wrong_status_message( $status ) {
		$status = trim( (string) $status );

		if ( '' === $status ) {
			return __( 'Oferta nie ma zapisanego stanu, więc nie da się jej zatwierdzić — zgłoś to administratorowi, bo wiersz w bazie jest niekompletny.', 'mp-offer-builder' );
		}

		return sprintf(
			/* translators: %s: status zapisany przy ofercie. */
			__( 'Oferta jest w stanie „%s", z którego nie da się jej zatwierdzić — zatwierdzać można wyłącznie szkice. Stan spoza słownika dokumentu zgłoś administratorowi.', 'mp-offer-builder' ),
			$status
		);
	}

	/**
	 * Zatwierdza ofertę i wystawia `mp_offer_approved` dokładnie raz.
	 *
	 * Warstwa DZIEDZINOWA — bez nonce'a i bez uprawnień roli (to sprawa granicy
	 * HTTP, patrz handle()). Sprawdza natomiast własność oferty, bo ta wynika z
	 * danych, a nie z żądania, i musi obowiązywać każdego wywołującego.
	 *
	 * @param int $offer_id Identyfikator oferty w BD-2.
	 * @param int $user_id  Kto zatwierdza (0 = system).
	 * @return true|WP_Error
	 */
	public static function approve( $offer_id, $user_id = 0 ) {
		global $wpdb;

		$offer_id = (int) $offer_id;
		$user_id  = (int) $user_id;
		$offer    = $offer_id > 0 ? MP_Offer_Builder_DB::get_offer( $offer_id ) : null;

		/*
		 * Nieistniejąca i cudza oferta dają TEN SAM błąd i ten sam komunikat —
		 * inaczej `offer_id` stałby się wyrocznią do wyliczania cudzych ofert
		 * (ten sam wzorzec co Dział 1 pipeline'u i endpoint pobierania PDF).
		 */
		if ( ! $offer || ( $user_id > 0 && ! MP_Offer_Builder_Download::can_download( $offer, $user_id ) ) ) {
			return new WP_Error(
				'offer_not_found',
				__( 'Oferta nie istnieje albo nie masz do niej dostępu.', 'mp-offer-builder' )
			);
		}

		if ( MP_Offer_Builder_DB::STATUS_APPROVED === (string) $offer['status'] ) {
			// Podwójne kliknięcie albo odświeżenie strony. Stan docelowy jest
			// osiągnięty, ale zdarzenie już poszło — drugi raz wystawić go nie wolno.
			return new WP_Error(
				'already_approved',
				__( 'Ta oferta była już zatwierdzona.', 'mp-offer-builder' )
			);
		}

		if ( MP_Offer_Builder_DB::STATUS_DRAFT !== (string) $offer['status'] ) {
			return new WP_Error(
				'wrong_status',
				self::wrong_status_message( (string) $offer['status'] )
			);
		}

		/*
		 * Nie ma czego zatwierdzać, dopóki nie ma dokumentu. Numer i PDF powstają
		 * dopiero w pełnym przebiegu pipeline'u (Działy 8/9) — szkic z samego
		 * zdarzenia `mp_lead_created` ma oba puste. Bez tej kontroli plugin 3
		 * dostałby polecenie „wyślij ofertę klientowi", nie mając pliku do wysłania;
		 * po tamtej stronie kończy się to odmową MP3-E190, czyli błędem zgłoszonym
		 * o jeden moduł za późno.
		 */
		if ( '' === (string) $offer['offer_number'] || '' === (string) $offer['pdf_path'] ) {
			return new WP_Error(
				'no_document',
				__( 'Oferta nie ma jeszcze numeru i pliku PDF — najpierw ją dokończ i wygeneruj dokument.', 'mp-offer-builder' )
			);
		}

		/*
		 * NIEPUSTA KOLUMNA TO JESZCZE NIE DOKUMENT.
		 *
		 * `pdf_path` i dysk potrafią się rozjechać. Anonimizacja RODO kasowała plik
		 * i zostawiała ścieżkę w bazie; kopia bazy z produkcji przynosi ścieżki do
		 * plików, których na tym serwerze nigdy nie było. Bez sprawdzenia pliku
		 * szkic po anonimizacji dawało się ZATWIERDZIĆ: zdarzenie szło do pluginu 3,
		 * ten dostawał polecenie „wyślij ofertę klientowi" bez dokumentu i odbijał
		 * je kodem MP3-E190 — błąd wychodził o dwa moduły za późno, a oferta nie
		 * była już szkicem i znikała z edycji.
		 */
		if ( ! MP_Offer_Builder_Storage::document_exists( (string) $offer['pdf_path'] ) ) {
			return new WP_Error(
				'no_document',
				__( 'Plik PDF tej oferty nie istnieje — wygeneruj dokument ponownie przed zatwierdzeniem.', 'mp-offer-builder' )
			);
		}

		/*
		 * Przejście warunkowe: `WHERE status = 'draft'` w samym UPDATE. Kontrola
		 * wyżej jest tylko po to, żeby dać sensowny komunikat — o tym, KTO wygrał,
		 * decyduje baza. Przy dwóch równoległych żądaniach dokładnie jedno dostanie
		 * 1 zmieniony wiersz, więc zdarzenie wyjdzie dokładnie raz (ta sama zasada,
		 * co wartownik statusu wewnątrz transakcji zapisu w pluginie 3).
		 *
		 * `lock_version` ROŚNIE TAKŻE TUTAJ. Bez tego zatwierdzenie było dla
		 * blokady optymistycznej niewidzialne: Dział 2 czytał `lock_version = N`
		 * i `status = draft`, pipeline liczył i renderował PDF (setki milisekund),
		 * w tym czasie ktoś klikał „Zatwierdź", a Dział 10 zapisywał WHERE
		 * `lock_version = N` — trafiał, cofał status do `draft` i podmieniał plik
		 * JUŻ WYSŁANY klientowi. Oferta wracała na listę jako szkic z aktywnym
		 * przyciskiem, więc drugie kliknięcie wystawiało `mp_offer_approved`
		 * po raz drugi dla tego samego `offer_id` — wprost wbrew gwarancji
		 * „dokładnie raz", której plugin 3 pilnuje po swojej stronie.
		 */
		$changed = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MP_Offer_Builder_DB::offers_table(),
			array(
				'status'       => MP_Offer_Builder_DB::STATUS_APPROVED,
				'lock_version' => (int) $offer['lock_version'] + 1,

				/*
				 * UTC, tak samo jak zapis Działu 10 (`gmdate`). Wcześniej to jedno
				 * miejsce zapisywało czas WITRYNY, więc kolumna `updated_at` niosła
				 * dwie różne strefy naraz — a lista ofert domyślnie sortuje właśnie
				 * po niej. W strefie innej niż UTC oferta zatwierdzona później
				 * potrafiła wylądować na liście przed ofertą zapisaną wcześniej.
				 */
				'updated_at'   => current_time( 'mysql', true ),
			),
			array(
				'id'     => $offer_id,
				'status' => MP_Offer_Builder_DB::STATUS_DRAFT,
			)
		);

		/*
		 * `$wpdb->update()` zwraca DWIE różne rzeczy pod jednym warunkiem
		 * `1 !== $changed`: `false` przy błędzie zapytania (zerwane połączenie,
		 * zablokowana tabela, błąd SQL) i `0`, gdy żaden wiersz nie pasował
		 * — czyli gdy ktoś zatwierdził tę ofertę pierwszy.
		 *
		 * Wrzucanie obu do kodu `already_approved` kończyło się tym, że przy
		 * AWARII ZAPISU pracownik widział niebieską informację „nic się nie
		 * zmieniło", uznawał sprawę za załatwioną i nie ponawiał akcji.
		 * Tymczasem oferta zostawała szkicem, `mp_offer_approved` nigdy nie
		 * wychodziło i plugin 3 nie zaczynał wysyłki. Komunikat mówił
		 * dokładnie odwrotnie niż stan bazy.
		 */
		if ( false === $changed ) {
			return new WP_Error(
				'db_error',
				__( 'Nie udało się zapisać zatwierdzenia — spróbuj ponownie za chwilę, a jeśli problem wraca, zgłoś to administratorowi.', 'mp-offer-builder' )
			);
		}

		if ( 1 !== (int) $changed ) {
			/*
			 * ZERO ZMIENIONYCH WIERSZY NIE ZNACZY „JUŻ ZATWIERDZONA".
			 *
			 * `WHERE status = 'draft'` nie trafia z każdego powodu, nie z jednego:
			 * ktoś zatwierdził ofertę pierwszy, ale też — Dział 10 zapisał w tym
			 * czasie inny status, albo wiersz zniknął. Bezwarunkowe
			 * `already_approved` (poziom „info": „nic się nie zmieniło") mówiło
			 * wtedy pracownikowi, że sprawa jest załatwiona, choć oferta NIE
			 * przeszła w `approved`, zdarzenie nie wyszło i nikt jej nie wyśle.
			 *
			 * Pytamy więc bazę o STAN FAKTYCZNY. Odczyt jest po nieudanym zapisie,
			 * czyli na ścieżce wyjątkowej — nie obciąża normalnego przebiegu.
			 */
			$aktualna = MP_Offer_Builder_DB::get_offer( $offer_id );

			if ( ! $aktualna ) {
				/*
				 * Tu NIE chodzi o uprawnienia. Dostęp do tej samej oferty został
				 * potwierdzony kilkanaście linii wyżej, w tym samym wywołaniu —
				 * komunikat „albo nie masz do niej dostępu" wysyłał więc pracownika
				 * na poszukiwanie uprawnień, których mu nie brakuje. Wiersz zniknął
				 * między odczytem a zapisem i to jest cała treść zdarzenia.
				 */
				return new WP_Error(
					'offer_not_found',
					__( 'Oferta zniknęła w trakcie zatwierdzania — zapis nie doszedł do skutku. Odśwież listę ofert; jeśli oferta wróci, spróbuj ponownie.', 'mp-offer-builder' )
				);
			}

			if ( MP_Offer_Builder_DB::STATUS_APPROVED !== (string) $aktualna['status'] ) {
				return new WP_Error(
					'wrong_status',
					self::wrong_status_message( (string) $aktualna['status'] )
				);
			}

			return new WP_Error(
				'already_approved',
				__( 'Ta oferta była już zatwierdzona.', 'mp-offer-builder' )
			);
		}

		/*
		 * PAYLOAD Z ODCZYTU PO ZAPISIE, NIE SPRZED NIEGO.
		 *
		 * `$offer` pochodzi z odczytu sprzed UPDATE-a. Warunek `WHERE status =
		 * 'draft'` pilnuje statusu, ale NIE pilnuje reszty wiersza: równoległa
		 * korekta z Działu 10 mogła w tym czasie zmienić kwotę, numer oferty,
		 * wersję albo nazwę klienta i zostawić status na `draft`. Zatwierdzenie
		 * kończyło się wtedy powodzeniem, a do wtyczki 3 i wtyczki 1 szły
		 * wartości NIEAKTUALNE — proces sprzedażowy wysyłał klientowi ofertę
		 * opisaną liczbami, których nie ma już w bazie.
		 *
		 * Ponowny odczyt jest jednym zapytaniem na ścieżce, która i tak właśnie
		 * zapisała wiersz. Gdyby wiersz zdążył zniknąć, zostajemy przy tym, co
		 * mamy — zdarzenie ze starymi danymi jest lepsze niż brak zdarzenia po
		 * udanym zatwierdzeniu.
		 */
		$zapisana = MP_Offer_Builder_DB::get_offer( $offer_id );

		/*
		 * Ponowny odczyt przyjmujemy tylko wtedy, gdy nadal spełnia to, co przed
		 * zapisem sprawdziliśmy. Kontrola „numer nie jest pusty" wykonała się na
		 * wierszu SPRZED UPDATE-a; między jednym a drugim mogła wejść korekta
		 * z Działu 10 i zostawić numer pusty. Wpis do dziennika i zdarzenie
		 * ruszały wtedy z pustym numerem oferty, choć warunek na to nie pozwalał —
		 * tyle że sprawdzony był na innej wersji wiersza.
		 */
		if ( is_array( $zapisana ) && '' !== (string) $zapisana['offer_number'] ) {
			$offer = $zapisana;
		}

		$lead_id = isset( $offer['lead_id'] ) ? (int) $offer['lead_id'] : 0;

		// Dziennik BD-2 przed zdarzeniem: subskrybent może paść, a ślad po
		// zatwierdzeniu ma zostać w każdym przypadku (kryt. 5.5 — historia
		// odtwarzalna z samego dziennika).
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MP_Offer_Builder_DB::activity_log_table(),
			array(
				'offer_id'    => $offer_id,
				'action'      => 'offer_approved',
				'description' => sprintf(
					/* translators: 1: numer oferty, 2: identyfikator leada. */
					__( 'Oferta %1$s zatwierdzona (lead_id=%2$d) — zdarzenie mp_offer_approved zaraz zostanie wystawione.', 'mp-offer-builder' ),
					(string) $offer['offer_number'],
					$lead_id
				),
				'user_id'     => $user_id > 0 ? $user_id : null,
				'meta_json'   => wp_json_encode(
					array(
						'lead_id'      => $lead_id,
						'offer_number' => (string) $offer['offer_number'],
						'version'      => (int) $offer['version'],
					)
				),
			)
		);

		/*
		 * Kontrakt zdarzenia jest lustrem `mp_offer_created` (Dział 11) — ten sam
		 * zestaw kluczy, inny status. Odbiorcy: plugin 3 (proces sprzedażowy, czyta
		 * `lead_id`) i plugin 1 (wskaźnik oferty w BD-3, czyta numer, status, kwotę
		 * i walutę). Zero w `lead_id` oznacza ofertę zbudowaną ręcznie, bez leada.
		 */
		do_action(
			self::HOOK,
			$offer_id,
			array(
				'offer_id'     => $offer_id,
				'offer_number' => (string) $offer['offer_number'],
				'version'      => (int) $offer['version'],
				'status'       => MP_Offer_Builder_DB::STATUS_APPROVED,
				'client_name'  => (string) $offer['client_name'],
				'gross_grosze' => (int) $offer['gross_grosze'],
				'currency'     => (string) $offer['currency'],
				'lead_id'      => $lead_id,
				'approved_by'  => $user_id,
			)
		);

		return true;
	}

	/**
	 * Granica HTTP: token CSRF → uprawnienie → operacja → powrót na listę.
	 *
	 * @return void
	 */
	public static function handle() {
		$offer_id = isset( $_REQUEST['offer_id'] ) ? absint( $_REQUEST['offer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::ACTION . '_' . $offer_id );

		if ( ! current_user_can( MP_OB_D1_Agent_Permission::CAPABILITY ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'mp-offer-builder' ), '', array( 'response' => 403 ) );
		}

		$user_id = get_current_user_id();
		$result  = self::approve( $offer_id, $user_id );
		$code    = is_wp_error( $result ) ? $result->get_error_code() : 'ok';

		/*
		 * Numer bierzemy PO operacji i wprost z bazy — komunikat ma mówić o tym,
		 * co w niej stoi, a nie o tym, co wysłała przeglądarka. Przy `offer_not_found`
		 * numeru z natury nie ma; komunikaty radzą sobie z pustym.
		 */
		$offer  = $offer_id > 0 ? MP_Offer_Builder_DB::get_offer( $offer_id ) : null;
		$number = ( is_array( $offer ) && isset( $offer['offer_number'] ) ) ? (string) $offer['offer_number'] : '';

		self::remember_notice( $code, $number, $user_id );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => MP_Offer_Builder_Admin::PAGE_SLUG ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Zapamiętuje wynik akcji dla użytkownika, który ją wykonał.
	 *
	 * @param string $code     Kod wyniku (klucz słownika komunikatów).
	 * @param string $number   Numer oferty (może być pusty).
	 * @param int    $user_id  Kto ma zobaczyć komunikat.
	 * @return void
	 */
	public static function remember_notice( $code, $number, $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		set_transient(
			self::NOTICE_TRANSIENT . $user_id,
			array(
				'code'   => (string) $code,
				'number' => (string) $number,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Kasuje zapamiętany komunikat (odczyt przez notice() robi to samo).
	 *
	 * @param int $user_id Właściciel komunikatu.
	 * @return void
	 */
	public static function forget_notice( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id > 0 ) {
			delete_transient( self::NOTICE_TRANSIENT . $user_id );
		}
	}

	/**
	 * Komunikat po powrocie z akcji zatwierdzania.
	 *
	 * @return void
	 */
	public static function notice() {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		$stored = get_transient( self::NOTICE_TRANSIENT . $user_id );

		if ( ! is_array( $stored ) || empty( $stored['code'] ) ) {
			return;
		}

		// Jednorazowo: odświeżenie strony ma NIE powtarzać komunikatu o operacji,
		// która wydarzyła się raz.
		self::forget_notice( $user_id );

		$code   = sanitize_key( $stored['code'] );
		$number = isset( $stored['number'] ) ? (string) $stored['number'] : '';
		$label  = '' !== $number
			? sprintf(
				/* translators: %s: numer oferty. */
				__( 'Oferta %s', 'mp-offer-builder' ),
				$number
			)
			: __( 'Oferta', 'mp-offer-builder' );

		$messages = array(

			/*
			 * Komunikat sukcesu MÓWI TO, CO WIADOMO.
			 *
			 * „Moduł sprzedażowy przejmuje wysyłkę do klienta" było twierdzeniem
			 * o cudzej wtyczce, którego kod nie sprawdzał. Wtyczka 2 potrafi
			 * zbudować ofertę sama (ze zdarzenia `mp_lead_created`), więc wtyczka 3
			 * bywa wyłączona albo w ogóle niezainstalowana. `do_action()` trafiał
			 * wtedy w pustkę, pracownik czytał zielony komunikat i uznawał sprawę
			 * za zamkniętą, a oferta nigdy nie wychodziła do klienta — i nie było
			 * już `draft`, więc znikała też z edycji.
			 */
			'ok'               => array(
				'success',
				has_action( self::HOOK )
					? sprintf(
						/* translators: %s: „Oferta OF/2026/000123" albo samo „Oferta". */
						__( '%s zatwierdzona. Moduł sprzedażowy przejmuje wysyłkę do klienta.', 'mp-offer-builder' ),
						$label
					)
					: sprintf(
						/* translators: %s: „Oferta OF/2026/000123" albo samo „Oferta". */
						__( '%s zatwierdzona, ale ŻADEN moduł nie nasłuchuje wysyłki — wyślij ją klientowi ręcznie albo włącz wtyczkę MP Sales Workflow.', 'mp-offer-builder' ),
						$label
					),
			),
			'already_approved' => array(
				'info',
				sprintf(
					/* translators: %s: „Oferta OF/2026/000123" albo samo „Oferta". */
					__( '%s była już zatwierdzona — nic się nie zmieniło.', 'mp-offer-builder' ),
					$label
				),
			),
			// Poziom „error" jest tu istotą sprawy: zapis się NIE udał, więc
			// komunikat nie może wyglądać jak potwierdzenie, że wszystko gra.
			'db_error'         => array(
				'error',
				sprintf(
					/* translators: %s: „Oferta OF/2026/000123" albo samo „Oferta". */
					__( '%s: nie udało się zapisać zatwierdzenia — spróbuj ponownie za chwilę, a jeśli problem wraca, zgłoś to administratorowi.', 'mp-offer-builder' ),
					$label
				),
			),
			'no_document'      => array(
				'error',
				sprintf(
					/* translators: %s: „Oferta OF/2026/000123" albo samo „Oferta". */
					__( '%s nie ma jeszcze numeru i pliku PDF — najpierw ją dokończ i wygeneruj dokument.', 'mp-offer-builder' ),
					$label
				),
			),
			// Bez numeru z rozmysłem: nieistniejąca i cudza oferta mają dawać ten
			// sam komunikat, więc nie wolno go różnicować numerem (patrz approve()).
			'offer_not_found'  => array( 'error', __( 'Oferta nie istnieje albo nie masz do niej dostępu.', 'mp-offer-builder' ) ),
			'wrong_status'     => array(
				'error',
				sprintf(
					/* translators: %s: „Oferta OF/2026/000123" albo samo „Oferta". */
					__( '%s jest w stanie, z którego nie da się jej zatwierdzić.', 'mp-offer-builder' ),
					$label
				),
			),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Adres akcji zatwierdzania dla danej oferty (token CSRF per-oferta).
	 *
	 * Odnośnik, a nie formularz POST, bo lista ofert jest już opakowana w
	 * `<form method="get">` (wyszukiwarka WP_List_Table) — formularz w formularzu
	 * to nieprawidłowy HTML i przeglądarka po prostu zignorowałaby wewnętrzny.
	 * Tym samym wzorcem działają akcje wierszy w rdzeniu WordPressa; przed
	 * fałszywym żądaniem broni nonce sprawdzany w handle().
	 *
	 * @param int $offer_id Identyfikator oferty.
	 * @return string
	 */
	public static function action_url( $offer_id ) {
		$offer_id = (int) $offer_id;

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'offer_id' => $offer_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $offer_id
		);
	}
}
