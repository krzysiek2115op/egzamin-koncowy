<?php
/**
 * Dzial 6, Agent 6.2 — podstawa VAT miala dwie skladowe, a pilnowana byla jedna.
 *
 * Uruchamianie: wp eval-file tests/naprawy/podstawa-vat-dwie-skladowe.php
 *
 * Piec ustalen z audytu glebokiego (para 1.25):
 *
 * 1. `net_grosze = subtotal_grosze - discount_total`. Sprawdzane bylo wylacznie,
 *    czy suma pozycji rowna sie `subtotal_grosze` — `discount_total` nie mial
 *    zadnego ograniczenia ani z dolu, ani z gory. Rabat wiekszy od sumy daje
 *    UJEMNA podstawe VAT, a dzial mimo to konczy sie sukcesem.
 *
 * 2. Kontrola zgodnosci podstawy (`tax_base_mismatch`) siedziala WEWNATRZ galezi
 *    `if ( 'domestic' === $mechanism )`. Dla `reverse_charge` i `out_of_scope`
 *    nikt nie porownywal `net_grosze` z suma pozycji.
 *
 * 3. Pozycje parowaly sie ze snapshotem produktow wylacznie po indeksie
 *    tablicy, a jedyny guard sprawdzal ISTNIENIE klucza, nie tozsamosc produktu.
 *    Przesuniecie indeksow dawalo pozycji cudza klase podatkowa i cudza stawke,
 *    bez zadnego bledu.
 *
 * 4. Stawka VAT byla sprawdzana wylacznie przez `is_numeric()`, bez zakresu.
 *    Wartosc ujemna albo podana w zlej jednostce (ulamek zamiast procentu)
 *    przechodzila i dawala cichy, bledny podatek.
 *
 * 5. Docblock `prorate()` deklarowal podzial „wylacznie arytmetyka calkowita",
 *    a sciezka zapasowa wykonywala dzielenie zmiennoprzecinkowe.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_pd'] = array(
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
function pd_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_pd']['pass'];
		$GLOBALS['mp_pd']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_pd']['fail'];
	$GLOBALS['mp_pd']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Uruchamia Agenta 6.2.
 *
 * @param array $dane Kontekst.
 * @return MP_OB_Result
 */
function pd_licz( array $dane ) {
	$agent = new MP_OB_D6_Agent_Rounding();

	return $agent->run( new MP_OB_Context( $dane ) );
}

/**
 * Kontekst krajowy: dwie pozycje po 100,00 zl, jedna klasa podatkowa.
 *
 * @param array $zmiany Nadpisania.
 * @return array
 */
function pd_kontekst( array $zmiany = array() ) {
	return array_merge(
		array(
			'tax_mechanism'   => 'domestic',
			'tax_rates'       => array( '' => array( 'rate' => 23.0, 'label' => 'VAT 23%' ) ),
			'items'           => array(
				array( 'product_id' => 11 ),
				array( 'product_id' => 22 ),
			),
			'products'        => array(
				array( 'id' => 11, 'tax_class' => '', 'tax_status' => 'taxable' ),
				array( 'id' => 22, 'tax_class' => '', 'tax_status' => 'taxable' ),
			),
			'lines'           => array(
				array( 'unit_grosze' => 10000, 'line_grosze' => 10000 ),
				array( 'unit_grosze' => 10000, 'line_grosze' => 10000 ),
			),
			'discount_total'  => 0,
			'subtotal_grosze' => 20000,
		),
		$zmiany
	);
}

$GLOBALS['mp_pd']['lines'][] = '=== A. rabat nie moze przekroczyc sumy pozycji ===';

$pd_kontrola = pd_licz( pd_kontekst() );

pd_ok(
	$pd_kontrola->is_ok(),
	'A0: (zalozenie testu) poprawny koszyk przechodzi',
	'kod=' . $pd_kontrola->get_code()
);

$pd_za_duzy = pd_licz( pd_kontekst( array( 'discount_total' => 30000 ) ) );
$pd_dane_zd = (array) $pd_za_duzy->get_data();

pd_ok(
	! $pd_za_duzy->is_ok(),
	'A1: rabat wiekszy od sumy pozycji zatrzymuje dzial',
	'kod=' . $pd_za_duzy->get_code() . ' netto=' . ( $pd_dane_zd['net_grosze'] ?? '(brak)' )
);

$pd_ujemny = pd_licz( pd_kontekst( array( 'discount_total' => -5000 ) ) );

pd_ok(
	! $pd_ujemny->is_ok(),
	'A2: ujemny rabat tez — to podwyzka udajaca rabat',
	'kod=' . $pd_ujemny->get_code()
);

// KONTR-ASERCJA: rabat rowny sumie to zero do zaplaty, ale nie blad.
$pd_caly = pd_licz( pd_kontekst( array( 'discount_total' => 20000 ) ) );
$pd_dane_caly = (array) $pd_caly->get_data();

pd_ok(
	$pd_caly->is_ok() && 0 === (int) ( $pd_dane_caly['net_grosze'] ?? -1 ),
	'A3: KONTR-ASERCJA — rabat rowny sumie daje zero netto i przechodzi',
	'kod=' . $pd_caly->get_code() . ' netto=' . ( $pd_dane_caly['net_grosze'] ?? '(brak)' )
);

// KONTR-ASERCJA: zwykly rabat czastkowy liczy sie jak dotad.
$pd_zwykly = pd_licz( pd_kontekst( array( 'discount_total' => 2000 ) ) );
$pd_dane_zw = (array) $pd_zwykly->get_data();

pd_ok(
	$pd_zwykly->is_ok() && 18000 === (int) ( $pd_dane_zw['net_grosze'] ?? 0 ) && 4140 === (int) ( $pd_dane_zw['vat_grosze'] ?? 0 ),
	'A4: KONTR-ASERCJA — rabat 20,00 zl: netto 180,00 zl, VAT 41,40 zl',
	'netto=' . ( $pd_dane_zw['net_grosze'] ?? '?' ) . ' vat=' . ( $pd_dane_zw['vat_grosze'] ?? '?' )
);

$GLOBALS['mp_pd']['lines'][] = '';
$GLOBALS['mp_pd']['lines'][] = '=== B. podstawa poza mechanizmem krajowym — audyt sie pomylil ===';

/*
 * USTALENIE ODRZUCONE, sekcja zostaje jako straz.
 *
 * Audyt zglosil, ze kontrola zgodnosci podstawy siedzi wylacznie w galezi
 * `if ( 'domestic' === $mechanism )`, wiec `reverse_charge` i `out_of_scope`
 * przyjmuja netto bez porownania z suma pozycji. Nieprawda: gałąź niekrajowa ma
 * wlasny odpowiednik tej kontroli, swiadomie zawezony do pozycji NIEPUSTYCH
 * (oferta bez pozycji przy odwrotnym obciazeniu jest dopuszczona) — z komentarzem
 * tlumaczacym, czemu nie jest to kopia jeden do jednego. Model zobaczyl pierwsza
 * kontrole i orzekl o calosci pliku.
 *
 * Asercje ponizej PRZECHODZILY juz przed naprawa. Zostaja, zeby nikt tej kontroli
 * nie usunal w ramach porzadkow.
 */
$pd_rc = pd_licz(
	pd_kontekst(
		array(
			'tax_mechanism'   => 'reverse_charge',
			'subtotal_grosze' => 50000,
		)
	)
);

pd_ok(
	! $pd_rc->is_ok() && 'tax_base_mismatch' === $pd_rc->get_code(),
	'B1: reverse_charge z podstawa niezgodna z suma pozycji → odmowa',
	'kod=' . $pd_rc->get_code()
);

$pd_oos = pd_licz(
	pd_kontekst(
		array(
			'tax_mechanism'   => 'out_of_scope',
			'subtotal_grosze' => 50000,
		)
	)
);

pd_ok(
	! $pd_oos->is_ok() && 'tax_base_mismatch' === $pd_oos->get_code(),
	'B2: out_of_scope tak samo',
	'kod=' . $pd_oos->get_code()
);

// KONTR-ASERCJA: zgodna koperta w tych mechanizmach ma przechodzic z VAT 0.
$pd_rc_ok   = pd_licz( pd_kontekst( array( 'tax_mechanism' => 'reverse_charge' ) ) );
$pd_dane_rc = (array) $pd_rc_ok->get_data();

pd_ok(
	$pd_rc_ok->is_ok() && 0 === (int) ( $pd_dane_rc['vat_grosze'] ?? -1 ) && 20000 === (int) ( $pd_dane_rc['net_grosze'] ?? 0 ),
	'B3: KONTR-ASERCJA — zgodny reverse_charge nadal przechodzi z VAT 0',
	'kod=' . $pd_rc_ok->get_code() . ' netto=' . ( $pd_dane_rc['net_grosze'] ?? '?' )
);

$GLOBALS['mp_pd']['lines'][] = '';
$GLOBALS['mp_pd']['lines'][] = '=== C. pozycja i produkt to ma byc TEN SAM produkt ===';

/*
 * Guard sprawdzal wylacznie, czy klucz istnieje. Przesuniecie zbioru produktow
 * (np. gdy Dzial 2 odsial jedna pozycje) dawalo pozycji cudza klase podatkowa
 * i cudza stawke — bez bledu, bez sladu, z innym podatkiem na dokumencie.
 */
/*
 * Fikstura musi byc dobrana tak, zeby STARY kod nie zauwazyl niczego: obie klasy
 * podatkowe maja stawke (wiec nie ma `missing_tax_rate`), a sumy pozycji zgadzaja
 * sie z podstawa. Rozni sie wylacznie PODATEK: przy poprawnym sparowaniu
 * 100,00 zl idzie po 23%, a 300,00 zl po 8% (razem 47,00 zl); po zamianie
 * miejscami wychodzi 77,00 zl. Zaden warunek tego nie lapal.
 */
$pd_dwie_klasy = array(
	'tax_rates' => array(
		''         => array( 'rate' => 23.0, 'label' => 'VAT 23%' ),
		'obnizona' => array( 'rate' => 8.0, 'label' => 'VAT 8%' ),
	),
	'lines'     => array(
		array( 'unit_grosze' => 10000, 'line_grosze' => 10000 ),
		array( 'unit_grosze' => 30000, 'line_grosze' => 30000 ),
	),
	'subtotal_grosze' => 40000,
);

$pd_przesuniecie = pd_licz(
	pd_kontekst(
		array_merge(
			$pd_dwie_klasy,
			array(
				'products' => array(
					// Kolejnosc odwrocona wzgledem `items`.
					array( 'id' => 22, 'tax_class' => 'obnizona', 'tax_status' => 'taxable' ),
					array( 'id' => 11, 'tax_class' => '', 'tax_status' => 'taxable' ),
				),
			)
		)
	)
);

pd_ok(
	! $pd_przesuniecie->is_ok() && 'line_product_mismatch' === $pd_przesuniecie->get_code(),
	'C1: snapshot produktow w innej kolejnosci niz pozycje → odmowa',
	'kod=' . $pd_przesuniecie->get_code() . ' vat=' . ( ( (array) $pd_przesuniecie->get_data() )['vat_grosze'] ?? '(brak)' )
);

// KONTR-ASERCJA: poprawne sparowanie tych samych danych ma dac 47,00 zl podatku.
$pd_poprawne = pd_licz(
	pd_kontekst(
		array_merge(
			$pd_dwie_klasy,
			array(
				'products' => array(
					array( 'id' => 11, 'tax_class' => '', 'tax_status' => 'taxable' ),
					array( 'id' => 22, 'tax_class' => 'obnizona', 'tax_status' => 'taxable' ),
				),
			)
		)
	)
);
$pd_dane_popr = (array) $pd_poprawne->get_data();

pd_ok(
	$pd_poprawne->is_ok() && 4700 === (int) ( $pd_dane_popr['vat_grosze'] ?? 0 ),
	'C1b: KONTR-ASERCJA — wlasciwe sparowanie liczy 23% od 100 zl i 8% od 300 zl',
	'kod=' . $pd_poprawne->get_code() . ' vat=' . ( $pd_dane_popr['vat_grosze'] ?? '?' )
);

// KONTR-ASERCJA: brak identyfikatorow w snapshocie nie moze wywracac dzialu
// (starsze konteksty i testy jednostkowe podaja sam tax_class).
$pd_bez_id = pd_licz(
	pd_kontekst(
		array(
			'products' => array(
				array( 'tax_class' => '', 'tax_status' => 'taxable' ),
				array( 'tax_class' => '', 'tax_status' => 'taxable' ),
			),
		)
	)
);

pd_ok(
	$pd_bez_id->is_ok(),
	'C2: KONTR-ASERCJA — snapshot bez identyfikatorow dziala jak dotad',
	'kod=' . $pd_bez_id->get_code()
);

$GLOBALS['mp_pd']['lines'][] = '';
$GLOBALS['mp_pd']['lines'][] = '=== D. stawka VAT ma miescic sie w zakresie ===';

$pd_ujemna_stawka = pd_licz(
	pd_kontekst( array( 'tax_rates' => array( '' => array( 'rate' => -23.0, 'label' => 'VAT' ) ) ) )
);

pd_ok(
	! $pd_ujemna_stawka->is_ok(),
	'D1: ujemna stawka VAT nie przechodzi',
	'kod=' . $pd_ujemna_stawka->get_code()
);

$pd_absurd = pd_licz(
	pd_kontekst( array( 'tax_rates' => array( '' => array( 'rate' => 2300.0, 'label' => 'VAT' ) ) ) )
);

pd_ok(
	! $pd_absurd->is_ok(),
	'D2: stawka 2300% (procent pomylony z ulamkiem) tez nie',
	'kod=' . $pd_absurd->get_code()
);

// KONTR-ASERCJA: prawdziwe stawki maja przechodzic, ze stawka zerowa wlacznie.
$pd_zero = pd_licz(
	pd_kontekst( array( 'tax_rates' => array( '' => array( 'rate' => 0.0, 'label' => 'VAT 0%' ) ) ) )
);
$pd_dane_zero = (array) $pd_zero->get_data();

pd_ok(
	$pd_zero->is_ok() && 0 === (int) ( $pd_dane_zero['vat_grosze'] ?? -1 ),
	'D3: KONTR-ASERCJA — stawka 0% to legalna stawka, nie brak stawki',
	'kod=' . $pd_zero->get_code()
);

$pd_osiem = pd_licz(
	pd_kontekst( array( 'tax_rates' => array( '' => array( 'rate' => 8.0, 'label' => 'VAT 8%' ) ) ) )
);
$pd_dane_osiem = (array) $pd_osiem->get_data();

pd_ok(
	$pd_osiem->is_ok() && 1600 === (int) ( $pd_dane_osiem['vat_grosze'] ?? 0 ),
	'D4: KONTR-ASERCJA — 8% od 200,00 zl to 16,00 zl',
	'vat=' . ( $pd_dane_osiem['vat_grosze'] ?? '?' )
);

$GLOBALS['mp_pd']['lines'][] = '';
$GLOBALS['mp_pd']['lines'][] = '=== E. „arytmetyka calkowita" ma znaczyc arytmetyke calkowita ===';

/*
 * Asercja na kodzie, nie na zachowaniu: w tym srodowisku BCMath JEST zaladowany,
 * wiec sciezka zapasowa nigdy sie nie wykona i zadne wywolanie jej nie sprawdzi.
 * A to wlasnie o nia chodzi — o instalacje bez BCMath, gdzie dzielenie
 * zmiennoprzecinkowe traci precyzje na kwotach, ktorych docblock obiecuje nie
 * tracic.
 */
$pd_zrodlo = (string) file_get_contents( dirname( __DIR__ ) . '/../includes/pipeline/departments/class-mp-ob-department-06.php' );
$pd_od     = strpos( $pd_zrodlo, 'private static function prorate' );
$pd_cialo  = false === $pd_od ? '' : substr( $pd_zrodlo, $pd_od, 600 );
$pd_koniec = strpos( $pd_cialo, "\n\t}" );
$pd_cialo  = false === $pd_koniec ? $pd_cialo : substr( $pd_cialo, 0, $pd_koniec );

pd_ok(
	'' !== $pd_cialo,
	'E0: (zalozenie testu) cialo prorate() znalezione'
);
pd_ok(
	false === strpos( $pd_cialo, 'floor(' ),
	'E1: sciezka zapasowa nie dzieli zmiennoprzecinkowo',
	'cialo=' . $pd_cialo
);
pd_ok(
	false !== strpos( $pd_cialo, 'intdiv(' ),
	'E2: dzieli calkowicie — tak, jak obiecuje docblock',
	'cialo=' . $pd_cialo
);

echo implode( "\n", $GLOBALS['mp_pd']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_pd']['pass'], $GLOBALS['mp_pd']['fail'] );
echo ( 0 === $GLOBALS['mp_pd']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
