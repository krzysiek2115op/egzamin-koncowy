<?php
/**
 * P2-G13 — alarmy do administratora mowily rzeczy, ktorych kod nie sprawdzil.
 *
 * Uruchamianie: wp eval-file tests/naprawy/alarm-mowi-prawde.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-G13  Cztery komunikaty logera pipeline wtyczki 2: „dzial 0", zmyslona przyczyna
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
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_ob_am_miejsca'] = array();

$GLOBALS['mp_ob_am'] = array(
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
function oam_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_ob_am']['pass'];
		$GLOBALS['mp_ob_am']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_ob_am']['fail'];
	$GLOBALS['mp_ob_am']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

$log_t         = MP_Offer_Builder_DB::activity_log_table();
$stary_admin   = get_option( 'admin_email' );
$GLOBALS['mp_ob_am_mail']   = array();
$GLOBALS['mp_ob_am_powodzi'] = true;

/*
 * Poczta nie wychodzi z testu. pre_wp_mail przerywa wysylke przed PHPMailerem,
 * oddaje zadany wynik i pozwala obejrzec temat oraz tresc DOKLADNIE takie,
 * jakie dostalby administrator.
 */
add_filter(
	'pre_wp_mail',
	function ( $krotki_obieg, $atts ) {
		$GLOBALS['mp_ob_am_mail'][] = array(
			'subject' => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
			'message' => isset( $atts['message'] ) ? (string) $atts['message'] : '',
		);

		return (bool) $GLOBALS['mp_ob_am_powodzi'];
	},
	10,
	2
);

/**
 * Czysci ograniczniki czestotliwosci i zebrane wiadomosci.
 *
 * @return void
 */
function oam_reset() {
	/*
	 * Ogranicznik wyjatkow ma klucz OSOBNY DLA DZIALU (P2-S9), wiec sprzatanie
	 * musi objac cala numeracje: 0 to „dzial nieustalony", 1-11 to pipeline.
	 * Pominiecie tego zostawialo transient na kwadrans i NASTEPNE uruchomienie
	 * testu widzialo alarm jako wyciszony — czyli test padal z powodu swojego
	 * poprzedniego przebiegu, a nie z powodu kodu.
	 */
	for ( $dz = 0; $dz <= 11; $dz++ ) {
		delete_transient( 'mp_ob_notify_exception_' . $dz );
	}

	delete_transient( 'mp_ob_notify_exception' );
	delete_transient( 'mp_ob_notify_dzial-testowy' );

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
			$wpdb->esc_like( '_transient_mp_ob_notify_exception_' ) . '%'
		)
	);
	foreach ( (array) $klucze as $klucz ) {
		delete_transient( substr( $klucz, strlen( '_transient_' ) ) );
	}
	$GLOBALS['mp_ob_am_mail'] = array();
}

/**
 * Ostatni wpis dziennika o niedostarczonym alarmie.
 *
 * @param int $od_id Identyfikator, od ktorego szukamy.
 * @return array
 */
function oam_wpis_o_alarmie( $od_id ) {
	global $wpdb;

	$wiersz = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			'SELECT id, description, meta_json FROM ' . MP_Offer_Builder_DB::activity_log_table() . ' WHERE action = %s AND id > %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
function oam_kontekst() {
	return new MP_OB_Context(
		array(
			'offer_id'   => 4242,
			'request_id' => 'req-testowy-1',
		)
	);
}

$logger = new MP_OB_Pipeline_Logger();
$max_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$GLOBALS['mp_ob_am']['lines'][] = '=== A. miejsce awarii nieustalone: zadnego „dzialu 0" ===';

update_option( 'admin_email', 'admin@example.test' );
oam_reset();
$GLOBALS['mp_ob_am_powodzi'] = true;

$logger->log_exception( new RuntimeException( 'Awaria u subskrybenta' ), oam_kontekst(), 0 );

$mail_zerowy = isset( $GLOBALS['mp_ob_am_mail'][0] ) ? $GLOBALS['mp_ob_am_mail'][0] : array();

oam_ok( ! empty( $mail_zerowy ), 'alarm o wyjatku zostal wyslany' );
oam_ok(
	! empty( $mail_zerowy ) && false === strpos( $mail_zerowy['subject'], 'dział 0' ),
	'temat NIE podaje nieistniejacego „dzialu 0"',
	'temat=' . ( isset( $mail_zerowy['subject'] ) ? $mail_zerowy['subject'] : '?' )
);
oam_ok(
	! empty( $mail_zerowy ) && false === strpos( $mail_zerowy['message'], 'dziale 0' ),
	'tresc NIE podaje nieistniejacego „dzialu 0"',
	'tresc=' . ( isset( $mail_zerowy['message'] ) ? $mail_zerowy['message'] : '?' )
);
oam_ok(
	! empty( $mail_zerowy ) && false !== mb_stripos( $mail_zerowy['subject'], 'nieustalon' ),
	'zamiast tego mowi wprost, ze miejsca nie ustalono',
	'temat=' . ( isset( $mail_zerowy['subject'] ) ? $mail_zerowy['subject'] : '?' )
);

$opis_wpisu = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare( "SELECT description FROM {$log_t} WHERE action = %s ORDER BY id DESC LIMIT 1", 'pipeline_exception' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

oam_ok(
	false === strpos( $opis_wpisu, 'dziale 0' ),
	'wpis w dzienniku tez nie zmysla numeru dzialu',
	'opis=' . $opis_wpisu
);

$GLOBALS['mp_ob_am']['lines'][] = '';
$GLOBALS['mp_ob_am']['lines'][] = '=== B. alarm identyfikuje zgloszenie i uprzedza o wyciszeniu ===';

oam_ok(
	! empty( $mail_zerowy ) && false !== strpos( $mail_zerowy['message'], '4242' ),
	'tresc alarmu niesie offer_id',
	'tresc=' . ( isset( $mail_zerowy['message'] ) ? $mail_zerowy['message'] : '?' )
);
oam_ok(
	! empty( $mail_zerowy ) && false !== strpos( $mail_zerowy['message'], 'req-testowy-1' ),
	'tresc alarmu niesie identyfikator zadania'
);
oam_ok(
	! empty( $mail_zerowy ) && false !== mb_stripos( $mail_zerowy['message'], 'wyciszone' ),
	'tresc uprzedza, ze kolejne alarmy sa przez kwadrans wyciszone',
	'tresc=' . ( isset( $mail_zerowy['message'] ) ? $mail_zerowy['message'] : '?' )
);

$GLOBALS['mp_ob_am']['lines'][] = '';
$GLOBALS['mp_ob_am']['lines'][] = '=== C. niedostarczony alarm nie zmysla przyczyny ===';

oam_reset();
$GLOBALS['mp_ob_am_powodzi'] = false;
$przed_c                  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception( new RuntimeException( 'Awaria druga' ), oam_kontekst(), 3 );

$wpis_c = oam_wpis_o_alarmie( $przed_c );

oam_ok(
	! empty( $wpis_c ),
	'nieudana wysylka zostawia slad w dzienniku'
);
oam_ok(
	! empty( $wpis_c ) && false === mb_stripos( $wpis_c['description'], 'serwer poczty odrzucił' ),
	'slad NIE twierdzi, ze odrzucil ja serwer poczty',
	'opis=' . ( isset( $wpis_c['description'] ) ? $wpis_c['description'] : '?' )
);
oam_ok(
	! empty( $wpis_c ) && false !== mb_stripos( $wpis_c['description'], 'wp_mail' ),
	'slad mowi, co naprawde wiadomo: wp_mail() zglosilo niepowodzenie',
	'opis=' . ( isset( $wpis_c['description'] ) ? $wpis_c['description'] : '?' )
);

$GLOBALS['mp_ob_am']['lines'][] = '';
$GLOBALS['mp_ob_am']['lines'][] = '=== D. brak adresu administratora to TEZ niedostarczony alarm ===';

oam_reset();
$GLOBALS['mp_ob_am_powodzi'] = true;

/*
 * Adres zdejmujemy FILTREM, nie update_option(). WordPress sanityzuje opcje
 * `admin_email` i przy wartosci, ktora nie jest adresem, PRZYWRACA poprzednia —
 * zapis pustego ciagu nie odtworzylby wiec badanego stanu, a test mierzylby
 * zachowanie przy poprawnym adresie i cicho przechodzil.
 */
add_filter( 'option_admin_email', '__return_empty_string', 99 );
$przed_d = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception( new RuntimeException( 'Awaria trzecia' ), oam_kontekst(), 5 );

remove_filter( 'option_admin_email', '__return_empty_string', 99 );

$wpis_d = oam_wpis_o_alarmie( $przed_d );

oam_ok(
	! empty( $wpis_d ),
	'brak admin_email zostawia wpis admin_alert_failed, a nie cisze'
);
oam_ok(
	! empty( $wpis_d ) && false !== mb_stripos( $wpis_d['description'], 'admin_email' ),
	'wpis nazywa przyczyne: nie ma adresu administratora',
	'opis=' . ( isset( $wpis_d['description'] ) ? $wpis_d['description'] : '?' )
);
oam_ok(
	empty( $GLOBALS['mp_ob_am_mail'] ),
	'i nie probowano niczego wysylac'
);

$GLOBALS['mp_ob_am']['lines'][] = '';
$GLOBALS['mp_ob_am']['lines'][] = '=== E. KONTR-ASERCJE: prawdziwy numer dzialu i alarm dostarczony ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na wyrzuceniu numeru dzialu z
 * komunikatow w ogole albo na wpisywaniu admin_alert_failed przy KAZDEJ
 * wysylce — dziennik pelen falszywych porazek jest tak samo bezuzyteczny jak
 * dziennik milczacy.
 */
update_option( 'admin_email', 'admin@example.test' );
oam_reset();
$GLOBALS['mp_ob_am_powodzi'] = true;
$przed_e                  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception( new RuntimeException( 'Awaria czwarta' ), oam_kontekst(), 7 );

$mail_e = isset( $GLOBALS['mp_ob_am_mail'][0] ) ? $GLOBALS['mp_ob_am_mail'][0] : array();

oam_ok(
	! empty( $mail_e ) && false !== strpos( $mail_e['message'], 'dziale 7' ),
	'znany numer dzialu nadal jest podany wprost',
	'tresc=' . ( isset( $mail_e['message'] ) ? $mail_e['message'] : '?' )
);
oam_ok(
	empty( oam_wpis_o_alarmie( $przed_e ) ),
	'dostarczony alarm NIE zostawia wpisu o niedostarczeniu'
);

$GLOBALS['mp_ob_am']['lines'][] = '';
$GLOBALS['mp_ob_am']['lines'][] = '=== F. „z tego samego miejsca" ma znaczyc to, co mowi ===';

/*
 * Blizniak ustalenia z wtyczki 1 (P1-G15). Stopka alarmu obiecuje, ze wyciszone
 * sa kolejne alarmy „Z TEGO SAMEGO MIEJSCA", ale sciezka WYJATKU uzywala
 * JEDNEGO klucza na cala wtyczke: wyjatek w Dziale 4 uciszal na kwadrans
 * wyjatki z Dzialu 10. Druga, niezalezna awaria nie dawala znaku zycia.
 *
 * Sprawdzane osobno w kazdej wtyczce, bo to osobne klasy z wlasnymi kluczami —
 * wspolny test dawalby zludzenie pokrycia.
 */
foreach ( array( 4, 10 ) as $oam_dz ) {
	delete_transient( 'mp_ob_notify_exception_' . $oam_dz );
}
delete_transient( 'mp_ob_notify_exception' );
$GLOBALS['mp_ob_am_mail']    = array();
$GLOBALS['mp_ob_am_powodzi'] = true;
update_option( 'admin_email', 'admin@example.test' );

$logger->log_exception( new RuntimeException( 'Awaria w dziale 4' ), oam_kontekst(), 4 );
$oam_po_pierwszym = count( $GLOBALS['mp_ob_am_mail'] );

$logger->log_exception( new RuntimeException( 'Awaria w dziale 10' ), oam_kontekst(), 10 );
$oam_po_drugim = count( $GLOBALS['mp_ob_am_mail'] );

oam_ok( 1 === $oam_po_pierwszym, 'F1: wyjatek z Dzialu 4 wysyla alarm', 'wiadomosci=' . $oam_po_pierwszym );
oam_ok(
	2 === $oam_po_drugim,
	'F2: wyjatek z INNEGO dzialu w tym samym kwadransie tez wysyla alarm',
	'wiadomosci=' . $oam_po_drugim
);
oam_ok(
	2 === $oam_po_drugim && false !== strpos( (string) $GLOBALS['mp_ob_am_mail'][1]['message'], 'dziale 10' ),
	'F3: drugi alarm dotyczy naprawde Dzialu 10, a nie powtorki z czworki',
	'tresc=' . ( isset( $GLOBALS['mp_ob_am_mail'][1]['message'] ) ? $GLOBALS['mp_ob_am_mail'][1]['message'] : '?' )
);

/*
 * KONTR-ASERCJA: rozbicie klucza nie moze znaczyc „ogranicznik przestal
 * dzialac". Powtorka z tego samego dzialu ma dalej byc wyciszona.
 */
$logger->log_exception( new RuntimeException( 'Druga awaria w dziale 4' ), oam_kontekst(), 4 );

oam_ok(
	2 === count( $GLOBALS['mp_ob_am_mail'] ),
	'F4: kontr-asercja — powtorka z tego samego dzialu nadal jest wyciszona',
	'wiadomosci=' . count( $GLOBALS['mp_ob_am_mail'] )
);
oam_ok(
	isset( $GLOBALS['mp_ob_am_mail'][0] ) && false !== mb_stripos( (string) $GLOBALS['mp_ob_am_mail'][0]['message'], 'z tego samego miejsca' ),
	'F5: stopka nadal sklada te obietnice — i dopiero teraz jest ona prawdziwa',
	'tresc=' . ( isset( $GLOBALS['mp_ob_am_mail'][0] ) ? $GLOBALS['mp_ob_am_mail'][0]['message'] : '?' )
);

foreach ( array( 4, 10 ) as $oam_dz ) {
	delete_transient( 'mp_ob_notify_exception_' . $oam_dz );
}

$GLOBALS['mp_ob_am']['lines'][] = '';
$GLOBALS['mp_ob_am']['lines'][] = '=== G. slad o niedostarczonym alarmie da sie polaczyc z oferta ===';

/*
 * Wpis `admin_alert_failed` szedl z `offer_id = null` na sztywno i bez
 * `request_id` w `meta_json`. Alarm, ktory NIE doszedl, byl wiec jedynym
 * zdarzeniem w dzienniku, ktorego nie dalo sie przypisac do dokumentu —
 * a to wlasnie on jest momentem, w ktorym cos poszlo nie tak.
 */
foreach ( array( 4, 10, 5, 6 ) as $oam_dz ) {
	delete_transient( 'mp_ob_notify_exception_' . $oam_dz );
}
$GLOBALS['mp_ob_am_mail']    = array();
$GLOBALS['mp_ob_am_powodzi'] = false;
update_option( 'admin_email', 'admin@example.test' );

$przed_g = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception( new RuntimeException( 'Awaria z niedostarczonym alarmem' ), oam_kontekst(), 5 );

$wiersz_g = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"SELECT offer_id, meta_json FROM {$log_t} WHERE action = %s AND id > %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'admin_alert_failed',
		$przed_g
	),
	ARRAY_A
);

$meta_g = is_array( $wiersz_g ) ? (array) json_decode( (string) $wiersz_g['meta_json'], true ) : array();

oam_ok( is_array( $wiersz_g ) && ! empty( $wiersz_g ), 'G1: wpis o niedostarczonym alarmie powstal' );
oam_ok(
	is_array( $wiersz_g ) && 4242 === (int) $wiersz_g['offer_id'],
	'G2: wpis niesie offer_id oferty, nie null',
	'offer_id=' . ( is_array( $wiersz_g ) ? var_export( $wiersz_g['offer_id'], true ) : 'brak wiersza' )
);
oam_ok(
	isset( $meta_g['request_id'] ) && 'req-testowy-1' === (string) $meta_g['request_id'],
	'G3: meta_json niesie identyfikator zadania',
	'meta=' . ( is_array( $wiersz_g ) ? (string) $wiersz_g['meta_json'] : 'brak wiersza' )
);

/*
 * KONTR-ASERCJA: awaria PRZED zapisem oferty nie ma offer_id i wtedy null jest
 * jedyna poprawna wartoscia. Wpis ma wowczas stac na samym request_id.
 */
$przed_g2 = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$log_t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$logger->log_exception(
	new RuntimeException( 'Awaria przed zapisem oferty' ),
	new MP_OB_Context( array( 'request_id' => 'req-testowy-2' ) ),
	6
);

$wiersz_g2 = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"SELECT offer_id, meta_json FROM {$log_t} WHERE action = %s AND id > %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'admin_alert_failed',
		$przed_g2
	),
	ARRAY_A
);

$meta_g2 = is_array( $wiersz_g2 ) ? (array) json_decode( (string) $wiersz_g2['meta_json'], true ) : array();

oam_ok(
	is_array( $wiersz_g2 ) && null === $wiersz_g2['offer_id'],
	'G4: kontr-asercja — bez oferty kolumna zostaje pusta, nic sie nie zmysla',
	'offer_id=' . ( is_array( $wiersz_g2 ) ? var_export( $wiersz_g2['offer_id'], true ) : 'brak wiersza' )
);
oam_ok(
	isset( $meta_g2['request_id'] ) && 'req-testowy-2' === (string) $meta_g2['request_id'],
	'G5: a zadanie i tak da sie wskazac — po request_id',
	'meta=' . ( is_array( $wiersz_g2 ) ? (string) $wiersz_g2['meta_json'] : 'brak wiersza' )
);

$GLOBALS['mp_ob_am_powodzi'] = true;

// Sprzatanie: przywrocenie adresu i wpisow testowych.
update_option( 'admin_email', $stary_admin );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_t} WHERE id > %d AND action IN ('pipeline_exception','admin_alert_failed')", $max_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
oam_reset();

$GLOBALS['mp_ob_am']['lines'][] = '';
$GLOBALS['mp_ob_am']['lines'][] = '=== H. dzial NIEUSTALONY to nie jest jeden dzial ===';

/*
 * Blizniak sekcji H z wtyczki 1. `dept_num = 0` znaczy „nie wiemy, gdzie to bylo",
 * a nie „dzial numer zero" — a wszystkie takie wyjatki dzielily jeden kubelek
 * wyciszania, wiec jedna nieznana awaria uciszala druga, zupelnie niezalezna.
 */
$oam_pierwszy = new RuntimeException( 'awaria w nieznanym miejscu A' );
$oam_drugi    = new RuntimeException( 'awaria w nieznanym miejscu B' );

$GLOBALS['mp_ob_am_miejsca'] = array(
	$oam_pierwszy->getFile() . ':' . $oam_pierwszy->getLine(),
	$oam_drugi->getFile() . ':' . $oam_drugi->getLine(),
);

oam_reset();

$oam_logger = new MP_OB_Pipeline_Logger();
$oam_logger->log_exception( $oam_pierwszy, oam_kontekst(), 0 );
$oam_po_pierwszym = count( $GLOBALS['mp_ob_am_mail'] );

$oam_logger->log_exception( $oam_drugi, oam_kontekst(), 0 );
$oam_po_drugim = count( $GLOBALS['mp_ob_am_mail'] );

oam_ok(
	1 === $oam_po_pierwszym,
	'H1: pierwszy wyjatek bez ustalonego dzialu wysyla alarm',
	'wiadomosci=' . $oam_po_pierwszym
);
oam_ok(
	2 === $oam_po_drugim,
	'H2: drugi, z INNEGO miejsca, tez wysyla — nie jest wyciszony przez pierwszy',
	'wiadomosci=' . $oam_po_drugim
);

$oam_logger->log_exception( $oam_pierwszy, oam_kontekst(), 0 );

oam_ok(
	2 === count( $GLOBALS['mp_ob_am_mail'] ),
	'H3: KONTR-ASERCJA — powtorka z TEGO SAMEGO miejsca nadal milczy',
	'wiadomosci=' . count( $GLOBALS['mp_ob_am_mail'] )
);

oam_reset();

echo implode( "\n", $GLOBALS['mp_ob_am']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_ob_am']['pass'], $GLOBALS['mp_ob_am']['fail'] );
echo ( 0 === $GLOBALS['mp_ob_am']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
