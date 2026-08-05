<?php
/**
 * Trzy ustalenia audytu z Działu 5 — wszystkie w jednej gałęzi „przejście w to
 * samo miejsce" i w warunku ją poprzedzającym.
 *
 * Uruchamianie: wp eval-file tests/naprawy/powtorne-zatwierdzenie-oferty.php
 *
 * A. DRUGA OFERTA GINIE PO CICHU (średnie). Handlowiec robi w P2 poprawioną
 *    ofertę dla tego samego procesu i zatwierdza ją. P2 wysyła `offer.approved`
 *    z NOWYM `event_id`, więc bramka idempotencji go nie zatrzyma. Proces jest
 *    już w `offer_sent`, więc `target_status()` zwraca `offer_sent`, przejście
 *    `offer_sent → offer_sent` nie ma w słowniku i wchodzi gałąź „w to samo
 *    miejsce" z `changes_status = false`. A5.2 liczy skutki wyłącznie wtedy, gdy
 *    status się zmienia, więc lista wychodzi pusta: klient NIE DOSTAJE
 *    powiadomienia o nowej ofercie, follow-upy nie powstają, a P2 widzi HTTP 200
 *    i uznaje, że ofertę wysłano. Operacja się nie odbyła, a wynik mówi „dobrze".
 *
 *    Rozróżnienie, którego brakowało: powtórka TEGO SAMEGO zdarzenia (od tego
 *    jest `event_id` i token blokady) to co innego niż NOWE zdarzenie prowadzące
 *    w ten sam status. Pierwsze ma być ciche, drugie ma mieć skutki.
 *
 *    Duplikatów zadań to nie tworzy — broni przed nimi A6.2 przez `open_key`
 *    (sekcja E). Ryzykiem był brak wysyłki, nie nadmiar.
 *
 * B. STATUS SPOZA SŁOWNIKA POTWIERDZANY SUKCESEM. Ta sama gałąź nie pytała
 *    o `$known`. Wiersz ze statusem spoza aktualnego słownika — zapisany przez
 *    starszą wersję maszyny (stała `MACHINE_VERSION` istnieje właśnie dlatego,
 *    że słownik jest wersjonowany) albo wstawiony ręcznie w bazie — na żądanie
 *    `status.change` w ten sam status dostawał „stan potwierdzony" zamiast
 *    odmowy, którą to samo żądanie dostaje dla każdego innego wiersza. Wiersz
 *    zablokowany nigdy nie zgłaszał się jako zepsuty.
 *
 * C. KOMUNIKAT O POLU, KTÓREGO NIKT NIE WYSŁAŁ. Typ zdarzenia spoza słownika
 *    fabryki i spoza `statusless_events()` odbijał się komunikatem „Zmiana
 *    statusu bez statusu docelowego" i polem błędu `to_status`, mimo że
 *    wywołujący o żadną zmianę statusu nie prosił. Diagnostyka kierowała na
 *    treść żądania zamiast na brakujący wpis w liście typów. To ten sam błąd,
 *    który naprawiono w K5.1, tylko w drugą stronę.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_po'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'lines' => array(),
);

/**
 * Asercja.
 *
 * @param bool   $warunek Warunek.
 * @param string $opis    Opis.
 * @param string $detal   Szczegół przy porażce.
 * @return bool
 */
function po_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_po']['pass'];
		$GLOBALS['mp_po']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_po']['fail'];
	$GLOBALS['mp_po']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Wypisuje wynik.
 *
 * @return void
 */
function po_koniec() {
	if ( empty( $GLOBALS['mp_po']['lines'] ) ) {
		return;
	}

	$r    = $GLOBALS['mp_po'];
	$out  = implode( "\n", $r['lines'] );
	$out .= "\n\n----- PASS: " . $r['pass'] . ' / FAIL: ' . $r['fail'] . " -----\n";
	$out .= 0 === $r['fail'] ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";

	$GLOBALS['mp_po']['lines'] = array();
	echo $out; // phpcs:ignore
}
register_shutdown_function( 'po_koniec' );

/**
 * Uruchamia A5.1 na kopercie zbudowanej z podanych części.
 *
 * @param string $typ            Typ zdarzenia.
 * @param string $status_wiersza Status procesu w bazie.
 * @param array  $dodatkowe      Dodatkowe klucze kontekstu (np. to_status).
 * @return MP_SW_Result
 */
function po_przejscie( $typ, $status_wiersza, array $dodatkowe = array() ) {
	$agent = new MP_SW_D5_Agent_Transition( '5.1', 'przejscie', 'test' );

	$dane = array_merge(
		array(
			'event' => array( 'type' => $typ ),
			'flow'  => array( 'row' => array( 'status' => $status_wiersza ) ),
		),
		$dodatkowe
	);

	return $agent->run( new MP_SW_Context( $dane ) );
}

/**
 * Liczy skutki A5.2 dla gotowej tablicy przejścia.
 *
 * @param array  $transition Tablica przejścia z A5.1.
 * @param string $typ        Typ zdarzenia.
 * @param array  $dodatkowe  Dodatkowe klucze kontekstu.
 * @return array Lista skutków.
 */
function po_skutki( array $transition, $typ, array $dodatkowe = array() ) {
	$agent = new MP_SW_D5_Agent_Effects( '5.2', 'skutki', 'test' );

	$dane = array_merge(
		array(
			'transition' => $transition,
			'event'      => array( 'type' => $typ ),
		),
		$dodatkowe
	);

	$wynik = $agent->run( new MP_SW_Context( $dane ) );
	$dane  = $wynik->get_data();

	return isset( $dane['effects'] ) ? (array) $dane['effects'] : array();
}

/* ==================================================================== A */

$GLOBALS['mp_po']['lines'][] = '=== A. druga oferta dla tego samego procesu ma skutki ===';

$po_a = po_przejscie(
	MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED,
	MP_Sales_Workflow_DB::STATUS_OFFER_SENT
);
$po_at = (array) ( $po_a->get_data()['transition'] ?? array() );

po_ok(
	$po_a->is_ok(),
	'ponowione zatwierdzenie oferty nie jest bledem'
);
po_ok(
	true === ( $po_at['allowed'] ?? null ) && false === ( $po_at['changes_status'] ?? null ),
	'status sie nie zmienia — proces juz jest w offer_sent'
);
po_ok(
	true === ( $po_at['repeat_entry'] ?? null ),
	'gałąź oznacza sie jako PONOWNE WEJSCIE w ten sam status',
	'repeat_entry=' . var_export( $po_at['repeat_entry'] ?? null, true )
);
po_ok(
	true === ( $po_at['known_status'] ?? null ),
	'i niesie known_status, tak jak galaz glowna'
);

$po_ea = po_skutki( $po_at, MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED );

po_ok(
	in_array( MP_SW_D5_Machine::EFFECT_NOTIFY_CLIENT, $po_ea, true ),
	'klient dostaje powiadomienie o nowej ofercie',
	'skutki=' . implode( ',', $po_ea )
);
po_ok(
	in_array( MP_SW_D5_Machine::EFFECT_SCHEDULE_FOLLOWUPS, $po_ea, true ),
	'follow-upy sa planowane',
	'skutki=' . implode( ',', $po_ea )
);

$po_k52 = new MP_SW_D5_Critic_Effects( '5.2', 'komplet-skutkow', 'test' );
$po_rk  = $po_k52->review(
	MP_SW_Result::ok( array( 'effects' => $po_ea ) ),
	new MP_SW_Context(
		array(
			'transition' => $po_at,
			'event'      => array( 'type' => MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED ),
		)
	)
);

po_ok(
	$po_rk->is_ok(),
	'K5.2 przyjmuje skutki ponownego wejscia',
	'kod=' . $po_rk->get_code() . ' ' . implode( ' ', (array) $po_rk->get_errors() )
);

/* ==================================================================== B */

$GLOBALS['mp_po']['lines'][] = '';
$GLOBALS['mp_po']['lines'][] = '=== B. status spoza slownika nie jest potwierdzany sukcesem ===';

$po_b = po_przejscie(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	'wygrny',
	array( 'to_status' => 'wygrny' )
);
$po_bt = (array) ( $po_b->get_data()['transition'] ?? array() );

po_ok(
	false === ( $po_bt['allowed'] ?? null ),
	'przejscie w ten sam NIEISTNIEJACY status nie jest dozwolone',
	'allowed=' . var_export( $po_bt['allowed'] ?? null, true )
);
po_ok(
	false === ( $po_bt['known_status'] ?? null ),
	'known_status mowi wprost, ze statusu nie ma w slowniku'
);

$po_k51 = new MP_SW_D5_Critic_Legality( '5.1', 'legalnosc-przejscia', 'test' );
$po_rb  = $po_k51->review(
	MP_SW_Result::ok( array( 'transition' => $po_bt ) ),
	new MP_SW_Context( array() )
);
$po_mb  = implode( ' ', (array) $po_rb->get_errors() );

po_ok(
	! $po_rb->is_ok() && 'illegal_transition' === $po_rb->get_code(),
	'K5.1 odmawia',
	'kod=' . $po_rb->get_code()
);
po_ok(
	false !== mb_stripos( $po_mb, 'nie istnieje' ),
	'i mowi o NIEISTNIEJACYM STATUSIE, nie o regule przejscia',
	'komunikat=' . $po_mb
);

/* ==================================================================== C */

$GLOBALS['mp_po']['lines'][] = '';
$GLOBALS['mp_po']['lines'][] = '=== C. nieobslugiwany typ zdarzenia ma wlasny komunikat ===';

$po_c = po_przejscie( 'note.added', MP_Sales_Workflow_DB::STATUS_ASSIGNED );
$po_mc = implode( ' ', (array) $po_c->get_errors() );

po_ok(
	! $po_c->is_ok(),
	'typ spoza slownika fabryki jest odrzucany'
);
po_ok(
	'unsupported_event_type' === $po_c->get_code(),
	'kodem o NIEOBSLUGIWANYM TYPIE, nie o brakujacym statusie',
	'kod=' . $po_c->get_code()
);
po_ok(
	false === mb_stripos( $po_mc, 'to_status' ) && false !== mb_stripos( $po_mc, 'note.added' ),
	'komunikat nazywa typ, a nie pole, ktorego nikt nie wyslal',
	'komunikat=' . $po_mc
);

$po_dc = $po_c->get_data();
po_ok(
	! in_array( 'to_status', (array) ( $po_dc['errors'] ?? array() ), true ),
	'pole bledu nie wskazuje na to_status'
);

/* ==================================================================== D */

$GLOBALS['mp_po']['lines'][] = '';
$GLOBALS['mp_po']['lines'][] = '=== D. kontr-asercje: co ma zostac po staremu ===';

$po_d1 = po_przejscie(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
	array( 'to_status' => '' )
);

po_ok(
	! $po_d1->is_ok() && 'missing_target_status' === $po_d1->get_code(),
	'status.change bez to_status nadal ma kod missing_target_status',
	'kod=' . $po_d1->get_code()
);

$po_d2 = po_przejscie(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
	array( 'to_status' => MP_Sales_Workflow_DB::STATUS_OFFER_SENT )
);
$po_d2t = (array) ( $po_d2->get_data()['transition'] ?? array() );

po_ok(
	$po_d2->is_ok() && true === ( $po_d2t['allowed'] ?? null ),
	'reczne potwierdzenie tego samego statusu nadal przechodzi'
);
po_ok(
	empty( $po_d2t['repeat_entry'] ),
	'ale NIE jest ponownym wejsciem — nie niesie nowego faktu',
	'repeat_entry=' . var_export( $po_d2t['repeat_entry'] ?? null, true )
);
po_ok(
	array() === po_skutki( $po_d2t, MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE ),
	'wiec nie wysyla klientowi niczego drugi raz'
);

$po_d3 = po_przejscie( MP_SW_Pipeline_Factory::EVENT_DASHBOARD_VIEW, MP_Sales_Workflow_DB::STATUS_ASSIGNED );

po_ok(
	$po_d3->is_ok(),
	'zdarzenie bez statusu (dashboard.view) nadal przechodzi'
);

$po_d4 = po_przejscie( MP_SW_Pipeline_Factory::EVENT_TASK_DUE, MP_Sales_Workflow_DB::STATUS_OFFER_SENT );

po_ok(
	$po_d4->is_ok() && empty( $po_d4->get_data()['transition']['repeat_entry'] ),
	'task.due nadal przechodzi i nie wywoluje skutkow'
);

$po_d5 = po_przejscie(
	MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE,
	MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
	array( 'to_status' => MP_Sales_Workflow_DB::STATUS_WON )
);
$po_d5t = (array) ( $po_d5->get_data()['transition'] ?? array() );

po_ok(
	true === ( $po_d5t['changes_status'] ?? null ),
	'legalne przejscie offer_sent -> won nadal zmienia status'
);
po_ok(
	in_array( MP_SW_D5_Machine::EFFECT_CLOSE_TASKS, po_skutki( $po_d5t, MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE, array( 'to_status' => MP_Sales_Workflow_DB::STATUS_WON ) ), true ),
	'i nadal ma swoje skutki'
);

/* ==================================================================== E */

$GLOBALS['mp_po']['lines'][] = '';
$GLOBALS['mp_po']['lines'][] = '=== E. ponowne wejscie nie powiela zadan ===';

$po_a62 = new MP_SW_D6_Agent_Dedup( '6.2', 'deduplikacja', 'test' );
$po_now = current_time( 'mysql', true );

$po_plan = array();
foreach ( MP_SW_D6_Scheduler::plan_types() as $po_typ => $po_dni ) {
	$po_plan[] = array(
		'type'         => $po_typ,
		'due_at'       => MP_SW_D6_Scheduler::due_at( $po_dni, $po_now ),
		'guard_status' => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
		'offset_days'  => (int) $po_dni,
	);
}

$po_otwarte = array();
$po_i       = 1;
foreach ( MP_SW_D6_Scheduler::plan_types() as $po_typ => $po_dni ) {
	$po_otwarte[] = array(
		'id'           => $po_i++,
		'type'         => $po_typ,
		'guard_status' => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
	);
}

$po_re = $po_a62->run(
	new MP_SW_Context(
		array(
			MP_SW_D2_Reader::SNAPSHOT_KEY => array(
				'flow' => array(
					'row'        => array(
						'id'     => 7,
						'status' => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
					),
					'open_tasks' => $po_otwarte,
				),
			),
			'followup'                    => array(
				'schedule'   => $po_plan,
				'fire'       => array(),
				'skip'       => array(),
				'planned_at' => $po_now,
			),
			'effects'                     => array( MP_SW_D5_Machine::EFFECT_SCHEDULE_FOLLOWUPS, MP_SW_D5_Machine::EFFECT_NOTIFY_CLIENT ),
			'event_id'                    => 'evt-druga-oferta',
		)
	)
);
$po_pl = (array) ( $po_re->get_data()['tasks_plan'] ?? array() );

po_ok(
	array() === (array) ( $po_pl['create'] ?? array( 'x' ) ),
	'druga para follow-upow NIE powstaje — zadania tego typu juz czekaja',
	'create=' . count( (array) ( $po_pl['create'] ?? array() ) )
);
po_ok(
	count( (array) ( $po_pl['duplicates'] ?? array() ) ) === count( MP_SW_D6_Scheduler::plan_types() ),
	'oba typy sa rozpoznane jako duplikaty'
);
po_ok(
	array() === (array) ( $po_pl['close'] ?? array( 'x' ) ),
	'i nic nie jest zamykane — otwarte terminy zostaja wazne'
);

/* ==================================================================== F */

$GLOBALS['mp_po']['lines'][] = '';
$GLOBALS['mp_po']['lines'][] = '=== F. komplet pol w KAZDEJ galezi ===';

/*
 * Docblock galezi „w to samo miejsce" nazywa komplet pol tablicy `transition`
 * inwariantem NIEZALEZNYM od galezi. Galaz dla zdarzen bez statusu docelowego
 * (`task.due`, `dashboard.view`) go lamala: oddawala tablice bez `known_status`.
 * Odbiorca musialby wtedy rozrozniac, z ktorej galezi przyszla tablica — czyli
 * dokladnie to, przed czym inwariant chroni.
 */
foreach ( MP_SW_D5_Machine::statusless_events() as $po_typ_bez ) {
	$po_bez = po_przejscie( $po_typ_bez, MP_Sales_Workflow_DB::STATUS_ASSIGNED );
	$po_bt  = (array) ( $po_bez->get_data()['transition'] ?? array() );

	po_ok(
		array_key_exists( 'known_status', $po_bt ),
		'F: ' . $po_typ_bez . ' — tablica przejscia niesie known_status',
		'klucze=' . implode( ',', array_keys( $po_bt ) )
	);
	po_ok(
		array_key_exists( 'repeat_entry', $po_bt ) && false === $po_bt['repeat_entry'],
		'F: ' . $po_typ_bez . ' — i repeat_entry rowne false',
		'repeat_entry=' . var_export( $po_bt['repeat_entry'] ?? null, true )
	);
}

$po_widmo = po_przejscie( MP_SW_Pipeline_Factory::EVENT_TASK_DUE, 'status-po-starej-maszynie' );
$po_wt    = (array) ( $po_widmo->get_data()['transition'] ?? array() );

po_ok(
	false === ( $po_wt['known_status'] ?? null ),
	'F: status wiersza spoza slownika jest widoczny takze przy zdarzeniu bez statusu',
	'known_status=' . var_export( $po_wt['known_status'] ?? null, true )
);

$po_klucze_a = array_keys( (array) ( po_przejscie( MP_SW_Pipeline_Factory::EVENT_OFFER_APPROVED, MP_Sales_Workflow_DB::STATUS_OFFER_SENT )->get_data()['transition'] ?? array() ) );
$po_klucze_b = array_keys( (array) ( po_przejscie( MP_SW_Pipeline_Factory::EVENT_TASK_DUE, MP_Sales_Workflow_DB::STATUS_ASSIGNED )->get_data()['transition'] ?? array() ) );
$po_klucze_c = array_keys( (array) ( po_przejscie( MP_SW_Pipeline_Factory::EVENT_STATUS_CHANGE, MP_Sales_Workflow_DB::STATUS_OFFER_SENT, array( 'to_status' => MP_Sales_Workflow_DB::STATUS_WON ) )->get_data()['transition'] ?? array() ) );

sort( $po_klucze_a );
sort( $po_klucze_b );
sort( $po_klucze_c );

po_ok(
	$po_klucze_a === $po_klucze_b && $po_klucze_b === $po_klucze_c,
	'F: wszystkie trzy galezie A5.1 oddaja DOKLADNIE te same klucze',
	'a=' . implode( ',', $po_klucze_a ) . ' | b=' . implode( ',', $po_klucze_b ) . ' | c=' . implode( ',', $po_klucze_c )
);
