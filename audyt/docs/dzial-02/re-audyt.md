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

---

## Pary agent + krytyk w Dziale 2 — komplet 10

Kolejność nie jest przypadkowa. Najpierw odsiewamy zgłoszenia, które nie
wskazują istniejącego miejsca (2.2) — nie ma sensu weryfikować ich drugą metodą
ani pytać o nie modelu. Potem potwierdzamy to, co zostało. Na samym końcu
oceniamy **sam werdykt**.

| Para | Poziom | Agent zbiera | Krytyk rozstrzyga | Po co |
|---|---|---|---|---|
| 2.2 | S | dla każdego ustalenia: czy plik istnieje, czy linia mieści się w pliku, czy to nie komentarz, czy nie duplikat | zgłoszenie musi wskazywać istniejące miejsce | najtańsza kontrola całego re-audytu; wyłapuje błędy narzędzia, nie projektu |
| 2.1 | S | próba potwierdzenia ustalenia **inną drogą** niż ta, którą je znaleziono | bez drugiego dowodu to tylko hipoteza | w pierwszym przebiegu odrzuciła 14 z 14 „krytycznych" |
| 2.3 | S | graf sprzężeń: które ustalenie **maskuje** które | nie naprawiaj maskującego bez maskowanego | martwy endpoint maskował wyciek cudzych PDF-ów |
| 2.7 | S | pliki, do których **nie zajrzała żadna para** | białych plam ma nie być | „zero ustaleń w pliku X" i „nikt nie otworzył pliku X" wyglądały identycznie |
| 2.8 | S | porównanie z `raporty/raport-ostatni.json` sprzed tego przebiegu | ubyło czy tylko się przesunęło | jedyne pytanie, które ma sens po naprawie |
| 2.6 | P | archeologia gitowa: czy test przyszedł w jednym commicie z naprawą | naprawa musi mieć ślad weryfikacji | osiem napraw z 29.07 potwierdzano ręcznie przez `git stash` |
| 2.5 | S | pętla stabilizacji: powtarza **sprawdzenia** aż wynik przestanie się zmieniać (maks. 3 obiegi) | wynik ma być powtarzalny | patrz niżej: dlaczego pętla nie naprawia |
| 2.4 | P | powtórzony przebieg **całego Działu 1** na tym samym stanie | ten sam stan = ten sam wynik | TEST-F2: nasz własny test dawał 10 FAIL przy drugim uruchomieniu bez zmiany w kodzie |
| 2.9 | G | paczki ustaleń jako dossier dla **drugiego sędziego** | model ma zakwestionować cudze zgłoszenia | Dział 1 używa modelu do zgłaszania; tu ten sam mechanizm działa w przeciwną stronę |
| 2.10 | S | ustalenia bez dowodu albo bez scenariusza awarii | każde ustalenie uniesie swój ciężar | zgłoszenie krytyczne bez dowodu blokowałoby wydanie samym brzmieniem |

## Dlaczego pętla powtarza sprawdzenia, a nie naprawy

To jest **decyzja projektowa, nie ograniczenie techniczne**, i wynika wprost
z najdroższej lekcji tego projektu.

Wyciek cudzych ofert (INT-K3) był **zamaskowany** przez to, że endpoint w ogóle
się nie rejestrował (P3-K1). Automat naprawiający „aż do zera ustaleń" naprawiłby
rejestrację jako pierwszą — bo to poprawka o jedną linię — i tym samym
**udostępniłby klientom dokumenty innych firm**. Zero ustaleń w raporcie,
katastrofa w produkcji.

Dlatego:

- **2.5 powtarza kontrole**, żeby sprawdzić, czy wynik jest stabilny. Nie zmienia
  ani jednego znaku w projekcie.
- **2.3 buduje graf sprzężeń** i przy każdym ustaleniu maskującym wypisuje wprost:
  „naprawiać RAZEM z X".
- **Poprawki semantyczne zostają przy człowieku.** Automat nie ma jak wiedzieć,
  że naprawa A odsłania B — tę wiedzę niesie graf, a decyzję podejmuje ten, kto
  odpowiada za wydanie.

## Dlaczego 2.6 nie cofa napraw naprawdę

Wzorcowy dowód, że test łapie błąd, wygląda tak: cofnij naprawę, uruchom test,
zobacz FAIL, przywróć naprawę. Tak potwierdzono wszystkie osiem napraw z 29.07.

Automat tego **nie robi i nie będzie robił**:

- cofanie naprawy w repozytorium, na którym ktoś pracuje, to operacja niszcząca,
- testy tego projektu wymagają żywego WordPressa z bazą — audyt nie ma prawa
  zakładać, że one stoją.

Zamiast udawać taką weryfikację, para sprawdza **ślad po niej w historii gita**:
czy test przyszedł razem z naprawą, w jednym commicie.

- **Co to dowodzi:** że autor miał oba pliki na biurku naraz.
- **Czego NIE dowodzi:** że test faktycznie FAIL-ował przed naprawą.

Ta granica jest wypisana w kodzie pary i w raporcie. Audyt, który obiecuje
więcej, niż daje, jest gorszy od audytu, którego nie ma.

## Bezpiecznik pary 2.2

Para 2.2 ma prawo odrzucać ustalenia — i to jest jej zadanie. Ale gdy odrzuca
**niemal wszystko** z powodu „pliku nie ma", to prawie na pewno zepsuło się
odwzorowanie ścieżek w narzędziu, a nie cały Dział 1 naraz.

Ten przypadek **wystąpił podczas budowy** tego działu: poprawka formatu ścieżek
w raporcie sprawiła, że 2.2 skasowała **77 z 87 ustaleń**, a audyt wydał
uspokajający werdykt na podstawie własnej awarii. Dlatego przy odsetku
nieodnalezionych plików powyżej 50% para zgłasza **awarię narzędzia jako
ustalenie krytyczne** i nie odrzuca niczego.

To ta sama zasada, na której stoi cały pipeline, zastosowana do samego re-audytu:
**kontrola, której nie dało się wykonać, nie ma prawa raportować sukcesu.**
