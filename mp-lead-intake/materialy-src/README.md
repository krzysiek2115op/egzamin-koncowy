# Materiały klienckie — źródła (Plugin 1 · MP Lead Intake)

Dedykowane, wersjonowane **źródła** wszystkich materiałów dla klienta.
`./build.sh` odtwarza z nich komplet 9 plików wynikowych.

Ten katalog powstał później niż same materiały — i to jest jego powód istnienia.
Przez kilka wydań dziewięć plików PDF/`.drawio` leżało w `paczka-klienta/` jako
**artefakty bez źródła**: nie dało się ich poprawić, przetłumaczyć ani nawet
podbić numeru wersji w stopce. Materiały wtyczek 2 i 3 miały źródła od początku,
wtyczka 1 nie miała ich wcale. Stąd stopki mówiące „v1.2.3", gdy wtyczka była
na 1.3.10.

## Zawartość (źródła)

| Plik źródłowy | Materiał wynikowy | Uwaga |
|---|---|---|
| `schemat-nietechniczny.js` | `schemat-nietechniczny.drawio` + `.pdf` | proces prostym językiem (5 kroków) |
| `schemat-techniczny.js` | `schemat-techniczny.drawio` + `.pdf` | pipeline 11 działów + BD-3 + zdarzenie |
| `schemat-bazy-danych.js` | `schemat-bazy-danych.drawio` + `.pdf` | ERD BD-3 (kształty draw.io `table`) |
| `instrukcja-instalacji-nietechniczna.json` | `instrukcja-instalacji-nietechniczna.pdf` | instalacja dla osób nietechnicznych |
| `instrukcja-instalacji-techniczna.json` | `instrukcja-instalacji-techniczna.pdf` | wymagania, rejestry, role, cron, deinstalacja |
| `jak-dziala-plugin.json` | `jak-dziala-plugin.pdf` | jak działa wtyczka (część biznesowa + techniczna) |
| `gen-doc.js` | — | wspólny renderer A4 PDF (pdf-lib + DejaVu Sans) |
| `build.sh` | — | budowanie + opcjonalny `deploy` |

Każdy generator schematu emituje **równolegle** plik `.drawio` (edytowalny w
[draw.io](https://app.diagrams.net) / diagrams.net) oraz `.svg`, z którego
`rsvg-convert` renderuje `.pdf`. Oba pochodzą z tego samego opisu danych, więc
nie rozjeżdżają się.

## Numer wersji nie jest wpisywany z ręki

W plikach `.json` stopka zawiera znacznik `{wersja}`. `gen-doc.js` podstawia pod
niego wersję odczytaną z **nagłówka `Version:` pliku głównego wtyczki**. Dzięki
temu materiał zbudowany po podbiciu wersji sam mówi prawdę, a numer nie może
zostać w tyle po cichu — tak jak zostawał przez cztery wydania.

## Zgodność z kodem (single source of truth)

Treść jest utrzymywana ręcznie w zgodzie z realnym kodem wtyczki:

- **ERD** (`schemat-bazy-danych.js`) odwzorowuje `CREATE TABLE` z
  `includes/db/class-mp-db.php` (`DB_VERSION 1.4.0`) wraz z jedynym twardym
  kluczem obcym `fk_offers_lead` dokładanym przez `add_foreign_keys()`.
- **Schemat techniczny** odwzorowuje nazwy i kolejność 11 działów z
  `includes/pipeline/departments/` oraz zdarzenie `mp_lead_created` z Działu 11.
- **Instrukcje** odwzorowują wymagania z nagłówka wtyczki (WP 6.0+, PHP 7.4+),
  role z `includes/class-mp-roles.php`, akcję AJAX `mp_lead_intake_submit`
  i dwa zadania cron rejestrowane w pliku głównym.

Po zmianie któregokolwiek z tych miejsc w kodzie — popraw źródło tutaj
i przebuduj. Bramka wydania sprawdza, czy paczka materiałów jest kompletna,
ale nie sprawdzi, czy jej treść jest prawdziwa.

## Wymagania i budowanie

Potrzebne: `node`, `rsvg-convert` (pakiet `librsvg`), czcionki DejaVu Sans
(`/usr/share/fonts/TTF/DejaVuSans*.ttf`).

```bash
./build.sh           # buduje wszystko do ./out
./build.sh deploy    # dodatkowo kopiuje 9 plików do paczka-klienta/mp-lead-intake/materialy
```

Katalog `out/` i `node_modules/` są ignorowane przez git — w repozytorium
trzymamy źródła, nie wyniki.
