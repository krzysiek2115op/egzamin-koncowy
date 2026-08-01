<?php
/**
 * U-1 — jeden lead, dwoch roznych handlowcow.
 *
 * Uruchamianie: wp eval-file tests/naprawy/handlowiec-jeden-wybor.php
 *
 * Zlecenie mowi trzy razy, niezaleznie od siebie, to samo:
 *   - cel biznesowy: „skierowac zadanie do ODPOWIEDNIEGO handlowca";
 *   - tabela zakresu: wtyczka 1 robi „przypisanie kraju i segmentu",
 *     przypisanie HANDLOWCA nalezy do wtyczki 3;
 *   - BD-1: „logiczne powiazanie uzytkownika z krajem, zespolem, jezykiem
 *     i zakresem obslugi".
 *
 * Kod robil to inaczej. Wtyczka 1 (Dzial 7) wybierala handlowca sama:
 *
 *     $index = abs( crc32( (string) $nip ) ) % count( $users );
 *
 * Hasz numeru NIP. Bez kraju, bez jezyka, bez zespolu, bez obciazenia — czyli
 * bez niczego, co decyduje o tym, czy handlowiec jest ODPOWIEDNI. Wynik ladowal
 * w `wp_mp_leads.salesman_id` (BD-3) i w kopercie zdarzenia `mp_lead_created`,
 * z ktorej wtyczka 2 brala wlasciciela szkicu oferty.
 *
 * Rownolegle wtyczka 3 dobierala wlasciciela procesu naprawde: Dzial 4 patrzy
 * na kraj, jezyk, gotowosc i obciazenie (MP_SW_D4_Assigner::decide). Zapisywala
 * go w `wp_mp_sw_flow.assigned_user_id` (BD-1).
 *
 * Skutek: JEDEN lead miewal DWOCH roznych handlowcow naraz. Niemiecka firma
 * trafiala do polskiego handlowca w BD-3 i do niemieckiego w BD-1 — a oba
 * zapisy powstawaly z tego samego zgloszenia, w tym samym zadaniu. Zaden test
 * tego nie widzial, bo kazda wtyczka z osobna zachowywala sie spojnie.
 *
 * NAPRAWA: wtyczka 1 przestaje wybierac i zaczyna PYTAC. Filtr
 * `mp_lead_assign_salesman` jest pytaniem, wtyczka 3 — jedyna, ktora zna
 * odpowiedz — na nie odpowiada. Gdy wtyczki 3 nie ma, odpowiedzi nie ma i
 * kolumna zostaje pusta; wymyslanie handlowca haszem bylo gorsze niz przyznanie
 * sie, ze nie ma czym go wybrac. Dodatkowo zdarzenie `mp_sw_flow_updated` niesie
 * `assigned_user_id`, a wtyczka 1 sie na nie wpina — dzieki temu KAZDA pozniejsza
 * zmiana wlasciciela procesu (rotacja, przepisanie przez managera) dogania BD-3.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['hjw'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $info    Kontekst przy porazce.
 * @return void
 */
function hjw_ok( $warunek, $opis, $info = '' ) {
	if ( $warunek ) {
		++$GLOBALS['hjw']['pass'];
		$GLOBALS['hjw']['lines'][] = '[PASS] ' . $opis;
		return;
	}

	++$GLOBALS['hjw']['fail'];
	$GLOBALS['hjw']['lines'][] = '[FAIL] ' . $opis . ( '' !== $info ? ' -- ' . $info : '' );
}

/**
 * Zaklada handlowca z metadanymi, ktorych uzywa Dzial 4 wtyczki 3.
 *
 * @param string $login   Login (decyduje o kolejnosci w get_users, a wiec o starym haszu).
 * @param string $country Kraj obslugi (ISO-2).
 * @param string $langs   Jezyki po przecinku.
 * @return int
 */
function hjw_user( $login, $country, $langs ) {
	$existing = get_user_by( 'login', $login );

	if ( $existing instanceof WP_User ) {
		wp_delete_user( (int) $existing->ID );
	}

	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 16 ),
			'user_email' => $login . '@example.test',
			'role'       => MP_SW_Roles::ROLE_SALESMAN,
		)
	);

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	$id   = (int) $id;
	$user = new WP_User( $id );
	$user->set_role( MP_SW_Roles::ROLE_SALESMAN );

	update_user_meta( $id, MP_SW_D2_Reader::META_COUNTRY, $country );
	update_user_meta( $id, MP_SW_D2_Reader::META_LANGS, $langs );
	update_user_meta( $id, MP_SW_D2_Reader::META_TEAM, 'DE' === $country ? 'eksport' : 'krajowy' );
	update_user_meta( $id, MP_SW_D2_Reader::META_ACTIVE, 1 );
	clean_user_cache( $id );

	return $id;
}

/**
 * Puszcza PRAWDZIWY pipeline wtyczki 1 (11 dzialow) i zwraca id leada.
 *
 * @param string $nip     Numer VAT (bez prefiksu kraju).
 * @param string $country Kraj (ISO-2).
 * @return int
 */
function hjw_lead( $nip, $country ) {
	global $wpdb;

	$ctx = new MP_Context(
		array(
			'company_name'      => 'Muster GmbH ' . substr( $nip, 0, 4 ),
			'nip'               => $nip,
			'email'             => 'hjw-' . substr( $nip, 0, 6 ) . '@example.test',
			'phone'             => '+49 30 111222',
			'country'           => $country,
			'segment'           => 'roboty',
			'message'           => 'Prosze o oferte.',
			'consent_rodo'      => true,
			'consent_marketing' => true,
			'mp_nonce'          => wp_create_nonce( 'mp_lead_intake' ),
		)
	);

	MP_Pipeline_Factory::make()->run( $ctx );

	$leads = MP_Lead_Intake_DB::leads_table();

	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$leads} WHERE email = %s ORDER BY id DESC LIMIT 1", 'hjw-' . substr( $nip, 0, 6 ) . '@example.test' ) ); // phpcs:ignore WordPress.DB
}

// --- Sanity: bez kompletu trzech wtyczek ten test nie ma o czym mowic ---
$komplet = class_exists( 'MP_Pipeline_Factory' ) && class_exists( 'MP_Offer_Builder_DB' ) && class_exists( 'MP_SW_D4_Assigner' );
hjw_ok( $komplet, '0: wszystkie trzy wtyczki zaladowane' );

if ( ! $komplet ) {
	echo implode( "\n", $GLOBALS['hjw']['lines'] ) . "\n";
	echo "VERDICT_HAS_FAILURES\n";
	return;
}

$leads_t = MP_Lead_Intake_DB::leads_table();
$flow_t  = MP_Sales_Workflow_DB::flow_table();
$ob_t    = MP_Offer_Builder_DB::offers_table();

/*
 * Sprzatanie po poprzednim przebiegu. Wtyczka 1 pilnuje unikalnosci firmy po
 * (kraj, NIP): zostawiony lead sprawia, ze pipeline ODMAWIA, a test czyta stary
 * wiersz i ocenia nie ten przebieg, co trzeba. Pierwsza wersja tego testu tak
 * wlasnie sklamala — pokazala wynik sprzed naprawy jako wynik po naprawie.
 */
$stare = $wpdb->get_col( "SELECT id FROM {$leads_t} WHERE email LIKE 'hjw-%@example.test'" ); // phpcs:ignore WordPress.DB

foreach ( array_map( 'intval', (array) $stare ) as $stary_lead ) {
	$wpdb->delete( $flow_t, array( 'lead_id' => $stary_lead ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	$wpdb->delete( $ob_t, array( 'lead_id' => $stary_lead ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	$wpdb->delete( $leads_t, array( 'id' => $stary_lead ), array( '%d' ) ); // phpcs:ignore WordPress.DB
}

// Czysta pula handlowcow: kazdy zostawiony z innego testu przesunalby indeks hasza.
foreach ( get_users( array( 'role' => MP_SW_Roles::ROLE_SALESMAN, 'fields' => 'ID' ) ) as $stary ) {
	wp_delete_user( (int) $stary );
}

/*
 * Loginy dobrane celowo. `get_users()` sortuje domyslnie po loginie, wiec stary
 * hasz indeksowal wlasnie ta liste: hjw_de = 0, hjw_pl_a = 1, hjw_pl_b = 2.
 * Jeden handlowiec od Niemiec, dwoch od Polski — przy niemieckim leadzie
 * Dzial 4 wtyczki 3 MUSI wskazac tego pierwszego, a hasz trafi w niego tylko
 * przez przypadek (1 na 3).
 */
$sal_de   = hjw_user( 'hjw_de', 'DE', 'de,en' );
$sal_pl_a = hjw_user( 'hjw_pl_a', 'PL', 'pl' );
$sal_pl_b = hjw_user( 'hjw_pl_b', 'PL', 'pl' );

hjw_ok( $sal_de > 0 && $sal_pl_a > 0 && $sal_pl_b > 0, '0: trzej handlowcy zalozeni (1x DE, 2x PL)' );

MP_SW_Hooks::register();
MP_SW_Cron::register();

/* ==================================================================== A */
// Niemiecki lead. Wtyczka 3 wskaze handlowca od Niemiec — pytanie brzmi, czy
// BD-3 dostanie TEGO SAMEGO czlowieka.

$nip_a   = '123456789';
$lead_a  = hjw_lead( $nip_a, 'DE' );
$row_a   = $lead_a > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_t} WHERE id = %d", $lead_a ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
$flow_a  = $lead_a > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$flow_t} WHERE lead_id = %d", $lead_a ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
$draft_a = $lead_a > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ob_t} WHERE lead_id = %d ORDER BY id DESC LIMIT 1", $lead_a ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB

hjw_ok( $lead_a > 0 && is_array( $row_a ), 'A0: lead zapisany w BD-3', 'lead_id=' . $lead_a );
hjw_ok( is_array( $flow_a ), 'A0: proces zalozony w BD-1' );

$w_bd3 = is_array( $row_a ) ? (int) $row_a['salesman_id'] : 0;
$w_bd1 = is_array( $flow_a ) ? (int) $flow_a['assigned_user_id'] : 0;

// Diagnostyka: kogo wskazalby stary hasz na tej samej puli.
$pula   = get_users( array( 'role' => MP_SW_Roles::ROLE_SALESMAN, 'fields' => 'ID' ) );
$hasz   = empty( $pula ) ? 0 : (int) $pula[ abs( crc32( (string) $row_a['nip'] ) ) % count( $pula ) ];
$kto    = static function ( $id ) use ( $sal_de, $sal_pl_a, $sal_pl_b ) {
	if ( $id === $sal_de ) {
		return 'DE';
	}
	if ( $id === $sal_pl_a ) {
		return 'PL-a';
	}
	if ( $id === $sal_pl_b ) {
		return 'PL-b';
	}

	return 0 === (int) $id ? 'brak' : ( 'obcy#' . $id );
};

$GLOBALS['hjw']['lines'][] = sprintf(
	'[INFO] nip w bazie=%s · stary hasz wskazywal %s · BD-3 ma %s · BD-1 ma %s',
	is_array( $row_a ) ? $row_a['nip'] : '-',
	$kto( $hasz ),
	$kto( $w_bd3 ),
	$kto( $w_bd1 )
);

hjw_ok(
	$w_bd1 === $sal_de,
	'A1: wtyczka 3 przypisala handlowca OD NIEMIEC (dobor po kraju, nie po haszu)',
	'jest=' . $kto( $w_bd1 )
);

hjw_ok(
	$w_bd3 === $sal_de,
	'A2: BD-3 zapisala tego samego handlowca, ktorego wybrala wtyczka 3',
	'BD-3=' . $kto( $w_bd3 ) . ', oczekiwano DE'
);

hjw_ok(
	$w_bd3 === $w_bd1 && $w_bd3 > 0,
	'A3: NIEZMIENNIK — jeden lead ma jednego handlowca w obu bazach',
	'BD-3=' . $kto( $w_bd3 ) . ' vs BD-1=' . $kto( $w_bd1 )
);

hjw_ok(
	is_array( $draft_a ) && (int) $draft_a['created_by'] === $sal_de,
	'A4: szkic oferty (BD-2) nalezy do tego samego handlowca',
	'created_by=' . ( is_array( $draft_a ) ? $kto( (int) $draft_a['created_by'] ) : 'brak szkicu' )
);

// Niezmiennik przeniesiony z harnessu LP.1 (26b): znacznik przypisania idzie w GMT,
// jak kazda inna kolumna datetime tej tabeli. Po naprawie zapisuje go inny kod niz
// wczesniej, wiec regula musi isc razem z nim — inaczej zostalaby bez straznika.
$assigned_at = is_array( $row_a ) ? (string) $row_a['salesman_assigned_at'] : '';
$drift       = '' !== $assigned_at ? abs( strtotime( $assigned_at . ' UTC' ) - time() ) : PHP_INT_MAX;

hjw_ok(
	'' !== $assigned_at && $drift <= 300,
	'A5: salesman_assigned_at zapisany w GMT, spojnie z reszta wiersza',
	'wartosc=' . ( '' !== $assigned_at ? $assigned_at : 'null' ) . ', odchylenie=' . ( PHP_INT_MAX === $drift ? '-' : $drift . 's' )
);

/* ==================================================================== B */
// KONTR-ASERCJA. Bez wtyczki 3 wtyczka 1 nie ma czym wybrac handlowca — i nie
// wolno jej go wymyslic. Pusta kolumna jest informacja prawdziwa, hasz nie byl.

/*
 * „Brak wtyczki 3" to brak OBU jej wejść: filtra, którym odpowiada na pytanie,
 * i nasłuchu `mp_lead_created`, z którego bierze się proces (a z procesu —
 * zdarzenie dociągające właściciela do BD-3). Zdjęcie samego filtra byłoby
 * symulacją nieszczelną: proces i tak by powstał i i tak dosłałby handlowca.
 */
remove_all_filters( 'mp_lead_assign_salesman' );
remove_all_actions( 'mp_lead_created' );

$nip_b  = '811111112';
$lead_b = hjw_lead( $nip_b, 'DE' );
$row_b  = $lead_b > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_t} WHERE id = %d", $lead_b ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB

hjw_ok( $lead_b > 0, 'B0: drugi lead zapisany', 'lead_id=' . $lead_b );

hjw_ok(
	is_array( $row_b ) && null === $row_b['salesman_id'],
	'B1: KONTR-ASERCJA — bez wtyczki 3 wtyczka 1 NIE wymysla handlowca',
	'salesman_id=' . ( is_array( $row_b ) ? var_export( $row_b['salesman_id'], true ) : '-' )
);

hjw_ok(
	is_array( $row_b ) && null === $row_b['salesman_assigned_at'],
	'B2: KONTR-ASERCJA — bez przypisania nie ma tez znacznika czasu przypisania',
	'salesman_assigned_at=' . ( is_array( $row_b ) ? var_export( $row_b['salesman_assigned_at'], true ) : '-' )
);

// Wtyczka 3 wraca — dalsze niezmienniki zakladaja komplet.
MP_SW_Hooks::register();

/* ==================================================================== C */
// Wlasciciel procesu potrafi sie zmienic PO zalozeniu leada: rotacja w Dziale 4,
// przepisanie przez managera. BD-3 ma za nim nadazyc, inaczej rozjazd wraca
// tylnymi drzwiami — tym razem z opoznieniem.

if ( $lead_a > 0 ) {
	// Znacznik przypisania ma rozdzielczość sekundy, a asercja C2 pyta o to, czy
	// się ODŚWIEŻYŁ. Bez tej sekundy test bywałby zielony przez zbieg okoliczności.
	sleep( 1 );

	do_action(
		'mp_sw_flow_updated',
		array(
			'event_id'         => 'hjw-test-' . $lead_a,
			'lead_id'          => $lead_a,
			'flow_id'          => is_array( $flow_a ) ? (int) $flow_a['id'] : 0,
			'status'           => 'new',
			'assigned_user_id' => $sal_pl_a,
			'trace_id'         => 'hjw-test',
		)
	);

	$po = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_t} WHERE id = %d", $lead_a ), ARRAY_A ); // phpcs:ignore WordPress.DB

	hjw_ok(
		is_array( $po ) && (int) $po['salesman_id'] === $sal_pl_a,
		'C1: przepisanie procesu na innego handlowca dogania BD-3',
		'BD-3=' . $kto( is_array( $po ) ? (int) $po['salesman_id'] : 0 )
	);

	hjw_ok(
		is_array( $po ) && (string) $po['salesman_assigned_at'] !== $assigned_at,
		'C2: przepisanie odswieza znacznik czasu przypisania',
		'przed=' . $assigned_at . ', po=' . ( is_array( $po ) ? (string) $po['salesman_assigned_at'] : '-' )
	);

	// KONTR-ASERCJA: zdarzenie BEZ wlasciciela (cron, podglad) niczego nie kasuje.
	do_action(
		'mp_sw_flow_updated',
		array(
			'event_id'         => 'hjw-test-pusty-' . $lead_a,
			'lead_id'          => $lead_a,
			'flow_id'          => is_array( $flow_a ) ? (int) $flow_a['id'] : 0,
			'status'           => 'new',
			'assigned_user_id' => 0,
			'trace_id'         => 'hjw-test',
		)
	);

	$po_pustym = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_t} WHERE id = %d", $lead_a ), ARRAY_A ); // phpcs:ignore WordPress.DB

	hjw_ok(
		is_array( $po_pustym ) && (int) $po_pustym['salesman_id'] === $sal_pl_a,
		'C3: KONTR-ASERCJA — zdarzenie bez wlasciciela nie czysci przypisania',
		'BD-3=' . $kto( is_array( $po_pustym ) ? (int) $po_pustym['salesman_id'] : 0 )
	);
}

/* ==================================================================== D */
// Zrodlo prawdy ma byc JEDNO. Hasz numeru NIP nie ma prawa wrocic zadna droga.

$dz7 = (string) file_get_contents( WP_PLUGIN_DIR . '/mp-lead-intake/includes/pipeline/departments/class-mp-department-07.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

/*
 * Szukamy w KODZIE, nie w tekscie pliku. Naprawiony Dzial 7 cytuje stara linijke
 * w komentarzu — po to, zeby bylo wiadomo, co i dlaczego stad znikneło. Zwykly
 * strpos() uznalby ten cytat za dzialajacy kod i test zglaszalby usterke, ktorej
 * nie ma. (Zglaszal: pierwsza wersja tej asercji wlasnie tak padala.)
 */
$kod_dz7 = '';

foreach ( token_get_all( $dz7 ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}

	$kod_dz7 .= is_array( $token ) ? $token[1] : $token;
}

hjw_ok(
	false === strpos( $kod_dz7, 'crc32' ),
	'D1: wtyczka 1 nie wybiera juz handlowca haszem NIP-u',
	'crc32 nadal w wykonywanym kodzie Dzialu 7'
);

hjw_ok(
	false !== strpos( $kod_dz7, 'mp_lead_assign_salesman' ),
	'D2: wtyczka 1 PYTA o handlowca filtrem, zamiast wybierac',
	'brak filtra mp_lead_assign_salesman w Dziale 7'
);

/*
 * Sprzatanie PO tescie, nie tylko przed. Unikalnosc firmy (kraj, NIP) jest wspolna
 * dla calej bazy, wiec zostawiony wiersz wywala CUDZY test uzywajacy tego samego
 * numeru — i wyglada to na regresje w Dziale 7, a nie na smiec po sasiedzie.
 */
foreach ( array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$leads_t} WHERE email LIKE 'hjw-%@example.test'" ) ) as $do_kasacji ) { // phpcs:ignore WordPress.DB
	$wpdb->delete( $flow_t, array( 'lead_id' => $do_kasacji ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	$wpdb->delete( $ob_t, array( 'lead_id' => $do_kasacji ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	$wpdb->delete( $leads_t, array( 'id' => $do_kasacji ), array( '%d' ) ); // phpcs:ignore WordPress.DB
}

echo implode( "\n", $GLOBALS['hjw']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['hjw']['pass'], $GLOBALS['hjw']['fail'] );
echo ( 0 === $GLOBALS['hjw']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
