<?php
/**
 * Trzy ustalenia audytu z wtyczki 1 — wszystkie o tym samym: dane przechodzą
 * przez kod, który je po cichu zmienia albo w ogóle ich nie dotyka.
 *
 * Uruchamianie: wp eval-file tests/naprawy/dane-klienta-nie-sa-podmieniane.php
 *
 * A. ADRES PODMIENIONY BEZ SŁOWA. Agent 2.2 normalizował e-mail przez
 *    `sanitize_email()`, a agent 2.3 sprawdzał `is_email()` na wartości JUŻ
 *    przepisanej — nie na tej, którą wpisał klient. `sanitize_email()` usuwa
 *    znaki spoza swojego węższego zbioru i oddaje adres, który przechodzi
 *    walidację. Zmierzone wprost:
 *
 *      zażółć@firma.pl  ->  za@firma.pl        (is_email: tak)
 *      Jan Kowalski@x.pl -> JanKowalski@x.pl   (is_email: tak)
 *
 *    Cały proces kończył się sukcesem, a w BD-3 lądował INNY adres. Oferta
 *    z wtyczki 2 szła na skrzynkę, której klient nigdy nie podał, i nikt tego
 *    nie widział — ani klient, ani handlowiec, ani dziennik.
 *
 *    Apostrof jest przy tym legalny (`o'brien@firma.pl` przechodzi bez zmian) —
 *    ta część zgłoszenia była nieprawdziwa i test to utrwala.
 *
 * B. POLA OPISOWE BEZ NORMALIZACJI. Agent 2.2 sanityzuje `company_name`,
 *    `email`, `nip` i `phone`. `segment` i `est_volume` mają tylko limit
 *    długości — do BD-3 szła wartość surowa, z tagami i złamaniami linii,
 *    mimo że opis działu deklaruje „normalizację danych oficjalnymi funkcjami
 *    WordPressa". Ta sama wartość idzie potem do oferty i do PDF-a we wtyczce 2.
 *
 * C. PUSTY ŚLAD ZNACZY „STRONA JEST". Ślad po nieudanym utworzeniu strony
 *    zapisywał `$page_id->get_error_message()` bez sprawdzenia, czy komunikat
 *    nie jest pusty — a pusta wartość `OPTION_PAGE_ERROR` znaczy w tej klasie
 *    dokładnie „strona istnieje" (tak mówi docblock stałej). WP_Error z samym
 *    kodem, bez treści, dawał więc ślad nieodróżnialny od powodzenia.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_dk'] = array(
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
function dk_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_dk']['pass'];
		$GLOBALS['mp_dk']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_dk']['fail'];
	$GLOBALS['mp_dk']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function dk_koniec() {
	if ( empty( $GLOBALS['mp_dk']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_dk'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_dk']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'dk_koniec' );

/**
 * Uruchamia agenta 2.3 na kopercie po normalizacji agenta 2.2 — dokładnie tak,
 * jak robi to pipeline.
 *
 * @param array $pola Pola zgłoszenia.
 * @return array Błędy zgłoszone przez 2.3.
 */
function dk_walidacja( array $pola ) {
	$kontekst = new MP_Context(
		array_merge(
			array(
				'company_name' => 'Firma Testowa',
				'email'        => 'kontakt@firma.pl',
				'nip'          => '5260001246',
				'country'      => 'PL',
				'phone'        => '123456789',
			),
			$pola
		)
	);

	$norm = ( new MP_D2_Agent_Normalize() )->run( $kontekst );
	foreach ( (array) $norm->get_data() as $klucz => $wartosc ) {
		$kontekst->set( $klucz, $wartosc );
	}

	$wynik = ( new MP_D2_Agent_Validate_Formats() )->run( $kontekst );
	$dane  = (array) $wynik->get_data();

	return array(
		'errors'  => (array) ( $dane['errors'] ?? array() ),
		'email'   => (string) $kontekst->get( 'email', '' ),
		'segment' => (string) $kontekst->get( 'segment', '' ),
		'wolumen' => (string) $kontekst->get( 'est_volume', '' ),
	);
}

/* ==================================================================== A */

$GLOBALS['mp_dk']['lines'][] = '=== A. adres e-mail nie jest po cichu podmieniany ===';

$dk_podmieniane = array(
	'zażółć@firma.pl'      => 'polskie znaki w czesci lokalnej',
	'Jan Kowalski@firma.pl' => 'spacja w czesci lokalnej',
	'użytkownik@firma.pl'  => 'jeden znak diakrytyczny',
);

foreach ( $dk_podmieniane as $dk_adres => $dk_opis ) {
	$dk_po = sanitize_email( $dk_adres );
	$dk_w  = dk_walidacja( array( 'email' => $dk_adres ) );

	dk_ok(
		$dk_adres !== $dk_po && is_email( $dk_po ),
		'A-pomiar (' . $dk_opis . '): sanitize_email ZMIENIA adres na taki, ktory przechodzi is_email',
		$dk_adres . ' -> ' . $dk_po
	);
	dk_ok(
		isset( $dk_w['errors']['email'] ),
		'A-odmowa (' . $dk_opis . '): zgloszenie jest ODRZUCANE, a nie ciche',
		'bledy=' . wp_json_encode( $dk_w['errors'] )
	);
}

$dk_komunikat = (string) ( dk_walidacja( array( 'email' => 'zażółć@firma.pl' ) )['errors']['email'] ?? '' );

dk_ok(
	false !== mb_stripos( $dk_komunikat, 'adres' ),
	'A: komunikat mowi o adresie, wiec klient wie, co poprawic',
	'komunikat=' . $dk_komunikat
);
dk_ok(
	false === mb_strpos( $dk_komunikat, 'za@firma.pl' ),
	'A: i NIE pokazuje klientowi wersji przepisanej jako jego adresu'
);

$dk_poprawne = array(
	"o'brien@firma.pl"          => 'apostrof jest legalny — ta czesc zgloszenia byla nieprawdziwa',
	'jan.kowalski+tag@firma.pl' => 'plus i kropka',
	'kontakt@firma.pl'          => 'zwykly adres',
	'  kontakt@firma.pl  '      => 'adres z bialymi znakami dokola',
);

foreach ( $dk_poprawne as $dk_adres => $dk_opis ) {
	dk_ok(
		! isset( dk_walidacja( array( 'email' => $dk_adres ) )['errors']['email'] ),
		'A-kontr-asercja (' . $dk_opis . '): przechodzi jak dotad',
		'bledy=' . wp_json_encode( dk_walidacja( array( 'email' => $dk_adres ) )['errors'] )
	);
}

/* ==================================================================== B */

$GLOBALS['mp_dk']['lines'][] = '';
$GLOBALS['mp_dk']['lines'][] = '=== B. pola opisowe przechodza normalizacje ===';

$dk_b = dk_walidacja(
	array(
		'segment'    => "Handel\n<b>hurt</b>\t",
		'est_volume' => '  <script>alert(1)</script> 500 ton  ',
	)
);

dk_ok(
	false === mb_strpos( $dk_b['segment'], '<b>' ) && false === mb_strpos( $dk_b['segment'], "\n" ),
	'B1: segment bez znacznikow HTML i bez zlamania linii',
	'segment=' . wp_json_encode( $dk_b['segment'] )
);
dk_ok(
	false === mb_strpos( $dk_b['wolumen'], '<script' ),
	'B2: wolumen bez znacznikow',
	'wolumen=' . wp_json_encode( $dk_b['wolumen'] )
);
dk_ok(
	false !== mb_strpos( $dk_b['segment'], 'Handel' ) && false !== mb_strpos( $dk_b['segment'], 'hurt' ),
	'B3: tresc podana przez klienta zostaje — normalizacja, nie kasowanie',
	'segment=' . wp_json_encode( $dk_b['segment'] )
);
dk_ok(
	'500 ton' === trim( $dk_b['wolumen'] ) || false !== mb_strpos( $dk_b['wolumen'], '500 ton' ),
	'B4: wolumen zachowuje liczbe i jednostke',
	'wolumen=' . wp_json_encode( $dk_b['wolumen'] )
);

$dk_b2 = dk_walidacja( array( 'segment' => 'Przetwórstwo spożywcze', 'est_volume' => '1 200 t/rok' ) );

dk_ok(
	'Przetwórstwo spożywcze' === $dk_b2['segment'] && '1 200 t/rok' === $dk_b2['wolumen'],
	'B5: KONTR-ASERCJA — zwykla tresc z polskimi znakami przechodzi bez zmian',
	'segment=' . $dk_b2['segment'] . ' wolumen=' . $dk_b2['wolumen']
);
dk_ok(
	empty( $dk_b2['errors'] ),
	'B6: KONTR-ASERCJA — i nie powstaje z tego blad'
);

/* ==================================================================== C */

$GLOBALS['mp_dk']['lines'][] = '';
$GLOBALS['mp_dk']['lines'][] = '=== C. slad po awarii nigdy nie jest pusty ===';

/*
 * `OPTION_PAGE_ERROR` z pusta wartoscia znaczy w tej klasie „strona jest" —
 * tak mowi docblock stalej. WP_Error z samym kodem, bez tresci, dawal wiec slad
 * nieodroznialny od powodzenia: administrator nie widzial ani strony, ani
 * powodu jej braku.
 */
$dk_metoda = new ReflectionMethod( 'MP_Lead_Intake_Page', 'powod_awarii' );
$dk_metoda->setAccessible( true );

$dk_przypadki = array(
	'WP_Error z trescia'     => new WP_Error( 'zablokowane', 'Wtyczka bezpieczenstwa zablokowala zapis' ),
	'WP_Error bez tresci'    => new WP_Error( 'zablokowane', '' ),
	'WP_Error bez wiadomosci' => new WP_Error(),
	'wartosc nie-bledowa'    => 0,
);

foreach ( $dk_przypadki as $dk_opis => $dk_wartosc ) {
	$dk_slad = (string) $dk_metoda->invoke( null, $dk_wartosc );

	dk_ok(
		'' !== trim( $dk_slad ),
		'C (' . $dk_opis . '): slad NIE jest pusty',
		'slad=' . wp_json_encode( $dk_slad )
	);
}

dk_ok(
	false !== mb_strpos( (string) $dk_metoda->invoke( null, new WP_Error( 'zablokowane', 'Wtyczka bezpieczenstwa zablokowala zapis' ) ), 'Wtyczka bezpieczenstwa' ),
	'C: KONTR-ASERCJA — gdy WordPress podal powod, to on trafia do sladu'
);
dk_ok(
	false !== mb_strpos( (string) $dk_metoda->invoke( null, new WP_Error( 'zablokowane_przez_wtyczke', '' ) ), 'zablokowane_przez_wtyczke' ),
	'C: przy pustej tresci do sladu idzie przynajmniej KOD bledu — jedyna wskazowka, jaka zostala'
);

/* ==================================================================== D */

$GLOBALS['mp_dk']['lines'][] = '';
$GLOBALS['mp_dk']['lines'][] = '=== D. odmowa mowi, KTOREGO pola brakuje ===';

/*
 * Krytyk K2.1 dostawal `errors` jako klucz z brakami, a agent 2.1 zwraca je pod
 * kluczem `missing_fields`. Nazwa podana raz i zle nie rzuca bledu: `isset()`
 * jest falszem, lista wychodzi pusta i wyglada jak „braki nieznane" zamiast jak
 * literowka. Odmowa `required_missing` szla wiec BEZ informacji, co uzupelnic.
 */
$dk_agent21 = ( new MP_D2_Agent_Required_Fields() )->run(
	new MP_Context(
		array(
			'company_name' => '',
			'email'        => 'kontakt@firma.pl',
			'nip'          => '',
		)
	)
);
$dk_d21 = (array) $dk_agent21->get_data();

dk_ok(
	false === ( $dk_d21['required_ok'] ?? null ),
	'D1: agent 2.1 zglasza braki'
);

$dk_krytyk = new MP_Flag_Critic( 'K2.1', 'test', 'required_ok', 'missing_fields', 'required_missing' );
$dk_ocena  = $dk_krytyk->review( $dk_agent21, new MP_Context( array() ) );
/*
 * Braki ida do DANYCH wyniku pod kluczem `errors` (tak sklada odmowe
 * `MP_Flag_Critic`), a `get_errors()` niesie samo zdanie o niespelnionym
 * warunku. Asercja pyta wiec o to miejsce, w ktorym lista naprawde jest —
 * inaczej sprawdzalaby komunikat zamiast tresci odmowy.
 */
$dk_bledy = (array) ( $dk_ocena->get_data()['errors'] ?? array() );

dk_ok(
	! $dk_ocena->is_ok() && 'required_missing' === $dk_ocena->get_code(),
	'D2: krytyk odmawia z kodem required_missing',
	'kod=' . $dk_ocena->get_code()
);
dk_ok(
	! empty( $dk_bledy ),
	'D3: i odmowa NIESIE liste brakow, a nie pusta tablice',
	'bledy=' . wp_json_encode( $dk_bledy )
);

$dk_plaskie = wp_json_encode( $dk_bledy );

dk_ok(
	false !== mb_strpos( (string) $dk_plaskie, 'company_name' ) && false !== mb_strpos( (string) $dk_plaskie, 'nip' ),
	'D4: wymienia OBA brakujace pola po nazwie',
	'bledy=' . $dk_plaskie
);
dk_ok(
	false === mb_strpos( (string) $dk_plaskie, 'email' ),
	'D5: KONTR-ASERCJA — pola podanego nie wymienia'
);

/*
 * Klucz sprawdzony PRZECIWKO temu, co agent naprawde zwraca — inaczej test
 * utrwalilby te sama literowke, ktora naprawia.
 */
dk_ok(
	array_key_exists( 'missing_fields', $dk_d21 ) && ! array_key_exists( 'errors', $dk_d21 ),
	'D6: agent 2.1 oddaje braki pod kluczem `missing_fields` i nie ma klucza `errors`',
	'klucze=' . implode( ',', array_keys( $dk_d21 ) )
);

/* ==================================================================== E */

$GLOBALS['mp_dk']['lines'][] = '';
$GLOBALS['mp_dk']['lines'][] = '=== E. adres SCIETY DO ZERA tez dostaje zdanie dla czlowieka ===';

/*
 * Gdy `sanitize_email()` zetnie adres do pustej wartosci — a robi to dla adresu
 * zlozonego z samych znakow spoza swojego zbioru — potok zatrzymywal sie na
 * krytyku K2.2 z komunikatem technicznym „Puste pole po normalizacji: email".
 * Zdanie napisane WLASNIE dla tego przypadku stalo dwa agenty dalej i nigdy do
 * klienta nie docieralo. Czyli najgorszy przypadek dostawal najgorszy komunikat.
 */
$dk_scinane = array( 'żółć@firma.pl', 'ąęćń@firma.pl' );

foreach ( $dk_scinane as $dk_adres ) {
	$dk_po = sanitize_email( $dk_adres );

	dk_ok(
		'' === $dk_po,
		'E-pomiar (' . $dk_adres . '): sanitize_email scina adres DO ZERA',
		'wynik=' . wp_json_encode( $dk_po )
	);

	$dk_kontekst = new MP_Context(
		array(
			'company_name' => 'Firma Testowa',
			'email'        => $dk_adres,
			'nip'          => '5260001246',
			'country'      => 'PL',
		)
	);
	$dk_wynik = ( new MP_D2_Normalize_Critic( 'K2.2', 'test' ) )->review(
		( new MP_D2_Agent_Normalize() )->run( $dk_kontekst ),
		$dk_kontekst
	);
	$dk_tresc = implode( ' ', (array) $dk_wynik->get_errors() );

	dk_ok(
		! $dk_wynik->is_ok(),
		'E (' . $dk_adres . '): zgloszenie nadal jest odrzucane'
	);
	dk_ok(
		false === mb_stripos( $dk_tresc, 'po normalizacji' ),
		'E (' . $dk_adres . '): i NIE dostaje komunikatu technicznego',
		'komunikat=' . $dk_tresc
	);
	dk_ok(
		$dk_tresc === MP_D2_Agent_Validate_Formats::MSG_EMAIL_NIEOBSLUGIWANY,
		'E (' . $dk_adres . '): tylko to samo zdanie, co przy adresie przepisanym',
		'komunikat=' . $dk_tresc
	);
}

/*
 * KONTR-ASERCJA. Puste pole OD POCZATKU to zupelnie inna sytuacja — tam
 * komunikat techniczny jest na miejscu, bo nie ma czego „przepisywac".
 */
$dk_puste_kontekst = new MP_Context(
	array(
		'company_name' => '',
		'email'        => 'kontakt@firma.pl',
		'nip'          => '5260001246',
		'country'      => 'PL',
	)
);
$dk_puste = ( new MP_D2_Normalize_Critic( 'K2.2', 'test' ) )->review(
	( new MP_D2_Agent_Normalize() )->run( $dk_puste_kontekst ),
	$dk_puste_kontekst
);

/*
 * Ta asercja pilnuje ROZROZNIENIA dwoch sytuacji, a nie konkretnej nazwy kodu.
 * W rundzie 7 utrwalila przy okazji `normalize_failed` dla pola, ktorego klient
 * NIGDY nie wypelnil — a to opisuje operacje, ktora nie zaszla: nie bylo czego
 * normalizowac. Od braku pola jest `required_missing`. Sens zostaje ten sam:
 * „puste od poczatku" ma konczyc sie INACZEJ niz „sciete do pustego".
 */
dk_ok(
	! $dk_puste->is_ok() && 'required_missing' === $dk_puste->get_code(),
	'E: KONTR-ASERCJA — pusta nazwa firmy to BRAK POLA, nie nieudana normalizacja',
	'kod=' . $dk_puste->get_code()
);
dk_ok(
	$dk_puste->get_code() !== ( ( new MP_D2_Normalize_Critic( 'K2.2', 'test' ) )->review(
		( new MP_D2_Agent_Normalize() )->run(
			new MP_Context( array( 'company_name' => 'Firma Testowa', 'email' => 'żółć@firma.pl', 'nip' => '5260001246', 'country' => 'PL' ) )
		),
		new MP_Context( array( 'company_name' => 'Firma Testowa', 'email' => 'żółć@firma.pl', 'nip' => '5260001246', 'country' => 'PL' ) )
	) )->get_code(),
	'E: i obie sytuacje maja ROZNE kody odmowy — o to w tej asercji chodzi'
);

/* ==================================================================== F */

$GLOBALS['mp_dk']['lines'][] = '';
$GLOBALS['mp_dk']['lines'][] = '=== F. „NIP jest wymagany" tylko gdy NIP-u NIE PODANO ===';

/*
 * Agent 2.3 widzi wartosc PO kanonizacji, ktora dla Polski wycina wszystko poza
 * cyframi. Klient, ktory wpisal „---" albo „brak", dostawal wiec zdanie „NIP
 * jest wymagany" o polu, ktore wypelnil — widzial swoj wpis w formularzu i obok
 * komunikat twierdzacy, ze go nie ma.
 */
$dk_bez_cyfr = array( '---', 'brak', 'nie dotyczy', '.-/' );

foreach ( $dk_bez_cyfr as $dk_wpis ) {
	$dk_kom = (string) ( dk_walidacja( array( 'nip' => $dk_wpis ) )['errors']['nip'] ?? '' );

	dk_ok(
		'' !== $dk_kom && false === mb_stripos( $dk_kom, 'jest wymagany' ),
		'F (' . wp_json_encode( $dk_wpis ) . '): odmowa NIE mowi, ze pola nie podano',
		'komunikat=' . $dk_kom
	);
	dk_ok(
		false !== mb_stripos( $dk_kom, 'cyfr' ),
		'F (' . wp_json_encode( $dk_wpis ) . '): tylko ze nie ma w nim cyfr',
		'komunikat=' . $dk_kom
	);
}

$dk_puste_nip = (string) ( dk_walidacja( array( 'nip' => '' ) )['errors']['nip'] ?? '' );

dk_ok(
	false !== mb_stripos( $dk_puste_nip, 'jest wymagany' ),
	'F: KONTR-ASERCJA — pole NAPRAWDE puste nadal dostaje „NIP jest wymagany"',
	'komunikat=' . $dk_puste_nip
);
dk_ok(
	! isset( dk_walidacja( array( 'nip' => '526-000-12-46' ) )['errors']['nip'] ),
	'F: KONTR-ASERCJA — NIP z myslnikami nadal przechodzi po kanonizacji'
);

/*
 * SAME BIALE ZNAKI TO PUSTE POLE, nie „wpis bez cyfr". Pierwsza wersja tej
 * sekcji wrzucila je do listy wpisow — i asercja slusznie padla. Wartosc surowa
 * jest przycinana, wiec „   " znaczy dokladnie tyle, co pole niewypelnione,
 * a „NIP jest wymagany" jest tam komunikatem wlasciwym.
 */
dk_ok(
	false !== mb_stripos( (string) ( dk_walidacja( array( 'nip' => '   ' ) )['errors']['nip'] ?? '' ), 'jest wymagany' ),
	'F: KONTR-ASERCJA — same biale znaki to puste pole, nie wpis bez cyfr',
	'komunikat=' . ( dk_walidacja( array( 'nip' => '   ' ) )['errors']['nip'] ?? '' )
);
