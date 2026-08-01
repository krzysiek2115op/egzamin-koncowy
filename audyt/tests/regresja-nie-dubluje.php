<?php
/**
 * NARZ-F3 — para 2.8 dublowala ustalenia i nadawala kopii wage z powietrza.
 *
 * Uruchamianie: php audyt/tests/regresja-nie-dubluje.php
 *
 * Para 2.8 sprawdza, ktore ustalenia sa NOWE wzgledem poprzedniego przebiegu.
 * Zglaszala je jednak jako OSOBNE ustalenia wagi SREDNIA — i to bylo zle na dwa
 * sposoby naraz.
 *
 * 1. DUBLOWANIE. Ta sama rzecz stala w raporcie dwa razy: raz od pary, ktora ja
 *    znalazla, i raz od pary 2.8. W przebiegu z 1.08.2026 na osiemnascie pozycji
 *    sredniej wagi az szesc bylo echem innych szesciu. Bramka liczy ustalenia,
 *    wiec zawyzone liczby to nie kosmetyka.
 *
 * 2. WAGA KOPII BRANA Z POWIETRZA. Duplikat dostawal na sztywno wage SREDNIA,
 *    bez ogladania sie na wage ustalenia, ktore opisywal. Kopia ustalenia
 *    krytycznego byla wiec srednia, a liczba pozycji sredniej wagi rosla o rzeczy,
 *    ktore wcale nie sa sredniej wagi. Nowosc jest cecha CZASU POWSTANIA, nie
 *    ciezaru szkody, i nie ma prawa ustawiac oceny.
 *
 * Wiedza tej pary jest cenna i ma zostac: wiadomo, w ktorych zmianach szukac
 * przyczyny. To jednak informacja O ustaleniu, wiec jej miejsce jest w dowodzie —
 * tak jak przy parach 2.2 i 2.9.
 *
 * @package MP_Audyt
 */

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/kontrakty.php';
require_once $korzen . '/includes/pomoc.php';
require_once $korzen . '/includes/class-mp-au-workspace.php';
require_once $korzen . '/includes/class-mp-au-model-client.php';
require_once $korzen . '/includes/pary/dzial-02-weryfikacja.php';

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
function rnd_ok( bool $warunek, string $opis, string $info = '' ): void {
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
 * @param string $para Para zglaszajaca.
 * @param string $opis Opis.
 * @param string $waga Waga.
 * @return MP_AU_Ustalenie
 */
function rnd_ustalenie( string $para, string $opis, string $waga ): MP_AU_Ustalenie {
	return new MP_AU_Ustalenie(
		$para,
		$opis,
		$waga,
		array(
			'plik'       => 'mp-lead-intake/includes/y.php',
			'linia'      => 42,
			'dowod'      => 'dowod ' . $opis,
			'scenariusz' => 'scenariusz ' . $opis,
		)
	);
}

$worktree = dirname( $korzen );
$baza     = sys_get_temp_dir() . '/mp-audyt-2-8-' . getmypid();

$ws = new MP_AU_Workspace( $worktree, $baza, 'refs/heads/main' );
$ws->wystaw();

$kontekst = new MP_AU_Kontekst( $ws, new MP_AU_Model_Client( $ws, sys_get_temp_dir(), 'bez-modelu' ) );

$srednie = rnd_ustalenie( '1.25', 'swieze ustalenie sredniej wagi', MP_AU_Ustalenie::SREDNIE );
$drobne  = rnd_ustalenie( '1.26', 'swiezy drobiazg', MP_AU_Ustalenie::DROBNE );
$stare   = rnd_ustalenie( '1.3', 'ustalenie znane z poprzedniego przebiegu', MP_AU_Ustalenie::SREDNIE );

$kontekst->dopisz_ustalenia( array( $srednie, $drobne, $stare ) );

$przed = count( $kontekst->ustalenia() );

/*
 * Wejscie takie, jakie oddaje Agent 2.8: dwa ustalenia sa nowe wzgledem
 * poprzedniego raportu, trzecie bylo w nim juz wczesniej.
 */
$od_agenta = MP_AU_Wynik::ok(
	array(
		'pierwszy_przebieg' => false,
		'data_poprzedniego' => '2026-07-31',
		'werdykt_poprzedni' => 'GO WITH MINOR FIXES',
		'bylo'              => 1,
		'jest'              => 3,
		'nowe'              => array(
			$srednie->klucz() => array(
				'opis' => $srednie->opis,
				'waga' => $srednie->waga,
			),
			$drobne->klucz()  => array(
				'opis' => $drobne->opis,
				'waga' => $drobne->waga,
			),
		),
		'znikle'            => array(),
	)
);

$krytyk = new MP_AU_K28_Regresja( '2.8', 'ubylo-czy-sie-przesunelo' );
$wynik  = $krytyk->ocen( $od_agenta, $kontekst );

echo "=== A. nic sie nie dubluje ===\n";

rnd_ok(
	count( $kontekst->ustalenia() ) === $przed,
	'A1: para 2.8 nie dokłada ani jednego ustalenia do dossier',
	'przed=' . $przed . ' po=' . count( $kontekst->ustalenia() )
);

rnd_ok(
	empty( $wynik->ustalenia ),
	'A2: i nie oddaje wlasnych ustalen w wyniku',
	'ustalen=' . count( (array) $wynik->ustalenia )
);

echo "=== B. waga zostaje wlasna ===\n";

rnd_ok(
	MP_AU_Ustalenie::DROBNE === $drobne->waga,
	'B1: swiezy drobiazg NADAL jest drobny — nowosc nie awansuje wagi',
	'waga=' . $drobne->waga
);
rnd_ok(
	MP_AU_Ustalenie::SREDNIE === $srednie->waga,
	'B2: KONTR-ASERCJA — ustalenie sredniej wagi tez zostaje przy swojej',
	'waga=' . $srednie->waga
);

echo "=== C. wiedza pary nie ginie, tylko zmienia miejsce ===\n";

rnd_ok(
	false !== strpos( $srednie->dowod, '[2.8 regresja]' )
		&& false !== strpos( $srednie->dowod, '2026-07-31' ),
	'C1: nowe ustalenie dostaje adnotacje z data poprzedniego raportu',
	'dowod=' . $srednie->dowod
);
rnd_ok(
	false !== strpos( $drobne->dowod, '[2.8 regresja]' ),
	'C2: drobiazg tez — adnotacja nie zalezy od wagi',
	'dowod=' . $drobne->dowod
);
rnd_ok(
	false === strpos( $stare->dowod, '[2.8 regresja]' ),
	'C3: KONTR-ASERCJA — ustalenie znane z poprzedniego przebiegu NIE dostaje adnotacji',
	'dowod=' . $stare->dowod
);
rnd_ok(
	2 === (int) ( $wynik->dane['oznaczonych_jako_regresja'] ?? -1 ),
	'C4: podsumowanie podaje, ile ustalen oznaczono',
	'oznaczonych=' . ( $wynik->dane['oznaczonych_jako_regresja'] ?? 'brak' )
);

echo "\n----- PASS: {$pass} / FAIL: {$fail} -----\n";
echo 0 === $fail ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
