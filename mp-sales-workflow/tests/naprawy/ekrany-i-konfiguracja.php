<?php
/**
 * U-4, U-5, U-6 — trzy rzeczy, ktorych czlowiek nie mogl ani zobaczyc, ani zmienic.
 *
 * Uruchamianie: wp eval-file tests/naprawy/ekrany-i-konfiguracja.php
 *
 * U-4. Reguly rabatowe byly zaszyte w `const RULES` Dzialu 5 wtyczki 2. Zmiana
 * progu — decyzja handlowa, nie techniczna — wymagala edycji pliku wtyczki,
 * wydania nowej wersji i wgrania jej na produkcje. Wszystko po to, zeby partner
 * dostawal 12% zamiast 10%.
 *
 * Najwazniejsza czesc naprawy nie jest ekranem, tylko WERSJA. Kazda oferta
 * zapisuje przy sobie `rules_version`, zeby dalo sie pozniej odpowiedziec, dlaczego
 * ma taki rabat. Gdyby wersja zostala ta sama po zmianie progow, dwie oferty
 * z identycznym znacznikiem mialyby rozne rabaty — i znacznik przestalby cokolwiek
 * znaczyc. Kazdy zapis ustawien nadaje wiec NOWA wersje.
 *
 * U-5. Pulpit procesow byl JEDEN dla wszystkich rol i roznil sie wylacznie tym,
 * ile wierszy pokazuje. Manager sprzedazy dostawal dluzsza liste tego samego, co
 * handlowiec — a odpowiada za co innego: za rozlozenie pracy w zespole i za
 * terminy, ktorych nikt nie dotrzymal. Musial przeliczac liste recznie.
 *
 * U-6. Zlecenie mowi o kierowaniu zgloszenia na wlasciwy RYNEK, a pole formularza
 * nazywalo sie „Kraj". To ta sama rzecz — kolumna `country` jest jedynym
 * wyznacznikiem rynku w calym procesie — tylko nazwa opisywala format wartosci
 * zamiast tego, do czego sluzy.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['eik'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $info    Kontekst przy porazce.
 * @return void
 */
function eik_ok( $warunek, $opis, $info = '' ) {
	if ( $warunek ) {
		++$GLOBALS['eik']['pass'];
		$GLOBALS['eik']['lines'][] = '[PASS] ' . $opis;
		return;
	}

	++$GLOBALS['eik']['fail'];
	$GLOBALS['eik']['lines'][] = '[FAIL] ' . $opis . ( '' !== $info ? ' -- ' . $info : '' );
}

$komplet = class_exists( 'MP_OB_Settings' ) && class_exists( 'MP_SW_Admin' ) && class_exists( 'MP_Lead_Intake_Form' );
eik_ok( $komplet, '0: wszystkie trzy wtyczki zaladowane' );

if ( ! $komplet ) {
	echo implode( "\n", $GLOBALS['eik']['lines'] ) . "\n";
	echo "VERDICT_HAS_FAILURES\n";
	return;
}

/* ==================================================================== A */
// U-4: reguly rabatowe z ustawien, z zachowana domyslka.

delete_option( MP_OB_Settings::OPTION );

$wbudowane = MP_OB_Settings::rules();
$wersja_0  = MP_OB_Settings::rules_version();

eik_ok(
	$wbudowane === MP_OB_D5_Agent_Discount_Rules::RULES,
	'A1: bez ustawien obowiazuja reguly WBUDOWANE (pusta konfiguracja to nie brak rabatow)'
);

eik_ok(
	MP_OB_D5_Agent_Discount_Rules::RULES_VERSION === $wersja_0,
	'A2: bez ustawien obowiazuje wersja wbudowana',
	'wersja=' . $wersja_0
);

$wynik = MP_OB_Settings::validate(
	array(
		array( 'wariant' => 'partner', 'min_qty' => 25, 'percent' => 12 ),
		array( 'wariant' => '', 'min_qty' => '', 'percent' => '' ),
	)
);

eik_ok( empty( $wynik['errors'] ), 'A3: poprawny prog przechodzi walidacje', implode( ' | ', $wynik['errors'] ) );

eik_ok(
	isset( $wynik['rules'][0]['rule_id'] ) && 'R-00' === $wynik['rules'][0]['rule_id'] && 0 === (int) $wynik['rules'][0]['min_qty'],
	'A4: regula domyslna R-00 jest dokladana ZAWSZE i nie da sie jej usunac'
);

update_option(
	MP_OB_Settings::OPTION,
	array( 'version' => 'cfg-test-1', 'rules' => $wynik['rules'] )
);

eik_ok(
	MP_OB_Settings::rules() === $wynik['rules'] && 'cfg-test-1' === MP_OB_Settings::rules_version(),
	'A5: U-4 — zapisane reguly obowiazuja zamiast wbudowanych'
);

eik_ok(
	MP_OB_D5_Agent_Discount_Rules::obowiazujace() === $wynik['rules'],
	'A6: U-4 — Dzial 5 liczy rabat wg regul z ustawien, nie ze stalej'
);

eik_ok(
	MP_OB_D5_Agent_Discount_Rules::wersja() !== MP_OB_D5_Agent_Discount_Rules::RULES_VERSION,
	'A7: U-4 — zmiana regul zmienia rules_version (inaczej ten sam znacznik opisywalby dwa rozne rabaty)',
	'wersja=' . MP_OB_D5_Agent_Discount_Rules::wersja()
);

// KONTR-ASERCJE: wartosci, ktore nie maja prawa wejsc do slownika.
foreach (
	array(
		'rabat 100%'          => array( 'wariant' => 'partner', 'min_qty' => 10, 'percent' => 100 ),
		'rabat ujemny'        => array( 'wariant' => 'partner', 'min_qty' => 10, 'percent' => -5 ),
		'nieznany wariant'    => array( 'wariant' => 'zlota_rybka', 'min_qty' => 10, 'percent' => 5 ),
		'prog zerowy'         => array( 'wariant' => 'partner', 'min_qty' => 0, 'percent' => 5 ),
	) as $opis => $wiersz
) {
	$zly = MP_OB_Settings::validate( array( $wiersz ) );

	eik_ok(
		! empty( $zly['errors'] ) && 1 === count( $zly['rules'] ),
		'A8: KONTR-ASERCJA — ' . $opis . ' zostaje odrzucony',
		'bledow=' . count( $zly['errors'] ) . ', regul=' . count( $zly['rules'] )
	);
}

delete_option( MP_OB_Settings::OPTION );

eik_ok(
	MP_OB_Settings::rules() === MP_OB_D5_Agent_Discount_Rules::RULES,
	'A9: „Przywroc wbudowane" kasuje opcje, wiec progi znow ida za wtyczka'
);

/* ==================================================================== B */
// U-5: manager ma wlasny widok, handlowiec nie ma go po co ogladac.

$wiersze = array(
	array( 'status' => MP_Sales_Workflow_DB::STATUS_NEW, 'assigned_user_id' => 0, 'sla_due_at' => '2020-01-01 00:00:00' ),
	array( 'status' => MP_Sales_Workflow_DB::STATUS_ASSIGNED, 'assigned_user_id' => 1, 'sla_due_at' => '2020-01-01 00:00:00' ),
	array( 'status' => MP_Sales_Workflow_DB::STATUS_WON, 'assigned_user_id' => 1, 'sla_due_at' => '2020-01-01 00:00:00' ),
);

$mgr = get_user_by( 'login', 'eik_manager' );

if ( $mgr instanceof WP_User ) {
	wp_delete_user( (int) $mgr->ID );
}

$mgr_id = (int) wp_insert_user(
	array(
		'user_login' => 'eik_manager',
		'user_pass'  => wp_generate_password( 16 ),
		'user_email' => 'eik_manager@example.test',
		'role'       => MP_SW_Roles::ROLE_MANAGER,
	)
);

$sal = get_user_by( 'login', 'eik_handlowiec' );

if ( $sal instanceof WP_User ) {
	wp_delete_user( (int) $sal->ID );
}

$sal_id = (int) wp_insert_user(
	array(
		'user_login' => 'eik_handlowiec',
		'user_pass'  => wp_generate_password( 16 ),
		'user_email' => 'eik_handlowiec@example.test',
		'role'       => MP_SW_Roles::ROLE_SALESMAN,
	)
);

eik_ok( $mgr_id > 0 && $sal_id > 0, 'B0: konta testowe zalozone' );

wp_set_current_user( $mgr_id );
ob_start();
$narysowane = MP_SW_Admin::podsumowanie_zespolu( $wiersze );
$html_mgr   = (string) ob_get_clean();

eik_ok( $narysowane, 'B1: U-5 — manager dostaje podsumowanie zespolu' );

eik_ok(
	false !== strpos( $html_mgr, 'Obciążenie handlowców' ),
	'B2: U-5 — podsumowanie pokazuje rozlozenie pracy w zespole'
);

eik_ok(
	false !== strpos( wp_strip_all_tags( $html_mgr ), 'Po terminie SLA: 2' ),
	'B3: U-5 — po terminie licza sie tylko procesy OTWARTE (2 z 3; wygrany nie jest zalegloscia)',
	'HTML=' . wp_strip_all_tags( $html_mgr )
);

eik_ok(
	false !== strpos( $html_mgr, 'bez właściciela' ),
	'B4: U-5 — proces bez wlasciciela jest widoczny osobno, a nie ginie w liczniku'
);

wp_set_current_user( $sal_id );
ob_start();
$dla_handlowca = MP_SW_Admin::podsumowanie_zespolu( $wiersze );
$html_sal      = (string) ob_get_clean();

eik_ok(
	! $dla_handlowca && '' === trim( $html_sal ),
	'B5: KONTR-ASERCJA — handlowiec nie oglada podsumowania zespolu (to powtorzony licznik jego wlasnej listy)'
);

/* ==================================================================== C */
// U-6: pole nazywa sie tym, do czego sluzy.

$formularz = (string) file_get_contents( WP_PLUGIN_DIR . '/mp-lead-intake/includes/class-mp-form.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

eik_ok(
	false !== strpos( $formularz, "esc_html_e( 'Rynek (kraj klienta)', 'mp-lead-intake' )" ),
	'C1: U-6 — formularz pyta o RYNEK, a nie o sam „kraj"'
);

$ekran = (string) file_get_contents( WP_PLUGIN_DIR . '/mp-lead-intake/includes/admin/class-mp-admin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

eik_ok(
	false !== strpos( $ekran, "esc_html__( 'Rynek', 'mp-lead-intake' )" ),
	'C2: U-6 — ekran leadow nazywa te kolumne tak samo jak formularz'
);

wp_delete_user( $mgr_id );
wp_delete_user( $sal_id );

echo implode( "\n", $GLOBALS['eik']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['eik']['pass'], $GLOBALS['eik']['fail'] );
echo ( 0 === $GLOBALS['eik']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
