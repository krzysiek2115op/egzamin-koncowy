<?php
/**
 * Test: raport rozlicza KAZDE zgloszone ustalenie — zadne nie znika po cichu.
 *
 * Uruchamianie: php audyt/tests/raport-bilansuje-ustalenia.php
 *
 * Przebieg gleboki z 01.08.2026 deklarowal w podsumowaniu par 89 ustalen,
 * a w tresci raportu wypisywal 52. Przebieg `--bez-modelu` na tym samym
 * repozytorium: 48 zadeklarowanych, 14 wypisanych. Trzydziesci cztery pozycje
 * przepadaly przy zerowym udziale modelu, wiec nie byla to kaprysnosc oceny.
 *
 * Przyczyna sama w sobie jest POPRAWNA: para 2.2 odrzuca ustalenia, ktorych
 * miejsca nie potwierdza niezalezny odczyt pliku — trafienie w komentarz,
 * w nieistniejacy plik, poza zakres linii albo duplikat innego ustalenia.
 * `MP_AU_Raport::wedlug_wagi()` pomija odrzucone i slusznie: raport ma pokazywac
 * to, co sie obronilo.
 *
 * Wadliwe bylo ROZLICZENIE. Podsumowanie par pokazuje liczbe SPRZED weryfikacji,
 * tresc raportu — liczbe PO niej, i nigdzie nie pada slowo o roznicy. Czytelnik
 * widzi „[BLAD] 1.5 kod-kontra-DDL: 14 ustalen", nie znajduje pod spodem ani
 * jednego i nie ma jak rozstrzygnac, czy zostaly odrzucone, czy zgubione. Raport,
 * ktorego nie da sie zbilansowac, kaze wierzyc na slowo — a audyt istnieje po to,
 * zeby nie trzeba bylo.
 *
 * @package MP_Audyt
 */

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/kontrakty.php';
require_once $korzen . '/includes/pomoc.php';
require_once $korzen . '/includes/class-mp-au-workspace.php';
require_once $korzen . '/includes/class-mp-au-model-client.php';
require_once $korzen . '/includes/class-mp-au-raport.php';

$pass = 0;
$fail = 0;

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $info    Kontekst przy porazce.
 * @return void
 */
function rb_ok( bool $warunek, string $opis, string $info = '' ): void {
	global $pass, $fail;

	if ( $warunek ) {
		++$pass;
		echo "  [PASS] {$opis}\n";
		return;
	}

	++$fail;
	echo "  [FAIL] {$opis}" . ( '' !== $info ? ' -- ' . $info : '' ) . "\n";
}

/**
 * Ustalenie testowe.
 *
 * @param string $klucz  Rozroznienie.
 * @param string $waga   Waga.
 * @param string $status Status.
 * @return MP_AU_Ustalenie
 */
function rb_ustalenie( string $klucz, string $waga, string $status ): MP_AU_Ustalenie {
	return new MP_AU_Ustalenie(
		'1.99',
		'Ustalenie testowe ' . $klucz,
		$waga,
		array(
			'plik'       => 'mp-lead-intake/plik.php',
			'linia'      => 1,
			'dowod'      => 'dowod ' . $klucz,
			'scenariusz' => 'scenariusz ' . $klucz,
			'status'     => $status,
		)
	);
}

/**
 * Swiezy kontekst audytu (bez modelu — test dotyczy samego rozliczenia).
 *
 * @return MP_AU_Kontekst
 */
function rb_kontekst(): MP_AU_Kontekst {
	$ws = new MP_AU_Workspace( sys_get_temp_dir(), sys_get_temp_dir() );

	return new MP_AU_Kontekst( $ws, new MP_AU_Model_Client( $ws, sys_get_temp_dir(), 'bez-modelu' ) );
}

$kontekst = rb_kontekst();

// Trzy przezyja weryfikacje, cztery zostana odrzucone jako falszywe alarmy.
$przezyly  = 3;
$odrzucone = 4;

$partia = array();
for ( $i = 1; $i <= $przezyly; $i++ ) {
	$partia[] = rb_ustalenie( 'ok' . $i, MP_AU_Ustalenie::SREDNIE, MP_AU_Ustalenie::POTWIERDZONE );
}
for ( $i = 1; $i <= $odrzucone; $i++ ) {
	$partia[] = rb_ustalenie( 'zle' . $i, MP_AU_Ustalenie::SREDNIE, MP_AU_Ustalenie::ODRZUCONE );
}
$kontekst->dopisz_ustalenia( $partia );

$raport = new MP_AU_Raport( $kontekst, array(), array() );
$raport->ustaw_przebieg( array( 'glebokosc' => 'pelny' ) );
$tresc = $raport->podsumowanie_tekstowe();

echo "== A. rozliczenie zgadza sie z trescia ==\n";

rb_ok(
	false !== strpos( $tresc, 'ustalen zgloszonych' ),
	'rozliczenie podaje liczbe ustalen ZGLOSZONYCH'
);
rb_ok(
	false !== strpos( $tresc, 'odrzuconych w weryfikacji' ),
	'rozliczenie podaje, ile odrzucila weryfikacja'
);

if ( preg_match( '/ustalen zgloszonych:\s*(\d+)/', $tresc, $m_zgl )
	&& preg_match( '/odrzuconych w weryfikacji:\s*(\d+)/', $tresc, $m_odrz )
	&& preg_match( '/w tresci raportu:\s*(\d+)/', $tresc, $m_tresc ) ) {

	$zgl   = (int) $m_zgl[1];
	$odrz  = (int) $m_odrz[1];
	$wtres = (int) $m_tresc[1];

	rb_ok( $przezyly + $odrzucone === $zgl, 'liczba zgloszonych obejmuje TAKZE odrzucone', "zgloszonych={$zgl}" );
	rb_ok( $odrzucone === $odrz, 'liczba odrzuconych zgadza sie ze stanem', "odrzuconych={$odrz}" );
	rb_ok( $przezyly === $wtres, 'liczba w tresci zgadza sie z tym, co widac', "w tresci={$wtres}" );
	rb_ok( $zgl === $odrz + $wtres, 'BILANS: zgloszone = odrzucone + w tresci', "{$zgl} != {$odrz} + {$wtres}" );
} else {
	rb_ok( false, 'rozliczenie da sie odczytac trzema liczbami', 'brak ktoregos z pol w raporcie' );
	rb_ok( false, 'liczba odrzuconych zgadza sie ze stanem' );
	rb_ok( false, 'liczba w tresci zgadza sie z tym, co widac' );
	rb_ok( false, 'BILANS: zgloszone = odrzucone + w tresci' );
}

echo "\n== B. kontr-asercje: odrzucone nadal NIE zasmiecaja tresci ==\n";

/*
 * Rozliczenie ma domykac raport, a nie wracac do niego odrzuconych ustalen.
 * Bez tej kontr-asercji „naprawa" mogloby znaczyc „pokaz wszystko", czyli
 * dokladnie ten szum, przed ktorym broni para 2.2.
 */
rb_ok(
	false === strpos( $tresc, 'Ustalenie testowe zle1' ),
	'odrzucone ustalenie nie pojawia sie w tresci raportu'
);
rb_ok(
	false !== strpos( $tresc, 'Ustalenie testowe ok1' ),
	'potwierdzone ustalenie nadal jest w tresci raportu'
);

$puste_raport = new MP_AU_Raport( rb_kontekst(), array(), array() );
$puste_raport->ustaw_przebieg( array( 'glebokosc' => 'pelny' ) );
$puste_tresc = $puste_raport->podsumowanie_tekstowe();

rb_ok(
	preg_match( '/ustalen zgloszonych:\s*0/', $puste_tresc ) > 0,
	'przebieg bez zadnego ustalenia tez sie rozlicza, zerem'
);

echo "\n== PODSUMOWANIE ==\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
echo ( 0 === $fail ) ? "WYNIK: PASS\n" : "WYNIK: FAIL\n";

exit( 0 === $fail ? 0 : 1 );
