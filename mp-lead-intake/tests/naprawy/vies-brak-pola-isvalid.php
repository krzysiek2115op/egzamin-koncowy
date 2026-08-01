<?php
/**
 * Ustalenie 1.25 — brak pola `isValid` to nie jest „VAT niepoprawny".
 *
 * Uruchamianie: wp eval-file tests/naprawy/vies-brak-pola-isvalid.php
 *
 * Agent 3.2 czytal odpowiedz VIES tak:
 *
 *   $is_valid = ! empty( $body['isValid'] );
 *
 * `! empty()` nie odroznia `isValid: false` od BRAKU tego pola. Komentarz nad
 * warunkiem lagodnego fallbacku bronil tylko przypadku `isValid=false` z kodem
 * bledu („MS_UNAVAILABLE" i podobne). Odpowiedz 200 bez `isValid` i bez
 * `userError` schodzila ponizej: `$user_err` bylo puste, wiec fallback jej nie
 * lapal, a kod zapisywal do cache ZERO na 24 h i zwracal
 * `vat_valid => false, vat_checked => true`. Krytyk 3.2 zamienia taki wynik
 * w twardy STOP `vat_invalid`, wiec lead byl odrzucany — i to przez cala dobe,
 * bo werdykt siedzial w cache.
 *
 * To ta sama rodzina bledow co P1-C1, P1-G1 i P3-A1: brak potwierdzenia uznany
 * za potwierdzenie. Agent 3.3 dostal to zabezpieczenie przy P1-G1, agent 3.2
 * zostal wtedy pominiety — audyt to wylapal.
 *
 * Dokumentacja VIES (docs/dzial-03/vies-rest-api.md) wymienia `isValid`
 * i `userError` jako OSOBNE pola i nigdzie nie mowi, ze brak `isValid` znaczy
 * „numer niewazny".
 *
 * Test podstawia odpowiedzi HTTP przez `pre_http_request`, wiec nie rusza sieci.
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-G2  Odpowiedz VIES bez pola isValid traktowana jak „VAT niepoprawny"
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_vs'] = array(
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
function vs_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_vs']['pass'];
		$GLOBALS['mp_vs']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_vs']['fail'];
	$GLOBALS['mp_vs']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function vs_dump() {
	if ( empty( $GLOBALS['mp_vs']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_vs'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_vs']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'vs_dump' );

/**
 * Podstawia odpowiedz VIES zamiast realnego zapytania HTTP.
 *
 * @param array $cialo Tresc odpowiedzi.
 * @return void
 */
function vs_udawaj( $cialo ) {
	$GLOBALS['mp_vs_cialo'] = $cialo;

	remove_all_filters( 'pre_http_request' );

	add_filter(
		'pre_http_request',
		static function ( $wynik, $args, $url ) {
			if ( false === strpos( (string) $url, 'vies/rest-api' ) ) {
				return $wynik;
			}

			++$GLOBALS['mp_vs_zapytan'];

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $GLOBALS['mp_vs_cialo'] ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		},
		10,
		3
	);
}

$GLOBALS['mp_vs_zapytan'] = 0;

$kraj = 'PL';
$nip  = '1234563218';

/**
 * Czysci cache VIES dla numeru testowego.
 *
 * @return void
 */
function vs_wyczysc() {
	delete_transient( MP_D3_Agent_Vat::vies_cache_key( 'PL', '1234563218' ) );
}

/* ==================================================================== A */

$GLOBALS['mp_vs']['lines'][] = '=== A. numer wazny ===';

vs_wyczysc();
vs_udawaj(
	array(
		'isValid' => true,
		'name'    => 'MP Test sp. z o.o.',
	)
);

$a = MP_D3_Agent_Vat::resolve_vies( $kraj, $nip );

vs_ok( true === $a['vat_valid'], 'wazny numer daje vat_valid = true', 'vat_valid=' . var_export( $a['vat_valid'], true ) );
vs_ok( ! empty( $a['vat_checked'] ), 'i jest oznaczony jako sprawdzony' );
vs_ok( '1' === (string) get_transient( MP_D3_Agent_Vat::vies_cache_key( $kraj, $nip ) ), 'wynik trafia do cache' );

/* ==================================================================== B */

$GLOBALS['mp_vs']['lines'][] = '';
$GLOBALS['mp_vs']['lines'][] = '=== B. numer JAWNIE niewazny — to rozstrzygniecie ===';

/*
 * Strona przeciwna naprawy. `isValid: false` z kodem „INVALID" to prawdziwe
 * rozstrzygniecie i MUSI dalej konczyc sie odrzuceniem oraz wpisem do cache.
 * Bez tej asercji „naprawa" mogla polegac na przepuszczaniu wszystkiego.
 */
vs_wyczysc();
vs_udawaj(
	array(
		'isValid'   => false,
		'userError' => 'INVALID',
	)
);

$b = MP_D3_Agent_Vat::resolve_vies( $kraj, $nip );

vs_ok( false === $b['vat_valid'], 'jawnie niewazny numer daje vat_valid = false', 'vat_valid=' . var_export( $b['vat_valid'], true ) );
vs_ok( ! empty( $b['vat_checked'] ), 'i liczy sie jako sprawdzony' );
vs_ok( '0' === (string) get_transient( MP_D3_Agent_Vat::vies_cache_key( $kraj, $nip ) ), 'odrzucenie tez trafia do cache' );

/* ==================================================================== C */

$GLOBALS['mp_vs']['lines'][] = '';
$GLOBALS['mp_vs']['lines'][] = '=== C. awaria panstwa czlonkowskiego — bez zmian ===';

vs_wyczysc();
vs_udawaj(
	array(
		'isValid'   => false,
		'userError' => 'MS_UNAVAILABLE',
	)
);

$c = MP_D3_Agent_Vat::resolve_vies( $kraj, $nip );

vs_ok( null === $c['vat_valid'], 'awaria VIES nie rozstrzyga', 'vat_valid=' . var_export( $c['vat_valid'], true ) );
vs_ok( empty( $c['vat_checked'] ), 'i nie melduje sprawdzenia' );
vs_ok( false === get_transient( MP_D3_Agent_Vat::vies_cache_key( $kraj, $nip ) ), 'nic nie idzie do cache' );

/* ==================================================================== D */

$GLOBALS['mp_vs']['lines'][] = '';
$GLOBALS['mp_vs']['lines'][] = '=== D. odpowiedz 200 BEZ pola isValid ===';

vs_wyczysc();
vs_udawaj( array( 'requestDate' => '2026-07-31' ) );

$d = MP_D3_Agent_Vat::resolve_vies( $kraj, $nip );

vs_ok( null === $d['vat_valid'], 'brak pola isValid NIE znaczy „niewazny"', 'vat_valid=' . var_export( $d['vat_valid'], true ) );
vs_ok( empty( $d['vat_checked'] ), 'i nie jest meldowane jako sprawdzenie' );
vs_ok( false === get_transient( MP_D3_Agent_Vat::vies_cache_key( $kraj, $nip ) ), 'zaden werdykt nie trafia do cache na 24 h' );

/* ==================================================================== E */

$GLOBALS['mp_vs']['lines'][] = '';
$GLOBALS['mp_vs']['lines'][] = '=== E. brak wpisu w cache oznacza ponowna probe ===';

$przed = $GLOBALS['mp_vs_zapytan'];
MP_D3_Agent_Vat::resolve_vies( $kraj, $nip );
$po = $GLOBALS['mp_vs_zapytan'];

vs_ok( $po > $przed, 'druga proba faktycznie pyta VIES', 'zapytan przybylo: ' . ( $po - $przed ) );

/* ==================================================================== F */

$GLOBALS['mp_vs']['lines'][] = '';
$GLOBALS['mp_vs']['lines'][] = '=== F. krytyk 3.2 nie dostaje twardego STOP-u ===';

/*
 * Wlasciwy skutek bledu byl tutaj: Krytyk 3.2 zamienia vat_valid === false
 * w STOP `vat_invalid`. Przy odpowiedzi bez `isValid` lead byl odrzucany.
 */
$krytyk = new MP_D3_Vat_Critic( 'K3.2', 'Krytyk 3.2 — weryfikuje VAT (VIES)' );

$wynik_zly = $krytyk->review(
	MP_Result::ok(
		array(
			'vat_valid'   => false,
			'vat_checked' => true,
		)
	),
	new MP_Context( array() )
);

vs_ok( ! $wynik_zly->is_ok(), 'krytyk nadal zatrzymuje pipeline przy JAWNIE nieprawidlowym VAT' );

$wynik_nieustalony = $krytyk->review(
	MP_Result::ok(
		array(
			'vat_valid'   => null,
			'vat_checked' => false,
		)
	),
	new MP_Context( array() )
);

vs_ok( $wynik_nieustalony->is_ok(), 'ale „nie ustalono" przepuszcza — lead nie ginie przez awarie VIES' );

/* ==================================================================== G */

$GLOBALS['mp_vs']['lines'][] = '';
$GLOBALS['mp_vs']['lines'][] = '=== G. pole isValid OBECNE, ale bez tresci ===';

/*
 * Ustalenie 1.25 (P1-G13). Straz z sekcji D pytala wylacznie o OBECNOSC klucza:
 *
 *   if ( ! array_key_exists( 'isValid', $body ) ) { ... }
 *
 * `isValid: null` klucz ma, wiec straz go przepuszczala. Ponizej `! empty( null )`
 * dawalo `false`, lagodny fallback wymaga NIEPUSTEGO `userError`, wiec tez nie
 * lapal — i odpowiedz bez zadnego werdyktu konczyla sie jako „numer niewazny",
 * z zerem w cache na 24 h i twardym STOP-em krytyka 3.2. Dokladnie ten skutek,
 * przed ktorym sekcja D mial bronic; straz pytala o zla rzecz.
 *
 * Prawidlowe pytanie brzmi „czy VIES dal uzyteczny werdykt", a uzyteczny werdykt
 * to wartosc logiczna. Gdyby VIES kiedys zaczal oddawac 1/0 zamiast true/false,
 * kod zdegraduje sie do „nie ustalono" i ponowi probe — czyli w strone bezpieczna,
 * a nie w strone odrzucenia leada.
 */
foreach ( array(
	'null'         => null,
	'pusty ciag'   => '',
	'zero tekstem' => '0',
) as $opis => $wartosc ) {
	vs_wyczysc();
	vs_udawaj(
		array(
			'requestDate' => '2026-07-31',
			'isValid'     => $wartosc,
		)
	);

	$g = MP_D3_Agent_Vat::resolve_vies( $kraj, $nip );

	vs_ok(
		null === $g['vat_valid'],
		sprintf( 'isValid = %s NIE znaczy „niewazny"', $opis ),
		'vat_valid=' . var_export( $g['vat_valid'], true )
	);
	vs_ok(
		empty( $g['vat_checked'] ),
		sprintf( 'isValid = %s nie liczy sie jako sprawdzenie', $opis )
	);
	vs_ok(
		false === get_transient( MP_D3_Agent_Vat::vies_cache_key( $kraj, $nip ) ),
		sprintf( 'isValid = %s nie zostawia werdyktu w cache na 24 h', $opis )
	);
}

/*
 * Kontr-asercja do sekcji G: zaostrzenie strazy nie ma prawa ruszyc jedynych
 * dwoch odpowiedzi, ktore VIES naprawde rozstrzyga. Bez tego „naprawa" mogloby
 * znaczyc „nic juz nie rozstrzyga".
 */
vs_wyczysc();
vs_udawaj(
	array(
		'requestDate' => '2026-07-31',
		'isValid'     => false,
	)
);
$g_false = MP_D3_Agent_Vat::resolve_vies( $kraj, $nip );
vs_ok(
	false === $g_false['vat_valid'] && ! empty( $g_false['vat_checked'] ),
	'logiczne false nadal rozstrzyga jako „numer niewazny"',
	'vat_valid=' . var_export( $g_false['vat_valid'], true )
);

vs_wyczysc();
vs_udawaj(
	array(
		'requestDate' => '2026-07-31',
		'isValid'     => true,
		'name'        => 'FIRMA TESTOWA',
	)
);
$g_true = MP_D3_Agent_Vat::resolve_vies( $kraj, $nip );
vs_ok(
	true === $g_true['vat_valid'] && ! empty( $g_true['vat_checked'] ),
	'logiczne true nadal rozstrzyga jako „numer wazny"',
	'vat_valid=' . var_export( $g_true['vat_valid'], true )
);

vs_wyczysc();
remove_all_filters( 'pre_http_request' );
