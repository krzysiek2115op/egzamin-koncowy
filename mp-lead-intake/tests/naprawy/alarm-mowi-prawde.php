<?php
/**
 * P1-G10 — alarmy do administratora mowily rzeczy, ktorych kod nie sprawdzil.
 *
 * Uruchamianie: wp eval-file tests/naprawy/alarm-mowi-prawde.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-G10  Cztery komunikaty logera pipeline: „dzial 0", zmyslona przyczyna
 *             niedostarczenia, alarm bez identyfikatorow, cisza przy braku
 *             admin_email
 *
 * Cztery rzeczy naraz, wszystkie z jednej rodziny: TEKST DLA CZLOWIEKA TWIERDZI
 * WIECEJ, NIZ KOD USTALIL.
 *
 * 1. „dzial 0". Docblock mowil „0 = nieznany", ale ta sama wartosc szla wprost
 *    do %d w temacie maila, tresci maila i opisie wpisu w dzienniku. Powstawal
 *    numer, ktorego w pipeline nie ma (dzialy numerowane sa od 1) i ktorego
 *    czytelnik nie ma jak odroznic od prawdziwego.
 *
 * 2. „serwer poczty odrzucil wiadomosc". Jedyna przeslanka byla wartosc false
 *    z wp_mail(), ktora o przyczynie nie mowi nic. wp_mail() zwraca false takze
 *    wtedy, gdy do serwera poczty w ogole nie doszlo — filtr pre_wp_mail innej
 *    wtyczki, niepoprawny adres w admin_email, wyjatek PHPMailera. Pracownik
 *    diagnozowal SMTP, ktory dziala poprawnie.
 *
 * 3. Alarm bez identyfikatorow. Tresc nie zawierala ani lead_id, ani request_id,
 *    i nie mowila, ze przez kolejny kwadrans dalsze alarmy z tego samego miejsca
 *    sa wyciszone. Przy bledzie zatrzymujacym 50 zglosen w ciagu kwadransa
 *    administrator obslugiwal jedno i uznawal sprawe za zamknieta.
 *
 * 4. Cisza przy braku admin_email. `if ( ! $to ) { return; }` bez sladu — wpis
 *    o awarii w dzienniku byl, wpisu admin_alert_failed nie bylo. Historia
 *    wygladala identycznie jak przy alarmie dostarczonym poprawnie.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_am'] = array(
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
function am_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_am']['pass'];
		$GLOBALS['mp_am']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_am']['fail'];
	$GLOBALS['mp_am']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

$log_t         = MP_Lead_Intake_DB::activity_log_table();
$stary_admin   = get_option( 'admin_email' );
$GLOBALS['mp_am_mail']   = array();
$GLOBALS['mp_am_powodzi'] = true;

/*
 * Poczta nie wychodzi z testu. pre_wp_mail przerywa wysylke przed PHPMailerem,
 * oddaje zadany wynik i pozwala obejrzec temat oraz tresc DOKLADNIE takie,
 * jakie dostalby administrator.
 */
add_filter(
	'pre_wp_mail',
	function ( $krotki_obieg, $atts ) {
		$GLOBALS['mp_am_mail'][] = array(
			'subject' => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
			'message' => isset( $atts['message'] ) ? (string) $atts['message'] : '',
		);

		return (bool) $GLOBALS['mp_am_powodzi'];
	},
	10,
	2
);

/**
 * Czysci ograniczniki czestotliwosci i zebrane wiadomosci.
 *
 * @return void
 */
function am_reset() {
	delete_transient( 'mp_notify_exception' );
	delete_transient( 'mp_notify_dzial-testowy' );
	$GLOBALS['mp_am_mail'] = array();
}

/**
 * Ostatni wpis dziennika o niedostarczonym alarmie.
 *
 * @param int $od_id Identyfikator, od ktorego szukamy.
 * @return array
 */
function am_wpis_o_alarmie( $od_id ) {
	global $wpdb;

	$wiersz = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			'SELECT id, description, meta_json FROM ' . MP_Lead_Intake_DB::activity_log_table() . ' WHERE action = %s AND id > %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'admin_alert_failed',
			(int) $od_id
		),
		ARRAY_A
	);

	return is_array( $wiersz ) ? $wiersz : array();
}

/**
 * Kontekst z identyfikatorami zgloszenia.
 *
 * @return MP_Context
 */
function am_kontekst() {
	return new MP_Context(
		array(
			'lead_id'    => 4242,
			'request_id' => 'req-testowy-1',
		)
	);
}

$logger = new MP_Pipeline_Logger();
$max_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$GLOBALS['mp_am']['lines'][] = '=== A. miejsce awarii nieustalone: zadnego „dzialu 0" ===';

update_option( 'admin_email', 'admin@example.test' );
am_reset();
$GLOBALS['mp_am_powodzi'] = true;

$logger->log_exception( new RuntimeException( 'Awaria u subskrybenta' ), am_kontekst(), 0 );

$mail_zerowy = isset( $GLOBALS['mp_am_mail'][0] ) ? $GLOBALS['mp_am_mail'][0] : array();

am_ok( ! empty( $mail_zerowy ), 'alarm o wyjatku zostal wyslany' );
am_ok(
	! empty( $mail_zerowy ) && false === strpos( $mail_zerowy['subject'], 'dział 0' ),
	'temat NIE podaje nieistniejacego „dzialu 0"',
	'temat=' . ( isset( $mail_zerowy['subject'] ) ? $mail_zerowy['subject'] : '?' )
);
am_ok(
	! empty( $mail_zerowy ) && false === strpos( $mail_zerowy['message'], 'dziale 0' ),
	'tresc NIE podaje nieistniejacego „dzialu 0"',
	'tresc=' . ( isset( $mail_zerowy['message'] ) ? $mail_zerowy['message'] : '?' )
);
am_ok(
	! empty( $mail_zerowy ) && false !== mb_stripos( $mail_zerowy['subject'], 'nieustalon' ),
	'zamiast tego mowi wprost, ze miejsca nie ustalono',
	'temat=' . ( isset( $mail_zerowy['subject'] ) ? $mail_zerowy['subject'] : '?' )
);

$opis_wpisu = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare( "SELECT description FROM {$log_t} WHERE action = %s ORDER BY id DESC LIMIT 1", 'pipeline_exception' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

am_ok(
	false === strpos( $opis_wpisu, 'dziale 0' ),
	'wpis w dzienniku tez nie zmysla numeru dzialu',
	'opis=' . $opis_wpisu
);

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== B. alarm identyfikuje zgloszenie i uprzedza o wyciszeniu ===';

am_ok(
	! empty( $mail_zerowy ) && false !== strpos( $mail_zerowy['message'], '4242' ),
	'tresc alarmu niesie lead_id',
	'tresc=' . ( isset( $mail_zerowy['message'] ) ? $mail_zerowy['message'] : '?' )
);
am_ok(
	! empty( $mail_zerowy ) && false !== strpos( $mail_zerowy['message'], 'req-testowy-1' ),
	'tresc alarmu niesie identyfikator zadania'
);
am_ok(
	! empty( $mail_zerowy ) && false !== mb_stripos( $mail_zerowy['message'], 'wyciszone' ),
	'tresc uprzedza, ze kolejne alarmy sa przez kwadrans wyciszone',
	'tresc=' . ( isset( $mail_zerowy['message'] ) ? $mail_zerowy['message'] : '?' )
);

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== C. niedostarczony alarm nie zmysla przyczyny ===';

am_reset();
$GLOBALS['mp_am_powodzi'] = false;
$przed_c                  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception( new RuntimeException( 'Awaria druga' ), am_kontekst(), 3 );

$wpis_c = am_wpis_o_alarmie( $przed_c );

am_ok(
	! empty( $wpis_c ),
	'nieudana wysylka zostawia slad w dzienniku'
);
am_ok(
	! empty( $wpis_c ) && false === mb_stripos( $wpis_c['description'], 'serwer poczty odrzucił' ),
	'slad NIE twierdzi, ze odrzucil ja serwer poczty',
	'opis=' . ( isset( $wpis_c['description'] ) ? $wpis_c['description'] : '?' )
);
am_ok(
	! empty( $wpis_c ) && false !== mb_stripos( $wpis_c['description'], 'wp_mail' ),
	'slad mowi, co naprawde wiadomo: wp_mail() zglosilo niepowodzenie',
	'opis=' . ( isset( $wpis_c['description'] ) ? $wpis_c['description'] : '?' )
);

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== D. brak adresu administratora to TEZ niedostarczony alarm ===';

am_reset();
$GLOBALS['mp_am_powodzi'] = true;

/*
 * Adres zdejmujemy FILTREM, nie update_option(). WordPress sanityzuje opcje
 * `admin_email` i przy wartosci, ktora nie jest adresem, PRZYWRACA poprzednia —
 * zapis pustego ciagu nie odtworzylby wiec badanego stanu, a test mierzylby
 * zachowanie przy poprawnym adresie i cicho przechodzil.
 */
add_filter( 'option_admin_email', '__return_empty_string', 99 );
$przed_d = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception( new RuntimeException( 'Awaria trzecia' ), am_kontekst(), 5 );

remove_filter( 'option_admin_email', '__return_empty_string', 99 );

$wpis_d = am_wpis_o_alarmie( $przed_d );

am_ok(
	! empty( $wpis_d ),
	'brak admin_email zostawia wpis admin_alert_failed, a nie cisze'
);
am_ok(
	! empty( $wpis_d ) && false !== mb_stripos( $wpis_d['description'], 'admin_email' ),
	'wpis nazywa przyczyne: nie ma adresu administratora',
	'opis=' . ( isset( $wpis_d['description'] ) ? $wpis_d['description'] : '?' )
);
am_ok(
	empty( $GLOBALS['mp_am_mail'] ),
	'i nie probowano niczego wysylac'
);

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== E. KONTR-ASERCJE: prawdziwy numer dzialu i alarm dostarczony ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na wyrzuceniu numeru dzialu z
 * komunikatow w ogole albo na wpisywaniu admin_alert_failed przy KAZDEJ
 * wysylce — dziennik pelen falszywych porazek jest tak samo bezuzyteczny jak
 * dziennik milczacy.
 */
update_option( 'admin_email', 'admin@example.test' );
am_reset();
$GLOBALS['mp_am_powodzi'] = true;
$przed_e                  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception( new RuntimeException( 'Awaria czwarta' ), am_kontekst(), 7 );

$mail_e = isset( $GLOBALS['mp_am_mail'][0] ) ? $GLOBALS['mp_am_mail'][0] : array();

am_ok(
	! empty( $mail_e ) && false !== strpos( $mail_e['message'], 'dziale 7' ),
	'znany numer dzialu nadal jest podany wprost',
	'tresc=' . ( isset( $mail_e['message'] ) ? $mail_e['message'] : '?' )
);
am_ok(
	empty( am_wpis_o_alarmie( $przed_e ) ),
	'dostarczony alarm NIE zostawia wpisu o niedostarczeniu'
);

// Sprzatanie: przywrocenie adresu i wpisow testowych.
update_option( 'admin_email', $stary_admin );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_t} WHERE id > %d AND action IN ('pipeline_exception','admin_alert_failed')", $max_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
am_reset();

echo implode( "\n", $GLOBALS['mp_am']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_am']['pass'], $GLOBALS['mp_am']['fail'] );
echo ( 0 === $GLOBALS['mp_am']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
