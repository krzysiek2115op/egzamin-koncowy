=== MP Offer Builder ===
Contributors: krzysiek2115op
Tags: oferty, pdf, woocommerce, cennik
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Kalkulacja cenowa, integracja z WooCommerce, generowanie ofert PDF.

== Description ==

Druga z trzech wtyczek procesu "formularz → oferta". Odbiera zakwalifikowanego
lead-a z MP Lead Intake, dobiera wariant cenowy na bazie cen WooCommerce,
generuje ofertę PDF wraz z numeracją i historią wersji.

== Changelog ==

= 1.0.1 =
* Testy na żywym WordPressie/WooCommerce (WordPress Playground) — naprawione
  5 problemów niewykrywalnych w testach jednostkowych ze stubami:
* [Krytyczny] Stawka VAT: pobierana przez WC_Tax::get_base_tax_rates()
  (deterministycznie z bazy sklepu) zamiast get_rates() (zależnego od sesji
  klienta) — wcześniej każda oferta padała z „brak stawki VAT".
* [Krytyczny] Domyślne szablony oferty (PL+EN) zakładane przy aktywacji —
  bez nich świeża instalacja nie mogła wygenerować żadnej oferty.
* Kolumna „Akcje" w liście ofert (Edytuj / Pobierz PDF) — naprawiona
  widoczność metody renderującej.
* Ekran edycji wczytuje teraz istniejące pozycje oferty.
* Usunięty komunikat deprecation na PHP 8.3.
* Wszystkie 12 scenariuszy odbioru — PASS na żywym WooCommerce (docs/TESTY.md).

= 1.0.0 =
* Pipeline 11 działów kompletny: kontrakt/uprawnienia, integracja WooCommerce
  (ceny, stawki VAT), rabaty, mechanizm VAT (krajowy/odwrotne obciążenie/poza
  zakresem), szablon i treść oferty, numeracja i wersjonowanie, render PDF
  (Dompdf), zapis transakcyjny, odpowiedź i przekazanie zdarzenia.
* Panel wp-admin (lista ofert, budowa oferty, wyszukiwanie produktów) i
  chroniony endpoint pobierania PDF (nonce + capability + właściciel).
* Automatyczny szkic oferty z leada Pluginu 1 (hook `mp_lead_created`).
* Pełny audyt bezpieczeństwa i jakości (14 subagentów + dwa niezależne
  re-audyty), wszystkie ustalenia Critical/High/Medium naprawione: IDOR w
  3 miejscach, blokada optymistyczna przed nadpisaniem współbieżnej zmiany,
  ochrona przed duplikacją pozycji przy korekcie, kontrola wyników
  zapisu/kasowania w bazie, bezpieczne nazwy plików PDF, siatka bezpieczeństwa
  na twarde fatale PHP.
* Harness: 98/98 scenariuszy PASS. PHPCS (WPCS): 0 błędów/ostrzeżeń.

= 0.1.0 =
* Szkielet wtyczki — branch utworzony, architektura w trakcie ustalania.
