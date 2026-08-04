<?php
/**
 * Dwa ustalenia audytu z wtyczki 2: cichy fallback stawki VAT i komunikat, który
 * mówi o czymś, czego kod nie sprawdził.
 *
 * Uruchamianie: wp eval-file tests/naprawy/stawka-vat-i-komunikat-dokumentu.php
 *
 * A. STAWKA. Dział 10 zapisuje `tax_rate` pozycji z mapy `line_tax_rates`,
 *    a gdy mapy nie ma — z jednej stawki oferty. Komentarz przy tej gałęzi
 *    mówi wprost: „brak CAŁEJ mapy to dokumentowany fallback (reverse_charge,
 *    out_of_scope)". Warunek jednak MECHANIZMU NIE SPRAWDZAŁ. Oferta krajowa,
 *    która przyszła do Działu 10 bez mapy — bo Dział 6 odmówił, bo pozycje
 *    dołożono później, bo kontekst przyszedł niekompletny — dostawała
 *    `$context->get( 'tax_rate', 0 )`, czyli przy braku stawki po prostu ZERO.
 *    Na dokumencie klienta pojawiłby się polski towar z 0% VAT, a jedyną
 *    informacją o tym byłby brak informacji.
 *
 *    Naprawa: pusta mapa jest dopuszczalna tylko tam, gdzie zerowa stawka
 *    wynika z mechanizmu (odwrotne obciążenie, poza zakresem). Przy sprzedaży
 *    krajowej brak mapy to dziura w danych i pozycja zostaje odrzucona — tak
 *    samo jak mapa bez tej jednej pozycji, bo to ten sam rodzaj szkody.
 *
 * B. KOMUNIKAT. Bramka `no_document` w `approve()` sprawdza `'' === numer
 *    || '' === plik`, a odmowę opisuje zdaniem „Oferta nie ma jeszcze numeru
 *    I pliku PDF". Przy ofercie, która numer MA, zdanie było po prostu
 *    nieprawdziwe — kierowało handlowca do numeracji zamiast do generowania
 *    dokumentu. Warunek mówi „albo", więc komunikat też musi.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_sv'] = array(
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
function sv_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_sv']['pass'];
		$GLOBALS['mp_sv']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_sv']['fail'];
	$GLOBALS['mp_sv']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik i kasuje oferty testowe.
 *
 * @return void
 */
function sv_koniec() {
	global $wpdb;

	if ( empty( $GLOBALS['mp_sv']['lines'] ) ) {
		return;
	}

	foreach ( (array) ( $GLOBALS['mp_sv_sprzatanie'] ?? array() ) as $id ) {
		$wpdb->delete( MP_Offer_Builder_DB::offers_table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	$r    = $GLOBALS['mp_sv'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_sv']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'sv_koniec' );

$GLOBALS['mp_sv_sprzatanie'] = array();

/**
 * Kontekst poprawnego planu zapisu (jedna pozycja, 3 x 100,00 zl).
 *
 * @param array $zmiany Nadpisania.
 * @return array
 */
function sv_kontekst( array $zmiany = array() ) {
	return array_merge(
		array(
			'offer_number'   => 'OF/2026/000321',
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
			'request_id'     => 'req-sv-1',
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
function sv_plan( array $dane ) {
	$agent = new MP_OB_D10_Agent_Plan();

	return $agent->run( new MP_OB_Context( $dane ) );
}

/**
 * Pola, na które poskarżył się agent.
 *
 * @param MP_OB_Result $wynik Wynik.
 * @return string
 */
function sv_pola( MP_OB_Result $wynik ) {
	$dane  = (array) $wynik->get_data();
	$bledy = isset( $dane['errors'] ) ? (array) $dane['errors'] : array();
	$pola  = array();

	foreach ( $bledy as $blad ) {
		$pola[] = isset( $blad['field'] ) ? (string) $blad['field'] : '?';
	}

	return implode( ', ', $pola );
}

/* ==================================================================== A */

$GLOBALS['mp_sv']['lines'][] = '=== A. sprzedaz krajowa bez mapy stawek to nie jest 0% ===';

$sv_krajowa = sv_plan(
	sv_kontekst(
		array(
			'line_tax_rates' => array(),
			'tax_rate'       => null,
		)
	)
);

sv_ok(
	! $sv_krajowa->is_ok(),
	'oferta krajowa bez mapy stawek zostaje odrzucona',
	'pola=' . sv_pola( $sv_krajowa )
);
sv_ok(
	false !== strpos( sv_pola( $sv_krajowa ), 'items.0' ),
	'skarga wskazuje konkretna pozycje',
	'pola=' . sv_pola( $sv_krajowa )
);

/*
 * Wariant groźniejszy, bo wygląda niewinnie: mapy nie ma, ale stawka oferty
 * JEST. Bez sprawdzenia mechanizmu wszystkie pozycje dostawały tę jedną
 * stawkę — także wtedy, gdy pochodziły z różnych klas podatkowych.
 */
$sv_krajowa_ze_stawka = sv_plan(
	sv_kontekst(
		array(
			'line_tax_rates' => array(),
		)
	)
);

sv_ok(
	! $sv_krajowa_ze_stawka->is_ok(),
	'takze wtedy, gdy oferta niesie jedna stawke zbiorcza',
	'pola=' . sv_pola( $sv_krajowa_ze_stawka )
);

/* ==================================================================== B */

$GLOBALS['mp_sv']['lines'][] = '';
$GLOBALS['mp_sv']['lines'][] = '=== B. kontr-asercje: udokumentowany fallback nadal dziala ===';

/**
 * Stawki VAT z planu zapisu.
 *
 * @param MP_OB_Result $wynik Wynik agenta.
 * @return array
 */
function sv_stawki( MP_OB_Result $wynik ) {
	$dane    = (array) $wynik->get_data();
	$plan    = isset( $dane['write_plan'] ) ? (array) $dane['write_plan'] : array();
	$wiersze = isset( $plan['items'] ) ? (array) $plan['items'] : array();
	$stawki  = array();

	foreach ( $wiersze as $wiersz ) {
		$stawki[] = isset( $wiersz['tax_rate'] ) ? (float) $wiersz['tax_rate'] : -1.0;
	}

	return $stawki;
}

foreach ( array( 'reverse_charge', 'out_of_scope' ) as $sv_mechanizm ) {
	$sv_zero = sv_plan(
		sv_kontekst(
			array(
				'line_tax_rates' => array(),
				'tax_mechanism'  => $sv_mechanizm,
				'tax_rate'       => 0.0,
				'vat_grosze'     => 0,
				'gross_grosze'   => 30000,
			)
		)
	);

	sv_ok(
		$sv_zero->is_ok(),
		'mechanizm ' . $sv_mechanizm . ' bez mapy stawek nadal przechodzi',
		'pola=' . sv_pola( $sv_zero )
	);
	sv_ok(
		array( 0.0 ) === sv_stawki( $sv_zero ),
		'i zapisuje 0.00 dla pozycji',
		'stawki=' . implode( ', ', sv_stawki( $sv_zero ) )
	);
}

$sv_pelna = sv_plan( sv_kontekst() );

sv_ok(
	$sv_pelna->is_ok(),
	'oferta krajowa z pelna mapa nadal przechodzi',
	'pola=' . sv_pola( $sv_pelna )
);
sv_ok(
	array( 23.0 ) === sv_stawki( $sv_pelna ),
	'i zapisuje stawke z mapy, nie zbiorcza',
	'stawki=' . implode( ', ', sv_stawki( $sv_pelna ) )
);

$sv_dziura = sv_plan(
	sv_kontekst(
		array(
			'items'          => array( array( 'product_id' => 7, 'qty' => 3 ), array( 'product_id' => 8, 'qty' => 1 ) ),
			'lines'          => array( array( 'unit_grosze' => 10000, 'line_grosze' => 30000 ), array( 'unit_grosze' => 5000, 'line_grosze' => 5000 ) ),
			'line_tax_rates' => array( 23.0 ),
			'net_grosze'     => 35000,
		)
	)
);

sv_ok(
	! $sv_dziura->is_ok() && false !== strpos( sv_pola( $sv_dziura ), 'items.1' ),
	'mapa bez jednej pozycji nadal jest bledem tej pozycji',
	'pola=' . sv_pola( $sv_dziura )
);

/* ==================================================================== C */

$GLOBALS['mp_sv']['lines'][] = '';
$GLOBALS['mp_sv']['lines'][] = '=== C. odmowa mowi, CZEGO brakuje ===';

$sv_seria      = (int) substr( (string) time(), -6 );
$sv_handlowiec = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

/**
 * Zakłada szkic oferty.
 *
 * Klucz `uq_offer_number_version` obejmuje numer I wersję, więc dwa szkice
 * z pustym numerem muszą różnić się wersją — inaczej drugi INSERT odbija się
 * o unikat i test mierzy własną pomyłkę zamiast kodu.
 *
 * @param string $numer  Numer oferty (może być pusty).
 * @param string $plik   Ścieżka dokumentu (może być pusta).
 * @param int    $wersja Wersja oferty.
 * @return int
 */
function sv_szkic( $numer, $plik, $wersja = 1 ) {
	global $wpdb;

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		MP_Offer_Builder_DB::offers_table(),
		array(
			'lead_id'      => 0,
			'offer_number' => $numer,
			'version'      => (int) $wersja,
			'status'       => MP_Offer_Builder_DB::STATUS_DRAFT,
			'client_email' => 'stawka' . wp_rand( 1000, 9999 ) . '@example.test',
			'client_name'  => 'Firma Testowa VAT',
			'pdf_path'     => $plik,
			'lock_version' => 1,
			'created_by'   => (int) $GLOBALS['mp_sv_handlowiec'],
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		)
	);

	$id = (int) $wpdb->insert_id;
	$GLOBALS['mp_sv_sprzatanie'][] = $id;

	return $id;
}

$GLOBALS['mp_sv_handlowiec'] = $sv_handlowiec;

$sv_id_bez_pliku = sv_szkic( 'OF/TEST/' . $sv_seria . '/NUM', '' );
$sv_bez_pliku    = MP_Offer_Builder_Approval::approve( $sv_id_bez_pliku, $sv_handlowiec );
$sv_msg_plik     = is_wp_error( $sv_bez_pliku ) ? (string) $sv_bez_pliku->get_error_message() : '';

sv_ok(
	is_wp_error( $sv_bez_pliku ) && 'no_document' === $sv_bez_pliku->get_error_code(),
	'oferta z numerem, ale bez pliku, nadal nie da sie zatwierdzic',
	'wynik=' . var_export( $sv_bez_pliku, true )
);
sv_ok(
	false !== mb_stripos( $sv_msg_plik, 'PDF' ),
	'komunikat mowi o pliku PDF',
	'komunikat=' . $sv_msg_plik
);
sv_ok(
	false === mb_stripos( $sv_msg_plik, 'numer' ),
	'i NIE twierdzi, ze brakuje numeru — numer jest',
	'komunikat=' . $sv_msg_plik
);

$sv_id_bez_numeru = sv_szkic( '', '/nie/ma/takiego/pliku-' . $sv_seria . '.pdf' );
$sv_bez_numeru    = MP_Offer_Builder_Approval::approve( $sv_id_bez_numeru, $sv_handlowiec );
$sv_msg_numer     = is_wp_error( $sv_bez_numeru ) ? (string) $sv_bez_numeru->get_error_message() : '';

sv_ok(
	is_wp_error( $sv_bez_numeru ) && 'no_document' === $sv_bez_numeru->get_error_code(),
	'oferta bez numeru tez nie przechodzi'
);
sv_ok(
	false !== mb_stripos( $sv_msg_numer, 'numer' ),
	'komunikat mowi o numerze',
	'komunikat=' . $sv_msg_numer
);

$sv_id_bez_obu = sv_szkic( '', '', 2 );
$sv_bez_obu    = MP_Offer_Builder_Approval::approve( $sv_id_bez_obu, $sv_handlowiec );
$sv_msg_oba    = is_wp_error( $sv_bez_obu ) ? (string) $sv_bez_obu->get_error_message() : '';

sv_ok(
	false !== mb_stripos( $sv_msg_oba, 'numer' ) && false !== mb_stripos( $sv_msg_oba, 'PDF' ),
	'przy braku obu rzeczy komunikat wymienia obie',
	'komunikat=' . $sv_msg_oba
);
