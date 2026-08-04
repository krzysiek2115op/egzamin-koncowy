<?php
/**
 * Nieudany zapis wpisu do dziennika NIE MOŻE dotknąć cudzego wiersza.
 *
 * Uruchamianie: wp eval-file tests/naprawy/nieudany-wpis-nie-psuje-cudzego.php
 *
 * Audyt zgłosił to jako błąd: `log_failure()` i `log_exception()` wstawiają
 * wiersz do dziennika, a potem biorą `$wpdb->insert_id`, żeby dopisać do niego
 * los alarmu — nie sprawdzając, czy `insert()` się powiodło. Zarzut brzmiał:
 * przy nieudanym wstawieniu `insert_id` zachowa wartość z poprzedniego, udanego
 * INSERT-u w tym samym żądaniu, a `oznacz_los_alarmu()` nadpisze CUDZY wiersz
 * dziennika — jedyna obrona w kodzie (`$wpis_id <= 0`) nic tu nie da, bo
 * identyfikator jest dodatni.
 *
 * ZMIERZONE, ZAMIAST WYWNIOSKOWANE: na tym stosie (WordPress 7.0, mysqli)
 * nieudany `insert()` zwraca `false` i USTAWIA `insert_id` na 0. Strażnik
 * `$wpis_id <= 0` wystarcza więc w zupełności, a ustalenie jest fałszywym
 * alarmem — jego przesłanka o zachowaniu poprzedniej wartości nie zachodzi.
 *
 * Test zostaje mimo to i pilnuje właśnie tego, na czym stoi bezpieczeństwo tej
 * ścieżki: że po nieudanym wstawieniu identyfikator jest zerem, a cudzy wiersz
 * pozostaje nietknięty. Gdyby przyszła wersja WordPressa albo sterownika bazy
 * to zmieniła, opisany scenariusz stałby się prawdziwy — i wtedy ten plik
 * ma o tym powiedzieć, zanim powie o tym dziennik audytowy klienta.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_nw'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegół przy porażce.
 * @return bool
 */
function nw_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_nw']['pass'];
		$GLOBALS['mp_nw']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_nw']['fail'];
	$GLOBALS['mp_nw']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik i sprząta po teście.
 *
 * @return void
 */
function nw_koniec() {
	global $wpdb;

	if ( empty( $GLOBALS['mp_nw']['lines'] ) ) {
		return;
	}

	if ( ! empty( $GLOBALS['mp_nw_sprzatanie'] ) ) {
		$t = MP_Lead_Intake_DB::activity_log_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE id >= %d", (int) $GLOBALS['mp_nw_sprzatanie'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	$r    = $GLOBALS['mp_nw'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_nw']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'nw_koniec' );

// Poczta nie wychodzi z testu; alarm ma prawo isc, wiec go tylko przechwytujemy.
add_filter( 'pre_wp_mail', '__return_true', 99 );

$nw_tabela = MP_Lead_Intake_DB::activity_log_table();
$nw_logger = new MP_Pipeline_Logger();
$nw_dzial  = MP_Department_11::build();

/**
 * Kontekst przebiegu.
 *
 * @return MP_Context
 */
function nw_kontekst() {
	return new MP_Context(
		array(
			'lead_id'    => 4246,
			'request_id' => 'req-nieudany-wpis',
		)
	);
}

/* ==================================================================== A */

$GLOBALS['mp_nw']['lines'][] = '=== A. nieudane wstawienie nie dotyka cudzego wiersza ===';

// Cudzy wiersz — powstaje PRZED awarią, żeby to jego identyfikator został
// w `insert_id` jako ostatni udany.
$nw_opis_obcy = 'WPIS OBCY ' . wp_rand( 100000, 999999 );

$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$nw_tabela,
	array(
		'lead_id'     => 4247,
		'action'      => 'test_wpis_obcy',
		'description' => $nw_opis_obcy,
		'meta_json'   => wp_json_encode( array( 'kto' => 'obcy' ) ),
	)
);

$nw_obcy_id                = (int) $wpdb->insert_id;
$GLOBALS['mp_nw_sprzatanie'] = $nw_obcy_id;

if ( ! nw_ok( $nw_obcy_id > 0, 'cudzy wpis powstal i ma identyfikator', 'id=' . $nw_obcy_id ) ) {
	return;
}

/*
 * Wstawienie wpisu o awarii ma się NIE UDAĆ. Podmieniamy zapytanie filtrem
 * `query` WordPressa (oficjalne API `wpdb::query()`) na takie, które baza
 * odrzuci — to najbliższy prawdzie sposób odtworzenia pełnego dysku czy
 * blokady tabeli, bez ruszania schematu.
 */
// Bezpiecznik alarmu musi byc zwolniony, inaczej `notify_admin()` konczy sie
// wyciszeniem i UPDATE, ktorego szukamy, w ogole by nie ruszyl — test
// przeszedlby, nie sprawdzajac niczego.
delete_transient( 'mp_notify_' . $nw_dzial->get_key() );
$GLOBALS['mp_nw_poczta'] = 0;
add_filter(
	'pre_wp_mail',
	function ( $krotko ) {
		++$GLOBALS['mp_nw_poczta'];
		return $krotko;
	},
	98
);

$GLOBALS['mp_nw_psuj'] = true;

add_filter(
	'query',
	function ( $zapytanie ) {
		if ( empty( $GLOBALS['mp_nw_psuj'] ) ) {
			return $zapytanie;
		}

		if ( 0 === stripos( (string) $zapytanie, 'INSERT' ) && false !== strpos( (string) $zapytanie, 'pipeline_error' ) ) {
			return 'INSERT INTO mp_tabela_ktorej_nie_ma (id) VALUES (1)';
		}

		return $zapytanie;
	},
	99
);

$wpdb->suppress_errors( true );
$nw_logger->log_failure( $nw_dzial, MP_Result::fail( 'Awaria testowa', array(), 'test_awarii' ), nw_kontekst() );
$wpdb->suppress_errors( false );

$GLOBALS['mp_nw_psuj'] = false;

nw_ok(
	$GLOBALS['mp_nw_poczta'] > 0,
	'(zalozenie testu) sciezka alarmu naprawde sie wykonala',
	'prob wysylki: ' . $GLOBALS['mp_nw_poczta']
);


$nw_obcy_po = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$nw_tabela} WHERE id = %d", $nw_obcy_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

nw_ok(
	is_array( $nw_obcy_po ) && $nw_opis_obcy === (string) $nw_obcy_po['description'],
	'opis cudzego wpisu zostal nietkniety',
	is_array( $nw_obcy_po ) ? 'jest: ' . mb_substr( (string) $nw_obcy_po['description'], 0, 90 ) : 'wiersz zniknal'
);
nw_ok(
	is_array( $nw_obcy_po ) && 'test_wpis_obcy' === (string) $nw_obcy_po['action'],
	'i nadal opisuje swoje wlasne zdarzenie'
);

$nw_meta_obcy = is_array( $nw_obcy_po ) ? json_decode( (string) $nw_obcy_po['meta_json'], true ) : null;

nw_ok(
	is_array( $nw_meta_obcy ) && 'obcy' === ( $nw_meta_obcy['kto'] ?? '' ) && ! isset( $nw_meta_obcy['alarm'] ),
	'a jego metadane nie dostaly cudzego losu alarmu',
	wp_json_encode( $nw_meta_obcy )
);

/* ==================================================================== B */

$GLOBALS['mp_nw']['lines'][] = '';
$GLOBALS['mp_nw']['lines'][] = '=== B. niezmiennik, na ktorym stoi straznik ===';

/*
 * Mierzymy WPROST i NATYCHMIAST po nieudanym wstawieniu, bo tylko w tej chwili
 * czyta `insert_id` kod loggera. Czytane pozniej pokazuje juz identyfikator
 * z zapisow, ktore zdazyly sie wykonac po drodze (choćby transient bezpiecznika
 * alarmu) — i to wlasnie pomylenie tych dwoch momentow stoi za zgloszeniem
 * audytu.
 */
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$nw_tabela,
	array( 'action' => 'test_sonda_ok', 'description' => 'sonda przed awaria' )
);

$nw_sonda_id = (int) $wpdb->insert_id;

nw_ok( $nw_sonda_id > 0, 'sonda: udany zapis daje dodatni identyfikator', 'id=' . $nw_sonda_id );

$GLOBALS['mp_nw_psuj_sonde'] = true;
add_filter(
	'query',
	function ( $zapytanie ) {
		if ( ! empty( $GLOBALS['mp_nw_psuj_sonde'] ) && 0 === stripos( (string) $zapytanie, 'INSERT' ) && false !== strpos( (string) $zapytanie, 'test_sonda_zla' ) ) {
			return 'INSERT INTO mp_tabela_ktorej_nie_ma (id) VALUES (1)';
		}

		return $zapytanie;
	},
	97
);

$wpdb->suppress_errors( true );
$nw_wynik_sondy = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$nw_tabela,
	array( 'action' => 'test_sonda_zla', 'description' => 'sonda awarii' )
);
$nw_id_po_awarii = (int) $wpdb->insert_id;
$wpdb->suppress_errors( false );
$GLOBALS['mp_nw_psuj_sonde'] = false;

nw_ok( false === $nw_wynik_sondy, 'sonda: nieudany zapis zwraca false' );
nw_ok(
	0 === $nw_id_po_awarii,
	'i USTAWIA insert_id na zero — na tym stoi straznik $wpis_id <= 0',
	'insert_id=' . $nw_id_po_awarii . ' (poprzedni udany: ' . $nw_sonda_id . ')'
);

/* ==================================================================== C */

$GLOBALS['mp_nw']['lines'][] = '';
$GLOBALS['mp_nw']['lines'][] = '=== C. kontr-asercje: udany zapis dziala jak dotad ===';

delete_transient( 'mp_notify_' . $nw_dzial->get_key() );

$nw_max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$nw_tabela}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$nw_logger->log_failure( $nw_dzial, MP_Result::fail( 'Awaria testowa druga', array(), 'test_awarii_2' ), nw_kontekst() );

$nw_wpis = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare( "SELECT * FROM {$nw_tabela} WHERE id > %d AND action = %s ORDER BY id DESC LIMIT 1", $nw_max, 'pipeline_error' ),
	ARRAY_A
);

nw_ok(
	is_array( $nw_wpis ),
	'przy sprawnej bazie wpis o awarii nadal powstaje'
);
nw_ok(
	is_array( $nw_wpis ) && false !== strpos( (string) $nw_wpis['description'], 'Awaria testowa druga' ),
	'i niesie powod slowami'
);

$nw_meta = is_array( $nw_wpis ) ? json_decode( (string) $nw_wpis['meta_json'], true ) : null;

nw_ok(
	is_array( $nw_meta ) && in_array( (string) ( $nw_meta['alarm'] ?? '' ), MP_Pipeline_Logger::stany_alarmu(), true ),
	'i ma zapisany los alarmu ze slownika',
	wp_json_encode( $nw_meta['alarm'] ?? null )
);

remove_filter( 'pre_wp_mail', '__return_true', 99 );
