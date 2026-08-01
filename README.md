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

| # | Wtyczka | Wersja | Baza | Opis |
|---|---------|--------|------|------|
| 1 | `mp-lead-intake` | 1.3.7 | BD-3 | Przyjęcie i kwalifikacja lead-a z formularza |
| 2 | `mp-offer-builder` | 1.3.7 | BD-2 | Kalkulacja cenowa, integracja WooCommerce, oferty PDF |
| 3 | `mp-sales-workflow` | 1.3.7 | BD-1 | Statusy procesu, handlowiec, powiadomienia, follow-up, dashboard |

Kolejność instalacji ma znaczenie: **1, potem 2, potem 3**. Wtyczka 2 nasłuchuje
zdarzenia z wtyczki 1, a wtyczka 3 — zdarzeń z obu poprzednich. Gotowe paczki
do wgrania są w [Releases](https://github.com/krzysiek2115op/egzamin-koncowy/releases).

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
.github/workflows/     CI — trzy wtyczki na PHP 7.4 i 8.3
.phpcs.xml.dist        wspólne reguły PHPCS/WPCS dla trzech wtyczek
composer.json          narzędzia deweloperskie (PHPCS/WPCS) — NIE zależności wtyczek
LICENSE                GPL-2.0-or-later
```

Każda wtyczka mieszka we własnym katalogu w korzeniu repo, zgodnie z konwencją
wtyczek WordPress. Powstawały na osobnych gałęziach (`mp-lead-intake`,
`mp-offer-builder`, `mp-sales-workflow`) i te gałęzie nadal istnieją jako zapis
historii — ale od scalenia **źródłem prawdy jest `main`** i to on zawiera komplet.

Narzędzie audytujące żyje osobno, na gałęzi `audyt-projektu`: nie jest wtyczką
WordPressa i nie trafia do klienta, więc świadomie nie ma go w `main`.

## Workflow

- Każda wtyczka ma dedykowany branch (`mp-lead-intake`, `mp-offer-builder`,
  `mp-sales-workflow`). Branche powstały jako
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

![CI](https://github.com/krzysiek2115op/egzamin-koncowy/actions/workflows/ci.yml/badge.svg?branch=main)

Przy każdym push/PR na `main` (oraz na gałęzie wtyczek) GitHub Actions
(`.github/workflows/ci.yml`) uruchamia na PHP 7.4 i 8.3:

1. `php -l` — składnia **wszystkich trzech wtyczek**, bez `vendor/`,
2. PHPCS/WPCS wg wspólnych reguł z korzenia repo (`.phpcs.xml.dist`),
3. `tests/process-harness/run-process.php` wtyczki 1 — 7 scenariuszy + niezmienniki,
4. `tests/process-harness/run-process.php` wtyczki 2 — 110 niezmienników procesu.

Testy wtyczki 3 oraz testy końcowe wtyczek 1 i 2 wymagają **żywego WordPressa
z WooCommerce** (`wp eval-file`), więc nie są częścią CI — uruchamia się je
w środowisku opisanym w [tools/test-env/README.md](tools/test-env/README.md).

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
z uzasadnieniem obu stron. Narzędzie wystawia `git worktree` i audytuje
**stan w repozytorium**, a nie to, co akurat leży na dysku — domyślnie trzy
gałęzie wtyczek, a z przełącznikiem `--ref=refs/heads/main` scalonego `main`,
czyli kod, który faktycznie idzie do wydania.

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

`audyt/rejestr/znane-bledy.json` — **38 błędów, które w tym projekcie naprawdę
wystąpiły**, każdy z klasą pomyłki, dowodem z kodu, skutkiem dla użytkownika
i wskazaniem testu regresji (35 z 38 ma taki test; pozostałe 3 to pozycje
otwarte i narzędziowe, wymienione w raporcie z nazwy). Para 1.15 sprawdza, czy
każdy wpis nadal ma pokrycie — a para 2.6, czy test przyszedł razem z naprawą.

### Wynik

Ostatni przebieg (`--glebokosc=pelny`, 33 z 37 par): **WERDYKT GO** — zero ustaleń
krytycznych, średnich i drobnych; 8 obserwacji, wszystkie z pary 2.7 („żadna para
nie otworzyła tego pliku" — narzędzia testowe poza zakresem reguł).

Uczciwa uwaga o ograniczeniu narzędzia, wynikająca z pomiaru na pełnych
przebiegach głębokich: pary **1.25** i **1.26** pytają model, więc każdy przebieg
próbkuje inny wycinek kodu (43 i 55 ustaleń, wspólnych 17). Są **generatorem
hipotez**, nie listą defektów — każde ich zgłoszenie wymaga ręcznej weryfikacji.
Bramką odbioru są pary deterministyczne, których wynik był identyczny w każdym
przebiegu.

Warto wiedzieć, do czego to prowadzi w praktyce. Ustalenia ze statusem
`prawdopodobne` z tej warstwy zostały początkowo uznane za szum wynikający
z owej niepowtarzalności — błędnie. Niepowtarzalne jest to, **czy** ustalenie się
pojawi, a nie to, czy jest prawdziwe. Przegląd tej warstwy przeprowadzony
31.07.2026 potwierdził testem sześć realnych defektów, w tym niepełną
anonimizację RODO i możliwość dwukrotnej wysyłki tej samej oferty. Wszystkie
zamknięte w wydaniu 1.3.3, a ostatnie cztery ustalenia z tej fali — w **1.3.4**
(01.08.2026): krytyk maszyny statusów, który sprawdzał wynik agenta zamiast jego
wejścia; dedup czytający brak danych jako potwierdzoną unikalność; komunikat
panelu brany z parametru adresu; kolumna zadeklarowana w schemacie, której kod
nigdy nie używał.

Wydanie **1.3.5** (01.08.2026) zamyka kolejnych siedem ustaleń, w tym dwa
o ciężarze prawnym: wtyczka 3 realizowała prawo do usunięcia danych, ale nie
prawo do ich wydania — żądanie „Eksportuj dane osobowe" pomijało ją w ciszy,
więc raport dla klienta wyglądał na kompletny; do tego jej teksty nie były
w ogóle przygotowywane do tłumaczenia. Reszta to odczyt archiwum firm mylący
brak wpisu z awarią zapytania, strażnik VIES pytający o obecność pola zamiast
o werdykt, dwie strefy czasowe w jednym wierszu bazy oraz dwa testy, które
zaliczały się także przy zepsutej funkcji.

Przy tej okazji wyszła rzecz o samym narzędziu i trzeba ją powiedzieć wprost:
para weryfikująca ustalenia sprawdzała ich miejsca funkcją rozpoznającą wyłącznie
pliki, więc **każde ustalenie wskazujące katalog było z góry odrzucane jako
fałszywy alarm**. Milczały przez to całe rodziny kontroli — między innymi ta od
RODO. Bezpiecznik na taki wypadek istnieje, ale odpala się powyżej 50%
nieodnalezionych miejsc, a odrzuceń było 42%: przeszło tuż pod progiem. Po
naprawie treść raportu urosła z 14 do 35 ustaleń przy tym samym kodzie. Raport
podaje teraz bilans (zgłoszone = odrzucone + w treści), żeby takiej różnicy nie
dało się już przeoczyć.

Wydanie **1.3.6** (01.08.2026) domyka falę ustaleń średniej wagi z tego samego
przebiegu. Najszerszą zmianą jest przyjmowanie numerów VAT z **całej Unii**:
formularz od początku pozwalał wybrać kraj, ale sprawdzanie żądało dziesięciu
cyfr, czyli reguły polskiej, więc firma z Niemiec czy Czech odbijała się od
komunikatu o błędnym numerze — poprawnie przepisanym z własnej faktury. Reszta
architektury była już wielokrajowa (VIES pytany osobno dla każdego kraju, klucz
niepowtarzalności `(kraj, numer)`, przydział handlowca po kraju); zamknięta była
sama bramka wejścia. Świadomie **nie** powstała tablica formatów 27 państw —
byłaby drugim, konkurencyjnym źródłem prawdy obok VIES, który i tak orzeka
ostatecznie. Subtelniejsza połowa naprawy dotyczyła czyszczenia numeru: w siedmiu
miejscach usuwało ono wszystko poza cyframi, więc numer niderlandzki czy irlandzki
tracił litery i do VIES szedł numer, którego nikt nie wpisał.

Poza tym: cena ujemna, która przy cenniku **brutto** trafiała na dokument
handlowy (kontrola stała za przeliczeniem, a to nie zachowuje znaku); podstawa
opodatkowania niepilnowana przy odwrotnym obciążeniu, eksporcie i zwolnieniu;
doba Białej listy MF liczona strefą ustawioną w panelu zamiast polską; dwa
komunikaty administratora mówiące o czynnościach, których nie było; oraz
znaczniki powiadomień wyszukiwane w tekście **po** podstawieniu zmiennych, przez
co klamry w nazwie firmy wstrzymywały wysyłkę i blokowały zmianę statusu.

Wniosek, który wart jest zapamiętania bardziej niż sam werdykt: **„GO" znaczy
„nie wróciło nic, co już znamy"** — nie „nie ma błędów". Deterministyczne pary
to siatka regresyjna zbudowana z historii pomyłek tego projektu; nie zastąpią
czytania kodu ze zrozumieniem. A jak pokazały 1.3.5 i 1.3.6 — potrafią też
milczeć albo krzyczeć z powodu własnej wady, więc i one bywają audytowane.
W 1.3.6 zawężono dwie reguły produkujące fałszywe alarmy: para cyklu życia
czytała z `wp_schedule_event()` **częstotliwość** zamiast nazwy zadania — żądała
więc sprzątnięcia haka „daily" i zarazem nie widziała haków prawdziwych, czyli
przeoczyłaby zadanie faktycznie niesprzątnięte; a para RODO żądała rozpoznania
anonimizacji od wtyczki, która kolumnę adresu **zeruje**, czyli nie zostawia nic,
co dałoby się wziąć za prawdziwy adres.

Raporty z kolejnych przebiegów leżą w `audyt/raport-*.txt` na gałęzi
`audyt-projektu`, a szczegółowy opis narzędzia — w `audyt/README.md`.

---

## Wydanie 1.3.7 — po recenzji zewnętrznej

Recenzent zgłosił jedenaście usterek **bez listy**: jedną dużą, jedną średnią,
sześć drobnych i trzy w stronie pokazowej. Nasze narzędzia dawały wtedy wynik
czysty — komplet testów bez porażki, PHPCS bez błędu, bramka audytu „GO". To
znaczyło jedno: znalazł rzeczy, których te narzędzia **z definicji nie widzą**.
Siatka regresyjna sprawdza wyłącznie to, co już kiedyś w tym projekcie
zepsuliśmy; bramka audytu porównuje kod z rejestrem własnych pomyłek, a nie
z treścią zlecenia; żaden test nie oceniał demo ani tego, czy produkt robi to,
co zamówiono.

Zamiast zgadywać, rozpisaliśmy zlecenie **zdanie po zdaniu** i każdemu wymogowi
przypisaliśmy werdykt z dowodem `plik:linia`. Znalazło się siedemnaście ustaleń
w kodzie i pięć w demo — więcej, niż zgłosił recenzent, więc część na pewno się
nie pokrywa. Naprawione są wszystkie realne; przy braku listy to jedyna sensowna
reakcja.

**Duże.** Jeden lead miał DWÓCH różnych handlowców. Wtyczka 1 wybierała go sama,
haszem `crc32(NIP) % liczba_handlowców` — bez kraju, języka, zespołu i obciążenia,
czyli bez niczego, co przesądza, czy handlowiec jest *odpowiedni*. Równolegle
wtyczka 3 dobierała właściciela procesu naprawdę. Niemiecka firma trafiała do
polskiego handlowca w BD-3 i do niemieckiego w BD-1 — z tego samego zgłoszenia,
w tym samym żądaniu. Żaden test tego nie widział, bo każda wtyczka **z osobna**
zachowywała się spójnie. Zlecenie mówi to samo trzy razy niezależnie: cel
biznesowy żąda „odpowiedniego" handlowca, tabela zakresu umieszcza przypisanie
we wtyczce 3, a BD-1 opisuje jako powiązanie użytkownika z krajem, zespołem
i językiem. Naprawa nie przenosi kodu — zamienia decyzję w **pytanie**.

**Średnie.** Punktacja leada była liczona przy każdym zgłoszeniu i nie pokazywał
jej żaden ekran w żadnej z trzech wtyczek. Zlecenie wymienia scoring jako element
kwalifikacji; liczba, której nikt nie widzi, niczego nie kwalifikuje.

**Znalezione przy okazji, a niewidoczne wcześniej dla nikogo:**

- **CI była czerwona od chwili powstania**, z wydaniem 1.3.6 włącznie. Brama
  wydania czytała z PHPCS liczbę błędów — zgadzała się — i nigdy jego **kodu
  wyjścia**. PHPCS kończy się jedynką także przy samych ostrzeżeniach.
- **Wtyczka 2 obiecywała PHP 7.4**, na którym jej autoloader kończy się fatalem
  (dołączony dompdf wymaga 8.1). Komentarz przy macierzy CI mówił wprost, że bez
  tego sprawdzenia deklaracja zgodności jest gołosłowna. Była.
- **Dwie wtyczki definiowały te same role.** `add_role()` przy istniejącej roli
  nie robi nic, więc uprawnienia dostawała tylko ta aktywowana pierwsza —
  handlowiec czytał „Brak uprawnień" albo nie, zależnie od kolejności instalacji.
- **Motyw strony pokazowej istniał wyłącznie na maszynie autora.** Archiwum
  w wydaniu powstawało z katalogu spoza repozytorium; nikt poza autorem nie mógł
  odtworzyć tego, co ogląda recenzent.

Dwa zarzuty **odrzuciliśmy z uzasadnieniem**, bo były fałszywymi alarmami — i to
też jest wynik, zapisany po to, żeby nikt nie wracał do nich drugi raz. Jedno
z naszych własnych ustaleń okazało się przy naprawie odwrotne do prawdy: zarzut
o łamaniu zasady „jeden plik na dział" przez wtyczkę 3 wziął się z błędu
w liczeniu (`ls | wc -l` liczył też `index.php`). To ona jedyna zasady
przestrzegała; łamały ją wtyczki 1 i 2.

Pełny rozpis: [`audyt/zgodnosc-ze-zleceniem.md`](https://github.com/krzysiek2115op/egzamin-koncowy/blob/audyt-projektu/audyt/zgodnosc-ze-zleceniem.md)
(gałąź `audyt-projektu`). Zapis z przebiegu testów:
[`raporty/PRZEBIEG-TESTOW.md`](raporty/PRZEBIEG-TESTOW.md).

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
