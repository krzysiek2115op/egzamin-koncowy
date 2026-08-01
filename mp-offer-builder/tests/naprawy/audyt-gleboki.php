<?php
/**
 * Trzy ustalenia z glebokiego przebiegu audytu (pary 1.25 i 1.26).
 *
 * Uruchamianie: wp eval-file tests/naprawy/audyt-gleboki.php
 *
 * A. Agent 2.3 budowal zbior klas podatkowych ze WSZYSTKICH pozycji, nie
 *    patrzac na `tax_status`. Pozycja zwolniona z VAT w klasie bez
 *    skonfigurowanej stawki wywracala caly Dzial 2 bledem 'missing_tax_rate'
 *    — mimo ze Dzial 6 tej stawki i tak by nie uzyl (traktuje 'none' jako 0%).
 *
 * B. Limity dlugosci pol mierzone byly `strlen()`, czyli w BAJTACH, przy
 *    limitach przepisanych z `varchar(191)`, ktore w utf8mb4 liczy ZNAKI.
 *    Polska nazwa firmy z ogonkami przekraczala limit w bajtach, miescac sie
 *    w kolumnie — Dzial 10 odrzucal zapis komunikatem o „limicie 191 znakow",
 *    ktory dla 120-znakowej nazwy byl po prostu nieprawdziwy.
 *
 * C. Nieudany `$wpdb->update()` przy zatwierdzaniu wracal kodem
 *    'already_approved', a ten jest zmapowany na NIEBIESKI komunikat
 *    informacyjny „nic sie nie zmienilo". `update()` zwraca `false` przy
 *    bledzie zapytania i `0`, gdy zaden wiersz nie pasowal — dwie rozne
 *    rzeczy z jednym komunikatem. Przy awarii zapisu pracownik czytal, ze
 *    wszystko w porzadku, a oferta zostawala szkicem.
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G1  Klasy podatkowe brane z pozycji zwolnionych z VAT
 *   - P2-G2  Limity pol mierzone w bajtach zamiast w znakach
 *   - P2-G3  Blad bazy przy zatwierdzaniu raportowany jako „juz zatwierdzona"
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_ag'] = array(
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
function ag_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_ag']['pass'];
		$GLOBALS['mp_ag']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_ag']['fail'];
	$GLOBALS['mp_ag']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym.
 *
 * @return void
 */
function ag_dump() {
	if ( empty( $GLOBALS['mp_ag']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_ag'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_ag']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'ag_dump' );

if ( ! function_exists( 'wc_get_products' ) || ! class_exists( 'WC_Tax' ) ) {
	ag_ok( false, 'WooCommerce jest dostepne' );
	return;
}

global $wpdb;

/* ==================================================================== A */

$GLOBALS['mp_ag']['lines'][] = '=== A. pozycja zwolniona z VAT nie wywraca Dzialu 2 ===';

/*
 * Klasa podatkowa BEZ ani jednego wiersza stawki. Dokladnie tak wyglada
 * domyslna klasa „Zero rate" w swiezej instalacji WooCommerce: istnieje
 * w slowniku, nie ma skonfigurowanych stawek. Nazwa wlasna, zeby test nie
 * zalezal od tego, co klient ma w sklepie.
 */
$klasa_pusta = 'mp-test-bez-stawki';

/*
 * Klase zaklada WC_Tax::create_tax_class(), NIE zapis do opcji
 * `woocommerce_tax_classes`. Roznica jest istotna: przy samym zapisie opcji
 * `WC_Product::set_tax_class()` odrzuca nieznany slug i po cichu wraca do
 * klasy standardowej — obie pozycje testowe ladowaly wtedy w klasie, ktora
 * stawke MA, i test przechodzil takze na kodzie sprzed naprawy.
 */
if ( ! in_array( $klasa_pusta, WC_Tax::get_tax_class_slugs(), true ) ) {
	WC_Tax::create_tax_class( 'MP Test Bez Stawki' );
}

// Stawka dla klasy standardowej — zeby pozycja opodatkowana miala czym przejsc.
$ma_standardowa = ! empty( WC_Tax::get_base_tax_rates( '' ) );

if ( ! $ma_standardowa ) {
	WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => 'PL',
			'tax_rate'          => '23.0000',
			'tax_rate_name'     => 'VAT',
			'tax_rate_priority' => 1,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => '',
		)
	);
}

ag_ok( ! empty( WC_Tax::get_base_tax_rates( '' ) ), 'klasa standardowa ma stawke' );
ag_ok( empty( WC_Tax::get_base_tax_rates( $klasa_pusta ) ), 'klasa testowa NIE ma stawki — to jest warunek scenariusza' );

$zwolniony = new WC_Product_Simple();
$zwolniony->set_name( 'MP test 1.25 — usluga zwolniona' );
$zwolniony->set_regular_price( '500' );
$zwolniony->set_status( 'publish' );
$zwolniony->set_tax_status( 'none' );
$zwolniony->set_tax_class( $klasa_pusta );
$id_zwolniony = (int) $zwolniony->save();

$zwykly = new WC_Product_Simple();
$zwykly->set_name( 'MP test 1.25 — towar opodatkowany' );
$zwykly->set_regular_price( '100' );
$zwykly->set_status( 'publish' );
$zwykly->set_tax_status( 'taxable' );
$zwykly->set_tax_class( '' );
$id_zwykly = (int) $zwykly->save();

$items = array(
	array(
		'product_id'   => $id_zwykly,
		'variation_id' => null,
		'qty'          => 1,
	),
	array(
		'product_id'   => $id_zwolniony,
		'variation_id' => null,
		'qty'          => 1,
	),
);

$kontekst = new MP_OB_Context( array( 'items' => $items ) );

$a21 = new MP_OB_D2_Agent_Products();
$w21 = $a21->run( $kontekst );

ag_ok( $w21->is_ok(), 'Agent 2.1 rozpoznaje obie pozycje' );

if ( $w21->is_ok() ) {
	$produkty = (array) $w21->get_data()['products'];
	$kontekst->set( 'products', $produkty );
	$statusy  = wp_list_pluck( $produkty, 'tax_status' );

	ag_ok(
		in_array( 'none', $statusy, true ) && in_array( 'taxable', $statusy, true ),
		'Agent 2.1 zapisal oba statusy podatkowe',
		'statusy=' . implode( ',', $statusy )
	);

	// Bez tej asercji cala sekcja A moglaby przejsc na pusto: gdyby WooCommerce
	// odrzucilo klase testowa, obie pozycje siedzialyby w klasie ze stawka.
	ag_ok(
		in_array( $klasa_pusta, wp_list_pluck( $produkty, 'tax_class' ), true ),
		'pozycja zwolniona naprawde siedzi w klasie bez stawki',
		'klasy=' . implode( ',', wp_list_pluck( $produkty, 'tax_class' ) )
	);

	$a23 = new MP_OB_D2_Agent_Tax();
	$w23 = $a23->run( $kontekst );

	/*
	 * SEDNO. Klasa pozycji ZWOLNIONEJ nie ma stawki i nigdy nie bedzie
	 * potrzebna — Dzial 6 wyprowadza 'none' do wlasnej klasy i jawnie zwalnia
	 * ja z wymogu stawki. Dzial 2 nie ma prawa z tego powodu odrzucic oferty.
	 */
	ag_ok(
		$w23->is_ok(),
		'Dzial 2 przechodzi mimo pozycji zwolnionej w klasie bez stawki',
		$w23->is_ok() ? '' : 'kod=' . $w23->get_code()
	);

	if ( $w23->is_ok() ) {
		$stawki = array_keys( (array) $w23->get_data()['tax_rates'] );

		ag_ok(
			in_array( '', $stawki, true ),
			'klasa pozycji opodatkowanej nadal jest w zbiorze',
			'klasy=' . implode( ',', $stawki )
		);
		ag_ok(
			! in_array( $klasa_pusta, $stawki, true ),
			'klasa wylacznie pozycji zwolnionej nie trafia do zbioru',
			'klasy=' . implode( ',', $stawki )
		);
	}
}

/*
 * A2 (P2-G5). Koszyk zlozony WYLACZNIE z pozycji zwolnionych.
 *
 * Naprawa P2-G1 pomija klasy pozycji zwolnionych przy zbieraniu stawek. Gdy
 * zwolniona jest KAZDA pozycja, zbior stawek wychodzi pusty — i wtedy krytyk
 * K2.3 (MP_OB_Array_Critic na kluczu `tax_rates`) odrzucal caly dzial, bo jego
 * jedyny warunek brzmial „tablica ma byc niepusta". Pusty zbior znaczy tu
 * jednak „zadna stawka nie byla potrzebna", a nie „stawki brakuje".
 *
 * Przypadek nie jest wydumany: oferta na same uslugi zwolnione z VAT (art. 43
 * ustawy o VAT — szkolenia, uslugi medyczne, finansowe) to normalna oferta.
 * Sekcja A powyzej go nie lapala, bo ma koszyk MIESZANY — zawsze zostawala
 * przynajmniej jedna klasa opodatkowana.
 */
$GLOBALS['mp_ag']['lines'][] = '';
$GLOBALS['mp_ag']['lines'][] = '=== A2. koszyk wylacznie ze zwolnien ===';

$kontekst_z = new MP_OB_Context(
	array(
		'items' => array(
			array(
				'product_id'   => $id_zwolniony,
				'variation_id' => null,
				'qty'          => 3,
			),
		),
	)
);

$w21z = ( new MP_OB_D2_Agent_Products() )->run( $kontekst_z );
ag_ok( $w21z->is_ok(), 'Agent 2.1 rozpoznaje pozycje zwolniona' );

if ( $w21z->is_ok() ) {
	$kontekst_z->set( 'products', (array) $w21z->get_data()['products'] );

	$a23z = new MP_OB_D2_Agent_Tax();
	$w23z = $a23z->run( $kontekst_z );

	ag_ok(
		$w23z->is_ok(),
		'Agent 2.3 przechodzi, gdy zwolnione sa wszystkie pozycje',
		$w23z->is_ok() ? '' : 'kod=' . $w23z->get_code()
	);

	if ( $w23z->is_ok() ) {
		ag_ok(
			array() === (array) $w23z->get_data()['tax_rates'],
			'zbior stawek jest pusty — bo zadna nie byla potrzebna'
		);

		// SEDNO A2: krytyk musi przepuscic pusty zbior w tym przypadku.
		$k23z = new MP_OB_D2_Tax_Critic( 'K2.3', 'Krytyk 2.3 — stawka-istnieje' );
		$r23z = $k23z->review( $w23z, $kontekst_z );

		ag_ok(
			$r23z->is_ok(),
			'Krytyk 2.3 przepuszcza pusty zbior przy samych zwolnieniach',
			$r23z->is_ok() ? '' : 'kod=' . $r23z->get_code()
		);
	}
}

/*
 * A3 (P2-G6). „Tylko wysylka" (`tax_status = 'shipping'`) wpadalo w szczeline
 * miedzy dzialami.
 *
 * WooCommerce ma TRZY statusy podatkowe: 'taxable', 'shipping', 'none'.
 * Dzial 2 pomijal przy zbieraniu stawek KAZDY status rozny od 'taxable',
 * a Dzial 6 zwalnial z VAT wylacznie 'none'. Pozycja 'shipping' trafiala
 * wiec miedzy nie: klasa bez stawki w snapshocie, a mimo to naliczana stawka
 * — albo STOP `missing_tax_rate` na ofercie, ktorej nic nie brakowalo.
 *
 * Poprawka nie polega na dopisaniu 'shipping' w drugim miejscu, tylko na
 * usunieciu drugiego miejsca: oba dzialy pytaja teraz TEN SAM predykat.
 * Dwa warunki, ktore musza byc komplementarne, nie moga byc pisane osobno —
 * to wlasnie ta klasa bledu.
 */
$GLOBALS['mp_ag']['lines'][] = '';
$GLOBALS['mp_ag']['lines'][] = '=== A3. status "shipping" nie wpada w szczeline ===';

ag_ok(
	method_exists( 'MP_OB_Products', 'zwolniona_z_vat' ),
	'istnieje jeden wspolny predykat zwolnienia'
);

if ( method_exists( 'MP_OB_Products', 'zwolniona_z_vat' ) ) {
	ag_ok( true === MP_OB_Products::zwolniona_z_vat( array( 'tax_status' => 'none' ) ), "'none' jest zwolniony" );
	ag_ok( true === MP_OB_Products::zwolniona_z_vat( array( 'tax_status' => 'shipping' ) ), "'shipping' tez jest zwolniony" );

	// Kontr-asercja: predykat nie moze zwalniac wszystkiego.
	ag_ok( false === MP_OB_Products::zwolniona_z_vat( array( 'tax_status' => 'taxable' ) ), "'taxable' NIE jest zwolniony" );
	ag_ok( false === MP_OB_Products::zwolniona_z_vat( array() ), 'brak statusu = opodatkowany (bezpieczniejsza strona)' );
}

// Oba dzialy musza pytac ten sam predykat — inaczej znowu sie rozjada.
$d2 = file_get_contents( dirname( dirname( __DIR__ ) ) . '/includes/pipeline/departments/class-mp-ob-department-02.php' );
$d6 = file_get_contents( dirname( dirname( __DIR__ ) ) . '/includes/pipeline/departments/class-mp-ob-department-06.php' );

ag_ok(
	is_string( $d2 ) && false !== strpos( $d2, 'MP_OB_Products::zwolniona_z_vat' ),
	'Dzial 2 pyta wspolny predykat'
);
ag_ok(
	is_string( $d6 ) && false !== strpos( $d6, 'MP_OB_Products::zwolniona_z_vat' ),
	'Dzial 6 pyta wspolny predykat'
);
ag_ok(
	is_string( $d6 ) && false === strpos( $d6, "'none' === $products" ),
	'Dzial 6 nie ma juz wlasnego porownania ze statusem'
);

/*
 * A4 (P2-G7). Bramka `missing_tax_rate` przepuszczala PUSTA stawke.
 *
 * Warunek sprawdzal tylko brak klucza i `null`. Wpis `array( 'rate' => '' )`
 * — albo `false`, albo `'abc'` — przechodzil: `isset()` prawda, wartosc nie
 * jest `null`. Nizej `(float) ''` daje 0.0, a warunek `0.0 === $rate` zerowal
 * VAT dla calej klasy. Efekt: dokument handlowy z cichym 0% VAT w mechanizmie
 * krajowym, bez zadnego bledu i bez sladu w dzienniku.
 *
 * „Brak stawki" i „stawka rowna zero" to dwie rozne rzeczy. Zero jest legalna
 * stawka i musi byc podane WPROST; pusty string znaczy, ze stawki nie ma.
 */
$GLOBALS['mp_ag']['lines'][] = '';
$GLOBALS['mp_ag']['lines'][] = '=== A4. pusta stawka nie moze dac cichego 0% VAT ===';

$zle = array( '', false, 'abc', array() );
foreach ( $zle as $i => $wartosc ) {
	$k = new MP_OB_Context(
		array(
			'subtotal_grosze' => 10000,
			'discount_total'  => 0,
			'tax_mechanism'   => 'domestic',
			'lines'           => array( array( 'line_grosze' => 10000, 'tax_class' => '' ) ),
			'products'        => array( array( 'tax_status' => 'taxable', 'tax_class' => '' ) ),
			'tax_rates'       => array( '' => array( 'rate' => $wartosc ) ),
		)
	);
	$w = ( new MP_OB_D6_Agent_Rounding() )->run( $k );
	ag_ok(
		! $w->is_ok() && 'missing_tax_rate' === $w->get_code(),
		'stawka ' . var_export( $wartosc, true ) . ' jest odrzucana',
		'ok=' . ( $w->is_ok() ? 'tak(BLAD)' : 'nie' ) . ' kod=' . $w->get_code()
	);
}

/*
 * KONTR-ASERCJE. Bez nich „naprawa" mogla by polegac na odrzucaniu wszystkiego,
 * co nie jest dodatnia liczba — a wtedy legalna stawka 0% (np. eksport, klasa
 * „Zero rate") przestalaby dzialac.
 */
foreach ( array( 0, 0.0, '0', '0.00', 23, '23.0000' ) as $wartosc ) {
	$k = new MP_OB_Context(
		array(
			'subtotal_grosze' => 10000,
			'discount_total'  => 0,
			'tax_mechanism'   => 'domestic',
			'lines'           => array( array( 'line_grosze' => 10000, 'tax_class' => '' ) ),
			'products'        => array( array( 'tax_status' => 'taxable', 'tax_class' => '' ) ),
			'tax_rates'       => array( '' => array( 'rate' => $wartosc ) ),
		)
	);
	$w = ( new MP_OB_D6_Agent_Rounding() )->run( $k );
	ag_ok(
		'missing_tax_rate' !== $w->get_code(),
		'liczbowa stawka ' . var_export( $wartosc, true ) . ' nadal przechodzi',
		'kod=' . $w->get_code()
	);
}

/*
 * Kontrola przeciwna, wspolna dla A i A2: gdy w tej samej klasie bez stawki
 * siedzi pozycja OPODATKOWANA, Dzial 2 ma nadal odmowic. Bez tej asercji obie
 * naprawy mogly by polegac na wylaczeniu kontroli — a wtedy oferta liczylaby
 * 0% VAT tam, gdzie stawka istnieje, tylko nie zostala skonfigurowana.
 */
$zwykly_bez_stawki = new WC_Product_Simple();
$zwykly_bez_stawki->set_name( 'MP test 1.25 — towar opodatkowany bez stawki' );
$zwykly_bez_stawki->set_regular_price( '100' );
$zwykly_bez_stawki->set_status( 'publish' );
$zwykly_bez_stawki->set_tax_status( 'taxable' );
$zwykly_bez_stawki->set_tax_class( $klasa_pusta );
$id_bez_stawki = (int) $zwykly_bez_stawki->save();

$kontekst2 = new MP_OB_Context(
	array(
		'items' => array(
			array(
				'product_id'   => $id_bez_stawki,
				'variation_id' => null,
				'qty'          => 1,
			),
		),
	)
);

$w21b = ( new MP_OB_D2_Agent_Products() )->run( $kontekst2 );

if ( $w21b->is_ok() ) {
	$kontekst2->set( 'products', (array) $w21b->get_data()['products'] );
	$w23b = ( new MP_OB_D2_Agent_Tax() )->run( $kontekst2 );

	ag_ok(
		! $w23b->is_ok() && 'missing_tax_rate' === $w23b->get_code(),
		'pozycja OPODATKOWANA bez stawki nadal konczy sie odmowa',
		'ok=' . ( $w23b->is_ok() ? 'tak(BLAD)' : 'nie' ) . ' kod=' . $w23b->get_code()
	);
}

/* ==================================================================== B */

$GLOBALS['mp_ag']['lines'][] = '';
$GLOBALS['mp_ag']['lines'][] = '=== B. limit pola liczony w znakach, nie w bajtach ===';

/*
 * 120 znakow, z czego 80 z ogonkami: 120 znakow = 200 bajtow.
 * varchar(191) w utf8mb4 przyjmie te nazwe bez obciecia.
 */
$nazwa = str_repeat( 'ą', 80 ) . str_repeat( 'a', 40 );

ag_ok( 120 === mb_strlen( $nazwa, 'UTF-8' ), 'nazwa ma 120 znakow', 'znakow=' . mb_strlen( $nazwa, 'UTF-8' ) );
ag_ok( strlen( $nazwa ) > 191, 'ta sama nazwa ma ponad 191 bajtow', 'bajtow=' . strlen( $nazwa ) );

$limit = MP_OB_D10_Agent_Plan::FIELD_LIMITS['client_name'];

ag_ok( 191 === $limit, 'limit client_name to 191 (lustro varchar(191))', 'limit=' . $limit );

/*
 * Sprawdzamy sama regule pomiaru w kodzie zrodlowym: liczenie ma isc przez
 * mb_strlen. Uruchomienie calego Dzialu 10 wymagaloby kompletu kontekstu
 * z osmiu poprzednich dzialow, a pytanie dotyczy jednej linii walidacji.
 */
$zrodlo_d10 = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/departments/class-mp-ob-department-10.php' );

ag_ok(
	method_exists( 'MP_OB_D10_Agent_Plan', 'dlugosc_znakow' ),
	'Dzial 10 ma wlasny pomiar dlugosci pola'
);
ag_ok(
	! preg_match( '/strlen\(\s*\(string\)\s*\$header\[/', $zrodlo_d10 ),
	'walidacja limitow nie liczy juz bajtow wprost'
);

if ( method_exists( 'MP_OB_D10_Agent_Plan', 'dlugosc_znakow' ) ) {
	$zmierzone = MP_OB_D10_Agent_Plan::dlugosc_znakow( $nazwa );

	ag_ok( 120 === $zmierzone, 'pomiar zwraca 120 dla nazwy z ogonkami', 'zwrocil=' . $zmierzone );
	ag_ok( 5 === MP_OB_D10_Agent_Plan::dlugosc_znakow( 'abcde' ), 'dla ASCII wynik sie nie zmienia' );
	ag_ok( $zmierzone <= $limit, 'nazwa mieszczaca sie w kolumnie NIE przekracza limitu' );
}

/* ==================================================================== C */

$GLOBALS['mp_ag']['lines'][] = '';
$GLOBALS['mp_ag']['lines'][] = '=== C. blad bazy to nie „juz zatwierdzona" ===';

$zrodlo_zatw = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-mp-offer-builder-approval.php' );

ag_ok(
	false !== strpos( $zrodlo_zatw, "false === \$changed" ),
	'kod rozroznia false (blad zapytania) od 0 (zaden wiersz nie pasowal)'
);
ag_ok(
	false !== strpos( $zrodlo_zatw, "'db_error'" ),
	'nieudany zapis ma wlasny kod bledu'
);

/*
 * Poziom komunikatu jest tu istota sprawy. 'already_approved' zostaje
 * informacja („nic sie nie zmienilo") — bo to prawda. Awaria zapisu musi byc
 * bledem, inaczej pracownik uzna sprawe za zalatwiona i nie ponowi akcji.
 */
$mapa = array();

if ( preg_match_all( "/'([a-z_]+)'\s*=>\s*array\(\s*'(success|info|error)'/", $zrodlo_zatw, $t, PREG_SET_ORDER ) ) {
	foreach ( $t as $wiersz ) {
		$mapa[ $wiersz[1] ] = $wiersz[2];
	}
}

ag_ok( isset( $mapa['db_error'] ), 'db_error jest w mapie komunikatow' );
ag_ok( isset( $mapa['db_error'] ) && 'error' === $mapa['db_error'], 'db_error pokazuje sie jako BLAD, nie informacja', 'poziom=' . ( isset( $mapa['db_error'] ) ? $mapa['db_error'] : '-' ) );
ag_ok( isset( $mapa['already_approved'] ) && 'info' === $mapa['already_approved'], 'already_approved zostaje informacja — bo wtedy naprawde nic sie nie zmienilo' );

// Sprzatanie: produkty testowe i klasa podatkowa.
foreach ( array( $id_zwolniony, $id_zwykly, $id_bez_stawki ) as $id_testowy ) {
	if ( $id_testowy > 0 ) {
		wp_delete_post( $id_testowy, true );
	}
}

if ( in_array( $klasa_pusta, WC_Tax::get_tax_class_slugs(), true ) ) {
	WC_Tax::delete_tax_class_by( 'slug', $klasa_pusta );
}
