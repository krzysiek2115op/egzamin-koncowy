<?php
/**
 * Test na ZYWYM WordPressie: prosba klienta w szkicu + zatwierdzenie oferty.
 *
 * Uruchamianie: wp eval-file tests/koncowe/zatwierdzenie-oferty.php
 * Srodowisko: WordPress + MySQL/MariaDB, aktywna wtyczka MP Offer Builder.
 * Sekcja E dodatkowo wymaga wtyczek MP Lead Intake i MP Sales Workflow — bez
 * nich jest pomijana, a nie zglaszana jako blad (kazda wtyczka ma dzialac sama).
 *
 * Zakres wynika ze zlecenia:
 *  - krok 4: „oferta zatwierdzona -> wysylka do klienta" — wtyczka 3 od poczatku
 *    nasluchiwala `mp_offer_approved`, ale nikt tego zdarzenia nie wystawial;
 *  - cel biznesowy: proces „bez recznego kopiowania danych" — produkty i wolumen
 *    podane przez klienta nie docieraly do ekranu budowy oferty.
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-K1  Zatwierdzenie oferty nie podbijalo lock_version
 *   - P2-K2  Dzial 10 nadpisywal oferte juz zatwierdzona
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_z'] = array(
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
function mz_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_z']['pass'];
		$GLOBALS['mp_z']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_z']['fail'];
	$GLOBALS['mp_z']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Wypisuje wynik takze po bledzie krytycznym (fatal w trakcie testu skasowalby
 * caly dziennik, gdyby leciał dopiero na koncu skryptu).
 *
 * @return void
 */
function mz_dump() {
	if ( empty( $GLOBALS['mp_z']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_z'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$path = is_dir( '/scr' ) ? '/scr/mp-p2-zatwierdzenie.txt' : '/tmp/mp-p2-zatwierdzenie.txt';
	file_put_contents( $path, $out ); // phpcs:ignore
	$GLOBALS['mp_z']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'mz_dump' );

global $wpdb;

$offers_t = MP_Offer_Builder_DB::offers_table();
$log_t    = MP_Offer_Builder_DB::activity_log_table();

// Kazdy przebieg ma wlasna serie identyfikatorow i numerow: numer oferty jest
// unikalny w skali instalacji (uq_offer_number_version tutaj, uq_offer_number w
// BD-3), wiec staly numer przeszedlby raz i wywalil sie za drugim razem.
// Krok 3 miedzy seriami, a nie 1: przy dwoch przebiegach w odstepie sekundy
// lead1 kolejnego przebiegu trafilby na lead2/lead3 poprzedniego i czytalby
// cudze wiersze zamiast swoich.
$seria = (int) substr( (string) time(), -6 );
$lead1 = 900000 + $seria * 3;
$lead2 = $lead1 + 1;
$lead3 = $lead1 + 2;

$GLOBALS['mp_z']['lines'][] = '=== SEKCJA A: prosba klienta trafia do szkicu (#3b) ===';

// Handlowiec-wlasciciel: kontrola dostepu do oferty opiera sie na created_by,
// a `wp eval-file` startuje bez zalogowanego uzytkownika.
$handlowiec = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" );
mz_ok( $handlowiec > 0, 'srodowisko: jest przynajmniej jedno konto uzytkownika' );

$produkty = "500 szt. filtrow HEPA H13\nmoze tez obudowy stalowe";
$wolumen  = '500 szt./mies.';

do_action(
	'mp_lead_created',
	$lead1,
	array(
		'lead_id'      => $lead1,
		'company_name' => 'Testowa Wentylacja Sp. z o.o.',
		'email'        => 'kontakt+' . $seria . '@example.test',
		'nip'          => '1234567890',
		'country'      => 'PL',
		'vat_status'   => 'checked',
		'salesman_id'  => $handlowiec,
		'products'     => $produkty,
		'est_volume'   => $wolumen,
	)
);

$szkic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE lead_id = %d", $lead1 ), ARRAY_A ); // phpcs:ignore
mz_ok( is_array( $szkic ), 'A1: szkic oferty powstal ze zdarzenia leada' );
mz_ok( is_array( $szkic ) && $produkty === (string) $szkic['lead_products'], 'A2: szkic niesie opis produktow z formularza', is_array( $szkic ) ? var_export( $szkic['lead_products'], true ) : 'brak szkicu' );
mz_ok( is_array( $szkic ) && $wolumen === (string) $szkic['lead_est_volume'], 'A3: szkic niesie przewidywany wolumen' );
mz_ok( is_array( $szkic ) && $handlowiec === (int) $szkic['created_by'], 'A4: wlascicielem szkicu jest handlowiec z leada' );

// Powtorzone zdarzenie z NOWA trescia (reaktywacja leada) odswieza opis.
do_action(
	'mp_lead_created',
	$lead1,
	array(
		'lead_id'      => $lead1,
		'company_name' => 'Testowa Wentylacja Sp. z o.o.',
		'email'        => 'kontakt+' . $seria . '@example.test',
		'products'     => 'tym razem tylko obudowy',
		'est_volume'   => '20 szt./kw.',
	)
);

$po_reaktywacji = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE lead_id = %d", $lead1 ), ARRAY_A ); // phpcs:ignore
mz_ok( 1 === count( $po_reaktywacji ), 'A5: powtorzone zdarzenie NIE zdublowalo szkicu', 'szkicow: ' . count( $po_reaktywacji ) );
mz_ok( 'tym razem tylko obudowy' === (string) $po_reaktywacji[0]['lead_products'], 'A6: opis prosby odswiezony po reaktywacji leada' );
mz_ok( '20 szt./kw.' === (string) $po_reaktywacji[0]['lead_est_volume'], 'A7: wolumen odswiezony po reaktywacji leada' );

// Pusty payload (starsza wtyczka 1) nie ma prawa skasowac tego, co juz mamy.
do_action(
	'mp_lead_created',
	$lead1,
	array(
		'lead_id'      => $lead1,
		'company_name' => 'Testowa Wentylacja Sp. z o.o.',
		'email'        => 'kontakt+' . $seria . '@example.test',
	)
);

$po_pustym = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE lead_id = %d", $lead1 ), ARRAY_A ); // phpcs:ignore
mz_ok( 'tym razem tylko obudowy' === (string) $po_pustym['lead_products'], 'A8: zdarzenie bez pol produktowych NIE kasuje zapisanej tresci' );

// Lead bez opisu produktow (pola nieobowiazkowe w formularzu) — szkic i tak ma powstac.
do_action(
	'mp_lead_created',
	$lead2,
	array(
		'lead_id'      => $lead2,
		'company_name' => 'Firma Bez Opisu',
		'email'        => 'bezopisu+' . $seria . '@example.test',
		'salesman_id'  => $handlowiec,
	)
);

$szkic2 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE lead_id = %d", $lead2 ), ARRAY_A ); // phpcs:ignore
mz_ok( is_array( $szkic2 ), 'A9: brak produktow i wolumenu nie blokuje zalozenia szkicu' );
mz_ok( is_array( $szkic2 ) && null === $szkic2['lead_products'], 'A10: brak opisu zapisany jako NULL, a nie pusty tekst' );

$GLOBALS['mp_z']['lines'][] = '';
$GLOBALS['mp_z']['lines'][] = '=== SEKCJA B: odmowy zatwierdzenia (#4) ===';

$przed_b = did_action( MP_Offer_Builder_Approval::HOOK );

// Szkic z leada nie ma jeszcze ani numeru, ani PDF-a.
$wynik = MP_Offer_Builder_Approval::approve( (int) $po_pustym['id'], $handlowiec );
mz_ok( is_wp_error( $wynik ) && 'no_document' === $wynik->get_error_code(), 'B1: szkic bez numeru i PDF-a nie da sie zatwierdzic', is_wp_error( $wynik ) ? $wynik->get_error_code() : 'zwrocono ' . var_export( $wynik, true ) );
mz_ok( 'draft' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$offers_t} WHERE id = %d", (int) $po_pustym['id'] ) ), 'B2: odmowa nie ruszyla statusu oferty' ); // phpcs:ignore

$wynik = MP_Offer_Builder_Approval::approve( 999000123, $handlowiec );
mz_ok( is_wp_error( $wynik ) && 'offer_not_found' === $wynik->get_error_code(), 'B3: nieistniejaca oferta konczy sie odmowa' );

mz_ok( $przed_b === did_action( MP_Offer_Builder_Approval::HOOK ), 'B4: zadna odmowa NIE wystawila zdarzenia mp_offer_approved' );

$GLOBALS['mp_z']['lines'][] = '';
$GLOBALS['mp_z']['lines'][] = '=== SEKCJA C: zatwierdzenie kompletnej oferty ===';

// Gotowy dokument jako fixture: sposob, w jaki oferta dostala numer i PDF, jest
// sprawdzany osobno (harness pipeline'u 110/110). Tutaj testujemy sam akt
// zatwierdzenia, ktory o pochodzenie wiersza nie pyta.
/*
 * Numer w formacie zgodnym z generatorem (OF/RRRR/NNNNNN) i w roku 2999.
 * Wczesniej fixture uzywal 'OF/2026/T<seria>' — format niezgodny z reszta, a do
 * tego w BIEZACYM roku. `get_last_offer_number_for_year()` bierze najwyzszy numer
 * roku, wiec ten smiec stawal sie „ostatnim numerem" i realna oferta konczyla sie
 * odmowa `malformed_last_number`. Kod zachowal sie poprawnie (odmowil zamiast
 * zgadywac) — to test zatruwal przestrzen numerow. Rok 2999 jest poza filtrem
 * biezacego roku, wiec nie ruszy sekwencji produkcyjnej.
 */
$numer = sprintf( 'OF/2999/%06d', $seria % 1000000 );
$wpdb->update( // phpcs:ignore
	$offers_t,
	array(
		'offer_number' => $numer,
		'version'      => 1,
		'lang'         => 'pl',
		'net_grosze'   => 100000,
		'vat_grosze'   => 23000,
		'gross_grosze' => 123000,
		'currency'     => 'PLN',
		'pdf_path'     => 'mp-offer-builder-private/test-' . $seria . '.pdf',
	),
	array( 'id' => (int) $po_pustym['id'] )
);

$oferta_id = (int) $po_pustym['id'];

$GLOBALS['mp_z_payload'] = null;
$GLOBALS['mp_z_licznik'] = 0;
add_action(
	MP_Offer_Builder_Approval::HOOK,
	function ( $offer_id, $payload ) {
		$GLOBALS['mp_z_payload'] = array( 'offer_id' => $offer_id ) + (array) $payload;
		++$GLOBALS['mp_z_licznik'];
	},
	1,
	2
);

$wynik = MP_Offer_Builder_Approval::approve( $oferta_id, $handlowiec );
mz_ok( true === $wynik, 'C1: kompletna oferta zostala zatwierdzona', is_wp_error( $wynik ) ? $wynik->get_error_code() . ': ' . $wynik->get_error_message() : var_export( $wynik, true ) );
mz_ok( 'approved' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$offers_t} WHERE id = %d", $oferta_id ) ), 'C2: status oferty w BD-2 to approved' ); // phpcs:ignore
mz_ok( 1 === $GLOBALS['mp_z_licznik'], 'C3: zdarzenie mp_offer_approved wystawione DOKLADNIE raz', 'razy: ' . $GLOBALS['mp_z_licznik'] );

$p = is_array( $GLOBALS['mp_z_payload'] ) ? $GLOBALS['mp_z_payload'] : array();
mz_ok( $oferta_id === (int) $p['offer_id'], 'C4: payload niesie identyfikator oferty' );
mz_ok( isset( $p['lead_id'] ) && $lead1 === (int) $p['lead_id'], 'C5: payload niesie lead_id (bez niego wtyczka 3 nie wie, ktorego procesu dotyczy oferta)' );
mz_ok( isset( $p['offer_number'] ) && $numer === (string) $p['offer_number'], 'C6: payload niesie numer oferty' );
mz_ok( isset( $p['status'] ) && 'approved' === (string) $p['status'], 'C7: payload niesie status approved' );
mz_ok( isset( $p['gross_grosze'] ) && 123000 === (int) $p['gross_grosze'], 'C8: payload niesie kwote brutto w groszach' );
mz_ok( isset( $p['currency'] ) && 'PLN' === (string) $p['currency'], 'C9: payload niesie walute' );
mz_ok( isset( $p['approved_by'] ) && $handlowiec === (int) $p['approved_by'], 'C10: payload niesie, kto zatwierdzil' );

mz_ok(
	1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log_t} WHERE offer_id = %d AND action = 'offer_approved'", $oferta_id ) ), // phpcs:ignore
	'C11: zatwierdzenie odnotowane w dzienniku BD-2 (kryt. 5.5)'
);

// Podwojne kliknieciepowtarza sie w praktyce czesciej, niz sie wydaje.
$GLOBALS['mp_z_licznik'] = 0;
$wynik                   = MP_Offer_Builder_Approval::approve( $oferta_id, $handlowiec );
mz_ok( is_wp_error( $wynik ) && 'already_approved' === $wynik->get_error_code(), 'C12: powtorne zatwierdzenie konczy sie informacja, nie zmiana' );
mz_ok( 0 === $GLOBALS['mp_z_licznik'], 'C13: powtorne zatwierdzenie NIE wystawilo drugiego zdarzenia' );

$GLOBALS['mp_z']['lines'][] = '';
$GLOBALS['mp_z']['lines'][] = '=== SEKCJA D: oferta zatwierdzona jest zamrozona ===';

// Dzial 1 pipeline'u przyjmuje offer_id wylacznie w statusie draft — to jest
// mechanizm, ktory po zatwierdzeniu zamyka oferte na edycje.
$d1  = MP_OB_Department_01::build();
$ctx = new MP_OB_Context(
	array(
		'offer_id' => $oferta_id,
		'items'    => array( array( 'product_id' => 1, 'qty' => 1 ) ),
		'wariant'  => 'standard',
		'lang'     => 'pl',
	)
);
$r1 = $d1->process( $ctx );
mz_ok( ! $r1->is_ok(), 'D1: pipeline odmawia edycji oferty zatwierdzonej', 'kod: ' . $r1->get_code() );

// Cudza oferta i oferta nieistniejaca musza dawac TEN SAM komunikat, inaczej
// offer_id staje sie wyrocznia do wyliczania cudzych ofert.
$obcy   = $handlowiec + 90001;
$wynik  = MP_Offer_Builder_Approval::approve( (int) $szkic2['id'], $obcy );
$wynik2 = MP_Offer_Builder_Approval::approve( 999000124, $obcy );
mz_ok( is_wp_error( $wynik ) && 'offer_not_found' === $wynik->get_error_code(), 'D2: cudza oferta zwraca „nie istnieje", a nie „brak dostepu"', is_wp_error( $wynik ) ? $wynik->get_error_code() : 'zwrocono ' . var_export( $wynik, true ) );
mz_ok( is_wp_error( $wynik2 ) && $wynik->get_error_message() === $wynik2->get_error_message(), 'D3: cudza i nieistniejaca oferta daja identyczny komunikat' );
mz_ok( 'draft' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$offers_t} WHERE id = %d", (int) $szkic2['id'] ) ), 'D4: proba na cudzej ofercie niczego nie zmienila' ); // phpcs:ignore

$GLOBALS['mp_z']['lines'][] = '';
$GLOBALS['mp_z']['lines'][] = '=== SEKCJA E: co robia z tym wtyczki 1 i 3 ===';

if ( ! class_exists( 'MP_Lead_Intake_DB' ) || ! class_exists( 'MP_Sales_Workflow_DB' ) ) {
	$GLOBALS['mp_z']['lines'][] = '  [POMINIETO] wtyczka 1 lub 3 nieaktywna — sekcja E wymaga wszystkich trzech';
} else {
	$bd3_leads  = MP_Lead_Intake_DB::leads_table();
	$bd3_offers = MP_Lead_Intake_DB::offers_table();
	$flow_t     = MP_Sales_Workflow_DB::flow_table();

	// Wskaznik oferty w BD-3 powstaje tylko dla ISTNIEJACEGO leada, wiec lead musi
	// byc prawdziwym wierszem. Wstawiamy go wprost: pelny przebieg formularza jest
	// sprawdzany w tescie wtyczki 1, tutaj potrzebujemy tylko poprawnego celu relacji.
	$wpdb->insert( // phpcs:ignore
		$bd3_leads,
		array(
			'id'           => $lead3,
			'company_name' => 'Relacja Test Sp. z o.o.',
			'nip'          => '999' . str_pad( (string) ( $seria % 10000000 ), 7, '0', STR_PAD_LEFT ),
			'email'        => 'relacja+' . $seria . '@example.test',
			'country'      => 'PL',
			'status'       => 'new',
		)
	);

	$lead_ok = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bd3_leads} WHERE id = %d", $lead3 ) ); // phpcs:ignore
	mz_ok( 1 === $lead_ok, 'E0: lead-fixture zapisany w BD-3', $wpdb->last_error );

	// Pelna sciezka: lead -> szkic -> oferta robocza -> zatwierdzenie.
	do_action(
		'mp_lead_created',
		$lead3,
		array(
			'lead_id'      => $lead3,
			'company_name' => 'Relacja Test Sp. z o.o.',
			'email'        => 'relacja+' . $seria . '@example.test',
			'country'      => 'PL',
			'salesman_id'  => $handlowiec,
			'products'     => 'kompletna linia filtracyjna',
			'est_volume'   => '1 komplet',
		)
	);

	$oferta3 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE lead_id = %d", $lead3 ), ARRAY_A ); // phpcs:ignore
	mz_ok( is_array( $oferta3 ), 'E1: szkic dla leada-fixture powstal' );

	$numer3 = sprintf( 'OF/2999/9%05d', $seria % 100000 );
	$wpdb->update( // phpcs:ignore
		$offers_t,
		array(
			'offer_number' => $numer3,
			'version'      => 1,
			'lang'         => 'pl',
			'net_grosze'   => 200000,
			'vat_grosze'   => 46000,
			'gross_grosze' => 246000,
			'currency'     => 'PLN',
			'pdf_path'     => 'mp-offer-builder-private/test-e-' . $seria . '.pdf',
		),
		array( 'id' => (int) $oferta3['id'] )
	);

	// Krok posredni: oferta robocza. Wtyczka 3 dopuszcza zatwierdzenie dopiero z
	// tego stanu procesu — tak samo jak w prawdziwym przebiegu z pipeline'u.
	do_action(
		'mp_offer_created',
		(int) $oferta3['id'],
		array(
			'offer_id'     => (int) $oferta3['id'],
			'offer_number' => $numer3,
			'version'      => 1,
			'status'       => 'draft',
			'client_name'  => 'Relacja Test Sp. z o.o.',
			'gross_grosze' => 246000,
			'currency'     => 'PLN',
			'lead_id'      => $lead3,
		)
	);

	$wskaznik = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bd3_offers} WHERE offer_number = %s", $numer3 ), ARRAY_A ); // phpcs:ignore
	mz_ok( is_array( $wskaznik ) && $lead3 === (int) $wskaznik['lead_id'], 'E2: wtyczka 1 zapisala wskaznik oferty w BD-3' );
	mz_ok( is_array( $wskaznik ) && 'draft' === (string) $wskaznik['status'], 'E3: wskaznik ma status draft' );

	$wynik = MP_Offer_Builder_Approval::approve( (int) $oferta3['id'], $handlowiec );
	mz_ok( true === $wynik, 'E4: oferta zatwierdzona', is_wp_error( $wynik ) ? $wynik->get_error_code() . ': ' . $wynik->get_error_message() : '' );

	$po_zatw = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bd3_offers} WHERE offer_number = %s", $numer3 ), ARRAY_A ); // phpcs:ignore
	mz_ok( is_array( $po_zatw ) && 'approved' === (string) $po_zatw['status'], 'E5: wtyczka 1 przestawila status wskaznika w BD-3 na approved', is_array( $po_zatw ) ? (string) $po_zatw['status'] : 'brak wiersza' );
	mz_ok( is_array( $po_zatw ) && is_array( $wskaznik ) && (int) $po_zatw['id'] === (int) $wskaznik['id'], 'E6: zatwierdzenie ZAKTUALIZOWALO wiersz, nie dolozylo drugiego' );
	mz_ok( is_array( $po_zatw ) && '2460.00' === (string) $po_zatw['total_amount'], 'E7: kwota przeliczona z groszy na zlote', is_array( $po_zatw ) ? (string) $po_zatw['total_amount'] : '' );

	$status_procesu = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$flow_t} WHERE lead_id = %d", $lead3 ) ); // phpcs:ignore
	mz_ok( 'offer_sent' === $status_procesu, 'E8: wtyczka 3 przesunela proces na „oferta wyslana" (krok 4 zlecenia)', 'jest: ' . $status_procesu );
}

$GLOBALS['mp_z']['lines'][] = '';
$GLOBALS['mp_z']['lines'][] = '=== SEKCJA F: wyscig zatwierdzenie <-> zapis Dzialu 10 ===';

/*
 * Blad znaleziony w audycie koncowym. `approve()` nie podbijalo `lock_version`,
 * a Dzial 10 nie mial `status` w warunku WHERE. Scenariusz:
 *
 *   Dzial 2 czyta lock_version = N, status = draft
 *   -> pipeline liczy i renderuje PDF (setki milisekund)
 *   -> w tym czasie handlowiec klika „Zatwierdz" (zdarzenie idzie do wtyczki 3,
 *      klient dostaje dokument)
 *   -> Dzial 10 zapisuje WHERE lock_version = N — TRAFIA, bo zatwierdzenie
 *      tokena nie ruszylo — i cofa status do `draft`, podmieniajac plik JUZ
 *      WYSLANY klientowi. Oferta wraca na liste jako szkic z aktywnym
 *      przyciskiem, a drugie kliniecie wystawia `mp_offer_approved` PONOWNIE.
 *
 * Test odtwarza oba przebiegi: z nieaktualnym tokenem i z AKTUALNYM (ten drugi
 * sprawdza sam wartownik statusu, bo token juz sie zgadza).
 */
$szkic_f = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$offers_t} WHERE id = %d", (int) $szkic2['id'] ), ARRAY_A ); // phpcs:ignore

if ( ! is_array( $szkic_f ) ) {
	$GLOBALS['mp_z']['lines'][] = '  [POMINIETO] brak szkicu do testu wyscigu';
} else {
	$numer_f = sprintf( 'OF/2999/%06d', ( $seria + 7 ) % 1000000 );
	$pdf_f   = 'mp-offer-builder-private/wyscig-' . $seria . '.pdf';

	$wpdb->update( // phpcs:ignore
		$offers_t,
		array(
			'offer_number' => $numer_f,
			'version'      => 1,
			'lang'         => 'pl',
			'net_grosze'   => 100000,
			'vat_grosze'   => 23000,
			'gross_grosze' => 123000,
			'currency'     => 'PLN',
			'pdf_path'     => $pdf_f,
			'status'       => 'draft',
		),
		array( 'id' => (int) $szkic_f['id'] )
	);

	$id_f = (int) $szkic_f['id'];

	// To odczytalby Dzial 2 na poczatku przebiegu.
	$token_przed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$offers_t} WHERE id = %d", $id_f ) ); // phpcs:ignore

	$wynik_f = MP_Offer_Builder_Approval::approve( $id_f, $handlowiec );
	mz_ok( true === $wynik_f, 'F1: oferta zatwierdzona w trakcie „trwajacego" przebiegu', is_wp_error( $wynik_f ) ? $wynik_f->get_error_code() . ': ' . $wynik_f->get_error_message() : '' );

	$token_po = (int) $wpdb->get_var( $wpdb->prepare( "SELECT lock_version FROM {$offers_t} WHERE id = %d", $id_f ) ); // phpcs:ignore
	mz_ok( $token_po === $token_przed + 1, 'F2: zatwierdzenie PODBILO lock_version (inaczej jest niewidzialne dla blokady)', $token_przed . ' -> ' . $token_po );

	/**
	 * Plan zapisu taki, jaki zbudowalby Dzial 10 w trwajacym przebiegu.
	 *
	 * @param int    $id       Identyfikator oferty.
	 * @param int    $token    Token odczytany przez Dzial 2.
	 * @param string $numer    Numer oferty.
	 * @param string $pdf      Sciezka dokumentu do zapisania.
	 * @return array
	 */
	function mz_plan_f( $id, $token, $numer, $pdf ) {
		return array(
			'header'                => array(
				'id'           => $id,
				'lock_version' => $token + 1,
				'offer_number' => $numer,
				'version'      => 1,
				'pdf_path'     => $pdf,
				'updated_at'   => current_time( 'mysql' ),
			),
			'items'                 => array(
				array(
					'product_id'   => 1,
					'variation_id' => 0,
					'qty'          => 1,
				),
			),
			'version'               => array( 'version' => 1 ),
			'expected_lock_version' => $token,
		);
	}

	$pdf_podmieniony = 'mp-offer-builder-private/PODMIENIONY-' . $seria . '.pdf';

	// (a) Token NIEAKTUALNY — tak wyglada realny wyscig po poprawce.
	$r_f = ( new MP_OB_D10_Agent_Transaction() )->run(
		new MP_OB_Context( array( 'write_plan' => mz_plan_f( $id_f, $token_przed, $numer_f, $pdf_podmieniony ) ) )
	);
	mz_ok( ! $r_f->is_ok(), 'F3: zapis z nieaktualnym tokenem ODRZUCONY', 'kod: ' . $r_f->get_code() );
	mz_ok( 'concurrent_modification' === $r_f->get_code(), 'F4: kod odmowy to concurrent_modification', $r_f->get_code() );

	// (b) Token AKTUALNY, ale oferta jest juz zatwierdzona — broni wartownik statusu.
	$r_f2 = ( new MP_OB_D10_Agent_Transaction() )->run(
		new MP_OB_Context( array( 'write_plan' => mz_plan_f( $id_f, $token_po, $numer_f, $pdf_podmieniony ) ) )
	);
	mz_ok( ! $r_f2->is_ok(), 'F5: nawet z AKTUALNYM tokenem nie da sie nadpisac oferty zatwierdzonej', 'kod: ' . $r_f2->get_code() );

	$po_wyscigu = $wpdb->get_row( $wpdb->prepare( "SELECT status, pdf_path FROM {$offers_t} WHERE id = %d", $id_f ), ARRAY_A ); // phpcs:ignore
	mz_ok( is_array( $po_wyscigu ) && 'approved' === (string) $po_wyscigu['status'], 'F6: oferta NIE wrocila do stanu szkicu', is_array( $po_wyscigu ) ? (string) $po_wyscigu['status'] : '?' );
	mz_ok( is_array( $po_wyscigu ) && $pdf_f === (string) $po_wyscigu['pdf_path'], 'F7: dokument wyslany klientowi NIE zostal podmieniony', is_array( $po_wyscigu ) ? (string) $po_wyscigu['pdf_path'] : '?' );
}

mz_dump();
