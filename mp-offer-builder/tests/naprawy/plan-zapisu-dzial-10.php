<?php
/**
 * Dzial 10, Agent 10.1 — plan zapisu przepuszczal dane, ktorych baza nie obroni.
 *
 * Uruchamianie: wp eval-file tests/naprawy/plan-zapisu-dzial-10.php
 *
 * Siedem ustalen z audytu glebokiego (para 1.25), wszystkie w jednym agencie:
 *
 * 1. Walidacja „zgodnosci z DDL" sprawdzala WYLACZNIE gorne limity dlugosci.
 *    Pusty `offer_number` i `version = 0` przechodzily jako poprawne (0 <= 30),
 *    a to klucz biznesowy oferty i podstawa sciezki pliku PDF.
 *
 * 2. Brak odczytanego `lock_version` dla ISTNIEJACEJ oferty byl nieodrozninalny
 *    od nowej: token wracal do 1, mimo `offer_id > 0`. Blokada optymistyczna
 *    porownywala sie wtedy z wartoscia wzieta z powietrza.
 *
 * 3. Ponowne sprawdzenie wlasciciela (obrona w glab przeciw IDOR) bylo pomijane
 *    DOKLADNIE wtedy, gdy odczyt wlasciciela nic nie dal — a w tym samym
 *    przebiegu `created_by` bylo nadpisywane biezacym uzytkownikiem.
 *
 * 4. `created_by = 0` (zapis bez zalogowanego uzytkownika) czytalo sie pozniej
 *    nie jako „brak wlasciciela", tylko jako CUDZEGO wlasciciela — i kontrola
 *    IDOR odmawiala zapisu wszystkim poza administratorem.
 *
 * 5. Pozycje byly korelowane z wyliczeniami po indeksie tablicy, a brak
 *    odpowiednika dawal CICHE 0: wiersze pozycji z cena zero przy niezerowych
 *    sumach w nagłowku.
 *
 * 6. Wiersz pozycji mieszal jednostki: `price_base_grosze` dostawalo cene
 *    JEDNOSTKOWA, a `price_final_grosze` wartosc CALEJ LINII, przy
 *    `discount_grosze` zawsze 0. Trzy kolumny tego samego wiersza byly wzajemnie
 *    sprzeczne dla kazdej pozycji z qty > 1.
 *
 * 7. Sprawdzenie statusu wobec ALLOWED_STATUSES bylo tautologia — porownywalo
 *    stala z jednoelementowa lista zawierajaca te sama stala.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_pz'] = array(
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
function pz_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_pz']['pass'];
		$GLOBALS['mp_pz']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_pz']['fail'];
	$GLOBALS['mp_pz']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Kontekst poprawnego planu zapisu.
 *
 * Jedna pozycja, 3 sztuki po 100,00 zl. `line_grosze = unit_grosze * qty`
 * (Dzial 4, wprost).
 *
 * @param array $zmiany Nadpisania.
 * @return array
 */
function pz_kontekst( array $zmiany = array() ) {
	return array_merge(
		array(
			'offer_number'   => 'OF/2026/000123',
			'version'        => 1,
			'lang'           => 'pl',
			'client'         => array(
				'name'       => 'Firma Testowa',
				'email'      => 'test@example.test',
				'nip'        => '5252248481',
				'country'    => 'PL',
				'vat_status' => 'valid',
			),
			'items'          => array( array( 'product_id' => 7, 'qty' => 3 ) ),
			'lines'          => array( array( 'unit_grosze' => 10000, 'line_grosze' => 30000 ) ),
			'line_tax_rates' => array( 23.0 ),
			'net_grosze'     => 30000,
			'vat_grosze'     => 6900,
			'gross_grosze'   => 36900,
			'currency'       => 'PLN',
			'tax_mechanism'  => 'domestic',
			'tax_rate'       => 23.0,
			'pdf'            => array( 'sha256' => str_repeat( 'a', 64 ) ),
			'request_id'     => 'req-pz-1',
			'numbering'      => array(
				'existing_offer_number' => null,
				'existing_version'      => null,
				'existing_created_by'   => null,
				'existing_lock_version' => null,
			),
			'numbering_mode' => 'new_number',
		),
		$zmiany
	);
}

/**
 * Uruchamia Agenta 10.1.
 *
 * @param array $dane Kontekst.
 * @return MP_OB_Result
 */
function pz_plan( array $dane ) {
	$agent = new MP_OB_D10_Agent_Plan();

	return $agent->run( new MP_OB_Context( $dane ) );
}

/**
 * Kody bledow z odmowy.
 *
 * @param MP_OB_Result $wynik Wynik agenta.
 * @return string
 */
function pz_pola( MP_OB_Result $wynik ) {
	$dane  = (array) $wynik->get_data();
	$bledy = isset( $dane['errors'] ) ? (array) $dane['errors'] : array();
	$pola  = array();

	foreach ( $bledy as $blad ) {
		$pola[] = isset( $blad['field'] ) ? (string) $blad['field'] : '?';
	}

	return implode( ', ', $pola );
}

$GLOBALS['mp_pz']['lines'][] = '=== A. klucz biznesowy oferty musi istniec ===';

$pz_kontrola = pz_plan( pz_kontekst() );

pz_ok(
	$pz_kontrola->is_ok(),
	'A0: (zalozenie testu) poprawny plan przechodzi',
	'kod=' . $pz_kontrola->get_code() . ' pola=' . pz_pola( $pz_kontrola )
);

$pz_bez_numeru = pz_plan( pz_kontekst( array( 'offer_number' => '' ) ) );

pz_ok(
	! $pz_bez_numeru->is_ok(),
	'A1: pusty numer oferty nie przechodzi walidacji',
	'kod=' . $pz_bez_numeru->get_code()
);
pz_ok(
	false !== strpos( pz_pola( $pz_bez_numeru ), 'header.offer_number' ),
	'A2: odmowa wskazuje pole, ktorego brakuje',
	'pola=' . pz_pola( $pz_bez_numeru )
);

$pz_wersja_zero = pz_plan( pz_kontekst( array( 'version' => 0 ) ) );

pz_ok(
	! $pz_wersja_zero->is_ok() && false !== strpos( pz_pola( $pz_wersja_zero ), 'header.version' ),
	'A3: wersja 0 nie przechodzi — numeracja wersji zaczyna sie od 1',
	'kod=' . $pz_wersja_zero->get_code() . ' pola=' . pz_pola( $pz_wersja_zero )
);

// KONTR-ASERCJA: gorny limit dlugosci ma dzialac jak dotad.
$pz_za_dlugi = pz_plan( pz_kontekst( array( 'offer_number' => str_repeat( 'X', 40 ) ) ) );

pz_ok(
	! $pz_za_dlugi->is_ok() && false !== strpos( pz_pola( $pz_za_dlugi ), 'header.offer_number' ),
	'A4: KONTR-ASERCJA — za dlugi numer nadal odrzucany',
	'kod=' . $pz_za_dlugi->get_code()
);

$GLOBALS['mp_pz']['lines'][] = '';
$GLOBALS['mp_pz']['lines'][] = '=== B. istniejaca oferta bez odczytu to nie nowa oferta ===';

/*
 * `offer_id > 0` znaczy UPDATE. Gdy Dzial 2 nie odczytal wiersza (oferta
 * skasowana miedzy dzialami, podmieniony kontekst), `existing_lock_version`
 * zostaje pusty — a plan cofal wtedy token blokady do 1 i szedl dalej. WHERE
 * blokady optymistycznej porownywalby sie z liczba wzieta z powietrza, czyli
 * zabezpieczenie przed nadpisaniem cudzego zapisu przestawaloby dzialac
 * dokladnie w sytuacji, w ktorej cos juz poszlo nie tak.
 */
$pz_bez_odczytu = pz_plan(
	pz_kontekst(
		array(
			'offer_id'  => 4321,
			'numbering' => array(
				'existing_offer_number' => null,
				'existing_version'      => null,
				'existing_created_by'   => null,
				'existing_lock_version' => null,
			),
		)
	)
);

pz_ok(
	! $pz_bez_odczytu->is_ok(),
	'B1: UPDATE bez odczytanego wiersza konczy sie odmowa, a nie tokenem 1',
	'kod=' . $pz_bez_odczytu->get_code() . ' pola=' . pz_pola( $pz_bez_odczytu )
);

// KONTR-ASERCJA: prawidlowy UPDATE ma isc dalej i podbijac token.
$pz_update = pz_plan(
	pz_kontekst(
		array(
			'offer_id'  => 4321,
			'numbering' => array(
				'existing_offer_number' => 'OF/2026/000123',
				'existing_version'      => 1,
				'existing_created_by'   => null,
				'existing_lock_version' => 7,
			),
		)
	)
);

$pz_dane_update = (array) $pz_update->get_data();
$pz_naglowek    = isset( $pz_dane_update['write_plan']['header'] ) ? (array) $pz_dane_update['write_plan']['header'] : array();

pz_ok(
	$pz_update->is_ok() && 8 === (int) ( $pz_naglowek['lock_version'] ?? 0 ),
	'B2: KONTR-ASERCJA — odczytany token rosnie o jeden',
	'kod=' . $pz_update->get_code() . ' token=' . ( $pz_naglowek['lock_version'] ?? '(brak)' )
);
pz_ok(
	$pz_update->is_ok() && 4321 === (int) ( $pz_naglowek['id'] ?? 0 ),
	'B3: KONTR-ASERCJA — plan nadal celuje w istniejacy wiersz'
);

$GLOBALS['mp_pz']['lines'][] = '';
$GLOBALS['mp_pz']['lines'][] = '=== C. „brak wlasciciela" to null, nigdy zero ===';

/*
 * Zapis w kontekscie bez zalogowanego uzytkownika (cron, WP-CLI) dawal
 * `created_by = 0`. Przy nastepnym dokonczeniu draftu odczyt widzial 0, czyli
 * „wlasciciel o identyfikatorze zero" — a nie „brak wlasciciela". Kontrola
 * IDOR odmawiala wtedy zapisu kazdemu poza administratorem: draft stawal sie
 * nietykalny.
 */
$pz_stary_user = get_current_user_id();
wp_set_current_user( 0 );

$pz_bez_usera  = pz_plan( pz_kontekst() );
$pz_dane_bez_u = (array) $pz_bez_usera->get_data();
$pz_naglowek_0 = isset( $pz_dane_bez_u['write_plan']['header'] ) ? (array) $pz_dane_bez_u['write_plan']['header'] : array();

wp_set_current_user( (int) $pz_stary_user );

// `??` traktuje null jak brak klucza, wiec o wartosc NULL trzeba zapytac wprost.
pz_ok(
	$pz_bez_usera->is_ok()
		&& array_key_exists( 'created_by', $pz_naglowek_0 )
		&& null === $pz_naglowek_0['created_by'],
	'C1: bez zalogowanego uzytkownika wlascicielem jest NULL, nie zero',
	'created_by=' . var_export( array_key_exists( 'created_by', $pz_naglowek_0 ) ? $pz_naglowek_0['created_by'] : '(brak klucza)', true )
);

$GLOBALS['mp_pz']['lines'][] = '';
$GLOBALS['mp_pz']['lines'][] = '=== D. pozycja bez wyliczenia to blad, nie zero ===';

/*
 * Pozycje i ich ceny laczyly sie po tym samym kluczu tablicy. Brak odpowiednika
 * dawal ciche 0 — wiersze pozycji szly do bazy z cena zerowa, podczas gdy
 * naglowek niosl pelne kwoty. Dokument dla klienta i suma w bazie mowily wtedy
 * dwie rozne rzeczy.
 */
$pz_luka = pz_plan(
	pz_kontekst(
		array(
			'items' => array(
				array( 'product_id' => 7, 'qty' => 3 ),
				array( 'product_id' => 9, 'qty' => 1 ),
			),
			// Drugiej pozycji nikt nie policzyl.
			'lines' => array( array( 'unit_grosze' => 10000, 'line_grosze' => 30000 ) ),
		)
	)
);

pz_ok(
	! $pz_luka->is_ok(),
	'D1: pozycja bez odpowiadajacego wyliczenia zatrzymuje plan',
	'kod=' . $pz_luka->get_code() . ' pola=' . pz_pola( $pz_luka )
);
pz_ok(
	false !== strpos( pz_pola( $pz_luka ), 'items.1' ),
	'D2: odmowa wskazuje, ktora pozycja nie ma pokrycia',
	'pola=' . pz_pola( $pz_luka )
);

// KONTR-ASERCJA: brak MAPY stawek to nadal legalny fallback (reverse_charge).
$pz_bez_mapy = pz_plan( pz_kontekst( array( 'line_tax_rates' => array(), 'tax_rate' => 0.0 ) ) );

pz_ok(
	$pz_bez_mapy->is_ok(),
	'D3: KONTR-ASERCJA — brak calej mapy stawek to dokumentowany fallback, nie blad',
	'kod=' . $pz_bez_mapy->get_code() . ' pola=' . pz_pola( $pz_bez_mapy )
);

$GLOBALS['mp_pz']['lines'][] = '';
$GLOBALS['mp_pz']['lines'][] = '=== E. trzy kolumny wiersza w jednej jednostce ===';

$pz_dane_ok = (array) $pz_kontrola->get_data();
$pz_wiersze = isset( $pz_dane_ok['write_plan']['items'] ) ? (array) $pz_dane_ok['write_plan']['items'] : array();
$pz_wiersz  = isset( $pz_wiersze[0] ) ? (array) $pz_wiersze[0] : array();

pz_ok(
	! empty( $pz_wiersz ),
	'E0: (zalozenie testu) plan ma wiersz pozycji'
);
pz_ok(
	isset( $pz_wiersz['price_base_grosze'], $pz_wiersz['discount_grosze'], $pz_wiersz['price_final_grosze'] )
		&& (int) $pz_wiersz['price_base_grosze'] - (int) $pz_wiersz['discount_grosze'] === (int) $pz_wiersz['price_final_grosze'],
	'E1: base - rabat = final, czyli trzy kolumny opisuja to samo',
	'base=' . ( $pz_wiersz['price_base_grosze'] ?? '?' ) . ' rabat=' . ( $pz_wiersz['discount_grosze'] ?? '?' ) . ' final=' . ( $pz_wiersz['price_final_grosze'] ?? '?' )
);
pz_ok(
	30000 === (int) ( $pz_wiersz['price_final_grosze'] ?? 0 ),
	'E2: KONTR-ASERCJA — wartosc linii zostaje wartoscia linii (3 x 100,00 zl)',
	'final=' . ( $pz_wiersz['price_final_grosze'] ?? '?' )
);

$GLOBALS['mp_pz']['lines'][] = '';
$GLOBALS['mp_pz']['lines'][] = '=== F. sprawdzenie statusu ma moc cokolwiek wykryc ===';

/*
 * `ALLOWED_STATUSES` to jednoelementowa lista z ta sama stala, ktora dwadziescia
 * linii wyzej jest przypisywana do `header['status']` literalem. Warunek nie mial
 * wejscia, dla ktorego moglby sie nie powiesc — czyli nie sprawdzal niczego.
 * Prawdziwa bramka jest w Dziale 1 i to ona ma tu byc udokumentowana testem.
 */
$pz_zrodlo = (string) file_get_contents( dirname( __DIR__ ) . '/../includes/pipeline/departments/class-mp-ob-department-10.php' );

pz_ok(
	'' !== $pz_zrodlo,
	'F0: (zalozenie testu) zrodlo Dzialu 10 czytelne'
);
pz_ok(
	false === strpos( $pz_zrodlo, 'in_array( $header[\'status\'], self::ALLOWED_STATUSES, true )' ),
	'F1: martwego sprawdzenia statusu juz nie ma',
	'zrodlo zawiera tautologiczny warunek'
);
pz_ok(
	MP_Offer_Builder_DB::STATUS_DRAFT === (string) ( $pz_naglowek['status'] ?? '' ),
	'F2: KONTR-ASERCJA — plan nadal zapisuje szkic (Dzial 1 wpuszcza tu wylacznie szkice)',
	'status=' . ( $pz_naglowek['status'] ?? '(brak)' )
);

echo implode( "\n", $GLOBALS['mp_pz']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_pz']['pass'], $GLOBALS['mp_pz']['fail'] );
echo ( 0 === $GLOBALS['mp_pz']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
