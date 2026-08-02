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
function sv_lead( $vat_valid, $vat_pending, array $dodatki = array() ) {
	$kontekst = new MP_Context(
		array_merge(
			array(
				'company_name' => 'Firma Testowa VAT',
				'nip'          => '1234563218',
				'email'        => 'vat@example.test',
				'country'      => 'PL',
				'vat_valid'    => $vat_valid,
				'vat_pending'  => $vat_pending,
				/*
				 * Biala lista ROZSTRZYGNIETA — to jest stan bazowy fikstury, a nie
				 * szczegol. Od 1.3.7 nierozstrzygnieta Biala lista sama z siebie
				 * odklada leada do weryfikacji, wiec przypadek, ktory bada wylacznie
				 * VAT, musi powiedziec, co z drugim zrodlem; inaczej mierzy dwie
				 * rzeczy naraz. Przypadki sekcji D nadpisuja ta wartosc przez $dodatki.
				 */
				'company_status' => 'Czynny',
			),
			$dodatki
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

$GLOBALS['mp_sv']['lines'][] = '';
$GLOBALS['mp_sv']['lines'][] = '=== D. Biala lista: nierozstrzygniete to nie sprawdzone ===';

/*
 * Ta sama klasa bledu co w sekcji A, tylko w drugim ze zrodel weryfikacji.
 * Warunek statusu patrzyl wylacznie na `vat_valid`; `company_status` byl
 * odczytywany i nieuzywany. `resolve_wl()` zwraca `company_status => null`
 * przy awarii HTTP, odpowiedzi bez `subject` i przy blednym ciele — w trybie
 * SYNCHRONICZNYM bez zadnej flagi, bo `company_status_pending` powstaje tylko
 * przy cache-missie w trybie async. Lead dostawal wiec „sprawdzone", nigdy nie
 * wracal do weryfikatora, a nieznany status firmy i tak wchodzil do punktacji.
 */
$wl_nieznany = sv_lead( true, false, array( 'company_status' => null ) );

sv_ok(
	'pending' === (string) $wl_nieznany['vat_status'],
	'D1: nierozstrzygnieta Biala lista odklada leada do weryfikacji',
	'vat_status=' . (string) $wl_nieznany['vat_status']
);
sv_ok(
	null === $wl_nieznany['vat_checked_at'],
	'D2: i nie stawia daty sprawdzenia, ktorego nie bylo'
);

/*
 * KONTR-ASERCJA, i to najwazniejsza w tym pliku. `company_status === null`
 * znaczy DWIE rozne rzeczy. Dla firmy spoza Polski Biala lista po prostu NIE MA
 * ZASTOSOWANIA — Dzial 3 oznacza to zakresem `pl_only` i nie pyta API. Gdyby
 * naprawa patrzyla na sam null, kazdy lead zagraniczny szedlby w nieskonczonosc
 * do weryfikatora, ktory nie ma czego rozstrzygnac.
 */
$wl_poza_zakresem = sv_lead(
	true,
	false,
	array(
		'country'              => 'DE',
		'company_status'       => null,
		'company_status_scope' => 'pl_only',
	)
);

sv_ok(
	'pending' !== (string) $wl_poza_zakresem['vat_status'],
	'D3: KONTR-ASERCJA — firma spoza Polski NIE trafia do kolejki z powodu Bialej listy',
	'vat_status=' . (string) $wl_poza_zakresem['vat_status']
);
sv_ok(
	'valid' === (string) $wl_poza_zakresem['vat_status'],
	'D4: i zachowuje status wynikajacy z potwierdzonego numeru VAT',
	'vat_status=' . (string) $wl_poza_zakresem['vat_status']
);

$wl_rozstrzygniety = sv_lead( true, false, array( 'company_status' => 'Czynny' ) );

sv_ok(
	'valid' === (string) $wl_rozstrzygniety['vat_status'],
	'D5: KONTR-ASERCJA — rozstrzygnieta Biala lista niczego nie odklada',
	'vat_status=' . (string) $wl_rozstrzygniety['vat_status']
);

echo implode( "\n", $GLOBALS['mp_sv']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_sv']['pass'], $GLOBALS['mp_sv']['fail'] );
echo ( 0 === $GLOBALS['mp_sv']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
