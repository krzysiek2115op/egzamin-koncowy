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
| 1 | `mp-lead-intake` | 1.3.10 | BD-3 | Przyjęcie i kwalifikacja lead-a z formularza |
| 2 | `mp-offer-builder` | 1.3.10 | BD-2 | Kalkulacja cenowa, integracja WooCommerce, oferty PDF |
| 3 | `mp-sales-workflow` | 1.3.10 | BD-1 | Statusy procesu, handlowiec, powiadomienia, follow-up, dashboard |

Kolejność instalacji ma znaczenie: **1, potem 2, potem 3**. Wtyczka 2 nasłuchuje
zdarzenia z wtyczki 1, a wtyczka 3 — zdarzeń z obu poprzednich. Gotowe paczki
do wgrania są w [Releases](https://github.com/krzysiek2115op/egzamin-koncowy/releases).

### Wymagania

| | |
|---|---|
| WordPress | 6.0 lub nowszy; testowane na 7.0 |
| PHP | 7.4 dla wtyczek 1 i 3, **8.1 dla wtyczki 2** (dołączony dompdf nie działa niżej) |
| WooCommerce | wymagany przez wtyczkę 2 (`Requires Plugins: woocommerce`) |
| Stałe w `wp-config.php` | `MP_SW_LINK_KEY` — bez niej wtyczka 3 celowo wstrzymuje wysyłkę powiadomień; `MP_HASH_PEPPER` — pieprz do hashowania |

Na serwerze z PHP starszym niż 8.1 WordPress **nie pozwoli aktywować wtyczki 2**.
Wtyczki 1 i 3 zainstalują się normalnie, więc proces ruszy i zatrzyma się na
kroku ofert — dlatego wymaganie 8.1 dotyczy w praktyce całej dostawy.

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

## Wydanie 1.3.10 — po ocenie zewnętrznej: konfiguracja, tłumaczenia, paczka

Recenzent sprawdził **demo, repozytorium i paczkę ZIP** i naliczył sześć błędów
w rozkładzie 1 duży / 1 średni / 3 małe / 1 w demo — bez listy. Rozkład sam
w sobie był przesłanką: liczba dużych i średnich **nie spadła** względem
poprzedniej oceny, mimo trzech wydań napraw. Skoro nie dodawaliśmy w tym czasie
funkcjonalności, musiały to być te same błędy — rzeczy, których albo nigdy nie
znaleźliśmy, albo znaleźliśmy i odrzuciliśmy. Najniższa ocena dotyczyła gotowości
produkcyjnej i to tam siedział duży.

**SYSTEMU NIE DAŁO SIĘ SKONFIGUROWAĆ Z PANELU, W KTÓRYM SIĘ GO INSTALUJE.**
Dział 4 dobiera właściciela procesu po usermeta `mp_sw_country`, `mp_sw_langs`
i `mp_sw_active`. Konto z samą rolą „Handlowiec", bez tych pól, nie jest
kandydatem dla żadnego procesu — pipeline kończy się kodem `no_owner` i proces
nie powstaje. Ustawić je dało się **wyłącznie** przez `wp user meta update` albo
wprost w bazie. Klient, któremu `PRZECZYTAJ-MNIE.txt` każe wgrać wtyczki przez
„Wtyczki → Dodaj nową", nie miał jak dokończyć konfiguracji tam, gdzie ją zaczął:
system po instalacji przyjmował zgłoszenia i po cichu nie robił z nimi nic.
Nazywaliśmy to „konfiguracją wdrożeniową" — z punktu widzenia kogoś, kto ma to
uruchomić, była to konfiguracja bez interfejsu.

**PACZKA WGRYWAŁA NA PRODUKCJĘ KOD URUCHAMIALNY.** Cztery pliki PHP nie miały
zabezpieczenia przed bezpośrednim wywołaniem, w tym **dwa harnessy działające bez
WordPressa** — wejście na ich adres po prostu je wykonywało. Bliźniaczy plik
wtyczki 2 dostał tę ochronę przy SR5-03; wtyczka 1 nie. Kolejna połowa naprawy
zrobiona tam, gdzie się patrzyło.

**ZERO INFRASTRUKTURY TŁUMACZEŃ PRZY 367 CIĄGACH.** Wszystkie trzy wtyczki wołały
`load_plugin_textdomain( …, '/languages' )`, a katalogu nie było w żadnej, pliku
`.pot` nie było w żadnej i nagłówka `Domain Path` nie deklarowała żadna.
WordPress szukał katalogu, którego nie dostarczono, a tłumacz nie miał z czym
usiąść — mimo że kod był „przygotowany do tłumaczenia" w każdej linii.

**TEKST DLA KLIENTA ŻYŁ W KATALOGU TYMCZASOWYM.** `PRZECZYTAJ-MNIE.txt` — pierwsza
strona, którą widzi klient po rozpakowaniu — nie istniał w repozytorium wcale:
przepisywany był z paczki poprzedniego wydania przez skrypt w `/tmp`. Nie dało się
go zrecenzować w commicie ani odtworzyć po restarcie maszyny. Źródła leżą teraz
w `tools/wydanie/przeczytaj-mnie/`, a skrypt pakujący w `tools/wydanie/` —
sprawdzone: odtwarza opublikowane paczki 1.3.9 co do znaku.

**DEMO ŁAMAŁO WŁASNĄ POLITYKĘ PRYWATNOŚCI.** Strona „Kontakt" osadzała ramkę
Google Maps przy każdym wejściu, a polityka prywatności deklarowała „wyłącznie
niezbędne pliki cookies" — zdanie nieprawdziwe na stronie, która je głosi.
W produkcie, którego osią sprzedażową jest RODO. Mapa wczytuje się teraz dopiero
po kliknięciu i wskazuje adres, który strona podaje, zamiast ogólnego „Warszawa
Centrum"; polityka wymienia Google Fonts, Unsplash i Mapy Google.

Do tego `Tested up to` mówiło 6.6 (wtyczka 1) i 6.8 (wtyczki 2 i 3), podczas gdy
regresja chodzi na WordPressie **7.0**.

Przebieg: regresja **79/79** (trzy nowe pliki testowe), PHPCS kod wyjścia 0.

## Wydanie 1.3.9 — druga połowa własnych napraw

Audyt głęboki uruchomiony **po** wydaniu 1.3.8 — trzy przebiegi, bo pary modelowe
nie są powtarzalne — dał w każdym z nich werdykt „GO WITH MINOR FIXES". Dziesięć
ustaleń wypłynęło niezależnie w co najmniej dwóch przebiegach i to one były
prawdziwym wynikiem. Siedem z tych dziesięciu miało wspólną cechę, którą trzeba
powiedzieć wprost: **to były niedokończone połówki napraw z 1.3.8**.

Normalizacja „brak właściciela = NULL" objęła nagłówek oferty i pominęła wiersz
historii wersji. Strażnik „promocja bez ceny promocyjnej" łapał rozjazd danych
z pustą ceną, a przepuszczał rozjazd z ceną równą regularnej — czyli dokładnie
przypadek opisany w komentarzu przy nim samym. `intdiv()` trafiło do ścieżki
głównej i ominęło zapasową. Wniosek na przyszłość jest prosty i niewygodny:
naprawa domyka lukę tylko tam, gdzie się patrzyło, a pojęcie żyje zwykle
w kilku miejscach naraz.

**Dwie rzeczy warte zapamiętania poza samą listą.**

Pierwsza: ustalenie zgłoszone w **dwóch przebiegach z trzech okazało się
fałszywe**. „Nagłówek ustawia status na szkic bezwarunkowo, więc zapis cofnie
zatwierdzoną ofertę" — nieprawda, bo kilkaset linii niżej stoi wartownik
w zdaniu WHERE i zapis fizycznie nie trafi w wiersz o innym statusie. Obie próby
czytały ten sam fragment, więc powtarzalność mówiła o zgodności próbek, a nie
o prawdziwości. Drugie odrzucenie dotyczyło statusu podatkowego „tylko wysyłka",
zwolnionego z VAT od wcześniejszej rundy. Oba są zapisane w rejestrze wraz
z powodem, a ich asercje zostały w repozytorium jako straż.

Druga: **regresja złapała porażkę, której nie było widać przez pół roku** —
i to nie w produkcie, tylko w sondzie. Test Białej listy liczył „dzisiejszą datę"
w UTC, a wtyczka liczy dobę tego rejestru po polsku, bo tak stanowi prawo.
Przez dwadzieścia dwie godziny na dobę obie daty są identyczne. Przebieg wypadł
o 00:20 — w jedynym oknie, w którym ten test potrafił paść. Groźniejszy jest
wariant odwrotny: sonda licząca czas inaczej niż produkt równie dobrze może
**przeoczyć** realną wadę przez pozostałe dwadzieścia dwie godziny.

Wtyczka 3 nie dostała w tym wydaniu żadnej zmiany, więc **zostaje na 1.3.8**.
Podbicie numeru przy identycznym kodzie byłoby tym samym błędem, który to
wydanie zamyka: obietnicą, której kod nie dotrzymuje.

---

## Wydanie 1.3.8 — audyt głęboki, czyli pary, które pytają model

Wydanie 1.3.7 zamknęło to, co dało się znaleźć czytaniem zlecenia zdanie po
zdaniu. Zostało pytanie, na które ta metoda nie odpowiada: **czy kod robi to, co
sam o sobie mówi**. Odpowiadają na nie dwie pary audytu, których żaden wcześniejszy
przebieg nie uruchomił — 1.25 (semantyka działów) i 1.26 (komunikaty dla
człowieka). Są niedostępne na poziomie `pełny`, bo pytają model, a nie wzorzec;
w zamian nie są powtarzalne: każdy przebieg próbkuje inny wycinek kodu. Stąd trzy
przebiegi i suma, a nie jeden werdykt.

Dwadzieścia ustaleń, po rozpisaniu na pliki **trzydzieści siedem napraw i jedno
odrzucenie**. Wszystkie w jednym gatunku: kod, który **melduje coś innego, niż
zrobił**.

**Najgroźniejsze.** Wyczyszczenie tabeli reguł rabatowych zapisywało konfigurację
„0% dla każdej oferty" — z zielonym komunikatem o sukcesie. Rabaty znikały ze
sklepu, a udokumentowany powrót do reguł wbudowanych był tą drogą nieosiągalny.
Obok tego bramka K5.2 wtyczki 3 sprawdzała zgodność przejścia ze zdarzeniem
**jednostronnie**: gdy koperta nie przyznawała się do zmiany statusu, nie badał
tego nikt — wywołujący dostawał „przyjęte", a status zostawał na miejscu.
I trzecie: nierozstrzygnięta Biała lista uchodziła za sprawdzoną, bo warunek
patrzył wyłącznie na `vat_valid`, a odczytany `company_status` nie był używany do
niczego. Lead nigdy nie wracał do weryfikatora, a nieznany status firmy i tak
wchodził do punktacji.

**Jedno ustalenie okazało się fałszywe** i jest odnotowane jako odrzucone
(wpis U-24 w rejestrze): „kontrola podstawy VAT tylko w gałęzi krajowej".
Gałąź niekrajowa ma własną kontrolę, świadomie zawężoną do pozycji niepustych,
z komentarzem tłumaczącym różnicę. Model zobaczył pierwszą i orzekł o całym pliku.
Asercje zostały w repozytorium jako straż — dokumentują, dlaczego te dwie gałęzie
mają wyglądać różnie.

Metoda bez zmian: każda naprawa poprzedzona testem, który **padał** przed nią —
udowodnionym uruchomieniem, nie deklaracją — i kontr-asercjami pilnującymi
zachowania, którego ruszać nie wolno. Siatka regresyjna urosła z 66 do 71 plików.
Jedna z napraw wywróciła rusztowanie testowe wtyczki 2: twarda odmowa przy braku
ustawienia „ceny zawierają podatek" obnażyła, że atrapa WooCommerce tej funkcji
w ogóle nie miała. Dołożyliśmy brakującą atrapę, zamiast cofać naprawę —
rusztowanie ma modelować sklep, a nie ukrywać jego brak.

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
- **Pierwsze zgłoszenie po instalacji ginęło.** Dopóki administrator nie założył
  kont handlowców, wtyczka 3 nie miała komu przypisać procesu — i zamiast zapisać
  proces bez właściciela, odrzucała całe zdarzenie. Lead zostawał w BD-3, oferta
  szła do BD-2, a procesu w BD-1 nie było w ogóle: ani wiersza, ani wpisu
  w dzienniku. Jedyny ślad trafiał do `error_log` PHP i tylko przy włączonym
  `WP_DEBUG`. Nie było tego widać, bo testy uruchamiano na bazie, w której konta
  handlowców zostawały po **wcześniejszych przebiegach innych testów** — dopiero
  ich skasowanie pokazało prawdę. Bramka pilnuje teraz nie „zawsze ktoś
  przypisany", lecz „brak właściciela musi mieć podany powód".

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
