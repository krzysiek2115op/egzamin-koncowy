# Materiały klienckie — źródła (Plugin 3 · MP Sales Workflow)

Dedykowane, wersjonowane **źródła** wszystkich materiałów dla klienta. Każdy
materiał ma jeden, osobny plik źródłowy — nic nie jest współdzielone z Pluginem 1
ani 2 poza wspólnym rendererem PDF. `./build.sh` odtwarza z nich komplet 9 plików
wynikowych.

## Zawartość (źródła)

| Plik źródłowy | Materiał wynikowy | Uwaga |
|---|---|---|
| `schemat-nietechniczny.js` | `schemat-nietechniczny.drawio` + `.pdf` | proces prostym językiem (5 kroków) |
| `schemat-techniczny.js` | `schemat-techniczny.drawio` + `.pdf` | 4 kanały wejścia → brama pochodzenia → pipeline 9 działów → transakcja → wyjście |
| `schemat-bazy-danych.js` | `schemat-bazy-danych.drawio` + `.pdf` | ERD BD-1 (kształty draw.io `table`) |
| `instrukcja-instalacji-nietechniczna.json` | `instrukcja-instalacji-nietechniczna.pdf` | instalacja dla osób nietechnicznych |
| `instrukcja-instalacji-techniczna.json` | `instrukcja-instalacji-techniczna.pdf` | stałe, poczta, cron, role, profile, deinstalacja |
| `jak-dziala-system.json` | `jak-dziala-system.pdf` | cały łańcuch LP.1 → LP.2 → LP.3: skąd dane, co się z nimi dzieje, gdzie kończą |
| `gen-doc.js` | — | wspólny renderer A4 PDF (pdf-lib + DejaVu Sans) |
| `build.sh` | — | budowanie + opcjonalny `deploy` |

Każdy generator schematu emituje **równolegle** plik `.drawio` (edytowalny w
[draw.io](https://app.diagrams.net) / diagrams.net) oraz `.svg`, z którego
`rsvg-convert` renderuje `.pdf`. Oba pochodzą z tego samego opisu danych, więc
nie rozjeżdżają się.

## Zgodność z kodem (single source of truth)

Treść jest utrzymywana ręcznie w zgodzie z realnym kodem wtyczki v1.0.0:

- **ERD** (`schemat-bazy-danych.js`) odwzorowuje `CREATE TABLE` z
  `includes/db/class-mp-sales-workflow-db.php` (DB_VERSION 0.3.0) — 5 tabel,
  kolumny/typy/klucze 1:1, z `claimed_at` i `claim_token` oznaczonymi jako
  dodane przy utwardzeniu. Dwa twarde więzy (`tasks.flow_id`,
  `notifications.flow_id` → `flow.id` ON DELETE CASCADE) zakłada osobny ALTER,
  bo `dbDelta` kluczy obcych nie tworzy; dziennik i rejestr zdarzeń więzu nie
  mają **celowo** — audyt ma przetrwać usunięcie procesu.
- **Schemat techniczny**: liczby par Agent+Krytyk (3, 5, 2, 3, 2, 2, 3, 3, 2 =
  25) pochodzą z `MP_SW_Pipeline_Factory::make()`; kanały wejścia i dozwolone
  typy zdarzeń — z `MP_SW_Origin::matrix()`. Transakcja obejmuje **wyłącznie
  Dział 8**, kolejka e-mail rusza **po COMMIT** (Dział 9).
- **Instrukcje**: nazwy pól meta to `mp_sw_country`, `mp_sw_langs`, `mp_sw_team`,
  `mp_sw_active` — wtyczka nie dokłada pól do ekranu profilu, ustawia się je
  przez WP-CLI.
- **„Jak działa system"**: `mp_offer_approved` opisany jako kontrakt na
  przyszłość — LP.3 ma nasłuch, ale w tej wersji **nikt go nie emituje**; pełną
  drogę uruchamia `mp_offer_created` z Działu 11 LP.2.

## Budowanie

```bash
./build.sh          # → ./out (9 plików)
./build.sh deploy   # + kopia do ../../paczka-klienta/materialy-p3 i ~/Pulpit/mp-sales-workflow-materialy
```

Wymagania: `node`, `rsvg-convert` (librsvg), czcionki DejaVu Sans w systemie.
Katalog `out/` jest roboczy (nie wersjonowany).
