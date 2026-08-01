<?php
/**
 * P1-G11 — dedup 7.1 czytal BRAK DANYCH jako potwierdzona unikalnosc.
 *
 * Uruchamianie: wp eval-file tests/naprawy/dedup-bez-odczytu.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-G11  Dedup 7.1 opieral sie na obecnosci klucza, nie na fakcie odczytu
 *
 * Agent 7.1 rozstrzygal unikalnosc firmy tak:
 *
 *     $existing = (array) $context->get( 'leads', array() );
 *     $dup      = ! empty( $existing );
 *
 * Pusta tablica znaczyla „nie ma takiej firmy" — i to samo znaczyl BRAK klucza
 * oraz odczyt, ktory sie nie odbyl. Trzy rozne stany, jedna odpowiedz.
 *
 * ZASIEG — sprawdzony sonda, nie zalozony (sekcja C to utrwala):
 * Dzial 1 zawsze zostawia `leads` w kopercie, a jedyne wejscie, przy ktorym nie
 * wykonuje zapytania (pusty NIP), nie dochodzi do Dzialu 7 — pipeline odmawia
 * na Dziale 2, w kazdym z szesciu sprawdzonych krajow. Do tego BD-3 ma
 * UNIQUE(country, nip), wiec nawet przepuszczony duplikat rozbilby sie o baze.
 * To znaczy: NIE byl to blad z widocznym skutkiem, tylko sprawdzenie, ktore
 * opieralo sie na cudzej dyscyplinie zamiast na wlasnym warunku. Naprawiamy je
 * jako obrone w glab i tak tez opisujemy w changelogu — bez awansowania na
 * defekt krytyczny, ktorym nie bylo.
 *
 * Zostaje jednak stan naprawde nierozpoznawalny: odczyt, ktory PADL. `wpdb`
 * oddaje przy bledzie pusta tablice, wiec awaria bazy wygladala dokladnie tak
 * samo jak „firma jest nowa". Naprawa rozroznia oba przypadki u zrodla.
 *
 * Naprawa: Dzial 1 melduje `leads_checked` — zapytanie sie ODBYLO i SIE UDALO
 * (`get_leads_by_nip()` oddaje `null`, gdy `$wpdb->last_error` cos mowi). 7.1
 * tego wymaga i bez tego odmawia zamiast zgadywac.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_dbo'] = array(
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
function dbo_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_dbo']['pass'];
		$GLOBALS['mp_dbo']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_dbo']['fail'];
	$GLOBALS['mp_dbo']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Atrapa `$wpdb` zachowujaca sie jak baza PO nieudanym zapytaniu.
 *
 * Prawdziwy `wpdb::get_results()` oddaje przy bledzie pusta tablice i zostawia
 * powod w `last_error`. Bez czytania `last_error` awaria wyglada identycznie
 * jak poprawny odczyt bez wynikow — i o to w tym tescie chodzi.
 */
class MP_G11_Wpdb_Po_Awarii {

	/** @var string Prefiks tabel (potrzebny przez leads_table()). */
	public $prefix;

	/** @var string Ostatni blad — zawsze niepusty, bo atrapa udaje awarie. */
	public $last_error = 'MySQL server has gone away (atrapa testu P1-G11)';

	/**
	 * @param string $prefix Prefiks tabel z prawdziwego wpdb.
	 */
	public function __construct( $prefix ) {
		$this->prefix = $prefix;
	}

	/**
	 * @param string $query Zapytanie.
	 * @param mixed  ...$args Argumenty.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		unset( $args );
		return (string) $query;
	}

	/**
	 * Zapytanie „padlo": pusty wynik i slad w last_error.
	 *
	 * @param string $query  Zapytanie.
	 * @param string $output Format.
	 * @return array
	 */
	public function get_results( $query = null, $output = OBJECT ) {
		unset( $query, $output );
		$this->last_error = 'MySQL server has gone away (atrapa testu P1-G11)';
		return array();
	}
}

/**
 * Zgloszenie testowe.
 *
 * @param string $nip  NIP.
 * @param string $sufiks Rozroznienie firmy/adresu.
 * @return array
 */
function dbo_zgloszenie( $nip, $sufiks ) {
	return array(
		'company_name'      => 'P1G11 ' . $sufiks . ' sp. z o.o.',
		'nip'               => $nip,
		'email'             => 'p1g11-' . $sufiks . '@example.test',
		'phone'             => '+48 600 100 200',
		'country'           => 'PL',
		'segment'           => 'roboty',
		'message'           => 'Test P1-G11 — dedup bez odczytu.',
		'consent_rodo'      => 1,
		'consent_marketing' => 0,
		// Dzial 5 (zabezpieczenie formularza) stoi PRZED Dzialem 7 — bez nonce'a
		// koperta nie dochodzi do dedupu i kontr-asercje sprawdzalyby nie to.
		'mp_nonce'          => wp_create_nonce( 'mp_lead_intake' ),
	);
}

// Sprzatanie ZAWSZE, takze po fatalu — inaczej wiersze testowe zostaja w BD-3
// i psuja kolejne przebiegi (UNIQUE na tym samym NIP-ie).
register_shutdown_function(
	function () {
		global $wpdb;
		$tabela = $wpdb->prefix . 'mp_leads';
		$wpdb->query( "DELETE FROM {$tabela} WHERE company_name LIKE 'P1G11 %'" ); // phpcs:ignore
	}
);

$a71 = new MP_D7_Agent_Dedup();

$GLOBALS['mp_dbo']['lines'][] = '=== A. 7.1 bez sladu po odczycie ===';

// Odczyt sie NIE odbyl: klucza `leads` nie ma w ogole.
$ctx_brak = new MP_Context(
	array(
		'nip'     => '5252248481',
		'country' => 'PL',
	)
);
$r_brak = $a71->run( $ctx_brak );
$d_brak = $r_brak->get_data();

dbo_ok(
	! $r_brak->is_ok(),
	'7.1 odmawia, gdy w kopercie nie ma sladu po odczycie leadow',
	'wynik=' . ( $r_brak->is_ok() ? 'PRZESZLO z unique_ok=' . var_export( isset( $d_brak['unique_ok'] ) ? $d_brak['unique_ok'] : null, true ) : 'odmowa' )
);
dbo_ok(
	'dedup_not_checked' === $r_brak->get_code(),
	'odmowa niesie wlasny kod dedup_not_checked',
	'kod=' . $r_brak->get_code()
);

// Pusta tablica BEZ potwierdzenia, ze zapytanie sie odbylo — dokladnie stan,
// ktory 7.1 czytalo jako „firma nowa".
$ctx_pusto = new MP_Context(
	array(
		'nip'     => '5252248481',
		'country' => 'PL',
		'leads'   => array(),
	)
);
$r_pusto = $a71->run( $ctx_pusto );

dbo_ok(
	! $r_pusto->is_ok() && 'dedup_not_checked' === $r_pusto->get_code(),
	'pusta lista leadow bez potwierdzenia odczytu tez nie wystarcza',
	'kod=' . $r_pusto->get_code()
);

// Odczyt, ktory PADL. `get_leads_by_nip()` ma oddac null, a Dzial 1 zapisac
// `leads_checked = false` — awaria bazy przestaje wygladac jak „firma nowa".
$ctx_awaria = new MP_Context(
	array(
		'nip'           => '5252248481',
		'country'       => 'PL',
		'leads'         => array(),
		'leads_checked' => false,
	)
);
$r_awaria = $a71->run( $ctx_awaria );

dbo_ok(
	! $r_awaria->is_ok() && 'dedup_not_checked' === $r_awaria->get_code(),
	'nieudany odczyt (leads_checked=false) konczy sie odmowa, nie zgadywaniem',
	'kod=' . $r_awaria->get_code()
);

$GLOBALS['mp_dbo']['lines'][] = '';
$GLOBALS['mp_dbo']['lines'][] = '=== B. Dzial 1 melduje, ze zapytanie sie odbylo ===';

$d1 = MP_Department_01::build();

$ctx_d1 = new MP_Context(
	array(
		'nip'     => '5252248481',
		'country' => 'PL',
	)
);
$d1->process( $ctx_d1 );

dbo_ok(
	is_array( $ctx_d1->get( 'leads' ) ),
	'Dzial 1 nadal zostawia `leads` jako tablice (wymaga tego K1.1 i QA1)'
);
dbo_ok(
	true === $ctx_d1->get( 'leads_checked' ),
	'Dzial 1 przy poprawnym NIP-ie potwierdza wykonany odczyt',
	'leads_checked=' . var_export( $ctx_d1->get( 'leads_checked' ), true )
);

// Pusty NIP: Dzial 1 nie ma czego szukac i NIE WOLNO mu udawac, ze szukal.
$ctx_d1_pusty = new MP_Context(
	array(
		'nip'     => '',
		'country' => 'PL',
	)
);
$d1->process( $ctx_d1_pusty );

dbo_ok(
	false === $ctx_d1_pusty->get( 'leads_checked' ),
	'Dzial 1 przy pustym NIP-ie NIE potwierdza odczytu (bo go nie bylo)',
	'leads_checked=' . var_export( $ctx_d1_pusty->get( 'leads_checked' ), true )
);

$GLOBALS['mp_dbo']['lines'][] = '';
$GLOBALS['mp_dbo']['lines'][] = '=== C. UTRWALENIE ZASIEGU: stan byl nieosiagalny z zewnatrz ===';

/*
 * Sekcja pisana po to, zeby nastepny audyt nie zglosil tego jako defektu
 * krytycznego „przepuszczony duplikat" ani zeby ktos nie usunal naprawy jako
 * zbednej. Obie granice sa tu SPRAWDZANE, nie opisane.
 */
$ctx_pusty_nip = new MP_Context( dbo_zgloszenie( '', 'pusty-nip' ) );
$wynik_pusty   = MP_Pipeline_Factory::make()->run( $ctx_pusty_nip );

dbo_ok(
	! $wynik_pusty->is_ok(),
	'zgloszenie z pustym NIP-em nie dochodzi do Dzialu 7',
	'wynik=' . ( $wynik_pusty->is_ok() ? 'OK' : 'odmowa' ) . ', dzial=' . $ctx_pusty_nip->get_current_department()
);
dbo_ok(
	$ctx_pusty_nip->get_current_department() < 7,
	'pipeline zatrzymuje je wczesniej niz na Dziale 7',
	'zatrzymane na dziale ' . $ctx_pusty_nip->get_current_department()
);

global $wpdb;
$tabela  = $wpdb->prefix . 'mp_leads';
$indeksy = (array) $wpdb->get_results( "SHOW INDEX FROM {$tabela}", ARRAY_A ); // phpcs:ignore
$klucz   = array();

foreach ( $indeksy as $i ) {
	if ( '0' === (string) $i['Non_unique'] && 'PRIMARY' !== $i['Key_name'] ) {
		$klucz[ $i['Key_name'] ][] = $i['Column_name'];
	}
}

dbo_ok(
	isset( $klucz['uq_country_nip'] ) && array( 'country', 'nip' ) === $klucz['uq_country_nip'],
	'BD-3 ma UNIQUE(country, nip) — druga linia obrony przed duplikatem',
	'indeksy unikalne: ' . wp_json_encode( $klucz )
);

$GLOBALS['mp_dbo']['lines'][] = '';
$GLOBALS['mp_dbo']['lines'][] = '=== D. KONTR-ASERCJE: zwykly przebieg ma dzialac ===';

/*
 * Bez tej sekcji „naprawa" mogla by polegac na odmawianiu zawsze — a przez 7.1
 * przechodzi KAZDE zgloszenie, wiec zepsucie tego zatrzymuje cala wtyczke.
 */
$ctx_nowy = new MP_Context( dbo_zgloszenie( '5252248481', 'nowa' ) );
$nowy     = MP_Pipeline_Factory::make()->run( $ctx_nowy );

dbo_ok(
	$nowy->is_ok(),
	'nowa firma nadal przechodzi caly pipeline',
	'kod=' . $nowy->get_code() . ', dzial=' . $ctx_nowy->get_current_department()
);
dbo_ok(
	(int) $ctx_nowy->get( 'lead_id', 0 ) > 0,
	'lead zostal zapisany w BD-3',
	'lead_id=' . (int) $ctx_nowy->get( 'lead_id', 0 )
);

// To samo zgloszenie drugi raz — duplikat ma byc dalej rozpoznawany.
$ctx_dup = new MP_Context( dbo_zgloszenie( '5252248481', 'druga' ) );
$dup     = MP_Pipeline_Factory::make()->run( $ctx_dup );

dbo_ok(
	! $dup->is_ok(),
	'powtorzone zgloszenie tej samej firmy nadal jest odrzucane jako duplikat',
	'wynik=' . ( $dup->is_ok() ? 'OK' : 'odmowa' ) . ', kod=' . $dup->get_code()
);
dbo_ok(
	7 === $ctx_dup->get_current_department(),
	'odrzucenie zapada na Dziale 7 (dedup), a nie gdzie indziej',
	'dzial=' . $ctx_dup->get_current_department()
);

// Agent 7.1 wprost: potwierdzony odczyt z niepusta lista = duplikat.
$r_dup = $a71->run(
	new MP_Context(
		array(
			'nip'           => '5252248481',
			'country'       => 'PL',
			'leads'         => array( array( 'id' => 42 ) ),
			'leads_checked' => true,
		)
	)
);
$d_dup = $r_dup->get_data();

dbo_ok(
	$r_dup->is_ok() && true === $d_dup['is_duplicate'] && 42 === $d_dup['existing_lead_id'],
	'7.1 przy potwierdzonym odczycie nadal wskazuje istniejacego leada',
	'is_duplicate=' . var_export( $d_dup['is_duplicate'], true ) . ', id=' . var_export( $d_dup['existing_lead_id'], true )
);

$r_uniq = $a71->run(
	new MP_Context(
		array(
			'nip'           => '5252248481',
			'country'       => 'PL',
			'leads'         => array(),
			'leads_checked' => true,
		)
	)
);
$d_uniq = $r_uniq->get_data();

dbo_ok(
	$r_uniq->is_ok() && true === $d_uniq['unique_ok'],
	'7.1 przy potwierdzonym odczycie i pustej liscie potwierdza unikalnosc'
);

$GLOBALS['mp_dbo']['lines'][] = '';
$GLOBALS['mp_dbo']['lines'][] = '=== E. warstwa DB rozroznia „nic nie ma" od „nie udalo sie" ===';

$brak_firmy = MP_Lead_Intake_DB::get_leads_by_nip( '0000000000', 'PL' );

dbo_ok(
	is_array( $brak_firmy ) && array() === $brak_firmy,
	'brak firmy = pusta TABLICA (odczyt sie udal, wynik pusty)',
	'typ=' . gettype( $brak_firmy )
);

/*
 * Awarii bazy nie da sie wywolac danymi wejsciowymi — zle zapytanie to nie to
 * samo, co zerwane polaczenie. Podmieniamy wiec `$wpdb` na atrape, ktora
 * zachowuje sie jak wpdb po nieudanym zapytaniu: pusty wynik PLUS `last_error`.
 * Dokladnie tak wyglada awaria, ktorej stary kod nie odroznial od „firma nowa".
 */
$prawdziwy_wpdb = $GLOBALS['wpdb'];

// Przywrocenie takze po fatalu — bez tego atrapa zostaje i psuje kazdy kolejny test.
register_shutdown_function(
	function () use ( $prawdziwy_wpdb ) {
		$GLOBALS['wpdb'] = $prawdziwy_wpdb;
	}
);

$GLOBALS['wpdb'] = new MP_G11_Wpdb_Po_Awarii( $prawdziwy_wpdb->prefix );
$blad            = MP_Lead_Intake_DB::get_leads_by_nip( '5252248481', 'PL' );
$GLOBALS['wpdb'] = $prawdziwy_wpdb;

dbo_ok(
	null === $blad,
	'nieudany odczyt oddaje null (a nie pusta tablice udajaca brak firmy)',
	'typ=' . gettype( $blad ) . ', wartosc=' . wp_json_encode( $blad )
);

// Ten sam stan przepuszczony przez Dzial 1: `leads` zostaje tablica (K1.1/QA1
// tego wymagaja), ale `leads_checked` mowi prawde — i to 7.1 zatrzyma.
$GLOBALS['wpdb'] = new MP_G11_Wpdb_Po_Awarii( $prawdziwy_wpdb->prefix );
$ctx_d1_awaria   = new MP_Context(
	array(
		'nip'     => '5252248481',
		'country' => 'PL',
	)
);
$d1->process( $ctx_d1_awaria );
$GLOBALS['wpdb'] = $prawdziwy_wpdb;

dbo_ok(
	is_array( $ctx_d1_awaria->get( 'leads' ) ) && false === $ctx_d1_awaria->get( 'leads_checked' ),
	'Dzial 1 po awarii odczytu oddaje pusta liste, ale NIE potwierdza sprawdzenia',
	'leads=' . wp_json_encode( $ctx_d1_awaria->get( 'leads' ) ) . ', leads_checked=' . var_export( $ctx_d1_awaria->get( 'leads_checked' ), true )
);

$r_po_awarii = $a71->run( $ctx_d1_awaria );

dbo_ok(
	! $r_po_awarii->is_ok() && 'dedup_not_checked' === $r_po_awarii->get_code(),
	'7.1 na kopercie po awarii odczytu odmawia zamiast zakladac nowa firme',
	'kod=' . $r_po_awarii->get_code()
);

echo implode( "\n", $GLOBALS['mp_dbo']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_dbo']['pass'], $GLOBALS['mp_dbo']['fail'] );
echo ( 0 === $GLOBALS['mp_dbo']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
