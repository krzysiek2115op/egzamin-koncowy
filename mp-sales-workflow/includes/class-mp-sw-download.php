<?php
/**
 * Podpisany link pobrania oferty — jedyny publiczny punkt wejscia LP.3.
 *
 * Klient nie ma konta w WordPressie, wiec ten endpoint nie moze bronic sie
 * logowaniem. Broni sie kryptografia: adres niesie podpis HMAC-SHA256 nad para
 * (uchwyt oferty, termin waznosci), liczony kluczem `MP_SW_LINK_KEY` z
 * `wp-config.php`. Bez klucza nie da sie zbudowac wazniego adresu, a wiec
 * przelatywanie identyfikatorow niczego nie daje — kazdy z nich odbija sie o
 * ten sam brakujacy podpis.
 *
 * DLACZEGO IDENTYFIKATOR W ADRESIE JEST BEZPIECZNY. Sekretem jest podpis, nie
 * uchwyt. To ten sam uklad, ktory stosuja podpisane adresy w magazynach obiektow:
 * nazwa pliku bywa jawna, waznosc daje podpis nad nia. Tam, gdzie LP.2 nadalo
 * ofercie `request_id` (UUID), uzywamy jego — wtedy z adresu nie da sie odczytac
 * nawet tego, ile ofert wystawila firma.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publiczny handler pobrania oferty.
 */
class MP_SW_Download {

	/** Parametr adresu: uchwyt oferty. */
	const ARG_HANDLE = 'mp_sw_offer';

	/** Parametr adresu: termin waznosci (znacznik uniksowy). */
	const ARG_EXPIRES = 'mp_sw_exp';

	/** Parametr adresu: podpis. */
	const ARG_SIGNATURE = 'mp_sw_sig';

	/** Domyslna waznosc linku w dniach. */
	const TTL_DAYS = 14;

	/** Ile prob pobrania z jednego adresu w oknie czasu. */
	const RATE_LIMIT = 30;

	/** Dlugosc okna limitu w sekundach. */
	const RATE_WINDOW = 60;

	/** Filtr pozwalajacy LP.2 samodzielnie rozwiazac uchwyt na plik. */
	const FILTER_RESOLVE = 'mp_sw_resolve_offer_file';

	/** Filtr waznosci linku (dni). */
	const FILTER_TTL = 'mp_sw_link_ttl_days';

	/**
	 * Wpina handler.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 5 );
	}

	/**
	 * Klucz podpisu.
	 *
	 * Brak stalej to NIE jest powod do wygenerowania klucza losowego i zapisania
	 * go w bazie: klucz w bazie wycieka razem z kopia bazy, a wtedy kazdy moze
	 * podpisac sobie link do dowolnej oferty. Bez stalej endpoint po prostu nie
	 * dziala i mowi o tym w dzienniku — brak funkcji jest lepszy niz funkcja,
	 * ktora tylko wyglada na zabezpieczona.
	 *
	 * @return string
	 */
	public static function key() {
		if ( defined( 'MP_SW_LINK_KEY' ) && '' !== (string) MP_SW_LINK_KEY ) {
			return (string) MP_SW_LINK_KEY;
		}

		return '';
	}

	/**
	 * Czy podpisywanie linkow jest w ogole mozliwe.
	 *
	 * @return bool
	 */
	public static function available() {
		return '' !== self::key();
	}

	/**
	 * Podpis dla pary (uchwyt, termin).
	 *
	 * @param string $handle  Uchwyt oferty.
	 * @param int    $expires Znacznik uniksowy waznosci.
	 * @return string Pusty ciag, gdy brak klucza.
	 */
	public static function sign( $handle, $expires ) {
		$key = self::key();

		if ( '' === $key ) {
			return '';
		}

		return hash_hmac( 'sha256', (string) $handle . '|' . (int) $expires, $key );
	}

	/**
	 * Buduje podpisany adres pobrania.
	 *
	 * @param string $handle Uchwyt oferty (request_id albo identyfikator).
	 * @param int    $ttl    Waznosc w dniach; 0 = domyslna.
	 * @return string Pusty ciag, gdy brak klucza albo uchwytu.
	 */
	public static function url( $handle, $ttl = 0 ) {
		$handle = (string) $handle;

		if ( '' === $handle || ! self::available() ) {
			return '';
		}

		$days    = $ttl > 0 ? (int) $ttl : (int) apply_filters( self::FILTER_TTL, self::TTL_DAYS );
		$expires = time() + ( max( 1, $days ) * DAY_IN_SECONDS );

		return add_query_arg(
			array(
				self::ARG_HANDLE    => rawurlencode( $handle ),
				self::ARG_EXPIRES   => $expires,
				self::ARG_SIGNATURE => self::sign( $handle, $expires ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Obsluguje zadanie pobrania, jesli adres go dotyczy.
	 *
	 * @return void
	 */
	public static function maybe_serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- endpoint publiczny; broni go podpis, nie nonce.
		if ( ! isset( $_GET[ self::ARG_HANDLE ] ) ) {
			return;
		}

		$handle    = sanitize_text_field( wp_unslash( $_GET[ self::ARG_HANDLE ] ) );
		$expires   = isset( $_GET[ self::ARG_EXPIRES ] ) ? (int) $_GET[ self::ARG_EXPIRES ] : 0;
		$signature = isset( $_GET[ self::ARG_SIGNATURE ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::ARG_SIGNATURE ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$verdict = self::verify( $handle, $expires, $signature );

		if ( ! $verdict['ok'] ) {
			self::deny( $verdict['code'], $verdict['reason'] );
		}

		$file = self::resolve( $handle );

		if ( '' === $file ) {
			/*
			 * Ten sam kod i ten sam komunikat, co przy zlym podpisie. Rozroznienie
			 * „podpis zly" od „oferty nie ma" byloby wyrocznia: majac wlasny wazny
			 * link, mozna by po roznicy odpowiedzi ustalic, ktore uchwyty istnieja.
			 */
			self::deny( MP_SW_Errors::E_LINK_SIGNATURE, 'no_file' );
		}

		self::journal( $handle );
		self::stream( $file );
	}

	/**
	 * Sprawdza limit tempa, termin i podpis.
	 *
	 * @param string $handle    Uchwyt.
	 * @param int    $expires   Termin.
	 * @param string $signature Podpis.
	 * @return array{ok:bool,code:string,reason:string}
	 */
	public static function verify( $handle, $expires, $signature ) {
		if ( ! self::available() ) {
			return array(
				'ok'     => false,
				'code'   => MP_SW_Errors::E_LINK_SIGNATURE,
				'reason' => 'no_key',
			);
		}

		if ( ! self::within_rate_limit() ) {
			return array(
				'ok'     => false,
				'code'   => MP_SW_Errors::E_RATE_LIMIT,
				'reason' => 'rate',
			);
		}

		$expected = self::sign( $handle, $expires );

		/*
		 * Porownanie stalo-czasowe. Zwykle `===` konczy prace na pierwszym roznym
		 * znaku, wiec czas odpowiedzi zdradza, ile poczatkowych znakow podpisu
		 * zgadlismy — a to wystarczy, zeby dobrac caly podpis znak po znaku.
		 */
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return array(
				'ok'     => false,
				'code'   => MP_SW_Errors::E_LINK_SIGNATURE,
				'reason' => 'signature',
			);
		}

		// Termin sprawdzamy PO podpisie: inaczej odpowiedz „po terminie" byla by
		// potwierdzeniem, ze uchwyt jest prawdziwy, jeszcze zanim ktos zna klucz.
		if ( $expires < time() ) {
			return array(
				'ok'     => false,
				'code'   => MP_SW_Errors::E_LINK_EXPIRED,
				'reason' => 'expired',
			);
		}

		return array(
			'ok'     => true,
			'code'   => '',
			'reason' => '',
		);
	}

	/**
	 * Licznik prob na hash adresu IP.
	 *
	 * @return bool
	 */
	private static function within_rate_limit() {
		$ip_hash = MP_SW_Log::ip_hash();

		if ( '' === $ip_hash ) {
			// Bez pieprza nie liczymy per adres — zliczanie po surowym IP oznaczaloby
			// trzymanie adresow w bazie transientow, czyli dokladnie te dane, ktorych
			// unikamy w calej wtyczce.
			return true;
		}

		$key   = 'mp_sw_dl_' . substr( $ip_hash, 0, 32 );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return true;
	}

	/**
	 * Rozwiazuje uchwyt na sciezke pliku PDF — po stronie serwera.
	 *
	 * Sciezka NIGDY nie pochodzi z adresu (I-3). Wyliczamy ja z bazy, a nastepnie
	 * sprawdzamy `realpath()` wobec katalogu wysylek: bez tego wpis w bazie
	 * zawierajacy `../../wp-config.php` wystarczylby, zeby wyprowadzic z serwera
	 * dowolny plik.
	 *
	 * @param string $handle Uchwyt oferty.
	 * @return string Sciezka albo pusty ciag.
	 */
	public static function resolve( $handle ) {
		global $wpdb;

		/**
		 * Pozwala LP.2 rozwiazac uchwyt wlasnym kodem.
		 *
		 * @param string|null $path   Sciezka pliku albo null.
		 * @param string      $handle Uchwyt oferty.
		 */
		$path = apply_filters( self::FILTER_RESOLVE, null, (string) $handle );

		if ( null === $path ) {
			$table = $wpdb->prefix . 'mp_ob_offers';
			$path  = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT pdf_path FROM {$table} WHERE request_id = %s OR id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(string) $handle,
					(int) $handle
				)
			);
		}

		$path = (string) $path;

		if ( '' === $path ) {
			return '';
		}

		return self::inside_uploads( $path );
	}

	/**
	 * Zwraca sciezke tylko wtedy, gdy plik lezy w katalogu wysylek.
	 *
	 * @param string $path Sciezka z bazy (moze byc wzgledna wobec uploads).
	 * @return string
	 */
	public static function inside_uploads( $path ) {
		$uploads = wp_get_upload_dir();
		$base    = realpath( isset( $uploads['basedir'] ) ? $uploads['basedir'] : '' );

		if ( false === $base ) {
			return '';
		}

		$candidate = realpath( $path );

		if ( false === $candidate ) {
			$candidate = realpath( trailingslashit( $base ) . ltrim( $path, '/\\' ) );
		}

		if ( false === $candidate || ! is_file( $candidate ) ) {
			return '';
		}

		// Separator na koncu jest istotny: bez niego katalog `/uploads-kopia`
		// przeszedlby test „zaczyna sie od /uploads".
		if ( 0 !== strpos( $candidate, trailingslashit( $base ) ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Wpis o pobraniu do dziennika procesu (kryterium 5.5).
	 *
	 * @param string $handle Uchwyt oferty.
	 * @return void
	 */
	private static function journal( $handle ) {
		global $wpdb;

		$offers = $wpdb->prefix . 'mp_ob_offers';
		$lead   = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT lead_id FROM {$offers} WHERE request_id = %s OR id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $handle,
				(int) $handle
			)
		);

		if ( $lead < 1 ) {
			return;
		}

		$flow_table = MP_Sales_Workflow_DB::flow_table();
		$flow_id    = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id FROM {$flow_table} WHERE lead_id = %d LIMIT 1", $lead ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $flow_id < 1 ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MP_Sales_Workflow_DB::activity_table(),
			array(
				'flow_id'    => $flow_id,
				'entity_ref' => 'offer',
				'action'     => 'offer.downloaded',
				'actor_type' => 'client',
				'old_value'  => null,

				// Bez adresu IP i bez adresu e-mail: dziennik ma pokazywac, ZE
				// dokument pobrano, a nie kto i skad (BLOK H).
				'new_value'  => 'pdf',
				'actor_id'   => null,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Wysyla plik i konczy zadanie.
	 *
	 * @param string $file Bezwzgledna sciezka pliku.
	 * @return void
	 */
	private static function stream( $file ) {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $file ) ) . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'' );

		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Odmowa: jednakowa odpowiedz, slad w dzienniku technicznym.
	 *
	 * @param string $code   Kod MP3-Exxx.
	 * @param string $reason Powod (tylko do dziennika).
	 * @return void
	 */
	private static function deny( $code, $reason ) {
		MP_SW_Log::security(
			$code,
			array(
				'code'    => $code,
				'channel' => 'download',
				'reason'  => $reason,
				'ip_hash' => MP_SW_Log::ip_hash(),
			)
		);

		$status = MP_SW_Errors::E_RATE_LIMIT === $code ? 429 : 403;

		wp_die(
			esc_html( MP_SW_Errors::message( $code ) ),
			esc_html__( 'Nieprawidłowy link', 'mp-sales-workflow' ),
			array( 'response' => (int) $status )
		);
	}
}
