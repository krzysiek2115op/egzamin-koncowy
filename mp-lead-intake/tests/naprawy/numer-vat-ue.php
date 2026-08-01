<?php
/**
 * P1-Z1 — numer VAT z innego kraju UE nie mial jak przejsc, choc reszta
 * systemu byla na niego gotowa.
 *
 * Uruchamianie: wp eval-file tests/naprawy/numer-vat-ue.php
 *
 * Pilnuje wpisow z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P1-Z1  polska kontrola formatu zamykala droge numerom UE, mimo ze VIES,
 *            BD-3 i przydzial handlowca w P3 juz rozrozniaja kraje
 *
 * CO BYLO. Formularz pozwala wybrac kraj UE, bo kod kraju jest potrzebny do
 * VIES i do klucza UNIQUE (country, nip) w BD-3. Caly dalszy ciag potoku byl
 * na wiele krajow przygotowany: agent 3.2 wola VIES pod adresem
 * `/ms/{KRAJ}/vat/{NUMER}`, weryfikator w tle ma osobna galaz dla leadow spoza
 * UE, a Dzial 4 w P3 przydziela handlowca po kraju. Zamkniete bylo tylko
 * wejscie — dwie kontrole formatu, obie polskie.
 *
 * DRUGA, CICHSZA CZESC BLEDU. W siedmiu miejscach numer byl sprowadzany do
 * samych cyfr przez `preg_replace( '/\D+/', '', ... )`. Dla Polski to poprawna
 * kanonizacja (myslniki i spacje gina). Poza Polska litery bywaja CZESCIA
 * numeru: holenderski to `123456789B01`, irlandzki `1234567FA`. Ta normalizacja
 * okaleczala je po cichu — numer docieral do VIES juz zepsuty, wiec nawet po
 * otwarciu kontroli formatu odpowiedz bylaby bledna, a przyczyna niewidoczna.
 *
 * ZAKRES ZMIANY. Polska sciezka ma zostac NIETKNIETA — co do znaku. Pilnuja
 * tego kontr-asercje w sekcjach B, C i D. O waznosci numeru zagranicznego
 * rozstrzyga VIES, a nie nowa, rownolegla tablica regul: lokalnie zostaje tylko
 * sanity-check (dlugosc, dozwolone znaki, odrzucenie wartosci zastepczych).
 * Dwa zrodla prawdy o tym samym numerze rozjechalyby sie predzej czy pozniej.
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_ue'] = array(
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
function ue_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_ue']['pass'];
		$GLOBALS['mp_ue']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_ue']['fail'];
	$GLOBALS['mp_ue']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

$ue_jest_klasa = class_exists( 'MP_Vat_Number' );

/**
 * Normalizacja przez centralny helper. Gdy klasy jeszcze nie ma, oddaje znacznik
 * — zeby test RAPORTOWAL brak, zamiast wywracac przebieg bledem krytycznym.
 *
 * @param string $numer Numer.
 * @param string $kraj  Kod kraju.
 * @return string
 */
function ue_norm( $numer, $kraj ) {
	if ( ! class_exists( 'MP_Vat_Number' ) ) {
		return '<brak MP_Vat_Number>';
	}

	return (string) MP_Vat_Number::normalize( $numer, $kraj );
}

$ue_dzial2 = static function ( $nip, $country ) {
	$wynik = ( new MP_D2_Agent_Validate_Formats() )->run(
		new MP_Context(
			array(
				'nip'     => $nip,
				'email'   => 'kontakt@example.test',
				'country' => $country,
			)
		)
	);
	$dane = $wynik->get_data();

	return isset( $dane['errors']['nip'] ) ? (string) $dane['errors']['nip'] : '';
};

$ue_nip_valid = static function ( $nip, $country ) {
	$wynik = ( new MP_D3_Agent_Nip() )->run(
		new MP_Context(
			array(
				'nip'     => $nip,
				'country' => $country,
			)
		)
	);
	$dane = $wynik->get_data();

	return ! empty( $dane['nip_valid'] );
};

/*
 * ---------------------------------------------------------------------------
 * A. Normalizacja poza Polska zostawia litery i obcina prefiks kraju.
 *
 * VIES przyjmuje numer BEZ prefiksu (kraj jedzie osobno w sciezce adresu), a
 * czlowiek wpisuje numer tak, jak ma go na fakturze — czesto z prefiksem.
 * ---------------------------------------------------------------------------
 */
ue_ok(
	'123456789B01' === ue_norm( 'NL123456789B01', 'NL' ),
	'A1: holenderska litera B przezywa normalizacje',
	'wynik=' . ue_norm( 'NL123456789B01', 'NL' )
);
ue_ok(
	'1234567FA' === ue_norm( '1234567FA', 'IE' ),
	'A2: irlandzkie dwie litery na koncu przezywaja',
	'wynik=' . ue_norm( '1234567FA', 'IE' )
);
ue_ok(
	'123456789' === ue_norm( 'DE 123 456 789', 'DE' ),
	'A3: spacje i myslniki nadal gina — to separatory, nie tresc',
	'wynik=' . ue_norm( 'DE 123 456 789', 'DE' )
);
ue_ok(
	'PL1234563218' === ue_norm( 'PL1234563218', 'DE' ),
	'A4: obcinamy TYLKO prefiks zgodny z wybranym krajem — niezgodny zostaje, niech VIES go odrzuci',
	'wynik=' . ue_norm( 'PL1234563218', 'DE' )
);

/*
 * ---------------------------------------------------------------------------
 * B. KONTR-ASERCJE: polska normalizacja co do znaku taka, jak byla.
 *
 * Wzorzec odniesienia to dokladnie to, co robil `preg_replace( '/\D+/', '' )`.
 * ---------------------------------------------------------------------------
 */
ue_ok(
	'1234563218' === ue_norm( '123-456-32-18', 'PL' ),
	'B1: polski numer z myslnikami kanonizuje sie jak dotad',
	'wynik=' . ue_norm( '123-456-32-18', 'PL' )
);
ue_ok(
	'1234563218' === ue_norm( 'PL1234563218', 'PL' ),
	'B2: polski prefiks obciety, wynik ten sam co przy starym wycinaniu liter',
	'wynik=' . ue_norm( 'PL1234563218', 'PL' )
);
ue_ok(
	'1234563218' === ue_norm( '123 456 32 18', '' ),
	'B3: bez podanego kraju zostaje sciezka polska',
	'wynik=' . ue_norm( '123 456 32 18', '' )
);
ue_ok(
	'123' === ue_norm( 'abc123', 'PL' ),
	'B4: przy PL litery nadal gina — tak dzialal stary kod i tak ma zostac',
	'wynik=' . ue_norm( 'abc123', 'PL' )
);

/*
 * ---------------------------------------------------------------------------
 * C. Dzial 2 przepuszcza numer zagraniczny, ale nie smiec.
 * ---------------------------------------------------------------------------
 */
ue_ok(
	'' === $ue_dzial2( '123456789', 'DE' ),
	'C1: niemiecki numer o dziewieciu cyfrach przechodzi kontrole formatu',
	'komunikat=' . $ue_dzial2( '123456789', 'DE' )
);
ue_ok(
	'' === $ue_dzial2( '123456789B01', 'NL' ),
	'C2: holenderski numer z litera przechodzi',
	'komunikat=' . $ue_dzial2( '123456789B01', 'NL' )
);
ue_ok(
	'' !== $ue_dzial2( 'AB', 'DE' ),
	'C3: dwie litery to nie numer VAT — odrzucone mimo obcego kraju',
	'komunikat=' . $ue_dzial2( 'AB', 'DE' )
);
ue_ok(
	'' !== $ue_dzial2( '1111111111', 'DE' ),
	'C4: wartosc zastepcza z powtorzonej cyfry odrzucona takze za granica',
	'komunikat=' . $ue_dzial2( '1111111111', 'DE' )
);
ue_ok(
	'' !== $ue_dzial2( '', 'DE' ),
	'C5: puste pole nadal wymagane, niezaleznie od kraju',
	'komunikat=' . $ue_dzial2( '', 'DE' )
);
ue_ok(
	'' !== $ue_dzial2( '123456789', 'PL' ),
	'C6: KONTR-ASERCJA — przy PL dziewiec cyfr nadal odrzucone',
	'komunikat=' . $ue_dzial2( '123456789', 'PL' )
);
ue_ok(
	'' === $ue_dzial2( '1234563218', 'PL' ),
	'C7: KONTR-ASERCJA — poprawny polski NIP nadal przechodzi',
	'komunikat=' . $ue_dzial2( '1234563218', 'PL' )
);

/*
 * ---------------------------------------------------------------------------
 * D. Dzial 3 nie przyklada polskiej sumy kontrolnej do cudzego numeru.
 *
 * `nip_valid` znaczy „przeszedl kontrole, jaka umiemy zrobic lokalnie" — flage
 * czyta Krytyk 3.1 i to on zatrzymuje potok. Dla zagranicy kontrola lokalna
 * konczy sie na formacie, a zdanie o waznosci nalezy do VIES (agent 3.2).
 * ---------------------------------------------------------------------------
 */
ue_ok(
	true === $ue_nip_valid( '123456789', 'DE' ),
	'D1: niemiecki numer nie jest odrzucany polska suma kontrolna',
	'nip_valid=' . var_export( $ue_nip_valid( '123456789', 'DE' ), true )
);
ue_ok(
	true === $ue_nip_valid( '123456789B01', 'NL' ),
	'D2: holenderski numer z litera przechodzi Dzial 3',
	'nip_valid=' . var_export( $ue_nip_valid( '123456789B01', 'NL' ), true )
);
ue_ok(
	false === $ue_nip_valid( '2020123456', 'PL' ),
	'D3: KONTR-ASERCJA — polski numer o zlej sumie nadal odrzucony',
	'nip_valid=' . var_export( $ue_nip_valid( '2020123456', 'PL' ), true )
);
ue_ok(
	true === $ue_nip_valid( '1234563218', 'PL' ),
	'D4: KONTR-ASERCJA — poprawny polski NIP nadal przechodzi',
	'nip_valid=' . var_export( $ue_nip_valid( '1234563218', 'PL' ), true )
);
ue_ok(
	false === $ue_nip_valid( '2020123456', '' ),
	'D5: KONTR-ASERCJA — bez kraju obowiazuje polska suma kontrolna',
	'nip_valid=' . var_export( $ue_nip_valid( '2020123456', '' ), true )
);

/*
 * ---------------------------------------------------------------------------
 * E. Biala lista MF to polski urzad — nie pytamy go o cudze numery.
 *
 * Dowod jest z ruchu sieciowego, nie z deklaracji: filtr `pre_http_request`
 * zapisuje kazda probe wyjscia i oddaje odpowiedz zastepcza, wiec test nie
 * dotyka sieci.
 * ---------------------------------------------------------------------------
 */
$GLOBALS['mp_ue_url'] = array();

add_filter(
	'pre_http_request',
	static function ( $krotki_obieg, $args, $url ) {
		$GLOBALS['mp_ue_url'][] = (string) $url;

		return array(
			'response' => array( 'code' => 500 ),
			'body'     => '',
		);
	},
	10,
	3
);

$GLOBALS['mp_ue_url'] = array();
MP_D3_Agent_Company_Status::resolve_wl( '123456789', 'DE' );
$ue_zagranica = $GLOBALS['mp_ue_url'];

$GLOBALS['mp_ue_url'] = array();
MP_D3_Agent_Company_Status::resolve_wl( '1234563218', 'PL' );
$ue_polska = $GLOBALS['mp_ue_url'];

ue_ok(
	array() === $ue_zagranica,
	'E1: przy kraju obcym nie leci ani jedno zapytanie do wl-api.mf.gov.pl',
	'proby=' . implode( ' , ', $ue_zagranica )
);
ue_ok(
	1 === count( $ue_polska ) && false !== strpos( $ue_polska[0], 'wl-api.mf.gov.pl' ),
	'E2: KONTR-ASERCJA — przy PL Biala lista jest odpytywana tak jak dotad',
	'proby=' . implode( ' , ', $ue_polska )
);

echo implode( "\n", $GLOBALS['mp_ue']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_ue']['pass'], $GLOBALS['mp_ue']['fail'] );
echo ( 0 === $GLOBALS['mp_ue']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
