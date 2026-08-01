<?php
/**
 * P3-G11 — 176 ciagow do tlumaczenia i ani jednego zadania o tlumaczenie.
 *
 * Uruchamianie: wp eval-file tests/naprawy/tlumaczenia-ladowane.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-G11  LP.3 nie wolala load_plugin_textdomain()
 *
 * LP.3 uzywa `__()` z domena 'mp-sales-workflow' w 176 miejscach, ale nigdzie
 * nie wolala `load_plugin_textdomain()`. LP.1 i LP.2 wolaja ja obie. Bez tego
 * wywolania WordPress nie ma skad wziac tlumaczen tej wtyczki: `__()` oddaje
 * ciag zrodlowy i wszystko WYGLADA poprawnie, bo zrodlo jest po polsku.
 * Widac to dopiero na witrynie w innym jezyku — czyli u klienta, nie u nas.
 *
 * Wtyczka obsluguje korespondencje w wielu jezykach (kolumna `lang` w tabeli
 * procesow), wiec nie jest to wymaganie teoretyczne.
 *
 * Test sprawdza ZACHOWANIE, a nie obecnosc wywolania w kodzie — skan zrodla
 * przeszedlby tez wtedy, gdyby wywolanie stalo w martwej galezi.
 *
 * PULAPKA POMIARU, warta zapisania. W WordPressie 7.0 `load_plugin_textdomain()`
 * NICZEGO JUZ NIE LADUJE: rejestruje sciezke w `$wp_textdomain_registry` przez
 * `set_custom_path()` i zwraca `true`, a samo ladowanie dzieje sie just-in-time
 * przy pierwszym `__()`. Pierwsza wersja tej sondy nasluchiwala `override_load_textdomain`
 * i nie zobaczyla nic — ani dla LP.3, ani dla LP.2, ktora wolala te funkcje od
 * dawna. Filtr byl wlasciwy dla WordPressa sprzed 6.7 i mierzyl cos, czego w tej
 * wersji nie ma. `WP_Textdomain_Registry::has()` tez nie nadaje sie na sonde:
 * oddaje `true` dla dowolnej, nigdy nierejestrowanej domeny.
 *
 * Jedynym sladem po wywolaniu jest wpis w `custom_paths` rejestru — prywatnym,
 * wiec czytanym refleksja. Sonda inwazyjna, ale mierzaca rzecz wlasciwa, bije
 * sonde elegancka mierzaca nie to.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_tl'] = array(
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
function tl_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_tl']['pass'];
		$GLOBALS['mp_tl']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_tl']['fail'];
	$GLOBALS['mp_tl']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Czyta `custom_paths` z rejestru domen — jedyny slad po load_plugin_textdomain().
 *
 * @param string $domena Domena tlumaczen.
 * @return string Sciezka albo pusty ciag, gdy domeny tam nie ma.
 */
function tl_custom_path( $domena ) {
	global $wp_textdomain_registry;

	$refleksja = new ReflectionClass( $wp_textdomain_registry );

	if ( ! $refleksja->hasProperty( 'custom_paths' ) ) {
		return '';
	}

	$wlasciwosc = $refleksja->getProperty( 'custom_paths' );
	$wlasciwosc->setAccessible( true );
	$sciezki = (array) $wlasciwosc->getValue( $wp_textdomain_registry );

	return isset( $sciezki[ $domena ] ) ? (string) $sciezki[ $domena ] : '';
}

/**
 * Usuwa wpis domeny z rejestru, zeby zmierzyc SKUTEK ponownego bootu,
 * a nie slad po starcie WordPressa.
 *
 * @param string $domena Domena tlumaczen.
 * @return void
 */
function tl_wyczysc( $domena ) {
	global $wp_textdomain_registry;

	$refleksja = new ReflectionClass( $wp_textdomain_registry );

	if ( ! $refleksja->hasProperty( 'custom_paths' ) ) {
		return;
	}

	$wlasciwosc = $refleksja->getProperty( 'custom_paths' );
	$wlasciwosc->setAccessible( true );
	$sciezki = (array) $wlasciwosc->getValue( $wp_textdomain_registry );
	unset( $sciezki[ $domena ] );
	$wlasciwosc->setValue( $wp_textdomain_registry, $sciezki );
}

$GLOBALS['mp_tl']['lines'][] = '=== A. boot rejestruje sciezke tlumaczen wtyczki ===';

tl_wyczysc( 'mp-sales-workflow' );

tl_ok(
	'' === tl_custom_path( 'mp-sales-workflow' ),
	'punkt wyjscia: rejestr nie zna sciezki tej domeny (kontrola samej sondy)',
	'zostalo=' . tl_custom_path( 'mp-sales-workflow' )
);

/*
 * Wolamy boot ponownie. `add_action()` z tym samym wywolaniem i priorytetem
 * nie duplikuje wpisu, wiec powtorzenie jest bezpieczne — a to jedyny sposob,
 * zeby zmierzyc skutek bootu, ktory w normalnym przebiegu padl przed testem.
 */
mp_sales_workflow_boot();

$tl_sciezka = tl_custom_path( 'mp-sales-workflow' );

tl_ok(
	'' !== $tl_sciezka,
	'po boot() rejestr zna sciezke tlumaczen domeny mp-sales-workflow',
	'sciezka=' . ( '' === $tl_sciezka ? 'BRAK' : $tl_sciezka )
);
tl_ok(
	false !== strpos( $tl_sciezka, 'mp-sales-workflow' ) && false !== strpos( $tl_sciezka, 'languages' ),
	'sciezka wskazuje katalog languages TEJ wtyczki, nie cudzej',
	'sciezka=' . $tl_sciezka
);

$GLOBALS['mp_tl']['lines'][] = '=== B. domena w kodzie zgadza sie ze slugiem wtyczki ===';

/*
 * Domena niezgodna ze slugiem katalogu wtyczki nie zaladuje sie nigdy, nawet
 * przy poprawnym wywolaniu — WordPress szuka pliku `<domena>-<locale>.mo`
 * w `WP_LANG_DIR/plugins/`. Blad tego rodzaju jest niewidoczny do momentu,
 * w ktorym ktos faktycznie dostarczy tlumaczenie.
 */
$tl_naglowek = get_file_data(
	WP_PLUGIN_DIR . '/mp-sales-workflow/mp-sales-workflow.php',
	array( 'TextDomain' => 'Text Domain' )
);

tl_ok(
	'mp-sales-workflow' === trim( (string) $tl_naglowek['TextDomain'] ),
	'naglowek wtyczki deklaruje domene mp-sales-workflow',
	'naglowek=' . var_export( $tl_naglowek['TextDomain'], true )
);

$GLOBALS['mp_tl']['lines'][] = '=== C. kontr-asercje: boot nadal robi to, co robil ===';

/*
 * Dolozenie wywolania na poczatku boota nie ma prawa przerwac reszty
 * rejestracji — a przerwaloby ja po cichu, gdyby np. wywolanie rzucilo
 * wyjatkiem przy braku katalogu languages.
 */
tl_ok(
	has_action( 'init', 'mp_sales_workflow_boot' ) !== false,
	'boot nadal wpiety w init'
);

$tl_eksportery = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$tl_kasowniki  = apply_filters( 'wp_privacy_personal_data_erasers', array() );

tl_ok(
	isset( $tl_eksportery['mp-sales-workflow'] ),
	'boot nadal rejestruje eksporter RODO (P3-G10)'
);
tl_ok(
	isset( $tl_kasowniki['mp-sales-workflow'] ),
	'boot nadal rejestruje kasownik RODO'
);

echo implode( "\n", $GLOBALS['mp_tl']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_tl']['pass'], $GLOBALS['mp_tl']['fail'] );
echo ( 0 === $GLOBALS['mp_tl']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
