<?php
/**
 * NARZ-F4 — para 1.21 czytala z `wp_schedule_event()` nie ten argument.
 *
 * Uruchamianie: php audyt/tests/regula-1-21.php
 *
 * Para 1.21 pilnuje, zeby kazde zaplanowane zadanie bylo przy deaktywacji
 * sprzatniete. Nazwe zadania wyciagala z wywolania regula, ktora brala
 * PIERWSZY napis w cudzyslowie:
 *
 *     wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'mp_lead_intake_ip_retention' )
 *                                                  ^^^^^
 *
 * Pierwszy napis to CZESTOTLIWOSC, a nie hak. Sygnatura WordPressa jest
 * trzyargumentowa: `wp_schedule_event( $timestamp, $recurrence, $hook )`.
 * Para zapisywala wiec „daily" jako nazwe zadania i szukala potem
 * `wp_clear_scheduled_hook( 'daily' )` — wywolania, ktore nie ma prawa
 * istniec, bo sprzata sie HAK, nie czestotliwosc.
 *
 * Skutek byl podwojny i oba konce sa zle:
 *
 * 1. FALSZYWY ALARM. W przebiegu z 01.08.2026 para zglosila dwa ustalenia
 *    sredniej wagi — „zadanie daily nigdzie nieusuwane" i to samo o „hourly" —
 *    podczas gdy `mp-lead-intake.php` sprzata OBA haki przy deaktywacji
 *    i jeszcze raz w `uninstall.php`.
 * 2. SLEPOTA. Skoro pod nazwa zadania zapisywano czestotliwosc, prawdziwa
 *    nazwa haka nie trafiala do zestawienia w ogole. Zadanie naprawde
 *    niesprzatniete przeszloby przez te pare bez slowa — a to jest jedyna
 *    rzecz, ktorej ta para ma pilnowac.
 *
 * `wp_schedule_single_event( $timestamp, $hook )` ma hak na DRUGIEJ pozycji,
 * wiec pozycja zalezy od funkcji i nie da sie jej zgadnac jedna regula.
 *
 * @package MP_Audyt
 */

$korzen = dirname( __DIR__ );

require_once $korzen . '/includes/rdzen.php';
require_once $korzen . '/includes/kontrakty.php';
require_once $korzen . '/includes/pomoc.php';
require_once $korzen . '/includes/pary/dzial-01-integracja.php';

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
function r121_ok( bool $warunek, string $opis, string $info = '' ): void {
	global $pass, $fail;

	if ( $warunek ) {
		++$pass;
		echo "  [PASS] {$opis}\n";
		return;
	}

	++$fail;
	echo "  [FAIL] {$opis}" . ( '' !== $info ? ' -- ' . $info : '' ) . "\n";
}

echo "=== A. wp_schedule_event: hak jest TRZECIM argumentem ===\n";

$prawdziwy = "if ( ! wp_next_scheduled( 'mp_lead_intake_ip_retention' ) ) {\n"
	. "\twp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'mp_lead_intake_ip_retention' );\n"
	. "}\n";

$haki = MP_AU_A121_Cykl_Zycia::haki_z_planowania( 'wp_schedule_event', $prawdziwy );

r121_ok(
	in_array( 'mp_lead_intake_ip_retention', $haki, true ),
	'A1: z prawdziwego kodu wtyczki 1 wychodzi nazwa HAKA',
	'wyszlo: ' . implode( ', ', $haki )
);
r121_ok(
	! in_array( 'daily', $haki, true ),
	'A2: czestotliwosc „daily" NIE jest brana za nazwe zadania',
	'wyszlo: ' . implode( ', ', $haki )
);

$godzinowy = "wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'mp_lead_intake_vat_reconcile' );";
$haki_h    = MP_AU_A121_Cykl_Zycia::haki_z_planowania( 'wp_schedule_event', $godzinowy );

r121_ok(
	array( 'mp_lead_intake_vat_reconcile' ) === $haki_h,
	'A3: to samo dla zadania godzinowego — jedna pozycja, nazwa haka',
	'wyszlo: ' . implode( ', ', $haki_h )
);

echo "=== B. wp_schedule_single_event: hak jest DRUGIM argumentem ===\n";

$jednorazowy = "wp_schedule_single_event( time() + 300, 'mp_lead_intake_verify_vat', array( \$lead_id ) );";
$haki_j      = MP_AU_A121_Cykl_Zycia::haki_z_planowania( 'wp_schedule_single_event', $jednorazowy );

r121_ok(
	array( 'mp_lead_intake_verify_vat' ) === $haki_j,
	'B1: pozycja argumentu zalezy od funkcji, nie jest stala',
	'wyszlo: ' . implode( ', ', $haki_j )
);
r121_ok(
	! in_array( 'lead_id', $haki_j, true ),
	'B2: KONTR-ASERCJA — argumenty PO haku nie sa brane za kolejne haki',
	'wyszlo: ' . implode( ', ', $haki_j )
);

echo "=== C. czego regula nie ma prawa zgubic ===\n";

$zmienna = "wp_schedule_event( \$kiedy, \$jak_czesto, \$nazwa_haka );";
r121_ok(
	array() === MP_AU_A121_Cykl_Zycia::haki_z_planowania( 'wp_schedule_event', $zmienna ),
	'C1: hak podany zmienna nie jest zgadywany — lepiej cisza niz zmyslona nazwa',
	'wyszlo: ' . implode( ', ', MP_AU_A121_Cykl_Zycia::haki_z_planowania( 'wp_schedule_event', $zmienna ) )
);

$dwa = "wp_schedule_event( time(), 'daily', 'hak_pierwszy' );\n"
	. "wp_schedule_event( time(), 'twicedaily', 'hak_drugi' );";
$haki_d = MP_AU_A121_Cykl_Zycia::haki_z_planowania( 'wp_schedule_event', $dwa );

r121_ok(
	in_array( 'hak_pierwszy', $haki_d, true ) && in_array( 'hak_drugi', $haki_d, true ),
	'C2: KONTR-ASERCJA — dwa wywolania daja dwa haki, regula nie zatrzymuje sie na pierwszym',
	'wyszlo: ' . implode( ', ', $haki_d )
);

echo "\n----- PASS: {$pass} / FAIL: {$fail} -----\n";
echo 0 === $fail ? "VERDICT_ALL_PASS\n" : "VERDICT_HAS_FAILURES\n";
