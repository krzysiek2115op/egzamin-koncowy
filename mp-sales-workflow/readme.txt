=== MP Sales Workflow ===
Contributors: krzysiek2115op
Tags: sprzedaz, crm, workflow, follow-up
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Przypisanie handlowca, statusy procesu, powiadomienia e-mail, zadania follow-up,
dashboard i dziennik aktywności.

== Description ==

Trzecia z trzech wtyczek procesu "formularz → oferta". Domyka proces: prowadzi
lead-a i wygenerowaną ofertę przez statusy sprzedażowe, pilnuje przypisania
handlowca, wysyła powiadomienia e-mail, zakłada automatyczne zadania follow-up
i udostępnia dashboard z dziennikiem aktywności.

Wtyczka jest konsumentem zdarzeń dwóch pozostałych modułów — nie przyjmuje
formularzy (to MP Lead Intake) i nie buduje ofert (to MP Offer Builder).

== Installation ==

1. Wgraj katalog wtyczki do `wp-content/plugins/` (lub zainstaluj ZIP przez
   Wtyczki → Dodaj nową → Wyślij wtyczkę) i aktywuj.

Wdrozenie produkcyjne wymaga dodatkowo ustawien spoza wtyczki: stalych
`MP_HASH_PEPPER` i `MP_SW_LINK_KEY` w `wp-config.php`, systemowego crona,
rekordow SPF/DKIM/DMARC oraz blokady katalogu z ofertami PDF. Pelna checklista:
`docs/WDROZENIE.md`. Bez klucza `MP_SW_LINK_KEY` wtyczka celowo wstrzyma wysylke
powiadomien do klientow.

== Changelog ==

= 0.1.0 =
* Szkielet wtyczki (nagłówek, stałe, deinstalacja).
