# Audyt projektu — mały pipeline z dwóch działów

Narzędzie audytujące **cały projekt naraz**: trzy wtyczki i trzy bazy danych.
Nie jest wtyczką WordPress i nie trafia do klienta — dlatego żyje na własnej
gałęzi `audyt-projektu`, założonej od `main`.

## Uruchomienie

```sh
php audyt/bin/audyt.php --repo=/sciezka/do/repo
php audyt/bin/audyt.php --repo=... --bez-modelu   # tylko reguły, bez oceny modelu
```

Narzędzie samo wystawia `git worktree` trzech gałęzi i audytuje ich **aktualne
czubki** — czyli to, co jest w repozytorium, a nie to, co akurat leży na dysku.
Po przebiegu sprząta po sobie.

Kod wyjścia: `1`, gdy są ustalenia krytyczne (do użycia w CI), `0` w przeciwnym razie.

## Dwa działy

| Dział | Rola |
|---|---|
| 1 — Audyt | szuka problemów; **celowo nadgorliwy**, woli zgłosić za dużo |
| 2 — Re-audyt | weryfikuje każde zgłoszenie **drugą metodą**, odsiewa fałszywe alarmy, wykrywa sprzężenia między błędami |

Ten podział nie jest ozdobą. W pierwszym uruchomieniu na tym repozytorium
Dział 1 zgłosił 14 ustaleń krytycznych, a Dział 2 **odrzucił wszystkie 14**
jako fałszywe alarmy — sprawdzając je niezależnym greppem. Gdyby Działu 2 nie
było, raport straszyłby czternastoma nieistniejącymi błędami.

## Zasada, na której stoi całość

**Kontrola, której nie dało się wykonać, zgłasza NIEOCENIONE — nigdy PASS.**

Fałszywe „zielone" zamyka temat i jest groźniejsze niż brak wyniku. W tym
projekcie jeden test przechodził fałszywie, bo strażnik zwracał zera, a test
właśnie zer oczekiwał. Bramka jakości działu wymaga **100% wykonanych par** —
„prawie wszystko sprawdzone" to nie to samo, co „sprawdzone".

## Ocena modelem

Część rzeczy nie da się rozstrzygnąć regułą („ta cena jest liczona drugi raz",
„ten status kłamie"). Dla nich istnieje `MP_AU_Krytyk_Modelowy`: agent zbiera
dossier w PHP, model wydaje werdykt. Adapter próbuje kolejno: lokalnego
`claude -p`, potem API z `ANTHROPIC_API_KEY`, a gdy nic nie ma — zgłasza
NIEOCENIONE. Każde dossier ląduje na dysku w `raporty/dossier/`, żeby dało się
odtworzyć, **na jakiej podstawie** zapadła ocena.

Ocena modelu ma zawsze status `prawdopodobne`. Podnieść ją do `potwierdzone`
może wyłącznie Dział 2, sprawdzając ustalenie drugą drogą.

## Dokumentacja działów

Po jednym pliku na dział (zasada projektu):

- `docs/dzial-01/audyt.md`
- `docs/dzial-02/re-audyt.md`

Zawierają wierne cytaty ze źródeł oficjalnych (WordPress, MySQL) **oraz** opis
realnych błędów tego projektu, które dana para ma wykrywać.

## Stan wdrożenia

Zbudowane i działające: 8 par w Dziale 1, 3 pary w Dziale 2.
Zaplanowane (opisane w dokumentacji, jeszcze bez implementacji): pary 1.3, 1.6,
1.9, 1.11, 1.12, 1.14, 1.15, 1.16, 1.17 oraz 2.2, 2.4, 2.6.
