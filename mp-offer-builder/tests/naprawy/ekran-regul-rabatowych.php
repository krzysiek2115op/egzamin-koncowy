<?php
/**
 * Ekran „Reguly rabatowe" — komunikaty mowily rzeczy, ktorych kod nie zrobil.
 *
 * Uruchamianie: wp eval-file tests/naprawy/ekran-regul-rabatowych.php
 *
 * Szesc ustalen z audytu glebokiego (pary 1.25 i 1.26), wszystkie z jednej
 * rodziny: EKRAN MELDUJE CO INNEGO, NIZ ZASZLO W BAZIE.
 *
 * 1. „Przywroc wbudowane" kasuje opcje, a uzytkownik dostaje zielony komunikat
 *    „Reguly rabatowe zapisane." — meldunek o udanym ZAPISIE w galezi, ktora
 *    niczego nie zapisala.
 *
 * 2. Po bledzie walidacji formularz jest odrysowywany z regul ZAPISANYCH, a nie
 *    z tego, co uzytkownik przed chwila wpisal. Komunikat wskazuje wartosc,
 *    ktorej na ekranie juz nie ma, a cala paczka zmian znika bez slowa.
 *
 * 3. Wyczyszczenie wszystkich wierszy zapisuje konfiguracje „bez regul", ktora
 *    daje 0% dla kazdej oferty — z komunikatem o udanym zapisie i bez zadnego
 *    ostrzezenia. Opisany w naglowku pliku powrot do regul wbudowanych przez
 *    pusta konfiguracje jest ta sciezka NIEOSIAGALNY.
 *
 * 4. Komunikaty bledow nie mowia, ktorego wiersza dotycza. Przy dwoch blednych
 *    wierszach powstaja dwa identyczne, nierozroznialne notice'y.
 *
 * 5. Komunikat sukcesu pokazywany bez sprawdzenia, czy zapis sie powiodl.
 *
 * 6. Etykieta pochodzenia regul („z ustawien" / „wbudowana we wtyczke") liczona
 *    z innego warunku niz faktycznie obowiazujace reguly — przy niespojnej opcji
 *    naglowek twierdzi jedno, a pipeline liczy wedlug drugiego.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_err'] = array(
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
function err_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_err']['pass'];
		$GLOBALS['mp_err']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_err']['fail'];
	$GLOBALS['mp_err']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Rysuje ekran dla zadanego POST-a i oddaje HTML.
 *
 * Sciezka jest prawdziwa: ten sam `render()`, ten sam `maybe_save()`, ten sam
 * nonce. Test sprawdza wiec to, co zobaczy czlowiek, a nie wynik metody
 * pomocniczej wyjetej z kontekstu.
 *
 * @param array $post Zawartosc formularza (bez nonce).
 * @return string
 */
function err_ekran( array $post ) {
	$_POST = $post;

	if ( ! empty( $post ) ) {
		$_POST['_wpnonce'] = wp_create_nonce( MP_OB_Settings::ACTION );
	}

	$_REQUEST = $_POST;

	ob_start();
	MP_OB_Settings::render();
	$html = (string) ob_get_clean();

	$_POST    = array();
	$_REQUEST = array();

	return $html;
}

/**
 * Wiersz formularza.
 *
 * @param string $wariant Wariant cenowy.
 * @param mixed  $min_qty Prog.
 * @param mixed  $percent Rabat.
 * @return array
 */
function err_wiersz( $wariant, $min_qty, $percent ) {
	return array(
		'wariant' => $wariant,
		'min_qty' => $min_qty,
		'percent' => $percent,
	);
}

$err_stary_user = get_current_user_id();
$err_admin      = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( empty( $err_admin ) ) {
	echo "BRAK ADMINISTRATORA — test nie ma kim kliknac.\nVERDICT_HAS_FAILURES\n";
	return;
}

wp_set_current_user( (int) $err_admin[0] );

$err_stara_opcja = get_option( MP_OB_Settings::OPTION, null );
$err_wariant     = MP_OB_D1_Agent_Contract::ALLOWED_WARIANTS[0];

delete_option( MP_OB_Settings::OPTION );

$GLOBALS['mp_err']['lines'][] = '=== A. „Przywroc wbudowane" nie jest zapisem ===';

// Najpierw cokolwiek zapisujemy, zeby bylo co przywracac.
err_ekran( array( 'mp_ob_rabaty' => array( err_wiersz( $err_wariant, 10, 5 ) ) ) );
$err_po_zapisie = get_option( MP_OB_Settings::OPTION, array() );

err_ok(
	! empty( $err_po_zapisie['rules'] ),
	'A0: (zalozenie testu) reguly z formularza sa w bazie',
	'opcja=' . wp_json_encode( $err_po_zapisie )
);

$err_html_przywroc = err_ekran( array( 'mp_ob_przywroc' => 'Przywroc wbudowane' ) );

err_ok(
	false === get_option( MP_OB_Settings::OPTION, false ),
	'A1: (zalozenie testu) opcja faktycznie skasowana'
);
err_ok(
	false === strpos( $err_html_przywroc, 'Reguły rabatowe zapisane' ),
	'A2: ekran NIE melduje zapisu, bo niczego nie zapisano',
	'html=' . substr( wp_strip_all_tags( $err_html_przywroc ), 0, 200 )
);
err_ok(
	false !== mb_stripos( $err_html_przywroc, 'Przywrócono reguły wbudowane' ),
	'A3: ekran nazywa to, co sie stalo: przywrocenie regul wbudowanych',
	'html=' . substr( wp_strip_all_tags( $err_html_przywroc ), 0, 200 )
);

// KONTR-ASERCJA: zwykly zapis ma dalej meldowac zapis.
$err_html_zapis = err_ekran( array( 'mp_ob_rabaty' => array( err_wiersz( $err_wariant, 10, 5 ) ) ) );

err_ok(
	false !== mb_stripos( $err_html_zapis, 'Reguły rabatowe zapisane' ),
	'A4: KONTR-ASERCJA — udany zapis nadal melduje zapis',
	'html=' . substr( wp_strip_all_tags( $err_html_zapis ), 0, 200 )
);

$GLOBALS['mp_err']['lines'][] = '';
$GLOBALS['mp_err']['lines'][] = '=== B. po bledzie widac to, co sie wpisalo ===';

/*
 * Zapisane jest 10 szt. / 5%. Uzytkownik zmienia prog na 42 i wpisuje rabat 150%
 * — blad. Odrysowanie z bazy pokazuje mu z powrotem 10 i 5, wiec komunikat mowi
 * o wartosci, ktorej na ekranie nie ma, a poprawka progu przepada bez slowa.
 */
$err_html_blad = err_ekran(
	array(
		'mp_ob_rabaty' => array( err_wiersz( $err_wariant, 42, 150 ) ),
	)
);

err_ok(
	false !== strpos( $err_html_blad, 'value="42"' ),
	'B1: formularz pokazuje prog, ktory uzytkownik wpisal (42), a nie zapisany (10)',
	'html=' . substr( $err_html_blad, max( 0, (int) strpos( $err_html_blad, 'min_qty' ) - 60 ), 300 )
);
err_ok(
	false === strpos( $err_html_blad, 'value="10"' ),
	'B2: i nie podmienia go po cichu na stary',
	'html zawiera value="10"'
);
err_ok(
	false !== mb_stripos( $err_html_blad, 'Nic nie zostało zapisane' ),
	'B3: ekran mowi wprost, ze cala paczka zmian zostala odrzucona',
	'html=' . substr( wp_strip_all_tags( $err_html_blad ), 0, 300 )
);
err_ok(
	10 === (int) ( get_option( MP_OB_Settings::OPTION, array() )['rules'][1]['min_qty'] ?? 0 ),
	'B4: KONTR-ASERCJA — baza zostaje nietknieta przy bledzie',
	'opcja=' . wp_json_encode( get_option( MP_OB_Settings::OPTION, array() ) )
);

// KONTR-ASERCJA: po UDANYM zapisie formularz pokazuje to, co w bazie.
$err_html_ok = err_ekran( array( 'mp_ob_rabaty' => array( err_wiersz( $err_wariant, 33, 7 ) ) ) );

err_ok(
	false !== strpos( $err_html_ok, 'value="33"' ),
	'B5: KONTR-ASERCJA — po udanym zapisie na ekranie sa wartosci zapisane'
);

$GLOBALS['mp_err']['lines'][] = '';
$GLOBALS['mp_err']['lines'][] = '=== C. pusta tabela to nie „rabat zerowy dla wszystkich" ===';

/*
 * Walidacja zawsze doklada regule R-00 (catch-all, 0%), wiec wyczyszczenie
 * wszystkich wierszy dawalo konfiguracje z JEDNA regula: zero procent dla
 * kazdego wariantu. Zapis konczyl sie zielonym komunikatem, a rabaty znikaly
 * z calego sklepu. Naglowek pliku obiecuje przy tym, ze pusta konfiguracja
 * znaczy „reguly wbudowane" — tej sciezki nie dalo sie tedy osiagnac.
 */
$err_przed_pustym = get_option( MP_OB_Settings::OPTION, array() );
$err_html_pusty   = err_ekran(
	array(
		'mp_ob_rabaty' => array( err_wiersz( '', '', '' ) ),
	)
);
$err_po_pustym = get_option( MP_OB_Settings::OPTION, array() );

err_ok(
	$err_przed_pustym === $err_po_pustym,
	'C1: pusta tabela niczego nie nadpisuje',
	'przed=' . wp_json_encode( $err_przed_pustym ) . ' po=' . wp_json_encode( $err_po_pustym )
);
err_ok(
	false === mb_stripos( $err_html_pusty, 'Reguły rabatowe zapisane' ),
	'C2: i nie melduje zapisu',
	'html=' . substr( wp_strip_all_tags( $err_html_pusty ), 0, 300 )
);
err_ok(
	false !== mb_stripos( $err_html_pusty, 'Przywróć wbudowane' )
		&& false !== mb_stripos( wp_strip_all_tags( $err_html_pusty ), 'nie podano żadnej reguły' ),
	'C3: ekran tlumaczy, co zrobic zamiast tego',
	'html=' . substr( wp_strip_all_tags( $err_html_pusty ), 0, 300 )
);

$GLOBALS['mp_err']['lines'][] = '';
$GLOBALS['mp_err']['lines'][] = '=== D. blad wskazuje wiersz ===';

$err_html_dwa = err_ekran(
	array(
		'mp_ob_rabaty' => array(
			err_wiersz( $err_wariant, 0, 5 ),
			err_wiersz( $err_wariant, 0, 5 ),
		),
	)
);

$err_tekst_dwa = wp_strip_all_tags( $err_html_dwa );

err_ok(
	false !== mb_stripos( $err_tekst_dwa, 'wiersz 1' ) && false !== mb_stripos( $err_tekst_dwa, 'wiersz 2' ),
	'D1: kazdy z dwoch blednych wierszy jest nazwany z osobna',
	'tekst=' . substr( $err_tekst_dwa, 0, 400 )
);

// KONTR-ASERCJA: tresc bledu ma zostac, numer wiersza to dodatek.
err_ok(
	false !== mb_stripos( $err_tekst_dwa, 'co najmniej 1' ),
	'D2: KONTR-ASERCJA — powod bledu nadal jest w komunikacie',
	'tekst=' . substr( $err_tekst_dwa, 0, 400 )
);

$GLOBALS['mp_err']['lines'][] = '';
$GLOBALS['mp_err']['lines'][] = '=== E. etykieta pochodzenia zgodna z tym, co liczy pipeline ===';

/*
 * Opcja niespojna: ma wersje, nie ma regul. `rules()` odda wtedy reguly
 * WBUDOWANE, a etykieta liczona z samego istnienia opcji twierdzila „z ustawien".
 * Naglowek strony mowil co innego niz Dzial 5.
 */
update_option( MP_OB_Settings::OPTION, array( 'version' => 'cfg-niespojna' ) );

$err_html_etykieta = wp_strip_all_tags( err_ekran( array() ) );

err_ok(
	MP_OB_D5_Agent_Discount_Rules::RULES === MP_OB_Settings::rules(),
	'E0: (zalozenie testu) przy takiej opcji pipeline liczy wedlug regul wbudowanych'
);
err_ok(
	false !== mb_stripos( $err_html_etykieta, 'wbudowana we wtyczkę' ),
	'E1: naglowek mowi to samo, co liczy pipeline',
	'tekst=' . substr( $err_html_etykieta, 0, 400 )
);
err_ok(
	MP_OB_D5_Agent_Discount_Rules::RULES_VERSION === MP_OB_Settings::rules_version(),
	'E2: i wersja stemplujaca oferte tez — inaczej znacznik wskazuje slownik, ktorym nic nie liczono',
	'wersja=' . MP_OB_Settings::rules_version()
);

// KONTR-ASERCJA: spojna konfiguracja ma dalej byc rozpoznawana jako wlasna.
err_ekran( array( 'mp_ob_rabaty' => array( err_wiersz( $err_wariant, 15, 4 ) ) ) );
$err_html_wlasne = wp_strip_all_tags( err_ekran( array() ) );

err_ok(
	false !== mb_stripos( $err_html_wlasne, 'z ustawień' )
		&& MP_OB_D5_Agent_Discount_Rules::RULES_VERSION !== MP_OB_Settings::rules_version(),
	'E3: KONTR-ASERCJA — przy prawdziwych regulach z ustawien naglowek i wersja sa „z ustawien"',
	'wersja=' . MP_OB_Settings::rules_version()
);

$GLOBALS['mp_err']['lines'][] = '';
$GLOBALS['mp_err']['lines'][] = '=== F. nieudany zapis nie melduje sukcesu ===';

/*
 * `pre_update_option_*` oddaje wartosc dotychczasowa, wiec WordPress nie ma co
 * zapisac i `update_option()` zwraca false. Ekran nie sprawdzal tego w ogole:
 * zielony komunikat szedl na sama obecnosc POST-a bez bledow walidacji.
 */
delete_option( MP_OB_Settings::OPTION );
add_filter( 'pre_update_option_' . MP_OB_Settings::OPTION, 'err_blokuj_zapis', 10, 2 );

/**
 * Udaje bazE, ktora nie przyjmuje zapisu.
 *
 * @param mixed $nowa  Nowa wartosc.
 * @param mixed $stara Dotychczasowa wartosc.
 * @return mixed
 */
function err_blokuj_zapis( $nowa, $stara ) {
	return $stara;
}

$err_html_awaria = wp_strip_all_tags( err_ekran( array( 'mp_ob_rabaty' => array( err_wiersz( $err_wariant, 12, 3 ) ) ) ) );

remove_filter( 'pre_update_option_' . MP_OB_Settings::OPTION, 'err_blokuj_zapis', 10 );

err_ok(
	false === mb_stripos( $err_html_awaria, 'Reguły rabatowe zapisane' ),
	'F1: bez zapisu nie ma meldunku o zapisie',
	'tekst=' . substr( $err_html_awaria, 0, 300 )
);
err_ok(
	false !== mb_stripos( $err_html_awaria, 'nie udało się zapisać' ),
	'F2: uzytkownik dowiaduje sie, ze zapis nie doszedl do skutku',
	'tekst=' . substr( $err_html_awaria, 0, 300 )
);

// Sprzatanie: opcja i uzytkownik jak przed testem.
if ( null === $err_stara_opcja ) {
	delete_option( MP_OB_Settings::OPTION );
} else {
	update_option( MP_OB_Settings::OPTION, $err_stara_opcja );
}

wp_set_current_user( (int) $err_stary_user );

echo implode( "\n", $GLOBALS['mp_err']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_err']['pass'], $GLOBALS['mp_err']['fail'] );
echo ( 0 === $GLOBALS['mp_err']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
