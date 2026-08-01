<?php
/**
 * P3-G8 — K5.2 nie sprawdzal WEJSCIA, tylko przepisywal wyliczenie agenta.
 *
 * Uruchamianie: wp eval-file tests/naprawy/krytyk-skutkow.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-G8  Krytyk K5.2 liczyl `expected` z tego samego zrodla, co agent A5.2
 *
 * Para 5.2 ma dzialac tak: agent wylicza skutki przejscia, a krytyk sprawdza,
 * ze skutki maja pokrycie w regule. Tyle ze `$expected` u krytyka powstawalo
 * z DOKLADNIE tego samego wyrazenia, co `$effects` u agenta:
 *
 *     MP_SW_D5_Machine::effects_for( (string) $transition['to'] )
 *
 * na tym samym `context['transition']`. `array_diff()` w obie strony zawsze
 * dawal puste tablice — galaz `effects_mismatch` byla dla tej pary NIEOSIAGALNA.
 *
 * Uczciwie o zasiegu: krytyk nie byl calkiem bezuzyteczny — lapal wynik agenta
 * podstawiony z ZEWNATRZ (sekcja C tego pilnuje). Nie sprawdzal natomiast
 * WEJSCIA: nikt nie weryfikowal, czy `transition['to']` to status, o ktory
 * prosi zdarzenie. Koperta z podmienionym `to` szla przez cala pare 5.2 bez
 * slowa, a Dzialy 6 i 7 wykonywaly skutki NIE TEGO przejscia — powiadomienia
 * i zamkniecie zadan wyliczone dla cudzego statusu.
 *
 * Naprawa: krytyk czyta status docelowy NIEZALEZNIE — z typu zdarzenia przez
 * `MP_SW_D5_Machine::target_status()` — i porownuje go z tym, co podal agent.
 *
 * Kod `transition_not_from_event` to inwariant WEWNETRZNY: koperta w tym stanie
 * nie powstaje z zadnego zadania uzytkownika, tylko z bledu w kodzie albo
 * podmiany danych miedzy dzialami. Dlatego celowo NIE ma go w slowniku
 * `MP_SW_Errors::map()` i celowo NIE niesie `http_status` — ma wyjsc jako
 * MP3-E500, tak jak `multiple_writes` czy `journal_incomplete`. Sekcja D tego
 * pilnuje, bo bez niej „naprawa" mogla by polegac na dopisaniu kodu do slownika,
 * a wtedy padlby `tests/naprawy/kody-odmowy.php` (sekcja C).
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_ks'] = array(
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
function ks_ok( $cond, $msg, $info = '' ) {
	if ( $cond ) {
		++$GLOBALS['mp_ks']['pass'];
		$GLOBALS['mp_ks']['lines'][] = '  [PASS] ' . $msg;
		return true;
	}

	++$GLOBALS['mp_ks']['fail'];
	$GLOBALS['mp_ks']['lines'][] = '  [FAIL] ' . $msg . ( '' !== $info ? ' -- ' . $info : '' );
	return false;
}

/**
 * Ocena pary 5.2 dla zadanej koperty.
 *
 * Agent i krytyk uruchamiane sa tak, jak robi to dzial: agent dostaje kontekst,
 * jego wynik trafia do krytyka razem z tym samym kontekstem. Skutki mozna
 * podmienic, zeby udawac agenta, ktory policzyl co innego.
 *
 * @param array      $dane      Dane koperty.
 * @param array|null $podmiana  Skutki podstawione zamiast wyniku agenta (null = wynik agenta).
 * @return MP_SW_Result
 */
function ks_ocena( array $dane, $podmiana = null ) {
	$kontekst = new MP_SW_Context( $dane );

	$agent   = new MP_SW_D5_Agent_Effects( '5.2', 'skutki', 'test P3-G8' );
	$wynik   = $agent->run( $kontekst );
	$wyjscie = (array) $wynik->get_data();

	if ( null !== $podmiana ) {
		$wyjscie['effects'] = (array) $podmiana;
		$wynik              = MP_SW_Result::ok( $wyjscie );
	}

	$krytyk = new MP_SW_D5_Critic_Effects( 'K5.2', 'komplet-skutkow', 'test P3-G8' );

	return $krytyk->review( $wynik, $kontekst );
}

$GLOBALS['mp_ks']['lines'][] = '=== A. status docelowy NIE Z TEGO zdarzenia ===';

/*
 * Zdarzenie `offer.approved` prosi o `offer_sent`. Koperta niesie przejscie
 * na `won` — status z zupelnie innego miejsca procesu, o dwa kroki dalej,
 * ze skutkami `close_tasks` + `notify_salesman` zamiast follow-upow i poczty
 * do klienta. Agent policzy skutki dla `won` (bo czyta `transition`), wiec
 * porownanie „agent kontra ta sama tablica" nie zauwazy niczego.
 */
$koperta_obca = array(
	'event'      => array( 'type' => MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED ),
	'flow'       => array( 'row' => array( 'status' => MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT ) ),
	'transition' => array(
		'from'            => MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT,
		'to'              => MP_Sales_Workflow_DB::STATUS_WON,
		'allowed'         => true,
		'changes_status'  => true,
		'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
	),
);

$ocena_obca = ks_ocena( $koperta_obca );

ks_ok(
	! $ocena_obca->is_ok(),
	'K5.2 odmawia, gdy przejscie prowadzi gdzie indziej niz zadanie zdarzenia',
	'zdarzenie=offer.approved (→ offer_sent), transition.to=won, ocena=' . ( $ocena_obca->is_ok() ? 'PRZESZLO' : 'odmowa' )
);
ks_ok(
	'transition_not_from_event' === $ocena_obca->get_code(),
	'odmowa niesie kod transition_not_from_event',
	'kod=' . $ocena_obca->get_code()
);

$dane_obce = (array) $ocena_obca->get_data();
$pola      = isset( $dane_obce['errors'] ) ? (array) $dane_obce['errors'] : array();

ks_ok(
	in_array( 'transition.to', $pola, true ),
	'odmowa wskazuje pole, ktore sie nie zgadza',
	'errors=' . implode( ', ', $pola )
);

/*
 * To samo dla `lead.created` (→ assigned). Jedno zdarzenie moglo by przejsc
 * przypadkiem — dwa typy pokazuja, ze sprawdzenie idzie z typu zdarzenia,
 * a nie z pojedynczego dopasowania.
 */
$ocena_lead = ks_ocena(
	array(
		'event'      => array( 'type' => MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED ),
		'flow'       => array( 'row' => array( 'status' => MP_Sales_Workflow_DB::STATUS_NEW ) ),
		'transition' => array(
			'from'            => MP_Sales_Workflow_DB::STATUS_NEW,
			'to'              => MP_Sales_Workflow_DB::STATUS_LOST,
			'allowed'         => true,
			'changes_status'  => true,
			'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
		),
	)
);

ks_ok(
	! $ocena_lead->is_ok() && 'transition_not_from_event' === $ocena_lead->get_code(),
	'lead.created z przejsciem na „przegrany" tez zostaje zatrzymany',
	'kod=' . $ocena_lead->get_code()
);

/*
 * `status.change` bierze status docelowy z koperty (`to_status`), wiec tutaj
 * niezalezne odczytanie ma realna tresc: `to_status` mowi jedno, `transition.to`
 * drugie.
 */
$ocena_zmiana = ks_ocena(
	array(
		'event'      => array( 'type' => MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE ),
		'to_status'  => MP_Sales_Workflow_DB::STATUS_NEGOTIATION,
		'flow'       => array( 'row' => array( 'status' => MP_Sales_Workflow_DB::STATUS_OFFER_SENT ) ),
		'transition' => array(
			'from'            => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
			'to'              => MP_Sales_Workflow_DB::STATUS_WON,
			'allowed'         => true,
			'changes_status'  => true,
			'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
		),
	)
);

ks_ok(
	! $ocena_zmiana->is_ok() && 'transition_not_from_event' === $ocena_zmiana->get_code(),
	'status.change: to_status=negocjacje kontra transition.to=wygrany → odmowa',
	'kod=' . $ocena_zmiana->get_code()
);

$GLOBALS['mp_ks']['lines'][] = '';
$GLOBALS['mp_ks']['lines'][] = '=== B. KONTR-ASERCJE: zgodna koperta ma przechodzic ===';

/*
 * Bez tej sekcji „naprawa" mogla by polegac na odmawianiu zawsze. Kazde
 * zdarzenie zmieniajace status przechodzi tedy w normalnym przebiegu, wiec
 * blad tutaj zatrzymalby cala wtyczke — i test musi to wychwycic zamiast
 * zostawiac to regresji.
 */
$zgodne = array(
	'offer.approved → oferta_wyslana' => array(
		MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED,
		MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT,
		MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
		array(),
	),
	'lead.created → przypisany'       => array(
		MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED,
		MP_Sales_Workflow_DB::STATUS_NEW,
		MP_Sales_Workflow_DB::STATUS_ASSIGNED,
		array(),
	),
	'status.change → wygrany'         => array(
		MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
		MP_Sales_Workflow_DB::STATUS_NEGOTIATION,
		MP_Sales_Workflow_DB::STATUS_WON,
		array( 'to_status' => MP_Sales_Workflow_DB::STATUS_WON ),
	),
);

foreach ( $zgodne as $opis => $przypadek ) {
	list( $typ, $from, $to, $dodatki ) = $przypadek;

	$ocena = ks_ocena(
		array_merge(
			$dodatki,
			array(
				'event'      => array( 'type' => $typ ),
				'flow'       => array( 'row' => array( 'status' => $from ) ),
				'transition' => array(
					'from'            => $from,
					'to'              => $to,
					'allowed'         => true,
					'changes_status'  => true,
					'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
				),
			)
		)
	);

	$dane = (array) $ocena->get_data();

	ks_ok(
		$ocena->is_ok(),
		'przechodzi zgodna koperta: ' . $opis,
		'kod=' . $ocena->get_code()
	);
	ks_ok(
		MP_SW_D5_Machine::effects_for( $to ) === ( isset( $dane['effects'] ) ? (array) $dane['effects'] : array() ),
		'skutki zostaja te z reguly przejscia: ' . $opis,
		'skutki=' . implode( ', ', isset( $dane['effects'] ) ? (array) $dane['effects'] : array() )
	);
}

// Zdarzenie bez statusu docelowego: `transition.to` rowna sie `from`, a skutkow
// nie ma. Niezaleznie odczytany status jest pusty i NIE moze tu niczego zepsuc.
foreach ( MP_SW_D5_Machine::statusless_events() as $typ_bez ) {
	$ocena_bez = ks_ocena(
		array(
			'event'      => array( 'type' => $typ_bez ),
			'flow'       => array( 'row' => array( 'status' => MP_Sales_Workflow_DB::STATUS_ASSIGNED ) ),
			'transition' => array(
				'from'            => MP_Sales_Workflow_DB::STATUS_ASSIGNED,
				'to'              => MP_Sales_Workflow_DB::STATUS_ASSIGNED,
				'allowed'         => true,
				'changes_status'  => false,
				'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
			),
		)
	);

	$dane_bez = (array) $ocena_bez->get_data();

	ks_ok(
		$ocena_bez->is_ok(),
		'zdarzenie bez zmiany statusu przechodzi: ' . $typ_bez,
		'kod=' . $ocena_bez->get_code()
	);
	ks_ok(
		array() === ( isset( $dane_bez['effects'] ) ? (array) $dane_bez['effects'] : array() ),
		'zdarzenie bez zmiany statusu nie ma skutkow: ' . $typ_bez
	);
}

// Powtorzone zatwierdzenie oferty: A5.1 oddaje `to = from` z `changes_status`
// false. Zdarzenie prosi o `offer_sent`, a `transition.to` mowi `offer_sent` —
// ale nawet gdyby mowilo co innego, brak zmiany statusu znaczy brak skutkow
// i nie ma czego pilnowac.
$ocena_powtorka = ks_ocena(
	array(
		'event'      => array( 'type' => MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED ),
		'flow'       => array( 'row' => array( 'status' => MP_Sales_Workflow_DB::STATUS_OFFER_SENT ) ),
		'transition' => array(
			'from'            => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
			'to'              => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
			'allowed'         => true,
			'changes_status'  => false,
			'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
		),
	)
);

ks_ok(
	$ocena_powtorka->is_ok(),
	'powtorzone zatwierdzenie oferty (przejscie w to samo miejsce) przechodzi',
	'kod=' . $ocena_powtorka->get_code()
);

$GLOBALS['mp_ks']['lines'][] = '';
$GLOBALS['mp_ks']['lines'][] = '=== C. stare sprawdzenie ma dzialac dalej ===';

/*
 * Krytyk lapal wynik agenta podstawiony z zewnatrz i ma to robic nadal.
 * Gdyby naprawa zastapila porownanie skutkow porownaniem samych statusow,
 * ta sekcja by padla.
 */
$koperta_zgodna = array(
	'event'      => array( 'type' => MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED ),
	'flow'       => array( 'row' => array( 'status' => MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT ) ),
	'transition' => array(
		'from'            => MP_Sales_Workflow_DB::STATUS_OFFER_DRAFT,
		'to'              => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
		'allowed'         => true,
		'changes_status'  => true,
		'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
	),
);

$nadmiar = ks_ocena(
	$koperta_zgodna,
	array_merge( MP_SW_D5_Machine::effects_for( MP_Sales_Workflow_DB::STATUS_OFFER_SENT ), array( MP_SW_D5_Machine::EFFECT_NOTIFY_SALESMAN ) )
);

ks_ok(
	! $nadmiar->is_ok() && 'effects_mismatch' === $nadmiar->get_code(),
	'skutek doklejony „przy okazji" nadal zatrzymuje pare',
	'kod=' . $nadmiar->get_code()
);

$brak = ks_ocena( $koperta_zgodna, array( MP_SW_D5_Machine::EFFECT_NOTIFY_CLIENT ) );

ks_ok(
	! $brak->is_ok() && 'effects_mismatch' === $brak->get_code(),
	'skutek zgubiony (follow-upy, ktore nie powstana) nadal zatrzymuje pare',
	'kod=' . $brak->get_code()
);

$bez_sla = ks_ocena(
	array(
		'event'      => array( 'type' => MP_SW_Pipeline_Factory::EVENT_LEAD_CREATED ),
		'flow'       => array( 'row' => array( 'status' => MP_Sales_Workflow_DB::STATUS_NEW ) ),
		'transition' => array(
			'from'            => MP_Sales_Workflow_DB::STATUS_NEW,
			'to'              => MP_Sales_Workflow_DB::STATUS_ASSIGNED,
			'allowed'         => true,
			'changes_status'  => true,
			'machine_version' => MP_SW_D5_Machine::MACHINE_VERSION,
		),
	)
);
$dane_sla = (array) $bez_sla->get_data();

ks_ok(
	$bez_sla->is_ok() && '' !== (string) $dane_sla['sla_due_at'],
	'przejscie ze skutkiem set_sla ma wyliczony termin',
	'sla_due_at=' . ( isset( $dane_sla['sla_due_at'] ) ? $dane_sla['sla_due_at'] : '(brak)' )
);

$GLOBALS['mp_ks']['lines'][] = '';
$GLOBALS['mp_ks']['lines'][] = '=== D. inwariant zostaje inwariantem (nie przecieka do HTTP) ===';

$slownik_kodow = MP_SW_Errors::map();

ks_ok(
	! isset( $slownik_kodow['transition_not_from_event'] ),
	'transition_not_from_event NIE jest w slowniku kodow publicznych'
);
ks_ok(
	MP_SW_Errors::E_INTERNAL === MP_SW_Errors::code( 'transition_not_from_event' ),
	'transition_not_from_event wychodzi jako MP3-E500 (blad wewnetrzny)',
	'jest=' . MP_SW_Errors::code( 'transition_not_from_event' )
);
ks_ok(
	! isset( $dane_obce['http_status'] ),
	'odmowa inwariantu nie niesie wlasnego http_status (inaczej padnie kody-odmowy.php)',
	'http_status=' . ( isset( $dane_obce['http_status'] ) ? $dane_obce['http_status'] : '(brak)' )
);

$GLOBALS['mp_ks']['lines'][] = '';
$GLOBALS['mp_ks']['lines'][] = '=== E. krytyk czyta status NIEZALEZNIE od tablicy przejscia ===';

/*
 * Asercja na samym kodzie, nie na zachowaniu. Zachowanie da sie spelnic
 * przypadkiem (np. porownaniem `transition.to` z `transition.to`), a chodzi
 * o to, ze `expected` liczy sie ze statusu wyprowadzonego z TYPU ZDARZENIA.
 * Komentarze odsiewamy tokenizerem — inaczej alarm zapala wlasny opis naprawy.
 */
$plik_dzialu = dirname( __DIR__ ) . '/../includes/pipeline/departments/class-mp-sw-department-05.php';
$zrodlo      = is_readable( $plik_dzialu ) ? (string) file_get_contents( $plik_dzialu ) : '';

ks_ok( '' !== $zrodlo, 'zrodlo Dzialu 5 czytelne', 'sciezka=' . $plik_dzialu );

$kod_bez_komentarzy = '';

foreach ( token_get_all( $zrodlo ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}

	$kod_bez_komentarzy .= is_array( $token ) ? $token[1] : $token;
}

$od_krytyka = strpos( $kod_bez_komentarzy, 'class MP_SW_D5_Critic_Effects' );
$cialo      = false === $od_krytyka ? '' : substr( $kod_bez_komentarzy, $od_krytyka );
$koniec     = strpos( $cialo, 'class MP_SW_D5_QA_Agent' );
$cialo      = false === $koniec ? $cialo : substr( $cialo, 0, $koniec );

ks_ok(
	'' !== $cialo,
	'znaleziono cialo klasy krytyka w zrodle'
);
ks_ok(
	false !== strpos( $cialo, 'target_status' ),
	'krytyk wyprowadza status docelowy z typu zdarzenia (target_status)'
);

echo implode( "\n", $GLOBALS['mp_ks']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_ks']['pass'], $GLOBALS['mp_ks']['fail'] );
echo ( 0 === $GLOBALS['mp_ks']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
