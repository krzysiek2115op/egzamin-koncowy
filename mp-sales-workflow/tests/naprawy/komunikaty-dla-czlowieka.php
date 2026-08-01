<?php
/**
 * U-9, U-10, U-11 — co widzi czlowiek, gdy cos pojdzie nie tak.
 *
 * Uruchamianie: wp eval-file tests/naprawy/komunikaty-dla-czlowieka.php
 *
 * Test lezy w zestawie wtyczki 3, bo jako jedyny zaklada komplet trzech wtyczek —
 * a ustalenia dotycza wszystkich trzech naraz i maja jedna przyczyne: tekst
 * widziany przez czlowieka traktowano jako szczegol implementacyjny.
 *
 * U-9. Powtorne zgloszenie tej samej firmy konczylo sie komunikatem „Nie udalo sie
 * przetworzyc zgloszenia. Sprawdz dane i sprobuj ponownie." Odmowa jest poprawna
 * (kryterium odbioru zada braku duplikatow), ale nadawca nie dowiadywal sie, ze
 * jego firma juz jest w bazie — i poprawial dane, ktore sa dobre. Przyczyna siedziala
 * glebiej niz w tekscie: KAZDY krytyk flagowy zwracal ten sam kod `flag_failed`,
 * wiec warstwa AJAX nie miala z czego rozpoznac powodu, nawet gdyby chciala.
 *
 * U-10. Komunikaty zwracane nadawcy formularza byly wpisane na sztywno, bez funkcji
 * tlumaczacej — w tych samych plikach, kilkanascie linii wyzej, inne teksty
 * przechodzily przez `esc_html__()`. Tak samo naglowki glownej tabeli pulpitu
 * wtyczki 3. Changelog 1.3.5 mowi o „176 tekstach przygotowanych do przetlumaczenia";
 * naglowki jej wlasnego pulpitu do tej liczby nie nalezaly.
 *
 * U-11. Panel doklejal `MP3-Exxx` tuz za zdaniem po polsku, bez slowa wyjasnienia.
 * Kod bywa przydatny przy zglaszaniu awarii — ale wtedy ma byc PODPISANY.
 *
 * GRANICA, KTOREJ NIE PRZEKRACZAMY. Odmowy z Dzialu 5 (antyspam, CSRF, limit tempa)
 * zostaja generyczne. Powiedzenie botowi, ktora straz zadzialala, jest instrukcja
 * obejscia. Rozroznienie „co nadawca moze naprawic" kontra „czego nie ma prawa
 * wiedziec" jest tu tresc naprawy, a nie jej skutkiem ubocznym.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['kdc'] = array(
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
function kdc_ok( $warunek, $opis, $info = '' ) {
	if ( $warunek ) {
		++$GLOBALS['kdc']['pass'];
		$GLOBALS['kdc']['lines'][] = '[PASS] ' . $opis;
		return;
	}

	++$GLOBALS['kdc']['fail'];
	$GLOBALS['kdc']['lines'][] = '[FAIL] ' . $opis . ( '' !== $info ? ' -- ' . $info : '' );
}

/**
 * Wykonywany kod pliku, bez komentarzy.
 *
 * Reguly o `__()` musza patrzec na KOD. Naprawiony plik cytuje w komentarzu to,
 * co z niego zniknelo, a zwykle szukanie tekstu uznaloby cytat za dzialajacy kod.
 *
 * @param string $wzgledna Sciezka wzgledem katalogu wtyczek.
 * @return string
 */
function kdc_kod( $wzgledna ) {
	$sciezka = WP_PLUGIN_DIR . '/' . $wzgledna;

	if ( ! is_readable( $sciezka ) ) {
		return '';
	}

	$kod = '';

	foreach ( token_get_all( (string) file_get_contents( $sciezka ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$kod .= is_array( $token ) ? $token[1] : $token;
	}

	return $kod;
}

$komplet = class_exists( 'MP_Pipeline_Factory' ) && class_exists( 'MP_Lead_Intake_Ajax' ) && class_exists( 'MP_SW_Admin' );
kdc_ok( $komplet, '0: wszystkie trzy wtyczki zaladowane' );

if ( ! $komplet ) {
	echo implode( "\n", $GLOBALS['kdc']['lines'] ) . "\n";
	echo "VERDICT_HAS_FAILURES\n";
	return;
}

/* ==================================================================== A */
// U-9: powtorka tej samej firmy ma wlasny kod odmowy, nie zbiorczy `flag_failed`.

$leads_t = MP_Lead_Intake_DB::leads_table();
$wpdb->query( "DELETE FROM {$leads_t} WHERE email LIKE 'kdc-%@example.test'" ); // phpcs:ignore WordPress.DB

/**
 * Puszcza pipeline wtyczki 1 dla podanego NIP-u.
 *
 * @param string $nip NIP.
 * @return MP_Result
 */
function kdc_zgloszenie( $nip ) {
	return MP_Pipeline_Factory::make()->run(
		new MP_Context(
			array(
				'company_name'      => 'Kadecowa Sp. z o.o.',
				'nip'               => $nip,
				'email'             => 'kdc-' . substr( $nip, 0, 6 ) . '@example.test',
				'phone'             => '+48 600 100 200',
				'country'           => 'PL',
				'segment'           => 'IT',
				'consent_rodo'      => true,
				'consent_marketing' => true,
				'mp_nonce'          => wp_create_nonce( 'mp_lead_intake' ),
			)
		)
	);
}

$pierwsze = kdc_zgloszenie( '5252248481' );
kdc_ok( $pierwsze->is_ok(), 'A0: pierwsze zgloszenie przechodzi', wp_json_encode( $pierwsze->get_errors() ) );

$powtorka = kdc_zgloszenie( '5252248481' );

kdc_ok( ! $powtorka->is_ok(), 'A1: powtorka tej samej firmy zostaje odrzucona' );

kdc_ok(
	'duplicate_company' === $powtorka->get_code(),
	'A2: U-9 — odmowa ma WLASNY kod, nie zbiorczy flag_failed',
	'kod=' . $powtorka->get_code()
);

$dla_nadawcy = MP_Lead_Intake_Ajax::public_message( $powtorka->get_code() );

kdc_ok(
	false !== mb_stripos( $dla_nadawcy, 'już' ) || false !== mb_stripos( $dla_nadawcy, 'zarejestrowan' ),
	'A3: U-9 — nadawca dowiaduje sie, ze jego firma juz jest w bazie',
	'komunikat: ' . $dla_nadawcy
);

foreach ( array( 'flag_failed', 'unique_ok', 'K7.1', 'duplicate_company' ) as $wewnetrzne ) {
	kdc_ok(
		false === strpos( $dla_nadawcy, $wewnetrzne ),
		'A4: KONTR-ASERCJA — komunikat nie wynosi na zewnatrz „' . $wewnetrzne . '"',
		$dla_nadawcy
	);
}

/* ==================================================================== B */
// KONTR-ASERCJA: straze z Dzialu 5 zostaja generyczne.

$generyczny = MP_Lead_Intake_Ajax::public_message( 'processing_failed' );

foreach ( array( 'antispam_ok', 'csrf_ok', 'rate_ok', 'security_failed' ) as $straz ) {
	kdc_ok(
		MP_Lead_Intake_Ajax::public_message( $straz ) === $generyczny,
		'B1: KONTR-ASERCJA — odmowa straznika „' . $straz . '" nie mowi, ktora straz zadzialala'
	);
}

kdc_ok(
	MP_Lead_Intake_Ajax::public_message( 'cokolwiek_nowego' ) === $generyczny,
	'B2: KONTR-ASERCJA — nieznany kod dostaje komunikat generyczny, nie wlasna nazwe'
);

/* ==================================================================== C */
// U-9 dla pozostalych powodow, ktore nadawca MOZE naprawic.

$bledny_nip = kdc_zgloszenie( '1234567890' );

kdc_ok(
	'nip_invalid' === $bledny_nip->get_code(),
	'C1: zla suma kontrolna NIP ma wlasny kod',
	'kod=' . $bledny_nip->get_code()
);

kdc_ok(
	false !== mb_stripos( MP_Lead_Intake_Ajax::public_message( 'nip_invalid' ), 'nip' ),
	'C2: komunikat o NIP-ie mowi o NIP-ie'
);

/* ==================================================================== D */
// U-10: teksty dla czlowieka ida przez funkcje tlumaczaca.

$pliki = array(
	'mp-lead-intake/includes/class-mp-ajax.php'                 => 'mp-lead-intake',
	'mp-offer-builder/includes/class-mp-offer-builder-ajax.php' => 'mp-offer-builder',
	'mp-sales-workflow/includes/admin/class-mp-sw-admin.php'    => 'mp-sales-workflow',
);

foreach ( $pliki as $plik => $domena ) {
	$kod = kdc_kod( $plik );

	kdc_ok( '' !== $kod, 'D0: plik czytelny — ' . $plik );

	/*
	 * Szukamy przypisan 'message' => '...' z golym napisem. Taki zapis jest
	 * jednoznaczny: tekst idzie prosto do odpowiedzi, z pominieciem tlumaczen.
	 */
	$gole = preg_match_all( "/'message'\s*=>\s*'[^']{6,}'/", $kod, $trafienia );

	kdc_ok(
		0 === $gole,
		'D1: U-10 — ' . $plik . ': komunikaty nie sa wpisane na sztywno',
		$gole ? implode( ' | ', array_slice( $trafienia[0], 0, 3 ) ) : ''
	);

	kdc_ok(
		false !== strpos( $kod, "'" . $domena . "'" ),
		'D2: ' . $plik . ': teksty wskazuja wlasna domene tlumaczen'
	);
}

// Naglowki pulpitu wtyczki 3 — wymienione w ustaleniu z nazwy.
$kod_sw = kdc_kod( 'mp-sales-workflow/includes/admin/class-mp-sw-admin.php' );

foreach ( array( 'Termin SLA', 'Otwarte zadania', 'Akcje' ) as $naglowek ) {
	kdc_ok(
		false !== strpos( $kod_sw, "__( '" . $naglowek . "', 'mp-sales-workflow' )" ),
		'D3: U-10 — naglowek pulpitu „' . $naglowek . '" przechodzi przez __()',
		'naglowek poza funkcja tlumaczaca'
	);
}

/* ==================================================================== E */
// U-11: kod bledu w panelu jest PODPISANY, a nie doklejony.

kdc_ok(
	false === strpos( $kod_sw, "esc_html( MP_SW_Errors::message( \$kod ) ) . ' <code>'" ),
	'E1: U-11 — kod bledu nie stoi doklejony wprost za zdaniem komunikatu'
);

kdc_ok(
	false !== strpos( $kod_sw, 'Kod do zgłoszenia awarii' ),
	'E2: U-11 — kod bledu ma podpis mowiacy, po co jest'
);

$wpdb->query( "DELETE FROM {$leads_t} WHERE email LIKE 'kdc-%@example.test'" ); // phpcs:ignore WordPress.DB

echo implode( "\n", $GLOBALS['kdc']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['kdc']['pass'], $GLOBALS['kdc']['fail'] );
echo ( 0 === $GLOBALS['kdc']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
