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

$GLOBALS['mp_am_miejsca'] = array();

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
	/*
	 * Ogranicznik wyjatkow ma klucz OSOBNY DLA DZIALU (P1-G15), wiec sprzatanie
	 * musi objac cala numeracje: 0 to „dzial nieustalony", 1-11 to pipeline.
	 * Pominiecie tego zostawialo transient na kwadrans i NASTEPNE uruchomienie
	 * testu widzialo alarm jako wyciszony — czyli test padal z powodu swojego
	 * poprzedniego przebiegu, a nie z powodu kodu.
	 */
	for ( $dz = 0; $dz <= 11; $dz++ ) {
		delete_transient( 'mp_notify_exception_' . $dz );
	}

	delete_transient( 'mp_notify_exception' );
	delete_transient( 'mp_notify_dzial-testowy' );

	/*
	 * Wyjatki BEZ ustalonego dzialu maja kubelek liczony z ich POCHODZENIA
	 * (plik:linia), wiec nie da sie ich wyliczyc numerami — a kazda sekcja rzuca
	 * z innej linii. Kasujemy wiec wszystko, co ma ten prefiks: inaczej test padal
	 * z powodu swojego POPRZEDNIEGO przebiegu, bo transient zyje kwadrans.
	 */
	global $wpdb;
	$klucze = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_mp_notify_exception_' ) . '%'
		)
	);
	foreach ( (array) $klucze as $klucz ) {
		delete_transient( substr( $klucz, strlen( '_transient_' ) ) );
	}
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

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== F. „z tego samego miejsca" ma znaczyc to, co mowi ===';

/*
 * Stopka alarmu obiecuje administratorowi, ze wyciszone sa kolejne alarmy
 * „Z TEGO SAMEGO MIEJSCA". Sciezka bledu dzialu tak wlasnie dziala (klucz
 * `mp_notify_<dzial>`), ale sciezka WYJATKU uzywala JEDNEGO klucza na cala
 * wtyczke. Wyjatek w Dziale 3 wyciszal wiec na kwadrans wyjatki z Dzialu 9 —
 * druga, zupelnie niezalezna awaria nie dawala znaku zycia, a administrator
 * czytal w stopce, ze wyciszone jest tylko to jedno miejsce.
 *
 * To ten sam blad, ktory ta stopka miala naprawiac (P1-G10, punkt 3): tekst dla
 * czlowieka twierdzil wiecej, niz kod robil. Naprawiamy zachowanie, nie tekst —
 * obietnica jest sluszna, to kod jej nie dotrzymywal.
 */
foreach ( array( 3, 9 ) as $am_dz ) {
	delete_transient( 'mp_notify_exception_' . $am_dz );
}
delete_transient( 'mp_notify_exception' );
$GLOBALS['mp_am_mail']    = array();
$GLOBALS['mp_am_powodzi'] = true;
update_option( 'admin_email', 'admin@example.test' );

$logger->log_exception( new RuntimeException( 'Awaria w dziale 3' ), am_kontekst(), 3 );
$po_pierwszym = count( $GLOBALS['mp_am_mail'] );

$logger->log_exception( new RuntimeException( 'Awaria w dziale 9' ), am_kontekst(), 9 );
$po_drugim = count( $GLOBALS['mp_am_mail'] );

am_ok( 1 === $po_pierwszym, 'F1: wyjatek z Dzialu 3 wysyla alarm', 'wiadomosci=' . $po_pierwszym );
am_ok(
	2 === $po_drugim,
	'F2: wyjatek z INNEGO dzialu w tym samym kwadransie tez wysyla alarm',
	'wiadomosci=' . $po_drugim
);
am_ok(
	2 === $po_drugim && false !== strpos( (string) $GLOBALS['mp_am_mail'][1]['message'], 'dziale 9' ),
	'F3: drugi alarm dotyczy naprawde Dzialu 9, a nie powtorki z trojki',
	'tresc=' . ( isset( $GLOBALS['mp_am_mail'][1]['message'] ) ? $GLOBALS['mp_am_mail'][1]['message'] : '?' )
);

/*
 * KONTR-ASERCJA. Rozbicie klucza nie moze znaczyc „ogranicznik przestal
 * dzialac" — powtorka z TEGO SAMEGO dzialu ma dalej byc wyciszona, inaczej
 * awaria w petli zamienia skrzynke administratora w strumien.
 */
$logger->log_exception( new RuntimeException( 'Druga awaria w dziale 3' ), am_kontekst(), 3 );

am_ok(
	2 === count( $GLOBALS['mp_am_mail'] ),
	'F4: kontr-asercja — powtorka z tego samego dzialu nadal jest wyciszona',
	'wiadomosci=' . count( $GLOBALS['mp_am_mail'] )
);
am_ok(
	isset( $GLOBALS['mp_am_mail'][0] ) && false !== mb_stripos( (string) $GLOBALS['mp_am_mail'][0]['message'], 'z tego samego miejsca' ),
	'F5: stopka nadal sklada te obietnice — i dopiero teraz jest ona prawdziwa',
	'tresc=' . ( isset( $GLOBALS['mp_am_mail'][0] ) ? $GLOBALS['mp_am_mail'][0]['message'] : '?' )
);

foreach ( array( 3, 9 ) as $am_dz ) {
	delete_transient( 'mp_notify_exception_' . $am_dz );
}

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== G. slad o niedostarczonym alarmie da sie polaczyc ze zgloszeniem ===';

/*
 * Wpis `admin_alert_failed` szedl z `lead_id = null` na sztywno i bez
 * `request_id` w `meta_json`. Alarm, ktory NIE doszedl, byl wiec jedynym
 * zdarzeniem w dzienniku, ktorego nie dalo sie przypisac do zgloszenia —
 * a to wlasnie on jest momentem, w ktorym cos poszlo nie tak. Filtrowanie
 * historii po lead_id go nie pokazywalo.
 *
 * Sedno: identyfikatory sa w TRESCI alarmu (P1-G10 punkt 3), tylko ze tresc
 * poszla do skrzynki, ktora nie odpowiada. Zostaje dziennik i to on musi je
 * miec.
 */
foreach ( array( 3, 9, 5 ) as $am_dz ) {
	delete_transient( 'mp_notify_exception_' . $am_dz );
}
$GLOBALS['mp_am_mail']    = array();
$GLOBALS['mp_am_powodzi'] = false;
update_option( 'admin_email', 'admin@example.test' );

$przed_g = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception( new RuntimeException( 'Awaria z niedostarczonym alarmem' ), am_kontekst(), 5 );

$wiersz_g = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"SELECT lead_id, meta_json FROM {$log_t} WHERE action = %s AND id > %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'admin_alert_failed',
		$przed_g
	),
	ARRAY_A
);

$meta_g = is_array( $wiersz_g ) ? (array) json_decode( (string) $wiersz_g['meta_json'], true ) : array();

am_ok( is_array( $wiersz_g ) && ! empty( $wiersz_g ), 'G1: wpis o niedostarczonym alarmie powstal' );
am_ok(
	is_array( $wiersz_g ) && 4242 === (int) $wiersz_g['lead_id'],
	'G2: wpis niesie lead_id zgloszenia, nie null',
	'lead_id=' . ( is_array( $wiersz_g ) ? var_export( $wiersz_g['lead_id'], true ) : 'brak wiersza' )
);
am_ok(
	isset( $meta_g['request_id'] ) && 'req-testowy-1' === (string) $meta_g['request_id'],
	'G3: meta_json niesie identyfikator zadania',
	'meta=' . ( is_array( $wiersz_g ) ? (string) $wiersz_g['meta_json'] : 'brak wiersza' )
);

/*
 * KONTR-ASERCJA. Awaria PRZED zapisem leada nie ma lead_id i wtedy null jest
 * jedyna poprawna wartoscia — dopisanie tam zera albo zmyslonej liczby byloby
 * gorsze niz brak. Wpis ma wtedy stac na samym request_id.
 */
delete_transient( 'mp_notify_exception_6' );
$przed_g2 = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception(
	new RuntimeException( 'Awaria przed zapisem leada' ),
	new MP_Context( array( 'request_id' => 'req-testowy-2' ) ),
	6
);

$wiersz_g2 = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"SELECT lead_id, meta_json FROM {$log_t} WHERE action = %s AND id > %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'admin_alert_failed',
		$przed_g2
	),
	ARRAY_A
);

$meta_g2 = is_array( $wiersz_g2 ) ? (array) json_decode( (string) $wiersz_g2['meta_json'], true ) : array();

am_ok(
	is_array( $wiersz_g2 ) && null === $wiersz_g2['lead_id'],
	'G4: kontr-asercja — bez leada kolumna zostaje pusta, nic sie nie zmysla',
	'lead_id=' . ( is_array( $wiersz_g2 ) ? var_export( $wiersz_g2['lead_id'], true ) : 'brak wiersza' )
);
am_ok(
	isset( $meta_g2['request_id'] ) && 'req-testowy-2' === (string) $meta_g2['request_id'],
	'G5: a zadanie i tak da sie wskazac — po request_id',
	'meta=' . ( is_array( $wiersz_g2 ) ? (string) $wiersz_g2['meta_json'] : 'brak wiersza' )
);

foreach ( array( 5, 6 ) as $am_dz ) {
	delete_transient( 'mp_notify_exception_' . $am_dz );
}
$GLOBALS['mp_am_powodzi'] = true;

// Sprzatanie: przywrocenie adresu i wpisow testowych.
update_option( 'admin_email', $stary_admin );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_t} WHERE id > %d AND action IN ('pipeline_exception','admin_alert_failed')", $max_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
am_reset();

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== H. dzial NIEUSTALONY to nie jest jeden dzial ===';

/*
 * `dept_num = 0` znaczy „nie wiemy, gdzie to bylo", a nie „dzial numer zero".
 * Wszystkie takie wyjatki dzielily jednak jeden kubelek wyciszania, wiec awaria
 * w jednym nieznanym miejscu uciszala na kwadrans awarie w innym, zupelnie
 * niezaleznym. To ten sam blad, ktory P1-G15 naprawil dla dzialow 1-11, tylko
 * schowany w wartosci domyslnej.
 *
 * Dwa wyjatki rzucamy z DWOCH ROZNYCH linii tego pliku — to ich pochodzenie ma
 * je rozroznic.
 */
$am_pierwszy = new RuntimeException( 'awaria w nieznanym miejscu A' );
$am_drugi    = new RuntimeException( 'awaria w nieznanym miejscu B' );

$GLOBALS['mp_am_miejsca'] = array(
	$am_pierwszy->getFile() . ':' . $am_pierwszy->getLine(),
	$am_drugi->getFile() . ':' . $am_drugi->getLine(),
);

am_reset();

$am_logger = new MP_Pipeline_Logger();
$am_logger->log_exception( $am_pierwszy, am_kontekst(), 0 );
$am_po_pierwszym = count( $GLOBALS['mp_am_mail'] );

$am_logger->log_exception( $am_drugi, am_kontekst(), 0 );
$am_po_drugim = count( $GLOBALS['mp_am_mail'] );

am_ok(
	1 === $am_po_pierwszym,
	'H1: pierwszy wyjatek bez ustalonego dzialu wysyla alarm',
	'wiadomosci=' . $am_po_pierwszym
);
am_ok(
	2 === $am_po_drugim,
	'H2: drugi, z INNEGO miejsca, tez wysyla — nie jest wyciszony przez pierwszy',
	'wiadomosci=' . $am_po_drugim
);

$am_logger->log_exception( $am_pierwszy, am_kontekst(), 0 );

am_ok(
	2 === count( $GLOBALS['mp_am_mail'] ),
	'H3: KONTR-ASERCJA — powtorka z TEGO SAMEGO miejsca nadal milczy',
	'wiadomosci=' . count( $GLOBALS['mp_am_mail'] )
);

am_reset();

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== I. pochodzenie wyjatku: kod je zna, wiec ma je pokazac ===';

/*
 * Kubelek wyciszania dla nieustalonego dzialu liczy sie z pliku i linii wyjatku
 * (sekcja H). Ten sam fakt nie docieral jednak NIGDZIE dalej: ani do meta_json
 * wpisu, ani do tekstu dla czlowieka. Administrator dostawal „Nieoczekiwany
 * wyjatek w nieustalonym miejscu pipeline'u. Typ: TypeError. Komunikat:
 * Unsupported operand types" — zdanie, ktore nie wskazuje zadnego miejsca,
 * mimo ze funkcja obok wlasnie je wyliczyla.
 */
update_option( 'admin_email', 'admin@example.test' );
$GLOBALS['mp_am_powodzi'] = true;
am_reset();

$am_i_max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

/**
 * Ostatni wpis o wyjatku wraz z odkodowanym meta_json.
 *
 * @param int    $od_id  Identyfikator, od ktorego szukamy.
 * @param string $action Rodzaj wpisu.
 * @return array
 */
function am_wpis_wyjatku( $od_id, $action = 'pipeline_exception' ) {
	global $wpdb;

	$wiersz = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			'SELECT id, description, meta_json FROM ' . MP_Lead_Intake_DB::activity_log_table() . ' WHERE action = %s AND id > %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$action,
			(int) $od_id
		),
		ARRAY_A
	);

	if ( ! is_array( $wiersz ) ) {
		return array();
	}

	$wiersz['meta'] = (array) json_decode( (string) $wiersz['meta_json'], true );

	return $wiersz;
}

$am_i_a      = new RuntimeException( 'awaria I-A' );
$am_i_slad_a = basename( $am_i_a->getFile() ) . ':' . $am_i_a->getLine();

$logger->log_exception( $am_i_a, am_kontekst(), 0 );

$am_wpis_i = am_wpis_wyjatku( $am_i_max );
$am_mail_i = end( $GLOBALS['mp_am_mail'] );

am_ok(
	isset( $am_wpis_i['meta']['file'] ) && $am_i_a->getFile() === (string) $am_wpis_i['meta']['file']
		&& isset( $am_wpis_i['meta']['line'] ) && $am_i_a->getLine() === (int) $am_wpis_i['meta']['line'],
	'I1: meta_json wpisu niesie plik i linie wyjatku',
	'meta=' . ( isset( $am_wpis_i['meta_json'] ) ? $am_wpis_i['meta_json'] : 'brak wiersza' )
);
am_ok(
	isset( $am_wpis_i['description'] ) && false !== strpos( (string) $am_wpis_i['description'], $am_i_slad_a ),
	'I2: opis wpisu nazywa miejsce, ktorego uzywa ogranicznik',
	'opis=' . ( isset( $am_wpis_i['description'] ) ? $am_wpis_i['description'] : 'brak wiersza' )
);
am_ok(
	is_array( $am_mail_i ) && false !== strpos( (string) $am_mail_i['subject'], $am_i_slad_a ),
	'I3: temat maila tez, bo po nim administrator rozpoznaje watek',
	'temat=' . ( is_array( $am_mail_i ) ? $am_mail_i['subject'] : 'brak maila' )
);

/*
 * Sedno ustalenia: DWIE rozne awarie poza znanym dzialem wygladaly dla odbiorcy
 * jak jedna i ta sama. Temat i pierwsze zdanie tresci byly identyczne, a stopka
 * mowila, ze powtorki z tego samego miejsca sa wyciszone — wiec drugi mail
 * czytalo sie jako duplikat pierwszego i zamykalo watek. Druga awaria zostawala
 * bez obslugi.
 */
$am_i_b      = new RuntimeException( 'awaria I-B' );
$am_i_slad_b = basename( $am_i_b->getFile() ) . ':' . $am_i_b->getLine();

$logger->log_exception( $am_i_b, am_kontekst(), 0 );

$am_mail_i2 = end( $GLOBALS['mp_am_mail'] );

am_ok(
	$am_i_slad_a !== $am_i_slad_b,
	'I4: (zalozenie testu) oba wyjatki powstaly w roznych liniach',
	$am_i_slad_a . ' vs ' . $am_i_slad_b
);
am_ok(
	is_array( $am_mail_i2 ) && is_array( $am_mail_i ) && $am_mail_i['subject'] !== $am_mail_i2['subject'],
	'I5: dwie rozne awarie bez ustalonego dzialu maja ROZNE tematy',
	'temat1=' . ( is_array( $am_mail_i ) ? $am_mail_i['subject'] : '?' ) . ' | temat2=' . ( is_array( $am_mail_i2 ) ? $am_mail_i2['subject'] : '?' )
);

/*
 * KONTR-ASERCJA. Gdy dzial JEST ustalony, tozsamoscia miejsca jest jego numer —
 * i tak ma zostac. Doklejanie tam pliku i linii zamienialoby czytelne „dziale 7"
 * w szum, a przy okazji rozbijaloby jeden kubelek wyciszania na wiele.
 */
$am_i_c = new RuntimeException( 'awaria I-C' );

$logger->log_exception( $am_i_c, am_kontekst(), 7 );

$am_mail_i3 = end( $GLOBALS['mp_am_mail'] );
$am_wpis_i3 = am_wpis_wyjatku( $am_i_max );

am_ok(
	is_array( $am_mail_i3 ) && false !== mb_stripos( (string) $am_mail_i3['subject'], 'dziale 7' )
		&& false === strpos( (string) $am_mail_i3['subject'], basename( $am_i_c->getFile() ) ),
	'I6: KONTR-ASERCJA — znany dzial nadal przedstawia sie numerem, bez pliku i linii',
	'temat=' . ( is_array( $am_mail_i3 ) ? $am_mail_i3['subject'] : 'brak maila' )
);
am_ok(
	isset( $am_wpis_i3['meta']['file'] ) && $am_i_c->getFile() === (string) $am_wpis_i3['meta']['file'],
	'I7: ale w meta_json pochodzenie jest zawsze — to material diagnostyczny, nie tekst dla czytelnika',
	'meta=' . ( isset( $am_wpis_i3['meta_json'] ) ? $am_wpis_i3['meta_json'] : 'brak wiersza' )
);

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== J. wyciszony alarm musi zostawic slad ===';

/*
 * Ogranicznik konczyl metode `return`-em bez zadnego zapisu, wiec wpis o awarii
 * wygladal DOKLADNIE tak samo jak ten, przy ktorym alarm faktycznie poszedl.
 * Dziennik nie pozwalal odroznic „alarm wyslany" od „alarm pominiety" — czyli
 * dokladnie tego, przed czym broni docblock log_alert_failure(). Ostrzezenie
 * o wyciszeniu istnialo wylacznie w stopce maila, ktory trafial do skrzynki
 * administratora; pracownik czytajacy historie po incydencie nie mial go skad
 * wziac.
 */
am_reset();
$am_j_max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$am_j     = new RuntimeException( 'awaria J' );

$logger->log_exception( $am_j, am_kontekst(), 0 );
$am_wpis_j1 = am_wpis_wyjatku( $am_j_max );
$am_mail_j1 = count( $GLOBALS['mp_am_mail'] );

$logger->log_exception( $am_j, am_kontekst(), 0 );
$am_wpis_j2 = am_wpis_wyjatku( (int) $am_wpis_j1['id'] );
$am_mail_j2 = count( $GLOBALS['mp_am_mail'] );

am_ok(
	isset( $am_wpis_j1['meta']['alarm'] ) && 'wyslany' === (string) $am_wpis_j1['meta']['alarm'],
	'J1: pierwszy wpis mowi, ze alarm FAKTYCZNIE poszedl (nie prognoza)',
	'meta=' . ( isset( $am_wpis_j1['meta_json'] ) ? $am_wpis_j1['meta_json'] : 'brak wiersza' )
);
am_ok(
	isset( $am_wpis_j2['meta']['alarm'] ) && 'wyciszony' === (string) $am_wpis_j2['meta']['alarm'],
	'J2: drugi wpis mowi, ze alarmu NIE wyslano, bo ogranicznik',
	'meta=' . ( isset( $am_wpis_j2['meta_json'] ) ? $am_wpis_j2['meta_json'] : 'brak wiersza' )
);
am_ok(
	isset( $am_wpis_j1['id'], $am_wpis_j2['id'] ) && (int) $am_wpis_j1['id'] !== (int) $am_wpis_j2['id'],
	'J3: (zalozenie testu) to dwa rozne wpisy, a nie dwa odczyty tego samego'
);
am_ok(
	1 === $am_mail_j1 && 1 === $am_mail_j2,
	'J4: KONTR-ASERCJA — ogranicznik dalej ogranicza: jedna wiadomosc, nie dwie',
	'po pierwszym=' . $am_mail_j1 . ' po drugim=' . $am_mail_j2
);

/*
 * Ta sama cisza byla na drugiej sciezce — przy zatrzymaniu dzialu. Wpis
 * `pipeline_error` powstaje przed `notify_admin()`, wiec i on nie mowil nic
 * o losie alarmu.
 */
$am_dzial  = MP_Department_11::build();
$am_wynik  = MP_Result::fail( 'alarm-mowi-prawde', array(), 'test_code' );
$am_j2_max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_transient( 'mp_notify_' . $am_dzial->get_key() );

$logger->log_failure( $am_dzial, $am_wynik, am_kontekst() );
$am_wpis_j3 = am_wpis_wyjatku( $am_j2_max, 'pipeline_error' );

$logger->log_failure( $am_dzial, $am_wynik, am_kontekst() );
$am_wpis_j4 = am_wpis_wyjatku( (int) $am_wpis_j3['id'], 'pipeline_error' );

am_ok(
	isset( $am_wpis_j3['meta']['alarm'] ) && 'wyslany' === (string) $am_wpis_j3['meta']['alarm'],
	'J5: zatrzymanie dzialu — pierwszy wpis mowi o FAKTYCZNIE wyslanym alarmie',
	'meta=' . ( isset( $am_wpis_j3['meta_json'] ) ? $am_wpis_j3['meta_json'] : 'brak wiersza' )
);
am_ok(
	isset( $am_wpis_j4['meta']['alarm'] ) && 'wyciszony' === (string) $am_wpis_j4['meta']['alarm'],
	'J6: powtorka w oknie 15 minut zostawia slad wyciszenia',
	'meta=' . ( isset( $am_wpis_j4['meta_json'] ) ? $am_wpis_j4['meta_json'] : 'brak wiersza' )
);

delete_transient( 'mp_notify_' . $am_dzial->get_key() );

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== K. wyjatek bez komunikatu nie moze urwac zdania ===';

/*
 * `throw new RuntimeException();` daje getMessage() === '' — przypadek zwyczajny
 * u subskrybenta spoza wtyczki, ktory dokladnie po to jest w docblocku wymieniony.
 * Opis wpisu konczyl sie wtedy dwukropkiem („Nieoczekiwany wyjatek w dziale 4:"),
 * a mail mial pusta linie „Komunikat:". Klasa wyjatku — jedyna rzecz, ktora
 * cokolwiek mowi — zostawala w meta_json, ktorego lista wpisow nie pokazuje.
 */
am_reset();
$am_k_max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception( new RuntimeException( '' ), am_kontekst(), 4 );

$am_wpis_k = am_wpis_wyjatku( $am_k_max );
$am_mail_k = end( $GLOBALS['mp_am_mail'] );
$am_opis_k = isset( $am_wpis_k['description'] ) ? (string) $am_wpis_k['description'] : '';

am_ok(
	'' !== $am_opis_k && ':' !== substr( rtrim( $am_opis_k ), -1 ),
	'K1: opis wpisu nie urywa sie na dwukropku',
	'opis=' . $am_opis_k
);
am_ok(
	false !== mb_stripos( $am_opis_k, 'bez komunikatu' ) && false !== strpos( $am_opis_k, 'RuntimeException' ),
	'K2: zamiast pustki jest to, co kod wie: brak tresci i klasa wyjatku',
	'opis=' . $am_opis_k
);
am_ok(
	is_array( $am_mail_k ) && false === strpos( (string) $am_mail_k['message'], "Komunikat: \n" )
		&& false === strpos( (string) $am_mail_k['message'], "Komunikat:\n" ),
	'K3: mail nie ma pustej linii „Komunikat:"',
	'tresc=' . ( is_array( $am_mail_k ) ? $am_mail_k['message'] : 'brak maila' )
);

// KONTR-ASERCJA: prawdziwy komunikat ma isc slowo w slowo, bez doklejek.
am_reset();
$am_k2_max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception( new RuntimeException( 'Unsupported operand types' ), am_kontekst(), 5 );

$am_wpis_k2 = am_wpis_wyjatku( $am_k2_max );
$am_mail_k2 = end( $GLOBALS['mp_am_mail'] );

am_ok(
	isset( $am_wpis_k2['description'] ) && false !== strpos( (string) $am_wpis_k2['description'], 'Unsupported operand types' )
		&& false === mb_stripos( (string) $am_wpis_k2['description'], 'bez komunikatu' ),
	'K4: KONTR-ASERCJA — komunikat z trescia idzie bez zmian',
	'opis=' . ( isset( $am_wpis_k2['description'] ) ? $am_wpis_k2['description'] : 'brak wiersza' )
);
am_ok(
	is_array( $am_mail_k2 ) && false !== strpos( (string) $am_mail_k2['message'], 'Komunikat: Unsupported operand types' ),
	'K5: KONTR-ASERCJA — i tak samo w mailu',
	'tresc=' . ( is_array( $am_mail_k2 ) ? $am_mail_k2['message'] : 'brak maila' )
);

/* ==================================================================== L
 *
 * LOS ALARMU TO FAKT, NIE PROGNOZA — I WIDAC GO NA LISCIE.
 *
 * Do 1.3.9 stan czytalo `alert_state()` PRZED wysylka, wiec wpis meldowal
 * „wysylany" takze wtedy, gdy poczta zaraz potem odmowila. Cel zapisany
 * w docblocku tamtej metody — odroznienie „alarm wyslany" od „alarm pominiety"
 * — byl nieosiagalny w obie strony: prognoza bywala nieprawdziwa, a samo
 * `meta_json` i tak nie jest pokazywane na liscie wpisow.
 */

$GLOBALS['mp_am']['lines'][] = '';
$GLOBALS['mp_am']['lines'][] = '=== L. los alarmu: fakt zamiast prognozy, widoczny w opisie ===';

am_reset();

$am_przed_l = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

add_filter( 'pre_wp_mail', '__return_false' );
$logger->log_exception( new RuntimeException( 'Awaria L' ), am_kontekst(), 4 );
remove_filter( 'pre_wp_mail', '__return_false' );

$am_wpis_l1 = am_wpis_wyjatku( $am_przed_l );

am_ok(
	isset( $am_wpis_l1['meta']['alarm'] ) && 'nieudany' === (string) $am_wpis_l1['meta']['alarm'],
	'L1: odmowa poczty daje „nieudany", a nie „wyslany"',
	'alarm=' . var_export( isset( $am_wpis_l1['meta']['alarm'] ) ? $am_wpis_l1['meta']['alarm'] : 'BRAK', true )
);

am_ok(
	isset( $am_wpis_l1['description'] ) && false !== mb_stripos( (string) $am_wpis_l1['description'], 'alarm:' ),
	'L2: los alarmu jest w OPISIE — czyli w polu, ktore lista pokazuje',
	'opis=' . ( isset( $am_wpis_l1['description'] ) ? (string) $am_wpis_l1['description'] : 'BRAK' )
);

am_ok(
	isset( $am_wpis_l1['description'] ) && false !== mb_stripos( (string) $am_wpis_l1['description'], 'nie dotar' ),
	'L3: i mowi wprost, ze alarm NIE dotarl do administratora'
);

// Sprzatanie sekcji I-L: adres administratora, ograniczniki i wpisy testowe.
update_option( 'admin_email', $stary_admin );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_t} WHERE id > %d AND action IN ('pipeline_exception','pipeline_error','admin_alert_failed')", $am_i_max ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
am_reset();

echo implode( "\n", $GLOBALS['mp_am']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_am']['pass'], $GLOBALS['mp_am']['fail'] );
echo ( 0 === $GLOBALS['mp_am']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
