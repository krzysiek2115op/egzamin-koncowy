# Strona pokazowa dla egzaminatora

Jeden odnośnik, po którego kliknięciu w przeglądarce staje kompletny WordPress
z **trzema wtyczkami tego projektu**, WooCommerce skonfigurowanym po polsku
i jednym przebiegiem procesu widocznym we wszystkich trzech bazach.

**Odnośnik:**

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/krzysiek2115op/egzamin-koncowy/main/tools/strona-pokazowa/blueprint.json
```

Nic nie jest hostowane: WordPress uruchamia się w przeglądarce oglądającego
(WebAssembly), a dane żyją tylko w jego karcie. Zamknięcie karty kasuje wszystko,
więc kolejne wejście zawsze zaczyna od czystego stanu — i nikt nie zepsuje
demonstracji drugiej osobie.

## Co widać po wejściu

Strona otwiera się na **Procesach sprzedażowych** (wtyczka 3). Oglądający jest
zalogowany jako administrator.

W menu panelu są **dwie** pozycje tego projektu — i tak ma być:

| Gdzie | Co tam jest |
|---|---|
| Procesy sprzedażowe (wtyczka 3) | Proces „Zakłady Metalowe Wisła", przypisany handlowiec Anna Kowalska, dziennik aktywności |
| MP Offer Builder (wtyczka 2) | Oferta `OF/2026/000001` z gotowym PDF-em do pobrania i przyciskiem „Zatwierdź" |
| `/zapytanie-ofertowe/` (wtyczka 1) | Publiczny formularz — jedyny interfejs tej wtyczki |

**Wtyczka 1 nie ma własnego ekranu w panelu i to nie jest brak.** Jej zadaniem
jest przyjąć zgłoszenie i przekazać je dalej; nie prowadzi obsługi, więc nie
dubluje list, które prowadzą wtyczki 2 i 3. Jej baza (BD-3) jest źródłem danych,
które widać w obu pozostałych modułach.

### Dwie rzeczy warte kliknięcia

**1. Wyślij formularz.** Otwórz `/zapytanie-ofertowe/`, wypełnij i wyślij.
Natychmiast po wysłaniu w „Procesach sprzedażowych" pojawia się nowy proces
z przypisanym handlowcem, a w „MP Offer Builder" nowy szkic oferty. Ten jeden
klik pokazuje wszystkie trzy bazy naraz: proces nie mógłby powstać, gdyby
wtyczka 1 nie zapisała leada do BD-3 i nie wystawiła zdarzenia.

Uwaga: **jedna firma = jedno zgłoszenie**. Para „kraj + NIP" jest w BD-3 kluczem
niepowtarzalnym, więc powtórne wysłanie tego samego NIP-u zostanie odrzucone.
Do własnej próby użyj innego numeru niż `5252248481` (ten jest już zajęty przez
zasiane zgłoszenie).

**2. Zatwierdź ofertę.** W „MP Offer Builder" kliknij „Zatwierdź" przy ofercie
`OF/2026/000001`. Proces we wtyczce 3 przechodzi z „oferta robocza" na „oferta
wysłana" i sam zakłada dwa zadania follow-up — na trzeci i siódmy dzień. Zdarzenie
przechodzi z wtyczki 2 do wtyczki 3 bez żadnego ręcznego kroku.

## Skąd bierze się kod

Wtyczki instalują się z paczek wydania **1.3.6**, z archiwów pod tagiem
`demo-egzamin`. To te same bajty, co w paczkach dla klienta — różni je wyłącznie
brak zewnętrznej warstwy archiwum, której WordPress Playground nie rozpakuje.
Strona pokazowa uruchamia więc ten sam kod, który dostaje klient.

## Czego demonstracja NIE pokazuje

- **Więzów obcych w bazie.** Playground używa SQLite, a wtyczka 3 zakłada je
  tylko na InnoDB. Kod jest na to przygotowany — więzy są dodatkiem, a spójności
  pilnuje transakcja w Dziale 8 — ale na produkcyjnym MySQL-u baza pilnuje jej
  dodatkowo sama.
- **Wysyłki poczty.** W przeglądarce nie ma serwera SMTP, więc powiadomienia
  zostają w kolejce ze statusem `queued`. Widać, że powstały i do kogo są
  adresowane, ale nigdzie nie wychodzą.
- **Odpowiedzi z VIES i Białej listy MF.** Status VAT zasianego zgłoszenia to
  `pending` — wtyczka celowo nie zgaduje, gdy nie dostała rozstrzygnięcia.

## Jak to zbudować od nowa

```sh
npx @wp-playground/cli@latest server --blueprint=blueprint.json
```

Zmiana zasiewu: krok `runPHP` na końcu `blueprint.json`. Zmiana wersji wtyczek:
podmienić archiwa pod tagiem `demo-egzamin` (tag NIE jest wersyjny i nie dotyczy
wydań produktu — te mają tagi `v1.3.6` oraz `<wtyczka>/v1.3.6`).
