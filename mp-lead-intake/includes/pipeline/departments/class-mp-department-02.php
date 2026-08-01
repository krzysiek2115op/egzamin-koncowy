<?php
/**
 * Dział 2 — Walidacja formularza (wstępna).
 *
 * Sprawdza strukturę zgłoszenia: obecność pól wymaganych, normalizację danych
 * oficjalnymi funkcjami WordPressa oraz poprawność formatów (e-mail, wstępny NIP).
 * Pełna weryfikacja NIP/VAT (suma kontrolna, VIES) należy do działu 3.
 *
 * Źródła (oficjalne) — Golden Rule #2. Dokumentacja, którą "czytają" agenci/krytycy:
 *  - docs/dzial-02/wordpress-is_email.md
 *  - docs/dzial-02/wordpress-sanitize_text_field.md
 *  - docs/dzial-02/wordpress-sanitize_email.md
 * ZADANIE każdego agenta/krytyka jest przypisane do niego (label/opis + run()),
 * nie w dokumentacji.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent 2.1 — sprawdza obecność pól wymaganych.
 */
class MP_D2_Agent_Required_Fields extends MP_Abstract_Agent {

	/** Pola wymagane (zgodne z NOT NULL w wp_mp_leads). */
	const REQUIRED = array( 'company_name', 'nip', 'email' );

	public function __construct() {
		parent::__construct( '2.1', 'Sprawdza wymagane pola', 'Weryfikacja obecności pól obowiązkowych: company_name, nip, email' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$missing = array();
		foreach ( self::REQUIRED as $field ) {
			if ( '' === trim( (string) $context->get( $field, '' ) ) ) {
				$missing[] = $field;
			}
		}

		return MP_Result::ok(
			array(
				'required_ok'    => empty( $missing ),
				'missing_fields' => $missing,
			)
		);
	}
}

/**
 * Agent 2.2 — normalizuje dane oficjalnymi funkcjami WordPressa.
 */
class MP_D2_Agent_Normalize extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '2.2', 'Normalizuje dane', 'sanitize_text_field / sanitize_email + numer VAT do postaci kanonicznej dla kraju' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		return MP_Result::ok(
			array(
				'company_name' => sanitize_text_field( (string) $context->get( 'company_name', '' ) ),
				'email'        => sanitize_email( (string) $context->get( 'email', '' ) ),
				// Kanonizacja zależy od kraju: w Polsce giną wszystkie znaki poza cyframi,
				// poza nią litery bywają częścią numeru — patrz MP_Vat_Number::normalize().
				'nip'          => MP_Vat_Number::normalize( $context->get( 'nip', '' ), $context->get( 'country', '' ) ),
				'phone'        => sanitize_text_field( (string) $context->get( 'phone', '' ) ),
				'normalized'   => true,
			)
		);
	}
}

/**
 * Agent 2.3 — waliduje formaty (e-mail przez is_email, wstępny format NIP).
 */
class MP_D2_Agent_Validate_Formats extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( '2.3', 'Waliduje formaty', 'Poprawność e-maila (is_email) i wstępny format numeru VAT (PL: 10 cyfr, UE: sanity-check)' );
	}

	/**
	 * Długość w znakach z bezpiecznym fallbackiem, gdy brak rozszerzenia mbstring
	 * (WordPress go zaleca, ale nie gwarantuje — bez guardu byłby fatal).
	 *
	 * @param string $s Tekst.
	 * @return int
	 */
	private static function str_len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s ) : strlen( (string) $s );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$errors = array();

		$email = (string) $context->get( 'email', '' );
		if ( '' === $email || ! is_email( $email ) ) {
			$errors['email'] = 'Niepoprawny adres e-mail';
		} elseif ( self::str_len( $email ) > 190 ) {
			$errors['email'] = 'Adres e-mail jest za długi (maks. 190 znaków)';
		}

		// NIP jest wymagany. Po normalizacji (2.2) puste pole oznacza wejście bez
		// cyfr (np. same myślniki) — nie wolno go przepuścić do działu 3.
		$nip     = (string) $context->get( 'nip', '' );
		$country = (string) $context->get( 'country', '' );
		if ( '' === $nip ) {
			$errors['nip'] = 'NIP jest wymagany';
		} elseif ( ! MP_Vat_Number::format_valid( $nip, $country ) ) {
			/*
			 * Kontrola formatu jest teraz DWUTOROWA, bo formularz od zawsze pozwalał
			 * wybrać kraj UE, a cała dalsza droga leadu była na to gotowa: agent 3.2
			 * pyta VIES pod adresem /ms/{KRAJ}/vat/{NUMER}, klucz unikalności w BD-3
			 * to (country, nip), a Dział 4 w P3 przydziela handlowca po kraju.
			 * Zamknięte było wyłącznie wejście.
			 *
			 * Polska: dokładnie stary warunek 10 cyfr, z dokładnie starym zdaniem.
			 * Reszta UE: sanity-check w MP_Vat_Number — długość, dozwolone znaki,
			 * odrzucenie wartości zastępczych. O ważności numeru orzeka VIES, nie my.
			 *
			 * Kod kraju wchodzi do treści tylko wtedy, gdy ma kształt ISO 3166-1.
			 * Sprawdza to Agent 2.4, ale biegnie niezależnie od tego agenta, więc
			 * nie zakładamy jego wyniku. Przy czymkolwiek innym zostaje zdanie bez
			 * kodu — komunikat nie ma być kanałem na cudzy tekst.
			 */
			if ( MP_Vat_Number::is_polish( $country ) ) {
				$errors['nip'] = 'NIP powinien mieć 10 cyfr';
			} else {
				$kod           = MP_Vat_Number::country( $country );
				$errors['nip'] = sprintf(
					'Numer VAT z kraju %s nie wygląda na poprawny — przepisz go z faktury (bez prefiksu kraju).',
					$kod
				);
			}
		}

		// Limity długości zgodne z kolumnami BD-3 (unikamy „Data too long"/obcięcia w dziale 7).
		$company = (string) $context->get( 'company_name', '' );
		if ( self::str_len( $company ) > 255 ) {
			$errors['company_name'] = 'Nazwa firmy jest za długa (maks. 255 znaków)';
		}

		// Telefon jest opcjonalny — walidujemy tylko, gdy podany. Format: cyfry,
		// spacje, +, -, nawiasy (dopuszcza zapis międzynarodowy, np. "+48 12 345-67-89").
		$phone = (string) $context->get( 'phone', '' );
		if ( '' !== $phone ) {
			if ( self::str_len( $phone ) > 30 ) {
				$errors['phone'] = 'Numer telefonu jest za długi (maks. 30 znaków)';
			} elseif ( ! preg_match( '/^[0-9+()\-\s]{6,}$/u', $phone ) ) {
				$errors['phone'] = 'Numer telefonu ma nieprawidłowy format (dozwolone: cyfry, spacje, +, -, nawiasy)';
			}
		}

		/*
		 * Pozostałe pola tekstowe formularza — obie kolumny to `varchar(100)`.
		 * Wcześniej limit obowiązywał tylko dla `company_name` i `phone`, mimo że
		 * komentarz wyżej uzasadnia go zgodnością z kolumnami BD-3. Skutek nie był
		 * kosmetyczny: `wpdb::process_fields()` przycina wartość do długości
		 * kolumny i po wykryciu różnicy ZWRACA FALSE zamiast zapisać. Dział 7
		 * kończył się wtedy `insert_failed`, transakcja szła w ROLLBACK, a klient
		 * dostawał ogólne „sprawdź dane i spróbuj ponownie" bez wskazania pola —
		 * więc każda kolejna próba z tym samym tekstem kończyła się identycznie
		 * i lead przepadał.
		 *
		 * Limit liczony w ZNAKACH (`str_len`), bo `varchar(100)` w utf8mb4 liczy
		 * znaki, nie bajty — ta sama pułapka co w P2-G2.
		 */
		foreach ( array(
			'segment'    => 'Segment / branża',
			'est_volume' => 'Przewidywany wolumen',
		) as $pole => $etykieta ) {
			$wartosc = (string) $context->get( $pole, '' );

			if ( '' !== $wartosc && self::str_len( $wartosc ) > 100 ) {
				$errors[ $pole ] = sprintf( '%s: wartość jest za długa (maks. 100 znaków)', $etykieta );
			}
		}

		// Kraj jest opcjonalny (pusty → dział 4.1 domyślnie ustawi PL), ale gdy podany,
		// musi mieć format ISO 3166-1 alpha-2 — inaczej surowa, błędna wartość trafiłaby
		// nieprzechwycona do wywołania VIES w dziale 3 (audyt 2026-07-22, [ŚR] Walidacja pól).
		$country = strtoupper( trim( (string) $context->get( 'country', '' ) ) );
		if ( '' !== $country && ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$errors['country'] = 'Kod kraju musi być dwuliterowym kodem ISO 3166-1 (np. PL)';
		}

		return MP_Result::ok(
			array(
				'form_valid' => empty( $errors ),
				'errors'     => $errors,
			)
		);
	}
}

/**
 * Krytyk 2.2 — sprawdza, że po normalizacji pola wymagane nie są puste.
 */
class MP_D2_Normalize_Critic extends MP_Abstract_Critic {

	/**
	 * @param MP_Result  $agent_result Wynik agenta.
	 * @param MP_Context $context      Kontekst.
	 * @return MP_Result
	 */
	public function review( MP_Result $agent_result, MP_Context $context ) {
		unset( $context );

		if ( ! $agent_result->is_ok() ) {
			return $agent_result;
		}

		$data = $agent_result->get_data();
		foreach ( array( 'company_name', 'email' ) as $key ) {
			if ( '' === trim( (string) ( isset( $data[ $key ] ) ? $data[ $key ] : '' ) ) ) {
				return MP_Result::fail( sprintf( 'Puste pole po normalizacji: %s', $key ), array(), 'normalize_failed' );
			}
		}

		return MP_Result::ok( $data );
	}
}

/**
 * QA Agent 2 — kontrola poprawności działu (brak braków + form_valid).
 */
class MP_D2_QA_Agent extends MP_Abstract_Agent {

	public function __construct() {
		parent::__construct( 'QA2', 'QA Agent 2 — kontrola poprawności', 'Sprawdza kompletność pól i poprawność formatów' );
	}

	/**
	 * @param MP_Context $context Kontekst.
	 * @return MP_Result
	 */
	public function run( MP_Context $context ) {
		$missing    = (array) $context->get( 'missing_fields', array() );
		$form_valid = (bool) $context->get( 'form_valid', false );

		if ( ! empty( $missing ) || ! $form_valid ) {
			return MP_Result::fail(
				'Walidacja formularza nieudana',
				array(
					'missing' => $missing,
					'errors'  => $context->get( 'errors', array() ),
				),
				'validation_failed'
			);
		}

		return MP_Result::ok( array( 'd2_valid' => true ) );
	}
}

/**
 * Budowniczy działu 2.
 */
class MP_Department_02 {

	/**
	 * @return MP_Department
	 */
	public static function build() {
		$pairs = array(
			array(
				'agent'  => new MP_D2_Agent_Required_Fields(),
				'critic' => new MP_Flag_Critic( 'K2.1', 'Krytyk 2.1 — weryfikuje kompletność pól', 'required_ok', 'errors', 'required_missing' ),
			),
			array(
				'agent'  => new MP_D2_Agent_Normalize(),
				'critic' => new MP_D2_Normalize_Critic( 'K2.2', 'Krytyk 2.2 — weryfikuje normalizację' ),
			),
			array(
				'agent'  => new MP_D2_Agent_Validate_Formats(),
				'critic' => new MP_Flag_Critic( 'K2.3', 'Krytyk 2.3 — weryfikuje formaty', 'form_valid', 'errors', 'format_invalid' ),
			),
		);

		$gate = new MP_Quality_Gate(
			new MP_D2_QA_Agent(),
			new MP_Accept_Critic( 'QAK2', 'QA Krytyk 2 — akceptuje lub odrzuca' )
		);

		return new MP_Department(
			2,
			'validate-form',
			'Walidacja formularza (wstępna)',
			'Sprawdzenie struktury, wymaganych pól i formatów danych.',
			$pairs,
			$gate
		);
	}
}
