<?php
/**
 * Handlowca da sie skonfigurowac z panelu WordPressa, bez konsoli.
 *
 * Uruchamianie: wp eval-file tests/naprawy/handlowiec-konfigurowalny-z-panelu.php
 *
 * Dzial 4 dobiera wlasciciela procesu po usermeta `mp_sw_country`, `mp_sw_langs`
 * i `mp_sw_active`. Konto z sama rola „Handlowiec", bez tych pol, NIE JEST
 * kandydatem dla zadnego procesu — pipeline konczy sie kodem `no_owner`
 * i proces w ogole nie powstaje.
 *
 * Do 1.3.9 ustawic te pola dalo sie WYLACZNIE spoza panelu: instrukcja
 * techniczna podawala `wp user meta update 14 mp_sw_country PL`, a zasiew demo
 * wolal `update_user_meta()` z poziomu PHP. Klient, ktoremu PRZECZYTAJ-MNIE.txt
 * kaze wgrac wtyczki przez „Wtyczki -> Dodaj nowa", nie mial jak dokonczyc
 * konfiguracji tam, gdzie zaczal. Skutek: system po instalacji przyjmuje
 * zgloszenia i po cichu nie robi z nimi nic.
 *
 * A. HAKI. Pola siedza na ekranie profilu uzytkownika i zapisuja sie standardowa
 *    droga WordPressa (`show_user_profile`/`edit_user_profile` + `*_update`).
 *
 * B. ZAPIS. Administrator ustawia kraj, jezyki, zespol i aktywnosc — i to jest
 *    naprawde widoczne dla Dzialu 4, czyli pod tymi samymi kluczami meta.
 *
 * C. NORMALIZACJA. Kraj do ISO-2 wielkimi literami, jezyki do listy kodow
 *    dwuliterowych. Bez tego „pl" i „PL" to dla wyszukiwania dwa rozne kraje.
 *
 * D. KONTR-ASERCJE: bez nonce nic sie nie zapisuje, bez uprawnien do edycji
 *    tego uzytkownika nic sie nie zapisuje, smieci nie przechodza.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_hp'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
	'users' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $cond Warunek.
 * @param string $msg  Opis.
 * @param string $info Kontekst przy porazce.
 * @return bool
 */
function hp_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_hp']['pass'];
		$GLOBALS['mp_hp']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_hp']['fail'];
	$GLOBALS['mp_hp']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik i kasuje konta zalozone przez sonde.
 *
 * @return void
 */
function hp_koniec() {
	if ( empty( $GLOBALS['mp_hp']['lines'] ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';

	foreach ( $GLOBALS['mp_hp']['users'] as $id ) {
		wp_delete_user( (int) $id );
	}

	wp_set_current_user( 0 );

	$r    = $GLOBALS['mp_hp'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_hp']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'hp_koniec' );

/**
 * Zaklada konto na potrzeby sondy.
 *
 * @param string $login Login.
 * @param string $rola  Rola.
 * @return int
 */
function hp_konto( $login, $rola ) {
	$istnieje = username_exists( $login );
	if ( $istnieje ) {
		wp_delete_user( (int) $istnieje );
	}

	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 20 ),
			'user_email' => $login . '@example.test',
			'role'       => $rola,
		)
	);

	$id = (int) $id;
	$GLOBALS['mp_hp']['users'][] = $id;

	return $id;
}

$admin     = hp_konto( 'hp_admin', 'administrator' );
$handlowiec = hp_konto( 'hp_handlowiec', MP_SW_Roles::ROLE_SALESMAN );

/* ==================================================================== A */

$GLOBALS['mp_hp']['lines'][] = '=== A. pola sa na ekranie profilu uzytkownika ===';

$haki = array( 'show_user_profile', 'edit_user_profile', 'personal_options_update', 'edit_user_profile_update' );

foreach ( $haki as $hak ) {
	$ma = false;

	if ( isset( $GLOBALS['wp_filter'][ $hak ] ) ) {
		foreach ( $GLOBALS['wp_filter'][ $hak ]->callbacks as $lista ) {
			foreach ( $lista as $wpis ) {
				$cel = $wpis['function'];
				$cel = is_array( $cel ) ? ( is_object( $cel[0] ) ? get_class( $cel[0] ) : (string) $cel[0] ) : (string) ( is_string( $cel ) ? $cel : '' );
				if ( false !== stripos( $cel, 'MP_SW' ) ) {
					$ma = true;
				}
			}
		}
	}

	hp_ok( $ma, 'wtyczka jest podpieta pod `' . $hak . '`' );
}

hp_ok(
	class_exists( 'MP_SW_User_Profile' ),
	'istnieje klasa obslugujaca profil handlowca'
);

if ( ! class_exists( 'MP_SW_User_Profile' ) ) {
	return;
}

$stary_uzytkownik = get_current_user_id();
wp_set_current_user( $admin );

ob_start();
MP_SW_User_Profile::render( get_userdata( $handlowiec ) );
$html = (string) ob_get_clean();

hp_ok(
	false !== strpos( $html, MP_SW_D2_Reader::META_COUNTRY )
		&& false !== strpos( $html, MP_SW_D2_Reader::META_LANGS )
		&& false !== strpos( $html, MP_SW_D2_Reader::META_ACTIVE ),
	'ekran profilu zawiera pola kraju, jezykow i aktywnosci'
);

hp_ok(
	false !== strpos( $html, 'name="' . MP_SW_User_Profile::NONCE . '"' ),
	'i pole nonce, bo to formularz zmieniajacy dane',
	'szukam name="' . MP_SW_User_Profile::NONCE . '"'
);

/* ==================================================================== B */

$GLOBALS['mp_hp']['lines'][] = '';
$GLOBALS['mp_hp']['lines'][] = '=== B. administrator zapisuje konfiguracje z panelu ===';

/**
 * Udaje wyslanie formularza profilu.
 *
 * @param array $pola Pola formularza.
 * @param bool  $nonce Czy dolozyc poprawny nonce.
 * @return void
 */
function hp_wyslij( array $pola, $nonce = true ) {
	$_POST = $pola;
	if ( $nonce ) {
		$_POST[ MP_SW_User_Profile::NONCE ] = wp_create_nonce( MP_SW_User_Profile::NONCE );
	}
	$_REQUEST = $_POST;
}

hp_wyslij(
	array(
		MP_SW_D2_Reader::META_COUNTRY => 'pl',
		MP_SW_D2_Reader::META_LANGS   => 'PL, en , xx1',
		MP_SW_D2_Reader::META_TEAM    => '  Sprzedaz B2B  ',
		MP_SW_D2_Reader::META_ACTIVE  => '1',
	)
);

MP_SW_User_Profile::save( $handlowiec );

hp_ok(
	'PL' === (string) get_user_meta( $handlowiec, MP_SW_D2_Reader::META_COUNTRY, true ),
	'kraj zapisany jako ISO-2 wielkimi literami',
	'jest: ' . var_export( get_user_meta( $handlowiec, MP_SW_D2_Reader::META_COUNTRY, true ), true )
);

hp_ok(
	'pl,en' === (string) get_user_meta( $handlowiec, MP_SW_D2_Reader::META_LANGS, true ),
	'jezyki znormalizowane do listy kodow dwuliterowych, smiec odrzucony',
	'jest: ' . var_export( get_user_meta( $handlowiec, MP_SW_D2_Reader::META_LANGS, true ), true )
);

hp_ok(
	'Sprzedaz B2B' === (string) get_user_meta( $handlowiec, MP_SW_D2_Reader::META_TEAM, true ),
	'zespol zapisany bez otaczajacych spacji'
);

hp_ok(
	'1' === (string) get_user_meta( $handlowiec, MP_SW_D2_Reader::META_ACTIVE, true ),
	'aktywnosc zapisana'
);

/* Odznaczenie „aktywny" musi byc zapisem, a nie brakiem zapisu. */
hp_wyslij(
	array(
		MP_SW_D2_Reader::META_COUNTRY => 'PL',
		MP_SW_D2_Reader::META_LANGS   => 'pl',
		MP_SW_D2_Reader::META_TEAM    => 'Sprzedaz B2B',
	)
);
MP_SW_User_Profile::save( $handlowiec );

hp_ok(
	'0' === (string) get_user_meta( $handlowiec, MP_SW_D2_Reader::META_ACTIVE, true ),
	'odznaczone pole zapisuje „0", zamiast zostawiac poprzednia wartosc',
	'jest: ' . var_export( get_user_meta( $handlowiec, MP_SW_D2_Reader::META_ACTIVE, true ), true )
);

/* ==================================================================== C */

$GLOBALS['mp_hp']['lines'][] = '';
$GLOBALS['mp_hp']['lines'][] = '=== C. KONTR-ASERCJE: bez nonce i bez uprawnien ani drgnie ===';

update_user_meta( $handlowiec, MP_SW_D2_Reader::META_COUNTRY, 'PL' );

hp_wyslij( array( MP_SW_D2_Reader::META_COUNTRY => 'DE' ), false );
MP_SW_User_Profile::save( $handlowiec );

hp_ok(
	'PL' === (string) get_user_meta( $handlowiec, MP_SW_D2_Reader::META_COUNTRY, true ),
	'zadanie bez nonce nie zmienia niczego'
);

wp_set_current_user( $handlowiec );
hp_wyslij( array( MP_SW_D2_Reader::META_COUNTRY => 'DE' ) );
MP_SW_User_Profile::save( $admin );

hp_ok(
	'DE' !== (string) get_user_meta( $admin, MP_SW_D2_Reader::META_COUNTRY, true ),
	'handlowiec nie przepisze konfiguracji administratorowi'
);

wp_set_current_user( $admin );
hp_wyslij( array( MP_SW_D2_Reader::META_COUNTRY => '12', MP_SW_D2_Reader::META_LANGS => '@@' ) );
MP_SW_User_Profile::save( $handlowiec );

hp_ok(
	'' === (string) get_user_meta( $handlowiec, MP_SW_D2_Reader::META_COUNTRY, true ),
	'kraj spoza ISO-2 nie zostaje zapisany',
	'jest: ' . var_export( get_user_meta( $handlowiec, MP_SW_D2_Reader::META_COUNTRY, true ), true )
);

hp_ok(
	'' === (string) get_user_meta( $handlowiec, MP_SW_D2_Reader::META_LANGS, true ),
	'lista jezykow zlozona ze smieci konczy sie pusta, a nie smieciem'
);

$_POST    = array();
$_REQUEST = array();
wp_set_current_user( $stary_uzytkownik );
