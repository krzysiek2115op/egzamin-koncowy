<?php
/**
 * P1-G12 — odczyt archiwum firm mylil „nie ma wiersza" z „zapytanie padlo".
 *
 * Uruchamianie: wp eval-file tests/naprawy/archiwum-bez-odczytu.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-G12  get_archived_lead_by_nip() oddawal null dla obu stanow naraz
 *
 * Ta sama klasa co P1-G11, jedna funkcje ponizej w tym samym pliku. Tam bylo
 * to `get_results()`, ktory przy bledzie oddaje pusta tablice; tu `get_row()`,
 * ktory przy bledzie oddaje `null` — czyli DOKLADNIE to samo, co przy braku
 * pasujacego wiersza:
 *
 *     $row = $wpdb->get_row( ... );
 *     return is_array( $row ) ? $row : null;
 *
 * Skutek byl widoczny dopiero u klienta. Firma raz zarchiwizowana ma swoj
 * wiersz w BD-3 nadal — soft-delete zostawia go pod kluczem UNIQUE
 * (country, nip). Gdy zapytanie o archiwum padalo, Agent 7.1 czytal `null`
 * jako „nie ma archiwum", nie ustawial `reactivate_lead_id`, a Agent 7.3 szedl
 * na INSERT. INSERT bil w UNIQUE i wracal generycznym `insert_failed`.
 * Powracajaca firma nie dostawala reaktywacji ani zrozumialego powodu odmowy —
 * dostawala komunikat o nieudanym zapisie, choc zapis byl niemozliwy z zupelnie
 * innego powodu niz ten, ktory naprawde zaszedl.
 *
 * Naprawa: jedna regula dla calej klasy DB — `null` z metody odczytu znaczy
 * ZAWSZE „odczyt sie nie odbyl", a potwierdzony brak wiersza to `array()`.
 * Symetrycznie do naprawionego wczesniej `get_leads_by_nip()`. 7.1 na `null`
 * odmawia wlasnym kodem `archive_not_checked`, zamiast zgadywac.
 *
 * ZASIEG — sprawdzony sonda, nie zalozony:
 * `get_archived_lead_by_nip()` ma w kodzie produkcyjnym jednego wolajacego
 * (class-mp-department-07.php, Agent 7.1). Atrapa harnessu procesu oddaje dla
 * „brak archiwum" `null` — i dlatego normalizacja siedzi w metodzie DB, a nie
 * u wolajacego: inaczej 110 asercji harnessu czytaloby brak archiwum jako
 * awarie odczytu (sekcja C to utrwala).
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_abo'] = array(
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
function abo_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_abo']['pass'];
		$GLOBALS['mp_abo']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_abo']['fail'];
	$GLOBALS['mp_abo']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Atrapa `$wpdb` sterowana per przebieg.
 *
 * Prawdziwy `wpdb::get_row()` oddaje przy bledzie `null` i zostawia powod
 * wylacznie w `last_error` — nie do odroznienia od poprawnego odczytu, ktory
 * niczego nie znalazl. O to w tym tescie chodzi.
 */
class MP_G12_Wpdb_Sterowany {

	/** @var string Prefiks tabel (potrzebny przez leads_table()). */
	public $prefix;

	/** @var string Ostatni blad. */
	public $last_error = '';

	/** @var array|null Wiersz oddawany przez get_row(), gdy zapytanie „sie udaje". */
	private $wiersz;

	/** @var bool Czy zapytanie ma udawac awarie. */
	private $awaria;

	/**
	 * @param string     $prefix Prefiks tabel z prawdziwego wpdb.
	 * @param array|null $wiersz Wiersz do oddania (null = brak pasujacego wiersza).
	 * @param bool       $awaria Czy get_row() ma udawac awarie.
	 * @param string     $slad   Wstepna zawartosc last_error (slad po poprzednim zapytaniu).
	 */
	public function __construct( $prefix, $wiersz = null, $awaria = false, $slad = '' ) {
		$this->prefix     = $prefix;
		$this->wiersz     = $wiersz;
		$this->awaria     = (bool) $awaria;
		$this->last_error = (string) $slad;
	}

	/**
	 * @param string $query   Zapytanie.
	 * @param mixed  ...$args Argumenty.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		unset( $args );
		return (string) $query;
	}

	/**
	 * @param string $query  Zapytanie.
	 * @param string $output Format.
	 * @return array|null
	 */
	public function get_row( $query = null, $output = OBJECT ) {
		unset( $query, $output );

		if ( $this->awaria ) {
			$this->last_error = 'Lock wait timeout exceeded (atrapa testu P1-G12)';
			return null;
		}

		// Udane zapytanie czysci slad po poprzednim — tak robi prawdziwy wpdb::query().
		$this->last_error = '';
		return $this->wiersz;
	}

	/**
	 * @param string $query  Zapytanie.
	 * @param string $output Format.
	 * @return array
	 */
	public function get_results( $query = null, $output = OBJECT ) {
		unset( $query, $output );
		$this->last_error = '';
		return array();
	}
}

global $wpdb;
$abo_prefix   = $wpdb->prefix;
$abo_prawdziwy = $wpdb;

/**
 * Podmienia $wpdb na atrape, wola metode DB i przywraca prawdziwy $wpdb.
 *
 * Przywrocenie w `finally` — inaczej fatal w srodku zostawilby cala reszte
 * przebiegu (i sprzatanie) na atrapie.
 *
 * @param MP_G12_Wpdb_Sterowany $atrapa Atrapa.
 * @return mixed
 */
function abo_przez_atrape( $atrapa ) {
	global $wpdb;
	$zapasowy = $wpdb;
	$wpdb     = $atrapa; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	try {
		return MP_Lead_Intake_DB::get_archived_lead_by_nip( '5252248481', 'PL' );
	} finally {
		$wpdb = $zapasowy; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}

$GLOBALS['mp_abo']['lines'][] = '=== A. Kontrakt warstwy DB ===';

// A1 — potwierdzony BRAK wiersza. To jest ta asercja, ktora przed naprawa pada:
// stary kod oddawal tu `null`, czyli to samo, co przy awarii.
$abo_brak = abo_przez_atrape( new MP_G12_Wpdb_Sterowany( $abo_prefix, null, false ) );
abo_ok(
	is_array( $abo_brak ) && empty( $abo_brak ),
	'potwierdzony brak archiwum oddaje pusta TABLICE, nie null',
	'oddano=' . gettype( $abo_brak ) . ':' . var_export( $abo_brak, true )
);

// A2 — awaria odczytu. `null` zarezerwowane wylacznie dla tego stanu.
$abo_awaria = abo_przez_atrape( new MP_G12_Wpdb_Sterowany( $abo_prefix, null, true ) );
abo_ok(
	is_null( $abo_awaria ),
	'awaria odczytu oddaje null',
	'oddano=' . gettype( $abo_awaria ) . ':' . var_export( $abo_awaria, true )
);

// A3 — kontr-asercja: znaleziony wiersz nadal wraca w calosci.
$abo_wiersz = abo_przez_atrape(
	new MP_G12_Wpdb_Sterowany( $abo_prefix, array( 'id' => 4321, 'nip' => '5252248481' ), false )
);
abo_ok(
	is_array( $abo_wiersz ) && 4321 === (int) $abo_wiersz['id'],
	'znaleziony wiersz archiwum wraca nietkniety',
	'oddano=' . var_export( $abo_wiersz, true )
);

// A4 — slad po WCZESNIEJSZYM nieudanym zapytaniu (np. zapisie do activity_log,
// ktory realnie potrafi ustawic last_error) nie moze udawac awarii TEGO odczytu.
// Pinuje implementacje: zerowanie last_error PRZED zapytaniem.
$abo_po_sladzie = abo_przez_atrape(
	new MP_G12_Wpdb_Sterowany( $abo_prefix, array( 'id' => 77 ), false, 'blad poprzedniego ZAPISU' )
);
abo_ok(
	is_array( $abo_po_sladzie ) && 77 === (int) $abo_po_sladzie['id'],
	'slad po poprzednim nieudanym ZAPISIE nie udaje awarii odczytu',
	'oddano=' . var_export( $abo_po_sladzie, true )
);

$GLOBALS['mp_abo']['lines'][] = '=== B. Agent 7.1 wobec nieudanego odczytu archiwum ===';

$abo_a71 = new MP_D7_Agent_Dedup();

/**
 * Kontekst dla 7.1: dedup juz potwierdzony (P1-G11), brak aktywnych duplikatow,
 * wiec 7.1 przechodzi do pytania o archiwum.
 *
 * @return MP_Context
 */
function abo_kontekst() {
	return new MP_Context(
		array(
			'nip'           => '5252248481',
			'country'       => 'PL',
			'leads'         => array(),
			'leads_checked' => true,
		)
	);
}

global $wpdb;
$abo_zapasowy = $wpdb;
$wpdb         = new MP_G12_Wpdb_Sterowany( $abo_prefix, null, true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$abo_r_awaria = $abo_a71->run( abo_kontekst() );
$wpdb         = $abo_zapasowy; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

$abo_d_awaria = $abo_r_awaria->get_data();

abo_ok(
	! $abo_r_awaria->is_ok(),
	'7.1 odmawia, gdy odczyt archiwum sie nie odbyl',
	'wynik=' . ( $abo_r_awaria->is_ok() ? 'PRZESZLO z reactivate_lead_id=' . var_export( isset( $abo_d_awaria['reactivate_lead_id'] ) ? $abo_d_awaria['reactivate_lead_id'] : null, true ) : 'odmowa' )
);
abo_ok(
	'archive_not_checked' === $abo_r_awaria->get_code(),
	'odmowa niesie wlasny kod archive_not_checked',
	'kod=' . $abo_r_awaria->get_code()
);
abo_ok(
	! isset( $abo_d_awaria['reactivate_lead_id'] ) || null === $abo_d_awaria['reactivate_lead_id'],
	'odmowa nie podstawia reactivate_lead_id — 7.3 nie dostaje sciezki INSERT',
	'reactivate_lead_id=' . var_export( isset( $abo_d_awaria['reactivate_lead_id'] ) ? $abo_d_awaria['reactivate_lead_id'] : 'brak klucza', true )
);

$GLOBALS['mp_abo']['lines'][] = '=== C. Kontr-asercje: obie zdrowe sciezki nietkniete ===';

// C1 — zdrowy odczyt, archiwum NIE ma. Musi przechodzic tak samo przed i po
// naprawie; to ta sama wartosc (`null` z get_row()), ktora oddaje atrapa
// harnessu procesu dla „brak archiwum".
$wpdb          = new MP_G12_Wpdb_Sterowany( $abo_prefix, null, false ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$abo_r_czysto  = $abo_a71->run( abo_kontekst() );
$wpdb          = $abo_zapasowy; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$abo_d_czysto  = $abo_r_czysto->get_data();

abo_ok(
	$abo_r_czysto->is_ok(),
	'brak archiwum przy zdrowym odczycie nadal przechodzi',
	'kod=' . $abo_r_czysto->get_code()
);
abo_ok(
	null === $abo_d_czysto['reactivate_lead_id'],
	'brak archiwum nie ustawia reactivate_lead_id',
	'reactivate_lead_id=' . var_export( $abo_d_czysto['reactivate_lead_id'], true )
);
abo_ok(
	true === $abo_d_czysto['unique_ok'] && false === $abo_d_czysto['is_duplicate'],
	'brak archiwum zostawia unique_ok=true, is_duplicate=false'
);

// C2 — zdrowy odczyt, archiwum JEST: reaktywacja dziala jak dotad.
$wpdb            = new MP_G12_Wpdb_Sterowany( $abo_prefix, array( 'id' => 9182 ), false ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$abo_r_archiwum  = $abo_a71->run( abo_kontekst() );
$wpdb            = $abo_zapasowy; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$abo_d_archiwum  = $abo_r_archiwum->get_data();

abo_ok(
	$abo_r_archiwum->is_ok() && 9182 === (int) $abo_d_archiwum['reactivate_lead_id'],
	'znalezione archiwum nadal kieruje 7.3 na reaktywacje',
	'reactivate_lead_id=' . var_export( $abo_d_archiwum['reactivate_lead_id'], true )
);

// C3 — aktywny duplikat: 7.1 w ogole nie pyta o archiwum, wiec awaria tego
// odczytu nie ma prawa zmienic werdyktu o duplikacie.
$wpdb          = new MP_G12_Wpdb_Sterowany( $abo_prefix, null, true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$abo_r_dup     = $abo_a71->run(
	new MP_Context(
		array(
			'nip'           => '5252248481',
			'country'       => 'PL',
			'leads'         => array( array( 'id' => 55 ) ),
			'leads_checked' => true,
		)
	)
);
$wpdb          = $abo_zapasowy; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$abo_d_dup     = $abo_r_dup->get_data();

abo_ok(
	$abo_r_dup->is_ok() && true === $abo_d_dup['is_duplicate'] && 55 === (int) $abo_d_dup['existing_lead_id'],
	'aktywny duplikat rozstrzygniety bez pytania o archiwum',
	'kod=' . $abo_r_dup->get_code() . ' is_duplicate=' . var_export( $abo_d_dup['is_duplicate'], true )
);

// D — pewnosc, ze prawdziwy $wpdb wrocil na miejsce (inaczej kolejne testy
// w tym samym przebiegu pracowalyby na atrapie).
abo_ok(
	$wpdb === $abo_prawdziwy,
	'prawdziwy $wpdb przywrocony po wszystkich podmianach'
);

echo implode( "\n", $GLOBALS['mp_abo']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_abo']['pass'], $GLOBALS['mp_abo']['fail'] );
echo ( 0 === $GLOBALS['mp_abo']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
