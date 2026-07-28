=== MP Sales Workflow ===
Contributors: krzysiek2115op
Tags: sprzedaz, crm, workflow, follow-up
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
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

= 1.0.0 =

Pierwsze wydanie produkcyjne. Schemat bazy: 0.3.0.

Proces (pipeline 9 dzialow, pary Agent+Krytyk 1:1, bramka jakosci po kazdym dziale):

* Dzial 1 — brama zdarzen i kontrakt koperty; powtarzalne klucze zdarzen (idempotencja).
* Dzial 2 — jeden strzal odczytu BD-1 (migawka: role, zespol, lead, oferta).
* Dzial 3 — uprawnienia i zakres roli (WLASNE / ZESPOL / WSZYSTKIE).
* Dzial 4 — przypisanie handlowca.
* Dzial 5 — maszyna statusow procesu.
* Dzial 6 — zadania follow-up d+3 / d+7.
* Dzial 7 — powiadomienia e-mail (szablony wersjonowane, PL/EN).
* Dzial 8 — zapis jedna transakcja + dziennik aktywnosci.
* Dzial 9 — wyjscie i uruchomienie kolejki poczty po COMMIT.

Warstwa techniczna: punkt AJAX, cron zadan i retencji, kolejka e-mail z ponowieniami,
haki wejsciowe z MP Lead Intake i MP Offer Builder, pulpit z dziennikiem.

Utwardzenie przed produkcja:

* Macierz pochodzenia zdarzen — typ zdarzenia zwiazany z kanalem, ktorym przyszlo;
  domknieta domyslnie (nieznany typ nie ma zadnego dozwolonego zrodla).
* Sciezka HTTP wylacznie dla zalogowanych; jedyny publiczny punkt to podpisany
  link do oferty (HMAC-SHA256, waznosc 14 dni, limit prob).
* Autoryzacja obiektowa: siegniecie po cudzy proces zwraca 404, nie 403.
* Szesc uprawnien zamiast jednego; manager widzi ZESPOL, nie cala firme.
* Adres odbiorcy i sciezka dokumentu czytane z bazy — koperta niesie same
  identyfikatory.
* Ochrona przed wstrzykiwaniem naglowkow e-mail; bezpiecznik 200 wiadomosci/h.
* Atomowe klamrowanie zadan crona; wartownik statusu wewnatrz transakcji zapisu.
* Slownik kodow MP3-Exxx — odpowiedz nie ujawnia regul ani zapytan; dziennik
  techniczny do error_log, nie do bazy.
* RODO: anonimizacja bez kasowania historii, dziennik bez adresu i bez IP.
* Deinstalacja kasuje dane tylko po wlaczeniu opcji `mp_sw_delete_data`.
* Checklista wdrozenia produkcyjnego: `docs/WDROZENIE.md`.

= 0.1.0 =
* Szkielet wtyczki (nagłówek, stałe, deinstalacja).
