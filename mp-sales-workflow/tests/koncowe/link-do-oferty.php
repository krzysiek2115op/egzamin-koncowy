<?php
/**
 * Test na ZYWYM WordPressie: podpisany link do oferty dziala na REALNEJ trasie.
 *
 * Uruchamianie: wp eval-file tests/koncowe/link-do-oferty.php
 *
 * Ten plik powstal, bo audyt koncowy wykryl dwa bledy, ktorych zaden istniejacy
 * test nie mogl zlapac — obydwa dlatego, ze testy sprawdzaly FUNKCJE podpisu
 * (`sign`, `verify`, `inside_uploads`), a nigdy calej drogi zadania:
 *
 *  1. `MP_SW_Download::register()` bylo wolane z wnetrza callbacka `init`
 *     (priorytet 10) i dopinalo `maybe_serve` na priorytecie 5. WordPress pomija
 *     callback dodany na priorytecie juz minietym, a `init` odpala sie raz na
 *     zadanie — wiec handler NIE WYKONYWAL SIE NIGDY. Klient klikal link
 *     z e-maila i dostawal zwykla strone.
 *  2. `resolve()` szukalo `WHERE request_id = %s OR id = %d`. Uchwytem jest
 *     UUID, a `(int) '108f3e8a-…'` to 108 — warunek trafial w CUDZA oferte
 *     i wydawal klientowi dokument innej firmy.
 *
 * Dlatego test robi dwie rzeczy, ktorych nie da sie udawac:
 *  - odpala DRUGI, swiezy proces PHP z pelnym `wp-load.php` i ustawionym `$_GET`,
 *    czyli prawdziwy cykl zadania (`init` odpala sie tam dokladnie raz),
 *  - probuje rozwiazac uchwyt UUID-podobny, ktory rzutuje sie na istniejace `id`.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_l'] = array(
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
function ml_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_l']['pass'];
		$GLOBALS['mp_l']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_l']['fail'];
	$GLOBALS['mp_l']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function ml_dump() {
	if ( empty( $GLOBALS['mp_l']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_l'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	if ( is_dir( '/scr' ) ) {
		file_put_contents( '/scr/mp-link-oferty.txt', $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	$GLOBALS['mp_l']['lines'] = array();
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
register_shutdown_function( 'ml_dump' );

global $wpdb;

$GLOBALS['mp_l']['lines'][] = '=== A. REJESTRACJA HANDLERA (blad: init 5 dopinane z wnetrza init 10) ===';

$priorytet = has_action( 'init', array( 'MP_SW_Download', 'maybe_serve' ) );
ml_ok( false !== $priorytet, 'handler pobrania jest wpiety w `init`', var_export( $priorytet, true ) );
ml_ok( 5 === $priorytet, 'handler ma priorytet 5 (przed reszta wtyczki)', var_export( $priorytet, true ) );

$glowny = file_get_contents( MP_SALES_WORKFLOW_DIR . 'mp-sales-workflow.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$boot   = strpos( (string) $glowny, 'function mp_sales_workflow_boot' );
$wywol  = strpos( (string) $glowny, 'MP_SW_Download::register();' );
ml_ok(
	false !== $wywol && false !== $boot && $wywol < $boot,
	'rejestracja wolana przy ladowaniu pliku, a NIE z wnetrza `boot()` na `init`',
	'register@' . var_export( $wywol, true ) . ' boot@' . var_export( $boot, true )
);

$GLOBALS['mp_l']['lines'][] = '';
$GLOBALS['mp_l']['lines'][] = '=== B. ROZWIAZYWANIE UCHWYTU (blad: OR id = %d wydawalo cudza oferte) ===';

$tabela = $wpdb->prefix . 'mp_ob_offers';

/*
 * Test nie moze zalezec od tego, co zostawily po sobie inne zestawy — a te
 * czyszcza tabele. Jesli nie ma gotowej oferty z uchwytem i dokumentem,
 * zakladamy WLASNE: dwa wiersze (zeby „cudza oferta" byla dosloworna) i dwa
 * male, prawdziwe pliki PDF w katalogu wysylek. Sprzatamy je na koncu.
 */
$uploads   = wp_get_upload_dir();
$katalog   = trailingslashit( $uploads['basedir'] ) . 'mp-offer-builder-private';
$sprzatanie = array(
	'files'  => array(),
	'offers' => array(),
);

/**
 * Zaklada minimalny, poprawny plik PDF.
 *
 * @param string $sciezka Sciezka docelowa.
 * @return bool
 */
function ml_pdf( $sciezka ) {
	$tresc = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
		. "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
		. "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
		. "trailer<</Root 1 0 R>>\n%%EOF\n";

	return false !== file_put_contents( $sciezka, $tresc ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

$oferta = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT id, request_id, pdf_path FROM {$tabela} WHERE request_id <> '' AND pdf_path <> '' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ARRAY_A
);

if ( ! is_array( $oferta ) || ! is_file( MP_SW_Download::resolve( (string) $oferta['request_id'] ) ) ) {
	wp_mkdir_p( $katalog );
	$stempel = wp_generate_password( 10, false );

	foreach ( array( 'a', 'b' ) as $ktora ) {
		$plik = $katalog . '/test-link-' . $ktora . '-' . $stempel . '.pdf';

		if ( ! ml_pdf( $plik ) ) {
			continue;
		}

		$sprzatanie['files'][] = $plik;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$tabela,
			array(
				'offer_number'  => 'OF/2999/L' . strtoupper( $ktora ) . $stempel,
				'version'       => 1,
				'lock_version'  => 1,
				'status'        => 'approved',
				'lang'          => 'pl',
				'lead_id'       => 0,
				'client_name'   => 'Test Linku ' . strtoupper( $ktora ),
				'client_email'  => 'link' . $ktora . $stempel . '@example.test',
				'currency'      => 'PLN',
				'net_grosze'    => 0,
				'vat_grosze'    => 0,
				'gross_grosze'  => 0,
				'pdf_path'      => $plik,
				'request_id'    => wp_generate_uuid4(),
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			)
		);

		if ( $wpdb->insert_id > 0 ) {
			$sprzatanie['offers'][] = (int) $wpdb->insert_id;
		}
	}

	$oferta = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT id, request_id, pdf_path FROM {$tabela} WHERE request_id <> '' AND pdf_path <> '' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
}

if ( ! is_array( $oferta ) ) {
	ml_ok( false, 'przygotowanie: jest oferta z `request_id` i `pdf_path`', $wpdb->last_error );
	ml_dump();
	return;
}

$id_oferty = (int) $oferta['id'];
$uchwyt    = (string) $oferta['request_id'];

$plik_wlasciwy = MP_SW_Download::resolve( $uchwyt );
ml_ok( '' !== $plik_wlasciwy, 'wlasny uchwyt (UUID) rozwiazuje sie na plik oferty', $uchwyt );
ml_ok(
	'' !== $plik_wlasciwy && basename( $plik_wlasciwy ) === basename( (string) $oferta['pdf_path'] ),
	'rozwiazany plik nalezy do TEJ oferty, nie do jakiejkolwiek innej',
	$plik_wlasciwy . ' vs ' . $oferta['pdf_path']
);

/*
 * Uchwyt PODROBIONY: ma ksztalt UUID, ale rzutowanie na int daje `id` INNEJ
 * oferty. Taki uchwyt NIE ISTNIEJE w kolumnie `request_id`, wiec jedyna
 * poprawna odpowiedz to „nie ma takiego pliku". Przed poprawka zwracal PDF
 * oferty o tym `id` — czyli dokument innej firmy.
 */
$cudza = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare( "SELECT id FROM {$tabela} WHERE id <> %d AND pdf_path <> '' ORDER BY id DESC LIMIT 1", $id_oferty ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
ml_ok( $cudza > 0 && $cudza !== $id_oferty, 'jest w bazie DRUGA, cudza oferta z plikiem (cel testu)', (string) $cudza );

$podrobiony = $cudza . 'f3e8a12-0000-4000-8000-000000000000';
$istnieje   = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare( "SELECT COUNT(*) FROM {$tabela} WHERE request_id = %s", $podrobiony ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
ml_ok( 0 === $istnieje, 'uchwyt kontrolny naprawde nie istnieje w bazie', $podrobiony );

$wyciek = MP_SW_Download::resolve( $podrobiony );
ml_ok(
	'' === $wyciek,
	'UUID-podobny uchwyt rzutujacy sie na cudze `id` NIE wydaje pliku',
	'zwrocono: ' . $wyciek
);

// Uchwyt czysto liczbowy nadal ma prawo dzialac — kontrakt `url()` na to pozwala.
$po_id = MP_SW_Download::resolve( (string) $id_oferty );
ml_ok( '' !== $po_id || '' === $plik_wlasciwy, 'uchwyt liczbowy nadal rozwiazuje sie po `id`', (string) $id_oferty );

$zrodlo = file_get_contents( MP_SALES_WORKFLOW_DIR . 'includes/class-mp-sw-download.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
ml_ok( false === strpos( (string) $zrodlo, 'OR id = %d' ), 'w zapytaniach nie ma juz `OR id = %d`' );

$GLOBALS['mp_l']['lines'][] = '';
$GLOBALS['mp_l']['lines'][] = '=== C. REALNA TRASA ZADANIA (swiezy proces, pelny wp-load) ===';

if ( ! MP_SW_Download::available() ) {
	ml_ok( false, 'MP_SW_LINK_KEY zdefiniowany (bez niego endpoint jest wylaczony z zalozenia)' );
	ml_dump();
	return;
}

$adres = MP_SW_Download::url( $uchwyt );
ml_ok( '' !== $adres, 'podpisany adres wygenerowany' );

$czesci = array();
parse_str( (string) wp_parse_url( $adres, PHP_URL_QUERY ), $czesci );
$termin = isset( $czesci[ MP_SW_Download::ARG_EXPIRES ] ) ? (int) $czesci[ MP_SW_Download::ARG_EXPIRES ] : 0;
$podpis = isset( $czesci[ MP_SW_Download::ARG_SIGNATURE ] ) ? (string) $czesci[ MP_SW_Download::ARG_SIGNATURE ] : '';

$php = defined( 'PHP_BINARY' ) ? PHP_BINARY : '';
$run = function_exists( 'shell_exec' ) && '' !== $php && is_executable( $php );

if ( ! $run ) {
	// Lepiej powiedziec „nie sprawdzono" niz zaliczyc PASS bez dowodu.
	$GLOBALS['mp_l']['lines'][] = '  [POMINIETO] brak dostepu do binarki PHP — realnej trasy NIE zweryfikowano';
} else {
	$rozruch = get_temp_dir() . 'mp-sw-trasa-' . wp_generate_password( 8, false ) . '.php';

	// Skrypt udaje zadanie GET do strony glownej z parametrami linku.
	$tresc = "<?php\n"
		. "\$_SERVER['REQUEST_METHOD'] = 'GET';\n"
		. "\$_SERVER['HTTP_HOST']      = " . var_export( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), true ) . ";\n"
		. "\$_SERVER['REQUEST_URI']    = '/?' . getenv('MP_SW_QS');\n"
		. "\$_SERVER['REMOTE_ADDR']    = '203.0.113.77';\n"
		. "parse_str( (string) getenv('MP_SW_QS'), \$_GET );\n"
		. "require " . var_export( ABSPATH . 'wp-load.php', true ) . ";\n"
		. "echo 'HANDLER_NIE_ZADZIALAL';\n";

	file_put_contents( $rozruch, $tresc ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	/**
	 * Odpala swieze zadanie i zwraca poczatek odpowiedzi.
	 *
	 * @param string $qs      Ciag zapytania.
	 * @param string $rozruch Sciezka skryptu rozruchowego.
	 * @param string $php     Binarka PHP.
	 * @return string
	 */
	$zadanie = function ( $qs, $rozruch, $php ) {
		$cmd = 'MP_SW_QS=' . escapeshellarg( $qs ) . ' ' . escapeshellcmd( $php ) . ' ' . escapeshellarg( $rozruch ) . ' 2>&1';
		return (string) shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	};

	$qs_ok = http_build_query(
		array(
			MP_SW_Download::ARG_HANDLE    => $uchwyt,
			MP_SW_Download::ARG_EXPIRES   => $termin,
			MP_SW_Download::ARG_SIGNATURE => $podpis,
		)
	);

	$odp = $zadanie( $qs_ok, $rozruch, $php );

	ml_ok(
		false === strpos( $odp, 'HANDLER_NIE_ZADZIALAL' ),
		'zadanie z poprawnym podpisem zostalo PRZECHWYCONE przez handler',
		substr( $odp, 0, 160 )
	);
	ml_ok(
		0 === strpos( $odp, '%PDF' ),
		'odpowiedz to plik PDF, a nie strona WordPressa',
		substr( $odp, 0, 60 )
	);

	// Podmieniony podpis: handler ma zadzialac, ale odmowic.
	$qs_zly = http_build_query(
		array(
			MP_SW_Download::ARG_HANDLE    => $uchwyt,
			MP_SW_Download::ARG_EXPIRES   => $termin,
			MP_SW_Download::ARG_SIGNATURE => str_repeat( 'a', strlen( $podpis ) ),
		)
	);
	$odp_zly = $zadanie( $qs_zly, $rozruch, $php );

	ml_ok(
		false === strpos( $odp_zly, 'HANDLER_NIE_ZADZIALAL' ),
		'zadanie z podmienionym podpisem tez trafia do handlera',
		substr( $odp_zly, 0, 160 )
	);
	ml_ok(
		0 !== strpos( $odp_zly, '%PDF' ),
		'podmieniony podpis NIE dostaje pliku',
		substr( $odp_zly, 0, 60 )
	);

	// Podrobiony uchwyt z WLASNYM, poprawnym podpisem — tak wygladalby wyciek.
	$qs_wyciek = http_build_query(
		array(
			MP_SW_Download::ARG_HANDLE    => $podrobiony,
			MP_SW_Download::ARG_EXPIRES   => $termin,
			MP_SW_Download::ARG_SIGNATURE => MP_SW_Download::sign( $podrobiony, $termin ),
		)
	);
	$odp_wyciek = $zadanie( $qs_wyciek, $rozruch, $php );

	ml_ok(
		0 !== strpos( $odp_wyciek, '%PDF' ),
		'poprawnie podpisany uchwyt cudzej oferty NIE wydaje pliku (klucz testu na wyciek)',
		substr( $odp_wyciek, 0, 60 )
	);

	unlink( $rozruch ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

// Sprzatanie po wlasnym fixture — test nie ma prawa zostawiac smieci.
foreach ( $sprzatanie['offers'] as $id_do_usuniecia ) {
	$wpdb->delete( $tabela, array( 'id' => $id_do_usuniecia ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

foreach ( $sprzatanie['files'] as $plik_do_usuniecia ) {
	if ( is_file( $plik_do_usuniecia ) ) {
		unlink( $plik_do_usuniecia ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

ml_dump();
