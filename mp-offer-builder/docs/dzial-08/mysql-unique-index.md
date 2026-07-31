<!--
ŹRÓDŁO OFICJALNE — MySQL 8.4 Reference Manual, CREATE TABLE — indeksy UNIQUE.
URL:     https://dev.mysql.com/doc/refman/8.4/en/create-table.html
Pobrano: 2026-07-24.
Dotyczy: Dział 8 — Agent 8.1/8.2 (numer/wersja), krytyk QA "jedno-albo-drugie";
schemat UNIQUE KEY uq_offer_number_version (offer_number, version) w BD-2
(includes/db/class-mp-offer-builder-db.php).
-->

# UNIQUE index (indeks złożony) — dokumentacja źródłowa

## Treść (cytat)

"A UNIQUE index creates a constraint such that all values in the index must
be distinct. An error occurs if you try to add a new row with a key value
that matches an existing row. For all engines, a UNIQUE index permits
multiple NULL values for columns that can contain NULL. If you specify a
prefix value for a column in a UNIQUE index, the column values must be
unique within the prefix length."

## Znaczenie dla indeksu złożonego (offer_number, version)

Dla indeksu wielokolumnowego "wartości w indeksie" to KOMBINACJA wszystkich
kolumn — unikalna musi być PARA (offer_number, version), nie każda kolumna
osobno. Dwie oferty mogą mieć ten sam `offer_number` (różne wersje tej samej
oferty — korekty) i różne oferty mogą mieć `version = 1` — kolizję zgłasza
baza tylko przy DOKŁADNIE tej samej parze.

Dokumentacja wprost potwierdza też: NULL jest wyłączony z reguły unikalności
("permits multiple NULL values") — to podstawa decyzji z Kroku 2.5:
`offer_number` jest NULL-owalny dla draftów założonych automatycznie z leada
(patrz [[plugin2-architecture]]), więc wiele draftów bez numeru współistnieje
bez naruszenia tego indeksu.

## Zastosowanie w tym dziale

Dział 8 NIGDY sam nie zapisuje do bazy (to Dział 10) — oblicza tylko
KANDYDATA pary (offer_number, version), którą Dział 10 wstawi jedną
transakcją. Kolizja na tym indeksie (np. dwie równoległe oferty wyliczyły ten
sam kandydat numeru z tego samego, już nieaktualnego, `last_number`) wychodzi
dopiero PRZY ZAPISIE w Dziale 10 — stąd retry ('FAIL_RETRY: numer + 1,
ponowny render, maks. MAX_ATTEMPTS=5 podejść, kolejny numer liczony w pamięci) należy do Działu 10,
nie do Działu 8 (patrz docblock class-mp-ob-department-10.php). Dział 8
dostarcza tylko poprawną, spójną PARĘ kandydata — bramka jakości "jedno-albo-
drugie" pilnuje, że jest to ALBO nowy numer z wersją 1, ALBO ten sam numer
z podbitą wersją, nigdy inna kombinacja.
