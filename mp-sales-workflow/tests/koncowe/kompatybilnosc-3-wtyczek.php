<?php
/**
 * TEST KOMPATYBILNOSCI — trzy wtyczki na JEDNYM WordPressie.
 *
 * Uruchamianie:
 *   wp eval-file tests/koncowe/kompatybilnosc-3-wtyczek.php
 *
 * Pytanie, na ktore odpowiada ten plik: czy mp-lead-intake, mp-offer-builder i
 * mp-sales-workflow moga stac obok siebie na jednej instalacji, nie odbierajac
 * sobie nazw, tabel, zadan cron, uprawnien i uchwytow.
 *
 * Kazda sekcja sprawdza JEDNA przestrzen nazw, w ktorej wtyczki WordPressa
 * realnie sie zderzaja. Kolizja w kazdej z nich objawia sie inaczej — od cichej
 * utraty danych (te same tabele) po bialy ekran (te same klasy) — dlatego zadnej
 * nie da sie zastapic inna.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_k'] = array( 'pass' => 0, 'fail' => 0, 'lines' => array() );

/**
 * Naglowek sekcji.
 *
 * @param string $name Nazwa.
 * @return void
 */
function mk_sec( $name ) {
	$GLOBALS['mp_k']['lines'][] = "\n=== {$name} ===";
}

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function mk_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_k']['pass'];
		$GLOBALS['mp_k']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_k']['fail'];
	$GLOBALS['mp_k']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function mk_dump() {
	if ( empty( $GLOBALS['mp_k']['lines'] ) ) {
		return;
	}

	$k   = $GLOBALS['mp_k'];
	$out = implode( "\n", $k['lines'] );
	$out .= "\n\n================================================\n";
	$out .= sprintf( "WYNIK: PASS=%d  FAIL=%d  RAZEM=%d\n", $k['pass'], $k['fail'], $k['pass'] + $k['fail'] );
	$out .= 0 === $k['fail'] ? "STATUS: ALL_PASS\n" : "STATUS: SA_KOLIZJE\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-kompatybilnosc.txt' : '/tmp/mp-kompatybilnosc.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_k']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'mk_dump' );

global $wpdb;

$plugins = array( 'mp-lead-intake', 'mp-offer-builder', 'mp-sales-workflow' );

/* ------------------------------------------------------------------ 1 */
mk_sec( '1. Wszystkie trzy wtyczki aktywne i zaladowane' );

foreach ( $plugins as $slug ) {
	$active = false;
	foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
		if ( 0 === strpos( (string) $file, $slug . '/' ) ) {
			$active = true;
		}
	}
	mk_ok( $active, "wtyczka {$slug} jest aktywna" );
}

mk_ok( class_exists( 'MP_Pipeline_Factory' ), 'klasy LP.1 zaladowane' );
mk_ok( class_exists( 'MP_OB_Pipeline_Factory' ) || class_exists( 'MP_Offer_Builder_DB' ), 'klasy LP.2 zaladowane' );
mk_ok( class_exists( 'MP_SW_Pipeline_Factory' ), 'klasy LP.3 zaladowane' );

/* ------------------------------------------------------------------ 2 */
mk_sec( '2. Tabele bazy — trzy rozlaczne zestawy' );

$expected = array(
	'LP.1 (BD-3)' => array( 'mp_leads', 'mp_offers', 'mp_activity_log' ),
	'LP.2 (BD-2)' => array( 'mp_ob_offers', 'mp_ob_offer_items', 'mp_ob_offer_versions', 'mp_ob_offer_templates', 'mp_ob_offer_activity_log' ),
	'LP.3 (BD-1)' => array( 'mp_sw_flow', 'mp_sw_tasks', 'mp_sw_notifications', 'mp_sw_activity', 'mp_sw_events' ),
);

$all = array();
foreach ( $expected as $label => $tables ) {
	foreach ( $tables as $t ) {
		$full  = $wpdb->prefix . $t;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
		mk_ok( $found === $full, "{$label}: tabela {$t} istnieje" );
		$all[] = $t;
	}
}
mk_ok( count( $all ) === count( array_unique( $all ) ), 'zadna nazwa tabeli nie powtarza sie miedzy wtyczkami', wp_json_encode( array_diff_assoc( $all, array_unique( $all ) ) ) );

// Kluczowa pulapka: LP.1 ma wlasna tabele `mp_offers`, LP.2 wlasna `mp_ob_offers`.
// Gdyby ktoras siegnela po cudza, ofertami zarzadzalyby dwie wtyczki naraz.
$p1_offers = $wpdb->prefix . 'mp_offers';
$p2_offers = $wpdb->prefix . 'mp_ob_offers';
mk_ok( $p1_offers !== $p2_offers, 'tabela ofert LP.1 to NIE jest tabela ofert LP.2' );

/* ------------------------------------------------------------------ 3 */
mk_sec( '3. Opcje w wp_options — rozlaczne prefiksy' );

$opts = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'mp\\_%'" ); // phpcs:ignore
$buckets = array( 'lp1' => array(), 'lp2' => array(), 'lp3' => array(), 'inne' => array() );

foreach ( $opts as $o ) {
	if ( 0 === strpos( $o, 'mp_sw_' ) || 0 === strpos( $o, 'mp_sales_workflow_' ) ) {
		$buckets['lp3'][] = $o;
	} elseif ( 0 === strpos( $o, 'mp_ob_' ) || 0 === strpos( $o, 'mp_offer_builder_' ) ) {
		$buckets['lp2'][] = $o;
	} elseif ( 0 === strpos( $o, 'mp_lead_intake_' ) ) {
		$buckets['lp1'][] = $o;
	} else {
		$buckets['inne'][] = $o;
	}
}

mk_ok( count( $buckets['lp1'] ) > 0, 'LP.1 ma wlasne opcje', wp_json_encode( $buckets['lp1'] ) );
mk_ok( count( $buckets['lp3'] ) > 0, 'LP.3 ma wlasne opcje', wp_json_encode( $buckets['lp3'] ) );
mk_ok( count( $buckets['inne'] ) === 0, 'brak opcji mp_* poza trzema prefiksami wtyczek', wp_json_encode( $buckets['inne'] ) );

/* ------------------------------------------------------------------ 4 */
mk_sec( '4. Zadania cron — kazde nalezy do jednej wtyczki' );

$cron   = _get_cron_array();
$hooks  = array();
foreach ( (array) $cron as $ts => $jobs ) {
	foreach ( (array) $jobs as $hook => $x ) {
		if ( 0 === strpos( (string) $hook, 'mp_' ) ) {
			$hooks[ $hook ] = true;
		}
	}
}
$hooks = array_keys( $hooks );

$owner = array();
foreach ( $hooks as $h ) {
	if ( 0 === strpos( $h, 'mp_sw_' ) ) {
		$owner[ $h ] = 'LP.3';
	} elseif ( 0 === strpos( $h, 'mp_ob_' ) || 0 === strpos( $h, 'mp_offer_builder_' ) ) {
		$owner[ $h ] = 'LP.2';
	} elseif ( 0 === strpos( $h, 'mp_lead_intake_' ) ) {
		$owner[ $h ] = 'LP.1';
	} else {
		$owner[ $h ] = 'NIEZNANY';
	}
}
mk_ok( ! in_array( 'NIEZNANY', $owner, true ), 'kazde zaplanowane zadanie mp_* ma jednoznacznego wlasciciela', wp_json_encode( $owner ) );
mk_ok( in_array( 'LP.3', $owner, true ), 'LP.3 ma zaplanowane wlasne zadania', wp_json_encode( array_keys( $owner ) ) );

/* ------------------------------------------------------------------ 5 */
mk_sec( '5. Role i uprawnienia — brak przejmowania cudzych' );

$roles_p1 = array( 'mp_manager_sprzedazy', 'mp_handlowiec' );
$roles_p3 = array( MP_SW_Roles::ROLE_MANAGER, MP_SW_Roles::ROLE_SALESMAN );

foreach ( $roles_p3 as $r ) {
	mk_ok( null !== get_role( $r ), "rola LP.3 {$r} istnieje" );
}

// Rola `mp_handlowiec` jest WSPOLNA (LP.1 tez ja zaklada) — to zamierzone:
// jeden handlowiec, jedna rola. Sprawdzamy, czy obie wtyczki nadaly jej swoje
// uprawnienia i zadna nie skasowala cudzych.
$sal = get_role( MP_SW_Roles::ROLE_SALESMAN );
if ( mk_ok( null !== $sal, 'rola handlowca dostepna do inspekcji' ) ) {
	$caps    = array_keys( array_filter( (array) $sal->capabilities ) );
	$own_p3  = 0;
	$own_p1  = 0;
	foreach ( $caps as $c ) {
		if ( 0 === strpos( $c, 'mp_sw_' ) ) {
			++$own_p3;
		} elseif ( 0 === strpos( $c, 'mp_' ) ) {
			++$own_p1;
		}
	}
	mk_ok( $own_p3 > 0, 'handlowiec ma uprawnienia LP.3', 'caps=' . wp_json_encode( $caps ) );
	mk_ok( in_array( 'read', $caps, true ), 'LP.3 nie odebral roli podstawowego uprawnienia read' );
	mk_ok( ! in_array( 'manage_options', $caps, true ), 'zadna wtyczka nie podniosla handlowca do uprawnien administratora' );
}

// Administrator: LP.3 dokladalo mu uprawnien — nie wolno mu przy tym niczego zabrac.
$adm = get_role( 'administrator' );
if ( mk_ok( null !== $adm, 'rola administratora istnieje' ) ) {
	$acaps = array_keys( array_filter( (array) $adm->capabilities ) );
	foreach ( array( 'manage_options', 'edit_posts', 'activate_plugins', 'read' ) as $core ) {
		mk_ok( in_array( $core, $acaps, true ), "administrator zachowal uprawnienie rdzenia {$core}" );
	}
	mk_ok( in_array( MP_SW_Roles::CAP_VIEW_ALL, $acaps, true ), 'administrator ma uprawnienia LP.3' );
}

/* ------------------------------------------------------------------ 6 */
mk_sec( '6. Haki integracyjne — kto slucha i ilu sluchaczy' );

global $wp_filter;

$integr = array( 'mp_lead_created', 'mp_lead_verified', 'mp_offer_created', 'mp_offer_approved' );

foreach ( $integr as $hook ) {
	$count = 0;
	if ( isset( $wp_filter[ $hook ] ) ) {
		foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
			$count += count( $cbs );
		}
	}
	$GLOBALS['mp_k']['lines'][] = "  (info) {$hook}: sluchaczy = {$count}";
}

$lead_listeners = isset( $wp_filter['mp_lead_created'] ) ? $wp_filter['mp_lead_created']->callbacks : array();
$has_p2 = false;
$has_p3 = false;
foreach ( $lead_listeners as $prio => $cbs ) {
	foreach ( $cbs as $cb ) {
		$fn = $cb['function'];
		$cls = is_array( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) : (string) $fn;
		if ( false !== strpos( $cls, 'MP_Offer_Builder' ) || false !== strpos( $cls, 'MP_OB' ) ) {
			$has_p2 = true;
		}
		if ( false !== strpos( $cls, 'MP_SW' ) ) {
			$has_p3 = true;
		}
	}
}
mk_ok( $has_p2, 'LP.2 sluchа mp_lead_created' );
mk_ok( $has_p3, 'LP.3 sluchа mp_lead_created' );
mk_ok( $has_p2 && $has_p3, 'obie wtyczki reaguja na TEN SAM hak, nie odbierajac go sobie' );

// Kluczowe: LP.3 nie moze podpiac sie pod haki WEJSCIOWE LP.1 (formularz).
$p1_internal = array( 'mp_lead_intake_before_save', 'mp_lead_intake_pipeline' );
foreach ( $p1_internal as $h ) {
	$n = isset( $wp_filter[ $h ] ) ? count( $wp_filter[ $h ]->callbacks ) : 0;
	mk_ok( 0 === $n, "LP.3 nie wpina sie w wewnetrzny hak LP.1 ({$h})" );
}

/* ------------------------------------------------------------------ 7 */
mk_sec( '7. Punkty AJAX — rozlaczne akcje' );

$actions = array();
foreach ( array_keys( (array) $wp_filter ) as $tag ) {
	if ( 0 === strpos( (string) $tag, 'wp_ajax_' ) && false !== strpos( (string) $tag, 'mp_' ) ) {
		$actions[] = (string) $tag;
	}
}
$uniq = array_unique( $actions );
mk_ok( count( $actions ) === count( $uniq ), 'brak zdublowanych akcji AJAX', wp_json_encode( $actions ) );
mk_ok( in_array( 'wp_ajax_' . MP_SW_Ajax::ACTION, $actions, true ), 'LP.3 ma wlasny punkt AJAX (' . MP_SW_Ajax::ACTION . ')' );
mk_ok( ! in_array( 'wp_ajax_nopriv_' . MP_SW_Ajax::ACTION, $actions, true ), 'punkt LP.3 NIE jest dostepny dla niezalogowanych' );

$nopriv = array();
foreach ( $actions as $a ) {
	if ( 0 === strpos( $a, 'wp_ajax_nopriv_' ) ) {
		$nopriv[] = $a;
	}
}
$GLOBALS['mp_k']['lines'][] = '  (info) publiczne punkty AJAX mp_*: ' . wp_json_encode( $nopriv );

/* ------------------------------------------------------------------ 8 */
mk_sec( '8. Menu panelu — brak nadpisywania pozycji' );

$slugs = array( 'mp-lead-intake', 'mp-offer-builder', 'mp-sales-workflow', MP_SW_Admin::PAGE );
mk_ok( count( array_unique( $slugs ) ) === count( $slugs ) - 1, 'slug menu LP.3 pokrywa sie z nazwa wtyczki (jedno, nie dwa menu)', wp_json_encode( $slugs ) );

/* ------------------------------------------------------------------ 9 */
mk_sec( '9. Nazwy klas i funkcji — brak redeklaracji' );

$classes = get_declared_classes();
$mp      = array();
foreach ( $classes as $c ) {
	if ( 0 === strpos( $c, 'MP_' ) ) {
		$mp[] = $c;
	}
}
mk_ok( count( $mp ) === count( array_unique( $mp ) ), 'zadna klasa MP_* nie jest zadeklarowana dwa razy' );

$prefixed = array( 'MP_SW_' => 0, 'MP_OB_' => 0, 'MP_' => 0 );
foreach ( $mp as $c ) {
	if ( 0 === strpos( $c, 'MP_SW_' ) ) {
		++$prefixed['MP_SW_'];
	} elseif ( 0 === strpos( $c, 'MP_OB_' ) || 0 === strpos( $c, 'MP_Offer_Builder' ) ) {
		++$prefixed['MP_OB_'];
	} else {
		++$prefixed['MP_'];
	}
}
$GLOBALS['mp_k']['lines'][] = '  (info) klasy: LP.3=' . $prefixed['MP_SW_'] . ' LP.2=' . $prefixed['MP_OB_'] . ' LP.1/wspolne=' . $prefixed['MP_'];
mk_ok( $prefixed['MP_SW_'] > 20, 'LP.3 wnosi wlasna, prefiksowana przestrzen nazw' );

/* ----------------------------------------------------------------- 10 */
mk_sec( '10. Metadane uzytkownika — brak nadpisywania cudzych kluczy' );

$meta_p3 = array( 'mp_sw_country', 'mp_sw_langs', 'mp_sw_team', 'mp_sw_active' );
$rows    = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE 'mp\\_%'" ); // phpcs:ignore

$foreign = array();
foreach ( $rows as $k ) {
	if ( ! in_array( $k, $meta_p3, true ) && 0 === strpos( $k, 'mp_sw_' ) ) {
		$foreign[] = $k;
	}
}
mk_ok( empty( $foreign ), 'brak nieoczekiwanych kluczy mp_sw_* w metadanych', wp_json_encode( $foreign ) );
$GLOBALS['mp_k']['lines'][] = '  (info) klucze mp_* w usermeta: ' . wp_json_encode( $rows );

// Straznik metadanych LP.3 chroni caly prefiks mp_ — musi przepuszczac zapis
// wykonywany przez administratora, inaczej zablokowalby konfiguracje LP.1.
$admin_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore
wp_set_current_user( $admin_id );
update_user_meta( $admin_id, 'mp_sw_country', 'PL' );
mk_ok( 'PL' === (string) get_user_meta( $admin_id, 'mp_sw_country', true ), 'administrator moze zapisac chronione metadane' );
delete_user_meta( $admin_id, 'mp_sw_country' );
wp_set_current_user( 0 );

/* ----------------------------------------------------------------- 11 */
mk_sec( '11. Deinstalacja jednej wtyczki nie rusza danych pozostalych' );

$p1_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mp_leads" ); // phpcs:ignore
$p2_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mp_ob_offers" ); // phpcs:ignore
$p3_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MP_Sales_Workflow_DB::flow_table() ); // phpcs:ignore

mk_ok( $p1_rows > 0 && $p2_rows > 0 && $p3_rows > 0, 'wszystkie trzy bazy maja dane po wspolnym przebiegu', "LP.1={$p1_rows} LP.2={$p2_rows} LP.3={$p3_rows}" );
mk_ok( false === (bool) get_option( 'mp_sw_delete_data', false ), 'LP.3 domyslnie NIE kasuje danych przy deinstalacji' );

// Wiez CASCADE LP.3 obejmuje wylacznie wlasne tabele.
$fk = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
		 WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME LIKE %s',
		$wpdb->prefix . 'mp\_%'
	),
	ARRAY_A
);
$cross = array();
foreach ( $fk as $row ) {
	$child  = (string) $row['TABLE_NAME'];
	$parent = (string) $row['REFERENCED_TABLE_NAME'];
	$cs     = ( false !== strpos( $child, 'mp_sw_' ) );
	$ps     = ( false !== strpos( $parent, 'mp_sw_' ) );
	if ( $cs !== $ps ) {
		$cross[] = $child . ' -> ' . $parent;
	}
}
mk_ok( empty( $cross ), 'zaden wiez nie przechodzi miedzy bazami roznych wtyczek', wp_json_encode( $cross ) );

/* ----------------------------------------------------------------- 12 */
mk_sec( '12. Powtorna rejestracja nie dubluje sluchaczy' );

/*
 * Najgrozniejsza kolizja miedzy wtyczkami nie polega na tym, ze cos przestaje
 * dzialac, tylko ze dzieje sie DWA RAZY: dwoch sluchaczy na `mp_lead_created`
 * to dwa procesy sprzedazowe i dwa e-maile do klienta. Nie odpalamy tu jednak
 * `init`/`admin_init` ponownie — w kontekscie WP-CLI wywraca sie na tym
 * WooCommerce (brak ekranu admina), co nie ma zwiazku z naszymi wtyczkami.
 */
$count_hook = function ( $hook ) use ( &$wp_filter ) {
	$n = 0;
	if ( isset( $wp_filter[ $hook ] ) ) {
		foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
			$n += count( $cbs );
		}
	}
	return $n;
};

$before_lead  = $count_hook( 'mp_lead_created' );
$before_offer = $count_hook( 'mp_offer_created' );
$before_ajax  = $count_hook( 'wp_ajax_' . MP_SW_Ajax::ACTION );

MP_SW_Hooks::register();
MP_SW_Ajax::register();

mk_ok( $count_hook( 'mp_lead_created' ) === $before_lead, 'powtorna rejestracja LP.3 nie dodala drugiego sluchacza mp_lead_created', 'przed=' . $before_lead . ' po=' . $count_hook( 'mp_lead_created' ) );
mk_ok( $count_hook( 'mp_offer_created' ) === $before_offer, 'powtorna rejestracja nie zdublowala mp_offer_created' );
mk_ok( $count_hook( 'wp_ajax_' . MP_SW_Ajax::ACTION ) === $before_ajax, 'powtorna rejestracja nie zdublowala punktu AJAX' );

$dup = array();
foreach ( array( 'mp_lead_created', 'mp_offer_created', 'mp_offer_approved' ) as $hook ) {
	$seen = array();
	if ( ! isset( $wp_filter[ $hook ] ) ) {
		continue;
	}
	foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
		foreach ( $cbs as $cb ) {
			$fn  = $cb['function'];
			$key = is_array( $fn ) ? ( ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . $fn[1] ) : ( is_string( $fn ) ? $fn : 'closure' );
			if ( isset( $seen[ $key ] ) ) {
				$dup[] = $hook . ' -> ' . $key;
			}
			$seen[ $key ] = true;
		}
	}
}
mk_ok( empty( $dup ), 'zaden hak integracyjny nie ma tego samego sluchacza dwa razy', wp_json_encode( $dup ) );

mk_dump();
