<?php
/**
 * P3-G5 — okno bezpiecznika poczty nigdy sie nie zamykalo.
 *
 * Uruchamianie: wp eval-file tests/naprawy/okno-bezpiecznika.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-G5  Licznik wysylek rosl bez konca, bo kazda wysylka odnawiala TTL
 *
 * Bezpiecznik mial pilnowac progu „200 wiadomosci NA GODZINE". Licznik siedzial
 * w transiencie, a `allow_send()` zapisywalo go za kazdym razem z pelnym TTL
 * `HOUR_IN_SECONDS`. Transient wiec nigdy nie wygasal, dopoki cokolwiek
 * wychodzilo — a licznik liczyl nie „ostatnia godzine", tylko WSZYSTKIE wysylki
 * od pierwszej. Okno mialo dlugosc przerwy w ruchu, nie godziny.
 *
 * Skutek: sklep wysylajacy spokojne 30 wiadomosci na godzine przekraczal prog
 * po siedmiu godzinach pracy. Bezpiecznik zatrzymywal kolejke (`OPTION_HALTED`),
 * zawiadamial administratora o „zalewie zdarzen", ktorego nie bylo, a wysylka
 * stawala do czasu recznego wznowienia. Im lepiej szla sprzedaz, tym pewniej
 * bezpiecznik ja przerywal — i tym mniej wiarygodny byl jego alarm.
 *
 * Test steruje czasem przez pole `start` zapisane w oknie, a nie przez czekanie.
 * Godzinny test nie ma prawa trwac godziny.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_ob'] = array(
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
function ob_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_ob']['pass'];
		$GLOBALS['mp_ob']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_ob']['fail'];
	$GLOBALS['mp_ob']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Czysci stan bezpiecznika przed kazdym przypadkiem.
 *
 * @return void
 */
function ob_wyczysc() {
	delete_transient( MP_SW_Mailer::WINDOW_KEY );
	MP_SW_Mailer::resume();
}

/**
 * Ustawia okno o zadanym wieku i liczniku.
 *
 * TTL celowo PELNY — tak wlasnie wygladalo okno przy ciaglym ruchu, gdy kazda
 * wysylka odnawiala transient. Test odtwarza stan, ktory kod sam produkowal.
 *
 * @param int $wiek    Ile sekund temu okno sie zaczelo.
 * @param int $licznik Ile wysylek juz w nim odnotowano.
 * @return void
 */
function ob_ustaw_okno( $wiek, $licznik ) {
	set_transient(
		MP_SW_Mailer::WINDOW_KEY,
		array(
			'start' => time() - (int) $wiek,
			'count' => (int) $licznik,
		),
		HOUR_IN_SECONDS
	);
}

/**
 * Licznik zapisany w oknie.
 *
 * @return int
 */
function ob_licznik() {
	$okno = get_transient( MP_SW_Mailer::WINDOW_KEY );

	return is_array( $okno ) && isset( $okno['count'] ) ? (int) $okno['count'] : 0;
}

/**
 * Ile sekund zostalo do wygasniecia transientu okna.
 *
 * @return int
 */
function ob_ttl() {
	return (int) get_option( '_transient_timeout_' . MP_SW_Mailer::WINDOW_KEY ) - time();
}

$prog = MP_SW_Mailer::limit();

$GLOBALS['mp_ob']['lines'][] = '=== A. okno starsze niz godzina zaczyna sie od nowa ===';

ob_wyczysc();
ob_ustaw_okno( HOUR_IN_SECONDS + 60, $prog );

$wolno = MP_SW_Mailer::allow_send();

ob_ok(
	$wolno,
	'po godzinie wolno wyslac, choc poprzednie okno bylo pelne',
	'prog=' . $prog . ' zwrocono=' . var_export( $wolno, true )
);
ob_ok(
	1 === ob_licznik(),
	'licznik startuje od nowa, a nie od poprzedniej wartosci',
	'licznik=' . ob_licznik()
);
ob_ok(
	! MP_SW_Mailer::halted(),
	'bezpiecznik nie zatrzymuje kolejki przy normalnym tempie'
);

$GLOBALS['mp_ob']['lines'][] = '';
$GLOBALS['mp_ob']['lines'][] = '=== B. wysylka nie przedluza trwajacego okna ===';

ob_wyczysc();
ob_ustaw_okno( 1800, 5 );
MP_SW_Mailer::allow_send();

$ttl = ob_ttl();

ob_ok(
	$ttl > 0 && $ttl <= 1801,
	'TTL okna to reszta godziny, nie kolejna pelna godzina',
	'ttl=' . $ttl . 's (odnowiony byl 3600s)'
);
ob_ok(
	6 === ob_licznik(),
	'licznik trwajacego okna rosnie o jeden',
	'licznik=' . ob_licznik()
);

$GLOBALS['mp_ob']['lines'][] = '';
$GLOBALS['mp_ob']['lines'][] = '=== C. KONTR-ASERCJE: bezpiecznik nadal dziala ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na wylaczeniu bezpiecznika albo na
 * zerowaniu licznika przy kazdej wysylce. Sekcja A przeszlaby, a wtyczka
 * stracilaby jedyna ochrone przed zalewem poczty z wlasnej domeny.
 */
ob_wyczysc();
ob_ustaw_okno( 10, $prog );

$wolno_w_oknie = MP_SW_Mailer::allow_send();

ob_ok(
	! $wolno_w_oknie,
	'przekroczenie progu W TRAKCIE okna nadal blokuje wysylke',
	'zwrocono=' . var_export( $wolno_w_oknie, true )
);
ob_ok(
	MP_SW_Mailer::halted(),
	'bezpiecznik zatrzymuje kolejke po przekroczeniu progu'
);

ob_wyczysc();

$licznik_po_kolei = 0;

for ( $i = 0; $i < 5; $i++ ) {
	MP_SW_Mailer::allow_send();
	$licznik_po_kolei = ob_licznik();
}

ob_ok(
	5 === $licznik_po_kolei,
	'pieć wysylek pod rzad to licznik 5, a nie 1',
	'licznik=' . $licznik_po_kolei
);

ob_wyczysc();

echo implode( "\n", $GLOBALS['mp_ob']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_ob']['pass'], $GLOBALS['mp_ob']['fail'] );
echo ( 0 === $GLOBALS['mp_ob']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
