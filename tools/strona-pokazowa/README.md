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

Strona otwiera się na **Procesy sprzedażowe** (wtyczka 3). Oglądający jest
zalogowany jako administrator, więc ma dostęp do wszystkiego.

| Gdzie | Co tam jest |
|---|---|
| Procesy sprzedażowe | Proces w statusie „oferta robocza", przypisany handlowiec, dziennik aktywności |
| MP Offer Builder | Oferta `OF/2026/000001` z wygenerowanym plikiem PDF |
| Leady (wtyczka 1) | Zgłoszenie firmy z NIP-em i statusem weryfikacji VAT |
| `/zapytanie-ofertowe/` | Publiczny formularz — można wysłać własne zgłoszenie i zobaczyć, jak przechodzi przez trzy wtyczki |

Zasiane zgłoszenie **przeszło prawdziwą drogą** — przez pipeline wtyczki 1, a nie
przez ręcznie wystawione zdarzenie. Dlatego dane są we wszystkich trzech bazach,
a nie tylko w dwóch.

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
