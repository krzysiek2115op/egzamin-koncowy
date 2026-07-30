<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 1 — AUDYT CAŁEGO PROJEKTU.
Jeden plik na dział (zasada projektu, Golden Rule #2).

ŹRÓDŁA OFICJALNE — pobrane i zacytowane WIERNIE:
1. add_action() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/functions/add_action/
   Pobrano: 2026-07-30.
2. Statements That Cause an Implicit Commit — MySQL 8.0 Reference Manual.
   URL:     https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html
   Pobrano: 2026-07-30.
3. wpdb::prepare() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/classes/wpdb/prepare/
   Pobrano: 2026-07-30.

ŹRÓDŁA UZUPEŁNIAJĄCE (odesłania, bez cytatu — pełne kopie leżą w docs/ wtyczek):
4. Plugin Security — WordPress Plugin Handbook.
   https://developer.wordpress.org/plugins/security/
5. Privacy (RODO/GDPR) — WordPress Plugin Handbook.
   https://developer.wordpress.org/plugins/privacy/
6. WordPress Coding Standards (WPCS).
   https://github.com/WordPress/WordPress-Coding-Standards
7. CWE-89 (SQL Injection), CWE-639 (IDOR).
   https://cwe.mitre.org/data/definitions/89.html
   https://cwe.mitre.org/data/definitions/639.html

DRUGIE ŹRÓDŁO, RÓWNIE WAŻNE: własny rejestr błędów projektu
(`audyt/rejestr/znane-bledy.json`). Każdy wpis to błąd, który REALNIE wystąpił
w tym projekcie, wraz z dowodem i testem, który go wykrywa. Dla audytu jest to
materiał mocniejszy niż dokumentacja ogólna — opisuje pomyłki, które ten
konkretny zespół już popełnił, więc może je popełnić ponownie.
-->

# Dział 1 — audyt: dokumentacja źródłowa

## Po co ten dział istnieje

Audyt z 29.07.2026 znalazł **8 błędów krytycznych**, w tym dwa, które przez
miesiąc przechodziły przez komplet testów: martwy endpoint pobierania oferty
i wydawanie klientowi cudzego dokumentu PDF. Wspólna cecha wszystkich ośmiu:
**testy przechodziły, bo kodowały to samo założenie co kod**.

Dział 1 nie ma powtarzać tamtej pracy ręcznie. Ma **zamienić każdy znaleziony
błąd w regułę**, która wykryje go natychmiast, gdyby wrócił — i szukać tej samej
KLASY pomyłek w miejscach, których jeszcze nikt nie sprawdzał.

## Zasada nadrzędna działu: nigdy nie udawaj sprawdzenia

Kontrola, której nie dało się wykonać (brak narzędzia, brak środowiska, brak
modelu), zgłasza **NIEOCENIONE**, nigdy PASS. Fałszywe „zielone" jest gorsze niż
brak wyniku, bo zamyka temat. Ta zasada wynika wprost z historii projektu:
punkt 8 testów wtyczki 3 przechodził FAŁSZYWIE, bo strażnik zwracał zera,
a test właśnie zer oczekiwał.

---

## Źródło 1 — priorytety haków (cytat wierny)

> „Used to specify the order in which the functions associated with a particular
> action are executed. Lower numbers correspond with earlier execution, and
> functions with the same priority are executed in the order in which they were
> added to the action."

**Dlaczego to jest w dokumentacji audytu.** Z cytatu wynika rzecz, która nie
jest w nim napisana wprost, a kosztowała nas błąd krytyczny: skoro niższy numer
= wcześniejsze wykonanie, to callback **dodany w trakcie** wykonywania haka na
priorytecie NIŻSZYM niż aktualnie przetwarzany **nie zostanie w tym przebiegu
wywołany** — jego moment już minął. A `init` odpala się raz na żądanie.

Realny przypadek (wtyczka 3, naprawiony 29.07): `mp-sales-workflow.php` wieszał
`boot()` na `init` z priorytetem 10, a `boot()` w środku dopinał `maybe_serve`
na `init` z priorytetem **5**. Handler podpisanego linku do oferty **nie wykonał
się nigdy**. Klient dostawał maila, klikał i trafiał na zwykłą stronę.

→ Para **A1.7 / K1.7** („rejestracja haków") sprawdza dokładnie ten wzorzec.

## Źródło 2 — niejawny COMMIT (cytat wierny)

> „The statements listed in this section (and any synonyms for them) implicitly
> end any transaction active in the current session, as if you had done a
> `COMMIT` before executing the statement."

> „Transactions cannot be nested. This is a consequence of the implicit commit
> performed for any current transaction when you issue a `START TRANSACTION`
> statement or one of its synonyms."

**Dlaczego to jest w dokumentacji audytu.** Wtyczka 1 wystawiała
`do_action('mp_lead_created')` **wewnątrz otwartej transakcji**. Wtyczka 3,
reagując na to zdarzenie, otwierała własną transakcję — czyli, wprost wg cytatu,
robiła COMMIT transakcji wtyczki 1. Gwarancja „awaria działu 8/9 wycofa też
leada" przestawała obowiązywać w momencie zainstalowania wtyczki 3, i nikt nie
dostawał o tym żadnego sygnału.

→ Para **A1.10 / K1.10** („granice transakcji") szuka każdego `do_action`
wykonywanego między `START TRANSACTION` a `COMMIT`.

## Źródło 3 — przygotowywanie zapytań (cytat wierny)

> „Prepares a SQL query for safe execution."

> „All placeholders MUST be left unquoted in the query string."

> „A corresponding argument MUST be passed for each placeholder."

Oraz o identyfikatorach: symbol `%i` wprowadzono w wersji 6.2.0 „for identifiers,
e.g. table or field names" — z czego wynika, że `%s`/`%d` do nazw tabel i kolumn
się nie nadają.

**Dlaczego to jest w dokumentacji audytu.** `prepare()` chroni przed
wstrzyknięciem, ale **nie chroni przed błędną logiką warunku**. Realny przypadek
(naprawiony 29.07): zapytanie było poprawnie przygotowane, a mimo to wydawało
klientowi cudzy dokument, bo warunek brzmiał
`WHERE request_id = %s OR id = %d` i ten sam uchwyt (UUID) trafiał w oba
symbole — rzutowanie `(int) '120f3e8a…'` daje `120`.

→ Para **A1.8 / K1.8** („wzorce SQL") sprawdza i przygotowanie, i kształt
warunku: ten sam uchwyt podstawiony pod dwa różne typy w jednym `WHERE`
z alternatywą to sygnał alarmowy niezależny od `prepare()`.

---

## Pary agent + krytyk w Dziale 1

Każda para pilnuje **jednej właściwości**. Gdy para zgłasza problem, od razu
wiadomo czego dotyczy — dlatego jest ich dużo, a nie kilka wielozadaniowych.

| Para | Agent zbiera | Krytyk rozstrzyga | Skąd wiemy, że to potrzebne |
|---|---|---|---|
| 1.1 | inwentarz: pliki, klasy, tabele, haki, endpointy | czy pokrycie jest pełne (żaden plik nie pominięty) | audyt bez inwentarza nie wie, czego nie sprawdził |
| 1.2 | `php -l` każdego pliku | zero błędów składni | podstawa |
| 1.3 | PHPCS/WPCS per wtyczka | zero błędów; ostrzeżenia raportowane | 29.07 mój własny plik reguł miał złą domenę tekstową i pokazywał 16 fałszywych błędów |
| 1.4 | nazwy klas, tabel, opcji, haków cron, uprawnień, slugów menu, akcji AJAX | przecięcia między wtyczkami puste | trzy wtyczki w jednej instalacji WP |
| 1.5 | kolumny użyte w kodzie vs. kolumny w DDL | każda użyta kolumna istnieje | RODO nie działało, bo warunek filtrował po kolumnie `audience`, której nigdy nie było w schemacie |
| 1.6 | `do_action` i `add_action` po obu stronach granic | każde zdarzenie ma odbiorcę o zgodnym kontrakcie | integracja trzech wtyczek stoi wyłącznie na hakach |
| 1.7 | priorytety i miejsce rejestracji haków | callback nie jest dopinany na priorytecie już minionym | martwy link do oferty (źródło 1) |
| 1.8 | zapytania SQL: `prepare`, interpolacje, kształt `WHERE` | brak wstrzyknięć i brak alternatyw mieszających typy | wyciek cudzego PDF (źródło 3) |
| 1.9 | nonce, `current_user_can`, escaping, `realpath`, `readfile` | każdy punkt wejścia broniony | źródła 4 i 7 |
| 1.10 | pozycje `do_action` względem `START TRANSACTION`/`COMMIT` | żadne zdarzenie nie wychodzi w otwartej transakcji | niejawny COMMIT (źródło 2) |
| 1.11 | rejestracja eraserów/eksporterów, kolumny czyszczone przy anonimizacji | dane osobowe znikają po obu stronach granicy | dane usunięte we wtyczkach 2 i 3 wracały z wtyczki 1 |
| 1.12 | operacje na kwotach: grosze, `float`, `prices_include_tax` | zero arytmetyki zmiennoprzecinkowej na pieniądzach; ceny sprowadzone do jednej jednostki | oferta droższa o 23% przy sklepie z cenami brutto |
| 1.13 | `Version`, stała `MP_*_VERSION`, `Stable tag`, wpis w changelogu | wszystkie cztery zgodne | numer wersji żyje w czterech miejscach naraz |
| 1.14 | pliki `LICENSE`, nagłówki, licencje zależności w `vendor/` | licencja spójna i zgodna z zależnościami | `php-svg-lib` na LGPL-3.0 wymaga członu „or-later" |
| 1.15 | rejestr znanych błędów vs. istniejące testy | każdy dawny błąd ma test, który go wykrywa | wszystkie 8 błędów z 29.07 przechodziło przez testy |
| 1.16 | liczba zapytań przy 2 i przy 20 pozycjach | narzut liniowy, nie N+1 | mierzone, nie zgadywane |
| 1.17 | pliki `docs/`, źródła z URL i datą, 1 plik na dział | dokumentacja zgodna z zasadą projektu | Golden Rule #2 |

## Czego ten dział NIE potrafi

Reguła rozstrzyga to, co jest rozstrzygalne. Zdania w rodzaju „ten status kłamie
o tym, co się stało" albo „pusty numer oferty ośmiesza firmę przed klientem"
wymagają **sądu**, nie wzorca. Dlatego część krytyków tego działu jest
**krytykami modelowymi**: agent zbiera fakty w PHP i buduje dossier, a ocenę
wydaje model (`MP_AU_Model_Client`). Gdy modelu nie ma, taki krytyk zgłasza
NIEOCENIONE — patrz zasada nadrzędna działu.
