<?php
/**
 * Ustalenie audytu: mechanizm podatkowy spoza słownika kończył się zerowym VAT-em.
 *
 * Uruchamianie: wp eval-file tests/naprawy/mechanizm-vat-ze-slownika.php
 *
 * Agent 6.2 pytał WYŁĄCZNIE o równość z `'domestic'`. Każda inna wartość — w tym
 * domyślny pusty łańcuch z `get( 'tax_mechanism', '' )`, gdy klucza w kontekście
 * nie ma — omijała naliczanie stawki krajowej i szła gałęzią zerowego VAT-u,
 * mimo że nie ustalono żadnej podstawy prawnej do zera.
 *
 * Agent 6.1 oddaje jedną z trzech wartości i nigdy pustej, a K6.1 pilnuje pola —
 * w pełnym przebiegu ta ścieżka więc nie powstaje. Ale to jest DOKŁADNIE ten sam
 * kształt błędu, który naprawiono w Dziale 10 w tym samym wydaniu: „brak decyzji"
 * i „decyzja o zerze" wyglądały tam tak samo, a różnica trafia na dokument
 * wychodzący do klienta. Naprawa w jednym dziale, a w drugim nie, znaczyłaby,
 * że reguła zależy od tego, którędy dane przyszły.
 *
 * Zero z prawa jest WYLICZONE: odwrotne obciążenie i sprzedaż poza zakresem
 * dyrektywy. Cokolwiek innego jest brakiem decyzji i kończy się odmową.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_mv'] = array(
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
function mv_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_mv']['pass'];
		$GLOBALS['mp_mv']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_mv']['fail'];
	$GLOBALS['mp_mv']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function mv_koniec() {
	if ( empty( $GLOBALS['mp_mv']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_mv'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_mv']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'mv_koniec' );

/**
 * Uruchamia agenta 6.2 dla podanego mechanizmu.
 *
 * @param mixed $mechanizm Wartość klucza `tax_mechanism` (null = klucza nie ma).
 * @return MP_OB_Result
 */
function mv_dzial6( $mechanizm ) {
	$dane = array(
		'items'           => array( array( 'product_id' => 1, 'qty' => 1 ) ),
		'lines'           => array( array( 'unit_grosze' => 10000, 'line_grosze' => 10000 ) ),
		'products'        => array( array( 'id' => 1, 'tax_class' => '', 'tax_status' => 'taxable' ) ),
		'tax_rates'       => array( '' => array( 'rate' => 23.0 ) ),
		'subtotal_grosze' => 10000,
		'discount_total'  => 0,
	);

	if ( null !== $mechanizm ) {
		$dane['tax_mechanism'] = $mechanizm;
	}

	return ( new MP_OB_D6_Agent_Rounding() )->run( new MP_OB_Context( $dane ) );
}

/* ==================================================================== A */

$GLOBALS['mp_mv']['lines'][] = '=== A. mechanizm spoza slownika nie daje cichego zera ===';

$mv_zle = array(
	'pusty lancuch'        => '',
	'literowka'            => 'domestc',
	'wartosc z innej dziedziny' => 'reverse-charge',
	'liczba'               => 0,
	'brak klucza w ogole'  => null,
);

foreach ( $mv_zle as $mv_opis => $mv_wartosc ) {
	$mv_w = mv_dzial6( $mv_wartosc );
	$mv_d = (array) $mv_w->get_data();

	mv_ok(
		! $mv_w->is_ok(),
		'A (' . $mv_opis . '): odmowa zamiast zerowego VAT-u',
		'ok=' . var_export( $mv_w->is_ok(), true ) . ' vat=' . var_export( $mv_d['vat_grosze'] ?? null, true )
	);
}

$mv_pusty = mv_dzial6( '' );

mv_ok(
	'unknown_tax_mechanism' === $mv_pusty->get_code(),
	'A: kod odmowy nazywa rzecz po imieniu',
	'kod=' . $mv_pusty->get_code()
);
$mv_kom = implode( ' ', (array) $mv_pusty->get_errors() );

mv_ok(
	false !== mb_stripos( $mv_kom, 'mechanizm' ),
	'A: komunikat mowi o mechanizmie podatkowym',
	'komunikat=' . $mv_kom
);

/* ==================================================================== B */

$GLOBALS['mp_mv']['lines'][] = '';
$GLOBALS['mp_mv']['lines'][] = '=== B. kontr-asercje: trzy legalne mechanizmy dzialaja ===';

$mv_krajowy = mv_dzial6( 'domestic' );
$mv_dk      = (array) $mv_krajowy->get_data();

mv_ok(
	$mv_krajowy->is_ok() && (int) ( $mv_dk['vat_grosze'] ?? 0 ) > 0,
	'B1: sprzedaz krajowa nadal nalicza VAT',
	'ok=' . var_export( $mv_krajowy->is_ok(), true ) . ' vat=' . var_export( $mv_dk['vat_grosze'] ?? null, true )
);

foreach ( array( 'reverse_charge', 'out_of_scope' ) as $mv_zero ) {
	$mv_w = mv_dzial6( $mv_zero );
	$mv_d = (array) $mv_w->get_data();

	mv_ok(
		$mv_w->is_ok() && 0 === (int) ( $mv_d['vat_grosze'] ?? -1 ),
		'B: ' . $mv_zero . ' — zero Z PRAWA nadal przechodzi',
		'ok=' . var_export( $mv_w->is_ok(), true ) . ' vat=' . var_export( $mv_d['vat_grosze'] ?? null, true )
	);
}

/*
 * Trzy mechanizmy z agenta 6.1 to DOKLADNIE te, ktore przyjmuje agent 6.2.
 * Gdyby ktos dolozyl czwarty w 6.1, ta asercja pokaze, ze 6.2 o nim nie wie —
 * zanim pokaze to faktura klienta.
 */
$mv_z_61 = array();
foreach ( array( 'PL' => 'domestic', 'DE' => 'reverse_charge', 'US' => 'out_of_scope' ) as $mv_kraj => $mv_oczek ) {
	$mv_w61 = ( new MP_OB_D6_Agent_Mechanism() )->run(
		new MP_OB_Context(
			array(
				'client' => array(
					'country'    => $mv_kraj,
					'vat_status' => 'valid',
				),
			)
		)
	);
	$mv_z_61[] = (string) ( $mv_w61->get_data()['tax_mechanism'] ?? '' );

	mv_ok(
		$mv_oczek === (string) ( $mv_w61->get_data()['tax_mechanism'] ?? '' ),
		'B: agent 6.1 dla kraju ' . $mv_kraj . ' oddaje ' . $mv_oczek,
		'jest=' . ( $mv_w61->get_data()['tax_mechanism'] ?? '(brak)' )
	);
}

foreach ( array_unique( $mv_z_61 ) as $mv_m ) {
	mv_ok(
		mv_dzial6( $mv_m )->is_ok(),
		'B: mechanizm „' . $mv_m . '" z agenta 6.1 jest przyjmowany przez 6.2'
	);
}

/* ==================================================================== C */

$GLOBALS['mp_mv']['lines'][] = '';
$GLOBALS['mp_mv']['lines'][] = '=== C. podstawa prawna nie twierdzi wiecej, niz wiemy ===';

/*
 * Art. 196 dyrektywy 2006/112/WE dotyczy USLUG swiadczonych na rzecz podatnika
 * z innego panstwa czlonkowskiego (w zwiazku z art. 44). Dla wewnatrzwspolnotowej
 * DOSTAWY TOWAROW podstawa jest inna — art. 138. Wtyczka stoi na WooCommerce,
 * wiec pozycja jest najczesciej towarem, a drukowany artykul szedl na dokument
 * wychodzacy do klienta jako fakt.
 *
 * Danych pozwalajacych odroznic towar od uslugi tu nie ma. Test pilnuje wiec
 * dwoch rzeczy naraz: ze MECHANIZM jest nazwany (bo to wiemy na pewno) i ze
 * artykul nie jest podany jako jedyny (bo tego nie wiemy).
 */
$mv_ue = ( new MP_OB_D6_Agent_Mechanism() )->run(
	new MP_OB_Context(
		array(
			'client' => array(
				'country'    => 'DE',
				'vat_status' => 'valid',
			),
		)
	)
);
$mv_pod = (string) ( $mv_ue->get_data()['tax_basis'] ?? '' );

mv_ok(
	false !== mb_stripos( $mv_pod, 'odwrotne obciążenie' ),
	'C1: podstawa nazywa mechanizm — to wiemy na pewno',
	'podstawa=' . $mv_pod
);
mv_ok(
	false !== mb_strpos( $mv_pod, '196' ) && false !== mb_strpos( $mv_pod, '138' ),
	'C2: i wymienia OBIE mozliwe podstawy, a nie jedna jako fakt',
	'podstawa=' . $mv_pod
);
mv_ok(
	false !== mb_stripos( $mv_pod, 'usług' ) && false !== mb_stripos( $mv_pod, 'towar' ),
	'C3: z zaznaczeniem, od czego zaleza — uslugi kontra towary',
	'podstawa=' . $mv_pod
);
mv_ok(
	false !== mb_stripos( $mv_pod, 'nabywca' ),
	'C4: i mowi wprost, kto rozlicza podatek'
);

$mv_kraj = (string) ( ( new MP_OB_D6_Agent_Mechanism() )->run(
	new MP_OB_Context( array( 'client' => array( 'country' => 'PL', 'vat_status' => 'valid' ) ) )
)->get_data()['tax_basis'] ?? '' );

mv_ok(
	false !== mb_stripos( $mv_kraj, 'krajowa' ) && false === mb_strpos( $mv_kraj, '196' ),
	'C5: KONTR-ASERCJA — sprzedaz krajowa nie powoluje sie na dyrektywe',
	'podstawa=' . $mv_kraj
);
