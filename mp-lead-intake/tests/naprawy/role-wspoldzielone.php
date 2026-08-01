<?php
/**
 * U-15 — dwie wtyczki, jedna rola: uprawnienia dostawala tylko ta aktywowana pierwsza.
 *
 * Uruchamianie: wp eval-file tests/naprawy/role-wspoldzielone.php
 *
 * `mp_manager_sprzedazy` i `mp_handlowiec` to te same slugi w obu modulach.
 * Wtyczka 1 zakladala je przez `add_role()` z gotowa lista uprawnien — a
 * `add_role()` przy ISTNIEJACEJ roli nie robi NIC i zwraca null. Jesli wiec role
 * powstaly wczesniej (bo wtyczka 3 byla aktywowana pierwsza albo witryna miala je
 * z poprzedniej instalacji), zadne z uprawnien wtyczki 1 na nie nie trafialo.
 *
 * Skutek widac golym okiem i tylko na czesci witryn: uzytkownik ma role „Handlowiec",
 * wchodzi do panelu i czyta „Brak uprawnien do podgladu leadow". Wynik zalezal od
 * KOLEJNOSCI aktywacji wtyczek, wiec przy instalacji 1 → 2 → 3 wszystko dzialalo,
 * a przy 3 → 1 juz nie. Wtyczka 3 robila to poprawnie od poczatku (`install()`
 * synchronizuje uprawnienia PO `add_role()`); wtyczka 1 — nie, mimo ze jej wlasna
 * metoda `remove()` wprost pisze, ze role sa wspoldzielone.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['rw'] = array(
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
function rw_ok( $warunek, $opis, $info = '' ) {
	if ( $warunek ) {
		++$GLOBALS['rw']['pass'];
		$GLOBALS['rw']['lines'][] = '[PASS] ' . $opis;
		return;
	}

	++$GLOBALS['rw']['fail'];
	$GLOBALS['rw']['lines'][] = '[FAIL] ' . $opis . ( '' !== $info ? ' -- ' . $info : '' );
}

$obie = class_exists( 'MP_Lead_Intake_Roles' ) && class_exists( 'MP_SW_Roles' );
rw_ok( $obie, '0: obie wtyczki definiujace te role sa zaladowane' );

if ( ! $obie ) {
	echo implode( "\n", $GLOBALS['rw']['lines'] ) . "\n";
	echo "VERDICT_HAS_FAILURES\n";
	return;
}

rw_ok(
	MP_Lead_Intake_Roles::SALES === MP_SW_Roles::ROLE_SALESMAN
		&& MP_Lead_Intake_Roles::MANAGER === MP_SW_Roles::ROLE_MANAGER,
	'0: slugi rol sa WSPOLNE dla obu wtyczek (o to w tym tescie chodzi)',
	MP_Lead_Intake_Roles::SALES . ' vs ' . MP_SW_Roles::ROLE_SALESMAN
);

/*
 * Odtwarzamy sytuacje „wtyczka 3 zalozyla role pierwsza": role istnieja, maja
 * uprawnienia mp_sw_*, nie maja zadnego uprawnienia wtyczki 1.
 */
MP_SW_Roles::install();

foreach ( array( MP_Lead_Intake_Roles::MANAGER, MP_Lead_Intake_Roles::SALES ) as $slug ) {
	$role = get_role( $slug );

	if ( $role ) {
		foreach ( MP_Lead_Intake_Roles::CAPS as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}

$handlowiec_przed = get_role( MP_Lead_Intake_Roles::SALES );
rw_ok(
	$handlowiec_przed && ! $handlowiec_przed->has_cap( 'mp_view_leads' ),
	'A0: punkt startowy — role istnieja BEZ uprawnien wtyczki 1'
);

// Aktywacja wtyczki 1 na takiej witrynie.
MP_Lead_Intake_Roles::create();

$handlowiec = get_role( MP_Lead_Intake_Roles::SALES );
$manager    = get_role( MP_Lead_Intake_Roles::MANAGER );

rw_ok(
	$handlowiec && $handlowiec->has_cap( 'mp_view_leads' ),
	'A1: handlowiec dostaje mp_view_leads mimo ze role zalozyla inna wtyczka'
);

rw_ok(
	$manager && $manager->has_cap( 'mp_view_leads' )
		&& $manager->has_cap( 'mp_manage_leads' )
		&& $manager->has_cap( 'mp_assign_leads' ),
	'A2: manager dostaje komplet trzech uprawnien wtyczki 1'
);

rw_ok(
	$handlowiec && ! $handlowiec->has_cap( 'mp_assign_leads' ),
	'A3: handlowiec NIE dostaje uprawnienia do rozdzielania leadow (to rola managera)'
);

/*
 * KONTR-ASERCJA, bez ktorej naprawa bylaby lekiem gorszym od choroby: doprowadzajac
 * role do swojego wzorca, wtyczka 1 nie ma prawa ruszyc uprawnien wtyczki 3.
 * Petla synchronizujaca chodzi po WLASNYCH uprawnieniach, nigdy po wszystkich,
 * jakie rola posiada.
 */
rw_ok(
	$handlowiec && $handlowiec->has_cap( MP_SW_Roles::CAP_HANDLE_EVENT ),
	'B1: KONTR-ASERCJA — uprawnienia wtyczki 3 zostaly nietkniete (handlowiec)',
	'brak ' . MP_SW_Roles::CAP_HANDLE_EVENT
);

rw_ok(
	$manager && $manager->has_cap( MP_SW_Roles::CAP_VIEW_TEAM ),
	'B2: KONTR-ASERCJA — uprawnienia wtyczki 3 zostaly nietkniete (manager)',
	'brak ' . MP_SW_Roles::CAP_VIEW_TEAM
);

rw_ok(
	$handlowiec && $handlowiec->has_cap( 'read' ),
	'B3: KONTR-ASERCJA — uprawnienia rdzenia WordPressa nietkniete'
);

// I odwrotnie: aktywacja wtyczki 3 po wtyczce 1 tez niczego nie gubi.
MP_SW_Roles::install();

$handlowiec_po = get_role( MP_Lead_Intake_Roles::SALES );

rw_ok(
	$handlowiec_po && $handlowiec_po->has_cap( 'mp_view_leads' )
		&& $handlowiec_po->has_cap( MP_SW_Roles::CAP_HANDLE_EVENT ),
	'C1: po aktywacji obu wtyczek w DOWOLNEJ kolejnosci rola ma komplet uprawnien'
);

echo implode( "\n", $GLOBALS['rw']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['rw']['pass'], $GLOBALS['rw']['fail'] );
echo ( 0 === $GLOBALS['rw']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
