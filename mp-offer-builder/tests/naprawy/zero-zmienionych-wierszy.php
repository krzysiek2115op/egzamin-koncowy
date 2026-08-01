<?php
/**
 * P2-G12 — „nic sie nie zmienilo" przy ofercie, ktora NIE zostala zatwierdzona.
 *
 * Uruchamianie: wp eval-file tests/naprawy/zero-zmienionych-wierszy.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G12  Zero zmienionych wierszy raportowane bezwarunkowo jako already_approved
 *
 * Zatwierdzenie idzie przejsciem warunkowym: `UPDATE ... WHERE id = %d AND
 * status = 'draft'`. Zero zmienionych wierszy oznacza jednak KAZDY powod
 * niedopasowania, a nie jeden: ktos zatwierdzil pierwszy, ale rownie dobrze
 * Dzial 10 zapisal w tym czasie inny status albo wiersz zniknal.
 *
 * Kod zwracal bezwarunkowo `already_approved`, a panel pokazuje ten kod jako
 * informacje na niebiesko: „Ta oferta byla juz zatwierdzona — nic sie nie
 * zmienilo". Pracownik czytal, ze sprawa jest zalatwiona, i nie ponawial akcji.
 * Tymczasem oferta NIE przeszla w `approved`, zdarzenie `mp_offer_approved`
 * nie wyszlo i nikt jej nie wyslal. Komunikat mowil dokladnie odwrotnie niz
 * stan bazy — a to ten sam blad, ktory naprawiono juz raz przy `db_error`.
 *
 * Wyscig odtwarzamy deterministycznie: filtr `query` wpdb podmienia status
 * wiersza TUZ PRZED wykonaniem UPDATE-a zatwierdzenia. Dokladnie to robi
 * rownolegly proces, tylko bez losowosci.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_zz'] = array(
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
function zz_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_zz']['pass'];
		$GLOBALS['mp_zz']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_zz']['fail'];
	$GLOBALS['mp_zz']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

$offers_t   = MP_Offer_Builder_DB::offers_table();
$seria      = (int) substr( (string) time(), -6 );
$handlowiec = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$katalog    = MP_Offer_Builder_Storage::private_dir();
$plik       = $katalog . '/mp-test-zz-' . $seria . '.pdf';
$posprzatac = array();

file_put_contents( $plik, '%PDF-1.4 test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

/**
 * Zaklada szkic gotowy do zatwierdzenia.
 *
 * @param string $numer Numer oferty.
 * @param string $plik  Sciezka do dokumentu.
 * @param int    $kto   Wlasciciel.
 * @return int
 */
function zz_szkic( $numer, $plik, $kto ) {
	global $wpdb;

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		MP_Offer_Builder_DB::offers_table(),
		array(
			'lead_id'      => 0,
			'offer_number' => $numer,
			'version'      => 1,
			'status'       => MP_Offer_Builder_DB::STATUS_DRAFT,
			'client_email' => 'zz@example.test',
			'client_name'  => 'Firma Testowa ZZ',
			'pdf_path'     => $plik,
			'lock_version' => 1,
			'created_by'   => (int) $kto,
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		)
	);

	return (int) $wpdb->insert_id;
}

$GLOBALS['mp_zz']['lines'][] = '=== A. rownolegly proces zmienil status na inny niz approved ===';

$id_wyscig    = zz_szkic( 'OF/TEST/' . $seria . '/ZZ1', $plik, $handlowiec );
$posprzatac[] = $id_wyscig;

zz_ok( $id_wyscig > 0, 'szkic testowy zalozony', $wpdb->last_error );

/*
 * Podmiana statusu TUZ PRZED UPDATE-em zatwierdzenia. Filtr `query` widzi kazde
 * zapytanie; rozpoznajemy to jedno po tabeli, identyfikatorze i warunku
 * `status = 'draft'`, i tylko raz.
 */
$GLOBALS['mp_zz_podmien'] = $id_wyscig;
$GLOBALS['mp_zz_zrobione'] = false;

$podmiana = function ( $zapytanie ) use ( $offers_t ) {
	if (
		! $GLOBALS['mp_zz_zrobione']
		&& false !== strpos( $zapytanie, 'UPDATE' )
		&& false !== strpos( $zapytanie, $offers_t )
		&& false !== strpos( $zapytanie, "'draft'" )
		&& false !== strpos( $zapytanie, (string) $GLOBALS['mp_zz_podmien'] )
	) {
		$GLOBALS['mp_zz_zrobione'] = true;

		// Bezposrednio, z pominieciem $wpdb->update() — inaczej filtr zapetlilby sie.
		$GLOBALS['wpdb']->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$GLOBALS['wpdb']->prepare( "UPDATE {$offers_t} SET status = %s WHERE id = %d", 'archived', (int) $GLOBALS['mp_zz_podmien'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	return $zapytanie;
};

add_filter( 'query', $podmiana );
$wynik_wyscig = MP_Offer_Builder_Approval::approve( $id_wyscig, $handlowiec );
remove_filter( 'query', $podmiana );

$status_po = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$offers_t} WHERE id = %d", $id_wyscig ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

zz_ok(
	$GLOBALS['mp_zz_zrobione'],
	'warunek scenariusza: status podmieniony przed UPDATE-em'
);
zz_ok(
	'archived' === $status_po,
	'oferta NIE jest zatwierdzona — to jest stan faktyczny bazy',
	'status=' . $status_po
);
zz_ok(
	is_wp_error( $wynik_wyscig ),
	'zatwierdzenie zwraca blad',
	'wynik=' . ( is_wp_error( $wynik_wyscig ) ? $wynik_wyscig->get_error_code() : var_export( $wynik_wyscig, true ) )
);
zz_ok(
	is_wp_error( $wynik_wyscig ) && 'already_approved' !== $wynik_wyscig->get_error_code(),
	'komunikat NIE twierdzi, ze oferta byla juz zatwierdzona',
	'kod=' . ( is_wp_error( $wynik_wyscig ) ? $wynik_wyscig->get_error_code() : '-' )
);
zz_ok(
	is_wp_error( $wynik_wyscig ) && 'wrong_status' === $wynik_wyscig->get_error_code(),
	'komunikat nazywa rzecz po imieniu: zly status',
	'kod=' . ( is_wp_error( $wynik_wyscig ) ? $wynik_wyscig->get_error_code() : '-' )
);

$GLOBALS['mp_zz']['lines'][] = '';
$GLOBALS['mp_zz']['lines'][] = '=== B. wiersz zniknal miedzy odczytem a zapisem ===';

$id_znika     = zz_szkic( 'OF/TEST/' . $seria . '/ZZ2', $plik, $handlowiec );
$posprzatac[] = $id_znika;

$GLOBALS['mp_zz_podmien']  = $id_znika;
$GLOBALS['mp_zz_zrobione'] = false;

$kasowanie = function ( $zapytanie ) use ( $offers_t ) {
	if (
		! $GLOBALS['mp_zz_zrobione']
		&& false !== strpos( $zapytanie, 'UPDATE' )
		&& false !== strpos( $zapytanie, $offers_t )
		&& false !== strpos( $zapytanie, "'draft'" )
		&& false !== strpos( $zapytanie, (string) $GLOBALS['mp_zz_podmien'] )
	) {
		$GLOBALS['mp_zz_zrobione'] = true;

		$GLOBALS['wpdb']->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$GLOBALS['wpdb']->prepare( "DELETE FROM {$offers_t} WHERE id = %d", (int) $GLOBALS['mp_zz_podmien'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	return $zapytanie;
};

add_filter( 'query', $kasowanie );
$wynik_znika = MP_Offer_Builder_Approval::approve( $id_znika, $handlowiec );
remove_filter( 'query', $kasowanie );

zz_ok(
	is_wp_error( $wynik_znika ) && 'offer_not_found' === $wynik_znika->get_error_code(),
	'usuniety wiersz daje offer_not_found, nie „juz zatwierdzona"',
	'kod=' . ( is_wp_error( $wynik_znika ) ? $wynik_znika->get_error_code() : var_export( $wynik_znika, true ) )
);

$GLOBALS['mp_zz']['lines'][] = '';
$GLOBALS['mp_zz']['lines'][] = '=== C. KONTR-ASERCJE: prawdziwa powtorka nadal jest powtorka ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na zamianie already_approved na blad
 * w KAZDYM przypadku. Podwojne klikniecie to normalna sytuacja i musi zostac
 * lagodna informacja, a nie czerwonym bledem — inaczej pracownik zaczyna
 * zglaszac awarie, ktorych nie ma.
 */
$id_powtorka  = zz_szkic( 'OF/TEST/' . $seria . '/ZZ3', $plik, $handlowiec );
$posprzatac[] = $id_powtorka;

$pierwsze = MP_Offer_Builder_Approval::approve( $id_powtorka, $handlowiec );
$drugie   = MP_Offer_Builder_Approval::approve( $id_powtorka, $handlowiec );

zz_ok( true === $pierwsze, 'pierwsze zatwierdzenie przechodzi', 'wynik=' . ( is_wp_error( $pierwsze ) ? $pierwsze->get_error_code() : var_export( $pierwsze, true ) ) );
zz_ok(
	is_wp_error( $drugie ) && 'already_approved' === $drugie->get_error_code(),
	'drugie klikniecie nadal daje already_approved',
	'kod=' . ( is_wp_error( $drugie ) ? $drugie->get_error_code() : var_export( $drugie, true ) )
);

foreach ( $posprzatac as $id ) {
	$wpdb->delete( $offers_t, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( MP_Offer_Builder_DB::activity_log_table(), array( 'offer_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if ( file_exists( $plik ) ) {
	wp_delete_file( $plik );
}

echo implode( "\n", $GLOBALS['mp_zz']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_zz']['pass'], $GLOBALS['mp_zz']['fail'] );
echo ( 0 === $GLOBALS['mp_zz']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
