<?php
/**
 * Ustalenie z odbioru 1.3.12: WooCommerce wypychal handlowca i managera z panelu.
 *
 * Uruchamianie: wp eval-file tests/naprawy/rola-nie-jest-wypychana-z-panelu.php
 *
 * Znalezione NIE przez czytanie kodu, tylko przez wejscie na ekran procesow
 * prawdziwym logowaniem, na czystej instalacji z paczek wydania. Zadanie
 * `GET /wp-admin/admin.php?page=mp-sales-workflow` zalogowanym handlowcem
 * konczylo sie `302` na `/my-account/` — czyli rola stworzona przez te wtyczke
 * nie docierala do jedynego ekranu, ktory ta wtyczka dla niej robi.
 *
 * Powod jest poza nasza wtyczka: WooCommerce filtrem `woocommerce_prevent_admin_access`
 * wyrzuca z `/wp-admin/` kazdego, kto nie ma `edit_posts` ani `manage_woocommerce`.
 * Role wtyczki maja `read` i wlasne uprawnienia — i slusznie, bo handlowiec nie
 * ma powodu edytowac wpisow bloga. Skutek: na KAZDEJ instalacji zgodnej
 * z wymaganiami produktu (wtyczka 2 wymaga WooCommerce) dwie z trzech rol nie
 * mialy dostepu do wlasnych ekranow.
 *
 * Naprawa jest waska: mowimy WooCommerce „ten uzytkownik ma u nas robote
 * w panelu" wylacznie dla posiadaczy NASZYCH uprawnien. Nikomu niczego nie
 * dodajemy — `edit_posts` nadal nie maja i nadal nie zobacza wpisow.
 *
 * Testy zamiast wtyczki WooCommerce (ktorej w srodowisku regresji nie ma)
 * wolaja sam filtr — bo to on jest naszym kodem i to on ma odpowiadac poprawnie.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_wp'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $w     Warunek.
 * @param string $opis  Opis.
 * @param string $detal Szczegol przy porazce.
 * @return bool
 */
function wpn_ok( $w, $opis, $detal = '' ) {
	if ( $w ) {
		++$GLOBALS['mp_wp']['pass'];
		$GLOBALS['mp_wp']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_wp']['fail'];
	$GLOBALS['mp_wp']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function wpn_koniec() {
	if ( empty( $GLOBALS['mp_wp']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_wp'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_wp']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'wpn_koniec' );

/**
 * Zaklada uzytkownika w podanej roli i zwraca jego identyfikator.
 *
 * @param string $rola Slug roli.
 * @return int
 */
function wpn_uzytkownik( $rola ) {
	$login = 'wpn_' . substr( md5( $rola . microtime( true ) . wp_rand() ), 0, 10 );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 20 ),
			'user_email' => $login . '@example.test',
			'role'       => $rola,
		)
	);

	return is_wp_error( $id ) ? 0 : (int) $id;
}

/* ==================================================================== A */

$GLOBALS['mp_wp']['lines'][] = '=== A. role wtyczki zostaja w panelu ===';

wpn_ok(
	method_exists( 'MP_SW_Roles', 'pozwol_na_panel' ),
	'A1: wtyczka ma odpowiedz na pytanie WooCommerce o dostep do panelu'
);

if ( ! method_exists( 'MP_SW_Roles', 'pozwol_na_panel' ) ) {
	return;
}

$wpn_stary = get_current_user_id();

foreach ( array( MP_SW_Roles::ROLE_SALESMAN, MP_SW_Roles::ROLE_MANAGER ) as $wpn_rola ) {
	$wpn_id = wpn_uzytkownik( $wpn_rola );

	if ( ! wpn_ok( $wpn_id > 0, 'A2 (' . $wpn_rola . '): uzytkownik zalozony' ) ) {
		continue;
	}

	wp_set_current_user( $wpn_id );

	wpn_ok(
		false === MP_SW_Roles::pozwol_na_panel( true ),
		'A3 (' . $wpn_rola . '): NIE jest wypychany z panelu, mimo ze WooCommerce chcial',
		'odpowiedz=' . wp_json_encode( MP_SW_Roles::pozwol_na_panel( true ) )
	);

	/*
	 * Naprawa nie moze przy okazji nic dodac. Handlowiec ma widziec ekran
	 * wtyczki 3 — i nic poza tym, do czego uprawnien nie dostal.
	 */
	wpn_ok(
		! current_user_can( 'edit_posts' ),
		'A4 (' . $wpn_rola . '): i nadal nie ma prawa edytowac wpisow'
	);
	wpn_ok(
		! current_user_can( 'manage_options' ),
		'A5 (' . $wpn_rola . '): ani ustawien witryny'
	);

	wp_delete_user( $wpn_id );
}

/* ==================================================================== B */

$GLOBALS['mp_wp']['lines'][] = '';
$GLOBALS['mp_wp']['lines'][] = '=== B. kontr-asercje: nikogo innego nie wpuszczamy ===';

$wpn_klient = wpn_uzytkownik( 'subscriber' );

if ( wpn_ok( $wpn_klient > 0, 'B1: uzytkownik bez rol wtyczki zalozony' ) ) {
	wp_set_current_user( $wpn_klient );

	wpn_ok(
		true === MP_SW_Roles::pozwol_na_panel( true ),
		'B2: subskrybent zostaje wypchniety tak, jak chcial WooCommerce',
		'odpowiedz=' . wp_json_encode( MP_SW_Roles::pozwol_na_panel( true ) )
	);

	/*
	 * Filtr ODPOWIADA na cudza decyzje, a nie podejmuje wlasnej. Gdy WooCommerce
	 * nikogo nie wypycha, my tez nie mamy nic do powiedzenia — inaczej wtyczka
	 * zaczelaby wypychac ludzi z panelu na wlasna reke.
	 */
	wpn_ok(
		false === MP_SW_Roles::pozwol_na_panel( false ),
		'B3: gdy WooCommerce nie wypycha, my tez nie zmieniamy odpowiedzi'
	);

	wp_delete_user( $wpn_klient );
}

$wpn_admin = wpn_uzytkownik( 'administrator' );

if ( wpn_ok( $wpn_admin > 0, 'B4: administrator zalozony' ) ) {
	wp_set_current_user( $wpn_admin );
	wpn_ok(
		false === MP_SW_Roles::pozwol_na_panel( true ),
		'B5: administratora nie wypychamy — ma wszystkie nasze uprawnienia'
	);
	wp_delete_user( $wpn_admin );
}

wp_set_current_user( 0 );
wpn_ok(
	true === MP_SW_Roles::pozwol_na_panel( true ),
	'B6: niezalogowany nie jest naszym uzytkownikiem i decyzji nie zmieniamy'
);

/* ==================================================================== C */

$GLOBALS['mp_wp']['lines'][] = '';
$GLOBALS['mp_wp']['lines'][] = '=== C. filtr jest naprawde podpiety ===';

wpn_ok(
	has_filter( 'woocommerce_prevent_admin_access', array( 'MP_SW_Roles', 'pozwol_na_panel' ) ) !== false,
	'C1: odpowiedz jest podpieta pod filtr WooCommerce — metoda bez podpiecia '
	. 'nie broni nikogo'
);

wp_set_current_user( $wpn_stary );
