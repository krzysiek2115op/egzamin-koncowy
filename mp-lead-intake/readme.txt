=== MP Lead Intake ===
Contributors: krzysiek2115op
Tags: leads, woocommerce, oferty, formularz
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Przyjęcie i kwalifikacja lead-a z formularza ofertowego WordPress.

== Description ==

Pierwsza z trzech wtyczek procesu "formularz → oferta". Odpowiada za odbiór
zgłoszenia z formularza, wstępną kwalifikację lead-a i zapis do dedykowanej bazy.

== Changelog ==

= 0.5.0 =
* Krok 3 — Dział 2 (Walidacja wstępna): agenci 2.1/2.2/2.3 (wymagane pola,
  normalizacja sanitize_*, formaty is_email) + krytycy + QA.
* Uniwersalny MP_Flag_Critic (weryfikacja flag typu required_ok/form_valid).
* docs/dzial-02/ = oficjalna dokumentacja WordPress (is_email, sanitize_text_field, sanitize_email).

= 0.4.1 =
* docs/ przebudowane wg zasady: docs = FAKTYCZNA oficjalna dokumentacja źródeł działu
  (pobrane wiernie z URL + data), którą "czytają" agenci/krytycy. Zadania agentów są w kodzie.
* Dział 1: docs/dzial-01/ = oficjalna dokumentacja WordPress wpdb (get_results, prepare).
* Usunięto błędny plik opisujący zadania (dzial-01-pobranie-danych.md).

= 0.4.0 =
* Krok 3 — Dział 1 (Pobranie danych z BD-3): realni agenci 1.1/1.2/1.3 + krytycy + QA.
* Bazowe klasy Agent/Krytyk, uniwersalny QA Krytyk (MP_Accept_Critic).
* Metody odczytu w warstwie DB (get_leads_by_nip, get_offers/activity_by_lead_ids).
* Dokumentacja działu: docs/dzial-01-pobranie-danych.md.

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
