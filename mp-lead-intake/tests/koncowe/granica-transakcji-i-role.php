<?php
/**
 * Test na ZYWYM WordPressie: granica transakcji i deinstalacja rol.
 *
 * Uruchamianie: wp eval-file tests/koncowe/granica-transakcji-i-role.php
 *
 * Dwa ustalenia audytu koncowego, oba dotyczace GRANICY miedzy wtyczkami:
 *
 * A. `do_action('mp_lead_created')` szlo z Dzialu 11 WEWNATRZ otwartej
 *    transakcji (transakcja trwala do konca pipeline'u). Skutki byly trzy:
 *    wtyczka 3, otwierajac wlasna transakcje, robila NIEJAWNY COMMIT naszej;
 *    nasz ROLLBACK kasowal szkic oferty w bazie wtyczki 2; a wiersz leada
 *    trzymal blokade na `uq_country_nip` przez caly czas pracy subskrybentow
 *    (render PDF, HTTP, kolejka poczty). Najgorszy przypadek: wyjatek
 *    w subskrybencie NISZCZYL leada — klient wypelnial poprawny formularz
 *    i dostawal blad 500, bo cudzy modul mial usterke.
 *
 * B. Deinstalacja wtyczki 1 kasowala role `mp_handlowiec` i
 *    `mp_manager_sprzedazy` — te same, ktorych uzywa wtyczka 3. Usuniecie
 *    samej wtyczki 1 zabieralo uprawnienia calej instalacji.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_g'] = array(
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
function mg_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_g']['pass'];
		$GLOBALS['mp_g']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_g']['fail'];
	$GLOBALS['mp_g']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik i ODTWARZA role takze po bledzie krytycznym.
 *
 * @return void
 */
function mg_dump() {
	// Test rusza definicje rol — nie ma prawa zostawic instalacji bez nich.
	if ( class_exists( 'MP_Lead_Intake_Roles' ) ) {
		MP_Lead_Intake_Roles::create();
	}

	if ( class_exists( 'MP_SW_Roles' ) ) {
		MP_SW_Roles::install();
	}

	if ( empty( $GLOBALS['mp_g']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_g'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	if ( is_dir( '/scr' ) ) {
		file_put_contents( '/scr/mp-granica.txt', $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	$GLOBALS['mp_g']['lines'] = array();
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
register_shutdown_function( 'mg_dump' );

global $wpdb;

$leads_t = MP_Lead_Intake_DB::leads_table();

$GLOBALS['mp_g']['lines'][] = '=== A. ZEPSUTY SUBSKRYBENT NIE NISZCZY LEADA ===';

/**
 * Poprawny NIP (wagi 6,5,7,2,3,4,5,6,7) — wtyczka deduplikuje leady po NIP.
 *
 * @param int $seed Ziarno.
 * @return string
 */
function mg_nip( $seed ) {
	$wagi = array( 6, 5, 7, 2, 3, 4, 5, 6, 7 );

	for ( $i = 0; $i < 200; $i++ ) {
		$baza = str_pad( (string) ( ( $seed + $i ) % 1000000000 ), 9, '0', STR_PAD_LEFT );
		$suma = 0;

		for ( $k = 0; $k < 9; $k++ ) {
			$suma += $wagi[ $k ] * (int) $baza[ $k ];
		}

		if ( 10 !== $suma % 11 ) {
			return $baza . ( $suma % 11 );
		}
	}

	return '1234563218';
}

$nip  = mg_nip( (int) ( microtime( true ) * 100 ) % 900000000 + 100000 );
$mail = 'granica-' . substr( $nip, 0, 6 ) . '@example.test';

$GLOBALS['mp_g_wybuchl'] = false;
$wybuchowy               = function () {
	$GLOBALS['mp_g_wybuchl'] = true;
	throw new RuntimeException( 'Symulacja awarii modulu ofertowego.' );
};

/*
 * Priorytet 1 — PRZED naszymi wlasnymi nasluchami. To istotne dla wartosci tego
 * testu: wtyczka 3 otwiera w swoim Dziale 8 wlasna transakcje, co w MySQL robi
 * NIEJAWNY COMMIT transakcji wtyczki 1. Subskrybent wybuchajacy PO niej mialby
 * wiec leada juz zatwierdzonego przez przypadek i test przechodzilby takze na
 * kodzie sprzed poprawki. Rzucamy zanim ktokolwiek inny dotknie bazy — wtedy
 * o przetrwaniu leada decyduje wylacznie granica naszej transakcji.
 */
add_action( 'mp_lead_created', $wybuchowy, 1 );

$ctx = new MP_Context(
	array(
		'company_name'      => 'Granica Transakcji Sp. z o.o.',
		'nip'               => $nip,
		'email'             => $mail,
		'phone'             => '+48555111333',
		'country'           => 'PL',
		'segment'           => 'roboty',
		'message'           => 'Prosze o oferte.',
		'consent_rodo'      => true,
		'consent_marketing' => false,
		'mp_nonce'          => wp_create_nonce( 'mp_lead_intake' ),
	)
);

$wyjatek = null;

try {
	MP_Pipeline_Factory::make()->run( $ctx );
} catch ( \Throwable $e ) {
	$wyjatek = $e;
}

remove_action( 'mp_lead_created', $wybuchowy, 1 );

mg_ok( true === $GLOBALS['mp_g_wybuchl'], 'A1: zepsuty subskrybent naprawde zostal wywolany' );
mg_ok( null !== $wyjatek, 'A2: wyjatek subskrybenta doszedl do wywolujacego (WordPress go nie izoluje)' );

$lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_t} WHERE nip = %s", $nip ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
mg_ok(
	is_array( $lead ),
	'A3: LEAD ZOSTAL W BAZIE mimo awarii subskrybenta (transakcja zamknieta przed emisja haka)',
	'nip: ' . $nip
);

if ( is_array( $lead ) ) {
	mg_ok( (string) $lead['email'] === $mail, 'A4: zapisany lead to ten wlasciwy' );

	$log = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( 'SELECT COUNT(*) FROM ' . MP_Lead_Intake_DB::activity_log_table() . ' WHERE lead_id = %d', (int) $lead['id'] )
	);
	mg_ok( $log > 0, 'A5: wpisy dziennika tez przetrwaly (nie zostaly wycofane)', (string) $log );
}

// Ten sam NIP raz jeszcze — blokada `uq_country_nip` nie moze byc trzymana
// przez subskrybentow, a lead nie moze sie zdublowac.
$ile_przed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_t} WHERE nip = %s", $nip ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
mg_ok( 1 === $ile_przed, 'A6: dokladnie JEDEN wiersz dla tego NIP-u', (string) $ile_przed );

$GLOBALS['mp_g']['lines'][] = '';
$GLOBALS['mp_g']['lines'][] = '=== B. DEINSTALACJA WTYCZKI 1 NIE ZABIERA ROL WTYCZCE 3 ===';

if ( ! class_exists( 'MP_SW_Roles' ) ) {
	$GLOBALS['mp_g']['lines'][] = '  [POMINIETO] wtyczka 3 nieaktywna — sekcja B wymaga obu';
} else {
	MP_SW_Roles::install();
	MP_Lead_Intake_Roles::create();

	$slug = MP_SW_Roles::ROLE_SALESMAN;
	mg_ok( null !== get_role( $slug ), 'B1: rola handlowca istnieje przed proba deinstalacji' );

	$rola_przed = get_role( $slug );
	$caps_p3    = is_object( $rola_przed ) ? array_keys( array_filter( (array) $rola_przed->capabilities ) ) : array();
	$caps_p3    = array_values( array_diff( $caps_p3, MP_Lead_Intake_Roles::CAPS ) );
	mg_ok( ! empty( $caps_p3 ), 'B2: rola ma uprawnienia SPOZA wtyczki 1 (czyli ktos jej jeszcze uzywa)', implode( ',', $caps_p3 ) );

	// Deinstalacja wtyczki 1.
	MP_Lead_Intake_Roles::remove();

	$rola_po = get_role( $slug );
	mg_ok( null !== $rola_po, 'B3: rola PRZETRWALA deinstalacje wtyczki 1', null === $rola_po ? 'rola zniknela' : 'ok' );

	if ( $rola_po ) {
		$zostalo = array_keys( array_filter( (array) $rola_po->capabilities ) );
		mg_ok(
			empty( array_intersect( $zostalo, MP_Lead_Intake_Roles::CAPS ) ),
			'B4: uprawnienia WLASNE wtyczki 1 zostaly zdjete',
			implode( ',', array_intersect( $zostalo, MP_Lead_Intake_Roles::CAPS ) )
		);
		mg_ok(
			! empty( array_intersect( $zostalo, $caps_p3 ) ),
			'B5: uprawnienia wtyczki 3 nietkniete',
			implode( ',', $zostalo )
		);
	}

	// Druga strona reguly: rola, ktorej nikt inny nie uzywa, MA zniknac —
	// inaczej po odinstalowaniu wszystkiego zostawalyby puste role-widma.
	MP_Lead_Intake_Roles::create();
	$rola_tylko_p1 = get_role( $slug );

	if ( $rola_tylko_p1 ) {
		foreach ( $caps_p3 as $cap ) {
			$rola_tylko_p1->remove_cap( $cap );
		}
	}

	MP_Lead_Intake_Roles::remove();
	mg_ok( null === get_role( $slug ), 'B6: rola uzywana WYLACZNIE przez wtyczke 1 zostaje usunieta' );

	// Odtworzenie stanu wyjsciowego (i tak powtorzone w mg_dump()).
	MP_SW_Roles::install();
	MP_Lead_Intake_Roles::create();
	mg_ok( null !== get_role( $slug ), 'B7: stan rol odtworzony po tescie' );
}

mg_dump();
