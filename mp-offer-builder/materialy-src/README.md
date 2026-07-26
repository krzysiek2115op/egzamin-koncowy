# Materiały klienckie — źródła (Plugin 2 · MP Offer Builder)

Dedykowane, wersjonowane **źródła** wszystkich materiałów dla klienta. Każdy
materiał ma jeden, osobny plik źródłowy — nic nie jest współdzielone z Pluginem 1
ani trzymane wyłącznie w katalogu tymczasowym. `./build.sh` odtwarza z nich
komplet 9 plików wynikowych.

## Zawartość (źródła)

| Plik źródłowy | Materiał wynikowy | Uwaga |
|---|---|---|
| `schemat-nietechniczny.js` | `schemat-nietechniczny.drawio` + `.pdf` | proces prostym językiem (5 kroków) |
| `schemat-techniczny.js` | `schemat-techniczny.drawio` + `.pdf` | pipeline 11 działów + BD-2 + zdarzenie |
| `schemat-bazy-danych.js` | `schemat-bazy-danych.drawio` + `.pdf` | ERD BD-2 (kształty draw.io `table`) |
| `instrukcja-instalacji-nietechniczna.json` | `instrukcja-instalacji-nietechniczna.pdf` | instalacja dla osób nietechnicznych |
| `instrukcja-instalacji-techniczna.json` | `instrukcja-instalacji-techniczna.pdf` | wymagania, WooCommerce, role, deinstalacja |
| `jak-dziala-plugin.json` | `jak-dziala-plugin.pdf` | jak działa wtyczka (część biznesowa + techniczna) |
| `gen-doc.js` | — | wspólny renderer A4 PDF (pdf-lib + DejaVu Sans) |
| `build.sh` | — | budowanie + opcjonalny `deploy` |

Każdy generator schematu emituje **równolegle** plik `.drawio` (edytowalny w
[draw.io](https://app.diagrams.net) / diagrams.net) oraz `.svg`, z którego
`rsvg-convert` renderuje `.pdf`. Oba pochodzą z tego samego opisu danych, więc
nie rozjeżdżają się.

## Zgodność z kodem (single source of truth)

Treść jest utrzymywana ręcznie w zgodzie z realnym kodem wtyczki v1.0.3:

- **ERD** (`schemat-bazy-danych.js`) odwzorowuje `CREATE TABLE` z
  `includes/db/class-mp-offer-builder-db.php` (DB_VERSION 0.7.0) — 5 tabel,
  kolumny/typy/klucze 1:1, w tym `client_vat_status` i indeks `updated_at`.
  Uwaga: `dbDelta` nie tworzy twardych kluczy obcych — relacje są logiczne.
- **Schemat techniczny**: transakcja obejmuje **wyłącznie Dział 10** (START
  przed D10, COMMIT przed D11, `mp_offer_created` PO COMMIT) — zgodnie z
  `set_transactional_from/until(10)`.
- **Instrukcje/„jak działa"**: odwrotne obciążenie z leada jest odłożone do
  rundy integracyjnej (P1 emituje `vat_status='checked'`, D6 wymaga `'valid'`) —
  ręczna oferta włącza je checkboxem „VAT UE potwierdzony".

## Budowanie

```bash
./build.sh          # → ./out (9 plików)
./build.sh deploy   # + kopia do ../../paczka-klienta/materialy i ~/Pulpit/mp-offer-builder-materialy
```

Wymagania: `node`, `rsvg-convert` (librsvg), czcionki DejaVu Sans w systemie.
Katalog `out/` jest roboczy (nie wersjonowany).
