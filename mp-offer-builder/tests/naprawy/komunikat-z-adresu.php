<?php
/**
 * P2-G17 — komunikat o zatwierdzeniu wisial na samym parametrze GET.
 *
 * Uruchamianie: wp eval-file tests/naprawy/komunikat-z-adresu.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G17  Komunikat panelu zalezny wylacznie od parametru adresu
 *
 * `MP_Offer_Builder_Approval::notice()` czytal wylacznie `$_GET['mp_ob_approved']`
 * — bez nonce'a, bez stanu po stronie serwera i bez zwiazku z konkretna oferta.
 * Dawalo to dwie rzeczy naraz:
 *
 *   1. KOMUNIKAT O NICZYM. Adres z `&mp_ob_approved=ok` w zakladce, w historii
 *      przegladarki albo podeslany linkiem pokazywal zielone „Oferta
 *      zatwierdzona" przy KAZDYM wejsciu na strone ofert, choc nic sie wtedy
 *      nie wydarzylo. Ten sam adres z `=db_error` pokazywal czerwona awarie
 *      zapisu, ktorej nie bylo.
 *   2. KOMUNIKAT O KTOREJ OFERCIE? Nie mowil. Pracownik zatwierdzajacy kilka
 *      ofert pod rzad dostawal za kazdym razem to samo zdanie.
 *
 * Naprawa: wynik akcji zapamietuje sie po stronie serwera, w transiencie
 * zwiazanym Z UZYTKOWNIKIEM, na minute i JEDNORAZOWO (odczyt kasuje). Komunikat
 * niesie numer oferty. Adres powrotny nie przenosi juz nic, co da sie podrobic.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_kza'] = array(
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
function kza_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_kza']['pass'];
		$GLOBALS['mp_kza']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_kza']['fail'];
	$GLOBALS['mp_kza']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * HTML komunikatu wypisanego przez notice().
 *
 * @return string
 */
function kza_html() {
	ob_start();
	MP_Offer_Builder_Approval::notice();
	return wp_strip_all_tags( (string) ob_get_clean() );
}

/**
 * Zapamietuje wynik akcji po stronie serwera.
 *
 * Osobna funkcja, a nie wywolanie wprost, zeby przebieg PRZED naprawa konczyl
 * sie uczciwym FAIL-em zamiast fatala na nieistniejacej metodzie — inaczej nie
 * widac, ktore asercje padaja.
 *
 * @param string $kod        Kod wyniku.
 * @param string $numer      Numer oferty.
 * @param int    $uzytkownik Wlasciciel komunikatu.
 * @return bool
 */
function kza_zapamietaj( $kod, $numer, $uzytkownik ) {
	if ( ! method_exists( 'MP_Offer_Builder_Approval', 'remember_notice' ) ) {
		kza_ok( false, 'brak MP_Offer_Builder_Approval::remember_notice() — komunikat nadal wisi na adresie' );
		return false;
	}

	MP_Offer_Builder_Approval::remember_notice( $kod, $numer, $uzytkownik );
	return true;
}

/**
 * Kasuje zapamietany komunikat (o ile jest co kasowac).
 *
 * @param int $uzytkownik Wlasciciel komunikatu.
 * @return void
 */
function kza_zapomnij( $uzytkownik ) {
	if ( method_exists( 'MP_Offer_Builder_Approval', 'forget_notice' ) ) {
		MP_Offer_Builder_Approval::forget_notice( $uzytkownik );
	}
}

$uzytkownik = (int) wp_insert_user(
	array(
		'user_login' => 'p2g17-' . wp_generate_password( 6, false ),
		'user_email' => 'p2g17-' . wp_generate_password( 6, false ) . '@example.test',
		'user_pass'  => wp_generate_password( 12, false ),
		'role'       => 'administrator',
	)
);

$poprzedni = get_current_user_id();
wp_set_current_user( $uzytkownik );

// Sprzatanie po fatalu: uzytkownik, transient i podstawione $_GET.
register_shutdown_function(
	function () use ( $uzytkownik, $poprzedni ) {
		kza_zapomnij( $uzytkownik );
		wp_set_current_user( $poprzedni );

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		wp_delete_user( $uzytkownik );
	}
);

$GLOBALS['mp_kza']['lines'][] = '=== A. sam adres nie robi komunikatu ===';

/*
 * Dokladnie to, co dzialo sie z zakladka: parametr w adresie jest, po stronie
 * serwera nie wydarzylo sie nic.
 */
kza_zapomnij( $uzytkownik );
$_GET['mp_ob_approved'] = 'ok';
$html_z_zakladki        = kza_html();
unset( $_GET['mp_ob_approved'] );

kza_ok(
	'' === trim( $html_z_zakladki ),
	'adres z mp_ob_approved=ok NIE pokazuje juz „Oferta zatwierdzona"',
	'html=' . $html_z_zakladki
);

$_GET['mp_ob_approved'] = 'db_error';
$html_falszywy_blad     = kza_html();
unset( $_GET['mp_ob_approved'] );

kza_ok(
	'' === trim( $html_falszywy_blad ),
	'adres z mp_ob_approved=db_error nie straszy awaria, ktorej nie bylo',
	'html=' . $html_falszywy_blad
);

$GLOBALS['mp_kza']['lines'][] = '';
$GLOBALS['mp_kza']['lines'][] = '=== B. komunikat mowi, KTOREJ oferty dotyczy ===';

kza_zapamietaj( 'ok', 'OF/2026/000123', $uzytkownik );
$html_ok = kza_html();

kza_ok(
	'' !== trim( $html_ok ),
	'po prawdziwej akcji komunikat sie pokazuje',
	'html=' . $html_ok
);
kza_ok(
	false !== mb_strpos( $html_ok, 'OF/2026/000123' ),
	'komunikat zawiera numer oferty',
	'html=' . $html_ok
);

kza_zapamietaj( 'already_approved', 'OF/2026/000999', $uzytkownik );
$html_juz = kza_html();

kza_ok(
	false !== mb_strpos( $html_juz, 'OF/2026/000999' ),
	'komunikat „byla juz zatwierdzona" tez wskazuje oferte',
	'html=' . $html_juz
);

$GLOBALS['mp_kza']['lines'][] = '';
$GLOBALS['mp_kza']['lines'][] = '=== C. komunikat jest jednorazowy i wlasny ===';

kza_zapamietaj( 'ok', 'OF/2026/000123', $uzytkownik );
$pierwszy = kza_html();
$drugi    = kza_html();

kza_ok(
	'' !== trim( $pierwszy ) && '' === trim( $drugi ),
	'komunikat pokazuje sie RAZ — odswiezenie strony go nie powtarza',
	'pierwszy=' . $pierwszy . ' | drugi=' . $drugi
);

// Komunikat nalezy do tego, kto klikal. Inny zalogowany nie ma go zobaczyc.
$obcy = (int) wp_insert_user(
	array(
		'user_login' => 'p2g17b-' . wp_generate_password( 6, false ),
		'user_email' => 'p2g17b-' . wp_generate_password( 6, false ) . '@example.test',
		'user_pass'  => wp_generate_password( 12, false ),
		'role'       => 'administrator',
	)
);

kza_zapamietaj( 'ok', 'OF/2026/000123', $uzytkownik );
wp_set_current_user( $obcy );
$html_obcy = kza_html();
wp_set_current_user( $uzytkownik );

kza_ok(
	'' === trim( $html_obcy ),
	'komunikat nie wyswietla sie INNEMU zalogowanemu uzytkownikowi',
	'html=' . $html_obcy
);

// Wlasciciel dalej go ma — nie zjadl mu go cudzy odczyt.
$html_wlasciciel = kza_html();

kza_ok(
	false !== mb_strpos( $html_wlasciciel, 'OF/2026/000123' ),
	'wlascicielowi komunikat nadal sie nalezy',
	'html=' . $html_wlasciciel
);

if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}

wp_delete_user( $obcy );

// Niezalogowany: `admin_notices` nie ma prawa niczego wypisac ani szukac.
wp_set_current_user( 0 );
$html_gosc = kza_html();
wp_set_current_user( $uzytkownik );

kza_ok(
	'' === trim( $html_gosc ),
	'bez zalogowanego uzytkownika komunikatu nie ma',
	'html=' . $html_gosc
);

$GLOBALS['mp_kza']['lines'][] = '';
$GLOBALS['mp_kza']['lines'][] = '=== D. KONTR-ASERCJE: tresci komunikatow zostaja ===';

/*
 * Naprawa dotyczy SPOSOBU dostarczenia komunikatu, nie jego tresci. Ustalenia
 * P2-G15 (komunikat nie obiecuje cudzej pracy) i poziomy „error" musza zostac
 * nienaruszone — inaczej naprawa jednego cofnelaby drugie.
 */
global $wp_filter;
$sluchacze = isset( $wp_filter[ MP_Offer_Builder_Approval::HOOK ] ) ? $wp_filter[ MP_Offer_Builder_Approval::HOOK ] : null;

remove_all_actions( MP_Offer_Builder_Approval::HOOK );
kza_zapamietaj( 'ok', 'OF/2026/000123', $uzytkownik );
$bez_odbiorcy = kza_html();

kza_ok(
	false === mb_stripos( $bez_odbiorcy, 'przejmuje wysyłkę' ),
	'bez odbiorcy zdarzenia nadal NIE obiecuje wysylki przez modul sprzedazowy',
	'html=' . $bez_odbiorcy
);
kza_ok(
	false !== mb_stripos( $bez_odbiorcy, 'ręcznie' ) || false !== mb_stripos( $bez_odbiorcy, 'nasłuchuje' ),
	'nadal mowi pracownikowi, co ma zrobic',
	'html=' . $bez_odbiorcy
);

add_action( MP_Offer_Builder_Approval::HOOK, '__return_true' );
kza_zapamietaj( 'ok', 'OF/2026/000123', $uzytkownik );
$z_odbiorca = kza_html();

kza_ok(
	false !== mb_stripos( $z_odbiorca, 'przejmuje wysyłkę' ),
	'z podpietym modulem komunikat brzmi jak dawniej',
	'html=' . $z_odbiorca
);

remove_all_actions( MP_Offer_Builder_Approval::HOOK );

if ( $sluchacze ) {
	$wp_filter[ MP_Offer_Builder_Approval::HOOK ] = $sluchacze;
}

foreach ( array( 'db_error', 'no_document', 'offer_not_found', 'wrong_status' ) as $kod ) {
	kza_zapamietaj( $kod, '', $uzytkownik );
	$html = kza_html();

	kza_ok(
		'' !== trim( $html ),
		'kod ' . $kod . ' nadal daje komunikat',
		'html=' . $html
	);
}

// Kod spoza slownika nie ma prawa niczego wypisac — ani przepuscic dalej.
kza_zapamietaj( 'kod_ktorego_nie_ma', 'OF/2026/000123', $uzytkownik );
$html_nieznany = kza_html();

kza_ok(
	'' === trim( $html_nieznany ),
	'nieznany kod nie wypisuje niczego',
	'html=' . $html_nieznany
);

$GLOBALS['mp_kza']['lines'][] = '';
$GLOBALS['mp_kza']['lines'][] = '=== E. adres powrotny nie przenosi juz komunikatu ===';

$zrodlo = (string) file_get_contents( dirname( __DIR__ ) . '/../includes/class-mp-offer-builder-approval.php' );
$kod    = '';

foreach ( token_get_all( $zrodlo ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}

	$kod .= is_array( $token ) ? $token[1] : $token;
}

kza_ok(
	false === strpos( $kod, '$_GET' ),
	'notice() nie siega juz po $_GET (poza komentarzami)',
	'znaleziono $_GET w kodzie wykonywalnym'
);
kza_ok(
	false === strpos( $kod, 'mp_ob_approved' ),
	'adres powrotny nie niesie juz parametru komunikatu',
	'znaleziono mp_ob_approved w kodzie wykonywalnym'
);

echo implode( "\n", $GLOBALS['mp_kza']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_kza']['pass'], $GLOBALS['mp_kza']['fail'] );
echo ( 0 === $GLOBALS['mp_kza']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
