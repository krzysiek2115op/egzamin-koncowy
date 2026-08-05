<?php
/**
 * Trzy ustalenia audytu z pluginu 1 — wszystkie tej samej klasy: pole albo
 * zdanie twierdzi coś, czego kod nie sprawdził.
 *
 * Uruchamianie: wp eval-file tests/naprawy/status-sumy-i-etykieta-strony.php
 *
 * A. SUMA, KTÓREJ NIE POLICZONO. Dział 3 zwracał `nip_checksum` jako pole
 *    dwuwartościowe: `$valid ? 'zgodna' : 'niezgodna'`. Tymczasem
 *    `checksum_valid()` odpada w TRZECH miejscach ZANIM dojdzie do modulo:
 *    zły format (`\A\d{10}\z`), same powtórzone cyfry i reszta 10. Puste pole
 *    dostawało więc status „suma kontrolna niezgodna" dla numeru, którego nikt
 *    nie podał. Ta sama wada została świadomie naprawiona dla komunikatu
 *    (`rejection_reason()` rozróżnia cztery powody) — pole statusu zostało
 *    binarne i mówiło dalej swoje.
 *
 * B. DWA KRYTERIA PRAWDZIWOŚCI NA JEDNEJ ZMIENNEJ. Dział 7 czytał `vat_valid`
 *    raz ściśle (`true === $vat_valid` przy wyborze statusu), a dwie linie niżej
 *    luźno (`$vat_valid ? 1 : 0` przy kolumnie). Wartość prawdziwa, ale nie
 *    będąca literalnym `true` — `1` albo `'1'` po przejściu przez cache czy
 *    bazę, gdzie typ logiczny nie przeżył — rozjeżdżała wiersz: kolumna
 *    `vat_valid = 1` przy statusie `checked`, czyli „numer jest ważny" obok
 *    „numeru nie potwierdzono". Nikt tego potem nie prostuje: wtyczka 2 czyta
 *    ważność ze statusu, a weryfikator w tle bierze WYŁĄCZNIE wiersze `pending`.
 *
 * C. SLUG ZAMIAST ETYKIETY. Komunikat dla administratora wstawiał surowy
 *    `post_status` („ma status „pitch””) i odsyłał do „Strony → Wszystkie
 *    strony” dla każdego statusu poza koszem. Instalacja z własnymi statusami
 *    wpisów (PublishPress i podobne) daje więc zdanie z technicznym wtrętem
 *    i wskazówkę prowadzącą na listę, która wpisu w takim statusie nie pokazuje.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_se'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegół przy porażce.
 * @return bool
 */
function se_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_se']['pass'];
		$GLOBALS['mp_se']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_se']['fail'];
	$GLOBALS['mp_se']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function se_koniec() {
	if ( empty( $GLOBALS['mp_se']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_se'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_se']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'se_koniec' );

/**
 * Status sumy kontrolnej zwrócony przez agenta 3.1 dla danego NIP-u.
 *
 * @param string $nip  Numer.
 * @param string $kraj Kraj.
 * @return array Dane wyniku.
 */
function se_dzial3( $nip, $kraj = 'PL' ) {
	$agent = new MP_D3_Agent_Nip();
	$wynik = $agent->run(
		new MP_Context(
			array(
				'nip'     => $nip,
				'country' => $kraj,
			)
		)
	);

	return (array) $wynik->get_data();
}

/* ==================================================================== A */

$GLOBALS['mp_se']['lines'][] = '=== A. status sumy kontrolnej nie klamie ===';

/*
 * 5260001246 to NIP Ministerstwa Finansów — numer publiczny, o poprawnej sumie
 * kontrolnej. Numer z podmienioną ostatnią cyfrą jest jedynym przypadkiem,
 * w którym modulo naprawdę policzono i wyszło inaczej.
 */
$se_dobry = '5260001246';
$se_zly   = '5260001247';

se_ok(
	'zgodna' === ( se_dzial3( $se_dobry )['nip_checksum'] ?? '' ),
	'poprawny NIP: suma zgodna',
	'status=' . ( se_dzial3( $se_dobry )['nip_checksum'] ?? '?' )
);
se_ok(
	'niezgodna' === ( se_dzial3( $se_zly )['nip_checksum'] ?? '' ),
	'zla cyfra kontrolna: suma NIEZGODNA — modulo policzono',
	'status=' . ( se_dzial3( $se_zly )['nip_checksum'] ?? '?' )
);

$se_niepoliczone = array(
	''            => 'puste pole',
	'12345'       => 'za krotki numer',
	'52600012467' => 'za dlugi numer',
	'0000000000'  => 'same powtorzone cyfry',
	'526000124a'  => 'litera w numerze',
);

foreach ( $se_niepoliczone as $se_wart => $se_opis ) {
	$se_st = se_dzial3( (string) $se_wart )['nip_checksum'] ?? '';
	se_ok(
		'nie_policzona' === $se_st,
		$se_opis . ': status mowi, ze sumy NIE POLICZONO',
		'status=' . $se_st
	);
}

se_ok(
	'nie_dotyczy' === ( se_dzial3( 'DE123456789', 'DE' )['nip_checksum'] ?? '' ),
	'kontr-asercja: numer spoza Polski nadal ma nie_dotyczy'
);
se_ok(
	true === ( se_dzial3( $se_dobry )['nip_valid'] ?? null )
	&& false === ( se_dzial3( $se_zly )['nip_valid'] ?? null ),
	'kontr-asercja: nip_valid bez zmian — to on zatrzymuje potok'
);

$se_puste = se_dzial3( '' );
se_ok(
	false !== mb_stripos( (string) ( $se_puste['errors']['nip'] ?? '' ), 'wymagany' ),
	'kontr-asercja: komunikat nadal mowi o polu wymaganym, nie o sumie'
);

/* ==================================================================== B */

$GLOBALS['mp_se']['lines'][] = '';
$GLOBALS['mp_se']['lines'][] = '=== B. vat_valid ma jedno kryterium prawdziwosci ===';

/**
 * Buduje lead przez agenta 7.2 dla podanej wartości `vat_valid`.
 *
 * @param mixed $vat_valid Wartość z kontekstu.
 * @return array Dane leada.
 */
function se_dzial7( $vat_valid ) {
	$agent = new MP_D7_Agent_Prepare();
	$wynik = $agent->run(
		new MP_Context(
			array(
				'company_name'         => 'Firma sp. z o.o.',
				'nip'                  => '5260001246',
				'email'                => 'kontakt@example.com',
				'country'              => 'PL',
				'vat_valid'            => $vat_valid,
				'company_status'       => 'Czynny',
				'company_status_scope' => 'pl',
				'score'                => 50,
			)
		)
	);

	$dane = (array) $wynik->get_data();

	return (array) ( $dane['lead_data'] ?? array() );
}

$se_przypadki = array(
	'true (bool)'  => true,
	'1 (int)'      => 1,
	"'1' (string)" => '1',
);

foreach ( $se_przypadki as $se_opis => $se_wart ) {
	$se_lead = se_dzial7( $se_wart );
	se_ok(
		'valid' === (string) ( $se_lead['vat_status'] ?? '' ) && 1 === (int) ( $se_lead['vat_valid'] ?? -1 ),
		'wartosc prawdziwa ' . $se_opis . ': status valid ORAZ kolumna 1',
		'status=' . ( $se_lead['vat_status'] ?? '?' ) . ' kolumna=' . var_export( $se_lead['vat_valid'] ?? null, true )
	);
}

$se_falsz = array(
	'false (bool)' => false,
	'0 (int)'      => 0,
	"'0' (string)" => '0',
);

foreach ( $se_falsz as $se_opis => $se_wart ) {
	$se_lead = se_dzial7( $se_wart );
	se_ok(
		'checked' === (string) ( $se_lead['vat_status'] ?? '' ) && 0 === (int) ( $se_lead['vat_valid'] ?? -1 ),
		'wartosc falszywa ' . $se_opis . ': status checked ORAZ kolumna 0',
		'status=' . ( $se_lead['vat_status'] ?? '?' ) . ' kolumna=' . var_export( $se_lead['vat_valid'] ?? null, true )
	);
}

/*
 * Bez `??` — operator łączenia null wciągnąłby tu wartość zastępczą dokładnie
 * dla przypadku, o który pytamy, i asercja sprawdzałaby własną domyślkę.
 */
$se_null = se_dzial7( null );
se_ok(
	'pending' === (string) ( $se_null['vat_status'] ?? '' )
	&& array_key_exists( 'vat_valid', $se_null ) && is_null( $se_null['vat_valid'] ),
	'kontr-asercja: null nadal znaczy „nie wiadomo" — status pending, kolumna NULL',
	'status=' . ( $se_null['vat_status'] ?? '?' ) . ' kolumna=' . var_export( isset( $se_null['vat_valid'] ) ? $se_null['vat_valid'] : null, true )
);

/* ==================================================================== C */

$GLOBALS['mp_se']['lines'][] = '';
$GLOBALS['mp_se']['lines'][] = '=== C. komunikat o stanie strony mowi po polsku i wskazuje miejsce ===';

/**
 * Komunikat o stanie dla wpisu w podanym statusie.
 *
 * @param string $status Status wpisu.
 * @return string
 */
function se_komunikat( $status ) {
	$id = wp_insert_post(
		array(
			'post_title'   => 'Zapytanie ofertowe (test stanu)',
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
		)
	);

	if ( is_wp_error( $id ) || ! $id ) {
		return '';
	}

	// Status niestandardowy nie przejdzie przez wp_insert_post — ustawiamy wprost.
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
	clean_post_cache( $id );

	$metoda = new ReflectionMethod( 'MP_Lead_Intake_Page', 'komunikat_o_stanie' );
	$metoda->setAccessible( true );
	$tekst = (string) $metoda->invoke( null, get_post( $id ) );

	wp_delete_post( $id, true );

	return $tekst;
}

register_post_status(
	'pitch',
	array(
		'label'                  => 'Propozycja tematu',
		'public'                 => false,
		'show_in_admin_all_list' => false,
	)
);

$se_pitch = se_komunikat( 'pitch' );

se_ok(
	false !== mb_strpos( $se_pitch, 'Propozycja tematu' ),
	'status niestandardowy pokazany ETYKIETA, nie slugiem',
	'komunikat=' . $se_pitch
);
se_ok(
	false === mb_strpos( $se_pitch, '„pitch"' ),
	'surowy slug nie trafia w cudzyslow jako nazwa statusu'
);
/*
 * Asercja pyta o RADE, nie o wystapienie slow. Komunikat wolno wspomniec liste
 * „Wszystkie strony" w wyjasnieniu, dlaczego tam nie odsyla — zabronione jest
 * odeslanie na nia jako miejsce do wykonania.
 */
se_ok(
	false === mb_strpos( $se_pitch, 'publikacji w Strony' ),
	'i NIE odsyla na liste, ktora wpisu w tym statusie nie pokazuje',
	'komunikat=' . $se_pitch
);
se_ok(
	false !== mb_strpos( $se_pitch, 'identyfikatorze' ),
	'za to podaje identyfikator wpisu — droga dzialajaca dla kazdego statusu'
);

$se_szkic = se_komunikat( 'draft' );

se_ok(
	false !== mb_strpos( $se_szkic, 'Wszystkie strony' ),
	'kontr-asercja: szkic nadal odsyla do Strony → Wszystkie strony',
	'komunikat=' . $se_szkic
);
/*
 * Etykieta czytana z REJESTRU, nie wpisana w test na sztywno: instalacja bywa
 * anglojezyczna, a chodzi o to, ze komunikat bierze ja stamtad, skad WordPress.
 */
$se_obj_szkic = get_post_status_object( 'draft' );
$se_lab_szkic = ( $se_obj_szkic && ! empty( $se_obj_szkic->label ) ) ? (string) $se_obj_szkic->label : '';

se_ok(
	'' !== $se_lab_szkic && false !== mb_strpos( $se_szkic, $se_lab_szkic ),
	'i tez ma etykiete z rejestru statusow',
	'etykieta=' . $se_lab_szkic . ' komunikat=' . $se_szkic
);
se_ok(
	false === mb_strpos( $se_szkic, '„draft"' ),
	'a surowego slugu w cudzyslowie juz nie ma'
);

$se_kosz = se_komunikat( 'trash' );

se_ok(
	false !== mb_strpos( $se_kosz, 'Kosz' ),
	'kontr-asercja: kosz nadal odsyla do Strony → Kosz',
	'komunikat=' . $se_kosz
);
