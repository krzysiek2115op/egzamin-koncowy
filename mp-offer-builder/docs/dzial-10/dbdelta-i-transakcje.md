<!--
ŹRÓDŁA OFICJALNE:
1. dbDelta() — WordPress Plugin Handbook, "Creating Tables with Plugins".
   URL:     https://developer.wordpress.org/plugins/creating-tables-with-plugins/
   Pobrano: 2026-07-24.
2. COMMIT / ROLLBACK — MySQL 8.4 Reference Manual.
   URL:     https://dev.mysql.com/doc/refman/8.4/en/commit.html
   Pobrano: 2026-07-24.
Dotyczy: Dział 10 — Agent 10.1 "plan" (zgodność-z-DDL, patrz też schemat BD-2
w includes/db/class-mp-offer-builder-db.php, gdzie te reguły są już
zastosowane) i Agent 10.2 "transakcja" (START TRANSACTION/COMMIT/ROLLBACK).
-->

# dbDelta() i transakcje MySQL — dokumentacja źródłowa

## dbDelta() — wymogi formatowania SQL (cytaty)

"You must put each field on its own line in your SQL statement."

"You must have two spaces between the words PRIMARY KEY and the definition
of your primary key."

"You must use the key word KEY rather than its synonym INDEX and you must
include at least one KEY. KEY must be followed by a SINGLE SPACE then the
key name then a space then open parenthesis with the field name then
a closed parenthesis."

Dodatkowo (ta sama strona): bez apostrofów/backticków wokół nazw pól, typy
pól małymi literami, słowa kluczowe SQL (CREATE TABLE) wielkimi literami,
każde pole z parametrem długości musi go mieć jawnie podany (np. `int(11)`).

## Zastosowanie w tym dziale

Te reguły są WYMOGIEM SKŁADNIOWYM na etapie `MP_Offer_Builder_DB::install()`
(już zastosowane — dwie spacje po `PRIMARY KEY`, `KEY` zamiast `INDEX`, jedno
pole na linię, zero pustych linii) — Dział 10 sam NIE wywołuje `dbDelta()`
(to robi tylko aktywacja wtyczki), ale Agent 10.1 "plan" pilnuje ANALOGICZNEJ
zgodności po stronie DANYCH: typy/długości wstawianych wartości muszą mieścić
się w limitach kolumn zdefiniowanych tymi regułami (np. `varchar(191)` dla
`client_name`, `varchar(30)` dla `offer_number`) — inaczej MySQL obcina albo
odrzuca wiersz PRZY ZAPISIE (Agent 10.2), zamiast wcześniej i czytelnie
(Agent 10.1, przed otwarciem transakcji).

## COMMIT / ROLLBACK (cytaty)

"COMMIT commits the current transaction, making its changes permanent."

"ROLLBACK rolls back the current transaction, canceling its changes."

## Zastosowanie w tym dziale

Agent 10.2 "transakcja": `START TRANSACTION` → INSERT/UPDATE nagłówka +
INSERT pozycji + INSERT wersji + INSERT dziennika → `COMMIT` (zmiany trwałe,
dopiero WTEDY plik PDF dostaje nazwę docelową — patrz gate Działu 9). Każdy
błąd zapisu w trakcie → `ROLLBACK` (wszystkie zmiany tej transakcji cofnięte,
łącznie z nagłówkiem — nie istnieje oferta bez pozycji ani wersji, kryt.
"jeden-zapis"), a plik tymczasowy PDF jest kasowany (patrz Dział 9). Kolizja
konkretnie na indeksie `UNIQUE(offer_number, version)` (patrz
docs/dzial-08/mysql-unique-index.md) ma WŁASNĄ ścieżkę: RETRY lokalny
(nowy kandydat numeru/wersji + ponowny render PDF, maks. 2 podejścia),
zamiast zwykłego ROLLBACK-i-STOP.
