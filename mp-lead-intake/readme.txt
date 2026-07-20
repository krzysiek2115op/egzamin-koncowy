=== MP Lead Intake ===
Contributors: krzysiek2115op
Tags: leads, woocommerce, oferty, formularz
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Przyjęcie i kwalifikacja lead-a z formularza ofertowego WordPress.

== Description ==

Pierwsza z trzech wtyczek procesu "formularz → oferta". Odpowiada za odbiór
zgłoszenia z formularza, wstępną kwalifikację lead-a i zapis do dedykowanej bazy.

== Changelog ==

= 0.3.0 =
* Krok 2: rusztowanie pipeline (11 działów). Klasy: Result, Context (JSON),
  kontrakty Agent/Krytyk, Quality Gate, Department, Pipeline, Logger, Factory.
* Agenci/krytycy jako zaślepki (logika i dokumentacja w kroku 3).

= 0.2.1 =
* Leady: kolumna deleted_at (soft delete — archiwizacja zamiast kasowania).
* Klucz obcy offers.lead_id -> leads.id (ON DELETE RESTRICT). activity_log bez FK (audyt).
* Tabele jawnie ENGINE=InnoDB (transakcje + klucze obce).

= 0.2.0 =
* Baza danych BD-3: tabele wp_mp_leads, wp_mp_offers, wp_mp_activity_log (dbDelta).
* Instalacja tabel przy aktywacji, migracja wg wersji schematu, usuwanie przy deinstalacji.

= 0.1.0 =
* Szkielet wtyczki: nagłówek, stałe, hooki aktywacji/deaktywacji, bootstrap.
