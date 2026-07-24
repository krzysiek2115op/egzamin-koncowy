=== MP Offer Builder ===
Contributors: krzysiek2115op
Tags: oferty, pdf, woocommerce, cennik
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Kalkulacja cenowa, integracja z WooCommerce, generowanie ofert PDF.

== Description ==

Druga z trzech wtyczek procesu "formularz → oferta". Odbiera zakwalifikowanego
lead-a z MP Lead Intake, dobiera wariant cenowy na bazie cen WooCommerce,
generuje ofertę PDF wraz z numeracją i historią wersji.

== Changelog ==

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
