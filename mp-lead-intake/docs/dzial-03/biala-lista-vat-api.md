<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://wl-api.mf.gov.pl/  (API Wykazu podatników VAT — Biała lista)
Pobrano: 2026-07-21
Dotyczy: Dział 3 — agent 3.3 (status firmy).
-->

# Biała lista VAT — API (dokumentacja oficjalna)

Host: `https://wl-api.mf.gov.pl`

## Endpoint — wyszukanie po NIP

```
GET /api/search/nip/{nip}?date=YYYY-MM-DD
```
- `{nip}` — numer NIP (10 cyfr).
- `date` (wymagany) — data w formacie `YYYY-MM-DD`.

Przykład: `/api/search/nip/1111111111?date=2019-11-19`

## Odpowiedź (JSON) — struktura

Dane opakowane w obiekt `result`:

- **`subject`** — obiekt podmiotu:
  - `name` — nazwa firmy/osoby,
  - `nip` — numer NIP,
  - `statusVat` — status VAT: **`Czynny`**, **`Zwolniony`**, **`Niezarejestrowany`**,
  - `regon`, `krs`, `accountNumbers`, `residenceAddress`, `workingAddress`.
- **`requestDateTime`** — znacznik czasu odpowiedzi,
- **`requestId`** — identyfikator zapytania.

## Zastosowanie w dziale 3
Agent 3.3 pobiera `statusVat` dla NIP na dzień bieżący, z **timeoutem**, **cache**
(transient) i **fallbackiem** (awaria → status „nieustalony", bez STOP). Status jest
informacyjny (nie przerywa pipeline).
