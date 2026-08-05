<?php
/**
 * U-3 i U-2 — leadow nie dalo sie nigdzie zobaczyc, a punktacji tym bardziej.
 *
 * Uruchamianie: wp eval-file tests/naprawy/ekran-leadow.php
 *
 * U-3. Wtyczka 1 nie rejestrowala ZADNEGO ekranu panelu — w calym module nie bylo
 * ani jednego `add_menu_page()`. Zgloszenia z formularza ladowaly w BD-3 i tam
 * zostawaly: zeby je zobaczyc, trzeba bylo wejsc do bazy. Jednoczesnie wtyczka od
 * poczatku zakladala dwie role i trzy uprawnienia (`mp_view_leads`,
 * `mp_manage_leads`, `mp_assign_leads`, class-mp-roles.php) — nadawane nikomu do
 * niczego, bo nie bylo ekranu, ktory by o nie zapytal.
 *
 * U-2. Punktacja leada liczy sie przy kazdym zgloszeniu (MP_Lead_Scoring::calculate,
 * ponownie po weryfikacji VAT w tle) i trafia do kolumny `score`. Nie pokazywal jej
 * zaden ekran — ani ten, ktorego nie bylo tutaj, ani pulpit procesow wtyczki 3, ani
 * lista ofert wtyczki 2. Zlecenie wymienia scoring jako element kwalifikacji leada;
 * liczba, ktorej nikt nie widzi, nie kwalifikuje niczego.
 *
 * Oba ustalenia zamyka jeden ekran, bo maja jedna przyczyne: dane byly, brakowalo
 * miejsca, w ktorym czlowiek moze na nie spojrzec.
 *
 * WIDOK ZALEZY OD ROLI. Handlowiec widzi WLASNE leady, manager i administrator —
 * wszystkie. To nie jest ozdobnik: bez tego ekran „dla wszystkich" pokazywalby
 * kazdemu handlowcowi cudze firmy, adresy i telefony, czyli dane osobowe klientow
 * spoza jego zakresu obslugi.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['el'] = array(
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
function el_ok( $warunek, $opis, $info = '' ) {
	if ( $warunek ) {
		++$GLOBALS['el']['pass'];
		$GLOBALS['el']['lines'][] = '[PASS] ' . $opis;
		return;
	}

	++$GLOBALS['el']['fail'];
	$GLOBALS['el']['lines'][] = '[FAIL] ' . $opis . ( '' !== $info ? ' -- ' . $info : '' );
}

/**
 * Zaklada uzytkownika o zadanej roli.
 *
 * @param string $login Login.
 * @param string $role  Rola.
 * @return int
 */
function el_user( $login, $role ) {
	$istnieje = get_user_by( 'login', $login );

	if ( $istnieje instanceof WP_User ) {
		wp_delete_user( (int) $istnieje->ID );
	}

	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 16 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);

	return is_wp_error( $id ) ? 0 : (int) $id;
}

/**
 * Rysuje ekran leadow jako wskazany uzytkownik i zwraca HTML.
 *
 * WSZYSTKIE STRONY, NIE PIERWSZA.
 *
 * Wersja sprzed tej poprawki renderowala jeden ekran i szukala zasianych leadow
 * w jego HTML — czyli milczaco zakladala, ze cala tabela miesci sie na jednej
 * stronie. Ekran sortuje `score DESC, id DESC` i pokazuje 25 wierszy naraz, wiec
 * gdy w bazie uzbieralo sie 25 leadow z punktacja wyzsza niz zasiana, lead
 * „Beta" wypadal na strone druga i asercja „manager widzi WSZYSTKIE leady"
 * zaczynala oskarzac kod o blad, ktorego nie ma. Test przeszedl przez wiele
 * wydan wylacznie dlatego, ze baza byla mala.
 *
 * Kryterium odbioru mowi „manager widzi leady wszystkich handlowcow" i nic nie
 * mowi o tym, na ktorej stronie — wiec test chodzi po wszystkich.
 *
 * @param int $user_id Uzytkownik.
 * @return string HTML sklejony ze wszystkich stron listy.
 */
function el_render( $user_id ) {
	wp_set_current_user( $user_id );

	$strony = 1;
	$dane   = MP_Lead_Intake_Admin::fetch( 1 );
	$razem  = isset( $dane['razem'] ) ? (int) $dane['razem'] : 0;

	if ( $razem > 0 && defined( 'MP_Lead_Intake_Admin::PER_PAGE' ) ) {
		$strony = (int) ceil( $razem / MP_Lead_Intake_Admin::PER_PAGE );
	} elseif ( $razem > 0 ) {
		$strony = (int) ceil( $razem / 25 );
	}

	// Bezpiecznik: test ma padac na tresci, a nie wisiec na rozrosnietej bazie.
	$strony = max( 1, min( $strony, 40 ) );
	$html   = '';

	$paged_pierwotne = isset( $_GET['paged'] ) ? $_GET['paged'] : null; // phpcs:ignore WordPress.Security.NonceVerification

	for ( $i = 1; $i <= $strony; $i++ ) {
		$_GET['paged'] = $i;

		ob_start();
		MP_Lead_Intake_Admin::render();
		$html .= (string) ob_get_clean();
	}

	if ( null === $paged_pierwotne ) {
		unset( $_GET['paged'] );
	} else {
		$_GET['paged'] = $paged_pierwotne;
	}

	return $html;
}

$klasa_jest = class_exists( 'MP_Lead_Intake_Admin' );
el_ok( $klasa_jest, 'A1: wtyczka 1 ma klase ekranu panelu' );

if ( ! $klasa_jest ) {
	echo implode( "\n", $GLOBALS['el']['lines'] ) . "\n";
	echo "\n----- PASS: {$GLOBALS['el']['pass']} / FAIL: {$GLOBALS['el']['fail']} -----\n";
	echo "VERDICT_HAS_FAILURES\n";
	return;
}

$leads_t = MP_Lead_Intake_DB::leads_table();

/**
 * Kasuje leady tego testu.
 *
 * Sprzatamy PRZED i PO. Przed — bo dedup po (kraj, NIP) odrzucilby zasiew.
 * Po — bo unikalnosc firmy jest wspolna dla calej bazy, wiec wiersz zostawiony
 * tutaj wywala CUDZY test, ktory akurat uzywa tego samego numeru. Dokladnie tak
 * sie stalo: leftover z tego pliku wylozyl `dedup-bez-odczytu.php` na NIP-ie
 * 5252248481, a wygladalo to na regresje w Dziale 7.
 *
 * @return void
 */
function el_sprzataj() {
	global $wpdb;

	$wpdb->query( "DELETE FROM " . MP_Lead_Intake_DB::leads_table() . " WHERE email LIKE 'el-%@example.test'" ); // phpcs:ignore WordPress.DB
}

el_sprzataj();

/*
 * Role sa WSPOLDZIELONE z wtyczka 3, a ta zaklada je pod tymi samymi slugami.
 * Doprowadzamy je do stanu po aktywacji wtyczki 1 — inaczej test ekranu mierzylby
 * nie ekran, tylko to, ktora wtyczka byla aktywowana pierwsza. Samo zderzenie
 * slugow ma wlasny test: tests/naprawy/role-wspoldzielone.php.
 */
MP_Lead_Intake_Roles::create();

$mgr = el_user( 'el_manager', MP_Lead_Intake_Roles::MANAGER );
$h_a = el_user( 'el_handlowiec_a', MP_Lead_Intake_Roles::SALES );
$h_b = el_user( 'el_handlowiec_b', MP_Lead_Intake_Roles::SALES );

el_ok( $mgr > 0 && $h_a > 0 && $h_b > 0, 'A2: konta testowe zalozone (manager + 2 handlowcow)' );

// Dwa leady o WYRAZNIE roznej punktacji, kazdy u innego handlowca.
$lead_a = MP_Lead_Intake_DB::insert_lead(
	array(
		'company_name' => 'Alfa Wysoka Punktacja',
		'nip'          => '5252248481',
		'email'        => 'el-alfa@example.test',
		'country'      => 'PL',
		'segment'      => 'IT',
		'score'        => 87,
		'status'       => 'new',
		'vat_status'   => 'valid',
		'salesman_id'  => $h_a,
		'created_at'   => current_time( 'mysql', true ),
	)
);

$lead_b = MP_Lead_Intake_DB::insert_lead(
	array(
		'company_name' => 'Beta Niska Punktacja',
		'nip'          => '1234563218',
		'email'        => 'el-beta@example.test',
		'country'      => 'PL',
		'segment'      => 'roboty',
		'score'        => 12,
		'status'       => 'new',
		'vat_status'   => 'pending',
		'salesman_id'  => $h_b,
		'created_at'   => current_time( 'mysql', true ),
	)
);

el_ok( $lead_a > 0 && $lead_b > 0, 'A3: dwa leady zasiane w BD-3', 'a=' . $lead_a . ', b=' . $lead_b );

/* ==================================================================== B */
// Ekran istnieje i pokazuje leady.

$html_mgr = el_render( $mgr );

el_ok(
	false !== strpos( $html_mgr, 'Alfa Wysoka Punktacja' ) && false !== strpos( $html_mgr, 'Beta Niska Punktacja' ),
	'B1: manager widzi WSZYSTKIE leady',
	'dlugosc HTML=' . strlen( $html_mgr )
);

// U-2: punktacja na ekranie. Nie sama liczba gdziekolwiek — kolumna z nazwa,
// zeby dalo sie odczytac, CO ta liczba znaczy.
el_ok(
	false !== strpos( $html_mgr, '>87<' ) && false !== strpos( $html_mgr, '>12<' ),
	'B2: U-2 — punktacja kazdego leada jest widoczna',
	'brak wartosci score w HTML'
);

el_ok(
	false !== strpos( $html_mgr, 'Punktacja' ),
	'B3: U-2 — kolumna punktacji jest podpisana',
	'brak naglowka kolumny'
);

// Reszta danych, po ktore czlowiek na ten ekran wchodzi.
foreach ( array( '5252248481' => 'NIP', 'el-alfa@example.test' => 'adres e-mail', 'IT' => 'segment' ) as $igla => $co ) {
	el_ok(
		false !== strpos( $html_mgr, (string) $igla ),
		'B4: ekran pokazuje ' . $co,
		'brak: ' . $igla
	);
}

/* ==================================================================== C */
// KONTR-ASERCJE: zakres widoku zalezy od roli.

$html_h_a = el_render( $h_a );

el_ok(
	false !== strpos( $html_h_a, 'Alfa Wysoka Punktacja' ),
	'C1: handlowiec widzi SWOJEGO leada'
);

el_ok(
	false === strpos( $html_h_a, 'Beta Niska Punktacja' ),
	'C2: KONTR-ASERCJA — handlowiec NIE widzi cudzego leada (dane osobowe spoza jego zakresu)'
);

$sub = el_user( 'el_subskrybent', 'subscriber' );

el_ok(
	! MP_Lead_Intake_Admin::can_view( $sub ),
	'C3: KONTR-ASERCJA — konto bez uprawnienia mp_view_leads nie ma wstepu na ekran'
);

el_ok(
	MP_Lead_Intake_Admin::can_view( $h_a ) && MP_Lead_Intake_Admin::can_view( $mgr ),
	'C4: handlowiec i manager maja wstep (uprawnienia z class-mp-roles.php nareszcie do czegos sluza)'
);

el_ok(
	MP_Lead_Intake_Admin::sees_all( $mgr ) && ! MP_Lead_Intake_Admin::sees_all( $h_a ),
	'C5: pelny wglad ma manager, nie handlowiec'
);

/* ==================================================================== D */
// Ekran jest ZAREJESTROWANY w menu, a nie tylko mozliwy do wywolania.

wp_set_current_user( $mgr );
$menu_przed = isset( $GLOBALS['menu'] ) ? $GLOBALS['menu'] : array();
MP_Lead_Intake_Admin::add_page();

$slug_w_menu = false;

foreach ( (array) $GLOBALS['menu'] as $pozycja ) {
	if ( isset( $pozycja[2] ) && MP_Lead_Intake_Admin::PAGE === $pozycja[2] ) {
		$slug_w_menu = true;
		break;
	}
}

el_ok( $slug_w_menu, 'D1: pozycja menu zarejestrowana pod slugiem ' . MP_Lead_Intake_Admin::PAGE );

$GLOBALS['menu'] = $menu_przed;

// KONTR-ASERCJA i18n (U-10): naglowki ekranu ida przez __(), a nie jako goly tekst.
$zrodlo = (string) file_get_contents( WP_PLUGIN_DIR . '/mp-lead-intake/includes/admin/class-mp-admin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

el_ok(
	false !== strpos( $zrodlo, "esc_html__( 'Punktacja', 'mp-lead-intake' )" ),
	'D2: naglowki ekranu sa przygotowane do tlumaczenia (U-10)',
	'naglowek Punktacja poza __()'
);

el_sprzataj();

echo implode( "\n", $GLOBALS['el']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['el']['pass'], $GLOBALS['el']['fail'] );
echo ( 0 === $GLOBALS['el']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
