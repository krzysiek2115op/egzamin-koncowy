<?php
/**
 * Slownik kodow bledow MP3-Exxx.
 *
 * Wywolujacy dostaje KOD, nie opis wnetrza. Powod jest praktyczny, nie
 * kosmetyczny: komunikat typu "Duplicate entry '...' for key 'uq_lead'" mowi
 * atakujacemu, ze taki lead istnieje, jak nazywa sie tabela i ktory indeks jest
 * unikalny. Kod MP3-E150 nie mowi nic ponad "sprobuj jeszcze raz".
 *
 * Szczegoly zostaja po naszej stronie — w dzienniku technicznym, razem z
 * trace_id, ktory laczy odpowiedz z wpisem (patrz MP_SW_Log).
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kody bledow wtyczki.
 */
class MP_SW_Errors {

	/** Nieaktualny lub podrobiony token zadania. */
	const E_NONCE = 'MP3-E100';

	/** Brak uprawnienia do operacji. */
	const E_CAPABILITY = 'MP3-E101';

	/** Typ zdarzenia niedozwolony z tego kanalu (macierz pochodzenia). */
	const E_ORIGIN = 'MP3-E110';

	/** Zdarzenie timera wywolane poza kontekstem crona. */
	const E_CRON_CONTEXT = 'MP3-E111';

	/** Koperta niezgodna z kontraktem. */
	const E_SCHEMA = 'MP3-E120';

	/** Koperta niekompletna (bramka jakosci Dzialu 1). */
	const E_INCOMPLETE = 'MP3-E121';

	/** Payload przekracza dopuszczalny rozmiar. */
	const E_TOO_LARGE = 'MP3-E122';

	/** Pole spoza kontraktu. */
	const E_UNKNOWN_FIELD = 'MP3-E123';

	/** Brak klucza idempotencji. */
	const E_KEY_MISSING = 'MP3-E130';

	/** Klucz idempotencji podany, ale uszkodzony. */
	const E_KEY_MALFORMED = 'MP3-E131';

	/** Obiekt nie istnieje ALBO lezy poza zakresem aktora (anty-IDOR). */
	const E_NOT_FOUND = 'MP3-E140';

	/** Proba rozszerzenia zakresu widoku. */
	const E_SCOPE = 'MP3-E141';

	/** Konflikt wersji wiersza — ktos zapisal przed nami. */
	const E_CONFLICT = 'MP3-E150';

	/** Przejscie statusu spoza maszyny stanow. */
	const E_TRANSITION = 'MP3-E160';

	/** Tresc powiadomienia odrzucona (nagłowki, znaczniki, odbiorca). */
	const E_MAIL = 'MP3-E170';

	/** Bezpiecznik kolejki zatrzymal wysylke. */
	const E_QUEUE_BREAKER = 'MP3-E171';

	/** Podpis linku pobrania nie zgadza sie. */
	const E_LINK_SIGNATURE = 'MP3-E180';

	/** Link pobrania po terminie waznosci. */
	const E_LINK_EXPIRED = 'MP3-E181';

	/** Zbyt wiele prob pobrania z jednego adresu. */
	const E_RATE_LIMIT = 'MP3-E182';

	/**
	 * Oferta nie ma jeszcze gotowego dokumentu do wyslania.
	 *
	 * Osobny kod, a nie „blad wewnetrzny": to jedyna odmowa na pulpicie, ktora
	 * handlowiec usuwa SAM — dokanczajac oferte w module ofertowym. Zlanie jej z
	 * brakiem klucza podpisu (usterka wdrozeniowa, sprawa administratora) kazaloby
	 * mu szukac winy nie tam, gdzie trzeba.
	 */
	const E_OFFER_DOCUMENT = 'MP3-E190';

	/** Blad po naszej stronie. */
	const E_INTERNAL = 'MP3-E500';

	/**
	 * Mapowanie wewnetrznych kodow krytykow na kody publiczne.
	 *
	 * Krytycy nazywaja bledy po swojemu (`scope_escalation`, `version_conflict`),
	 * bo tak czyta sie je w testach i w logu. Na zewnatrz wychodzi wylacznie
	 * kod ze slownika — inaczej nazwy wewnetrznych regul stalyby sie czescia
	 * kontraktu API i nie daloby sie ich zmienic bez zmiany odpowiedzi.
	 *
	 * @return array<string,string>
	 */
	public static function map() {
		return array(
			'bad_nonce'                 => self::E_NONCE,
			'forbidden'                 => self::E_CAPABILITY,
			'origin_forbidden'          => self::E_ORIGIN,
			'cron_context_required'     => self::E_CRON_CONTEXT,
			'invalid_event'             => self::E_SCHEMA,
			'contract_failed'           => self::E_SCHEMA,
			'incomplete_event'          => self::E_INCOMPLETE,
			'payload_too_large'         => self::E_TOO_LARGE,
			'unknown_field'             => self::E_UNKNOWN_FIELD,
			'missing_idempotency_key'   => self::E_KEY_MISSING,
			'malformed_idempotency_key' => self::E_KEY_MALFORMED,
			'flow_not_found'            => self::E_NOT_FOUND,
			'out_of_scope'              => self::E_NOT_FOUND,
			'scope_escalation'          => self::E_SCOPE,
			'scope_from_request'        => self::E_SCOPE,
			'operation_forbidden'       => self::E_CAPABILITY,
			'version_conflict'          => self::E_CONFLICT,
			'invalid_transition'        => self::E_TRANSITION,
			'unresolved_markers'        => self::E_MAIL,
			'offer_document_missing'    => self::E_OFFER_DOCUMENT,
			'invalid_recipient'         => self::E_MAIL,
			'header_injection'          => self::E_MAIL,
			'attachment_not_allowed'    => self::E_MAIL,
			'queue_circuit_open'        => self::E_QUEUE_BREAKER,
		);
	}

	/**
	 * Kod publiczny dla kodu wewnetrznego.
	 *
	 * Nieznany kod wewnetrzny to MP3-E500, a nie jego wlasna nazwa: nowy krytyk
	 * dodany bez wpisu w slowniku nie moze wyciec nazwa reguly na zewnatrz.
	 *
	 * @param string $internal Kod krytyka.
	 * @return string
	 */
	public static function code( $internal ) {
		$map      = self::map();
		$internal = (string) $internal;

		return isset( $map[ $internal ] ) ? $map[ $internal ] : self::E_INTERNAL;
	}

	/**
	 * Komunikat dla wywolujacego — OGOLNY, jeden na kod.
	 *
	 * Komunikat szczegolowy (ktory krytyk, jaka wartosc, ktory wiersz) zostaje w
	 * dzienniku technicznym pod tym samym `trace_id`. Powod jest konkretny: wsrod
	 * szczegolowych komunikatow pipeline'u sa takie, ktore odbijaja wywolujacemu
	 * jego wlasny payload — a przy odmowie z powodu zlego adresu odbiorcy byloby
	 * to odeslanie adresu e-mail klienta w odpowiedzi HTTP.
	 *
	 * @param string $code Kod publiczny.
	 * @return string
	 */
	public static function message( $code ) {
		$messages = array(
			self::E_NONCE          => __( 'Nieaktualny token żądania — odśwież stronę i spróbuj ponownie.', 'mp-sales-workflow' ),
			self::E_CAPABILITY     => __( 'Brak uprawnień do tej operacji.', 'mp-sales-workflow' ),
			self::E_ORIGIN         => __( 'Zdarzenie tego typu nie może pochodzić z tego kanału.', 'mp-sales-workflow' ),
			self::E_CRON_CONTEXT   => __( 'Zdarzenie harmonogramu poza przebiegiem harmonogramu.', 'mp-sales-workflow' ),
			self::E_SCHEMA         => __( 'Zdarzenie niezgodne z kontraktem.', 'mp-sales-workflow' ),
			self::E_INCOMPLETE     => __( 'Zdarzenie niekompletne.', 'mp-sales-workflow' ),
			self::E_TOO_LARGE      => __( 'Żądanie przekracza dopuszczalny rozmiar.', 'mp-sales-workflow' ),
			self::E_UNKNOWN_FIELD  => __( 'Żądanie zawiera pole spoza kontraktu.', 'mp-sales-workflow' ),
			self::E_KEY_MISSING    => __( 'Brak klucza idempotencji zdarzenia.', 'mp-sales-workflow' ),
			self::E_KEY_MALFORMED  => __( 'Klucz idempotencji ma niepoprawną postać.', 'mp-sales-workflow' ),
			self::E_NOT_FOUND      => __( 'Nie znaleziono procesu.', 'mp-sales-workflow' ),
			self::E_SCOPE          => __( 'Zakres widoku poza uprawnieniami.', 'mp-sales-workflow' ),
			self::E_CONFLICT       => __( 'Proces zmienił się w międzyczasie — odśwież i powtórz.', 'mp-sales-workflow' ),
			self::E_TRANSITION     => __( 'Niedozwolone przejście statusu.', 'mp-sales-workflow' ),
			self::E_MAIL           => __( 'Powiadomienie odrzucone przed wysyłką.', 'mp-sales-workflow' ),
			self::E_OFFER_DOCUMENT => __( 'Oferta nie ma jeszcze gotowego dokumentu PDF — dokończ ją w module ofertowym i spróbuj ponownie.', 'mp-sales-workflow' ),
			self::E_QUEUE_BREAKER  => __( 'Kolejka powiadomień wstrzymana przez bezpiecznik.', 'mp-sales-workflow' ),
			self::E_LINK_SIGNATURE => __( 'Link jest nieprawidłowy.', 'mp-sales-workflow' ),
			self::E_LINK_EXPIRED   => __( 'Link jest nieprawidłowy.', 'mp-sales-workflow' ),
			self::E_RATE_LIMIT     => __( 'Zbyt wiele żądań — spróbuj później.', 'mp-sales-workflow' ),
			self::E_INTERNAL       => __( 'Operacja nie powiodła się.', 'mp-sales-workflow' ),
		);

		$code = (string) $code;

		return isset( $messages[ $code ] ) ? $messages[ $code ] : $messages[ self::E_INTERNAL ];
	}

	/**
	 * Czy kod publiczny nalezy do slownika.
	 *
	 * @param string $code Kod do sprawdzenia.
	 * @return bool
	 */
	public static function is_known( $code ) {
		return in_array( (string) $code, array_values( self::map() ), true )
			|| in_array( (string) $code, array( self::E_INTERNAL, self::E_LINK_SIGNATURE, self::E_LINK_EXPIRED, self::E_RATE_LIMIT ), true );
	}
}
