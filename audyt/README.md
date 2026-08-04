# Audyt projektu — mały pipeline z dwóch działów

Narzędzie audytujące **cały projekt naraz**: trzy wtyczki i trzy bazy danych.
Nie jest wtyczką WordPress i nie trafia do klienta — dlatego żyje na własnej
gałęzi `audyt-projektu`, założonej od `main`.

**37 par agent + krytyk**: 26 w Dziale 1 (audyt), 11 w Dziale 2 (re-audyt).

## Uruchomienie

```sh
# szybko, przed commitem — same reguły, ~1 s
php audyt/bin/audyt.php --repo=/sciezka/do/repo --ref=refs/heads/main --glebokosc=szybki

# domyślnie — z narzędziami zewnętrznymi, ~90 s
php audyt/bin/audyt.php --repo=/sciezka/do/repo --ref=refs/heads/main

# komplet przed wydaniem — z oceną modelu, dziesiątki minut
php audyt/bin/audyt.php --repo=/sciezka/do/repo --ref=refs/heads/main --glebokosc=gleboki
```

Dodatkowe przełączniki:

| Przełącznik | Znaczenie |
|---|---|
| `--ref=<ref>` | audytuj JEDEN wskazany ref (np. `refs/heads/main`) zamiast trzech gałęzi wtyczek |
| `--bez-modelu` | wyłącza pary modelowe (zgłoszą NIEOCENIONE, nie PASS) |
| `--limit-modelu=N` | górna liczba pytań do modelu na parę (domyślnie 40) |
| `MP_AU_PHPCS=/sciezka` | wskazuje binarkę PHPCS dla pary 1.3 |

### `--ref` nie jest ozdobą

Bez tego przełącznika narzędzie wystawia `git worktree` **trzech gałęzi wtyczek**
i audytuje ich czubki. Taki był stan projektu, gdy narzędzie powstawało: każda
wtyczka miała własną gałąź. Po scaleniu wszystkiego do `main` te gałęzie stoją
w miejscu — a audyt bez `--ref` nadal je czyta i wypisuje uspokajający raport
o kodzie sprzed scalenia. Nic o tym nie ostrzega, bo z punktu widzenia narzędzia
wszystko przebiegło poprawnie.

Dlatego **od scalenia do `main` każde uruchomienie ma podawać `--ref`**.
Pierwsza linia wypisywana przez narzędzie mówi, co naprawdę audytuje:

```
zrodlo kodu:  refs/heads/main (jeden ref)     <- dobrze
zrodlo kodu:  trzy galezie wtyczek            <- czytasz historię, nie kod
```

Narzędzie audytuje **aktualne czubki** wskazanego źródła — czyli to, co jest
w repozytorium, a nie to, co akurat leży na dysku. Po przebiegu sprząta po sobie.

Kod wyjścia: `1`, gdy są ustalenia krytyczne (do użycia w CI), `0` w przeciwnym razie.

## Trzy głębokości — bo nie ma jednego dobrego czasu audytu

| Poziom | Co dochodzi | Czas na tym repo |
|---|---|---|
| `szybki` | 22 pary analizy statycznej | ~1 s |
| `pelny` (domyślny) | `php -l`, PHPCS/WPCS, archeologia gitowa, powtórzony przebieg Działu 1 | ~90 s |
| `gleboki` | ocena modelu w parach 1.25, 1.26 i 2.9 — dziesiątki dossier | dziesiątki minut |

**Czas nie jest miarą dokładności — pracą jest.** Głęboki przebieg trwa długo
nie dlatego, że czeka, tylko dlatego, że uruchamia PHPCS na trzech wtyczkach,
powtarza cały Dział 1 od zera i zadaje modelowi kilkadziesiąt osobnych pytań
o kolejne działy pipeline'u.

**Pominięcie pary nie jest jej zaliczeniem.** Bramka liczy pominięcia osobno,
a werdykt dostaje dopisek „audyt skrócony" — żeby „GO" po przebiegu szybkim nie
czytało się identycznie jak „GO" po pełnym.

## Dwa działy

| Dział | Rola |
|---|---|
| 1 — Audyt | szuka problemów; **celowo nadgorliwy**, woli zgłosić za dużo |
| 2 — Re-audyt | weryfikuje każde zgłoszenie **drugą metodą**, odsiewa fałszywe alarmy, wykrywa sprzężenia, ocenia sam werdykt |

Ten podział nie jest ozdobą. W pierwszym uruchomieniu Dział 1 zgłosił 14 ustaleń
krytycznych, a Dział 2 **odrzucił wszystkie 14** jako fałszywe alarmy —
sprawdzając je niezależnym greppem. Gdyby Działu 2 nie było, raport straszyłby
czternastoma nieistniejącymi błędami.

## Zasada, na której stoi całość

**Kontrola, której nie dało się wykonać, zgłasza NIEOCENIONE — nigdy PASS.**

Fałszywe „zielone" zamyka temat i jest groźniejsze niż brak wyniku. W tym
projekcie jeden test przechodził fałszywie, bo strażnik zwracał zera, a test
właśnie zer oczekiwał. Bramka jakości działu wymaga **100% wykonanych par** —
„prawie wszystko sprawdzone" to nie to samo, co „sprawdzone".

Ta sama zasada obowiązuje narzędzie wobec siebie samego: gdy para 2.2 nie
potrafi odnaleźć ponad połowy zgłaszanych plików, zgłasza **własną awarię jako
ustalenie krytyczne** i nie odrzuca niczego. Ten przypadek wystąpił podczas
budowy i kosztował 77 skasowanych ustaleń.

## Pętla sprawdzeń, nie pętla napraw

Para 2.5 powtarza **kontrole** aż wynik przestanie się zmieniać. Nie zmienia ani
jednego znaku w projekcie — i to jest decyzja projektowa, nie ograniczenie.

Wyciek cudzych ofert był **zamaskowany** przez martwy endpoint. Automat
naprawiający „aż do zera ustaleń" naprawiłby rejestrację jako pierwszą, bo to
poprawka o jedną linię — i tym samym udostępniłby klientom dokumenty innych
firm. Zero ustaleń w raporcie, katastrofa w produkcji.

Dlatego para 2.3 buduje **graf sprzężeń** i przy ustaleniu maskującym pisze
wprost „naprawiać RAZEM z X", a decyzję podejmuje człowiek.

## Ocena modelem

Część rzeczy nie da się rozstrzygnąć regułą („ta cena jest liczona drugi raz",
„ten status kłamie"). Dla nich istnieją krytycy modelowi: agent zbiera dossier
w PHP, model wydaje werdykt. Adapter próbuje kolejno lokalnego `claude -p`, potem
API z `ANTHROPIC_API_KEY`, a gdy nic nie ma — zgłasza NIEOCENIONE. Każde dossier
ląduje w `raporty/dossier/`, żeby dało się odtworzyć, **na jakiej podstawie**
zapadła ocena.

Ocena modelu ma zawsze status `prawdopodobne`. Podnieść ją do `potwierdzone`
może wyłącznie Dział 2. Model występuje w obu rolach: w Dziale 1 **zgłasza**
(pary 1.25, 1.26), w Dziale 2 **kwestionuje cudze zgłoszenia** (para 2.9).

## Rejestr znanych błędów

`rejestr/znane-bledy.json` — każdy wpis to błąd, który **realnie wystąpił w tym
projekcie**: klasa pomyłki, dowód z kodu, skutek dla użytkownika, test regresji
i para audytu, która ma go dziś łapać. Para 1.15 sprawdza, czy każdy dawny błąd
ma dziś test; para 2.6 — czy ten test przyszedł razem z naprawą.

## Dokumentacja działów

Po jednym pliku na dział (zasada projektu):

- `docs/dzial-01/audyt.md` — wierne cytaty ze źródeł (WordPress, MySQL) + tabela 26 par
- `docs/dzial-02/re-audyt.md` — tabela 10 par + uzasadnienie granic re-audytu

## Struktura

```
audyt/
├── bin/audyt.php                  punkt wejścia
├── includes/
│   ├── rdzen.php                  ustalenie, wynik (3 stany), kontekst
│   ├── kontrakty.php              agent, krytyk, para, dział, bramka
│   ├── pomoc.php                  wspólne narzędzia par (m.in. wygaszanie komentarzy)
│   ├── class-mp-au-workspace.php  git worktree trzech gałęzi
│   ├── class-mp-au-model-client.php
│   ├── class-mp-au-raport.php
│   ├── departments/               fabryki obu działów
│   └── pary/                      implementacje par, pogrupowane tematycznie
├── rejestr/znane-bledy.json
├── docs/
└── raporty/                       (git-ignorowane)
```
