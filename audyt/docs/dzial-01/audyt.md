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

## Pary agent + krytyk w Dziale 1 — komplet 26

Każda para pilnuje **jednej właściwości**. Gdy para zgłasza problem, od razu
wiadomo czego dotyczy — dlatego jest ich dużo, a nie kilka wielozadaniowych.

Kolumna **poziom** mówi, na jakiej głębokości przebiegu para w ogóle startuje:
`S` = szybki (sama analiza statyczna), `P` = pełny (+ narzędzia zewnętrzne),
`G` = głęboki (+ ocena modelu).

| Para | Poziom | Agent zbiera | Krytyk rozstrzyga | Skąd wiemy, że to potrzebne |
|---|---|---|---|---|
| 1.1 | S | inwentarz: pliki, klasy, tabele, haki, endpointy | czy pokrycie jest pełne | audyt bez inwentarza nie wie, czego nie sprawdził |
| 1.2 | P | `php -l` każdego pliku | zero błędów składni | podstawa |
| 1.3 | P | PHPCS/WPCS per wtyczka, ruleset składany na miejscu | zero błędów standardu | 29.07 mój własny plik reguł miał złą domenę tekstową i pokazał 16 fałszywych błędów (NARZ-F1) |
| 1.4 | S | nazwy klas, tabel, opcji, zadań cron, uprawnień, slugów, akcji AJAX | przecięcia między wtyczkami puste | trzy wtyczki w jednej instalacji WP |
| 1.5 | S | kolumny użyte w kodzie kontra kolumny w DDL | każda użyta kolumna istnieje | P3-K2: RODO nie działało, bo filtrowało po kolumnie `audience`, której nigdy nie było |
| 1.6 | S | `do_action` i `add_action` po obu stronach granic + sygnatury callbacków | każde zdarzenie ma odbiorcę o zgodnym kontrakcie | integracja trzech wtyczek stoi wyłącznie na hakach |
| 1.7 | S | priorytety i miejsce rejestracji haków | callback nie jest dopinany na priorytecie już minionym | P3-K1: martwy link do oferty (źródło 1) |
| 1.8 | S | zapytania SQL: `prepare`, interpolacje, kształt `WHERE` | brak wstrzyknięć i brak alternatyw mieszających typy | INT-K3: wyciek cudzego PDF (źródło 3) |
| 1.9 | S | nonce, `current_user_can`, `permission_callback`, `hash_equals`, `realpath` | każdy punkt wejścia broniony | źródła 4 i 7 |
| 1.10 | S | pozycje `do_action` względem `START TRANSACTION`/`COMMIT` | żadne zdarzenie nie wychodzi w otwartej transakcji | INT-K1: niejawny COMMIT (źródło 2) |
| 1.11 | S | eraser/exporter RODO, kolumny osobowe w DDL, sygnał „już zanonimizowany" | dane osobowe znikają po obu stronach granicy | P3-K2 i P3-K3 |
| 1.12 | S | `float` na kwotach, `get_price()` bez ustalenia jednostki, typy kolumn | zero arytmetyki zmiennoprzecinkowej; ceny w jednej jednostce | P2-K3: oferta droższa o 23% |
| 1.13 | S | `Version`, stała `MP_*_VERSION`, `Stable tag`, changelog | wszystkie cztery zgodne | numer wersji żyje w czterech miejscach naraz |
| 1.14 | S | `LICENSE`, nagłówki, licencje zależności z `vendor/*/*/composer.json` | licencja spójna i zgodna z zależnościami | `php-svg-lib` na LGPL-3.0 wymaga członu „or-later" |
| 1.15 | S | rejestr znanych błędów kontra istniejące testy | każdy dawny błąd ma test, który go wykrywa | wszystkie 8 błędów z 29.07 przechodziło przez testy |
| 1.16 | S | zapytania wewnątrz pętli (z pominięciem sterowania transakcją) | narzut liniowy, nie N+1 | oferta na 40 pozycji to normalne zamówienie hurtowe |
| 1.17 | S | pliki `docs/`, źródła z URL i datą, jeden plik na dział | dokumentacja zgodna z zasadą projektu | Golden Rule #2 |
| 1.18 | S | `UPDATE` bez wartownika statusu, brak podbicia `lock_version`, klucze bez `UNIQUE` | zapis sprawdza stan z chwili odczytu | P2-K1, P2-K2, P3-S1 |
| 1.19 | S | puste `catch`, wyciszenia `@`, zapis ścieżki przed potwierdzeniem zapisu pliku | porażka musi być widoczna | P2-S4: `pdf_path` do nieistniejącego pliku |
| 1.20 | S | domena tekstowa w funkcjach tłumaczących, `load_plugin_textdomain` | domena zgodna ze slugiem wtyczki | trzy wtyczki = trzy domeny; pomyłka nic nie wywala |
| 1.21 | S | aktywacja, deaktywacja, `uninstall`, cron, `remove_role`, `DROP TABLE` | wyłączenie wtyczki nie niszczy sąsiada | P1-K2: deaktywacja kasowała rolę współdzieloną |
| 1.22 | S | metody prywatne bez wywołań | kod bez wywołań albo zniknie, albo dostanie wywołanie | ślad po refaktorze myli następną osobę |
| 1.23 | S | stałe `STATUS_*` kontra literały w kodzie | status zawsze ze słownika | literówka w statusie nie wywala nic — rekord po prostu znika z obiegu |
| 1.24 | S | asercje w plikach testów: liczba, tautologie, oczekiwania zera | test musi umieć nie przejść | TEST-F1: punkt testów przechodził fałszywie, bo strażnik zwracał zera |
| 1.25 | G | kod działu + jego własna dokumentacja jako dossier dla modelu | czy kod robi to, co deklaruje | „ta cena jest liczona drugi raz" nie jest wzorcem — jest sądem |
| 1.26 | G | tematy maili, komunikaty błędów, etykiety | człowiek nie zobaczy pustego miejsca | P3-K3: pusty numer oferty w temacie maila do klienta |

## Trzy poziomy głębokości

Nie ma jednego dobrego czasu audytu. Przed commitem chcemy odpowiedzi w minutę,
przed wydaniem — kompletu, choćby trwał pół godziny. Dlatego para deklaruje
najniższy poziom, na którym ma sens:

| Poziom | Co robi | Ile trwa na tym repo |
|---|---|---|
| `szybki` | 22 pary analizy statycznej, zero uruchomień | ~1 s |
| `pełny` | + `php -l`, PHPCS/WPCS, archeologia gitowa, powtórzony przebieg | ~90 s |
| `głęboki` | + ocena modelu (1.25, 1.26, 2.9) — dziesiątki dossier | dziesiątki minut |

**Pominięcie pary nie jest jej zaliczeniem.** Bramka raportuje pominięcia
osobno, a werdykt niesie dopisek „audyt skrócony". Inaczej „GO" po przebiegu
szybkim czytałoby się identycznie jak „GO" po pełnym — a to są dwa różne zdania.

## Rejestr znanych błędów

`audyt/rejestr/znane-bledy.json` to drugie źródło tego działu, równie ważne jak
dokumentacja oficjalna. Każdy wpis opisuje błąd, który **realnie wystąpił w tym
projekcie**: klasę pomyłki, dowód z kodu, skutek dla użytkownika, test regresji
i parę audytu, która ma go dziś łapać automatycznie.

Dla audytu jest to materiał mocniejszy niż dokumentacja ogólna — opisuje pomyłki,
które ten konkretny zespół już popełnił, a więc może popełnić ponownie.

## Czego ten dział NIE potrafi

Reguła rozstrzyga to, co jest rozstrzygalne. Zdania w rodzaju „ten status kłamie
o tym, co się stało" wymagają **sądu**, nie wzorca. Dlatego pary 1.25 i 1.26 mają
**krytyków modelowych**: agent zbiera dossier w PHP, ocenę wydaje model. Gdy
modelu nie ma, taki krytyk zgłasza NIEOCENIONE — patrz zasada nadrzędna działu.

Ocena modelu ma **zawsze** status `prawdopodobne`. Podnieść ją może wyłącznie
Dział 2, sprawdzając ustalenie drugą drogą.
