<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/functions/current_time/
Pobrano: 2026-07-21
Dotyczy: Dział 6 — agent 6.3 (znacznik czasu zgody).
-->

# current_time() — dokumentacja oficjalna

## Sygnatura

```php
current_time( string $type, bool $gmt = false ): int|string
```

## Opis

"Retrieves the current time based on specified type." — zwraca bieżący czas wg typu:
- `'mysql'` — czas w formacie MySQL DATETIME,
- `'timestamp'` / `'U'` — znacznik uniksowy (z uwzględnieniem offsetu GMT strony, chyba że `$gmt=true`),
- łańcuch formatu PHP (np. `'Y-m-d'`).

Gdy `$gmt` jest prawdziwe — czas w GMT; inaczej wg offsetu strony.

## Parametry

- `$type` (string, wymagany) — `'mysql'`, `'timestamp'`, `'U'` lub format daty PHP.
- `$gmt` (bool, opcjonalny) — czy GMT. Domyślnie `false`.

## Zwraca

Integer dla `'timestamp'`/`'U'`; string w pozostałych przypadkach.

## Przykład

```php
echo current_time( 'mysql' );        // czas lokalny strony
echo current_time( 'mysql', true );  // czas GMT
```
