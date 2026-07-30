<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 2 — RE-AUDYT.
Jeden plik na dział (zasada projektu, Golden Rule #2).

ŹRÓDŁA OFICJALNE — odesłania (narzędzia, na których dział stoi):
1. git worktree — dokumentacja Git.
   https://git-scm.com/docs/git-worktree
2. wp eval-file — WP-CLI Commands.
   https://developer.wordpress.org/cli/commands/eval-file/
3. git stash — dokumentacja Git.
   https://git-scm.com/docs/git-stash

ŹRÓDŁO GŁÓWNE, WŁASNE: przebieg audytu końcowego z 29.07.2026
(`STAN-AUDYTU.md`, commity 36531d1, ba91d29, 8f650d3, 17893c5, 1894e8e).
Ten dział jest zapisem metody, która się tam sprawdziła — i zabezpieczeniem
przed pomyłkami, które się tam wydarzyły.
-->

# Dział 2 — re-audyt: dokumentacja źródłowa

## Po co osobny dział, skoro Dział 1 ma już krytyków

Krytyk w Dziale 1 ocenia **wynik jednego agenta**. Re-audyt zadaje pytania,
których żaden pojedynczy krytyk zadać nie może, bo wymagają spojrzenia na
CAŁOŚĆ ustaleń naraz:

- czy to zgłoszenie jest prawdziwe, gdy sprawdzić je **inną metodą**,
- czy naprawa jednego znaleziska **otworzy** drugie,
- czy to, co naprawiliśmy w przeszłości, wciąż jest naprawione.

## Trzy lekcje z 29.07, które ten dział koduje

### Lekcja 1: zgłoszenie agenta to hipoteza, nie fakt

Podczas tamtego audytu **każde** zgłoszenie weryfikowałem samodzielnie, zanim
uznałem je za prawdziwe. Dwa najgroźniejsze potwierdziłem **uruchomieniem
kodu**, nie czytaniem:

```
pierwszy przebieg init:            p10-boot
drugi przebieg (gdyby init 2x):    p5-maybe_serve -> p10-boot
```

```
uchwyt (UUID): 120f3e8a12-0000-4000-8000-000000000000
(int) uchwyt = 120
zapytanie zwrocilo: 'CUDZA-OFERTA-120.pdf'
```

→ Para **A2.1 / K2.1** wymaga, by każde ustalenie miało **dowód drugą drogą**:
znalezione analizą statyczną — potwierdzone wykonaniem; znalezione wykonaniem —
potwierdzone w kodzie. Ustalenie bez drugiego dowodu dostaje status
PRAWDOPODOBNE, nie POTWIERDZONE.

### Lekcja 2: błędy bywają sprzężone, a naprawa jednego otwiera drugi

Wyciek cudzych ofert był **zamaskowany** przez to, że endpoint w ogóle się nie
rejestrował. Naprawa samej rejestracji **udostępniłaby klientom dokumenty innych
firm**. Musiały pójść jednym commitem.

→ Para **A2.3 / K2.3** buduje **graf zależności** między ustaleniami i szuka
par „A maskuje B". Krytyk odrzuca plan naprawy, w którym maskujący błąd zostaje
naprawiony bez maskowanego.

To jest też powód, dla którego pętla naprawcza w tym projekcie **nie stosuje
poprawek semantycznych automatycznie** (patrz para 2.5).

### Lekcja 3: własne testy też kłamią

Cztery „błędy" z tamtej rundy okazały się błędami w moich testach, nie w kodzie:
fixture ze zahardkodowanym numerem oferty łamał więz `UNIQUE` (test przechodził
tylko za pierwszym razem), inny zależał od danych zostawionych przez sąsiednie
zestawy, a plik reguł PHPCS miał domenę tekstową innej wtyczki.

→ Para **A2.2 / K2.2** odsiewa fałszywe alarmy, a **A2.4 / K2.4** sprawdza
**powtarzalność**: ten sam zestaw uruchomiony dwa razy z rzędu musi dać ten sam
wynik. Test niepowtarzalny jest traktowany jak usterka audytu.

## Metoda potwierdzania naprawy — `git stash`

Poprawka, przy której nie wykazano, że test **failuje bez niej**, nie jest
uznana za potwierdzoną. Tak zweryfikowałem wszystkie osiem napraw z 29.07:

```
git stash push -- <pliki poprawki>
<uruchom test>      -> oczekiwany FAIL
git stash pop
<uruchom test>      -> oczekiwany PASS
```

→ Para **A2.6 / K2.6** wykonuje to automatycznie dla każdej pary
„poprawka + test" wskazanej w rejestrze.

## Dwa tryby pracy działu

Dział 2 uruchamia się **dwa razy** w pełnym cyklu:

**Tryb WERYFIKACJA** (zaraz po Dziale 1, przed jakąkolwiek naprawą) — sprawdza,
które ustalenia są prawdziwe, odsiewa fałszywe alarmy, buduje graf zależności
i ustala kolejność napraw.

**Tryb POTWIERDZENIE** (po naprawach) — powtarza komplet kontroli Działu 1,
porównuje z poprzednim przebiegiem i odpowiada na trzy pytania: czy zgłoszone
błędy zniknęły, czy nie pojawiły się nowe, czy dawne naprawy nadal trzymają.

Tryb jest parametrem przebiegu, nie osobnym kodem — inaczej oba tryby
rozjechałyby się przy pierwszej zmianie.

## Pary agent + krytyk w Dziale 2

| Para | Agent | Krytyk | Lekcja |
|---|---|---|---|
| 2.1 | odtwarza każde ustalenie **drugą metodą** | bez drugiego dowodu status najwyżej PRAWDOPODOBNE | 1 |
| 2.2 | konfrontuje ustalenia z rejestrem znanych fałszywych alarmów i z kontekstem (celowe wyjątki, `phpcs:ignore` z uzasadnieniem) | fałszywy alarm nie może trafić do raportu jako błąd | 3 |
| 2.3 | buduje graf zależności między ustaleniami | żaden maskujący błąd nie jest naprawiany bez maskowanego | 2 |
| 2.4 | uruchamia każdy zestaw testów **dwukrotnie** | wynik musi być identyczny; rozbieżność = usterka audytu | 3 |
| 2.5 | pętla stabilizacji: powtarza kontrole, aż zbiór ustaleń przestanie się zmieniać (twardy limit obiegów) | pętla, która nie zbiega, sama jest ustaleniem | — |
| 2.6 | weryfikacja napraw metodą `git stash` | poprawka bez testu failującego bez niej = NIEPOTWIERDZONA | — |

## Granica automatu — powiedziane wprost

Pętla z pary 2.5 powtarza **sprawdzenia**, nie naprawy semantyczne. Automat
stosuje wyłącznie poprawki dowodnie bezpieczne (formatowanie przez `phpcbf`,
synchronizacja numeru wersji między czterema plikami). Wszystko, co wymaga
decyzji „którą stronę naprawić", trafia do raportu jako propozycja z gotowym
diffem. Uzasadnienie jest w lekcji 2: automat naprawiający „aż do zera błędów"
otworzyłby 29.07 lukę bezpieczeństwa.
