<?php
/**
 * P2-S4 — nieudana finalizacja PDF nie ma prawa ogłosić oferty światu.
 *
 * Uruchamianie: wp eval-file tests/naprawy/finalizacja-pdf-a-zdarzenie.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P2-S4  Nieudana finalizacja PDF zostawia pdf_path do nieistniejacego pliku
 *
 * Wpis mial PUSTE pole `test` — naprawa zyla w kodzie bez niczego, co by jej
 * pilnowalo, wbrew Golden Rule #3. Znalazla to para 1.15 (rejestr kontra testy),
 * i to dopiero po naprawie samego narzedzia: ustalenia wskazujace katalog albo
 * korzen wtyczki byly wczesniej odrzucane jako „plik nie istnieje".
 *
 * SEDNO. Dzial 10 konczy PRZED COMMIT, wiec w chwili wejscia do Dzialu 11 oferta
 * jest juz ZAPISANA razem z `pdf_path` wskazujacym nazwe DOCELOWA. Jesli
 * przeniesienie tmp -> nazwa docelowa sie nie uda (dysk pelny, uprawnienia,
 * plik poza katalogiem tymczasowym), tego etapu nie da sie wycofac transakcja.
 * Jedyna obrona to STOP tutaj — zanim `mp_offer_created` wyjdzie do wtyczki 3
 * z odnosnikiem do pliku, ktory nigdy nie powstal.
 *
 * Bez tej obrony rekord twierdzil, ze dokument istnieje, a link do pobrania
 * prowadzil do 404 — i dowiadywal sie o tym klient, nie my.
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_fpz'] = array(
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
function fpz_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_fpz']['pass'];
		$GLOBALS['mp_fpz']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_fpz']['fail'];
	$GLOBALS['mp_fpz']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

$GLOBALS['mp_fpz_zdarzen'] = 0;

add_action(
	MP_OB_D11_Agent_Event::HOOK,
	static function () {
		++$GLOBALS['mp_fpz_zdarzen'];
	},
	1
);

$fpz_sprzataczka = array();

// Sprzatanie ZAWSZE, takze po fatalu — plik docelowy powstaje w katalogu
// prywatnym wtyczki i zostalby tam na stale.
register_shutdown_function(
	function () use ( &$fpz_sprzataczka ) {
		foreach ( $fpz_sprzataczka as $sciezka ) {
			if ( '' !== $sciezka && file_exists( $sciezka ) ) {
				wp_delete_file( $sciezka );
			}
		}
	}
);

/**
 * Buduje kontekst Dzialu 11 dla oferty, ktora zostala juz zapisana.
 *
 * @param string $tmp_path Sciezka pliku tymczasowego.
 * @param string $numer    Numer oferty.
 * @return MP_OB_Context
 */
function fpz_kontekst( $tmp_path, $numer ) {
	return new MP_OB_Context(
		array(
			'offer_id'     => 987654,
			'offer_number' => $numer,
			'version'      => 1,
			'pdf'          => array( 'tmp_path' => $tmp_path ),
			'client'       => array( 'name' => 'P2S4 Testowa sp. z o.o.' ),
			'gross_grosze' => 123400,
			'currency'     => 'PLN',
		)
	);
}

$fpz_agent = new MP_OB_D11_Agent_Event();

$GLOBALS['mp_fpz']['lines'][] = '=== A. finalizacja PADA — zdarzenie NIE wychodzi ===';

/*
 * Porazke wymuszamy plikiem POZA katalogiem tymczasowym: `finalize_pdf()` ma
 * straz SR3-06, ktora zada, zeby zrodlo lezalo w `tmp_dir()`, i oddaje `false`.
 * Wybieramy ta droge, a nie manipulacje uprawnieniami, bo jest deterministyczna
 * i nie zalezy od tego, na jakim uzytkowniku biegnie kontener.
 */
$fpz_obcy = trailingslashit( sys_get_temp_dir() ) . 'p2s4-poza-katalogiem.pdf';
file_put_contents( $fpz_obcy, '%PDF-1.4 test P2-S4' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$fpz_sprzataczka[] = $fpz_obcy;

fpz_ok(
	false === MP_Offer_Builder_Storage::finalize_pdf( $fpz_obcy, 'OF/P2S4/A', 1 ),
	'kontrola pozytywna: finalizacja tego pliku faktycznie sie NIE udaje',
	'finalize_pdf oddalo cos innego niz false'
);

$GLOBALS['mp_fpz_zdarzen'] = 0;
$fpz_wynik_zly            = $fpz_agent->run( fpz_kontekst( $fpz_obcy, 'OF/P2S4/A' ) );

fpz_ok(
	! $fpz_wynik_zly->is_ok(),
	'Dzial 11 odmawia, gdy finalizacja PDF sie nie udala',
	'wynik=' . ( $fpz_wynik_zly->is_ok() ? 'PRZESZLO' : 'odmowa' )
);
fpz_ok(
	'pdf_finalize_failed' === $fpz_wynik_zly->get_code(),
	'odmowa niesie wlasny kod pdf_finalize_failed',
	'kod=' . $fpz_wynik_zly->get_code()
);
fpz_ok(
	0 === $GLOBALS['mp_fpz_zdarzen'],
	'zdarzenie mp_offer_created NIE poszlo do wtyczki 3',
	'wystapien=' . $GLOBALS['mp_fpz_zdarzen']
);
fpz_ok(
	file_exists( $fpz_obcy ),
	'plik tymczasowy zostaje na dysku — jest z czego ponowic',
	'plik zniknal mimo nieudanej finalizacji'
);

$GLOBALS['mp_fpz']['lines'][] = '=== B. kontr-asercje: zdrowa sciezka nietknieta ===';

/*
 * Bez tej sekcji „naprawa" mogloby znaczyc: nie wystawiaj zdarzenia NIGDY.
 * Zdarzenie `mp_offer_created` jest jedynym wejsciem wtyczki 3 do procesu, wiec
 * jego cisza byloby zerwaniem calej integracji — gorszym niz blad, ktory tu
 * naprawiamy.
 */
$fpz_tmp = trailingslashit( MP_Offer_Builder_Storage::tmp_dir() ) . 'p2s4-poprawny.pdf';
file_put_contents( $fpz_tmp, '%PDF-1.4 test P2-S4 ok' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$fpz_sprzataczka[] = $fpz_tmp;
$fpz_sprzataczka[] = MP_Offer_Builder_Storage::final_pdf_path( 'OF/P2S4/B', 1 );

$GLOBALS['mp_fpz_zdarzen'] = 0;
$fpz_wynik_ok             = $fpz_agent->run( fpz_kontekst( $fpz_tmp, 'OF/P2S4/B' ) );

fpz_ok(
	$fpz_wynik_ok->is_ok(),
	'udana finalizacja nadal przepuszcza dzial',
	'kod=' . $fpz_wynik_ok->get_code()
);
fpz_ok(
	1 === $GLOBALS['mp_fpz_zdarzen'],
	'zdarzenie mp_offer_created poszlo DOKLADNIE RAZ',
	'wystapien=' . $GLOBALS['mp_fpz_zdarzen']
);
fpz_ok(
	file_exists( MP_Offer_Builder_Storage::final_pdf_path( 'OF/P2S4/B', 1 ) ),
	'plik lezy pod nazwa DOCELOWA — odnosnik z powiadomienia trafi w cos istniejacego'
);

$GLOBALS['mp_fpz']['lines'][] = '=== C. brak PDF-a to nie to samo, co PDF nieudany ===';

/*
 * Sciezka bez pliku tymczasowego (oferta bez zalacznika) ma przechodzic —
 * warunek `'' !== $tmp_path && file_exists( $tmp_path )` jest tam celowo.
 * Zaostrzenie obrony nie moze zamienic braku PDF-a w blad.
 */
$GLOBALS['mp_fpz_zdarzen'] = 0;
$fpz_wynik_bez            = $fpz_agent->run( fpz_kontekst( '', 'OF/P2S4/C' ) );

fpz_ok(
	$fpz_wynik_bez->is_ok() && 1 === $GLOBALS['mp_fpz_zdarzen'],
	'oferta bez pliku PDF nadal wystawia zdarzenie',
	'kod=' . $fpz_wynik_bez->get_code() . ' wystapien=' . $GLOBALS['mp_fpz_zdarzen']
);

echo implode( "\n", $GLOBALS['mp_fpz']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_fpz']['pass'], $GLOBALS['mp_fpz']['fail'] );
echo ( 0 === $GLOBALS['mp_fpz']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
