<?php
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
require $PLUGIN . '/includes/db/class-mp-offer-builder-db.php';
require $PLUGIN . '/includes/pipeline/bootstrap.php';
require $PLUGIN . '/includes/class-mp-offer-builder-lead-listener.php';

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
$rb_result                     = $pipeline_rollback->run( new MP_OB_Context( array() ) );
$inv6                          = ! $rb_result->is_ok()
	&& $GLOBALS['wpdb']->tx_log === array( 'START', 'ROLLBACK' )
	&& count( $GLOBALS['wpdb']->activity_log ) === 1
	&& 'pipeline_error' === ( $GLOBALS['wpdb']->activity_log[0]['action'] ?? '' );
record(
	'inv6_mechanika_rollback_i_log_bledu',
	$inv6 ? 'PASS' : 'FAIL',
	'tx_log=' . implode( '>', $GLOBALS['wpdb']->tx_log ) . ' activity_log_rows=' . count( $GLOBALS['wpdb']->activity_log )
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

// 29) Nieznany wariant → catch-all R-00 (0%), NIE błąd — nowy/nieznany wariant
//     nie blokuje budowy oferty.
$unknown_wariant             = base_input();
$unknown_wariant['wariant']  = 'enterprise-niezdefiniowany';
$r29                          = run_pipeline( $unknown_wariant );
$inv29                        = $r29['ok'] && 'R-00' === ( $r29['final_data']['discounts'][0]['rule_id'] ?? null ) && 0 === ( $r29['final_data']['discount_total'] ?? null );
record( 'inv29_nieznany_wariant_catchall_R00', $inv29 ? 'PASS' : 'FAIL', 'rule=' . ( $r29['final_data']['discounts'][0]['rule_id'] ?? '-' ) );

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

// 35) Zaokrąglenie metodą półówkową (bez float) — granica dokładnie .5: 1×50/100=0.5→1,
//     3×50/100=1.5→2 (round half up), sprawdzone bezpośrednio na metodzie statycznej.
$inv35 = 1 === MP_OB_D6_Agent_Rounding::vat_grosze( 1, 50 )
	&& 2 === MP_OB_D6_Agent_Rounding::vat_grosze( 3, 50 )
	&& 2300 === MP_OB_D6_Agent_Rounding::vat_grosze( 10000, 23 );
record( 'inv35_zaokraglenie_polowkowe_bez_float', $inv35 ? 'PASS' : 'FAIL', 'vat(1,50)=' . MP_OB_D6_Agent_Rounding::vat_grosze( 1, 50 ) . ' vat(3,50)=' . MP_OB_D6_Agent_Rounding::vat_grosze( 3, 50 ) );

/* ---------- Dział 7: realna logika (Krok 3) ---------- */

echo "\n=== DZIAŁ 7: REALNA LOGIKA (szablon i treść) ===\n";

// 36) Happy path (pl, domestic): dokument bez niepodstawionych znaczników, dane
//     klienta i sumy (w formacie pl: przecinek dziesiętny, spacja tysięczna)
//     realnie trafiają do HTML; tabela pozycji zawiera nazwę produktu i ilość.
$html36 = $hp['final_data']['document']['html'] ?? '';
$inv36  = $hp['ok']
	&& 'pl' === ( $hp['final_data']['document']['lang'] ?? null )
	&& false === strpos( $html36, '{{' )
	&& false !== strpos( $html36, 'Testowa Firma Sp. z o.o.' )
	&& false !== strpos( $html36, '6 075,73 zł' ) // gross_grosze=607573 -> "6 075,73 zł"
	&& false !== strpos( $html36, 'Testowy wariant' )
	&& false !== strpos( $html36, '<td>40</td>' );
record( 'inv36_happy_path_scalenie_pl_bez_znacznikow', $inv36 ? 'PASS' : 'FAIL', 'lang=' . ( $hp['final_data']['document']['lang'] ?? '-' ) . ' zawiera_gross=' . ( false !== strpos( $html36, '6 075,73 zł' ) ? 'tak' : 'nie' ) );

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

/* ---------- Podsumowanie ---------- */

echo "\n=== PODSUMOWANIE ===\n";
printf( "Scenariusze/niezmienniki: PASS=%d FAIL=%d\n", $pass, $fail );
echo 0 === $fail
	? "WYNIK: rusztowanie pipeline'u (krok 2) spójne wg niezmienników.\n"
	: "WYNIK: wykryto {$fail} naruszeń — patrz FAIL powyżej.\n";

exit( 0 === $fail ? 0 : 1 );
