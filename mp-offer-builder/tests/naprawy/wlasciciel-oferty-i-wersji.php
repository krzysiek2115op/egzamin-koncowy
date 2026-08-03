<?php
/**
 * Audyt koncowy 1.3.8 — „brak wlasciciela = NULL" konczylo sie na naglowku.
 *
 * Uruchamianie: wp eval-file tests/naprawy/wlasciciel-oferty-i-wersji.php
 *
 * Wydanie 1.3.8 wprowadzilo w Dziale 10 zasade, ktora plik deklaruje wprost:
 * „Brak wlasciciela ma w bazie DOKLADNIE jedna reprezentacje: NULL". Zasada
 * objela naglowek oferty i nic wiecej. Audyt gleboki zglosil to samo w trzech
 * niezaleznych przebiegach — najsilniejszy sygnal, jaki daja pary modelowe.
 *
 * Trzy skutki, kazdy w innej sekcji:
 *
 *   A. Wiersz WERSJI zapisuje `get_current_user_id()` bez normalizacji, wiec
 *      zapis bez zalogowanego uzytkownika (cron, WP-CLI) wklada tam 0 — czyli
 *      „uzytkownik numer zero" — podczas gdy naglowek tej samej oferty dostaje
 *      NULL. Jedno pojecie, dwie reprezentacje, dwie tabele.
 *
 *   B. Kontrola wlasnosci nie normalizuje zera ODCZYTANEGO z bazy. Oferty
 *      zapisane przed 1.3.8 maja `created_by = 0`; warunek czyta to jako
 *      wlasciciela o numerze zero, czyli KOGOS INNEGO niz zalogowany
 *      handlowiec, i odmawia mu zapisu. Wlasnie te oferty naprawa 1.3.8 miala
 *      odblokowac.
 *
 *   C. Ta sama kontrola odmawia zapisu, gdy nie ma zalogowanego uzytkownika,
 *      a oferta ma wlasciciela. `get_current_user_id()` oddaje wtedy 0, wiec
 *      „0 !== 5" i `current_user_can()` bez uzytkownika jest falszem. Tryb
 *      cron/WP-CLI, ktory docblock kilka linii wyzej opisuje jako obslugiwany,
 *      nie moze dokonczyc zadnej oferty z wlascicielem.
 *
 * Sekcja D pilnuje tego, czego ruszac NIE WOLNO: obrona w glab przeciw IDOR ma
 * dalej odmawiac obcemu ZALOGOWANEMU uzytkownikowi.
 *
 * Sekcja E dokumentuje ustalenie ODRZUCONE — patrz komentarz przy niej.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_wl'] = array(
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
function wl_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_wl']['pass'];
		$GLOBALS['mp_wl']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_wl']['fail'];
	$GLOBALS['mp_wl']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function wl_dump() {
	if ( empty( $GLOBALS['mp_wl']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_wl'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_wl']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'wl_dump' );

/**
 * Kontekst planu zapisu (ten sam ksztalt co w plan-zapisu-dzial-10.php).
 *
 * @param array $zmiany Nadpisania.
 * @return array
 */
function wl_kontekst( array $zmiany = array() ) {
	return array_merge(
		array(
			'offer_number'   => 'OF/2026/000777',
			'version'        => 1,
			'lang'           => 'pl',
			'client'         => array(
				'name'       => 'Firma Testowa',
				'email'      => 'test@example.test',
				'nip'        => '5252248481',
				'country'    => 'PL',
				'vat_status' => 'valid',
			),
			'items'          => array( array( 'product_id' => 7, 'qty' => 3 ) ),
			'lines'          => array( array( 'unit_grosze' => 10000, 'line_grosze' => 30000 ) ),
			'line_tax_rates' => array( 23.0 ),
			'net_grosze'     => 30000,
			'vat_grosze'     => 6900,
			'gross_grosze'   => 36900,
			'currency'       => 'PLN',
			'tax_mechanism'  => 'domestic',
			'tax_rate'       => 23.0,
			'pdf'            => array( 'sha256' => str_repeat( 'a', 64 ) ),
			'request_id'     => 'req-wl-1',
			'numbering'      => array(
				'existing_offer_number' => null,
				'existing_version'      => null,
				'existing_created_by'   => null,
				'existing_lock_version' => null,
			),
			'numbering_mode' => 'new_number',
		),
		$zmiany
	);
}

/**
 * Uruchamia Agenta 10.1.
 *
 * @param array $dane Kontekst.
 * @return MP_OB_Result
 */
function wl_plan( array $dane ) {
	$agent = new MP_OB_D10_Agent_Plan();

	return $agent->run( new MP_OB_Context( $dane ) );
}

/**
 * Kod odmowy albo pusty ciag przy powodzeniu.
 *
 * @param MP_OB_Result $wynik Wynik agenta.
 * @return string
 */
function wl_kod( $wynik ) {
	return $wynik->is_ok() ? '' : (string) $wynik->get_code();
}

/**
 * Kontekst istniejacej oferty (UPDATE) o wskazanym wlascicielu.
 *
 * @param int|null $wlasciciel Wartosc `existing_created_by` z Dzialu 2.
 * @return array
 */
function wl_istniejaca( $wlasciciel ) {
	return wl_kontekst(
		array(
			'offer_id'       => 41,
			'numbering'      => array(
				'existing_offer_number' => 'OF/2026/000777',
				'existing_version'      => 1,
				'existing_created_by'   => $wlasciciel,
				'existing_lock_version' => 3,
			),
			'numbering_mode' => 'keep_number',
		)
	);
}

/* --------------------------------------------------------------------------
 * Konto zwyklego handlowca — potrzebne, bo administrator ma `manage_options`
 * i przechodzi kontrole wlasnosci z definicji. Kasowane na koncu pliku:
 * konta zostawione po testach potrafia ukryc bledy nastepnych przebiegow.
 * ------------------------------------------------------------------------ */

$wl_login = 'wl_test_handlowiec';
$wl_uid   = username_exists( $wl_login );
if ( ! $wl_uid ) {
	$wl_uid = wp_insert_user(
		array(
			'user_login' => $wl_login,
			'user_pass'  => wp_generate_password( 20 ),
			'user_email' => 'wl.test@example.test',
			'role'       => 'subscriber',
		)
	);
}
$wl_uid = (int) $wl_uid;

$wl_uzytkownik_przed = get_current_user_id();

/* ==================================================================== A */

$GLOBALS['mp_wl']['lines'][] = '=== A. wiersz wersji: brak zalogowanego = NULL, nie zero ===';

wp_set_current_user( 0 );

$a = wl_plan( wl_kontekst() );

wl_ok( $a->is_ok(), 'plan powstaje bez zalogowanego uzytkownika', 'kod=' . wl_kod( $a ) );

$dane_a  = $a->get_data();
$naglowek_a = isset( $dane_a['write_plan']['header'] ) ? $dane_a['write_plan']['header'] : array();
$wersja_a   = isset( $dane_a['write_plan']['version'] ) ? $dane_a['write_plan']['version'] : array();

wl_ok(
	array_key_exists( 'created_by', $naglowek_a ) && null === $naglowek_a['created_by'],
	'naglowek: created_by = NULL',
	'created_by=' . var_export( isset( $naglowek_a['created_by'] ) ? $naglowek_a['created_by'] : 'BRAK', true )
);

wl_ok(
	array_key_exists( 'created_by', $wersja_a ) && null === $wersja_a['created_by'],
	'wiersz wersji: created_by TEZ = NULL (to samo pojecie, ta sama reprezentacja)',
	'created_by=' . var_export( isset( $wersja_a['created_by'] ) ? $wersja_a['created_by'] : 'BRAK', true )
);

/* ==================================================================== B */

$GLOBALS['mp_wl']['lines'][] = '';
$GLOBALS['mp_wl']['lines'][] = '=== B. zero ODCZYTANE z bazy to nadal „nikt" ===';

wp_set_current_user( $wl_uid );

$b = wl_plan( wl_istniejaca( 0 ) );

wl_ok(
	'not_offer_owner' !== wl_kod( $b ),
	'oferta z created_by = 0 (sprzed 1.3.8) nie jest cudza',
	'kod=' . wl_kod( $b )
);

$dane_b     = $b->get_data();
$naglowek_b = isset( $dane_b['write_plan']['header'] ) ? $dane_b['write_plan']['header'] : array();

wl_ok(
	isset( $naglowek_b['created_by'] ) && $wl_uid === (int) $naglowek_b['created_by'],
	'i dostaje wlasciciela: zapisujacy handlowiec',
	'created_by=' . var_export( isset( $naglowek_b['created_by'] ) ? $naglowek_b['created_by'] : 'BRAK', true )
);

/* ==================================================================== C */

$GLOBALS['mp_wl']['lines'][] = '';
$GLOBALS['mp_wl']['lines'][] = '=== C. cron/WP-CLI dokancza oferte z wlascicielem ===';

wp_set_current_user( 0 );

$c = wl_plan( wl_istniejaca( $wl_uid ) );

wl_ok(
	'not_offer_owner' !== wl_kod( $c ),
	'brak zalogowanego uzytkownika NIE jest „obcym uzytkownikiem"',
	'kod=' . wl_kod( $c )
);

$dane_c     = $c->get_data();
$naglowek_c = isset( $dane_c['write_plan']['header'] ) ? $dane_c['write_plan']['header'] : array();

wl_ok(
	isset( $naglowek_c['created_by'] ) && $wl_uid === (int) $naglowek_c['created_by'],
	'a wlasciciel zostaje ten sam — cron go nie przejmuje',
	'created_by=' . var_export( isset( $naglowek_c['created_by'] ) ? $naglowek_c['created_by'] : 'BRAK', true )
);

/* ==================================================================== D */

$GLOBALS['mp_wl']['lines'][] = '';
$GLOBALS['mp_wl']['lines'][] = '=== D. KONTR-ASERCJE: obrona przeciw IDOR stoi ===';

/*
 * Sedno: poluzowanie z sekcji B i C nie moze przepuscic tego, przed czym ta
 * kontrola powstala — ZALOGOWANEGO uzytkownika siegajacego po cudza oferte.
 */

$obcy_login = 'wl_test_obcy';
$obcy_uid   = username_exists( $obcy_login );
if ( ! $obcy_uid ) {
	$obcy_uid = wp_insert_user(
		array(
			'user_login' => $obcy_login,
			'user_pass'  => wp_generate_password( 20 ),
			'user_email' => 'wl.obcy@example.test',
			'role'       => 'subscriber',
		)
	);
}
$obcy_uid = (int) $obcy_uid;

wp_set_current_user( $obcy_uid );

$d1 = wl_plan( wl_istniejaca( $wl_uid ) );

wl_ok(
	'not_offer_owner' === wl_kod( $d1 ),
	'obcy zalogowany uzytkownik dalej dostaje odmowe',
	'kod=' . wl_kod( $d1 )
);

wp_set_current_user( $wl_uid );

$d2 = wl_plan( wl_istniejaca( $wl_uid ) );

wl_ok( $d2->is_ok(), 'wlasciciel zapisuje swoja oferte', 'kod=' . wl_kod( $d2 ) );

$dane_d2     = $d2->get_data();
$naglowek_d2 = isset( $dane_d2['write_plan']['header'] ) ? $dane_d2['write_plan']['header'] : array();

wl_ok(
	isset( $naglowek_d2['created_by'] ) && $wl_uid === (int) $naglowek_d2['created_by'],
	'i pozostaje wlascicielem',
	'created_by=' . var_export( isset( $naglowek_d2['created_by'] ) ? $naglowek_d2['created_by'] : 'BRAK', true )
);

$admin = get_users(
	array(
		'role'    => 'administrator',
		'number'  => 1,
		'fields'  => 'ID',
		'orderby' => 'ID',
	)
);

if ( ! empty( $admin ) ) {
	wp_set_current_user( (int) $admin[0] );

	$d3 = wl_plan( wl_istniejaca( $wl_uid ) );

	wl_ok( $d3->is_ok(), 'administrator dalej moze zapisac cudza oferte', 'kod=' . wl_kod( $d3 ) );
}

wp_set_current_user( $wl_uid );

$d4       = wl_plan( wl_kontekst() );
$dane_d4  = $d4->get_data();
$wersja_d4 = isset( $dane_d4['write_plan']['version'] ) ? $dane_d4['write_plan']['version'] : array();

wl_ok(
	isset( $wersja_d4['created_by'] ) && $wl_uid === (int) $wersja_d4['created_by'],
	'zalogowany uzytkownik dalej trafia do wiersza wersji',
	'created_by=' . var_export( isset( $wersja_d4['created_by'] ) ? $wersja_d4['created_by'] : 'BRAK', true )
);

/* ==================================================================== E */

$GLOBALS['mp_wl']['lines'][] = '';
$GLOBALS['mp_wl']['lines'][] = '=== E. USTALENIE ODRZUCONE: „status draft ustawiany bezwarunkowo" ===';

/*
 * Audyt zglosil (2 przebiegi z 3), ze naglowek ustawia `status` na STATUS_DRAFT
 * takze na sciezce UPDATE, wiec zapis moze cofnac do szkicu oferte, ktora
 * wtyczka 3 przestawila dalej.
 *
 * To NIEPRAWDA i ponizsze asercje przechodzily PRZED jakakolwiek naprawa.
 * Kilkaset linii nizej stoi wartownik: `$update_where['status'] = STATUS_DRAFT`.
 * UPDATE fizycznie nie moze trafic w wiersz o innym statusie — a gdy nie trafi,
 * agent 10.2 konczy sie `concurrent_modification`, nie cichym sukcesem.
 * Para modelowa przeczytala budowe naglowka i orzekla o calej sciezce zapisu.
 *
 * Asercje zostaja jako straz: gdyby ktos kiedys usunal wartownika ze zdania
 * WHERE, ustalenie audytu stalo by sie prawdziwe — i wtedy ma pasc TEN test,
 * a nie klient.
 */

$zrodlo = file_get_contents( MP_OFFER_BUILDER_DIR . 'includes/pipeline/departments/class-mp-ob-department-10.php' );

wl_ok(
	is_string( $zrodlo ) && false !== strpos( $zrodlo, "\$update_where['status'] = MP_Offer_Builder_DB::STATUS_DRAFT;" ),
	'wartownik statusu w WHERE istnieje — dlatego naglowek moze ustawiac draft bezwarunkowo',
	'znaleziony=' . var_export( is_string( $zrodlo ) && false !== strpos( $zrodlo, "\$update_where['status']" ), true )
);

wl_ok(
	is_string( $zrodlo ) && false !== strpos( $zrodlo, "'concurrent_modification'" ),
	'a nietrafiony UPDATE konczy sie odmowa, nie cichym sukcesem'
);

/* ---------------------------------------------------------------- sprzatanie */

wp_set_current_user( (int) $wl_uzytkownik_przed );

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $wl_uid );
wp_delete_user( $obcy_uid );
