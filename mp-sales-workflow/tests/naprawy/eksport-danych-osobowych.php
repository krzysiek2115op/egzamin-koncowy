<?php
/**
 * P3-G10 — LP.3 kasowala dane osobowe, ale nie umiala ich WYDAC.
 *
 * Uruchamianie: wp eval-file tests/naprawy/eksport-danych-osobowych.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-G10  Brak eksportera danych osobowych w LP.3
 *
 * `MP_SW_Privacy::register()` wpinalo wylacznie `wp_privacy_personal_data_erasers`.
 * LP.1 i LP.2 wpinaja OBA filtry rdzenia — eksporter i kasownik — wiec zadanie
 * „Eksportuj dane osobowe" z Narzedzi WordPressa oddawalo komplet z dwoch
 * wtyczek i CISZE z trzeciej. Nie bylo przy tym zadnego bledu ani ostrzezenia:
 * raport wygladal na kompletny.
 *
 * Sama wtyczka traktuje te dane jako osobowe i nigdy nie twierdzila inaczej —
 * ma `erase_by_email()`, `anonymize_lead()` i `is_anonymized()`, a naglowek
 * pliku wymienia oba miejsca, w ktorych trzyma adres. RODO daje jednak prawo
 * DOSTEPU (art. 15) i przenoszenia (art. 20) obok prawa do usuniecia (art. 17);
 * obsluzenie samego usuwania zalatwia jedno z trzech.
 *
 * ZASIEG — sprawdzony sonda, nie zalozony:
 * LP.3 przechowuje adres w dwoch miejscach i tylko dwoch (naglowek
 * class-mp-sw-privacy.php): `flow.client_email` oraz `notifications.recipient`.
 * Dziennik aktywnosci adresu nie zawiera. Eksport pokrywa oba i nic wiecej —
 * sekcja D to utrwala.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_edo'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function edo_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_edo']['pass'];
		$GLOBALS['mp_edo']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_edo']['fail'];
	$GLOBALS['mp_edo']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

$edo_email = 'p3g10-eksport@example.test';
$edo_obcy  = 'p3g10-nie-ten@example.test';

/**
 * Wypisuje wynik takze po bledzie krytycznym — bez tego padniecie w polowie
 * chowa asercje, ktore zdazyly sie wykonac, i nie widac CO dokladnie nie dziala.
 *
 * @return void
 */
function edo_dump() {
	if ( empty( $GLOBALS['mp_edo']['lines'] ) ) {
		return;
	}

	$r = $GLOBALS['mp_edo'];
	echo implode( "\n", $r['lines'] ) . "\n";
	echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $r['pass'], $r['fail'] );
	echo ( 0 === $r['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
	$GLOBALS['mp_edo']['lines'] = array();
}
register_shutdown_function( 'edo_dump' );

// Sprzatanie ZAWSZE, takze po fatalu — inaczej wiersze testowe zostaja w BD-1.
register_shutdown_function(
	function () {
		global $wpdb;
		$flow = MP_Sales_Workflow_DB::flow_table();
		$noti = MP_Sales_Workflow_DB::notifications_table();
		$wpdb->query( "DELETE FROM {$noti} WHERE recipient LIKE 'p3g10-%'" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$flow} WHERE client_email LIKE 'p3g10-%'" ); // phpcs:ignore
	}
);

global $wpdb;
$edo_flow_t = MP_Sales_Workflow_DB::flow_table();
$edo_noti_t = MP_Sales_Workflow_DB::notifications_table();
$edo_teraz  = current_time( 'mysql', true );

/*
 * Sprzatanie takze PRZED wstawieniem, nie tylko po. Sprzatanie na wyjsciu nie
 * wystarcza: gdy poprzedni przebieg padnie przed nim (albo zostanie ubity),
 * wiersze zostaja, a `uq_lead` odbija kolejny INSERT. Test przestaje wtedy
 * badac eksport i zaczyna badac stan bazy po swojej wlasnej awarii.
 */
$wpdb->query( "DELETE FROM {$edo_noti_t} WHERE recipient LIKE 'p3g10-%'" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$edo_flow_t} WHERE client_email LIKE 'p3g10-%'" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$edo_flow_t} WHERE lead_id IN ( 991001, 991002 )" ); // phpcs:ignore

$wpdb->insert( // phpcs:ignore
	$edo_flow_t,
	array(
		'lead_id'      => 991001,
		'offer_number' => 'OF/P3G10/1',
		'status'       => 'new',
		'lang'         => 'pl',
		'segment'      => 'roboty',
		'client_name'  => 'P3G10 Testowa sp. z o.o.',
		'client_email' => $edo_email,
		'created_at'   => $edo_teraz,
		'updated_at'   => $edo_teraz,
	)
);
$edo_flow_id = (int) $wpdb->insert_id;

// Wiersz obcego podmiotu — eksport nie ma prawa go wydac.
$wpdb->insert( // phpcs:ignore
	$edo_flow_t,
	array(
		'lead_id'      => 991002,
		'status'       => 'new',
		'lang'         => 'pl',
		'client_name'  => 'P3G10 Obca sp. z o.o.',
		'client_email' => $edo_obcy,
		'created_at'   => $edo_teraz,
		'updated_at'   => $edo_teraz,
	)
);

$GLOBALS['mp_edo']['lines'][] = '=== A. rejestracja w rdzeniu WordPressa ===';

remove_all_filters( 'wp_privacy_personal_data_exporters' );
remove_all_filters( 'wp_privacy_personal_data_erasers' );
MP_SW_Privacy::register();

$edo_eksportery = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$edo_kasowniki  = apply_filters( 'wp_privacy_personal_data_erasers', array() );

edo_ok(
	isset( $edo_eksportery['mp-sales-workflow'] ),
	'LP.3 rejestruje sie jako EKSPORTER danych osobowych',
	'zarejestrowani: ' . ( $edo_eksportery ? implode( ', ', array_keys( $edo_eksportery ) ) : 'ZADEN' )
);
edo_ok(
	isset( $edo_eksportery['mp-sales-workflow']['callback'] )
		&& is_callable( $edo_eksportery['mp-sales-workflow']['callback'] ),
	'wpis eksportera niesie wywolywalny callback'
);
edo_ok(
	isset( $edo_eksportery['mp-sales-workflow']['exporter_friendly_name'] )
		&& '' !== (string) $edo_eksportery['mp-sales-workflow']['exporter_friendly_name'],
	'eksporter ma nazwe czytelna dla czlowieka — to ona trafia do raportu podmiotu'
);

// Kontr-asercja: dolozenie eksportera nie ma prawa zgubic kasownika.
edo_ok(
	isset( $edo_kasowniki['mp-sales-workflow'] ),
	'kasownik NADAL jest zarejestrowany'
);

$GLOBALS['mp_edo']['lines'][] = '=== B. eksport oddaje dane tego podmiotu ===';

$edo_callback = isset( $edo_eksportery['mp-sales-workflow']['callback'] )
	? $edo_eksportery['mp-sales-workflow']['callback']
	: null;

if ( ! is_callable( $edo_callback ) ) {
	// Bez zarejestrowanego eksportera dalsze sekcje nie maja czego wolac.
	// Zglaszamy je jako porazki i konczymy CZYTELNIE, zamiast wywracac przebieg.
	foreach ( array(
		'eksport zwraca ksztalt wymagany przez rdzen (data + done)',
		'eksport znajduje proces powiazany z adresem',
		'w eksporcie jest numer oferty procesu',
		'w eksporcie jest nazwa klienta',
		'w eksporcie jest adres e-mail',
		'proces innego podmiotu NIE trafia do eksportu',
		'adres innego podmiotu NIE trafia do eksportu',
		'adres bez zadnego procesu daje pusty, ale POPRAWNY wynik',
		'eksport obejmuje TAKZE kolejke powiadomien (drugie miejsce z adresem)',
	) as $edo_pominieta ) {
		edo_ok( false, $edo_pominieta, 'brak zarejestrowanego eksportera' );
	}

	edo_dump();
	return;
}

$edo_wynik = call_user_func( $edo_callback, $edo_email, 1 );

edo_ok(
	is_array( $edo_wynik ) && isset( $edo_wynik['data'] ) && isset( $edo_wynik['done'] ),
	'eksport zwraca ksztalt wymagany przez rdzen (data + done)',
	'oddano=' . wp_json_encode( is_array( $edo_wynik ) ? array_keys( $edo_wynik ) : gettype( $edo_wynik ) )
);

$edo_pozycje = isset( $edo_wynik['data'] ) ? (array) $edo_wynik['data'] : array();

edo_ok(
	count( $edo_pozycje ) > 0,
	'eksport znajduje proces powiazany z adresem',
	'pozycji=' . count( $edo_pozycje )
);

// Bez JSON_UNESCAPED_SLASHES numer oferty „OF/P3G10/1" wychodzi jako
// „OF\/P3G10\/1" i szukanie po nim nie trafia — sonda oblewalaby poprawny kod.
$edo_zrzut = wp_json_encode( $edo_pozycje, JSON_UNESCAPED_SLASHES );

edo_ok(
	false !== strpos( (string) $edo_zrzut, 'OF/P3G10/1' ),
	'w eksporcie jest numer oferty procesu'
);
edo_ok(
	false !== strpos( (string) $edo_zrzut, 'P3G10 Testowa' ),
	'w eksporcie jest nazwa klienta'
);
edo_ok(
	false !== strpos( (string) $edo_zrzut, $edo_email ),
	'w eksporcie jest adres e-mail'
);

$GLOBALS['mp_edo']['lines'][] = '=== C. eksport NIE wydaje cudzych danych ===';

/*
 * Najgrozniejszy blad eksportera nie polega na tym, ze czegos nie odda — tylko
 * na tym, ze odda CUDZE. Zadanie RODO sklada sie po weryfikacji adresu, wiec
 * zbyt szeroki eksport wydaje dane obcej firmy osobie, ktora o nie poprosila.
 */
edo_ok(
	false === strpos( (string) $edo_zrzut, 'P3G10 Obca' ),
	'proces innego podmiotu NIE trafia do eksportu'
);
edo_ok(
	false === strpos( (string) $edo_zrzut, $edo_obcy ),
	'adres innego podmiotu NIE trafia do eksportu'
);

$edo_pusty = call_user_func( $edo_callback, 'p3g10-nikt@example.test', 1 );
edo_ok(
	isset( $edo_pusty['data'] ) && array() === (array) $edo_pusty['data'] && ! empty( $edo_pusty['done'] ),
	'adres bez zadnego procesu daje pusty, ale POPRAWNY wynik',
	'oddano=' . wp_json_encode( $edo_pusty )
);

$GLOBALS['mp_edo']['lines'][] = '=== D. zasieg: oba miejsca z adresem, i tylko one ===';

/*
 * Naglowek class-mp-sw-privacy.php deklaruje, ze LP.3 trzyma adres w DWOCH
 * miejscach: `flow.client_email` i `notifications.recipient`. Eksport, ktory
 * pokrywa jedno z nich, jest tak samo niepelny jak jego brak — z ta roznica,
 * ze wyglada na kompletny.
 */
$wpdb->insert( // phpcs:ignore
	$edo_noti_t,
	array(
		'flow_id'    => $edo_flow_id,
		// Kolumna nazywa sie `template`, nie `type`, i jest NOT NULL bez DEFAULT.
		// Pierwsza wersja tej sondy wstawiala do nieistniejacej kolumny, wiec
		// INSERT cicho padal i sekcja D badala pusta kolejke zamiast eksportu.
		'template'   => 'offer_sent',
		'recipient'  => $edo_email,
		'subject'    => 'P3G10 temat powiadomienia',
		'body'       => 'P3G10 tresc',
		'status'     => 'sent',
		'created_at' => $edo_teraz,
		'updated_at' => $edo_teraz,
	)
);

$edo_wynik2 = call_user_func( $edo_callback, $edo_email, 1 );
$edo_zrzut2 = wp_json_encode( isset( $edo_wynik2['data'] ) ? $edo_wynik2['data'] : array(), JSON_UNESCAPED_SLASHES );

edo_ok(
	false !== strpos( (string) $edo_zrzut2, 'P3G10 temat powiadomienia' ),
	'eksport obejmuje TAKZE kolejke powiadomien (drugie miejsce z adresem)'
);

edo_dump();
