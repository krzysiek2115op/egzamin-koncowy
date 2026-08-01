<?php
/**
 * P3-G9 (B8, druga polowa) — schemat obiecywal odtwarzanie odpowiedzi, ktorego nie ma.
 *
 * Uruchamianie: wp eval-file tests/naprawy/schemat-bez-obietnic.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-G9  Kolumna result_json zadeklarowana w schemacie, nieuzywana przez kod
 *
 * Komentarz przy tabeli zdarzen mowil wprost: „`result_json` przechowuje
 * odpowiedz zwrocona przy pierwszym przebiegu: powtorka tego samego zdarzenia
 * ma oddac ten sam wynik". Kolumna istniala, ale NIKT jej nie zapisywal ani nie
 * czytal — `grep result_json includes/` trafial wylacznie w schemat. Powtorka
 * dostawala 409 `duplicate_event`.
 *
 * DECYZJA: obietnica znika ze schematu, zachowanie zostaje. Odtwarzanie
 * odpowiedzi wymagaloby dwoch rzeczy, z ktorych kazda lamie inne, wczesniejsze
 * ustalenie klienta:
 *
 *   1. Odpowiedz HTTP powstaje PO calym pipelinie (`MP_SW_Events::payload()`),
 *      a wiersz zdarzenia zapisuje sie w transakcji Dzialu 8. Zapamietanie jej
 *      znaczyloby DRUGI zapis, juz po COMMIT-cie — a Dzial 8 ma kryterium
 *      `db_writes = 1` i bramka jakosci to mierzy.
 *   2. Oddanie zapamietanego wyniku przy odmowie wymagaloby dolozenia pol do
 *      odpowiedzi bledu — a ta jest celowo uboga: szczegoly ida do dziennika,
 *      nie do wywolujacego, zeby odmowa nie odsylala mu cudzego adresu e-mail
 *      (patrz komentarz w `MP_SW_Events::payload()`).
 *
 * Gwarancja niepowtarzalnosci dziala i bez tej kolumny: daje ja UNIQUE na
 * `event_id` wewnatrz transakcji. Ten test tego pilnuje — usuniecie kolumny
 * nie ma prawa oslabic idempotencji.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_sbo'] = array(
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
function sbo_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_sbo']['pass'];
		$GLOBALS['mp_sbo']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_sbo']['fail'];
	$GLOBALS['mp_sbo']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

$katalog = dirname( __DIR__ ) . '/../includes';

/**
 * Kod wtyczki bez komentarzy — inaczej alarm zapala wlasny opis naprawy.
 *
 * @param string $katalog Katalog zrodel.
 * @return string
 */
function sbo_kod_bez_komentarzy( $katalog ) {
	$kod   = '';
	$pliki = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $katalog ) );

	foreach ( $pliki as $plik ) {
		if ( $plik->isDir() || 'php' !== $plik->getExtension() ) {
			continue;
		}

		foreach ( token_get_all( (string) file_get_contents( $plik->getPathname() ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$kod .= is_array( $token ) ? $token[1] : $token;
		}
	}

	return $kod;
}

$GLOBALS['mp_sbo']['lines'][] = '=== A. schemat nie deklaruje kolumny, ktorej nikt nie uzywa ===';

$kod_wykonywalny = sbo_kod_bez_komentarzy( $katalog );

/*
 * Jedno miejsce ma prawo znac te nazwe: migracja, ktora kolumne KASUJE. Musi ja
 * wymienic, bo dbDelta() kolumn nie usuwa. Wycinamy wiec cialo tej metody i
 * dopiero reszte kodu sprawdzamy — inaczej test zapalalby alarm na wlasnej
 * naprawie, dokladnie tak jak skan `strpos()`-em po komentarzach.
 */
$od_migracji = strpos( $kod_wykonywalny, 'function maybe_drop_result_json' );
$reszta      = $kod_wykonywalny;

if ( false !== $od_migracji ) {
	// Koniec ciala = poczatek NASTEPNEJ metody w tym samym pliku.
	$po_naglowku = $od_migracji + strlen( 'function maybe_drop_result_json' );
	$nastepna    = strpos( $kod_wykonywalny, 'function ', $po_naglowku );
	$reszta      = substr( $kod_wykonywalny, 0, $od_migracji );
	$reszta     .= false === $nastepna ? '' : substr( $kod_wykonywalny, $nastepna );

	// Zostaje jeszcze samo WYWOLANIE migracji w install() — nazwa metody niesie
	// nazwe kolumny. To nie jest uzycie kolumny, wiec nie ma prawa zapalac alarmu.
	$reszta = str_replace( 'maybe_drop_result_json', '', $reszta );
}

sbo_ok(
	false !== $od_migracji,
	'istnieje jawna migracja usuwajaca kolumne (dbDelta() sama tego nie robi)'
);
sbo_ok(
	false === strpos( $reszta, 'result_json' ),
	'`result_json` nie wystepuje nigdzie poza ta migracja — ani w DDL, ani w zapisie, ani w odczycie',
	'znaleziono w kodzie wykonywalnym poza migracja'
);

// I w zywej bazie — po migracji kolumny ma nie byc.
global $wpdb;
$tabela   = MP_Sales_Workflow_DB::events_table();
$kolumny  = (array) $wpdb->get_results( "SHOW COLUMNS FROM {$tabela}", ARRAY_A ); // phpcs:ignore
$nazwy    = array();

foreach ( $kolumny as $kolumna ) {
	$nazwy[] = (string) $kolumna['Field'];
}

sbo_ok(
	! in_array( 'result_json', $nazwy, true ),
	'kolumna result_json zniknela z tabeli zdarzen w bazie',
	'kolumny: ' . implode( ', ', $nazwy )
);

$GLOBALS['mp_sbo']['lines'][] = '';
$GLOBALS['mp_sbo']['lines'][] = '=== B. KONTR-ASERCJA: idempotencja dziala dalej ===';

/*
 * Sedno: kolumna byla martwa, a gwarancja niepowtarzalnosci nie stala na niej,
 * tylko na UNIQUE wewnatrz transakcji. Gdyby usuniecie kolumny cokolwiek
 * zepsulo, wyszloby tutaj.
 */
$uq = null;

foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$tabela}", ARRAY_A ) as $indeks ) { // phpcs:ignore
	if ( 'uq_event_id' === (string) $indeks['Key_name'] ) {
		$uq = $indeks;
	}
}

sbo_ok(
	null !== $uq && '0' === (string) $uq['Non_unique'] && 'event_id' === (string) $uq['Column_name'],
	'UNIQUE uq_event_id nadal stoi na kolumnie event_id',
	null === $uq ? 'brak indeksu' : wp_json_encode( $uq )
);

$lead_id = 900000 + wp_rand( 1, 90000 );
$id_zdar = wp_generate_uuid4();

// Sprzatanie po fatalu — inaczej wiersze testowe zostaja w BD-1.
register_shutdown_function(
	function () use ( $lead_id ) {
		global $wpdb;
		$flow = MP_Sales_Workflow_DB::flow_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$flow} WHERE lead_id = %d", $lead_id ) ); // phpcs:ignore
	}
);

// `lead.created` wchodzi KANALEM HAKA — z HTTP zostalby odrzucony jako
// origin_forbidden (S1 w tescie bezpieczenstwa tego pilnuje).
$pierwsze = MP_SW_Events::from_hook(
	MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED,
	array(
		'entity'   => array( 'lead_id' => $lead_id ),
		'actor'    => array( 'user_id' => 0 ),
		'event_id' => $id_zdar,
	)
);

sbo_ok(
	$pierwsze['result']->is_ok(),
	'pierwsze zdarzenie przechodzi',
	'kod=' . $pierwsze['result']->get_code()
);

$powtorka = MP_SW_Events::from_hook(
	MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED,
	array(
		'entity'   => array( 'lead_id' => $lead_id ),
		'actor'    => array( 'user_id' => 0 ),
		'event_id' => $id_zdar,
	)
);

$dane_powtorki = $powtorka['result']->get_data();

sbo_ok(
	! $powtorka['result']->is_ok(),
	'powtorka tego samego event_id nie wykonuje pracy drugi raz',
	'wynik=' . ( $powtorka['result']->is_ok() ? 'OK' : 'odmowa' )
);
sbo_ok(
	'duplicate_event' === $powtorka['result']->get_code(),
	'powtorka rozpoznana jako duplicate_event',
	'kod=' . $powtorka['result']->get_code()
);
sbo_ok(
	isset( $dane_powtorki['http_status'] ) && 409 === (int) $dane_powtorki['http_status'],
	'powtorka wychodzi jako 409, nie jako blad wewnetrzny',
	'http_status=' . ( isset( $dane_powtorki['http_status'] ) ? $dane_powtorki['http_status'] : '(brak)' )
);

$odpowiedz = MP_SW_Events::payload( $powtorka['result'], $powtorka['context'] );

sbo_ok(
	isset( $odpowiedz['code'] ) && MP_SW_Errors::E_INTERNAL !== $odpowiedz['code'],
	'powtorka ma wlasny kod publiczny, nie MP3-E500',
	'code=' . ( isset( $odpowiedz['code'] ) ? $odpowiedz['code'] : '(brak)' )
);

// Dokladnie jeden wiersz zdarzenia na dwa wywolania — o to w idempotencji chodzi.
$ile = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabela} WHERE event_id = %s", $id_zdar ) ); // phpcs:ignore

sbo_ok(
	1 === $ile,
	'w rejestrze zdarzen stoi dokladnie jeden wiersz na dwa wywolania',
	'wierszy=' . $ile
);

$GLOBALS['mp_sbo']['lines'][] = '';
$GLOBALS['mp_sbo']['lines'][] = '=== C. schemat i baza zgadzaja sie co do reszty kolumn ===';

/*
 * Migracja usuwajaca kolumne musi byc jawna: dbDelta() kolumn NIE kasuje, wiec
 * bez ALTER-a stara instalacja zostalaby z martwa kolumna, a nowa jej nie mialaby
 * — dwa rozne schematy pod ta sama wersja. Ten test uruchamia sie na bazie, ktora
 * przeszla migracje, wiec porownanie DDL z rzeczywistoscia jest tu sprawdzeniem
 * TEJ migracji, nie samego pliku.
 */
// `schema()` jest prywatne i takie ma zostac — DDL czytamy wprost ze zrodla.
$zrodlo_db = (string) file_get_contents( $katalog . '/db/class-mp-sales-workflow-db.php' );
$od        = strpos( $zrodlo_db, 'CREATE TABLE {$events}' );
$ddl       = false === $od ? '' : substr( $zrodlo_db, $od, (int) strpos( substr( $zrodlo_db, $od ), ';' ) );

sbo_ok(
	'' !== $ddl,
	'znaleziono DDL tabeli zdarzen w zrodle'
);

$brakujace = array();

foreach ( $nazwy as $kolumna ) {
	if ( false === strpos( $ddl, $kolumna ) ) {
		$brakujace[] = $kolumna;
	}
}

sbo_ok(
	array() === $brakujace,
	'kazda kolumna z bazy jest zadeklarowana w schemacie',
	'w bazie, poza schematem: ' . implode( ', ', $brakujace )
);

echo implode( "\n", $GLOBALS['mp_sbo']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_sbo']['pass'], $GLOBALS['mp_sbo']['fail'] );
echo ( 0 === $GLOBALS['mp_sbo']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
