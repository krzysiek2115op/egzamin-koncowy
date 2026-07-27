<?php
// SR5-03: harness działa WYŁĄCZNIE z CLI (albo w WP z ABSPATH). Bezpośrednie
// wywołanie przez web-serwer nie robi nic (info disclosure / obciążenie).
if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Harness weryfikujący RUSZTOWANIE (krok 2) pipeline'u MP Offer Builder.
 *
 * Ładuje shim WP, buduje pełny pipeline (MP_OB_Pipeline_Factory) i sprawdza
 * mechanikę procesu — kolejność 11 działów, liczbę par A–K per dział wg
 * blueprint/LP2_diagram_wizualny.html, transakcyjność od działu 10 i
 * jednokierunkowy przepływ danych. Działy są na tym etapie zaślepkami
 * (MP_OB_Stub_Agent/Critic) — logika biznesowa trafi w kroku 3, dział po
 * dziale; wtedy ten harness urośnie o niezmienniki właściwe każdemu działowi
 * (wzorem tests/process-harness/run-process.php w mp-lead-intake).
 */

require __DIR__ . '/wp-stubs.php';

// Katalog wtyczki: z ENV, albo dwa poziomy w górę (tests/process-harness → wtyczka),
// albo fallback na ścieżkę deweloperską (uruchomienie ze scratchpad).
$PLUGIN = getenv( 'MP_PLUGIN_DIR' );
if ( ! $PLUGIN || ! is_dir( $PLUGIN ) ) {
	$guess  = dirname( __DIR__, 2 );
	$PLUGIN = is_file( $guess . '/mp-offer-builder.php' ) ? $guess : '/home/krzysiek/3 pluginy 3 bazy danych /mp-offer-builder';
}
if ( file_exists( $PLUGIN . '/vendor/autoload.php' ) ) {
	require $PLUGIN . '/vendor/autoload.php';
}
require $PLUGIN . '/includes/db/class-mp-offer-builder-db.php';
require $PLUGIN . '/includes/class-mp-offer-builder-storage.php';
require $PLUGIN . '/includes/pipeline/bootstrap.php';
require $PLUGIN . '/includes/class-mp-offer-builder-lead-listener.php';
require $PLUGIN . '/includes/class-mp-offer-builder-download.php';
require $PLUGIN . '/includes/class-mp-offer-builder-ajax.php';
require $PLUGIN . '/includes/admin/class-mp-offer-builder-admin.php';

// Fixture WooCommerce/BD-2 zgodne z base_input() poniżej (item wskazuje variation_id
// 9101 — Agent 2.1 zawsze woli variation_id nad product_id, więc TO wariant musi
// istnieć w fałszywym katalogu, nie sam product_id 812).
function seed_woocommerce_fixtures() {
	$GLOBALS['__mp_ob_wc_products']  = array(
		9101 => array(
			'status'        => 'publish',
			'name'          => 'Testowy wariant',
			'tax_class'     => '',
			'purchasable'   => true,
			'regular_price' => '129.99',
			'sale_price'    => '',
		),
	);
	$GLOBALS['__mp_ob_wc_tax_rates'] = array(
		'' => array( array( 'rate' => 23.0, 'label' => 'VAT' ) ),
	);
	$GLOBALS['__mp_ob_wc_currency']  = 'PLN';
	// Treść z kompletem znaczników używanych przez Dział 7 (Agent 7.2 "scalenie") —
	// pozwala harnessowi realnie sprawdzić podstawienie, nie tylko obecność wiersza.
	$body_pl                         = '<html><body><h1>{{client_name}}</h1>'
		. '<p>{{client_email}} | {{client_nip}} | {{client_country}}</p>'
		. '{{items_table}}<p>Suma: {{subtotal}} Rabat: {{discount_total}}</p>'
		. '<p>Netto: {{net_total}} VAT: {{vat_total}} Brutto: {{gross_total}}</p>'
		. '<p>{{tax_mechanism_note}}</p><p>{{offer_date}}</p></body></html>';
	$body_en                         = '<html><body><h1>{{client_name}}</h1>'
		. '<p>{{client_email}} | {{client_nip}} | {{client_country}}</p>'
		. '{{items_table}}<p>Subtotal: {{subtotal}} Discount: {{discount_total}}</p>'
		. '<p>Net: {{net_total}} VAT: {{vat_total}} Gross: {{gross_total}}</p>'
		. '<p>{{tax_mechanism_note}}</p><p>{{offer_date}}</p></body></html>';
	$GLOBALS['wpdb']->templates      = array(
		1 => array(
			'id'      => 1,
			'name'    => 'Domyślny PL',
			'lang'    => 'pl',
			'content' => $body_pl,
			'version' => '1.0',
			'status'  => 'active',
		),
		2 => array(
			'id'      => 2,
			'name'    => 'Default EN',
			'lang'    => 'en',
			'content' => $body_en,
			'version' => '1.0',
			'status'  => 'active',
		),
	);
}
seed_woocommerce_fixtures();

/* ---------- Narzędzia ---------- */

// Przykładowe żądanie zgodne z kontraktem Działu 1 (blueprint: json wejścia).
function base_input() {
	return array(
		'client'     => array(
			'name'    => 'Testowa Firma Sp. z o.o.',
			'email'   => 'kontakt@testowa-firma.pl',
			'nip'     => '1234563218',
			'country' => 'PL',
		),
		'items'      => array(
			array(
				'product_id'   => 812,
				'variation_id' => 9101,
				'qty'          => 40,
			),
		),
		'wariant'    => 'partner',
		'lang'       => 'pl',
		'request_id' => '3f1c2a10-aaaa-4bbb-8ccc-000000000001',
	);
}

// Uruchamia świeży pipeline dla danego wejścia; zwraca obserwacje procesu.
function run_pipeline( array $input ) {
	$context  = new MP_OB_Context( $input );
	$pipeline = MP_OB_Pipeline_Factory::make();
	$result   = $pipeline->run( $context );
	return array(
		'ok'         => $result->is_ok(),
		'code'       => $result->get_code(),
		'errors'     => $result->get_errors(),
		'stop_dept'  => $context->get_current_department(),
		'final_data' => $result->get_data(),
	);
}

// Pipeline OBCIĘTY do działów 1-9 (bez 10/11) — Dział 11 realnie FINALIZUJE
// plik PDF (rename tmp→docelowy) i zapis jest COMMITowany, więc testy
// STRUKTURY samego renderu (Dział 9) potrzebują pliku, który NIE zostanie
// przeniesiony/skasowany przez dalsze działy — stąd osobny, krótszy pipeline
// zamiast pełnego run_pipeline()/MP_OB_Pipeline_Factory::make().
function run_pipeline_through_department_9( array $input ) {
	$context  = new MP_OB_Context( $input );
	$pipeline = new MP_OB_Pipeline( new MP_OB_Pipeline_Logger() );
	$pipeline->add_department( MP_OB_Department_01::build() );
	$pipeline->add_department( MP_OB_Department_02::build() );
	$pipeline->add_department( MP_OB_Department_03::build() );
	$pipeline->add_department( MP_OB_Department_04::build() );
	$pipeline->add_department( MP_OB_Department_05::build() );
	$pipeline->add_department( MP_OB_Department_06::build() );
	$pipeline->add_department( MP_OB_Department_07::build() );
	$pipeline->add_department( MP_OB_Department_08::build() );
	$pipeline->add_department( MP_OB_Department_09::build() );
	$result   = $pipeline->run( $context );
	return array(
		'ok'         => $result->is_ok(),
		'code'       => $result->get_code(),
		'errors'     => $result->get_errors(),
		'final_data' => $result->get_data(),
	);
}

// Krytyk, który zawsze odrzuca — do testu mechaniki STOP/ROLLBACK (nie jest
// używany przez żaden z 11 prawdziwych działów, tylko w tym harnessie).
function make_failing_critic( $id, $label ) {
	return new class( $id, $label ) implements MP_OB_Critic_Interface {
		private $id;
		private $label;
		public function __construct( $id, $label ) {
			$this->id    = $id;
			$this->label = $label;
		}
		public function get_id() {
			return $this->id; }
		public function get_label() {
			return $this->label; }
		public function review( MP_OB_Result $agent_result, MP_OB_Context $context ) {
			return MP_OB_Result::fail( 'zawsze odrzuca (test harnessu)', array(), 'harness_forced_fail' );
		}
	};
}

$results = array();
$pass    = 0;
$fail    = 0;

function record( $name, $verdict, $note ) {
	global $results, $pass, $fail;
	$results[ $name ] = array( $verdict, $note );
	if ( 'PASS' === $verdict ) {
		++$pass; }
	if ( 'FAIL' === $verdict ) {
		++$fail; }
	printf( "[%-4s] %-60s %s\n", $verdict, $name, $note );
}

/* ---------- Scenariusz 1: pełny przelot przez 11 działów-zaślepek ---------- */

$hp = run_pipeline( base_input() );
record(
	'happy_path_11_departments',
	$hp['ok'] ? 'PASS' : 'FAIL',
	sprintf( 'ok=%s stop_dept=%d code=%s', $hp['ok'] ? 'true' : 'false', $hp['stop_dept'], $hp['code'] ?: '-' )
);

/* ---------- Niezmienniki rusztowania (krok 2) ---------- */

echo "\n=== NIEZMIENNIKI RUSZTOWANIA ===\n";

// 1) Jednokierunkowość: happy-path nie gubi kluczy wejścia.
$lost_keys = array();
if ( $hp['ok'] ) {
	foreach ( base_input() as $k => $v ) {
		if ( ! array_key_exists( $k, $hp['final_data'] ) ) {
			$lost_keys[] = $k;
		}
	}
}
$inv1 = $hp['ok'] && empty( $lost_keys );
record( 'inv1_jednokierunkowosc_bez_utraty_kluczy', $inv1 ? 'PASS' : 'FAIL', $lost_keys ? 'zgubione: ' . implode( ',', $lost_keys ) : 'wszystkie klucze zachowane' );

// 2) 11 działów zarejestrowanych, ponumerowanych kolejno 1..11.
$pipeline    = MP_OB_Pipeline_Factory::make();
$departments = $pipeline->get_departments();
$numbers     = array_map( function ( $d ) {
	return $d->get_number(); }, $departments );
$inv2        = count( $departments ) === 11 && $numbers === range( 1, 11 );
record( 'inv2_11_dzialow_w_kolejnosci', $inv2 ? 'PASS' : 'FAIL', 'numery=' . implode( ',', $numbers ) );

// 3) Liczba par Agent-Krytyk per dział zgodna z blueprint/LP2_diagram_wizualny.html
//    (Dział 1=3, 2=5, 3-9=2, 10=3, 11=2 — suma 27, zgodnie z licznikiem w diagramie).
$expected_pairs = array( 1 => 3, 2 => 5, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2, 8 => 2, 9 => 2, 10 => 3, 11 => 2 );
$actual_pairs   = array();
foreach ( $departments as $d ) {
	$actual_pairs[ $d->get_number() ] = count( $d->get_pairs() );
}
$inv3 = $actual_pairs === $expected_pairs;
record(
	'inv3_liczba_par_wg_diagramu',
	$inv3 ? 'PASS' : 'FAIL',
	'suma=' . array_sum( $actual_pairs ) . ' (oczekiwano 27): ' . wp_json_encode( $actual_pairs )
);

// 4) Transakcyjność: START TRANSACTION dokładnie raz (przed działem 10), COMMIT
//    dokładnie raz na końcu — zgodnie z set_transactional_from(10) w fabryce.
$GLOBALS['wpdb']->tx_log = array();
run_pipeline( base_input() );
$inv4 = $GLOBALS['wpdb']->tx_log === array( 'START', 'COMMIT' );
record( 'inv4_transakcja_start_commit_od_dzialu_10', $inv4 ? 'PASS' : 'FAIL', 'tx_log=' . implode( '>', $GLOBALS['wpdb']->tx_log ) );

// 5) Mechanika STOP: Krytyk odrzucający zatrzymuje dział (Department::process
//    zwraca fail, kod 'critic_failed'), bez przechodzenia do bramki jakości.
$dep_forced_fail = new MP_OB_Department(
	99,
	'harness-forced-fail',
	'Dział testowy harnessu',
	'Wymusza odrzucenie przez krytyka, by zweryfikować mechanikę STOP.',
	array(
		array(
			'agent'  => new MP_OB_Stub_Agent( '99.1', 'Agent testowy', '' ),
			'critic' => make_failing_critic( 'K99.1', 'Krytyk zawsze odrzucający' ),
		),
	),
	new MP_OB_Quality_Gate(
		new MP_OB_Stub_Agent( 'QA99', 'QA nigdy nieuruchomiony', '' ),
		new MP_OB_Accept_Critic( 'QAK99', 'QA Krytyk 99' )
	)
);
$forced_result = $dep_forced_fail->process( new MP_OB_Context( array() ) );
$inv5          = ! $forced_result->is_ok() && 'critic_failed' === $forced_result->get_code();
record( 'inv5_mechanika_stop_na_odrzuceniu_krytyka', $inv5 ? 'PASS' : 'FAIL', 'code=' . $forced_result->get_code() );

// 6) Mechanika ROLLBACK: STOP w dziale >= 10 (transakcyjnym) wycofuje transakcję
//    i loguje błąd do wp_mp_ob_offer_activity_log (MP_OB_Pipeline_Logger).
$pipeline_rollback = new MP_OB_Pipeline( new MP_OB_Pipeline_Logger() );
$pipeline_rollback->set_transactional_from( 10 );
$pipeline_rollback->add_department( MP_OB_Department_09::build() ); // ostatni dział czytający przed zapisem
$pipeline_rollback->add_department( $dep_forced_fail );             // symuluje awarię W TRAKCIE zapisu (dział 10)
$GLOBALS['wpdb']->tx_log       = array();
$GLOBALS['wpdb']->activity_log = array();
// Dział 9 jest teraz REALNY (Krok 3) — potrzebuje minimalnego wejścia zgodnego
// z jego kontraktem (dokument z Działu 7, numer z Działu 8), inaczej sam by
// odrzucił na Agencie 9.1 zanim test w ogóle dotarłby do symulowanej awarii
// w dziale 10.
$rb_context = new MP_OB_Context(
	array(
		'document'     => array( 'html' => '<html><body>Test ROLLBACK</body></html>' ),
		'offer_number' => 'OF/2026/000001',
		'gross_grosze' => 10000,
	)
);
$rb_result  = $pipeline_rollback->run( $rb_context );
$inv6                          = ! $rb_result->is_ok()
	&& $GLOBALS['wpdb']->tx_log === array( 'START', 'ROLLBACK' )
	&& count( $GLOBALS['wpdb']->activity_log ) === 1
	&& 'pipeline_error' === ( $GLOBALS['wpdb']->activity_log[0]['action'] ?? '' );
record(
	'inv6_mechanika_rollback_i_log_bledu',
	$inv6 ? 'PASS' : 'FAIL',
	'tx_log=' . implode( '>', $GLOBALS['wpdb']->tx_log ) . ' activity_log_rows=' . count( $GLOBALS['wpdb']->activity_log )
);

// 88) Sprzątanie tmp PDF NIEZALEŻNE od transakcji (Medium): dział PRZED progiem
//     transakcyjności (realny Dział 9 — render — jest numerem 9, próg to 10)
//     może zostawić plik tymczasowy na dysku, jeśli WŁASNA bramka QA tego
//     działu go odrzuci — w tym momencie żadna transakcja SQL jeszcze się nie
//     otworzyła, więc sprzątanie MUSI działać niezależnie od $in_transaction.
$pretend_render_agent = new class() implements MP_OB_Agent_Interface {
	public function get_id() {
		return '9.1-test';
	}
	public function get_label() {
		return 'Test — udany render';
	}
	public function run( MP_OB_Context $context ) {
		unset( $context );
		$tmp = MP_Offer_Builder_Storage::write_tmp_pdf( '%PDF-1.4 test render' );
		return MP_OB_Result::ok( array( 'pdf' => array( 'tmp_path' => $tmp ) ) );
	}
};
$dep_pretend_9 = new MP_OB_Department(
	9,
	'harness-pretend-render',
	'Dział testowy — udany render, odrzucona bramka QA',
	'Symuluje Dział 9: agent renderuje PLIK NAPRAWDĘ, ale bramka QA odrzuca — PRZED progiem transakcyjności.',
	array(
		array(
			'agent'  => $pretend_render_agent,
			'critic' => new MP_OB_Accept_Critic( 'K9.1-test', 'Zawsze akceptuje' ),
		),
	),
	new MP_OB_Quality_Gate(
		new MP_OB_Stub_Agent( 'QA9-test', 'QA zawsze ok', '' ),
		make_failing_critic( 'QAK9-test', 'QA Krytyk zawsze odrzuca' )
	)
);
$pipeline_pretmx = new MP_OB_Pipeline( new MP_OB_Pipeline_Logger() );
$pipeline_pretmx->set_transactional_from( 10 );
$pipeline_pretmx->add_department( $dep_pretend_9 );
$pretmx_context          = new MP_OB_Context( array() );
$GLOBALS['wpdb']->tx_log = array();
$pretmx_result           = $pipeline_pretmx->run( $pretmx_context );
$leaked_pdf_data         = is_array( $pretmx_context->get( 'pdf' ) ) ? $pretmx_context->get( 'pdf' ) : array();
$leaked_tmp_path         = isset( $leaked_pdf_data['tmp_path'] ) ? (string) $leaked_pdf_data['tmp_path'] : '';
$inv88                   = ! $pretmx_result->is_ok()
	&& array() === $GLOBALS['wpdb']->tx_log // dział 9 < próg 10 -> transakcja NIGDY się nie otworzyła.
	&& '' !== $leaked_tmp_path
	&& ! file_exists( $leaked_tmp_path );
record(
	'inv88_sprzatanie_tmp_pdf_niezaleznie_od_transakcji',
	$inv88 ? 'PASS' : 'FAIL',
	'plik_istnieje=' . ( file_exists( $leaked_tmp_path ) ? 'tak(BLAD)' : 'nie' ) . ' tx_log=' . ( $GLOBALS['wpdb']->tx_log ? implode( '>', $GLOBALS['wpdb']->tx_log ) : '(pusty)' )
);

/* ---------- Dział 1: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 1: REALNA LOGIKA ===\n";

// 10) Brak pozycji → STOP z kodem 'invalid_contract' (Agent 1.1, komplet błędów naraz).
$GLOBALS['__mp_ob_cfg']['denied_caps'] = array();
$no_items                              = base_input();
unset( $no_items['items'] );
$r10   = run_pipeline( $no_items );
$inv10 = ! $r10['ok'] && 1 === $r10['stop_dept'] && 'invalid_contract' === $r10['code'];
record( 'inv10_brak_pozycji_stop_invalid_contract', $inv10 ? 'PASS' : 'FAIL', 'stop_dept=' . $r10['stop_dept'] . ' code=' . $r10['code'] );

// 11) Zły lang (nie pl/en) → STOP z kodem 'invalid_contract'.
$bad_lang = base_input();
$bad_lang['lang'] = 'de';
$r11      = run_pipeline( $bad_lang );
$inv11    = ! $r11['ok'] && 'invalid_contract' === $r11['code'];
record( 'inv11_zly_lang_stop_invalid_contract', $inv11 ? 'PASS' : 'FAIL', 'code=' . $r11['code'] );

// 12) Zły request_id (nie UUID) → STOP z kodem 'invalid_request_id' (Agent 1.3).
$bad_rid              = base_input();
$bad_rid['request_id'] = 'nie-jest-uuid';
$r12                   = run_pipeline( $bad_rid );
$inv12                 = ! $r12['ok'] && 'invalid_request_id' === $r12['code'];
record( 'inv12_zly_request_id_stop', $inv12 ? 'PASS' : 'FAIL', 'code=' . $r12['code'] );

// 13) Brak capability → STOP z kodem 'forbidden' (Agent 1.2), niezależnie od
//     poprawności reszty żądania.
$GLOBALS['__mp_ob_cfg']['denied_caps'] = array( 'mp_offer_builder_manage_offers' => true );
$r13   = run_pipeline( base_input() );
$GLOBALS['__mp_ob_cfg']['denied_caps'] = array();
$inv13 = ! $r13['ok'] && 'forbidden' === $r13['code'];
record( 'inv13_brak_uprawnien_stop_forbidden', $inv13 ? 'PASS' : 'FAIL', 'code=' . $r13['code'] );

// 14) Dokończenie draftu: offer_id wskazuje istniejący wiersz status=draft →
//     client dociągnięty ZE SNAPSHOTU draftu, nie z (pustego) wejścia JSON.
$GLOBALS['wpdb']->offers = array(
	77 => array(
		'id'             => 77,
		'status'         => 'draft',
		'lead_id'        => 501,
		'client_name'    => 'Klient Z Draftu Sp. z o.o.',
		'client_email'   => 'klient@z-draftu.pl',
		'client_nip'     => '1234563218',
		'client_country' => 'PL',
	),
);
$draft_input             = base_input();
$draft_input['offer_id'] = 77;
unset( $draft_input['client'] ); // klient NIE podany w żądaniu — musi przyjść z draftu.
$r14   = run_pipeline( $draft_input );
$inv14 = $r14['ok'] && 'Klient Z Draftu Sp. z o.o.' === ( $r14['final_data']['client']['name'] ?? '' )
	&& 'draft' === ( $r14['final_data']['offer_mode'] ?? '' );
record(
	'inv14_dokonczenie_draftu_client_ze_snapshotu',
	$inv14 ? 'PASS' : 'FAIL',
	'ok=' . ( $r14['ok'] ? 'true' : 'false' ) . ' client.name=' . ( $r14['final_data']['client']['name'] ?? '-' )
);

// 15) offer_id wskazujący NIEISTNIEJĄCY/nie-draft wiersz → STOP 'invalid_contract'.
$bad_offer_id             = base_input();
$bad_offer_id['offer_id'] = 999999;
$r15                      = run_pipeline( $bad_offer_id );
$inv15                    = ! $r15['ok'] && 'invalid_contract' === $r15['code'];
record( 'inv15_offer_id_nieistniejacy_stop', $inv15 ? 'PASS' : 'FAIL', 'code=' . $r15['code'] );

/* ---------- Dział 2: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 2: REALNA LOGIKA (WooCommerce/BD-2) ===\n";

// 16) Pozycja spoza katalogu (variation_id nieistniejący w WC) → STOP 'invalid_products'.
$bad_item                       = base_input();
$bad_item['items'][0]['variation_id'] = 999999;
$r16                             = run_pipeline( $bad_item );
$inv16                           = ! $r16['ok'] && 'invalid_products' === $r16['code'];
record( 'inv16_produkt_spoza_katalogu_stop', $inv16 ? 'PASS' : 'FAIL', 'code=' . $r16['code'] );

// 17) Produkt bez ceny regularnej → STOP 'incomplete_prices'.
seed_woocommerce_fixtures();
$GLOBALS['__mp_ob_wc_products'][9101]['regular_price'] = '';
$r17   = run_pipeline( base_input() );
$inv17 = ! $r17['ok'] && 'incomplete_prices' === $r17['code'];
record( 'inv17_brak_ceny_regularnej_stop', $inv17 ? 'PASS' : 'FAIL', 'code=' . $r17['code'] );

// 18) Brak skonfigurowanej stawki VAT dla klasy podatkowej → STOP 'missing_tax_rate'
//     (NIGDY domyślne 23% podstawiane po cichu — kryt. "stawka-istnieje").
seed_woocommerce_fixtures();
$GLOBALS['__mp_ob_wc_tax_rates'] = array();
$r18   = run_pipeline( base_input() );
$inv18 = ! $r18['ok'] && 'missing_tax_rate' === $r18['code'];
record( 'inv18_brak_stawki_vat_stop', $inv18 ? 'PASS' : 'FAIL', 'code=' . $r18['code'] );

// 19) Brak aktywnego szablonu w żądanym języku → STOP 'missing_template'
//     (NIE ciche przejście na polski — kryt. "wersja-szablonu").
seed_woocommerce_fixtures();
unset( $GLOBALS['wpdb']->templates[2] ); // usuwa jedyny szablon 'en'
$en_request         = base_input();
$en_request['lang'] = 'en';
$r19                = run_pipeline( $en_request );
$inv19               = ! $r19['ok'] && 'missing_template' === $r19['code'];
record( 'inv19_brak_szablonu_w_jezyku_stop', $inv19 ? 'PASS' : 'FAIL', 'code=' . $r19['code'] );

// 20) Numeracja: ostatni numer w BIEŻĄCYM roku ze snapshotu trafia niezmieniony
//     do final_data (Dział 8 użyje go jako "punkt startu", bez własnego odczytu BD-2).
seed_woocommerce_fixtures();
$this_year               = (int) gmdate( 'Y' );
$GLOBALS['wpdb']->offers = array(
	1 => array(
		'id'           => 1,
		'offer_number' => sprintf( 'OF/%d/000050', $this_year ),
		'version'      => 1,
		'status'       => 'sent',
	),
);
$r20   = run_pipeline( base_input() );
$inv20 = $r20['ok']
	&& sprintf( 'OF/%d/000050', $this_year ) === ( $r20['final_data']['numbering']['last_number'] ?? '' )
	&& 1 === ( $r20['final_data']['db_reads'] ?? null );
record(
	'inv20_numeracja_punkt_startu_i_db_reads',
	$inv20 ? 'PASS' : 'FAIL',
	'last_number=' . ( $r20['final_data']['numbering']['last_number'] ?? '-' ) . ' db_reads=' . ( $r20['final_data']['db_reads'] ?? '-' )
);
$GLOBALS['wpdb']->offers = array();
seed_woocommerce_fixtures();

/* ---------- Dział 3: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 3: REALNA LOGIKA ===\n";

// 21) Happy path: items_valid trafia do final_data (kontrakt JSON diagramu Działu 3).
$inv21 = true === ( $hp['final_data']['items_valid'] ?? null );
record( 'inv21_happy_path_items_valid_true', $inv21 ? 'PASS' : 'FAIL', 'items_valid=' . var_export( $hp['final_data']['items_valid'] ?? null, true ) );

// 22) Ilość ponad limit (10 000) → STOP 'invalid_quantities'.
$over_qty                     = base_input();
$over_qty['items'][0]['qty']  = 10001;
$r22                          = run_pipeline( $over_qty );
$inv22                        = ! $r22['ok'] && 'invalid_quantities' === $r22['code'];
record( 'inv22_ilosc_ponad_limit_stop', $inv22 ? 'PASS' : 'FAIL', 'code=' . $r22['code'] );

// 23) Ilość niecałkowita (5.5) → STOP 'invalid_quantities'.
$frac_qty                    = base_input();
$frac_qty['items'][0]['qty'] = 5.5;
$r23                          = run_pipeline( $frac_qty );
$inv23                        = ! $r23['ok'] && 'invalid_quantities' === $r23['code'];
record( 'inv23_ilosc_niecalkowita_stop', $inv23 ? 'PASS' : 'FAIL', 'code=' . $r23['code'] );

// 24) Więcej niż 50 pozycji → STOP 'invalid_quantities'.
seed_woocommerce_fixtures();
$GLOBALS['__mp_ob_wc_products'][9101] = array(
	'status'        => 'publish',
	'name'          => 'Testowy wariant',
	'tax_class'     => '',
	'purchasable'   => true,
	'regular_price' => '129.99',
	'sale_price'    => '',
);
$too_many_items = base_input();
$one_item       = $too_many_items['items'][0];
$too_many_items['items'] = array_fill( 0, 51, $one_item );
$r24                     = run_pipeline( $too_many_items );
$inv24                   = ! $r24['ok'] && 'invalid_quantities' === $r24['code'];
record( 'inv24_zbyt_wiele_pozycji_stop', $inv24 ? 'PASS' : 'FAIL', 'code=' . $r24['code'] );

/* ---------- Dział 4: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 4: REALNA LOGIKA (ceny bazowe, grosze) ===\n";

// 25) Happy path: 129.99 PLN × 40 szt. = 519960 groszy DOKŁADNIE (BCMath, zero float;
//     bez tego "0.1+0.2≠0.3" 19.99*100 jako float dałoby 1998 zamiast 1999 groszy).
$inv25 = $hp['ok']
	&& 12999 === ( $hp['final_data']['lines'][0]['unit_grosze'] ?? null )
	&& 'regular' === ( $hp['final_data']['lines'][0]['price_source'] ?? null )
	&& 519960 === ( $hp['final_data']['lines'][0]['line_grosze'] ?? null )
	&& 519960 === ( $hp['final_data']['subtotal_grosze'] ?? null );
record(
	'inv25_ceny_bazowe_dokladna_arytmetyka_int',
	$inv25 ? 'PASS' : 'FAIL',
	'unit=' . ( $hp['final_data']['lines'][0]['unit_grosze'] ?? '-' ) . ' subtotal=' . ( $hp['final_data']['subtotal_grosze'] ?? '-' )
);

// 26) Cena promocyjna (sale_price < regular_price) → price_source='sale', liczona
//     od ceny promocyjnej, nie regularnej.
seed_woocommerce_fixtures();
$GLOBALS['__mp_ob_wc_products'][9101]['sale_price'] = '99.50';
$r26   = run_pipeline( base_input() );
$inv26 = $r26['ok']
	&& 'sale' === ( $r26['final_data']['lines'][0]['price_source'] ?? null )
	&& 9950 === ( $r26['final_data']['lines'][0]['unit_grosze'] ?? null );
record(
	'inv26_cena_promocyjna_price_source_sale',
	$inv26 ? 'PASS' : 'FAIL',
	'price_source=' . ( $r26['final_data']['lines'][0]['price_source'] ?? '-' ) . ' unit=' . ( $r26['final_data']['lines'][0]['unit_grosze'] ?? '-' )
);
seed_woocommerce_fixtures();

/* ---------- Dział 5: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 5: REALNA LOGIKA (rabaty) ===\n";

// 27) Happy path: wariant 'partner', 40 szt. → próg R-01 (>=1 szt., 5%) spełniony,
//     R-02 (>=50 szt., 10%) NIE — 5% z 519960 = 25998 gr, rules_version='v1'.
$inv27 = $hp['ok']
	&& 'R-01' === ( $hp['final_data']['discounts'][0]['rule_id'] ?? null )
	&& 25998 === ( $hp['final_data']['discount_total'] ?? null )
	&& 'v1' === ( $hp['final_data']['rules_version'] ?? null );
record(
	'inv27_happy_path_rabat_5_procent',
	$inv27 ? 'PASS' : 'FAIL',
	'rule=' . ( $hp['final_data']['discounts'][0]['rule_id'] ?? '-' ) . ' discount_total=' . ( $hp['final_data']['discount_total'] ?? '-' )
);

// 28) 50 szt. (próg R-02) → 10% rabatu zamiast 5%.
$high_qty                    = base_input();
$high_qty['items'][0]['qty'] = 50;
$r28                          = run_pipeline( $high_qty );
$inv28                        = $r28['ok'] && 'R-02' === ( $r28['final_data']['discounts'][0]['rule_id'] ?? null );
record( 'inv28_wolumen_wyzszy_prog_R02', $inv28 ? 'PASS' : 'FAIL', 'rule=' . ( $r28['final_data']['discounts'][0]['rule_id'] ?? '-' ) );

// 29) S1-3: nieznany wariant ODRZUCONY w Dziale 1 (invalid_contract) — literówka
//     wariantu ("enterprise", "Partner") to jawny błąd kontraktu, NIE ciche zejście
//     do catch-all R-00 (0% rabatu), które klient mógłby wziąć za świadomą wycenę.
$unknown_wariant             = base_input();
$unknown_wariant['wariant']  = 'enterprise-niezdefiniowany';
$r29                          = run_pipeline( $unknown_wariant );
$inv29                        = ! $r29['ok'] && 'invalid_contract' === $r29['code'];
record( 'inv29_nieznany_wariant_odrzucony', $inv29 ? 'PASS' : 'FAIL', 'ok=' . ( $r29['ok'] ? 'tak' : 'nie' ) . ' code=' . $r29['code'] );

// 30) Mechanika limitu (bezpośrednio na Agent 5.2 — reguły v1 max 10%, nie da się
//     naturalnie przekroczyć limitu 30% przez dobór reguły, więc testujemy STOP
//     "flaga do akceptacji, nie ciche przycięcie" na sfabrykowanym kontekście).
$over_limit_ctx = new MP_OB_Context(
	array(
		'subtotal_grosze' => 100000,
		'discount_total'  => 50000, // 50% > limit 30%
	)
);
$over_limit_agent  = new MP_OB_D5_Agent_Apply_Discount();
$over_limit_result = $over_limit_agent->run( $over_limit_ctx );
$inv30              = ! $over_limit_result->is_ok() && 'discount_over_limit' === $over_limit_result->get_code();
record( 'inv30_rabat_ponad_limit_stop_bez_przyciecia', $inv30 ? 'PASS' : 'FAIL', 'code=' . $over_limit_result->get_code() );

/* ---------- Dział 6: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 6: REALNA LOGIKA (podatki, VAT) ===\n";

// 31) Happy path (client.country='PL') → mechanizm 'domestic', stawka 23%,
//     net = subtotal - rabat = 519960 - 25998 = 493962 (zgadza się z przykładem
//     JSON w blueprint/LP2_diagram_wizualny.html — "net": 493962), netto+VAT=brutto.
$inv31 = $hp['ok']
	&& 'domestic' === ( $hp['final_data']['tax_mechanism'] ?? null )
	&& 23.0 === ( $hp['final_data']['tax_rate'] ?? null )
	&& 493962 === ( $hp['final_data']['net_grosze'] ?? null )
	&& ( $hp['final_data']['net_grosze'] ?? 0 ) + ( $hp['final_data']['vat_grosze'] ?? 0 ) === ( $hp['final_data']['gross_grosze'] ?? null );
record(
	'inv31_happy_path_mechanizm_domestic_spojnosc_sumy',
	$inv31 ? 'PASS' : 'FAIL',
	'mechanism=' . ( $hp['final_data']['tax_mechanism'] ?? '-' ) . ' net=' . ( $hp['final_data']['net_grosze'] ?? '-' ) . ' vat=' . ( $hp['final_data']['vat_grosze'] ?? '-' ) . ' gross=' . ( $hp['final_data']['gross_grosze'] ?? '-' )
);

// 32) Klient UE (nie-PL) z POTWIERDZONYM ważnym VAT → reverse_charge, VAT=0,
//     gross=net (art. 196 dyrektywy VAT).
$eu_valid                       = base_input();
$eu_valid['client']['country']  = 'DE';
$eu_valid['client']['vat_status'] = 'valid';
$r32                             = run_pipeline( $eu_valid );
$inv32                           = $r32['ok']
	&& 'reverse_charge' === ( $r32['final_data']['tax_mechanism'] ?? null )
	&& 0 === ( $r32['final_data']['vat_grosze'] ?? null )
	&& ( $r32['final_data']['net_grosze'] ?? null ) === ( $r32['final_data']['gross_grosze'] ?? null );
record( 'inv32_ue_vat_wazny_reverse_charge', $inv32 ? 'PASS' : 'FAIL', 'mechanism=' . ( $r32['final_data']['tax_mechanism'] ?? '-' ) . ' vat=' . ( $r32['final_data']['vat_grosze'] ?? '-' ) );

// 33) Klient UE BEZ potwierdzonego VAT (pole nieobecne) → BEZPIECZNY DOMYŚLNY
//     wybór: mechanizm 'domestic' (naliczona stawka), NIGDY ciche 0%.
$eu_unchecked                      = base_input();
$eu_unchecked['client']['country'] = 'DE';
$r33                                = run_pipeline( $eu_unchecked );
$inv33                              = $r33['ok'] && 'domestic' === ( $r33['final_data']['tax_mechanism'] ?? null ) && ( $r33['final_data']['vat_grosze'] ?? 0 ) > 0;
record( 'inv33_ue_bez_potwierdzenia_vat_bezpieczny_domyslny', $inv33 ? 'PASS' : 'FAIL', 'mechanism=' . ( $r33['final_data']['tax_mechanism'] ?? '-' ) . ' vat=' . ( $r33['final_data']['vat_grosze'] ?? '-' ) );

// 34) Klient spoza UE → out_of_scope, VAT=0, inna podstawa prawna niż reverse_charge.
$non_eu                      = base_input();
$non_eu['client']['country'] = 'US';
$r34                          = run_pipeline( $non_eu );
$inv34                        = $r34['ok'] && 'out_of_scope' === ( $r34['final_data']['tax_mechanism'] ?? null ) && 0 === ( $r34['final_data']['vat_grosze'] ?? null );
record( 'inv34_poza_ue_out_of_scope', $inv34 ? 'PASS' : 'FAIL', 'mechanism=' . ( $r34['final_data']['tax_mechanism'] ?? '-' ) );

// 84) Kod kraju NIEPUSTY, ale niezgodny z formatem ISO 3166-1 alpha-2 (np. literówka
//     "PLN" zamiast "PL") → STOP z jawnym kodem, NIGDY ciche 0% VAT pod fałszywą
//     etykietą "poza UE" (Dział 1 sprawdza tylko "niepuste", nie kształt).
$bad_country                      = base_input();
$bad_country['client']['country'] = 'PLN';
$r84                               = run_pipeline( $bad_country );
$inv84                             = ! $r84['ok'] && 'invalid_country' === $r84['code'];
record( 'inv84_zly_format_kraju_stop_invalid_country', $inv84 ? 'PASS' : 'FAIL', 'code=' . $r84['code'] );

// 35) Zaokrąglenie metodą półówkową (bez float) — granica dokładnie .5: 1×50/100=0.5→1,
//     3×50/100=1.5→2 (round half up), sprawdzone bezpośrednio na metodzie statycznej.
$inv35 = 1 === MP_OB_D6_Agent_Rounding::vat_grosze( 1, 50 )
	&& 2 === MP_OB_D6_Agent_Rounding::vat_grosze( 3, 50 )
	&& 2300 === MP_OB_D6_Agent_Rounding::vat_grosze( 10000, 23 );
record( 'inv35_zaokraglenie_polowkowe_bez_float', $inv35 ? 'PASS' : 'FAIL', 'vat(1,50)=' . MP_OB_D6_Agent_Rounding::vat_grosze( 1, 50 ) . ' vat(3,50)=' . MP_OB_D6_Agent_Rounding::vat_grosze( 3, 50 ) );

/* ---------- Dział 7: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 7: REALNA LOGIKA (szablon i treść) ===\n";

// 36) Happy path (pl, domestic): dokument bez niepodstawionych znaczników, dane
//     klienta i sumy (w formacie pl: przecinek dziesiętny, NBSP tysięczny —
//     patrz docs/dzial-07/php-intl-numberformatter.md) realnie trafiają do
//     HTML; tabela pozycji zawiera nazwę produktu i ilość.
$html36              = $hp['final_data']['document']['html'] ?? '';
$expected_gross_pl36 = "6\xc2\xa0075,73 zł"; // gross_grosze=607573, NBSP (U+00A0) nie zwykła spacja.
$inv36               = $hp['ok']
	&& 'pl' === ( $hp['final_data']['document']['lang'] ?? null )
	&& false === strpos( $html36, '{{' )
	&& false !== strpos( $html36, 'Testowa Firma Sp. z o.o.' )
	&& false !== strpos( $html36, $expected_gross_pl36 )
	&& false !== strpos( $html36, 'Testowy wariant' )
	&& false !== strpos( $html36, '<td>40</td>' );
record( 'inv36_happy_path_scalenie_pl_bez_znacznikow', $inv36 ? 'PASS' : 'FAIL', 'lang=' . ( $hp['final_data']['document']['lang'] ?? '-' ) . ' zawiera_gross_nbsp=' . ( false !== strpos( $html36, $expected_gross_pl36 ) ? 'tak' : 'nie' ) );

// 37) UE + ważny VAT (en, reverse_charge): adnotacja odwrotnego obciążenia po
//     angielsku w dokumencie, kwoty w formacie en (kropka dziesiętna, przecinek
//     tysięczny) — gross=net (VAT=0), 493962 gr -> "4,939.62 PLN".
$eu_en_input                  = base_input();
$eu_en_input['client']['country']   = 'DE';
$eu_en_input['client']['vat_status'] = 'valid';
$eu_en_input['lang']                 = 'en';
$r37                           = run_pipeline( $eu_en_input );
$html37                        = $r37['final_data']['document']['html'] ?? '';
$inv37                         = $r37['ok']
	&& 'en' === ( $r37['final_data']['document']['lang'] ?? null )
	&& false === strpos( $html37, '{{' )
	&& false !== strpos( $html37, 'Reverse charge — Article 196' )
	&& false !== strpos( $html37, '4,939.62 PLN' );
record( 'inv37_reverse_charge_adnotacja_en_format_kwot', $inv37 ? 'PASS' : 'FAIL', 'zawiera_adnotacje=' . ( false !== strpos( $html37, 'Reverse charge' ) ? 'tak' : 'nie' ) . ' zawiera_kwote=' . ( false !== strpos( $html37, '4,939.62 PLN' ) ? 'tak' : 'nie' ) );

// 38) Agent 7.1 "dobór": brak szablonu w żądanym języku w zamrożonym snapshocie
//     (defense-in-depth — niezależnie od tego, że Dział 2 już to blokuje wcześniej)
//     -> FAIL 'missing_template_selection', NIE ciche przejście na inny język.
$missing_tpl_ctx    = new MP_OB_Context(
	array(
		'lang'      => 'en',
		'templates' => array( 'pl' => array( 'lang' => 'pl' ) ), // tylko pl w snapshocie
	)
);
$missing_tpl_result = ( new MP_OB_D7_Agent_Selection() )->run( $missing_tpl_ctx );
$inv38               = ! $missing_tpl_result->is_ok() && 'missing_template_selection' === $missing_tpl_result->get_code();
record( 'inv38_dobor_brak_szablonu_w_jezyku_stop', $inv38 ? 'PASS' : 'FAIL', 'code=' . $missing_tpl_result->get_code() );

// 39) Agent 7.2 "scalenie": znacznik spoza słownika podstawień -> STOP jawny
//     ('unfilled_placeholder'), zamiast wysłać dokument z widocznym "{{...}}".
$unfilled_ctx    = new MP_OB_Context(
	array(
		'lang'             => 'pl',
		'currency'         => 'PLN',
		'client'           => array( 'name' => 'X' ),
		'items'            => array(),
		'products'         => array(),
		'lines'            => array(),
		'template_content' => '<html>{{client_name}} {{nieznany_znacznik}}</html>',
	)
);
$unfilled_result = ( new MP_OB_D7_Agent_Merge() )->run( $unfilled_ctx );
$inv39            = ! $unfilled_result->is_ok() && 'unfilled_placeholder' === $unfilled_result->get_code();
record( 'inv39_scalenie_nieznany_znacznik_stop', $inv39 ? 'PASS' : 'FAIL', 'code=' . $unfilled_result->get_code() );

// 40) QA Agent 7 "jednojęzyczność dokumentu": niespójność lang/template_lang/
//     document.lang (np. bug w innym dziale podmieniający lang w locie) -> STOP.
$mismatch_ctx    = new MP_OB_Context(
	array(
		'lang'          => 'pl',
		'template_lang' => 'pl',
		'document'      => array( 'lang' => 'en' ),
	)
);
$mismatch_result = ( new MP_OB_D7_QA_Agent() )->run( $mismatch_ctx );
$inv40            = ! $mismatch_result->is_ok() && 'document_language_mismatch' === $mismatch_result->get_code();
record( 'inv40_jednojezycznosc_niespojnosc_stop', $inv40 ? 'PASS' : 'FAIL', 'code=' . $mismatch_result->get_code() );

/* ---------- Dział 8: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 8: REALNA LOGIKA (numeracja i wersja) ===\n";

$d8_year = (int) gmdate( 'Y' );

// 41) Happy path ($hp policzony na starcie harnessu, offers wtedy puste): nowa
//     oferta, brak poprzedniego numeru w roku -> kandydat 000001, wersja 1.
$inv41 = $hp['ok']
	&& sprintf( 'OF/%d/000001', $d8_year ) === ( $hp['final_data']['offer_number'] ?? null )
	&& 1 === ( $hp['final_data']['version'] ?? null )
	&& array_key_exists( 'parent_version', $hp['final_data'] ) && null === $hp['final_data']['parent_version']
	&& 'new_number' === ( $hp['final_data']['numbering_mode'] ?? null );
record( 'inv41_happy_path_pierwszy_numer_w_roku', $inv41 ? 'PASS' : 'FAIL', 'offer_number=' . ( $hp['final_data']['offer_number'] ?? '-' ) . ' version=' . ( $hp['final_data']['version'] ?? '-' ) );

// 42) Ciągłość numeracji: ostatni numer w roku = 000050 -> kandydat 000051.
$GLOBALS['wpdb']->offers = array(
	1 => array(
		'id'           => 1,
		'offer_number' => sprintf( 'OF/%d/000050', $d8_year ),
		'version'      => 1,
		'status'       => 'sent',
	),
);
$r42                      = run_pipeline( base_input() );
$inv42                    = $r42['ok']
	&& sprintf( 'OF/%d/000051', $d8_year ) === ( $r42['final_data']['offer_number'] ?? null )
	&& 1 === ( $r42['final_data']['version'] ?? null );
record( 'inv42_ciaglosc_numeracji_kolejny_numer', $inv42 ? 'PASS' : 'FAIL', 'offer_number=' . ( $r42['final_data']['offer_number'] ?? '-' ) );
$GLOBALS['wpdb']->offers = array();

// 43) Korekta: offer_id wskazuje draft, który JUŻ MA numer (wcześniej ukończona
//     oferta wciąż w statusie 'draft' — status zmienia dopiero plugin 3) ->
//     TEN SAM numer, wersja+1, parent_version = poprzednia wersja. NIGDY nadpisanie.
$GLOBALS['wpdb']->offers = array(
	5 => array(
		'id'             => 5,
		'status'         => 'draft',
		'offer_number'   => sprintf( 'OF/%d/000010', $d8_year ),
		'version'        => 2,
		'client_name'    => 'Klient Korekta Sp. z o.o.',
		'client_email'   => 'korekta@testowa-firma.pl',
		'client_nip'     => '1234563218',
		'client_country' => 'PL',
	),
);
$correction_input             = base_input();
$correction_input['offer_id'] = 5;
unset( $correction_input['client'] );
$r43   = run_pipeline( $correction_input );
$inv43 = $r43['ok']
	&& sprintf( 'OF/%d/000010', $d8_year ) === ( $r43['final_data']['offer_number'] ?? null )
	&& 3 === ( $r43['final_data']['version'] ?? null )
	&& 2 === ( $r43['final_data']['parent_version'] ?? null )
	&& 'correction' === ( $r43['final_data']['numbering_mode'] ?? null );
record(
	'inv43_korekta_ten_sam_numer_wersja_plus_jeden',
	$inv43 ? 'PASS' : 'FAIL',
	'offer_number=' . ( $r43['final_data']['offer_number'] ?? '-' ) . ' version=' . ( $r43['final_data']['version'] ?? '-' ) . ' parent=' . ( $r43['final_data']['parent_version'] ?? '-' )
);
$GLOBALS['wpdb']->offers = array();

// 44) Agent 8.1: format ostatniego numeru w snapshocie uszkodzony (np. bug w innym
//     dziale/starsze dane) -> STOP jawny, NIE próba zgadywania kolejnej liczby.
$malformed_ctx    = new MP_OB_Context( array( 'numbering' => array( 'year' => $d8_year, 'last_number' => 'GARBAGE-NOT-A-NUMBER' ) ) );
$malformed_result = ( new MP_OB_D8_Agent_Number() )->run( $malformed_ctx );
$inv44             = ! $malformed_result->is_ok() && 'malformed_last_number' === $malformed_result->get_code();
record( 'inv44_numer_uszkodzony_format_stop', $inv44 ? 'PASS' : 'FAIL', 'code=' . $malformed_result->get_code() );

// 45) Krytyk 8.2 "przyrost-wersji": wersja NIE zwiększona względem parent_version
//     (np. bug próbujący powtórzyć/nadpisać wersję) -> STOP, nigdy ciche przejście.
$no_increment_critic = new MP_OB_D8_Critic_Version_Increment( 'K8.2', 'test' );
$no_increment_result = $no_increment_critic->review(
	MP_OB_Result::ok(
		array(
			'offer_number'   => sprintf( 'OF/%d/000010', $d8_year ),
			'version'        => 2,
			'parent_version' => 2, // brak przyrostu — to samo co poprzednio.
		)
	),
	new MP_OB_Context( array() )
);
$inv45 = ! $no_increment_result->is_ok() && 'version_not_incremented' === $no_increment_result->get_code();
record( 'inv45_przyrost_wersji_bez_zmiany_stop', $inv45 ? 'PASS' : 'FAIL', 'code=' . $no_increment_result->get_code() );

// 46) QA Agent 8 "jedno-albo-drugie": stan niespójny (tryb new_number, ale wersja
//     inna niż 1 — np. wynik pomieszania danych między działami) -> STOP.
$ambiguous_ctx    = new MP_OB_Context(
	array(
		'numbering_mode' => 'new_number',
		'version'        => 2,
		'parent_version' => null,
		'offer_number'   => sprintf( 'OF/%d/000099', $d8_year ),
	)
);
$ambiguous_result = ( new MP_OB_D8_QA_Agent() )->run( $ambiguous_ctx );
$inv46             = ! $ambiguous_result->is_ok() && 'numbering_mode_ambiguous' === $ambiguous_result->get_code();
record( 'inv46_jedno_albo_drugie_stan_niespojny_stop', $inv46 ? 'PASS' : 'FAIL', 'code=' . $ambiguous_result->get_code() );

/* ---------- Dział 9: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 9: REALNA LOGIKA (render PDF) ===\n";

// 47) Happy path: prawdziwy plik PDF na dysku, w katalogu prywatnym-tymczasowym,
//     strony/rozmiar/skrót spójne, plik-to-nie-dowód (QA9) potwierdza.
// Pipeline OBCIĘTY do działów 1-9 (nie pełny $hp!) — Dział 11 realnie
// finalizuje (rename) ten sam plik, więc pełny przebieg zostawiłby tu plik
// już przeniesiony i inv47 nie miałby czego sprawdzić.
$r9    = run_pipeline_through_department_9( base_input() );
$pdf47 = $r9['final_data']['pdf'] ?? array();
$inv47 = $r9['ok']
	&& ! empty( $pdf47['tmp_path'] ) && file_exists( $pdf47['tmp_path'] )
	&& 0 === strpos( $pdf47['tmp_path'], MP_Offer_Builder_Storage::tmp_dir() )
	&& ( $pdf47['pages'] ?? 0 ) >= 1
	&& ( $pdf47['bytes'] ?? 0 ) > 0
	&& 1 === preg_match( '/^[a-f0-9]{64}$/', $pdf47['sha256'] ?? '' );
// Uwaga: "pdf_verified" istnieje tylko w wyniku WEWNĄTRZ bramki jakości (QA9) —
// MP_OB_Department::process() NIE scala danych bramki z kontekstem (tylko jej
// werdykt PASS/FAIL), więc $r9['ok']===true już DOWODZI, że QA9
// zaakceptował (inaczej pipeline zatrzymałby się na dziale 9) — bez potrzeby
// odczytywania tej flagi z final_data (ten sam wzorzec co w Działach 2/3).
record(
	'inv47_happy_path_plik_pdf_realny_w_katalogu_prywatnym',
	$inv47 ? 'PASS' : 'FAIL',
	'tmp_path_istnieje=' . ( ! empty( $pdf47['tmp_path'] ) && file_exists( $pdf47['tmp_path'] ) ? 'tak' : 'nie' ) . ' pages=' . ( $pdf47['pages'] ?? '-' ) . ' bytes=' . ( $pdf47['bytes'] ?? '-' )
);

// 48) Agent 9.1: brak dokumentu (Dział 7 nie przebiegł) → STOP jawny, nie próba
//     renderu pustej treści.
$missing_doc_result = ( new MP_OB_D9_Agent_Render() )->run( new MP_OB_Context( array( 'offer_number' => 'OF/2026/000001' ) ) );
$inv48                = ! $missing_doc_result->is_ok() && 'missing_document' === $missing_doc_result->get_code();
record( 'inv48_render_brak_dokumentu_stop', $inv48 ? 'PASS' : 'FAIL', 'code=' . $missing_doc_result->get_code() );

// 49) Agent 9.2: treść PDF (metadane) niezgodna z "kopertą" — realny plik z
//     happy-path, ale porównywany z NIEPRAWDZIWYM numerem oferty.
$mismatch_ctx    = new MP_OB_Context(
	array(
		'pdf'          => $pdf47,
		'offer_number' => 'OF/2026/999999', // celowo INNY niż w metadanych realnego pliku.
		'gross_grosze' => $hp['final_data']['gross_grosze'] ?? 0,
	)
);
$mismatch_result = ( new MP_OB_D9_Agent_Control() )->run( $mismatch_ctx );
$inv49             = ! $mismatch_result->is_ok() && 'pdf_content_mismatch' === $mismatch_result->get_code();
record( 'inv49_kontrola_tresc_niezgodna_z_koperta_stop', $inv49 ? 'PASS' : 'FAIL', 'code=' . $mismatch_result->get_code() );

// 50) Agent 9.2: rozmiar ponad limit → STOP (fabrykowane dane, bez realnego renderu).
$too_large_ctx    = new MP_OB_Context( array( 'pdf' => array( 'tmp_path' => $pdf47['tmp_path'] ?? '', 'pages' => 1, 'bytes' => MP_OB_D9_Agent_Control::MAX_PDF_BYTES + 1 ) ) );
$too_large_result = ( new MP_OB_D9_Agent_Control() )->run( $too_large_ctx );
$inv50             = ! $too_large_result->is_ok() && 'pdf_too_large' === $too_large_result->get_code();
record( 'inv50_kontrola_rozmiar_ponad_limit_stop', $inv50 ? 'PASS' : 'FAIL', 'code=' . $too_large_result->get_code() );

// 51) Agent 9.2: zero stron → STOP.
$empty_pdf_result = ( new MP_OB_D9_Agent_Control() )->run( new MP_OB_Context( array( 'pdf' => array( 'tmp_path' => $pdf47['tmp_path'] ?? '', 'pages' => 0, 'bytes' => 100 ) ) ) );
$inv51              = ! $empty_pdf_result->is_ok() && 'empty_pdf' === $empty_pdf_result->get_code();
record( 'inv51_kontrola_zero_stron_stop', $inv51 ? 'PASS' : 'FAIL', 'code=' . $empty_pdf_result->get_code() );

// 52) QA Agent 9 "plik-to-nie-dowód": skrót w kontekście NIE zgadza się z realnym
//     plikiem na dysku (np. bug między agentami) → STOP, mimo że plik istnieje.
$hash_mismatch_ctx    = new MP_OB_Context( array( 'pdf' => array( 'tmp_path' => $pdf47['tmp_path'] ?? '', 'sha256' => str_repeat( '0', 64 ) ) ) );
$hash_mismatch_result = ( new MP_OB_D9_QA_Agent() )->run( $hash_mismatch_ctx );
$inv52                  = ! $hash_mismatch_result->is_ok() && 'pdf_hash_mismatch' === $hash_mismatch_result->get_code();
record( 'inv52_plik_to_nie_dowod_skrot_niezgodny_stop', $inv52 ? 'PASS' : 'FAIL', 'code=' . $hash_mismatch_result->get_code() );

// 53) QA Agent 9: plik POZA katalogiem prywatnym-tymczasowym (np. pomyłka innej
//     ścieżki) → STOP, nawet jeśli plik istnieje i skrót się zgadza.
$outside_dir      = sys_get_temp_dir() . '/mp-ob-outside-' . uniqid() . '.pdf';
file_put_contents( $outside_dir, 'not-a-real-pdf' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$outside_ctx      = new MP_OB_Context( array( 'pdf' => array( 'tmp_path' => $outside_dir, 'sha256' => hash_file( 'sha256', $outside_dir ) ) ) );
$outside_result   = ( new MP_OB_D9_QA_Agent() )->run( $outside_ctx );
$inv53              = ! $outside_result->is_ok() && 'pdf_outside_protected_dir' === $outside_result->get_code();
record( 'inv53_plik_poza_katalogiem_prywatnym_stop', $inv53 ? 'PASS' : 'FAIL', 'code=' . $outside_result->get_code() );
unlink( $outside_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink

// 54) Krytyk 9.1 "diakrytyka": plik BEZ osadzonego fontu DejaVu (np. inny
//     silnik renderujący w przyszłości) → STOP, "krzaki" nigdy nie przechodzą.
$fake_pdf_path = MP_Offer_Builder_Storage::tmp_dir() . '/of-fake-no-font-' . uniqid() . '.pdf';
file_put_contents( $fake_pdf_path, '%PDF-1.4 obsah bez osadzonego fontu' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$no_font_critic = new MP_OB_D9_Critic_Diacritics( 'K9.1', 'test' );
$no_font_result = $no_font_critic->review( MP_OB_Result::ok( array( 'pdf' => array( 'tmp_path' => $fake_pdf_path ) ) ), new MP_OB_Context( array() ) );
$inv54            = ! $no_font_result->is_ok() && 'font_not_embedded' === $no_font_result->get_code();
record( 'inv54_diakrytyka_brak_osadzonego_fontu_stop', $inv54 ? 'PASS' : 'FAIL', 'code=' . $no_font_result->get_code() );
unlink( $fake_pdf_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink

/* ---------- Dział 10: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 10: REALNA LOGIKA (zapis — jedna transakcja) ===\n";

// 55) Happy path (świeży przebieg, czyste tabele): nagłówek+pozycje+wersja+dziennik
//     naprawdę zapisane w BD-2 (fake wpdb), affected_rows=4, db_writes=1.
$GLOBALS['wpdb']->offers       = array();
$GLOBALS['wpdb']->items        = array();
$GLOBALS['wpdb']->versions     = array();
$GLOBALS['wpdb']->activity_log = array();
$r55                            = run_pipeline( base_input() );
$offer_id_55                    = $r55['final_data']['offer_id'] ?? 0;
$stored_offer_55                = $GLOBALS['wpdb']->offers[ $offer_id_55 ] ?? array();
$inv55                          = $r55['ok']
	&& 4 === ( $r55['final_data']['affected_rows'] ?? null )
	&& 1 === ( $r55['final_data']['db_writes'] ?? null )
	&& 'draft' === ( $stored_offer_55['status'] ?? '' )
	&& 607573 === (int) ( $stored_offer_55['gross_grosze'] ?? 0 )
	&& 1 === preg_match( '/^[a-f0-9]{64}$/', $stored_offer_55['pdf_sha256'] ?? '' )
	&& 1 === count( $GLOBALS['wpdb']->items )
	&& 1 === count( $GLOBALS['wpdb']->versions )
	&& 1 === count( $GLOBALS['wpdb']->activity_log )
	&& 'offer.created' === ( $GLOBALS['wpdb']->activity_log[0]['action'] ?? '' );
record(
	'inv55_happy_path_zapis_kompletny_w_bd2',
	$inv55 ? 'PASS' : 'FAIL',
	'offer_id=' . $offer_id_55 . ' status=' . ( $stored_offer_55['status'] ?? '-' ) . ' items=' . count( $GLOBALS['wpdb']->items ) . ' versions=' . count( $GLOBALS['wpdb']->versions ) . ' log=' . count( $GLOBALS['wpdb']->activity_log )
);

// 56) Dokończenie draftu z Kroku 2.5 (offer_id istnieje, ale BEZ numeru) →
//     UPDATE istniejącego wiersza, NIE nowy INSERT (offers ma nadal 1 wiersz).
$GLOBALS['wpdb']->offers       = array(
	8 => array(
		'id'             => 8,
		'status'         => 'draft',
		'lead_id'        => 900,
		'client_name'    => 'Klient Draft Sp. z o.o.',
		'client_email'   => 'draft@testowa-firma.pl',
		'client_nip'     => '1234563218',
		'client_country' => 'PL',
	),
);
$GLOBALS['wpdb']->items        = array();
$GLOBALS['wpdb']->versions     = array();
$GLOBALS['wpdb']->activity_log = array();
$draft_finish_input             = base_input();
$draft_finish_input['offer_id'] = 8;
unset( $draft_finish_input['client'] );
$r56           = run_pipeline( $draft_finish_input );
$inv56         = $r56['ok']
	&& 1 === count( $GLOBALS['wpdb']->offers )
	&& isset( $GLOBALS['wpdb']->offers[8] )
	&& ! empty( $GLOBALS['wpdb']->offers[8]['offer_number'] )
	&& 'Klient Draft Sp. z o.o.' === ( $GLOBALS['wpdb']->offers[8]['client_name'] ?? '' );
record(
	'inv56_dokonczenie_draftu_update_nie_insert',
	$inv56 ? 'PASS' : 'FAIL',
	'offers_count=' . count( $GLOBALS['wpdb']->offers ) . ' offer_number=' . ( $GLOBALS['wpdb']->offers[8]['offer_number'] ?? '-' )
);

// 57) Korekta (offer_id ma JUŻ numer) → TEN SAM numer, wersja+1 W ZAPISANYM
//     wierszu, log z action='offer.versioned'.
$d10_year                       = (int) gmdate( 'Y' );
$GLOBALS['wpdb']->offers        = array(
	9 => array(
		'id'             => 9,
		'status'         => 'draft',
		'offer_number'   => sprintf( 'OF/%d/000020', $d10_year ),
		'version'        => 1,
		'client_name'    => 'Klient Korekta 10 Sp. z o.o.',
		'client_email'   => 'korekta10@testowa-firma.pl',
		'client_nip'     => '1234563218',
		'client_country' => 'PL',
	),
);
$GLOBALS['wpdb']->items        = array();
$GLOBALS['wpdb']->versions     = array();
$GLOBALS['wpdb']->activity_log = array();
$correction10_input             = base_input();
$correction10_input['offer_id'] = 9;
unset( $correction10_input['client'] );
$r57   = run_pipeline( $correction10_input );
$inv57 = $r57['ok']
	&& sprintf( 'OF/%d/000020', $d10_year ) === ( $GLOBALS['wpdb']->offers[9]['offer_number'] ?? '' )
	&& 2 === (int) ( $GLOBALS['wpdb']->offers[9]['version'] ?? 0 )
	&& 'offer.versioned' === ( $GLOBALS['wpdb']->activity_log[0]['action'] ?? '' );
record(
	'inv57_korekta_ten_sam_numer_wersja_w_zapisie',
	$inv57 ? 'PASS' : 'FAIL',
	'offer_number=' . ( $GLOBALS['wpdb']->offers[9]['offer_number'] ?? '-' ) . ' version=' . ( $GLOBALS['wpdb']->offers[9]['version'] ?? '-' ) . ' log_action=' . ( $GLOBALS['wpdb']->activity_log[0]['action'] ?? '-' )
);
$GLOBALS['wpdb']->offers = array();

// 57b) Round-trip vat_status (regresja utrwalenia w BD-2, 1.0.2): korekta oferty
//      UE z ZAPISANYM ważnym VAT UE (client_vat_status='valid' w snapshocie) →
//      Dział 1 odtwarza vat_status ze snapshotu, Dział 6 zostaje przy
//      reverse_charge, VAT=0. Bez utrwalenia mechanizm spadłby do 'domestic'.
$d10_year_rc                   = (int) gmdate( 'Y' );
$GLOBALS['wpdb']->offers       = array(
	11 => array(
		'id'                => 11,
		'status'            => 'draft',
		'offer_number'      => sprintf( 'OF/%d/000030', $d10_year_rc ),
		'version'           => 1,
		'client_name'       => 'EU Reverse Charge GmbH',
		'client_email'      => 'vat@eu-firma.de',
		'client_nip'        => 'DE123456789',
		'client_country'    => 'DE',
		'client_vat_status' => 'valid',
	),
);
$GLOBALS['wpdb']->items        = array();
$GLOBALS['wpdb']->versions     = array();
$GLOBALS['wpdb']->activity_log = array();
$rc_corr_input             = base_input();
$rc_corr_input['offer_id'] = 11;
unset( $rc_corr_input['client'] );
$r57b   = run_pipeline( $rc_corr_input );
$inv57b = $r57b['ok']
	&& 'reverse_charge' === ( $r57b['final_data']['tax_mechanism'] ?? null )
	&& 0 === (int) ( $r57b['final_data']['vat_grosze'] ?? -1 )
	&& 'valid' === ( $GLOBALS['wpdb']->offers[11]['client_vat_status'] ?? null );
record(
	'inv57b_vat_status_roundtrip_korekta_reverse_charge',
	$inv57b ? 'PASS' : 'FAIL',
	'mechanism=' . ( $r57b['final_data']['tax_mechanism'] ?? '-' ) . ' vat=' . ( $r57b['final_data']['vat_grosze'] ?? '-' ) . ' stored_vat_status=' . ( $GLOBALS['wpdb']->offers[11]['client_vat_status'] ?? '-' )
);
$GLOBALS['wpdb']->offers = array();

// 58) Agent 10.1 "plan": pole spoza limitu DDL (client_country > 2 znaki, np.
//     błąd w innym dziale) → STOP jawny, PRZED jakimkolwiek zapisem.
$ddl_ctx    = new MP_OB_Context(
	array(
		'client'       => array( 'name' => 'X', 'email' => 'x@x.pl', 'nip' => '123', 'country' => 'ZBYT-DLUGI-KOD' ),
		'items'        => array( array( 'product_id' => 1, 'qty' => 1 ) ),
		'lines'        => array( array( 'unit_grosze' => 100, 'line_grosze' => 100 ) ),
		'offer_number' => 'OF/2026/000001',
		'version'      => 1,
		'lang'         => 'pl',
	)
);
$ddl_result = ( new MP_OB_D10_Agent_Plan() )->run( $ddl_ctx );
$inv58       = ! $ddl_result->is_ok() && 'ddl_violation' === $ddl_result->get_code();
record( 'inv58_plan_pole_ponad_limit_ddl_stop', $inv58 ? 'PASS' : 'FAIL', 'code=' . $ddl_result->get_code() );

// 59) Kolizja UNIQUE — DOKŁADNIE JEDNA, retry lokalny (Agent 10.2) ją
//     rozwiązuje bez interwencji pipeline'u: pipeline i tak kończy sukcesem.
$GLOBALS['wpdb']->offers                    = array();
$GLOBALS['wpdb']->items                     = array();
$GLOBALS['wpdb']->versions                  = array();
$GLOBALS['wpdb']->activity_log              = array();
$GLOBALS['wpdb']->force_unique_collision_once = true;
$r59                                          = run_pipeline( base_input() );
$inv59                                        = $r59['ok'] && 1 === count( $GLOBALS['wpdb']->offers ) && 1 === count( $GLOBALS['wpdb']->items );
record( 'inv59_kolizja_unique_pojedyncza_retry_odzyskuje', $inv59 ? 'PASS' : 'FAIL', 'ok=' . ( $r59['ok'] ? 'true' : 'false' ) . ' offers=' . count( $GLOBALS['wpdb']->offers ) );
$GLOBALS['wpdb']->force_unique_collision_once = false;

// 60) Kolizja UNIQUE TRWAŁA (np. realna równoległa oferta z tym samym numerem
//     nieustannie) → retry wyczerpuje MAX_ATTEMPTS podejść, jawny FAIL, pipeline
//     robi ROLLBACK (bez połowicznych wierszy).
$GLOBALS['wpdb']->offers                      = array();
$GLOBALS['wpdb']->items                       = array();
$GLOBALS['wpdb']->versions                    = array();
$GLOBALS['wpdb']->activity_log                = array();
$GLOBALS['wpdb']->tx_log                      = array();
$GLOBALS['wpdb']->force_unique_collision_always = true;
$r60                                            = run_pipeline( base_input() );
$inv60                                          = ! $r60['ok']
	&& 'numbering_collision_unresolved' === $r60['code']
	&& array( 'START', 'ROLLBACK' ) === $GLOBALS['wpdb']->tx_log
	&& 0 === count( $GLOBALS['wpdb']->offers );
record(
	'inv60_kolizja_unique_trwala_wyczerpuje_proby_rollback',
	$inv60 ? 'PASS' : 'FAIL',
	'code=' . $r60['code'] . ' tx_log=' . implode( '>', $GLOBALS['wpdb']->tx_log ) . ' offers=' . count( $GLOBALS['wpdb']->offers )
);
$GLOBALS['wpdb']->force_unique_collision_always = false;

// 87) Niesprawdzony INSERT pozycji/wersji/dziennika w Dziale 10 (High): jeśli
//     wynik insert() jest ignorowany, "0 wierszy zmienionych" przechodzi
//     przez QA Agenta 10 bez zauważenia (affected_rows rósłby "na sucho").
//     Każdy z trzech INSERT-ów musi FAKTYCZNIE zatrzymać zapis (ROLLBACK).
$insert_fail_flags = array(
	'force_items_insert_fail'    => 'pozycji',
	'force_versions_insert_fail' => 'wersji',
	'force_log_insert_fail'      => 'dziennika',
);
$inv87              = true;
$inv87_details      = array();
foreach ( $insert_fail_flags as $flag => $label ) {
	$GLOBALS['wpdb']->offers         = array();
	$GLOBALS['wpdb']->items          = array();
	$GLOBALS['wpdb']->versions       = array();
	$GLOBALS['wpdb']->activity_log   = array();
	$GLOBALS['wpdb']->tx_log         = array();
	$GLOBALS['wpdb']->{$flag}        = true;
	$r87                              = run_pipeline( base_input() );
	$GLOBALS['wpdb']->{$flag}        = false;
	// Uwaga: fake $wpdb NIE cofa wpisów już dodanych do swoich tablic in-memory
	// przy ROLLBACK (modeluje tylko SEKWENCJĘ START/COMMIT/ROLLBACK, nie realne
	// MVCC) — nagłówek oferty (wstawiony PRZED pozycjami/wersją/dziennikiem)
	// więc zostaje widoczny w $offers nawet po zwróceniu fail(). Sprawdzamy to,
	// co fake FAKTYCZNIE modeluje wiernie: kod błędu i sekwencję transakcji.
	$ok87                             = ! $r87['ok']
		&& 'write_failed' === $r87['code']
		&& array( 'START', 'ROLLBACK' ) === $GLOBALS['wpdb']->tx_log;
	$inv87_details[]                  = $label . '=' . ( $ok87 ? 'ok' : 'FAIL(code=' . $r87['code'] . ')' );
	$inv87                            = $inv87 && $ok87;
}
record( 'inv87_niesprawdzony_insert_pozycji_wersji_dziennika_stop', $inv87 ? 'PASS' : 'FAIL', implode( ' ', $inv87_details ) );

// 89) retry_after_collision (Medium): gdy PONOWNY render po kolizji numeracji
//     się nie powiedzie, wywołujący MUSI dostać jawny STOP — inaczej plan
//     miałby NOWY offer_number/version, ale STARY (już skasowany przez
//     delete_tmp) pdf_path/pdf_sha256: oferta z martwym wskaźnikiem na PDF.
//     Kontekst BEZ 'document' -> re-render w retry_after_collision nieuchronnie
//     zawiedzie (ten sam mechanizm co inv48 "Agent 9.1: brak dokumentu").
$GLOBALS['wpdb']->offers                       = array();
$GLOBALS['wpdb']->force_unique_collision_once  = true;
$retry_fail_ctx                                = new MP_OB_Context(
	array(
		'write_plan' => array(
			'header'  => array(
				'offer_number' => 'OF/2026/000050',
				'version'      => 1,
				'pdf_path'     => '/tmp/stara-sciezka-ktora-zaraz-zniknie.pdf',
			),
			'items'   => array( array( 'product_id' => 1, 'qty' => 1 ) ),
			'version' => array( 'version_number' => 1 ),
			'log'     => array( 'action' => 'offer.created' ),
		),
		// Celowo BRAK 'document' — Agent 9.1 wywołany wewnątrz retry_after_collision
		// zwróci 'missing_document' (patrz inv48), symulując nieudany re-render.
	)
);
$retry_fail_result                             = ( new MP_OB_D10_Agent_Transaction() )->run( $retry_fail_ctx );
$GLOBALS['wpdb']->force_unique_collision_once  = false;
$inv89                                          = ! $retry_fail_result->is_ok() && 'retry_render_failed' === $retry_fail_result->get_code();
record( 'inv89_retry_after_collision_nieudany_render_stop', $inv89 ? 'PASS' : 'FAIL', 'code=' . $retry_fail_result->get_code() );

// 61) QA Agent 10 "atomowość": affected_rows niezgodne z planem (np. bug w
//     Agencie 10.2/10.3) → STOP, mimo że każdy pojedynczy INSERT się udał.
$atomicity_ctx    = new MP_OB_Context(
	array(
		'write_plan'    => array( 'items' => array( array( 'qty' => 1 ) ) ), // oczekiwane: 1+1+1+1=4
		'affected_rows' => 3, // "zgubiony" jeden wiersz.
		'pdf'           => array( 'tmp_path' => $pdf47['tmp_path'] ?? '' ),
	)
);
$atomicity_result = ( new MP_OB_D10_QA_Agent() )->run( $atomicity_ctx );
$inv61              = ! $atomicity_result->is_ok() && 'atomicity_mismatch' === $atomicity_result->get_code();
record( 'inv61_atomowosc_niezgodna_liczba_wierszy_stop', $inv61 ? 'PASS' : 'FAIL', 'code=' . $atomicity_result->get_code() );

// 86) Blokada optymistyczna (Agent 10.2, High — "lost update"): lock_version w
//     bazie ZMIENIŁ SIĘ między odczytem (Dział 2) a zapisem (ktoś inny wygrał
//     wyścig na tę samą ofertę) -> STOP z 'concurrent_modification', BEZ
//     nadpisania cudzego zapisu i BEZ wstawienia naszych pozycji (ROLLBACK).
$GLOBALS['wpdb']->offers = array(
	40 => array(
		'id'           => 40,
		'status'       => 'draft',
		'offer_number' => 'OF/2026/000040',
		'version'      => 5,
		'lock_version' => 5, // ktoś inny zapisał już lock_version=5.
		'created_by'   => 1,
		'client_name'  => 'Wersja zapisana przez kogos innego',
	),
);
$GLOBALS['wpdb']->items = array();
$optimistic_ctx         = new MP_OB_Context(
	array(
		'write_plan' => array(
			'header'                => array(
				'id'           => 40,
				'offer_number' => 'OF/2026/000040',
				'version'      => 5,
				'lock_version' => 5,
				'client_name'  => 'Moja wersja (powinna przegrac)',
				'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			'items'                 => array( array( 'product_id' => 1, 'qty' => 1 ) ),
			'version'               => array( 'version_number' => 5 ),
			'log'                   => array( 'action' => 'offer.versioned' ),
			'expected_lock_version' => 4, // STARY lock_version odczytany przez NAS — już nieaktualny.
		),
	)
);
$optimistic_result = ( new MP_OB_D10_Agent_Transaction() )->run( $optimistic_ctx );
$inv86              = ! $optimistic_result->is_ok()
	&& 'concurrent_modification' === $optimistic_result->get_code()
	&& 'Wersja zapisana przez kogos innego' === ( $GLOBALS['wpdb']->offers[40]['client_name'] ?? null )
	&& 0 === count( $GLOBALS['wpdb']->items );
record(
	'inv86_blokada_optymistyczna_wykrywa_lost_update',
	$inv86 ? 'PASS' : 'FAIL',
	'code=' . $optimistic_result->get_code() . ' offer_niezmieniony=' . ( 'Wersja zapisana przez kogos innego' === ( $GLOBALS['wpdb']->offers[40]['client_name'] ?? null ) ? 'tak' : 'nie' )
);
$GLOBALS['wpdb']->offers = array();

// 96) Blokada optymistyczna MUSI działać też dla PIERWSZEGO ponumerowania
//     auto-utworzonego draftu (High#4, dokładny scenariusz z re-audytu): Dział 8
//     w trybie 'new_number' ZAWSZE nadaje business `version=1`, niezależnie od
//     tego, ile razy draft był czytany — użycie SAMEGO `version` jako tokenu
//     blokady NIE wykryłoby dwóch handlowców kończących TEN SAM świeży lead-draft
//     (obaj czytają version=1, obaj piszą version=1 — WHERE version=1 pasuje
//     OBU zapisom). `lock_version` (odrębna kolumna, rośnie bezwarunkowo) musi
//     to złapać: drugi zapis — ze STAŁYM odczytem sprzed zapisu pierwszego — ma
//     zostać zablokowany, NIE nadpisać cicho danych pierwszego handlowca.
$GLOBALS['wpdb']->offers = array(
	41 => array(
		'id'           => 41,
		'status'       => 'draft',
		'offer_number' => null,
		'version'      => 1,
		'lock_version' => 1, // OBAJ handlowcy czytają tę samą, jeszcze niezmienioną wartość.
		'created_by'   => null,
		'client_name'  => null,
	),
);
$GLOBALS['wpdb']->items = array();
$plan_a                 = array(
	'header'                => array(
		'id'           => 41,
		'offer_number' => 'OF/2026/000041',
		'version'      => 1,
		'lock_version' => 2,
		'client_name'  => 'Dane od Handlowca A',
		'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
	),
	'items'                 => array( array( 'product_id' => 100, 'qty' => 1 ) ),
	'version'               => array( 'version_number' => 1 ),
	'log'                   => array( 'action' => 'offer.created' ),
	'expected_lock_version' => 1, // odczyt SPRZED zapisu kogokolwiek.
);
$result_a                = ( new MP_OB_D10_Agent_Transaction() )->run( new MP_OB_Context( array( 'write_plan' => $plan_a ) ) );

// Handlowiec B budował swój plan z TEGO SAMEGO, już nieaktualnego odczytu
// (lock_version=1) — nie wie, że A właśnie zapisał.
$plan_b    = array(
	'header'                => array(
		'id'           => 41,
		'offer_number' => 'OF/2026/000041',
		'version'      => 1,
		'lock_version' => 2, // B liczy TAK SAMO: 1 (stary odczyt) + 1.
		'client_name'  => 'Dane od Handlowca B',
		'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
	),
	'items'                 => array( array( 'product_id' => 200, 'qty' => 1 ) ),
	'version'               => array( 'version_number' => 1 ),
	'log'                   => array( 'action' => 'offer.created' ),
	'expected_lock_version' => 1,
);
$result_b  = ( new MP_OB_D10_Agent_Transaction() )->run( new MP_OB_Context( array( 'write_plan' => $plan_b ) ) );
$items_41  = array_values(
	array_filter(
		$GLOBALS['wpdb']->items,
		function ( $row ) {
			return 41 === (int) ( $row['offer_id'] ?? 0 );
		}
	)
);
$inv96 = $result_a->is_ok()
	&& ! $result_b->is_ok()
	&& 'concurrent_modification' === $result_b->get_code()
	&& 'Dane od Handlowca A' === ( $GLOBALS['wpdb']->offers[41]['client_name'] ?? null )
	&& 1 === count( $items_41 )
	&& 100 === ( $items_41[0]['product_id'] ?? null );
record(
	'inv96_blokada_optymistyczna_pierwsze_ponumerowanie_draftu',
	$inv96 ? 'PASS' : 'FAIL',
	'a_ok=' . ( $result_a->is_ok() ? 'tak' : 'nie' ) . ' b_code=' . $result_b->get_code() . ' klient_niezmieniony=' . ( 'Dane od Handlowca A' === ( $GLOBALS['wpdb']->offers[41]['client_name'] ?? null ) ? 'tak' : 'nie' )
);
$GLOBALS['wpdb']->offers = array();

/* ---------- Krok 4.2: created_by (Agent 10.1 + Dział 2 Agent 2.5) ---------- */

echo "\n=== KROK 4.2: WŁAŚCICIEL OFERTY (created_by) ===\n";

// 73) Nowa oferta (bez offer_id): created_by = bieżący zalogowany użytkownik.
$GLOBALS['wpdb']->offers                = array();
$GLOBALS['wpdb']->items                 = array();
$GLOBALS['wpdb']->versions              = array();
$GLOBALS['wpdb']->activity_log          = array();
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 55;
$r73                                     = run_pipeline( base_input() );
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 0;
$offer_id_73                            = $r73['final_data']['offer_id'] ?? 0;
$inv73                                  = $r73['ok'] && 55 === (int) ( $GLOBALS['wpdb']->offers[ $offer_id_73 ]['created_by'] ?? 0 );
record(
	'inv73_nowa_oferta_created_by_biezacy_uzytkownik',
	$inv73 ? 'PASS' : 'FAIL',
	'created_by=' . ( $GLOBALS['wpdb']->offers[ $offer_id_73 ]['created_by'] ?? '-' )
);

// 74) Pierwsze dokończenie draftu z Kroku 2.5 (created_by dziś NULL) -> ustawiane
//     PRZY PIERWSZYM zapisie na bieżącego użytkownika.
$GLOBALS['wpdb']->offers       = array(
	21 => array(
		'id'             => 21,
		'status'         => 'draft',
		'lead_id'        => 700,
		'created_by'     => null,
		'client_name'    => 'Klient Bez Wlasciciela Sp. z o.o.',
		'client_email'   => 'bez-wlasciciela@testowa-firma.pl',
		'client_nip'     => '1234563218',
		'client_country' => 'PL',
	),
);
$GLOBALS['wpdb']->items                    = array();
$GLOBALS['wpdb']->versions                 = array();
$GLOBALS['wpdb']->activity_log             = array();
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 77;
$draft74_input                             = base_input();
$draft74_input['offer_id']                 = 21;
unset( $draft74_input['client'] );
$r74 = run_pipeline( $draft74_input );
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 0;
$inv74 = $r74['ok'] && 77 === (int) ( $GLOBALS['wpdb']->offers[21]['created_by'] ?? 0 );
record( 'inv74_pierwsze_dokonczenie_draftu_ustawia_wlasciciela', $inv74 ? 'PASS' : 'FAIL', 'created_by=' . ( $GLOBALS['wpdb']->offers[21]['created_by'] ?? '-' ) );

// 75) Korekta oferty z JUŻ USTAWIONYM created_by -> wartość zachowana, NAWET gdy
//     zapisuje ją zalogowany INNY handlowiec (pierwszy właściciel zostaje na stałe).
$d10_2_year               = (int) gmdate( 'Y' );
$GLOBALS['wpdb']->offers  = array(
	22 => array(
		'id'             => 22,
		'status'         => 'draft',
		'offer_number'   => sprintf( 'OF/%d/000030', $d10_2_year ),
		'version'        => 1,
		'created_by'     => 33,
		'client_name'    => 'Klient Z Wlascicielem Sp. z o.o.',
		'client_email'   => 'z-wlascicielem@testowa-firma.pl',
		'client_nip'     => '1234563218',
		'client_country' => 'PL',
	),
);
$GLOBALS['wpdb']->items                    = array();
$GLOBALS['wpdb']->versions                 = array();
$GLOBALS['wpdb']->activity_log             = array();
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 99;
$correction75_input                        = base_input();
$correction75_input['offer_id']            = 22;
unset( $correction75_input['client'] );
$r75 = run_pipeline( $correction75_input );
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 0;
$inv75 = $r75['ok'] && 33 === (int) ( $GLOBALS['wpdb']->offers[22]['created_by'] ?? 0 );
record( 'inv75_korekta_wlasciciel_zachowany_bez_zmian', $inv75 ? 'PASS' : 'FAIL', 'created_by=' . ( $GLOBALS['wpdb']->offers[22]['created_by'] ?? '-' ) );
$GLOBALS['wpdb']->offers = array();

// 95) Korekta CUDZEJ oferty (created_by=33) przez INNEGO, NIE-adminowego
//     użytkownika -> BLOKADA (IDOR). inv75 powyżej NIE testował tej granicy
//     naprawdę: current_user_can() w harnessie domyślnie zwraca true dla
//     KAŻDEJ capability (patrz wp-stubs.php) dopóki nie ustawimy denied_caps,
//     więc user 99 tam był traktowany jak administrator — test PRZECHODZIŁ,
//     ale nie dlatego, że blokada IDOR faktycznie zadziałała. Tu jawnie
//     odbieramy manage_options, żeby naprawdę zweryfikować fix z Działu 1.
$GLOBALS['wpdb']->offers = array(
	22 => array(
		'id'             => 22,
		'status'         => 'draft',
		'offer_number'   => sprintf( 'OF/%d/000030', $d10_2_year ),
		'version'        => 1,
		'created_by'     => 33,
		'client_name'    => 'Klient Z Wlascicielem Sp. z o.o.',
		'client_email'   => 'z-wlascicielem@testowa-firma.pl',
		'client_nip'     => '1234563218',
		'client_country' => 'PL',
	),
);
$GLOBALS['wpdb']->items                    = array();
$GLOBALS['wpdb']->versions                 = array();
$GLOBALS['wpdb']->activity_log             = array();
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 99;
$GLOBALS['__mp_ob_cfg']['denied_caps']     = array( 'manage_options' => true );
$idor95_input                              = base_input();
$idor95_input['offer_id']                  = 22;
unset( $idor95_input['client'] );
$r95                                        = run_pipeline( $idor95_input );
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 0;
$GLOBALS['__mp_ob_cfg']['denied_caps']     = array();
$inv95                                      = ! $r95['ok']
	// SR1-01: korekta CUDZEJ oferty nadal ZABLOKOWANA, ale kod jest teraz celowo
	// nierozróżnialny od "szkic nie istnieje" (invalid_contract), żeby sekwencyjny
	// offer_id nie stał się wyrocznią enumeracji cudzych szkiców (wcześniej 'forbidden').
	&& 'invalid_contract' === $r95['code']
	&& 33 === (int) ( $GLOBALS['wpdb']->offers[22]['created_by'] ?? 0 ) // właściciel niezmieniony.
	&& 'Klient Z Wlascicielem Sp. z o.o.' === ( $GLOBALS['wpdb']->offers[22]['client_name'] ?? '' ); // dane NIE nadpisane.
record(
	'inv95_korekta_cudzej_oferty_bez_manage_options_blokada_idor',
	$inv95 ? 'PASS' : 'FAIL',
	'ok=' . ( $r95['ok'] ? 'tak(BLAD)' : 'nie' ) . ' code=' . $r95['code']
);
$GLOBALS['wpdb']->offers = array();

// 83) Korekta oferty: STARE pozycje (z poprzedniej wersji tej samej oferty)
//     muszą zniknąć z wp_mp_ob_offer_items, a nie zostać OBOK nowych — inaczej
//     każda kolejna korekta tylko dopisywałaby wiersze, dublując pozycje.
$GLOBALS['wpdb']->offers = array(
	23 => array(
		'id'             => 23,
		'status'         => 'draft',
		'offer_number'   => sprintf( 'OF/%d/000031', $d10_2_year ),
		'version'        => 1,
		'created_by'     => 33,
		'client_name'    => 'Klient Korekta Pozycji Sp. z o.o.',
		'client_email'   => 'korekta-pozycji@testowa-firma.pl',
		'client_nip'     => '1234563218',
		'client_country' => 'PL',
	),
);
$GLOBALS['wpdb']->items = array(
	array(
		'id'         => 9001,
		'offer_id'   => 23,
		'product_id' => 555,
		'qty'        => 1,
	),
);
$GLOBALS['wpdb']->versions                 = array();
$GLOBALS['wpdb']->activity_log             = array();
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 33;
$correction83_input                        = base_input();
$correction83_input['offer_id']            = 23;
unset( $correction83_input['client'] );
$r83 = run_pipeline( $correction83_input );
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 0;
$items_for_23  = array_values(
	array_filter(
		$GLOBALS['wpdb']->items,
		function ( $row ) {
			return 23 === (int) ( $row['offer_id'] ?? 0 );
		}
	)
);
$product_ids_23 = array_column( $items_for_23, 'product_id' );
$inv83          = $r83['ok']
	&& 1 === count( $items_for_23 )
	&& ! in_array( 555, $product_ids_23, true )
	&& in_array( 812, $product_ids_23, true );
record(
	'inv83_korekta_usuwa_stare_pozycje_przed_wstawieniem_nowych',
	$inv83 ? 'PASS' : 'FAIL',
	'pozycji_po_korekcie=' . count( $items_for_23 ) . ' stary_product_id_555_obecny=' . ( in_array( 555, $product_ids_23, true ) ? 'tak' : 'nie' )
);
$GLOBALS['wpdb']->offers = array();

// 97) Niesprawdzony DELETE starych pozycji przy korekcie (Medium): jeśli
//     $wpdb->delete() zawiedzie (błąd SQL), stare pozycje zostają NA MIEJSCU,
//     a nowe i tak zostałyby dopisane obok nich, gdyby zapis kontynuował —
//     cichy powrót Critical#2 pod inną postacią. Musi zatrzymać cały zapis.
$GLOBALS['wpdb']->offers = array(
	24 => array(
		'id'             => 24,
		'status'         => 'draft',
		'offer_number'   => sprintf( 'OF/%d/000032', (int) gmdate( 'Y' ) ),
		'version'        => 1,
		'lock_version'   => 1,
		'created_by'     => 33,
		'client_name'    => 'Klient Awaria Delete Sp. z o.o.',
		'client_email'   => 'awaria-delete@testowa-firma.pl',
		'client_nip'     => '1234563218',
		'client_country' => 'PL',
	),
);
$GLOBALS['wpdb']->items = array(
	array(
		'id'         => 9002,
		'offer_id'   => 24,
		'product_id' => 666,
		'qty'        => 1,
	),
);
$GLOBALS['wpdb']->versions                    = array();
$GLOBALS['wpdb']->activity_log                = array();
$GLOBALS['wpdb']->force_items_delete_fail     = true;
$GLOBALS['__mp_ob_cfg']['current_user_id']    = 33;
$correction97_input                           = base_input();
$correction97_input['offer_id']               = 24;
unset( $correction97_input['client'] );
$r97 = run_pipeline( $correction97_input );
$GLOBALS['__mp_ob_cfg']['current_user_id'] = 0;
$GLOBALS['wpdb']->force_items_delete_fail  = false;
$items_for_24                              = array_values(
	array_filter(
		$GLOBALS['wpdb']->items,
		function ( $row ) {
			return 24 === (int) ( $row['offer_id'] ?? 0 );
		}
	)
);
$inv97 = ! $r97['ok']
	&& 'write_failed' === $r97['code']
	&& 1 === count( $items_for_24 )
	&& 666 === ( $items_for_24[0]['product_id'] ?? null ); // stara pozycja NIE usunięta, nowa NIE dopisana obok niej.
record(
	'inv97_niesprawdzony_delete_starych_pozycji_stop',
	$inv97 ? 'PASS' : 'FAIL',
	'code=' . $r97['code'] . ' pozycji_po_probie=' . count( $items_for_24 )
);
$GLOBALS['wpdb']->offers = array();

/* ---------- Dział 11: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 11: REALNA LOGIKA (odpowiedź i przekazanie) ===\n";

// 62) Happy path ($hp — pełny przebieg, WŁĄCZNIE z Działem 11): odpowiedź ma
//     dokładnie biały zestaw pól, pdf_url wskazuje na chroniony endpoint
//     (nie bezpośredni plik), a plik PDF jest już pod nazwą DOCELOWĄ (nie tmp).
$response62   = $hp['final_data']['response'] ?? array();
$final_path62 = MP_Offer_Builder_Storage::final_pdf_path( $hp['final_data']['offer_number'] ?? '', $hp['final_data']['version'] ?? 0 );
$inv62        = $hp['ok']
	&& array( 'success', 'offer_id', 'offer_number', 'version', 'pdf_url', 'status', 'trace_id' ) === array_keys( $response62 )
	&& true === ( $response62['success'] ?? null )
	&& 'draft' === ( $response62['status'] ?? '' )
	&& false !== strpos( (string) ( $response62['pdf_url'] ?? '' ), MP_OB_D11_Agent_Response::DOWNLOAD_ACTION )
	&& false !== strpos( (string) ( $response62['pdf_url'] ?? '' ), '_wpnonce=' )
	&& file_exists( $final_path62 )
	&& ! file_exists( $hp['final_data']['pdf']['tmp_path'] ?? '' );
record(
	'inv62_happy_path_odpowiedz_i_finalizacja_pdf',
	$inv62 ? 'PASS' : 'FAIL',
	'klucze=' . implode( ',', array_keys( $response62 ) ) . ' plik_docelowy_istnieje=' . ( file_exists( $final_path62 ) ? 'tak' : 'nie' )
);

// 85) Nazwa pliku PDF NIE jest odgadywalna z samego offer_number/version —
//     musi zawierać token HMAC (per-instalację), nie tylko "numer-vN.pdf"
//     (High: obrona w głąb, .htaccess nie działa na nginx — patrz docblock
//     final_pdf_path()). Ten sam offer_number/version -> ta sama nazwa
//     (Dział 10 i Dział 11 muszą policzyć identyczną ścieżkę).
$naive_name62  = preg_replace( '/[^A-Za-z0-9]+/', '-', trim( (string) ( $hp['final_data']['offer_number'] ?? '' ), '-' ) ) . '-v' . (int) ( $hp['final_data']['version'] ?? 0 ) . '.pdf';
$actual_name62 = basename( $final_path62 );
$repeat_path62 = MP_Offer_Builder_Storage::final_pdf_path( $hp['final_data']['offer_number'] ?? '', $hp['final_data']['version'] ?? 0 );
$inv85         = $naive_name62 !== $actual_name62
	&& 1 === preg_match( '/^.+-v\d+-[0-9a-f]{20}\.pdf$/', $actual_name62 )
	&& $repeat_path62 === $final_path62;
record(
	'inv85_nazwa_pliku_pdf_nieodgadywalna_token_hmac',
	$inv85 ? 'PASS' : 'FAIL',
	'nazwa=' . $actual_name62 . ' deterministyczna=' . ( $repeat_path62 === $final_path62 ? 'tak' : 'nie' )
);

// 90) finalize_pdf() zwraca false, gdy rename() się nie powiedzie (Medium) —
//     wymuszone przez PRE-UTWORZENIE ścieżki docelowej JAKO KATALOGU: rename
//     pliku na istniejący katalog zawsze zawodzi (EISDIR), niezależnie od
//     uprawnień/roota — przenośny sposób symulacji awarii I/O w teście.
$fp90_offer_number = 'OF/2026/000090';
$fp90_version      = 1;
$fp90_final_path   = MP_Offer_Builder_Storage::final_pdf_path( $fp90_offer_number, $fp90_version );
mkdir( $fp90_final_path, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
$fp90_tmp_path = MP_Offer_Builder_Storage::write_tmp_pdf( '%PDF-1.4 test finalize fail' );
$fp90_result   = MP_Offer_Builder_Storage::finalize_pdf( $fp90_tmp_path, $fp90_offer_number, $fp90_version );
$inv90         = false === $fp90_result && file_exists( $fp90_tmp_path );
record( 'inv90_finalize_pdf_zwraca_false_gdy_rename_zawiedzie', $inv90 ? 'PASS' : 'FAIL', 'wynik=' . var_export( $fp90_result, true ) . ' tmp_nadal_istnieje=' . ( file_exists( $fp90_tmp_path ) ? 'tak' : 'nie' ) );
rmdir( $fp90_final_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
unlink( $fp90_tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

// 91) Agent 11.1: gdy finalize_pdf() zawiedzie, STOP z jawnym kodem PRZED
//     wystawieniem mp_offer_created — inaczej plugin 3 dostałby zdarzenie dla
//     oferty, której PDF nigdy nie trafił pod nazwę docelową (oferta w bazie
//     jest już ZAPISANA — Dział 10 skończył przed COMMIT — więc jedyna obrona
//     to zatrzymać się TU, zanim zdarzenie wyjdzie na zewnątrz).
$fp91_offer_number = 'OF/2026/000091';
$fp91_version      = 1;
$fp91_final_path   = MP_Offer_Builder_Storage::final_pdf_path( $fp91_offer_number, $fp91_version );
mkdir( $fp91_final_path, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
$fp91_tmp_path    = MP_Offer_Builder_Storage::write_tmp_pdf( '%PDF-1.4 test agent 11.1 finalize fail' );
$fp91_before_fire = did_action( MP_OB_D11_Agent_Event::HOOK );
$fp91_ctx                   = new MP_OB_Context(
	array(
		'offer_id'     => 91,
		'offer_number' => $fp91_offer_number,
		'version'      => $fp91_version,
		'pdf'          => array( 'tmp_path' => $fp91_tmp_path ),
	)
);
$fp91_result      = ( new MP_OB_D11_Agent_Event() )->run( $fp91_ctx );
$fp91_after_fire  = did_action( MP_OB_D11_Agent_Event::HOOK );
$inv91            = ! $fp91_result->is_ok()
	&& 'pdf_finalize_failed' === $fp91_result->get_code()
	&& $fp91_before_fire === $fp91_after_fire; // zdarzenie NIE wystawione.
record(
	'inv91_agent_11_1_stop_przed_zdarzeniem_gdy_finalize_zawiedzie',
	$inv91 ? 'PASS' : 'FAIL',
	'code=' . $fp91_result->get_code() . ' zdarzenie_wystawione=' . ( $fp91_after_fire > $fp91_before_fire ? 'tak(BLAD)' : 'nie' )
);
rmdir( $fp91_final_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
unlink( $fp91_tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

// 63) Zdarzenie mp_offer_created RZECZYWIŚCIE wystawione (did_action > 0 po
//     happy-path) — nie tylko "zwrócony kod bez efektu".
$inv63 = did_action( 'mp_offer_created' ) > 0;
record( 'inv63_zdarzenie_mp_offer_created_realnie_wystawione', $inv63 ? 'PASS' : 'FAIL', 'did_action=' . did_action( 'mp_offer_created' ) );

// 64) Agent 11.1: brak potwierdzonej oferty (Dział 10 nie przebiegł/zawiódł) →
//     STOP, NIGDY zdarzenie dla oferty-widma.
$no_offer_result = ( new MP_OB_D11_Agent_Event() )->run( new MP_OB_Context( array() ) );
$inv64             = ! $no_offer_result->is_ok() && 'missing_committed_offer' === $no_offer_result->get_code();
record( 'inv64_zdarzenie_brak_potwierdzonej_oferty_stop', $inv64 ? 'PASS' : 'FAIL', 'code=' . $no_offer_result->get_code() );

// 65) Krytyk 11.1 "jednokrotność": zdarzenie wystawione WIĘCEJ niż raz
//     (fabrykowany wynik — np. bug podwójnego wywołania do_action) → STOP.
$twice_critic = new MP_OB_D11_Critic_Once( 'K11.1', 'test' );
$twice_result = $twice_critic->review( MP_OB_Result::ok( array( 'event_fire_count' => 2 ) ), new MP_OB_Context( array() ) );
$inv65          = ! $twice_result->is_ok() && 'event_not_fired_once' === $twice_result->get_code();
record( 'inv65_jednokrotnosc_wiecej_niz_raz_stop', $inv65 ? 'PASS' : 'FAIL', 'code=' . $twice_result->get_code() );

// 66) Agent 11.2: brak danych z Agenta 11.1 (nie przebiegł wcześniej) → STOP.
$no_data_result = ( new MP_OB_D11_Agent_Response() )->run( new MP_OB_Context( array( 'offer_id' => 1 ) ) );
$inv66            = ! $no_data_result->is_ok() && 'missing_response_data' === $no_data_result->get_code();
record( 'inv66_odpowiedz_brak_danych_z_11_1_stop', $inv66 ? 'PASS' : 'FAIL', 'code=' . $no_data_result->get_code() );

// 67) Krytyk 11.2 "zakres-odpowiedzi": odpowiedź z WYCIEKIEM pola spoza
//     schematu (np. ścieżka serwera) → STOP, "bez ścieżek serwera".
$leak_critic = new MP_OB_D11_Critic_Response_Scope( 'K11.2', 'test' );
$leak_result = $leak_critic->review(
	MP_OB_Result::ok(
		array(
			'response' => array(
				'success'      => true,
				'offer_id'     => 1,
				'offer_number' => 'OF/2026/000001',
				'version'      => 1,
				'pdf_url'      => 'http://x/y',
				'status'       => 'draft',
				'trace_id'     => 'x',
				'server_path'  => '/var/www/wp-content/uploads/mp-offer-builder-private/x.pdf', // WYCIEK.
			),
		)
	),
	new MP_OB_Context( array() )
);
$inv67 = ! $leak_result->is_ok() && 'response_out_of_scope' === $leak_result->get_code();
record( 'inv67_zakres_odpowiedzi_wyciek_pola_stop', $inv67 ? 'PASS' : 'FAIL', 'code=' . $leak_result->get_code() );

// 68) QA Agent 11 "tylko-json": plik PDF WCIĄŻ pod nazwą tymczasową (finalizacja
//     się nie powiodła/nie zaszła) → STOP, "nic nie wisi w tle po odpowiedzi".
$not_finalized_tmp = MP_Offer_Builder_Storage::tmp_dir() . '/of-still-tmp-' . uniqid() . '.pdf';
file_put_contents( $not_finalized_tmp, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$not_finalized_ctx    = new MP_OB_Context(
	array(
		'response' => array( 'success' => true, 'trace_id' => 'x' ),
		'pdf'      => array( 'tmp_path' => $not_finalized_tmp ),
	)
);
$not_finalized_result = ( new MP_OB_D11_QA_Agent() )->run( $not_finalized_ctx );
$inv68                  = ! $not_finalized_result->is_ok() && 'pdf_not_finalized' === $not_finalized_result->get_code();
record( 'inv68_tylko_json_plik_nadal_tymczasowy_stop', $inv68 ? 'PASS' : 'FAIL', 'code=' . $not_finalized_result->get_code() );
unlink( $not_finalized_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink

/* ---------- Krok 2.5: integracja z pluginem 1 (mp_lead_created → draft) ---------- */

echo "\n=== KROK 2.5: AUTOMATYCZNY DRAFT Z LEADA ===\n";

// Kontrakt payloadu = dokładnie to, co realnie emituje plugin 1 w
// class-mp-department-11.php (Agent 11.3), zweryfikowane w kodzie 2026-07-23.
function lead_payload( $overrides = array() ) {
	return array_merge(
		array(
			'lead_id'         => 501,
			'company_name'    => 'Testowa Firma Sp. z o.o.',
			'nip'             => '1234563218',
			'email'           => 'kontakt@testowa-firma.pl',
			'phone'           => '+48 600 100 200',
			'country'         => 'PL',
			'segment'         => 'IT',
			'client_category' => 'standard',
			'score'           => 80,
			'status'          => 'new',
			'vat_status'      => 'checked',
			'salesman_id'     => 12,
		),
		$overrides
	);
}

// 7) WIRING: register() realnie łączy hook 'mp_lead_created' z on_lead_created()
//    (nie wołamy metody wprost — to samo ryzyko co w pluginie 1, Runda 5: literówka
//    w nazwie hooka byłaby niewykrywalna, gdyby test omijał system hooków WP).
$GLOBALS['wpdb']->offers       = array();
$GLOBALS['wpdb']->activity_log = array();
MP_Offer_Builder_Lead_Listener::register();
do_action( 'mp_lead_created', 501, lead_payload() );

$draft = null;
foreach ( $GLOBALS['wpdb']->offers as $row ) {
	if ( 501 === (int) ( $row['lead_id'] ?? 0 ) ) {
		$draft = $row;
		break;
	}
}
$inv7 = null !== $draft
	&& 'draft' === ( $draft['status'] ?? '' )
	&& null === ( $draft['offer_number'] ?? null )
	&& 'Testowa Firma Sp. z o.o.' === ( $draft['client_name'] ?? '' )
	&& '1234563218' === ( $draft['client_nip'] ?? '' );
record(
	'inv7_wiring_mp_lead_created_tworzy_draft',
	$inv7 ? 'PASS' : 'FAIL',
	$draft ? ( 'status=' . $draft['status'] . ' client_name=' . $draft['client_name'] ) : 'brak wiersza draft'
);

// 8) Dziennik BD-2: draft_created_from_lead zapisany dokładnie raz.
$inv8 = 1 === count( $GLOBALS['wpdb']->activity_log )
	&& 'draft_created_from_lead' === ( $GLOBALS['wpdb']->activity_log[0]['action'] ?? '' );
record( 'inv8_dziennik_draft_created_from_lead', $inv8 ? 'PASS' : 'FAIL', 'wiersze=' . count( $GLOBALS['wpdb']->activity_log ) );

// 9) Idempotencja: reaktywacja tego samego leada (ten sam lead_id, hook odpalony
//    ponownie) NIE zakłada drugiego draftu.
do_action( 'mp_lead_created', 501, lead_payload() );
$count_for_lead = 0;
foreach ( $GLOBALS['wpdb']->offers as $row ) {
	if ( 501 === (int) ( $row['lead_id'] ?? 0 ) ) {
		++$count_for_lead;
	}
}
$inv9 = 1 === $count_for_lead;
record( 'inv9_idempotencja_bez_duplikatu_draftu', $inv9 ? 'PASS' : 'FAIL', 'draftow_dla_lead_id_501=' . $count_for_lead );

/* ---------- Krok 4.1: BD-2 — lista ofert (list_offers/count_offers) ---------- */

echo "\n=== KROK 4.1: LISTA OFERT (list_offers/count_offers) ===\n";

$GLOBALS['wpdb']->offers = array(
	1 => array(
		'id'           => 1,
		'status'       => 'draft',
		'created_by'   => 10,
		'client_name'  => 'Alfa Sp. z o.o.',
		'client_nip'   => '1111111111',
		'offer_number' => null,
		'updated_at'   => '2026-01-01 10:00:00',
	),
	2 => array(
		'id'           => 2,
		'status'       => 'sent',
		'created_by'   => 10,
		'client_name'  => 'Beta Sp. z o.o.',
		'client_nip'   => '2222222222',
		'offer_number' => 'OF/2026/000001',
		'updated_at'   => '2026-01-02 10:00:00',
	),
	3 => array(
		'id'           => 3,
		'status'       => 'draft',
		'created_by'   => 20,
		'client_name'  => 'Gamma Sp. z o.o.',
		'client_nip'   => '3333333333',
		'offer_number' => null,
		'updated_at'   => '2026-01-03 10:00:00',
	),
	4 => array(
		'id'           => 4,
		'status'       => 'completed',
		'created_by'   => 20,
		'client_name'  => 'Delta Sp. z o.o.',
		'client_nip'   => '4444444444',
		'offer_number' => 'OF/2026/000002',
		'updated_at'   => '2026-01-04 10:00:00',
	),
);

// 69) Filtr status: 'draft' -> tylko oferty 1 i 3, count_offers() zgodny z list_offers().
$draft_rows = MP_Offer_Builder_DB::list_offers( array( 'status' => 'draft' ) );
$draft_ids  = array_map( function ( $r ) {
	return (int) $r['id']; }, $draft_rows );
sort( $draft_ids );
$inv69 = array( 1, 3 ) === $draft_ids && 2 === MP_Offer_Builder_DB::count_offers( array( 'status' => 'draft' ) );
record( 'inv69_lista_ofert_filtr_status', $inv69 ? 'PASS' : 'FAIL', 'ids=' . implode( ',', $draft_ids ) . ' count=' . MP_Offer_Builder_DB::count_offers( array( 'status' => 'draft' ) ) );

// 70) Filtr created_by: 20 -> tylko oferty 3 i 4 (izolacja handlowca od cudzych ofert).
$owner_rows = MP_Offer_Builder_DB::list_offers( array( 'created_by' => 20 ) );
$owner_ids  = array_map( function ( $r ) {
	return (int) $r['id']; }, $owner_rows );
sort( $owner_ids );
$inv70 = array( 3, 4 ) === $owner_ids && 2 === MP_Offer_Builder_DB::count_offers( array( 'created_by' => 20 ) );
record( 'inv70_lista_ofert_filtr_created_by', $inv70 ? 'PASS' : 'FAIL', 'ids=' . implode( ',', $owner_ids ) );

// 71) Search: po nazwie klienta ("Beta") i po NIP ("4444444444") — trafia właściwa
//     pojedyncza oferta w obu przypadkach (kolumny client_name/client_nip/offer_number).
$search_name = MP_Offer_Builder_DB::list_offers( array( 'search' => 'Beta' ) );
$search_nip  = MP_Offer_Builder_DB::list_offers( array( 'search' => '4444444444' ) );
$inv71       = 1 === count( $search_name ) && 2 === (int) $search_name[0]['id']
	&& 1 === count( $search_nip ) && 4 === (int) $search_nip[0]['id'];
record(
	'inv71_lista_ofert_search_nazwa_i_nip',
	$inv71 ? 'PASS' : 'FAIL',
	'search_beta_id=' . ( $search_name[0]['id'] ?? '-' ) . ' search_nip_id=' . ( $search_nip[0]['id'] ?? '-' )
);

// 72) Paginacja: per_page=2 zwraca 2 strony po 2 (posortowane malejąco po updated_at,
//     domyślnie), count_offers() bez filtrów = 4 (cała populacja, NIEZALEŻNIE od per_page).
$page1 = MP_Offer_Builder_DB::list_offers( array( 'per_page' => 2, 'offset' => 0 ) );
$page2 = MP_Offer_Builder_DB::list_offers( array( 'per_page' => 2, 'offset' => 2 ) );
$page1_ids = array_map( function ( $r ) {
	return (int) $r['id']; }, $page1 );
$page2_ids = array_map( function ( $r ) {
	return (int) $r['id']; }, $page2 );
$inv72     = array( 4, 3 ) === $page1_ids && array( 2, 1 ) === $page2_ids
	&& 4 === MP_Offer_Builder_DB::count_offers();
record(
	'inv72_lista_ofert_paginacja_i_count_niezalezny_od_per_page',
	$inv72 ? 'PASS' : 'FAIL',
	'strona1=' . implode( ',', $page1_ids ) . ' strona2=' . implode( ',', $page2_ids ) . ' count=' . MP_Offer_Builder_DB::count_offers()
);
$GLOBALS['wpdb']->offers = array();

/* ---------- Krok 4.3: endpoint pobierania PDF — autoryzacja (can_download) ---------- */

echo "\n=== KROK 4.3: AUTORYZACJA POBIERANIA PDF (can_download) ===\n";

// 76) Administrator (manage_options) pobiera KAŻDĄ ofertę, niezależnie od created_by.
$GLOBALS['__mp_ob_cfg']['denied_caps'] = array();
$owned_by_5                             = array( 'created_by' => 5 );
$inv76                                  = true === MP_Offer_Builder_Download::can_download( $owned_by_5, 999 );
record( 'inv76_admin_pobiera_kazda_oferte', $inv76 ? 'PASS' : 'FAIL', 'can_download=' . var_export( MP_Offer_Builder_Download::can_download( $owned_by_5, 999 ), true ) );

// 77) Twórca oferty (created_by === user_id), BEZ manage_options -> dostęp.
$GLOBALS['__mp_ob_cfg']['denied_caps'] = array( 'manage_options' => true );
$inv77                                  = true === MP_Offer_Builder_Download::can_download( $owned_by_5, 5 );
record( 'inv77_wlasciciel_pobiera_swoja_oferte', $inv77 ? 'PASS' : 'FAIL', 'can_download=' . var_export( MP_Offer_Builder_Download::can_download( $owned_by_5, 5 ), true ) );

// 78) Inny handlowiec (created_by !== user_id), BEZ manage_options -> ODMOWA
//     (decyzja własności ofert, Krok 4 — cudza oferta jest niewidoczna).
$inv78 = false === MP_Offer_Builder_Download::can_download( $owned_by_5, 6 );
record( 'inv78_obcy_handlowiec_bez_dostepu', $inv78 ? 'PASS' : 'FAIL', 'can_download=' . var_export( MP_Offer_Builder_Download::can_download( $owned_by_5, 6 ), true ) );

// 79) created_by NULL (np. stary/nieoczekiwany wiersz), BEZ manage_options -> ODMOWA,
//     NIGDY ciche dopasowanie "brak właściciela = każdy może".
$inv79 = false === MP_Offer_Builder_Download::can_download( array( 'created_by' => null ), 6 );
record( 'inv79_brak_wlasciciela_bez_dostepu', $inv79 ? 'PASS' : 'FAIL', 'can_download=' . var_export( MP_Offer_Builder_Download::can_download( array( 'created_by' => null ), 6 ), true ) );
$GLOBALS['__mp_ob_cfg']['denied_caps'] = array();

/* ---------- Krok 4.5: wyszukiwanie produktów (search_products) ---------- */

echo "\n=== KROK 4.5: WYSZUKIWANIE PRODUKTÓW (search_products) ===\n";

seed_woocommerce_fixtures();
$GLOBALS['__mp_ob_wc_products'][9102] = array(
	'status'        => 'publish',
	'name'          => 'Inny produkt testowy',
	'tax_class'     => '',
	'purchasable'   => true,
	'regular_price' => '49.99',
	'sale_price'    => '',
);
$GLOBALS['__mp_ob_wc_products'][9103] = array(
	'status'        => 'draft', // nieopublikowany - NIE powinien trafić do wyników.
	'name'          => 'Testowy ukryty produkt',
	'tax_class'     => '',
	'purchasable'   => true,
	'regular_price' => '19.99',
	'sale_price'    => '',
);

// 80) Fraza "testowy" (bez rozróżniania wielkości liter) pasuje do nazw DWÓCH
//     opublikowanych produktów (9101 "Testowy wariant", 9102 "Inny produkt
//     testowy") - 9103 "Testowy ukryty produkt" jest 'draft', więc pominięty
//     mimo pasującej nazwy - tylko 'publish' trafia do wyniku.
$found_testowy = MP_Offer_Builder_Admin::search_products( 'Testowy' );
$found_ids     = array_map( function ( $r ) {
	return $r['id']; }, $found_testowy );
sort( $found_ids );
$inv80 = array( 9101, 9102 ) === $found_ids;
record( 'inv80_wyszukiwanie_tylko_opublikowane_dopasowanie_nazwy', $inv80 ? 'PASS' : 'FAIL', 'ids=' . implode( ',', $found_ids ) );

// 81) Fraza "Inny" pasuje dokładnie do jednego produktu - id/name/price w wyniku.
$found_inny = MP_Offer_Builder_Admin::search_products( 'Inny' );
$inv81      = 1 === count( $found_inny )
	&& 9102 === $found_inny[0]['id']
	&& 'Inny produkt testowy' === $found_inny[0]['name']
	&& '49.99' === $found_inny[0]['price'];
record( 'inv81_wyszukiwanie_pojedynczy_wynik_pola_kompletne', $inv81 ? 'PASS' : 'FAIL', 'wynik=' . wp_json_encode( $found_inny ) );

// 82) Pusta fraza -> pusty wynik (bez zwracania całego katalogu).
$inv82 = array() === MP_Offer_Builder_Admin::search_products( '' );
record( 'inv82_wyszukiwanie_pusta_fraza_pusty_wynik', $inv82 ? 'PASS' : 'FAIL', 'count=' . count( MP_Offer_Builder_Admin::search_products( '' ) ) );
seed_woocommerce_fixtures();

/* ---------- Granica AJAX: sanityzacja client/items (Medium) ---------- */

echo "\n=== GRANICA AJAX: SANITYZACJA CLIENT/ITEMS ===\n";

// 92) sanitize_client(): tagi/znaki sterujące usunięte, klucze spoza whitelisty
//     odrzucone, pola spoza listy dozwolonych (np. "extra") zignorowane.
$dirty_client     = array(
	'name'    => "<script>alert(1)</script>Firma \t\n Testowa",
	'email'   => 'kontakt@testowa-firma.pl',
	'nip'     => '1234563218',
	'country' => 'PL',
	'extra'   => 'nie-powinno-przetrwac',
);
$clean_client     = MP_Offer_Builder_Ajax::sanitize_client( $dirty_client );
$inv92            = false === strpos( $clean_client['name'] ?? '', '<script>' )
	&& ! array_key_exists( 'extra', $clean_client )
	&& 'kontakt@testowa-firma.pl' === ( $clean_client['email'] ?? '' )
	&& 'PL' === ( $clean_client['country'] ?? '' );
record( 'inv92_sanitize_client_usuwa_tagi_i_klucze_spoza_whitelisty', $inv92 ? 'PASS' : 'FAIL', 'name=' . ( $clean_client['name'] ?? '-' ) . ' extra_obecne=' . ( array_key_exists( 'extra', $clean_client ) ? 'tak(BLAD)' : 'nie' ) );

// 93) sanitize_items(): product_id/variation_id/qty wymuszone na nieujemne
//     liczby całkowite PRZED wejściem do pipeline'u; wpisy spoza tablicy
//     (np. string zamiast obiektu) pominięte, nie powodują wyjątku.
$dirty_items = array(
	array(
		'product_id'   => '812',
		'variation_id' => '9101',
		'qty'          => '5',
	),
	'nie-tablica-pomijana',
	array( 'product_id' => -3, 'qty' => -1 ), // absint() -> wartość bezwzględna, NIE 0 (spójne z resztą kodu).
);
$clean_items = MP_Offer_Builder_Ajax::sanitize_items( $dirty_items );
$inv93       = 2 === count( $clean_items )
	&& 812 === $clean_items[0]['product_id']
	&& 9101 === $clean_items[0]['variation_id']
	&& 5 === $clean_items[0]['qty']
	&& 3 === $clean_items[1]['product_id']
	&& 1 === $clean_items[1]['qty'];
record( 'inv93_sanitize_items_wymusza_typy_i_pomija_wpisy_niepoprawne', $inv93 ? 'PASS' : 'FAIL', wp_json_encode( $clean_items ) );

/* ---------- Siatka bezpieczeństwa na twarde fatale PHP (Medium) ---------- */

echo "\n=== SIATKA BEZPIECZEŃSTWA: SHUTDOWN ROLLBACK ===\n";

// 94) maybe_rollback_on_fatal(): transakcja wciąż "otwarta" w chwili shutdown
//     (np. twardy fatal PHP, którego \Throwable nie łapie) -> ROLLBACK. Gdy
//     transakcja już zamknięta normalnie (false) -> żadnego zapytania.
$GLOBALS['wpdb']->tx_log = array();
MP_OB_Pipeline::maybe_rollback_on_fatal( true, $GLOBALS['wpdb'] );
$inv94a                  = array( 'ROLLBACK' ) === $GLOBALS['wpdb']->tx_log;

$GLOBALS['wpdb']->tx_log = array();
MP_OB_Pipeline::maybe_rollback_on_fatal( false, $GLOBALS['wpdb'] );
$inv94b                  = array() === $GLOBALS['wpdb']->tx_log;

$inv94 = $inv94a && $inv94b;
record(
	'inv94_shutdown_rollback_guard_reaguje_tylko_na_otwarta_transakcje',
	$inv94 ? 'PASS' : 'FAIL',
	'otwarta=' . ( $inv94a ? 'ok' : 'FAIL' ) . ' zamknieta=' . ( $inv94b ? 'ok' : 'FAIL' )
);
$GLOBALS['wpdb']->tx_log = array();

// 95) VAT PER KLASA PODATKOWA (regresja F1, audyt 2026-07-26): koszyk mieszający
//     stawki (23% + 8%) musi liczyć VAT per klasa, nie jedną stawką na całości.
//     Stary kod: reset($tax_rates) -> 23% na całe 20000 = 4600 gr (ZAWYŻENIE).
//     Poprawnie: 10000*23% + 10000*8% = 2300 + 800 = 3100 gr.
$GLOBALS['__mp_ob_wc_products'][9201] = array(
	'status'        => 'publish',
	'name'          => 'Produkt 23%',
	'tax_class'     => '',
	'purchasable'   => true,
	'regular_price' => '100.00',
	'sale_price'    => '',
);
$GLOBALS['__mp_ob_wc_products'][9202] = array(
	'status'        => 'publish',
	'name'          => 'Produkt 8%',
	'tax_class'     => 'obnizona',
	'purchasable'   => true,
	'regular_price' => '100.00',
	'sale_price'    => '',
);
$GLOBALS['__mp_ob_wc_tax_rates']['obnizona'] = array( array( 'rate' => 8.0, 'label' => 'VAT 8%' ) );
$mixed_input               = base_input();
$mixed_input['wariant']    = 'standard';
$mixed_input['request_id'] = '3f1c2a10-aaaa-4bbb-8ccc-000000000095';
$mixed_input['items']      = array(
	array( 'product_id' => 9201, 'variation_id' => null, 'qty' => 1 ),
	array( 'product_id' => 9202, 'variation_id' => null, 'qty' => 1 ),
);
$r95   = run_pipeline( $mixed_input );
$inv95 = $r95['ok']
	&& 'domestic' === ( $r95['final_data']['tax_mechanism'] ?? null )
	&& 20000 === (int) ( $r95['final_data']['net_grosze'] ?? -1 )
	&& 3100 === (int) ( $r95['final_data']['vat_grosze'] ?? -1 )
	&& 23100 === (int) ( $r95['final_data']['gross_grosze'] ?? -1 );
record(
	'inv95_vat_per_klasa_podatkowa_mieszany_koszyk',
	$inv95 ? 'PASS' : 'FAIL',
	'net=' . ( $r95['final_data']['net_grosze'] ?? '-' ) . ' vat=' . ( $r95['final_data']['vat_grosze'] ?? '-' ) . ' (oczek. 3100) gross=' . ( $r95['final_data']['gross_grosze'] ?? '-' )
);

// 96) Mieszane klasy + RABAT na sumie: rabat rozdzielony proporcjonalnie na klasy,
//     suma netto klas == net_grosze, VAT liczony per klasa od netto po rabacie.
//     20×100 zł (23%) + 20×100 zł (8%) = subtotal 400000 gr, wariant partner → rabat.
$disc_input               = base_input();
$disc_input['wariant']    = 'partner';
$disc_input['request_id'] = '3f1c2a10-aaaa-4bbb-8ccc-000000000096';
$disc_input['items']      = array(
	array( 'product_id' => 9201, 'variation_id' => null, 'qty' => 20 ),
	array( 'product_id' => 9202, 'variation_id' => null, 'qty' => 20 ),
);
$r96      = run_pipeline( $disc_input );
$net96    = (int) ( $r96['final_data']['net_grosze'] ?? -1 );
$vat96    = (int) ( $r96['final_data']['vat_grosze'] ?? -1 );
$gross96  = (int) ( $r96['final_data']['gross_grosze'] ?? -1 );
// Niezależnie od wysokości rabatu: obie klasy mają równy subtotal (100000 każda),
// więc rabat dzieli się 50/50; VAT = net/2*23% + net/2*8% (z zaokrągleniem per klasa).
$half     = intdiv( $net96, 2 );
$exp_vat  = (int) round( $half * 0.23 ) + (int) round( $half * 0.08 );
$inv96    = $r96['ok'] && $net96 > 0 && $net96 < 400000 && $gross96 === $net96 + $vat96 && abs( $vat96 - $exp_vat ) <= 1;
record(
	'inv96_vat_per_klasa_z_rabatem_na_sumie',
	$inv96 ? 'PASS' : 'FAIL',
	'net=' . $net96 . ' vat=' . $vat96 . ' (oczek ~' . $exp_vat . ') gross=' . $gross96
);

// 97) Cena UJEMNA (regresja F5): błędna konfiguracja WC → jawny FAIL, nigdy
//     cicha oferta z ujemnymi kwotami.
$GLOBALS['__mp_ob_wc_products'][9203] = array(
	'status'        => 'publish',
	'name'          => 'Produkt z ujemną ceną',
	'tax_class'     => '',
	'purchasable'   => true,
	'regular_price' => '-50.00',
	'sale_price'    => '',
);
$neg_input               = base_input();
$neg_input['request_id'] = '3f1c2a10-aaaa-4bbb-8ccc-000000000097';
$neg_input['items']      = array( array( 'product_id' => 9203, 'variation_id' => null, 'qty' => 1 ) );
$r97   = run_pipeline( $neg_input );
$inv97 = ! $r97['ok'] && 'incomplete_prices' === $r97['code'];
record(
	'inv97_cena_ujemna_odrzucona',
	$inv97 ? 'PASS' : 'FAIL',
	'ok=' . ( $r97['ok'] ? 'tak(BLAD)' : 'nie' ) . ' code=' . ( $r97['code'] ?? '-' )
);

/* ---------- Runda debug cz.3: nowe zachowania ---------- */

echo "\n=== RUNDA DEBUG cz.3: tax_status / kraj / promocja / strefa / idempotencja ===\n";

// A) S3-02: produkt z tax_status='none' -> pozycja ZWOLNIONA z VAT (0 gr) mimo
//    klienta krajowego. Snapshot tax_status (Dzial 2) -> klasa TAX_NONE (Dzial 6) -> 0%.
$GLOBALS['__mp_ob_wc_products'][9101]['tax_status'] = 'none';
$rA = run_pipeline( base_input() );
$GLOBALS['__mp_ob_wc_products'][9101]['tax_status'] = 'taxable';
$invA = $rA['ok'] && 0 === ( $rA['final_data']['vat_grosze'] ?? null ) && ( $rA['final_data']['net_grosze'] ?? 0 ) > 0;
record( 'inv_s3_02_tax_status_none_vat_zero', $invA ? 'PASS' : 'FAIL', 'vat=' . ( $rA['final_data']['vat_grosze'] ?? '-' ) . ' net=' . ( $rA['final_data']['net_grosze'] ?? '-' ) );

// B) S4-02: kod kraju POPRAWNY formatem (2 wielkie litery), ale NIEZNANY WooCommerce
//    ('DR') -> STOP 'unknown_country'. Bez tego 'DR' udawalby 'poza UE' i dawal 0% VAT.
$unknown_country                      = base_input();
$unknown_country['client']['country'] = 'DR';
$rB                                   = run_pipeline( $unknown_country );
$invB                                 = ! $rB['ok'] && 'unknown_country' === $rB['code'];
record( 'inv_s4_02_kraj_nieznany_unknown_country', $invB ? 'PASS' : 'FAIL', 'ok=' . ( $rB['ok'] ? 'tak' : 'nie' ) . ' code=' . $rB['code'] );

// C) S3-01: promocja z harmonogramem, ktorego okno JUZ MINELO (date_on_sale_to w
//    przeszlosci) -> is_on_sale()=false -> wycena po cenie REGULARNEJ. Porownanie z
//    przebiegiem bez promocji: identyczna suma brutto = promocja slusznie pominieta.
$rC_base = run_pipeline( base_input() );
$GLOBALS['__mp_ob_wc_products'][9101]['sale_price']        = '99.99';
$GLOBALS['__mp_ob_wc_products'][9101]['date_on_sale_from'] = '2020-01-01';
$GLOBALS['__mp_ob_wc_products'][9101]['date_on_sale_to']   = '2020-12-31';
$rC_exp = run_pipeline( base_input() );
$GLOBALS['__mp_ob_wc_products'][9101]['sale_price']        = '';
unset( $GLOBALS['__mp_ob_wc_products'][9101]['date_on_sale_from'], $GLOBALS['__mp_ob_wc_products'][9101]['date_on_sale_to'] );
$invC = $rC_base['ok'] && $rC_exp['ok']
	&& ( $rC_exp['final_data']['gross_grosze'] ?? 0 ) > 0
	&& ( $rC_exp['final_data']['gross_grosze'] ?? null ) === ( $rC_base['final_data']['gross_grosze'] ?? -1 );
record( 'inv_s3_01_promocja_wygasla_cena_regularna', $invC ? 'PASS' : 'FAIL', 'brutto_exp=' . ( $rC_exp['final_data']['gross_grosze'] ?? '-' ) . ' brutto_reg=' . ( $rC_base['final_data']['gross_grosze'] ?? '-' ) );

// D) S5-02: rok w numerze oferty pochodzi z ZEGARA STREFY (current_time), nie z UTC
//    (gmdate). Przesuwamy zegar poza granice roku -> numer w NOWYM roku (regresja:
//    gmdate zwrocilby rok UTC, mylny numer/reset licznika o polnocy sylwestrowej).
$next_year    = (int) gmdate( 'Y' ) + 1;
$saved_offset = isset( $GLOBALS['__mp_ob_time_offset'] ) ? $GLOBALS['__mp_ob_time_offset'] : 0;
$GLOBALS['__mp_ob_time_offset'] = gmmktime( 12, 0, 0, 1, 15, $next_year ) - time();
$tz_input               = base_input();
$tz_input['request_id'] = '3f1c2a10-aaaa-4bbb-8ccc-0000000000d1';
$rD = run_pipeline( $tz_input );
$GLOBALS['__mp_ob_time_offset'] = $saved_offset;
$invD = $rD['ok'] && 0 === strpos( (string) ( $rD['final_data']['offer_number'] ?? '' ), 'OF/' . $next_year . '/' );
record( 'inv_s5_02_strefa_rok_w_numerze', $invD ? 'PASS' : 'FAIL', 'offer_number=' . ( $rD['final_data']['offer_number'] ?? '-' ) . ' oczek_rok=' . $next_year );

// E) S1-2: kolizja UNIQUE(request_id) przy INSERT (wyscig dwoch rownoleglych zadan z
//    tym samym request_id) -> Dzial 10 przerywa kodem 'idempotent_replay', pipeline
//    robi ROLLBACK, Dzial 11 NIE startuje (mp_offer_created nie odpala 2. raz).
$GLOBALS['wpdb']->offers        = array();
$GLOBALS['wpdb']->items         = array();
$GLOBALS['wpdb']->versions      = array();
$GLOBALS['wpdb']->activity_log  = array();
$GLOBALS['wpdb']->tx_log        = array();
$GLOBALS['wpdb']->force_request_id_collision_once = true;
$rE = run_pipeline( base_input() );
$GLOBALS['wpdb']->force_request_id_collision_once = false;
$invE = ! $rE['ok'] && 'idempotent_replay' === $rE['code']
	&& array( 'START', 'ROLLBACK' ) === $GLOBALS['wpdb']->tx_log
	&& 0 === count( $GLOBALS['wpdb']->offers );
record( 'inv_s1_2_request_id_kolizja_idempotent_replay', $invE ? 'PASS' : 'FAIL', 'code=' . $rE['code'] . ' tx_log=' . implode( '>', $GLOBALS['wpdb']->tx_log ) . ' offers=' . count( $GLOBALS['wpdb']->offers ) );

// F) S6-02: po retry lokalnym (kolizja UNIQUE numeru) version_row.data_json to PELNY
//    snapshot ZAKTUALIZOWANEGO stanu -> zawiera NOWY numer, nie ten sprzed kolizji.
$GLOBALS['wpdb']->offers        = array();
$GLOBALS['wpdb']->items         = array();
$GLOBALS['wpdb']->versions      = array();
$GLOBALS['wpdb']->activity_log  = array();
$GLOBALS['wpdb']->force_unique_collision_once = true;
$rF = run_pipeline( base_input() );
$GLOBALS['wpdb']->force_unique_collision_once = false;
$vrowF  = isset( $GLOBALS['wpdb']->versions[0] ) ? $GLOBALS['wpdb']->versions[0] : null;
$vdataF = $vrowF ? json_decode( (string) ( $vrowF['data_json'] ?? '' ), true ) : null;
$finalF = $rF['final_data']['offer_number'] ?? null;
$invF = $rF['ok'] && is_array( $vdataF )
	&& ( $vdataF['offer_number'] ?? null ) === $finalF
	&& 'OF/' . (int) gmdate( 'Y' ) . '/000002' === $finalF;
record( 'inv_s6_02_data_json_po_retry_nowy_numer', $invF ? 'PASS' : 'FAIL', 'final=' . ( $finalF ?? '-' ) . ' data_json.offer_number=' . ( $vdataF['offer_number'] ?? '-' ) );

/* ---------- Runda Security Hardening 1.0.5 ---------- */

echo "\n=== SECURITY: limit pozycji / IDOR-enumeracja ===\n";

// SEC-1 (SR1-02): żądanie z liczbą pozycji > MAX_ITEMS odrzucone w Dziale 1
//   (fail-fast, invalid_contract) ZANIM ruszą kosztowne działy 2-11 (DoS).
$too_many          = base_input();
$too_many['items'] = array();
for ( $k = 0; $k < ( MP_OB_D1_Agent_Contract::MAX_ITEMS + 5 ); $k++ ) {
	$too_many['items'][] = array( 'product_id' => 812, 'variation_id' => 9101, 'qty' => 1 );
}
$rSec1 = run_pipeline( $too_many );
$invSec1 = ! $rSec1['ok'] && 'invalid_contract' === $rSec1['code'] && 1 === $rSec1['stop_dept'];
record( 'inv_sec1_limit_pozycji_dos', $invSec1 ? 'PASS' : 'FAIL', 'ok=' . ( $rSec1['ok'] ? 'tak' : 'nie' ) . ' code=' . $rSec1['code'] . ' stop=' . $rSec1['stop_dept'] );

// SEC-2 (SR1-01): nieistniejący offer_id daje TEN SAM kod (invalid_contract) co
//   korekta CUDZEJ oferty (inv95) — brak wyroczni enumeracji po statusie/kodzie.
$ghost              = base_input();
$ghost['offer_id']  = 987654;
unset( $ghost['client'] );
$rSec2 = run_pipeline( $ghost );
$invSec2 = ! $rSec2['ok'] && 'invalid_contract' === $rSec2['code'];
record( 'inv_sec2_enumeracja_jednolity_kod', $invSec2 ? 'PASS' : 'FAIL', 'code=' . $rSec2['code'] );

/* ---------- Podsumowanie ---------- */

echo "\n=== PODSUMOWANIE ===\n";
printf( "Scenariusze/niezmienniki: PASS=%d FAIL=%d\n", $pass, $fail );
echo 0 === $fail
	? "WYNIK: rusztowanie pipeline'u (krok 2) spójne wg niezmienników.\n"
	: "WYNIK: wykryto {$fail} naruszeń — patrz FAIL powyżej.\n";

exit( 0 === $fail ? 0 : 1 );
