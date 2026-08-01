<?php
/**
 * P1-G6 — jeden komunikat o NIP-ie na cztery rozne powody odrzucenia.
 *
 * Uruchamianie: wp eval-file tests/naprawy/komunikat-nip.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-G6  Agent 3.1 twierdzil „niepoprawna suma kontrolna" takze wtedy,
 *            gdy sumy w ogole nie liczyl
 *
 * `MP_D3_Agent_Nip::run()` zwracal jeden napis dla KAZDEGO powodu odrzucenia:
 * pustego pola, zlej dlugosci, wartosci zastepczej z powtorzonych cyfr i
 * naprawde blednej cyfry kontrolnej. W trzech z tych czterech przypadkow
 * `checksum_valid()` odrzuca wejscie ZANIM policzy jakakolwiek sume — komunikat
 * twierdzil wiec cos, czego kod nie ustalil.
 *
 * Dla czlowieka to nie jest niuans. „Niepoprawna suma kontrolna NIP" przy pustym
 * polu kaze mu poprawiac cyfre w numerze, ktorego nie podal. Ten sam komunikat
 * przy 1111111111 kaze szukac bledu rachunkowego tam, gdzie liczba jest
 * arytmetycznie poprawna, a odrzucona jako wartosc zastepcza.
 *
 * ZASIEG, uczciwie: w pelnym przebiegu Dzial 2 odrzuca puste pole i zla dlugosc
 * WCZESNIEJ, z wlasnymi, poprawnymi komunikatami. Do Agenta 3.1 dociera wiec
 * dziesiec cyfr i realnie mylil sie on w jednym przypadku — wartosci zastepczej.
 * Kontrakt agenta i tak musi byc prawdziwy: jest publiczna metoda statyczna,
 * wolana takze poza pipeline'em (harness procesu), a komunikat, ktory klamie
 * w trzech na cztery galezie, jest bledem niezaleznie od tego, kto go dzis widzi.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_kn'] = array(
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
function kn_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_kn']['pass'];
		$GLOBALS['mp_kn']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_kn']['fail'];
	$GLOBALS['mp_kn']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Komunikat, ktory agent 3.1 zwraca dla podanego wejscia.
 *
 * @param string $nip Wejscie.
 * @return string
 */
function kn_komunikat( $nip ) {
	$wynik = ( new MP_D3_Agent_Nip() )->run( new MP_Context( array( 'nip' => $nip ) ) );
	$dane  = $wynik->get_data();

	return isset( $dane['errors']['nip'] ) ? (string) $dane['errors']['nip'] : '';
}

/**
 * Czy agent uznal NIP za poprawny.
 *
 * @param string $nip Wejscie.
 * @return bool
 */
function kn_poprawny( $nip ) {
	$wynik = ( new MP_D3_Agent_Nip() )->run( new MP_Context( array( 'nip' => $nip ) ) );
	$dane  = $wynik->get_data();

	return ! empty( $dane['nip_valid'] );
}

$suma_kontrolna = 'Niepoprawna suma kontrolna NIP';

$GLOBALS['mp_kn']['lines'][] = '=== A. kazdy powod odrzucenia mowi o sobie ===';

kn_ok(
	$suma_kontrolna !== kn_komunikat( '' ),
	'puste pole NIE jest opisane jako blad sumy kontrolnej',
	'komunikat=' . kn_komunikat( '' )
);
kn_ok(
	false !== stripos( kn_komunikat( '' ), 'wymagany' ),
	'puste pole prosi o uzupelnienie',
	'komunikat=' . kn_komunikat( '' )
);
kn_ok(
	$suma_kontrolna !== kn_komunikat( '123456' ),
	'za krotki numer NIE jest opisany jako blad sumy kontrolnej',
	'komunikat=' . kn_komunikat( '123456' )
);
kn_ok(
	false !== stripos( kn_komunikat( '123456' ), '10 cyfr' ),
	'za krotki numer mowi o wymaganej dlugosci',
	'komunikat=' . kn_komunikat( '123456' )
);
kn_ok(
	$suma_kontrolna !== kn_komunikat( '1111111111' ),
	'wartosc zastepcza NIE jest opisana jako blad sumy kontrolnej',
	'komunikat=' . kn_komunikat( '1111111111' )
);
kn_ok(
	false !== stripos( kn_komunikat( '1111111111' ), 'powtorzon' ) || false !== stripos( kn_komunikat( '1111111111' ), 'powtórzon' ),
	'wartosc zastepcza mowi, na czym polega problem',
	'komunikat=' . kn_komunikat( '1111111111' )
);

$GLOBALS['mp_kn']['lines'][] = '';
$GLOBALS['mp_kn']['lines'][] = '=== B. KONTR-ASERCJE: prawdziwy blad cyfry kontrolnej i numer poprawny ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na usunieciu komunikatu o sumie
 * kontrolnej albo na przepuszczaniu wszystkiego. Numer 1234563219 rozni sie od
 * poprawnego 1234563218 wylacznie ostatnia cyfra — to JEST blad sumy kontrolnej
 * i tak ma byc nazwany.
 */
kn_ok(
	$suma_kontrolna === kn_komunikat( '1234563219' ),
	'bledna cyfra kontrolna nadal jest nazwana bledem sumy kontrolnej',
	'komunikat=' . kn_komunikat( '1234563219' )
);
kn_ok(
	! kn_poprawny( '1234563219' ),
	'bledny numer nadal jest odrzucany'
);
kn_ok(
	kn_poprawny( '1234563218' ),
	'poprawny numer nadal przechodzi'
);
kn_ok(
	'' === kn_komunikat( '1234563218' ),
	'poprawny numer nie dostaje zadnego komunikatu',
	'komunikat=' . kn_komunikat( '1234563218' )
);
kn_ok(
	! kn_poprawny( '1111111111' ),
	'wartosc zastepcza nadal jest ODRZUCANA, a nie tylko inaczej opisana'
);

$GLOBALS['mp_kn']['lines'][] = '';
$GLOBALS['mp_kn']['lines'][] = '=== C. ustalenie [07] audytu: NIP z separatorami — SPRAWDZONE, nie jest defektem ===';

/*
 * Zgloszenie [07] twierdzilo, ze poprawny NIP wpisany z myslnikami dostaje
 * komunikat o blednej sumie kontrolnej, bo Agent 3.1 nie normalizuje wartosci.
 * Uruchomienie calego pipeline'u pokazuje co innego: Dzial 2 (Agent 2.2)
 * normalizuje `nip` do samych cyfr i WPISUJE go z powrotem do kontekstu, a
 * Dzial 3 chodzi po Dziale 2. Asercje ponizej utrwalaja te kolejnosc — gdyby
 * ktos przestawil dzialy, zgloszenie [07] staloby sie prawdziwe i test to zlapie.
 */
$kontekst = new MP_Context( array( 'nip' => '123-456-32-18' ) );
$po_dwojce = ( new MP_D2_Agent_Normalize() )->run( $kontekst );
$kontekst->merge( $po_dwojce->get_data() );

kn_ok(
	'1234563218' === (string) $kontekst->get( 'nip', '' ),
	'Dzial 2 normalizuje NIP do samych cyfr i oddaje go do kontekstu',
	'nip=' . (string) $kontekst->get( 'nip', '' )
);

$wynik_po_dwojce = ( new MP_D3_Agent_Nip() )->run( $kontekst );
$dane_po_dwojce  = $wynik_po_dwojce->get_data();

kn_ok(
	! empty( $dane_po_dwojce['nip_valid'] ),
	'NIP wpisany z myslnikami przechodzi Dzial 3 po normalizacji Dzialu 2',
	'nip_valid=' . var_export( isset( $dane_po_dwojce['nip_valid'] ) ? $dane_po_dwojce['nip_valid'] : null, true )
);

$kolejnosc = array();

foreach ( MP_Pipeline_Factory::make()->get_departments() as $dzial ) {
	$kolejnosc[] = (int) $dzial->get_number();
}

$pozycja_2 = array_search( 2, $kolejnosc, true );
$pozycja_3 = array_search( 3, $kolejnosc, true );

kn_ok(
	false !== $pozycja_2 && false !== $pozycja_3 && $pozycja_2 < $pozycja_3,
	'Dzial 2 (normalizacja) idzie PRZED Dzialem 3 (weryfikacja NIP)',
	'kolejnosc=' . implode( ',', $kolejnosc )
);

$GLOBALS['mp_kn']['lines'][] = '';
$GLOBALS['mp_kn']['lines'][] = '=== D. kraj inny niz PL: polska regula w ogole sie nie stosuje ===';

/*
 * HISTORIA TEJ SEKCJI — bo jej tresc raz sie juz zmienila i warto wiedziec czemu.
 *
 * Najpierw (P1-G16) kontrola byla polska, a poprawiony zostal sam komunikat:
 * niemiecka firma zamiast „NIP powinien miec 10 cyfr" — czyli zamiast zarzutu
 * literowki w numerze w pelni poprawnym — czytala, ze sprawdzamy wylacznie polski
 * NIP. Sekcja pilnowala wtedy DOBORU SLOW.
 *
 * Potem (P1-Z1) zapadla decyzja o szerszym zakresie: numery z innych krajow UE
 * maja byc przyjmowane, bo cala reszta systemu byla na nie gotowa — VIES pytany
 * per kraj, UNIQUE (country, nip) w BD-3, przydzial handlowca po kraju w P3.
 * Tamten komunikat stal sie wiec nieprawdziwy i zniknal razem z regula, ktora
 * opisywal. Nie „poprawiamy poprawki" — zmienil sie zakres produktu.
 *
 * Dzis sekcja pilnuje czegos mocniejszego niz slowa: ze do cudzego numeru NIE
 * przykladamy polskiej sumy kontrolnej — ani jej werdyktu, ani jej slownictwa.
 * Asercje P1-G6 (jeden komunikat na cztery rozne powody) zyja dalej, nietkniete,
 * w sekcjach A–C.
 */
$kn_dzial2 = static function ( $nip, $country ) {
	$wynik = ( new MP_D2_Agent_Validate_Formats() )->run(
		new MP_Context(
			array(
				'nip'     => $nip,
				'email'   => 'kontakt@example.test',
				'country' => $country,
			)
		)
	);
	$dane = $wynik->get_data();

	return isset( $dane['errors']['nip'] ) ? (string) $dane['errors']['nip'] : '';
};

$kn_dzial3 = static function ( $nip, $country ) {
	$wynik = ( new MP_D3_Agent_Nip() )->run(
		new MP_Context(
			array(
				'nip'     => $nip,
				'country' => $country,
			)
		)
	);
	$dane = $wynik->get_data();

	return isset( $dane['errors']['nip'] ) ? (string) $dane['errors']['nip'] : '';
};

// Niemiecki USt-IdNr. ma dziewiec cyfr — polskiej dlugosci nie spelnia i nie ma
// spelniac. Po P1-Z1 nie jest to juz powod do odrzucenia.
$kn_de = $kn_dzial2( '123456789', 'DE' );

kn_ok(
	'' === $kn_de,
	'D1: numer z kraju DE nie jest odrzucany za to, ze nie ma dziesieciu cyfr',
	'komunikat=' . $kn_de
);
kn_ok(
	'' === $kn_dzial3( '123456789', 'DE' ),
	'D2: i nie dostaje w Dziale 3 zadnego komunikatu o polskiej sumie kontrolnej',
	'komunikat=' . $kn_dzial3( '123456789', 'DE' )
);

/*
 * Slowacki numer VAT ma DOKLADNIE dziesiec cyfr, wiec przechodzil kontrole
 * dlugosci w Dziale 2 i docieral do polskiej sumy kontrolnej w Dziale 3 — to
 * najlatwiejszy do przeoczenia przypadek, bo z zewnatrz wyglada jak polski NIP.
 * Tam dostawal „Niepoprawna suma kontrolna NIP": zarzut bledu rachunkowego
 * w numerze, ktory zaden polski rachunek nie opisuje.
 */
$kn_sk = $kn_dzial3( '2020123456', 'SK' );

kn_ok(
	'' === $kn_sk,
	'D3: dziesieciocyfrowy numer z kraju SK nie jest juz mierzony polska suma kontrolna',
	'komunikat=' . $kn_sk
);

/*
 * Odrzucenie za granica nadal istnieje — tyle ze na podstawie formatu, nie sumy.
 * Komunikat ma o tym mowic uczciwie: ma nazwac kraj i NIE ma wspominac ani
 * o dziesieciu cyfrach, ani o sumie kontrolnej, bo zadnej z tych rzeczy tu nie
 * sprawdzano. To ta sama zasada, ktorej pilnuje caly ten plik od P1-G6:
 * komunikat nie twierdzi wiecej, niz kod naprawde ustalil.
 */
$kn_smiec = $kn_dzial2( 'AB', 'DE' );

kn_ok(
	'' !== $kn_smiec,
	'D4: numer bez sensu z kraju DE nadal jest odrzucany — luznosc nie znaczy brak kontroli',
	'komunikat=' . $kn_smiec
);
kn_ok(
	false !== strpos( $kn_smiec, 'DE' )
		&& false === stripos( $kn_smiec, 'suma kontrolna' )
		&& false === stripos( $kn_smiec, '10 cyfr' ),
	'D5: nazywa kraj i nie powoluje sie na polskie reguly, ktorych nie stosowano',
	'komunikat=' . $kn_smiec
);

$GLOBALS['mp_kn']['lines'][] = '';
$GLOBALS['mp_kn']['lines'][] = '=== E. KONTR-ASERCJE: polska sciezka nietknieta ===';

/*
 * Najwazniejsza czesc tej zmiany. Otwarcie na numery z innych krajow nie moze
 * przestawic ani jednej decyzji dla numeru polskiego — ani tego, co przechodzi,
 * ani tego, co czyta polski klient. Gdyby przy okazji poluzowala sie kontrola,
 * do BD-3 zaczelyby wchodzic numery, ktorych zaden dzial dalej nie rozumie —
 * a UNIQUE (country, nip) i weryfikator w tle licza na numer kanoniczny.
 */
kn_ok(
	'' === $kn_dzial2( '1234563218', 'DE' ),
	'E1: poprawny polski NIP przy kraju DE nadal PRZECHODZI Dzial 2',
	'komunikat=' . $kn_dzial2( '1234563218', 'DE' )
);
kn_ok(
	'' === $kn_dzial3( '1234563218', 'DE' ),
	'E2: i nadal przechodzi Dzial 3 — sama zmiana kraju niczego nie odrzuca',
	'komunikat=' . $kn_dzial3( '1234563218', 'DE' )
);
kn_ok(
	'' !== $kn_dzial2( '123456789', 'PL' ) && '' !== $kn_dzial3( '2020123456', 'PL' ),
	'E3: przy kraju PL zly numer nadal jest odrzucany',
	'dzial2=' . $kn_dzial2( '123456789', 'PL' ) . ' | dzial3=' . $kn_dzial3( '2020123456', 'PL' )
);
kn_ok(
	false !== stripos( $kn_dzial2( '123456789', 'PL' ), '10 cyfr' ),
	'E4: przy kraju PL komunikat zostaje DOKLADNIE taki, jaki byl',
	'komunikat=' . $kn_dzial2( '123456789', 'PL' )
);
kn_ok(
	false !== stripos( kn_komunikat( '123456' ), '10 cyfr' ),
	'E5: bez podanego kraju komunikat tez zostaje bez zmian',
	'komunikat=' . kn_komunikat( '123456' )
);

echo implode( "\n", $GLOBALS['mp_kn']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_kn']['pass'], $GLOBALS['mp_kn']['fail'] );
echo ( 0 === $GLOBALS['mp_kn']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
