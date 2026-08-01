<?php
/**
 * P1-G7 — „sprawdzone" przy weryfikacji, ktora sie nie odbyla.
 *
 * Uruchamianie: wp eval-file tests/naprawy/status-vat-nieznany.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-G7  vat_status='checked' takze wtedy, gdy vat_valid jest null
 *
 * Dzial 7 rozstrzygal status weryfikacji trzema galeziami, a ostatnia z nich
 * lapala DWIE rozne rzeczy naraz: potwierdzone `false` (sprawdzono, numer
 * niewazny) i `null` (weryfikacja sie NIE rozstrzygnela). Ze `null` jest tu
 * wartoscia oczekiwana, dowodzil sam kod obok: `'vat_valid' => is_null(...)`.
 *
 * Stan jest osiagalny w trybie synchronicznym (filtr
 * `mp_lead_intake_async_verification` na false — tak chodza testy i takie
 * wdrozenia sa dopuszczone): `resolve_vies()` zwraca `vat_valid => null` przy
 * awarii VIES albo odpowiedzi bez pola `isValid`, i to BEZ flagi `vat_pending`,
 * bo ta powstaje wylacznie przy cache-missie w trybie async.
 *
 * Skutek byl trwaly. Wiersz dostawal `vat_status = 'checked'`, `vat_checked_at`
 * = teraz i `vat_attempts = 0`, wiec dla wtyczki 2, raportow i czlowieka lead
 * wygladal na rozstrzygnietego. Weryfikator w tle bierze WYLACZNIE wiersze ze
 * statusem `pending` (class-mp-vat-verifier.php:84), wiec nikt nigdy nie wracal
 * do tego numeru — mimo ze jedyne, co o nim wiadomo, to ze nic nie wiadomo.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_sv'] = array(
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
function sv_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_sv']['pass'];
		$GLOBALS['mp_sv']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_sv']['fail'];
	$GLOBALS['mp_sv']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Uruchamia Agenta 7.1 na zadanym stanie weryfikacji i oddaje dane leada.
 *
 * @param mixed $vat_valid   Wynik weryfikacji (true / false / null).
 * @param bool  $vat_pending Czy weryfikacja zostala odlozona do tla.
 * @return array
 */
function sv_lead( $vat_valid, $vat_pending ) {
	$kontekst = new MP_Context(
		array(
			'company_name' => 'Firma Testowa VAT',
			'nip'          => '1234563218',
			'email'        => 'vat@example.test',
			'country'      => 'PL',
			'vat_valid'    => $vat_valid,
			'vat_pending'  => $vat_pending,
		)
	);

	$wynik = ( new MP_D7_Agent_Prepare() )->run( $kontekst );
	$dane  = $wynik->get_data();

	return isset( $dane['lead_data'] ) ? (array) $dane['lead_data'] : array();
}

$GLOBALS['mp_sv']['lines'][] = '=== A. wynik nieznany bez flagi pending ===';

$nieznany = sv_lead( null, false );

sv_ok(
	'checked' !== (string) $nieznany['vat_status'],
	'status NIE twierdzi, ze sprawdzenie sie odbylo',
	'vat_status=' . (string) $nieznany['vat_status']
);
sv_ok(
	'pending' === (string) $nieznany['vat_status'],
	'lead wraca do kolejki weryfikacji (status pending)',
	'vat_status=' . (string) $nieznany['vat_status']
);
sv_ok(
	null === $nieznany['vat_checked_at'],
	'brak daty sprawdzenia, bo sprawdzenia nie bylo',
	'vat_checked_at=' . var_export( $nieznany['vat_checked_at'], true )
);
sv_ok(
	null === $nieznany['vat_valid'],
	'wynik zostaje nieznany (NULL), nie zamieniony na „niewazny"',
	'vat_valid=' . var_export( $nieznany['vat_valid'], true )
);

/*
 * Sedno skutku: weryfikator w tle bierze WYLACZNIE wiersze ze statusem pending.
 * Status ustawiony przez Dzial 7 decyduje wiec o tym, czy ktokolwiek wroci do
 * tego numeru.
 */
sv_ok(
	'pending' === (string) $nieznany['vat_status'],
	'status jest tym, ktorego szuka weryfikator w tle (class-mp-vat-verifier.php)'
);
sv_ok(
	0 === (int) $nieznany['vat_attempts'],
	'licznik prob wystartuje od zera, wiec weryfikator ma pelna pule'
);

$GLOBALS['mp_sv']['lines'][] = '';
$GLOBALS['mp_sv']['lines'][] = '=== B. KONTR-ASERCJE: rozstrzygniete wyniki bez zmian ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na ustawianiu `pending` zawsze — a
 * wtedy weryfikator w tle mielilby w kolko leady dawno rozstrzygniete, a
 * wtyczka 2 nigdy nie zobaczylaby potwierdzonego VAT-u (odwrotne obciazenie
 * staloby sie nieosiagalne).
 */
$wazny = sv_lead( true, false );

sv_ok(
	'valid' === (string) $wazny['vat_status'],
	'potwierdzony wazny VAT nadal daje status valid',
	'vat_status=' . (string) $wazny['vat_status']
);
sv_ok(
	null !== $wazny['vat_checked_at'],
	'i nadal ma date sprawdzenia'
);
sv_ok(
	1 === (int) $wazny['vat_valid'],
	'oraz zapisany wynik pozytywny'
);

$niewazny = sv_lead( false, false );

sv_ok(
	'checked' === (string) $niewazny['vat_status'],
	'POTWIERDZONY brak waznosci nadal daje status checked',
	'vat_status=' . (string) $niewazny['vat_status']
);
sv_ok(
	null !== $niewazny['vat_checked_at'],
	'i nadal ma date sprawdzenia — bo sprawdzenie sie odbylo'
);
sv_ok(
	0 === (int) $niewazny['vat_valid'],
	'oraz zapisany wynik negatywny (0, nie NULL)',
	'vat_valid=' . var_export( $niewazny['vat_valid'], true )
);

$odlozony = sv_lead( null, true );

sv_ok(
	'pending' === (string) $odlozony['vat_status'] && null === $odlozony['vat_checked_at'],
	'cache-miss w trybie async nadal odklada weryfikacje do tla',
	'vat_status=' . (string) $odlozony['vat_status']
);

echo implode( "\n", $GLOBALS['mp_sv']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_sv']['pass'], $GLOBALS['mp_sv']['fail'] );
echo ( 0 === $GLOBALS['mp_sv']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
