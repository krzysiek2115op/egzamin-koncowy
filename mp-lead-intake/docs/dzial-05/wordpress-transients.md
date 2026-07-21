<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/functions/set_transient/
Pobrano: 2026-07-21
Dotyczy: Dział 5 — agent 5.3 (rate limit); także cache w dziale 3.
-->

# set_transient() / get_transient() — dokumentacja oficjalna

## Sygnatura

```php
set_transient( string $transient, mixed $value, int $expiration ): bool
```

## Opis

Ustawia lub aktualizuje wartość transientu (z automatyczną serializacją). Wartości nieskalarne
muszą być serializowalne.

## Parametry

- `$transient` (string, wymagany) — nazwa transientu; maks. 172 znaki.
- `$value` (mixed, wymagany) — wartość (serializowalna, jeśli nieskalarna).
- `$expiration` (int, opcjonalny) — czas do wygaśnięcia w sekundach; domyślnie 0 (bez wygaśnięcia).

## Zwraca

`true` przy powodzeniu, `false` w przeciwnym razie.

## Uwagi

- Transienty z wygaśnięciem nie są autoloadowane; bez wygaśnięcia — są.
- Jeśli transient istnieje, funkcja aktualizuje czas wygaśnięcia.
- Stałe czasu WordPress (`MINUTE_IN_SECONDS`, `HOUR_IN_SECONDS`, `DAY_IN_SECONDS`) upraszczają zapis.

## Przykład

```php
set_transient( 'latest_posts', $posts_array, DAY_IN_SECONDS );
```

## Powiązana funkcja

`get_transient()` — pobiera wcześniej zapisaną wartość transientu (lub `false`, gdy brak/wygasł).
