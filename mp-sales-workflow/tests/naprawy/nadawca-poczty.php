<?php
/**
 * P3-G7 — wtyczka zmieniala nadawce KAZDEGO maila witryny.
 *
 * Uruchamianie: wp eval-file tests/naprawy/nadawca-poczty.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-G7  Filtry wp_mail_from / wp_mail_from_name wpiete globalnie
 *
 * `MP_SW_Mailer::register()` wpinalo oba filtry przy starcie wtyczki i zostawialo
 * je na cale zadanie. Filtr `wp_mail_from` nie zna nadawcy — dostaje wylacznie
 * adres domyslny i nie ma jak sprawdzic, CZYJA wiadomosc wlasnie leci. Kazdy
 * mail wychodzacy z witryny dostawal wiec adres i nazwe ustalone przez wtyczke
 * sprzedazowa: reset hasla, powiadomienie o komentarzu, wiadomosc z formularza
 * kontaktowego, alarm bezpieczenstwa innej wtyczki.
 *
 * Dwa realne skutki. Uzytkownik proszacy o reset hasla dostawal wiadomosc od
 * „oferty@<domena>", czyli od adresu sprzedazowego, ktory dla niego znaczy co
 * innego niz sprawa konta. A administrator, ktory wylaczy wtyczke sprzedazowa,
 * traci nadawce dobranego pod SPF/DKIM adresu we WSZYSTKICH mailach witryny —
 * awaria pojawia sie w miejscu niezwiazanym z wylaczona wtyczka.
 *
 * Wtyczka wysyla poczte w dwoch miejscach (kolejka powiadomien i alarm
 * bezpiecznika) i tylko tam ma prawo ustawiac nadawce. Filtry sa odtad wpinane
 * na czas WLASNEJ wysylki i zdejmowane po niej.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$GLOBALS['mp_np'] = array(
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
function np_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_np']['pass'];
		$GLOBALS['mp_np']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_np']['fail'];
	$GLOBALS['mp_np']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Czy filtry nadawcy wtyczki sa aktualnie wpiete.
 *
 * @return bool
 */
function np_filtry_wpiete() {
	return false !== has_filter( 'wp_mail_from', array( 'MP_SW_Mailer', 'from_address' ) )
		|| false !== has_filter( 'wp_mail_from_name', array( 'MP_SW_Mailer', 'from_name' ) );
}

/*
 * Poczta nie wychodzi z testu. `pre_wp_mail` przerywa wysylke PRZED PHPMailerem
 * i oddaje sukces, a przy okazji jest jedynym miejscem, z ktorego widac stan
 * filtrow DOKLADNIE w chwili wysylki.
 */
$GLOBALS['mp_np_zlapane'] = array();

add_filter(
	'pre_wp_mail',
	function ( $krotki_obieg, $atts ) {
		$GLOBALS['mp_np_zlapane'][] = array(
			'to'        => isset( $atts['to'] ) ? $atts['to'] : '',
			'filtry'    => np_filtry_wpiete(),
			'nadawca'   => apply_filters( 'wp_mail_from', 'wordpress@example.com' ),
			'nazwa'     => apply_filters( 'wp_mail_from_name', 'WordPress' ),
		);

		return true;
	},
	10,
	2
);

$GLOBALS['mp_np']['lines'][] = '=== A. po starcie wtyczki filtry nie wisza na globalnym haku ===';

np_ok(
	false === has_filter( 'wp_mail_from', array( 'MP_SW_Mailer', 'from_address' ) ),
	'wp_mail_from bez filtru wtyczki',
	'priorytet=' . var_export( has_filter( 'wp_mail_from', array( 'MP_SW_Mailer', 'from_address' ) ), true )
);
np_ok(
	false === has_filter( 'wp_mail_from_name', array( 'MP_SW_Mailer', 'from_name' ) ),
	'wp_mail_from_name bez filtru wtyczki',
	'priorytet=' . var_export( has_filter( 'wp_mail_from_name', array( 'MP_SW_Mailer', 'from_name' ) ), true )
);

$GLOBALS['mp_np_zlapane'] = array();
wp_mail( 'ktos@przyklad.test', 'Reset hasla', 'Tresc spoza wtyczki' );
$obcy = isset( $GLOBALS['mp_np_zlapane'][0] ) ? $GLOBALS['mp_np_zlapane'][0] : array();

np_ok(
	! empty( $obcy ) && false === $obcy['filtry'],
	'mail SPOZA wtyczki (np. reset hasla) idzie z nadawca witryny',
	'nadawca=' . ( isset( $obcy['nadawca'] ) ? $obcy['nadawca'] : '?' )
		. ' nazwa=' . ( isset( $obcy['nazwa'] ) ? $obcy['nazwa'] : '?' )
);
np_ok(
	! empty( $obcy ) && 'WordPress' === $obcy['nazwa'],
	'nazwa nadawcy obcego maila nie jest podmieniana na nazwe witryny',
	'nazwa=' . ( isset( $obcy['nazwa'] ) ? $obcy['nazwa'] : '?' )
);

$GLOBALS['mp_np']['lines'][] = '';
$GLOBALS['mp_np']['lines'][] = '=== B. KONTR-ASERCJE: wlasna poczta wtyczki ma wlasnego nadawce ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na skasowaniu filtrow. Sekcja A
 * przeszlaby, a powiadomienia o ofertach zaczelyby wychodzic z domyslnego
 * `wordpress@<host>` — adresu, ktorego zwykle nie obejmuje SPF ani DKIM,
 * czyli prosto do spamu kontrahenta.
 */
np_ok(
	method_exists( 'MP_SW_Mailer', 'send' ),
	'MP_SW_Mailer::send() istnieje — jedno miejsce na wlasna wysylke'
);

if ( ! method_exists( 'MP_SW_Mailer', 'send' ) ) {
	$GLOBALS['mp_np']['lines'][] = '  (dalsze asercje pominiete — brak metody)';
} else {
	$GLOBALS['mp_np_zlapane'] = array();
	MP_SW_Mailer::send( 'klient@przyklad.test', 'Oferta', 'Tresc wtyczki' );
	$wlasny = isset( $GLOBALS['mp_np_zlapane'][0] ) ? $GLOBALS['mp_np_zlapane'][0] : array();

	np_ok(
		! empty( $wlasny ) && true === $wlasny['filtry'],
		'przy WLASNEJ wysylce filtry nadawcy sa wpiete',
		'nadawca=' . ( isset( $wlasny['nadawca'] ) ? $wlasny['nadawca'] : '?' )
	);
	np_ok(
		! empty( $wlasny ) && 'WordPress' !== $wlasny['nazwa'],
		'wlasna wiadomosc ma nazwe nadawcy z ustawien witryny',
		'nazwa=' . ( isset( $wlasny['nazwa'] ) ? $wlasny['nazwa'] : '?' )
	);
	np_ok(
		! np_filtry_wpiete(),
		'po wysylce filtry sa zdjete — nie zostaja na reszte zadania'
	);
}

$GLOBALS['mp_np']['lines'][] = '';
$GLOBALS['mp_np']['lines'][] = '=== C. kolejka powiadomien korzysta z tej samej drogi ===';

/*
 * Sama metoda to za malo: mogla by istniec, a kolejka i tak wolalaby wp_mail()
 * wprost. Sprawdzamy PRZEBIEG kolejki, bo to on wysyla do klientow.
 */
$tabela = MP_Sales_Workflow_DB::notifications_table();
$teraz  = current_time( 'mysql', true );

$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	MP_Sales_Workflow_DB::flow_table(),
	array(
		'lead_id'      => 999201,
		'status'       => MP_Sales_Workflow_DB::STATUS_NEW,
		'client_name'  => 'Firma Nadawca',
		'client_email' => 'nadawca@test.local',
		'offer_number' => 'OF/2999/NP',
		'lock_version' => 1,
		'created_at'   => $teraz,
		'updated_at'   => $teraz,
	)
);
$flow_id = (int) $wpdb->insert_id;

$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$tabela,
	array(
		'flow_id'           => $flow_id,
		'template'          => 'test.nadawca',
		'template_version'  => '1.0.0',
		'lang'              => 'pl',
		'recipient'         => 'nadawca@test.local',
		'recipient_user_id' => null,
		'subject'           => 'Test nadawcy',
		'body'              => 'Tresc',
		'status'            => MP_SW_Queue::STATUS_QUEUED,
		'attempts'          => 0,
		'created_at'        => $teraz,
		'updated_at'        => $teraz,
	)
);
$wiersz_id = (int) $wpdb->insert_id;

$GLOBALS['mp_np_zlapane'] = array();
MP_SW_Mailer::resume();
delete_transient( MP_SW_Mailer::WINDOW_KEY );
MP_SW_Queue::run();

$z_kolejki = array();

foreach ( $GLOBALS['mp_np_zlapane'] as $zlapane ) {
	if ( 'nadawca@test.local' === $zlapane['to'] ) {
		$z_kolejki = $zlapane;
		break;
	}
}

np_ok(
	! empty( $z_kolejki ),
	'kolejka wyslala wiersz testowy',
	'zlapano=' . count( $GLOBALS['mp_np_zlapane'] )
);
np_ok(
	! empty( $z_kolejki ) && true === $z_kolejki['filtry'],
	'wiadomosc z kolejki idzie z nadawca wtyczki',
	'nazwa=' . ( isset( $z_kolejki['nazwa'] ) ? $z_kolejki['nazwa'] : '?' )
);
np_ok(
	! np_filtry_wpiete(),
	'po przebiegu kolejki filtry sa zdjete'
);

$wpdb->delete( $tabela, array( 'id' => $wiersz_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->delete( MP_Sales_Workflow_DB::flow_table(), array( 'id' => $flow_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_transient( MP_SW_Mailer::WINDOW_KEY );

echo implode( "\n", $GLOBALS['mp_np']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_np']['pass'], $GLOBALS['mp_np']['fail'] );
echo ( 0 === $GLOBALS['mp_np']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
