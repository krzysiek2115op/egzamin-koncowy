<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://ec.europa.eu/taxation_customs/vies/  (REST API)
Pobrano: 2026-07-21
Dotyczy: Dział 3 — agent 3.2 (weryfikacja VAT UE).
-->

# VIES REST API — weryfikacja numeru VAT UE (dokumentacja oficjalna)

## Endpoint

```
GET /taxation_customs/vies/rest-api/ms/{countryCode}/vat/{vatNumber}
```
Host: `https://ec.europa.eu`

- `{countryCode}` — dwuliterowy kod państwa UE (np. `PL`).
- `{vatNumber}` — numer VAT (dla PL: 10 cyfr NIP).

## Odpowiedź (JSON) — kluczowe pola

| Pole | Typ | Opis |
|------|-----|------|
| `isValid` | boolean | Czy numer VAT jest ważny |
| `requestDate` | string | Znacznik czasu (ISO 8601) |
| `userError` | string | Kod błędu, gdy walidacja się nie powiedzie (np. `INVALID`) |
| `name` | string | Nazwa firmy (`---` gdy niedostępna) |
| `address` | string | Adres firmy (`---` gdy niedostępny) |
| `vatNumber` | string | Zweryfikowany numer VAT |
| `originalVatNumber` | string | Przesłany numer VAT |

Gdy numer jest niepoprawny → `"isValid": false` oraz wartości `---` w polach firmy.

## Zastosowanie w dziale 3
Agent 3.2 wywołuje endpoint dla `PL` (lub kraju z kontekstu), z **timeoutem**,
**cache** (transient) i **łagodnym fallbackiem** — awaria VIES nie zatrzymuje pipeline
(wynik „nieustalony"), a jednoznaczny `isValid=false` → STOP (krytyk 3.2).
