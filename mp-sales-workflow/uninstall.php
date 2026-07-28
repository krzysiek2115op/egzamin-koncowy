<?php
/**
 * Deinstalacja wtyczki MP Sales Workflow.
 *
 * Uruchamiane przez WordPress przy usuwaniu wtyczki.
 *
 * DANE PROCESOW NIE SA KASOWANE DOMYSLNIE. Dziennik `mp_sw_activity` odtwarza
 * historie statusow i wysylek — to kryterium odbioru 5.5, a przy sporze z
 * klientem jedyny dowod, co i kiedy wyszlo. Usuniecie wtyczki bywa pomylka
 * (klikniecie zamiast "Wylacz", porzadki na liscie wtyczek) i nie moze
 * jednoczesnie oznaczac nieodwracalnej utraty tej historii.
 *
 * Zeby dane znikly, administrator musi wlaczyc opcje `mp_sw_delete_data`.
 * Swiadoma decyzja przed operacja nieodwracalna — nie odkrecanie jej potem.
 *
 * @package MP_Sales_Workflow
 */

// Wywoływane tylko przez WordPress podczas deinstalacji.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/db/class-mp-sales-workflow-db.php';
require_once __DIR__ . '/includes/class-mp-sw-roles.php';

// Zaplanowane zadania znikaja zawsze: hak bez kodu, ktory go obsluguje, to
// wpis w `wp_options` probowany przy kazdym zaladowaniu strony.
foreach ( array( 'mp_sw_sweep_tasks', 'mp_sw_retention', 'mp_sw_run_queue' ) as $mp_sw_hook ) {
	wp_clear_scheduled_hook( $mp_sw_hook );
}

// Ustawienia operacyjne — bez wartosci historycznej, kasowane zawsze.
foreach ( array( 'mp_sw_queue_halted' ) as $mp_sw_option ) {
	delete_option( $mp_sw_option );
}

delete_transient( 'mp_sw_mail_window' );
delete_transient( 'mp_sw_breaker_alerted' );

if ( ! get_option( 'mp_sw_delete_data', false ) ) {
	// Role zostaja razem z danymi: bez nich wiersze `assigned_user_id`
	// wskazywalyby na uzytkownikow bez zadnej roli w systemie sprzedazy.
	return;
}

// Tabele BD-1 + zapisana wersja schematu.
MP_Sales_Workflow_DB::uninstall();

// Role wtyczki + uprawnienia dołożone administratorowi.
MP_SW_Roles::uninstall();

delete_option( 'mp_sw_delete_data' );
