<!--
ŹRÓDŁO OFICJALNE — RODO (RODO/GDPR), Rozporządzenie (UE) 2016/679.
Jeden plik na dział (zasada projektu).
Odniesienie: art. 6 ust. 1 lit. a) oraz art. 7 (warunki wyrażenia zgody).
Dotyczy: Dział 6 — zapis zgód (marketing + RODO) z dowodem zgody.
-->

# RODO — zgody (dokumentacja źródłowa)

Podstawa: **Rozporządzenie (UE) 2016/679 (RODO/GDPR)**.

## Zasady istotne dla działu 6
- **Art. 6 ust. 1 lit. a)** — przetwarzanie jest zgodne z prawem, jeśli osoba wyraziła
  **zgodę** na przetwarzanie swoich danych.
- **Art. 7** — administrator musi być w stanie **wykazać**, że osoba wyraziła zgodę
  (dowód zgody). Zgoda musi być dobrowolna, konkretna, świadoma i jednoznaczna.

## Konsekwencje w implementacji
- Zgoda **RODO jest wymagana** do dalszego przetwarzania (brak → STOP, dział 6).
- Zapisujemy **dowód zgody**: fakt zgody (`consent_rodo`), **znacznik czasu**
  (`consent_rodo_at`) oraz **wersję treści** zgody (`consent_version`).
- Zgoda **marketingowa** jest osobna i **opcjonalna** (`consent_marketing`,
  `consent_marketing_at`).

Kolumny w BD-3 (`wp_mp_leads`): `consent_marketing`, `consent_rodo`,
`consent_marketing_at`, `consent_rodo_at`, `consent_version`.

---

<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/functions/current_time/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 6 — agent 6.3 (znacznik czasu zgody).
-->

# current_time() — dokumentacja oficjalna

## Sygnatura

```php
current_time( string $type, bool $gmt = false ): int|string
```

## Opis (cytat)

"Retrieves the current time based on specified type."
- "The 'mysql' type will return the time in the format for MySQL DATETIME field."
- "The 'timestamp' or 'U' types will return the current timestamp or a sum of timestamp and
  timezone offset, depending on $gmt."
- "Other strings will be interpreted as PHP date formats (e.g. 'Y-m-d')."
- "If $gmt is a truthy value then both types will use GMT time, otherwise the output is
  adjusted with the GMT offset for the site."

## Parametry (cytaty z tabeli parametrów)

- `$type` (string, wymagany) — "Type of time to retrieve. Accepts 'mysql', 'timestamp', 'U',
  or PHP date format string (e.g. 'Y-m-d')."
- `$gmt` (bool, opcjonalny, domyślnie `false`) — "Whether to use GMT timezone."

## Zwraca (cytat)

"Integer if $type is 'timestamp' or 'U', string otherwise."

## Przykład

```php
echo current_time( 'mysql' );        // czas lokalny strony
echo current_time( 'mysql', true );  // czas GMT
```
