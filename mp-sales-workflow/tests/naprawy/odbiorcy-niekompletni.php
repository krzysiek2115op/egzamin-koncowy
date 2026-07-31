<?php
/**
 * P3-G2 — zanonimizowany proces nie mial jak zmienic statusu.
 *
 * Uruchamianie: wp eval-file tests/naprawy/odbiorcy-niekompletni.php
 *
 * Pilnuje wpisu z rejestru znanych bledow (audyt/rejestr/znane-bledy.json):
 *   - P3-G2  Zanonimizowany proces nadal produkowal odbiorce-klienta
 *
 * `client_data()` ma w komentarzu napisane wprost: „zanonimizowany wiersz
 * procesu jest sygnalem zatrzymania". Kod realizowal z tego tylko polowe —
 * pilnowal, zeby adresu NIE NADPISAL swiezy odczyt z wtyczki 1, ale sam
 * adres-zaslepke (`deleted+N@invalid`) zwracal dalej, a `run()` budowal z niego
 * pelnoprawnego odbiorce.
 *
 * Skutek byl gorszy, niz mowilo zgloszenie audytu. Wiadomosc NIE trafiala do
 * kolejki: `is_email('deleted+1@invalid')` zwraca false (domena bez kropki),
 * wiec krytyk K7.2 odrzucal cala koperte kodem `invalid_recipient` i dzial
 * konczyl sie porazka. Zanonimizowany proces nie mogl wiec przejsc W ZADEN
 * status wywolujacy powiadomienie klienta — RODO zamieniala proces w cegle.
 * Zadanie klienta o usuniecie danych blokowalo prace handlowca nad zamowieniem,
 * ktore trwalo dalej.
 *
 * Anonimizacja to poprawne, ZAMIERZONE zatrzymanie jednego powiadomienia,
 * a nie awaria: odbiorca-klient nie powstaje w ogole, pominiecie idzie do
 * dziennika (Dzial 8, `notification.skipped`), a dzial konczy sie OK.
 *
 * Czesc B pilnuje czegos odwrotnego — decyzji, ktora JUZ jest w kodzie.
 * Zgloszenie [23] audytu (kandydat P3-G3) twierdzilo, ze pusty adres handlowca
 * wpuszcza niekompletne dane do kolejki i powinien konczyc sie FAIL-em.
 * Sprawdzenie tego uruchomieniem pokazalo, ze pierwsze jest nieprawda: Agent
 * 7.2 odsiewa niedostarczalne adresy WEWNETRZNE i zapisuje je jako pominiete.
 * Zamiana tego na FAIL cofnelaby wczesniejsza naprawe — jedno konto bez
 * e-maila blokowaloby przyjmowanie leadow. Dlatego czesc B ten stan UTRWALA.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mp_on'] = array(
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
function on_ok( $warunek, $opis, $detal = '' ) {
	if ( $warunek ) {
		++$GLOBALS['mp_on']['pass'];
		$GLOBALS['mp_on']['lines'][] = '  [PASS] ' . $opis;
		return true;
	}

	++$GLOBALS['mp_on']['fail'];
	$GLOBALS['mp_on']['lines'][] = '  [FAIL] ' . $opis . ( '' !== $detal ? ' -- ' . $detal : '' );
	return false;
}

/**
 * Kontekst przejscia z powiadomieniem klienta i handlowca.
 *
 * @param string $client_email Adres w wierszu procesu.
 * @param string $sales_email  Adres handlowca w snapshocie.
 * @return MP_SW_Context
 */
function on_kontekst( $client_email, $sales_email ) {
	$kontekst = new MP_SW_Context(
		array(
			'transition' => array(
				'from'           => MP_Sales_Workflow_DB::STATUS_NEW,
				'to'             => MP_Sales_Workflow_DB::STATUS_OFFER_SENT,
				'changes_status' => true,
			),
			'effects'    => array(
				MP_SW_D5_Machine::EFFECT_NOTIFY_CLIENT,
				MP_SW_D5_Machine::EFFECT_NOTIFY_SALESMAN,
			),
			'assignment' => array( 'user_id' => 7 ),
			'event'      => array( 'entity' => array( 'lead_id' => 1 ) ),
			'event_id'   => 'ev-odbiorcy-1',
		)
	);

	$kontekst->set(
		MP_SW_D2_Reader::SNAPSHOT_KEY,
		array(
			'flow'      => array(
				'row' => array(
					'id'           => 1,
					'client_name'  => 'Firma Testowa',
					'client_email' => $client_email,
					'lang'         => 'pl',
					'offer_number' => 'OF/2026/0001',
				),
			),
			// Wtyczka 1 zna adres sprzed anonimizacji — i to jest sedno P3-G2:
			// swiezy odczyt NIE moze przywrocic kontaktu, ktory mial zniknac.
			'lead'      => array(
				'name'  => 'Firma Testowa',
				'email' => 'klient@test.local',
			),
			'salesmen'  => array(
				'complete'   => array(
					array(
						'user_id' => 7,
						'name'    => 'Handlowiec',
						'email'   => $sales_email,
					),
				),
				'incomplete' => array(),
			),
			'offer'     => array(
				'offer_number' => 'OF/2026/0001',
				'handle'       => 'uchwyt-testowy',
			),
			'templates' => array(
				'set' => array(
					MP_SW_Templates::TPL_OFFER_SENT     => array(
						'version' => '1',
						'pl'      => array(
							'subject' => 'Oferta {{offer_number}}',
							'body'    => 'Witaj {{client_name}}, dokument: {{link}}',
						),
					),
					MP_SW_Templates::TPL_STATUS_CHANGED => array(
						'version' => '1',
						'pl'      => array(
							'subject' => 'Status {{status_to}}',
							'body'    => 'Proces {{status_from}} → {{status_to}}, panel: {{link}}',
						),
					),
				),
			),
		)
	);

	return $kontekst;
}

/**
 * Uruchamia CALY Dzial 7 — pary agent+krytyk oraz bramke jakosci.
 *
 * Caly dzial, nie sama para 7.1: skutek bledu byl widoczny dopiero u krytyka
 * K7.2, ktory odrzucal koperte. Test sprawdzajacy jedna pare przegapilby to,
 * co bolalo naprawde — zatrzymanie calego przejscia.
 *
 * @param string $client_email Adres w wierszu procesu.
 * @param string $sales_email  Adres handlowca w snapshocie.
 * @return MP_SW_Result
 */
function on_dzial( $client_email, $sales_email ) {
	return MP_SW_Department_07::build()->process( on_kontekst( $client_email, $sales_email ) );
}

/**
 * Uruchamia sam agent 7.1.
 *
 * @param string $client_email Adres w wierszu procesu.
 * @param string $sales_email  Adres handlowca w snapshocie.
 * @return MP_SW_Result
 */
function on_agent( $client_email, $sales_email ) {
	$agent = new MP_SW_D7_Agent_Recipients( '7.1', 'adresaci' );

	return $agent->run( on_kontekst( $client_email, $sales_email ) );
}

/**
 * Adresy odbiorcow zbudowanych przez SAM agent 7.1, danej publicznosci.
 *
 * Agent chodzi tu osobno, poza dzialem, i to jest celowe: gdy dzial pada
 * u krytyka K7.2, klucz `recipients` w ogole nie wraca w danych wyniku —
 * asercja „nie ma odbiorcy-klienta" przechodzilaby wtedy z powodu awarii,
 * a nie z powodu naprawy. Tak zmierzone jest to, co agent naprawde zbudowal.
 *
 * @param string $client_email Adres w wierszu procesu.
 * @param string $sales_email  Adres handlowca w snapshocie.
 * @param string $audience     Publicznosc.
 * @return array
 */
function on_odbiorcy( $client_email, $sales_email, $audience ) {
	$dane = on_agent( $client_email, $sales_email )->get_data();
	$out  = array();

	foreach ( (array) ( isset( $dane['recipients'] ) ? $dane['recipients'] : array() ) as $odbiorca ) {
		if ( $audience === (string) $odbiorca['audience'] ) {
			$out[] = (string) $odbiorca['email'];
		}
	}

	return $out;
}

/**
 * Adresy wierszy kolejki zbudowanych przez dzial.
 *
 * Wiersz kolejki nie niesie publicznosci — trzyma sam adres. Porownujemy wiec
 * pelna liste, bo to ona odpowiada na pytanie „co naprawde wyjdzie z wtyczki".
 *
 * @param MP_SW_Result $wynik Wynik dzialu.
 * @return array
 */
function on_kolejka( MP_SW_Result $wynik ) {
	$dane = $wynik->get_data();
	$out  = array();

	foreach ( (array) ( isset( $dane['notifications'] ) ? $dane['notifications'] : array() ) as $wiersz ) {
		$out[] = (string) $wiersz['recipient'];
	}

	sort( $out );

	return $out;
}

/**
 * Pominiete powiadomienia danej publicznosci (slad dla dziennika).
 *
 * @param MP_SW_Result $wynik    Wynik dzialu.
 * @param string       $audience Publicznosc.
 * @return array
 */
function on_pominiete( MP_SW_Result $wynik, $audience ) {
	$dane = $wynik->get_data();
	$out  = array();

	foreach ( (array) ( isset( $dane['skipped_notifications'] ) ? $dane['skipped_notifications'] : array() ) as $wiersz ) {
		if ( $audience === (string) $wiersz['audience'] ) {
			$out[] = (string) $wiersz['cause'];
		}
	}

	return $out;
}

$GLOBALS['mp_on']['lines'][] = '=== A. zanonimizowany proces przechodzi dalej, bez powiadomienia klienta ===';

$zaslepka = sprintf( MP_SW_Privacy::PATTERN, 1 );
$anonim   = on_dzial( $zaslepka, 'handlowiec@test.local' );

on_ok(
	$anonim->is_ok(),
	'dzial konczy sie sukcesem — anonimizacja nie jest awaria procesu',
	'kod=' . $anonim->get_code() . ' bledy=' . wp_json_encode( $anonim->get_errors() )
);
on_ok(
	array() === on_odbiorcy( $zaslepka, 'handlowiec@test.local', MP_SW_D7_Notifier::AUDIENCE_CLIENT ),
	'agent 7.1 nie buduje odbiorcy dla zanonimizowanego klienta',
	'adresy=' . implode( ',', on_odbiorcy( $zaslepka, 'handlowiec@test.local', MP_SW_D7_Notifier::AUDIENCE_CLIENT ) )
);
on_ok(
	array( 'handlowiec@test.local' ) === on_kolejka( $anonim ),
	'w kolejce zostaje sam handlowiec — zadnego wiersza na adres-zaslepke',
	'kolejka=' . wp_json_encode( on_kolejka( $anonim ) )
);
on_ok(
	array( 'anonimizacja' ) === on_pominiete( $anonim, MP_SW_D7_Notifier::AUDIENCE_CLIENT ),
	'pominiecie zostawia slad dla dziennika (Dzial 8: notification.skipped)',
	'slady=' . wp_json_encode( on_pominiete( $anonim, MP_SW_D7_Notifier::AUDIENCE_CLIENT ) )
);

$GLOBALS['mp_on']['lines'][] = '';
$GLOBALS['mp_on']['lines'][] = '=== A2. KONTR-ASERCJA: zwykly proces nadal pisze do klienta ===';

/*
 * Bez tej czesci „naprawa" moglaby polegac na wycieciu powiadomien do klienta
 * w ogole — czyli na wylaczeniu kontroli zamiast jej zawezeniu. Wtedy komplet
 * asercji z czesci A przechodzi, a wtyczka przestaje wysylac oferty.
 */
$zwykly = on_dzial( 'klient@test.local', 'handlowiec@test.local' );

on_ok( $zwykly->is_ok(), 'dzial konczy sie sukcesem', 'kod=' . $zwykly->get_code() );
on_ok(
	array( 'klient@test.local' ) === on_odbiorcy( 'klient@test.local', 'handlowiec@test.local', MP_SW_D7_Notifier::AUDIENCE_CLIENT ),
	'zwykly proces ma odbiorce-klienta',
	'adresy=' . implode( ',', on_odbiorcy( 'klient@test.local', 'handlowiec@test.local', MP_SW_D7_Notifier::AUDIENCE_CLIENT ) )
);
on_ok(
	array( 'handlowiec@test.local', 'klient@test.local' ) === on_kolejka( $zwykly ),
	'obie wiadomosci trafiaja do kolejki',
	'kolejka=' . wp_json_encode( on_kolejka( $zwykly ) )
);
on_ok(
	array() === on_pominiete( $zwykly, MP_SW_D7_Notifier::AUDIENCE_CLIENT ),
	'nic nie zostaje pominiete bez powodu'
);

$GLOBALS['mp_on']['lines'][] = '';
$GLOBALS['mp_on']['lines'][] = '=== B. brak adresu handlowca: pominiecie ze sladem, NIE odmowa (utrwalenie decyzji) ===';

/*
 * Zgloszenie [23] audytu chcialo tutaj `MP_SW_Result::fail(..., 'missing_recipient')`.
 * Uruchomienie pokazalo, ze przeslanka zgloszenia jest nieprawdziwa — do kolejki
 * nic niekompletnego nie idzie. FAIL zamienilby usterke administracyjna (jedno
 * konto bez e-maila) w blokade calego procesu handlowego. Asercje ponizej sa
 * po to, zeby nikt tego nie „naprawil" w te strone.
 */
$bez_handlowca = on_dzial( 'klient@test.local', '' );

on_ok(
	$bez_handlowca->is_ok(),
	'brak adresu handlowca nie wywraca zdarzenia',
	'kod=' . $bez_handlowca->get_code() . ' bledy=' . wp_json_encode( $bez_handlowca->get_errors() )
);
on_ok(
	array( 'klient@test.local' ) === on_kolejka( $bez_handlowca ),
	'wiersz kolejki z pustym adresem nie powstaje, a klient dostaje oferte',
	'kolejka=' . wp_json_encode( on_kolejka( $bez_handlowca ) )
);
on_ok(
	array( 'brak_adresu' ) === on_pominiete( $bez_handlowca, MP_SW_D7_Notifier::AUDIENCE_SALESMAN ),
	'pominiecie handlowca ma wlasny powod w sladzie',
	'slady=' . wp_json_encode( on_pominiete( $bez_handlowca, MP_SW_D7_Notifier::AUDIENCE_SALESMAN ) )
);

echo implode( "\n", $GLOBALS['mp_on']['lines'] ) . "\n";
echo sprintf( "\n----- PASS: %d / FAIL: %d -----\n", $GLOBALS['mp_on']['pass'], $GLOBALS['mp_on']['fail'] );
echo ( 0 === $GLOBALS['mp_on']['fail'] ) ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
