<?php
/**
 * P2-G10 — VAT liczony od innej kwoty niz netto na dokumencie.
 *
 * Uruchamianie: wp eval-file tests/naprawy/podstawa-vat.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G10  Dwie podstawy VAT w Dziale 6 nigdy nie byly ze soba uzgadniane
 *
 * Agent 6.2 pracowal na DWOCH podstawach naraz. Raportowane `net_grosze`
 * powstawalo z `subtotal_grosze - discount_total`, a sam VAT — z sumy
 * `lines[*]['line_grosze']`. Zadna kontrola nie porownywala tych liczb ze soba.
 *
 * Rozjazd nie konczyl sie bledem, tylko INNYM PODATKIEM na dokumencie:
 *   - `lines` puste, `subtotal` 1000 zl  -> netto 1000 zl, VAT 0 zl,
 *   - `lines` na 100 zl, `subtotal` 1000 -> netto 1000 zl, VAT 23 zl (2,3%).
 * Oba przypadki konczyly sie SUKCESEM dzialu. Bramka QA6 ich nie widzi, bo
 * sprawdza wylacznie `netto + VAT = brutto`, a ta rownosc jest wtedy spelniona.
 *
 * UCZCIWA UWAGA O ZASIEGU. W pelnym przebiegu pipeline'u krytyk Dzialu 4
 * (`subtotal_mismatch`) pilnuje tej samej sumy kontrolnej i zatrzymalby rozjazd
 * przed Dzialem 6 — to jest obrona w glab, nie dziura otwarta na produkcji.
 * Naprawa i tak jest potrzebna: Dzial 6 nie moze opierac poprawnosci PODATKU
 * na kontroli z innego dzialu, bo to tutaj powstaje liczba idaca na dokument
 * handlowy, a kolejnosc dzialow i sciezki wywolania sa rzecza zmienna.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_pv'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegol.
 * @return bool
 */
function pv_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_pv']['pass'];
		$GLOBALS['mp_pv']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_pv']['fail'];
	$GLOBALS['mp_pv']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Uruchamia Agenta 6.2 na podanym kontekscie.
 *
 * @param array $dane Kontekst.
 * @return MP_OB_Result
 */
function pv_licz( array $dane ) {
	$agent = new MP_OB_D6_Agent_Rounding();

	return $agent->run( new MP_OB_Context( $dane ) );
}

/**
 * Kontekst mechanizmu krajowego z jedna klasa podatkowa.
 *
 * @param int   $subtotal Suma pozycji wg Dzialu 4.
 * @param array $lines    Pozycje.
 * @return array
 */
function pv_kontekst( $subtotal, array $lines ) {
	return array(
		'tax_mechanism'   => 'domestic',
		'tax_rates'       => array( '' => array( 'rate' => 23.0, 'label' => 'VAT 23%' ) ),
		'products'        => array( array( 'tax_class' => '', 'tax_status' => 'taxable' ) ),
		'discount_total'  => 0,
		'subtotal_grosze' => (int) $subtotal,
		'lines'           => $lines,
	);
}

/**
 * Wartosc z wyniku.
 *
 * @param MP_OB_Result $wynik Wynik.
 * @param string       $klucz Klucz.
 * @return mixed
 */
function pv_pole( MP_OB_Result $wynik, $klucz ) {
	$dane = $wynik->get_data();

	return isset( $dane[ $klucz ] ) ? $dane[ $klucz ] : null;
}

$GLOBALS['mp_pv']['lines'][] = '=== A. brak pozycji przy niezerowej sumie ===';

$puste = pv_licz( pv_kontekst( 100000, array() ) );

pv_ok(
	! $puste->is_ok(),
	'dzial odmawia zamiast policzyc VAT 0 od 1000 zl netto',
	'ok=' . var_export( $puste->is_ok(), true ) . ' vat=' . var_export( pv_pole( $puste, 'vat_grosze' ), true )
);
pv_ok(
	'tax_base_mismatch' === $puste->get_code(),
	'odmowa nazywa przyczyne: rozjazd podstaw',
	'kod=' . $puste->get_code()
);

$GLOBALS['mp_pv']['lines'][] = '';
$GLOBALS['mp_pv']['lines'][] = '=== B. pozycje mniejsze niz suma kontrolna ===';

$rozjazd = pv_licz( pv_kontekst( 100000, array( array( 'line_grosze' => 10000 ) ) ) );

pv_ok(
	! $rozjazd->is_ok(),
	'dzial odmawia zamiast policzyc VAT od ulamka kwoty',
	'ok=' . var_export( $rozjazd->is_ok(), true )
		. ' netto=' . var_export( pv_pole( $rozjazd, 'net_grosze' ), true )
		. ' vat=' . var_export( pv_pole( $rozjazd, 'vat_grosze' ), true )
);
pv_ok(
	'tax_base_mismatch' === $rozjazd->get_code(),
	'odmowa nazywa przyczyne takze tutaj',
	'kod=' . $rozjazd->get_code()
);

$GLOBALS['mp_pv']['lines'][] = '';
$GLOBALS['mp_pv']['lines'][] = '=== C. KONTR-ASERCJE: poprawne oferty licza sie jak dotad ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na odrzucaniu wszystkiego, co ma puste
 * `lines` — a to zablokowaloby odwrotne obciazenie i oferte pusta, czyli stany
 * calkowicie poprawne.
 */
$zgodne = pv_licz( pv_kontekst( 100000, array( array( 'line_grosze' => 100000 ) ) ) );

pv_ok( $zgodne->is_ok(), 'zgodne podstawy przechodza', 'kod=' . $zgodne->get_code() );
pv_ok(
	100000 === pv_pole( $zgodne, 'net_grosze' ) && 23000 === pv_pole( $zgodne, 'vat_grosze' ) && 123000 === pv_pole( $zgodne, 'gross_grosze' ),
	'VAT policzony od pelnej podstawy: 1000 zl + 230 zl = 1230 zl',
	'netto=' . var_export( pv_pole( $zgodne, 'net_grosze' ), true )
		. ' vat=' . var_export( pv_pole( $zgodne, 'vat_grosze' ), true )
		. ' brutto=' . var_export( pv_pole( $zgodne, 'gross_grosze' ), true )
);

$z_rabatem = pv_licz(
	array_merge(
		pv_kontekst( 100000, array( array( 'line_grosze' => 100000 ) ) ),
		array( 'discount_total' => 10000 )
	)
);

pv_ok(
	$z_rabatem->is_ok() && 90000 === pv_pole( $z_rabatem, 'net_grosze' ) && 20700 === pv_pole( $z_rabatem, 'vat_grosze' ),
	'rabat nadal dziala — podstawa to suma pozycji, nie kwota po rabacie',
	'kod=' . $z_rabatem->get_code()
		. ' netto=' . var_export( pv_pole( $z_rabatem, 'net_grosze' ), true )
		. ' vat=' . var_export( pv_pole( $z_rabatem, 'vat_grosze' ), true )
);

$pusta_oferta = pv_licz( pv_kontekst( 0, array() ) );

pv_ok(
	$pusta_oferta->is_ok() && 0 === pv_pole( $pusta_oferta, 'vat_grosze' ),
	'oferta pusta (0 i 0) nadal przechodzi',
	'kod=' . $pusta_oferta->get_code()
);

$odwrotne = pv_licz(
	array(
		'tax_mechanism'   => 'reverse_charge',
		'subtotal_grosze' => 100000,
		'discount_total'  => 0,
		'lines'           => array(),
	)
);

pv_ok(
	$odwrotne->is_ok() && 0 === pv_pole( $odwrotne, 'vat_grosze' ) && 100000 === pv_pole( $odwrotne, 'gross_grosze' ),
	'odwrotne obciazenie liczy 0% z wlasnej podstawy prawnej, bez pozycji',
	'kod=' . $odwrotne->get_code() . ' vat=' . var_export( pv_pole( $odwrotne, 'vat_grosze' ), true )
);

$GLOBALS['mp_pv']['lines'][] = '';
$GLOBALS['mp_pv']['lines'][] = '=== F. jedna podstawa obowiazuje KAZDY mechanizm, nie tylko krajowy ===';

/*
 * Kontrola zgodnosci podstaw siedziala WYLACZNIE w galezi 'domestic'. Dla
 * odwrotnego obciazenia i poza zakresem VAT bylo 0% z mocy prawa, wiec problem
 * „VAT policzony od innej kwoty" faktycznie nie moze tam wystapic — i to jest
 * powod, dla ktorego ustalenie audytu NIE zostalo przyjete w calosci.
 *
 * Zostaje jednak druga polowa szkody, wspolna dla wszystkich mechanizmow: gdy
 * pozycje SA, ale nie sumuja sie do podstawy, dokument pokazuje liste pozycji,
 * ktora nie dodaje sie do wlasnego netto. Dzial 10 zapisuje pozycje po jednej,
 * wiec rozjazd trafia wprost na papier dla klienta.
 *
 * ZAWEZENIE, ktore to godzi: kontrola obejmuje kazdy mechanizm, ale tylko gdy
 * pozycje sa NIEPUSTE. Oferta bez pozycji zostaje dopuszczona tak jak dotad —
 * to osobna, swiadoma decyzja, ktorej pilnuje asercja tuz powyzej.
 */
$odwrotne_rozjazd = pv_licz(
	array(
		'tax_mechanism'   => 'reverse_charge',
		'subtotal_grosze' => 100000,
		'discount_total'  => 0,
		'lines'           => array(
			array( 'line_grosze' => 50000 ),
		),
		'products'        => array(
			array( 'tax_class' => '' ),
		),
	)
);

pv_ok(
	! $odwrotne_rozjazd->is_ok() && 'tax_base_mismatch' === $odwrotne_rozjazd->get_code(),
	'F1: odwrotne obciazenie z pozycjami, ktore nie sumuja sie do podstawy, jest zatrzymane',
	'kod=' . $odwrotne_rozjazd->get_code()
);

$poza_rozjazd = pv_licz(
	array(
		'tax_mechanism'   => 'out_of_scope',
		'subtotal_grosze' => 100000,
		'discount_total'  => 0,
		'lines'           => array(
			array( 'line_grosze' => 120000 ),
		),
		'products'        => array(
			array( 'tax_class' => '' ),
		),
	)
);

pv_ok(
	! $poza_rozjazd->is_ok() && 'tax_base_mismatch' === $poza_rozjazd->get_code(),
	'F2: poza zakresem VAT tak samo — rozjazd w gore tez jest rozjazdem',
	'kod=' . $poza_rozjazd->get_code()
);

$odwrotne_zgodne = pv_licz(
	array(
		'tax_mechanism'   => 'reverse_charge',
		'subtotal_grosze' => 100000,
		'discount_total'  => 0,
		'lines'           => array(
			array( 'line_grosze' => 100000 ),
		),
		'products'        => array(
			array( 'tax_class' => '' ),
		),
	)
);

pv_ok(
	$odwrotne_zgodne->is_ok() && 0 === pv_pole( $odwrotne_zgodne, 'vat_grosze' ),
	'F3: KONTR-ASERCJA — zgodne pozycje przy odwrotnym obciazeniu nadal przechodza, nadal 0%',
	'kod=' . $odwrotne_zgodne->get_code()
);

pv_ok(
	$odwrotne->is_ok(),
	'F4: KONTR-ASERCJA — oferta BEZ pozycji nadal przechodzi, decyzja z tego pliku zostaje',
	'kod=' . $odwrotne->get_code()
);

$GLOBALS['mp_pv']['lines'][] = '';
$GLOBALS['mp_pv']['lines'][] = '=== G. pozycja bez odpowiednika w snapshocie nie znika po cichu ===';

/*
 * `$products[ $i ]` bylo czytane bez isset() dokladnie w linii poprzedzajacej te,
 * ktora ten sam odczyt guardem juz oslania. Brak wpisu dawal wiec ostrzezenie PHP
 * i klase podatkowa '' — czyli pozycja wpadala do kubelka stawki podstawowej,
 * zamiast zatrzymac oferte. Test mierzy to ostrzezeniem, bo to jedyny slad, jaki
 * ten blad po sobie zostawia.
 */
$GLOBALS['mp_pv_ostrzezenia'] = array();
set_error_handler(
	static function ( $nr, $tekst ) {
		$GLOBALS['mp_pv_ostrzezenia'][] = $tekst;

		return true;
	},
	E_WARNING | E_NOTICE
);

$bez_snapshotu = pv_licz(
	array(
		'tax_mechanism'   => 'domestic',
		'subtotal_grosze' => 100000,
		'discount_total'  => 0,
		'lines'           => array(
			array( 'line_grosze' => 100000 ),
		),
		'products'        => array(),
	)
);

restore_error_handler();

pv_ok(
	array() === $GLOBALS['mp_pv_ostrzezenia'],
	'G1: brak pozycji w snapshocie nie wywoluje ostrzezenia PHP o nieistniejacym kluczu',
	'ostrzezenia=' . implode( ' | ', $GLOBALS['mp_pv_ostrzezenia'] )
);

pv_ok(
	! $bez_snapshotu->is_ok(),
	'G2: i nie konczy sie po cichu sukcesem z klasa podatkowa wzieta z powietrza',
	'kod=' . $bez_snapshotu->get_code() . ' vat=' . var_export( pv_pole( $bez_snapshotu, 'vat_grosze' ), true )
);

echo implode( "\n", $GLOBALS['mp_pv']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_pv']['pass'], $GLOBALS['mp_pv']['fail'] );
echo ( 0 === $GLOBALS['mp_pv']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
