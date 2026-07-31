<?php
/**
 * P3-G6 — znacznik wykrywany, ale nigdy niepodstawiany.
 *
 * Uruchamianie: wp eval-file tests/naprawy/znaczniki-szablonu.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-G6  render() i unresolved_markers() uzywaly dwoch roznych skladni
 *
 * Dzial 7 mial DWIE definicje tego samego pojecia:
 *   - wykrywanie: `MARKER = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i'` — dopuszcza biale
 *     znaki wokol nazwy i nie zwraca uwagi na wielkosc liter,
 *   - podstawianie: `str_replace( '{{' . $key . '}}', ... )` — dopasowanie
 *     doslowne, bez spacji, wrazliwe na wielkosc liter.
 *
 * Redaktor piszacy w szablonie `Witaj {{ client_name }}` albo `{{Client_Name}}`
 * uzywal skladni, ktora wtyczka UZNAJE za poprawny znacznik. Podstawienia nie
 * bylo, wiec krytyk K7.2 „puste-pola" odrzucal cala koperte kodem
 * `unresolved_markers` — przejscie statusu nie dochodzilo do skutku, a jedyna
 * wskazowka brzmiala „szablon ma nierozwiazane znaczniki" przy szablonie
 * zgodnym z wlasnym wzorcem dzialu. Sprzedaz stala do czasu, az ktos zgadl,
 * ze chodzi o spacje w klamrach.
 *
 * Naprawa scala obie strony w jeden przebieg `preg_replace_callback` po tym
 * samym wzorcu MARKER. Skladnia jest odtad jedna, bo jest jedno wyrazenie.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_zs'] = array(
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
function zs_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_zs']['pass'];
		$GLOBALS['mp_zs']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_zs']['fail'];
	$GLOBALS['mp_zs']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Uruchamia Dzial 7 na szablonie o zadanej tresci.
 *
 * Sam `render()` nie wystarczy: bolala nie sama podmiana, tylko odmowa krytyka
 * K7.2, ktora zatrzymywala cale przejscie statusu. To trzeba zobaczyc.
 *
 * @param string $tresc Tresc szablonu oferty.
 * @return MP_SW_Result
 */
function zs_dzial( $tresc ) {
	$kontekst = new MP_SW_Context(
		array(
			'transition' => array(
				'from'           => MP_Sales_Workflow_DB::STATUS_NEW,
				'to'             => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
				'changes_status' => true,
			),
			'effects'    => array( MP_SW_D5_Machine::EFFECT_NOTIFY_CLIENT ),
			'assignment' => array( 'user_id' => 7 ),
			'event'      => array( 'entity' => array( 'lead_id' => 1 ) ),
			'event_id'   => 'ev-znaczniki-1',
		)
	);

	$kontekst->set(
		MP_SW_D2_Reader::SNAPSHOT_KEY,
		array(
			'flow'      => array(
				'row' => array(
					'id'           => 1,
					'client_name'  => 'Firma Testowa',
					'client_email' => 'klient@test.local',
					'lang'         => 'pl',
					'offer_number' => 'OF/2026/0001',
				),
			),
			'lead'      => array(
				'name'  => 'Firma Testowa',
				'email' => 'klient@test.local',
			),
			'salesmen'  => array(
				'complete'   => array(),
				'incomplete' => array(),
			),
			'offer'     => array(
				'offer_number' => 'OF/2026/0001',
				'handle'       => 'uchwyt-testowy',
			),
			'templates' => array(
				'set' => array(
					MP_SW_Templates::TPL_OFFER_SENT => array(
						'version' => '1',
						'pl'      => array(
							'subject' => 'Oferta {{offer_number}}',
							'body'    => $tresc,
						),
					),
				),
			),
		)
	);

	return MP_SW_Department_07::build()->process( $kontekst );
}

/**
 * Tresc pierwszej wiadomosci w kolejce.
 *
 * @param MP_SW_Result $wynik Wynik dzialu.
 * @return string
 */
function zs_tresc( MP_SW_Result $wynik ) {
	$dane   = $wynik->get_data();
	$queue  = isset( $dane['notifications'] ) ? (array) $dane['notifications'] : array();
	$pierw  = reset( $queue );

	return is_array( $pierw ) && isset( $pierw['body'] ) ? (string) $pierw['body'] : '';
}

$zmienne = array(
	'client_name' => 'Firma Testowa',
	'link'        => 'https://przyklad.test/oferta',
);

$GLOBALS['mp_zs']['lines'][] = '=== A. jedna skladnia znacznika: wykrywanie = podstawianie ===';

$przypadki = array(
	'{{client_name}}'   => 'postac podstawowa',
	'{{ client_name }}' => 'ze spacjami w klamrach',
	'{{  client_name}}' => 'ze spacja z jednej strony',
	'{{Client_Name}}'   => 'wielkimi literami',
);

foreach ( $przypadki as $znacznik => $opis ) {
	$po = MP_SW_D7_Notifier::render( 'Witaj ' . $znacznik . '!', $zmienne );

	zs_ok(
		'Witaj Firma Testowa!' === $po,
		'podstawiony znacznik — ' . $opis,
		'szablon=' . $znacznik . ' wynik=' . $po
	);
	zs_ok(
		array() === MP_SW_D7_Notifier::unresolved_markers( $po ),
		'po podstawieniu nic nie zostaje nierozwiazane — ' . $opis,
		'zostalo=' . wp_json_encode( MP_SW_D7_Notifier::unresolved_markers( $po ) )
	);
}

$GLOBALS['mp_zs']['lines'][] = '';
$GLOBALS['mp_zs']['lines'][] = '=== B. szablon ze spacjami przechodzi caly dzial ===';

$ze_spacjami = zs_dzial( 'Witaj {{ client_name }}, dokument: {{link}}' );

zs_ok(
	$ze_spacjami->is_ok(),
	'przejscie statusu dochodzi do skutku',
	'kod=' . $ze_spacjami->get_code() . ' bledy=' . wp_json_encode( $ze_spacjami->get_errors() )
);
zs_ok(
	false !== strpos( zs_tresc( $ze_spacjami ), 'Witaj Firma Testowa,' ),
	'klient dostaje nazwe firmy, a nie klamry',
	'tresc=' . zs_tresc( $ze_spacjami )
);

$GLOBALS['mp_zs']['lines'][] = '';
$GLOBALS['mp_zs']['lines'][] = '=== C. KONTR-ASERCJE: nierozwiazany znacznik nadal zatrzymuje wysylke ===';

/*
 * Bez tej czesci „naprawa" mogla polegac na wycieciu wszystkich klamr z tresci
 * albo na oslabieniu unresolved_markers(). Czesc A przeszlaby, a do klienta
 * poszlaby wiadomosc z dziura w miejscu numeru oferty — czego nie da sie cofnac.
 */
$nieznany = MP_SW_D7_Notifier::render( 'Numer: {{nieznany_znacznik}}', $zmienne );

zs_ok(
	'Numer: {{nieznany_znacznik}}' === $nieznany,
	'znacznik bez wartosci zostaje w tresci nietkniety',
	'wynik=' . $nieznany
);
zs_ok(
	array( 'nieznany_znacznik' ) === MP_SW_D7_Notifier::unresolved_markers( $nieznany ),
	'i jest raportowany jako nierozwiazany'
);

$z_dziura = zs_dzial( 'Witaj {{ client_name }}, numer: {{numer_ktorego_nie_ma}}' );

zs_ok(
	! $z_dziura->is_ok() && 'unresolved_markers' === $z_dziura->get_code(),
	'dzial nadal ODRZUCA wiadomosc z nierozwiazanym znacznikiem',
	'ok=' . var_export( $z_dziura->is_ok(), true ) . ' kod=' . $z_dziura->get_code()
);

/*
 * Wartosc podstawiona nie moze sama stac sie znacznikiem. Przy str_replace
 * w petli po kluczach tekst wstawiony wczesniej byl jeszcze przegladany przez
 * kolejne podmiany — nazwa firmy z publicznego formularza mogla wiec wskazac,
 * co wtyczka wstawi w nastepnym kroku. Jeden przebieg to zamyka.
 */
$podstepny = MP_SW_D7_Notifier::render(
	'Klient: {{client_name}}',
	array(
		'client_name' => '{{link}}',
		'link'        => 'https://przyklad.test/oferta',
	)
);

zs_ok(
	'Klient: {{link}}' === $podstepny,
	'wartosc podstawiona nie jest przegladana ponownie',
	'wynik=' . $podstepny
);

echo implode( "\n", $GLOBALS['mp_zs']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_zs']['pass'], $GLOBALS['mp_zs']['fail'] );
echo ( 0 === $GLOBALS['mp_zs']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
