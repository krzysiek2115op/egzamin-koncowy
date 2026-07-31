# Egzamin końcowy — automatyzacja zapytań ofertowych (WordPress / WooCommerce)

Projekt składa się z **3 wtyczek** i **3 baz danych** (zestawów tabel MySQL),
które razem tworzą spójny proces obsługi zapytań ofertowych z witryny WordPress.

## Cel biznesowy

Skrócenie czasu obsługi zapytań ofertowych. System ma automatycznie:

1. **kwalifikować lead-a** z formularza,
2. **tworzyć kartę klienta**,
3. **dobierać właściwy wariant cenowy**,
4. **generować ofertę PDF**,
5. **kierować zadanie do właściwego handlowca**.

Efekt: spójny przepływ **formularz → oferta**, bez ręcznego przepisywania
danych między formularzem, WooCommerce i pocztą.

## Wtyczki

| # | Wtyczka | Branch | Opis |
|---|---------|--------|------|
| 1 | `mp-lead-intake` | `mp-lead-intake` | Przyjęcie i kwalifikacja lead-a z formularza |
| 2 | `mp-offer-builder` | `mp-offer-builder` | Kalkulacja cenowa, integracja WooCommerce, oferty PDF |
| 3 | `mp-sales-workflow` | `mp-sales-workflow` | Statusy procesu, handlowiec, powiadomienia, follow-up, dashboard |

## Struktura repozytorium

```
mp-lead-intake/        wtyczka 1 (BD-3)
mp-offer-builder/      wtyczka 2 (BD-2), z vendor/ — dompdf
mp-sales-workflow/     wtyczka 3 (BD-1)
paczka-klienta/        materiały dla klienta, osobny komplet na wtyczkę
  ├─ mp-lead-intake/materialy/
  ├─ mp-offer-builder/materialy/
  └─ mp-sales-workflow/materialy/
tools/                 narzędzia deweloperskie (m.in. środowisko testowe)
.phpcs.xml.dist        wspólne reguły PHPCS/WPCS dla trzech wtyczek
```

Każda wtyczka mieszka we własnym katalogu w korzeniu repo (zgodnie z konwencją
wtyczek WordPress). Prace nad każdą wtyczką prowadzone są na dedykowanym branchu,
a gałąź `main` zbiera komplet — trzy wtyczki naraz.

## Workflow

- Każda wtyczka ma dedykowany branch (tabela wyżej). Branche powstały jako
  **w pełni odizolowane** — własny `composer.json` i `.phpcs.xml.dist`, bez
  dziedziczenia kodu między nimi. Dzięki temu kod trzech wtyczek nie kolidował
  ze sobą przy scalaniu do `main` ani w jednym pliku.
- `main` zbiera komplet. Pliki konfiguracyjne korzenia są tam **sumą** trzech
  wersji: `.phpcs.xml.dist` skanuje trzy katalogi i zna trzy text domains,
  `composer.json` opisuje wspólne narzędzia deweloperskie.
- Narzędzie audytujące mieszka na osobnym branchu `audyt-projektu` — nie jest
  częścią dostawy dla klienta.
- Commity powstają automatycznie przy każdym większym kroku.

## CI

![MP Lead Intake CI](https://github.com/krzysiek2115op/egzamin-koncowy/actions/workflows/mp-lead-intake-ci.yml/badge.svg?branch=mp-lead-intake)

Przy każdym push/PR na branch `mp-lead-intake` GitHub Actions
(`.github/workflows/mp-lead-intake-ci.yml`) uruchamia na PHP 7.4 i 8.3:

1. `php -l` — składnia wszystkich plików wtyczki,
2. PHPCS/WPCS wg reguł z korzenia repo (`.phpcs.xml.dist`),
3. `tests/process-harness/run-process.php` — 7 scenariuszy + niezmienniki procesu.

Zależności narzędzi dev (PHPCS/WPCS) są w `composer.json`/`composer.lock` w korzeniu
repo — wspólne dla wszystkich 3 wtyczek/branchy, nie duplikowane per plugin.

## Audyt projektu — narzędzie własne

Poza samymi wtyczkami repozytorium zawiera **narzędzie audytujące cały projekt
naraz**: trzy wtyczki i trzy bazy danych. Nie jest wtyczką WordPress i nie trafia
do klienta, więc żyje na osobnej gałęzi **`audyt-projektu`**, w katalogu `audyt/`.

### Po co powstało

Audyt końcowy z 29.07.2026 wykazał **8 błędów krytycznych** w kodzie, który
przechodził komplet testów końcowych. To nie był przypadek: test potwierdza to,
co autorowi przyszło do głowy sprawdzić, i nic ponadto. Narzędzie powstało po to,
żeby szukać rzeczy, o których nikt nie pomyślał — i żeby dało się to powtórzyć
przy każdej kolejnej zmianie, zamiast czytać 20 tysięcy linii od nowa.

### Jak działa

**37 par „agent + krytyk"** w dwóch działach:

| Dział | Rola |
|---|---|
| 1 — Audyt (26 par) | szuka problemów; celowo nadgorliwy, woli zgłosić za dużo niż przeoczyć |
| 2 — Re-audyt (11 par) | weryfikuje **każde** zgłoszenie drugą metodą, odsiewa fałszywe alarmy, ocenia sam werdykt |

Agent zbiera dowody, krytyk je ocenia. Ustalenie, którego Dział 2 nie potwierdzi
niezależnie, nie trafia do raportu jako fakt — dostaje status hipotezy razem
z uzasadnieniem obu stron. Narzędzie wystawia `git worktree` trzech gałęzi
i audytuje **czubki w repozytorium**, a nie to, co akurat leży na dysku.

### Trzy głębokości

| Poziom | Co dochodzi | Czas na tym repo |
|---|---|---|
| `szybki` | 22 pary analizy statycznej | ~1 s |
| `pelny` | `php -l`, PHPCS/WPCS, archeologia gitowa, powtórzony przebieg Działu 1 | ~90 s |
| `gleboki` | ocena modelu w parach 1.25, 1.26, 2.9 i 2.11 | ~45 min |

```sh
php audyt/bin/audyt.php --repo=/sciezka/do/repo --glebokosc=pelny
```

Kod wyjścia `1` przy ustaleniach krytycznych — nadaje się do CI. **Pominięcie pary
nie jest jej zaliczeniem:** werdykt po skróconym przebiegu dostaje dopisek „audyt
skrócony", żeby „GO" nie czytało się identycznie w obu przypadkach.

### Rejestr znanych błędów

`audyt/rejestr/znane-bledy.json` — **29 błędów, które w tym projekcie naprawdę
wystąpiły**, każdy z klasą pomyłki, dowodem z kodu, skutkiem dla użytkownika
i wskazaniem testu regresji (25 z 29 ma taki test; pozostałe 4 to pozycje otwarte
i narzędziowe, wymienione w raporcie z nazwy). Para 1.15 sprawdza, czy każdy wpis
nadal ma pokrycie — a para 2.6, czy test przyszedł razem z naprawą.

### Wynik

Ostatni przebieg (`--glebokosc=pelny`, 33 z 37 par): **WERDYKT GO** — zero ustaleń
krytycznych, średnich i drobnych, 15 obserwacji.

Uczciwa uwaga o ograniczeniu narzędzia, wynikająca z pomiaru na dwóch pełnych
przebiegach głębokich: pary **1.25** i **1.26** pytają model, więc każdy przebieg
próbkuje inny wycinek kodu (43 i 55 ustaleń, wspólnych 17). Są **generatorem
hipotez**, nie listą defektów — każde ich zgłoszenie było weryfikowane ręcznie.
Bramką odbioru są pary deterministyczne, których wynik był identyczny w każdym
przebiegu.

Raporty z kolejnych przebiegów leżą w `audyt/raport-*.txt` na gałęzi
`audyt-projektu`, a szczegółowy opis narzędzia — w `audyt/README.md`.

## Licencja

Cała automatyzacja — wszystkie trzy wtyczki wraz z materiałami klienckimi
(diagramy, schematy baz danych, instrukcje) — jest wydana na licencji
**GNU General Public License v2.0 lub późniejszej** (GPL-2.0-or-later).

Pełny tekst licencji: plik [LICENSE](LICENSE) w korzeniu repozytorium oraz
kopia w katalogu każdej wtyczki. Wersja online:
<https://www.gnu.org/licenses/gpl-2.0.html>.

Copyright (C) 2026 krzysiek2115op

Ten program jest wolnym oprogramowaniem: możesz go rozprowadzać dalej i/lub
modyfikować na warunkach GNU GPL wydanej przez Free Software Foundation —
w wersji 2 licencji lub (według twojego wyboru) dowolnej późniejszej.
Program rozpowszechniany jest w nadziei, że będzie użyteczny, ale **BEZ
JAKIEJKOLWIEK GWARANCJI**, nawet domyślnej gwarancji PRZYDATNOŚCI HANDLOWEJ
albo PRZYDATNOŚCI DO OKREŚLONYCH ZASTOSOWAŃ. Szczegóły w treści licencji.

GPL-2.0 jest zgodna z licencją samego WordPressa i WooCommerce, więc wtyczki
można rozpowszechniać razem z nimi bez dodatkowych warunków.

### Biblioteki zewnętrzne dołączone do wtyczki 2

Wtyczka `mp-offer-builder` zawiera w katalogu `vendor/` biblioteki potrzebne
do generowania PDF. Każda zachowuje własną licencję:

| Biblioteka | Licencja |
|---|---|
| `dompdf/dompdf` | LGPL-2.1 |
| `dompdf/php-font-lib` | LGPL-2.1-or-later |
| `dompdf/php-svg-lib` | LGPL-3.0-or-later |
| `masterminds/html5` | MIT |
| `sabberworm/php-css-parser` | MIT |
| `thecodingmachine/safe` | MIT |

**Dlaczego „lub późniejsza" ma tu znaczenie:** `php-svg-lib` jest na LGPL-3.0,
która jest zgodna z GPL-3.0, ale nie z samą GPL-2.0. Ponieważ wtyczki są wydane
jako GPL-2.0-**or-later**, odbiorca może przyjąć warunki GPL-3.0 i wtedy całość
jest spójna prawnie. Gdyby licencja była „GPL-2.0 only", tej biblioteki nie dałoby
się dołączyć zgodnie z prawem.

## Audyt projektu — narzędzie własne

Poza samymi wtyczkami repozytorium zawiera **narzędzie audytujące cały projekt
naraz**: trzy wtyczki i trzy bazy danych. Nie jest wtyczką WordPress i nie trafia
do klienta, więc żyje na osobnej gałęzi **`audyt-projektu`**, w katalogu `audyt/`.

### Po co powstało

Audyt końcowy z 29.07.2026 wykazał **8 błędów krytycznych** w kodzie, który
przechodził komplet testów końcowych. To nie był przypadek: test potwierdza to,
co autorowi przyszło do głowy sprawdzić, i nic ponadto. Narzędzie powstało po to,
żeby szukać rzeczy, o których nikt nie pomyślał — i żeby dało się to powtórzyć
przy każdej kolejnej zmianie, zamiast czytać 20 tysięcy linii od nowa.

### Jak działa

**37 par „agent + krytyk"** w dwóch działach:

| Dział | Rola |
|---|---|
| 1 — Audyt (26 par) | szuka problemów; celowo nadgorliwy, woli zgłosić za dużo niż przeoczyć |
| 2 — Re-audyt (11 par) | weryfikuje **każde** zgłoszenie drugą metodą, odsiewa fałszywe alarmy, ocenia sam werdykt |

Agent zbiera dowody, krytyk je ocenia. Ustalenie, którego Dział 2 nie potwierdzi
niezależnie, nie trafia do raportu jako fakt — dostaje status hipotezy razem
z uzasadnieniem obu stron. Narzędzie wystawia `git worktree` trzech gałęzi
i audytuje **czubki w repozytorium**, a nie to, co akurat leży na dysku.

### Trzy głębokości

| Poziom | Co dochodzi | Czas na tym repo |
|---|---|---|
| `szybki` | 22 pary analizy statycznej | ~1 s |
| `pelny` | `php -l`, PHPCS/WPCS, archeologia gitowa, powtórzony przebieg Działu 1 | ~90 s |
| `gleboki` | ocena modelu w parach 1.25, 1.26, 2.9 i 2.11 | ~45 min |

```sh
php audyt/bin/audyt.php --repo=/sciezka/do/repo --glebokosc=pelny
```

Kod wyjścia `1` przy ustaleniach krytycznych — nadaje się do CI. **Pominięcie pary
nie jest jej zaliczeniem:** werdykt po skróconym przebiegu dostaje dopisek „audyt
skrócony", żeby „GO" nie czytało się identycznie w obu przypadkach.

### Rejestr znanych błędów

`audyt/rejestr/znane-bledy.json` — **29 błędów, które w tym projekcie naprawdę
wystąpiły**, każdy z klasą pomyłki, dowodem z kodu, skutkiem dla użytkownika
i wskazaniem testu regresji (25 z 29 ma taki test; pozostałe 4 to pozycje otwarte
i narzędziowe, wymienione w raporcie z nazwy). Para 1.15 sprawdza, czy każdy wpis
nadal ma pokrycie — a para 2.6, czy test przyszedł razem z naprawą.

### Wynik

Ostatni przebieg (`--glebokosc=pelny`, 33 z 37 par): **WERDYKT GO** — zero ustaleń
krytycznych, średnich i drobnych, 15 obserwacji.

Uczciwa uwaga o ograniczeniu narzędzia, wynikająca z pomiaru na dwóch pełnych
przebiegach głębokich: pary **1.25** i **1.26** pytają model, więc każdy przebieg
próbkuje inny wycinek kodu (43 i 55 ustaleń, wspólnych 17). Są **generatorem
hipotez**, nie listą defektów — każde ich zgłoszenie było weryfikowane ręcznie.
Bramką odbioru są pary deterministyczne, których wynik był identyczny w każdym
przebiegu.

Raporty z kolejnych przebiegów leżą w `audyt/raport-*.txt` na gałęzi
`audyt-projektu`, a szczegółowy opis narzędzia — w `audyt/README.md`.
