<?php
/**
 * Dwa ustalenia audytu z wtyczki 2 — oba tej samej klasy: strażnik istnieje,
 * ale patrzy nie tam, gdzie trzeba.
 *
 * Uruchamianie: wp eval-file tests/naprawy/wariant-wycofanego-produktu.php
 *
 * A. WARIANT WYCOFANEGO PRODUKTU. Agent 2.1 odrzuca pozycję warunkiem
 *    `'publish' !== $product->get_status()`. Dla pozycji wskazującej WARIANT
 *    jest to status samego wariantu — a WooCommerce przy wycofaniu produktu
 *    zmiennego z katalogu zmienia status TYLKO rodzica; warianty zostają
 *    osobnymi wpisami w stanie `publish`. Zmierzone wprost, nie założone:
 *    po `wp_update_post( rodzic → draft )` wariant nadal ma `publish`, a jego
 *    `get_parent_data()['status']` mówi `draft`.
 *
 *    Skutek: handlowiec wstawia do oferty wariant produktu, którego nie ma
 *    w opublikowanym katalogu, i oferta idzie do klienta. Opis agenta deklaruje
 *    „filtruje po statusie publikacji" — i robi to o jeden poziom za płytko.
 *
 * B. OBRONA W GŁĄB WYŁĄCZONA DLA ŻĄDANIA BEZ SESJI. Kontrola właściciela oferty
 *    (warstwa przeciw IDOR) miała warunek `$biezacy > 0`, więc pomijała się dla
 *    KAŻDEGO żądania bez zalogowanego użytkownika — nie tylko dla crona i WP-CLI,
 *    ale i dla zwykłego żądania HTTP od gościa. Czyli dokładnie w sytuacji,
 *    dla której ta warstwa powstała: „gdyby kontrola w Dziale 1 zawiodła".
 *
 *    UWAGA NA POPRZEDNIĄ, BŁĘDNĄ PRÓBĘ. Wcześniejsza wersja tej naprawy pytała
 *    WYŁĄCZNIE o tryb uruchomienia (`wp_doing_cron() || WP_CLI`). Pod WP-CLI
 *    stała `WP_CLI` jest zdefiniowana ZAWSZE, także gdy ustawiono bieżącego
 *    użytkownika — obrona znikała więc również dla obcego użytkownika
 *    ZALOGOWANEGO. Sekcja C pilnuje, żeby ta pomyłka nie wróciła.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_wr'] = array(
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
function wr_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_wr']['pass'];
		$GLOBALS['mp_wr']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_wr']['fail'];
	$GLOBALS['mp_wr']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function wr_koniec() {
	if ( empty( $GLOBALS['mp_wr']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_wr'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_wr']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'wr_koniec' );

/* ==================================================================== A */

$GLOBALS['mp_wr']['lines'][] = '=== A. wariant wycofanego produktu nie wchodzi do oferty ===';

if ( ! class_exists( 'WC_Product_Variable' ) ) {
	wr_ok( false, 'WooCommerce dostepne w srodowisku testowym' );
	return;
}

$wr_rodzic = new WC_Product_Variable();
$wr_rodzic->set_name( 'Produkt zmienny (test wycofania)' );
$wr_rodzic->set_status( 'publish' );
$wr_rodzic_id = (int) $wr_rodzic->save();

$wr_wariant = new WC_Product_Variation();
$wr_wariant->set_parent_id( $wr_rodzic_id );
$wr_wariant->set_regular_price( '100' );
$wr_wariant->set_status( 'publish' );
$wr_wariant_id = (int) $wr_wariant->save();

$wr_prosty = new WC_Product_Simple();
$wr_prosty->set_name( 'Produkt prosty (kontr-asercja)' );
$wr_prosty->set_regular_price( '50' );
$wr_prosty->set_status( 'publish' );
$wr_prosty_id = (int) $wr_prosty->save();

wr_ok(
	$wr_rodzic_id > 0 && $wr_wariant_id > 0 && $wr_prosty_id > 0,
	'A1: produkt zmienny, wariant i produkt prosty zalozone',
	'rodzic=' . $wr_rodzic_id . ' wariant=' . $wr_wariant_id . ' prosty=' . $wr_prosty_id
);

/**
 * Uruchamia Agenta 2.1 na jednej pozycji i zwraca listę błędów.
 *
 * @param int $id Identyfikator produktu albo wariantu.
 * @return array
 */
function wr_agent_21( $id ) {
	$agent = new MP_OB_D2_Agent_Products();
	$wynik = $agent->run(
		new MP_OB_Context(
			array(
				'items' => array(
					array(
						'product_id' => $id,
						'qty'        => 1,
					),
				),
			)
		)
	);

	$dane = (array) $wynik->get_data();

	return array(
		'ok'     => $wynik->is_ok(),
		'errors' => (array) ( $dane['errors'] ?? array() ),
	);
}

$wr_przed = wr_agent_21( $wr_wariant_id );

wr_ok(
	$wr_przed['ok'],
	'A2: wariant opublikowanego produktu przechodzi (stan wyjsciowy)',
	'bledy=' . wp_json_encode( $wr_przed['errors'] )
);

// Wycofujemy produkt z katalogu — WooCommerce NIE zmienia przy tym statusu wariantow.
wp_update_post(
	array(
		'ID'          => $wr_rodzic_id,
		'post_status' => 'draft',
	)
);
wc_delete_product_transients( $wr_rodzic_id );
clean_post_cache( $wr_rodzic_id );
clean_post_cache( $wr_wariant_id );

$wr_obiekt = wc_get_product( $wr_wariant_id );
$wr_dane_r = $wr_obiekt instanceof WC_Product_Variation ? (array) $wr_obiekt->get_parent_data() : array();

wr_ok(
	'publish' === $wr_obiekt->get_status() && 'draft' === (string) get_post_status( $wr_rodzic_id ),
	'A3: POMIAR — wariant nadal `publish`, rodzic juz `draft`',
	'wariant=' . $wr_obiekt->get_status() . ' rodzic=' . get_post_status( $wr_rodzic_id )
);
wr_ok(
	'draft' === (string) ( $wr_dane_r['status'] ?? '' ),
	'A4: status rodzica jest dostepny przez get_parent_data()',
	'parent_data[status]=' . ( $wr_dane_r['status'] ?? '(brak)' )
);

$wr_po = wr_agent_21( $wr_wariant_id );

wr_ok(
	! $wr_po['ok'] && ! empty( $wr_po['errors'] ),
	'A5: wariant WYCOFANEGO produktu jest odrzucany przez Agenta 2.1',
	'ok=' . var_export( $wr_po['ok'], true ) . ' bledy=' . wp_json_encode( $wr_po['errors'] )
);

$wr_komunikat = '';
foreach ( $wr_po['errors'] as $wr_e ) {
	$wr_komunikat .= is_array( $wr_e ) ? (string) ( $wr_e['message'] ?? '' ) : (string) $wr_e;
}

wr_ok(
	false !== mb_stripos( $wr_komunikat, 'opublikowan' ),
	'A6: komunikat mowi o braku publikacji',
	'komunikat=' . $wr_komunikat
);

// Przywracamy rodzica — kontr-asercja, ze naprawa nie odrzuca wszystkiego.
wp_update_post(
	array(
		'ID'          => $wr_rodzic_id,
		'post_status' => 'publish',
	)
);
wc_delete_product_transients( $wr_rodzic_id );
clean_post_cache( $wr_rodzic_id );
clean_post_cache( $wr_wariant_id );

wr_ok(
	wr_agent_21( $wr_wariant_id )['ok'],
	'A7: KONTR-ASERCJA — wariant znow opublikowanego produktu przechodzi'
);
wr_ok(
	wr_agent_21( $wr_prosty_id )['ok'],
	'A8: KONTR-ASERCJA — produkt prosty bez rodzica przechodzi jak dotad'
);

wp_delete_post( $wr_wariant_id, true );
wp_delete_post( $wr_rodzic_id, true );
wp_delete_post( $wr_prosty_id, true );

/* ==================================================================== B */

$GLOBALS['mp_wr']['lines'][] = '';
$GLOBALS['mp_wr']['lines'][] = '=== B. obrona w glab dziala takze bez sesji ===';

/**
 * Uruchamia Agenta 10.1 dla oferty o wskazanym właścicielu.
 *
 * @param int $wlasciciel Właściciel istniejącej oferty (`created_by`).
 * @param int $biezacy    Użytkownik ustawiony jako bieżący (0 = brak sesji).
 * @return MP_OB_Result
 */
function wr_agent_101( $wlasciciel, $biezacy ) {
	wp_set_current_user( (int) $biezacy );

	$agent = new MP_OB_D10_Agent_Plan();

	return $agent->run(
		new MP_OB_Context(
			array(
				'offer_id'       => 41,
				'offer_number'   => 'OF/2026/000777',
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
				'request_id'     => 'req-wr-1',
				'numbering'      => array(
					'existing_offer_number' => 'OF/2026/000777',
					'existing_version'      => 1,
					'existing_created_by'   => $wlasciciel > 0 ? (int) $wlasciciel : null,
					'existing_lock_version' => 3,
				),
				'numbering_mode' => 'keep_number',
			)
		)
	);
}

/*
 * Reguła sprawdzana WPROST, nie przez uruchomienie agenta. Testy chodzą pod
 * WP-CLI, gdzie stała `WP_CLI` jest zdefiniowana zawsze — przebieg z definicji
 * wygląda tam na bezsesyjny i przypadku „żądanie HTTP bez zalogowanego
 * użytkownika" nie da się odtworzyć uruchomieniem. Dlatego decyzja przyjmuje
 * tryb jako argument: `MP_OB_D10_Agent_Plan::obcy_podmiot()`.
 */
wr_ok(
	true === MP_OB_D10_Agent_Plan::obcy_podmiot( 5, 0, false ),
	'B1: zadanie HTTP BEZ SESJI jest podmiotem obcym — cudza oferta broniona',
	'wynik=' . var_export( MP_OB_D10_Agent_Plan::obcy_podmiot( 5, 0, false ), true )
);
wr_ok(
	false === MP_OB_D10_Agent_Plan::obcy_podmiot( 5, 0, true ),
	'B2: cron i WP-CLI nadal przechodza — tam brak uzytkownika jest normalny'
);
wr_ok(
	true === MP_OB_D10_Agent_Plan::obcy_podmiot( 5, 9, true )
	&& true === MP_OB_D10_Agent_Plan::obcy_podmiot( 5, 9, false ),
	'B3: obcy ZALOGOWANY jest obcy w OBU trybach — tryb go nie uniewinnia'
);
wr_ok(
	false === MP_OB_D10_Agent_Plan::obcy_podmiot( 5, 5, false )
	&& false === MP_OB_D10_Agent_Plan::obcy_podmiot( 5, 5, true ),
	'B4: wlasciciel nie jest obcy w zadnym trybie'
);

/* ==================================================================== C */

$GLOBALS['mp_wr']['lines'][] = '';
$GLOBALS['mp_wr']['lines'][] = '=== C. kontr-asercje: poprzednia bledna proba nie wraca ===';

$wr_admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );

$wr_obcy = wp_insert_user(
	array(
		'user_login' => 'wr-obcy-' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'user_email' => 'wr-obcy-' . wp_generate_password( 6, false ) . '@example.test',
		'role'       => 'subscriber',
	)
);

if ( ! is_wp_error( $wr_obcy ) ) {
	$wr_zalogowany_obcy = wr_agent_101( 5, (int) $wr_obcy );

	wr_ok(
		! $wr_zalogowany_obcy->is_ok() && 'not_offer_owner' === $wr_zalogowany_obcy->get_code(),
		'C1: obcy ZALOGOWANY uzytkownik nadal odbija sie o straznika (mimo WP_CLI)',
		'kod=' . $wr_zalogowany_obcy->get_code()
	);

	wp_delete_user( (int) $wr_obcy );
}

if ( $wr_admin > 0 ) {
	wr_ok(
		wr_agent_101( 5, $wr_admin )->is_ok(),
		'C2: KONTR-ASERCJA — administrator nadal moze zapisac cudza oferte'
	);
	wr_ok(
		wr_agent_101( $wr_admin, $wr_admin )->is_ok(),
		'C3: KONTR-ASERCJA — wlasciciel nadal zapisuje swoja oferte'
	);
}

wr_ok(
	wr_agent_101( 0, 0 )->is_ok(),
	'C4: KONTR-ASERCJA — oferta BEZ wlasciciela nie ma czego bronic'
);

wp_set_current_user( 0 );
