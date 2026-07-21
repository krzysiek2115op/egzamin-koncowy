<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/functions/current_time/
Pobrano: 2026-07-21
Dotyczy: Dział 11 — agent 11.3 (znacznik zakończenia, czas trwania).
-->

# Źródła WordPress dla działu 11

## current_time()

```php
current_time( string $type, bool $gmt = false ): int|string
```

Zwraca bieżący czas wg typu: `'mysql'` (format DATETIME), `'timestamp'`/`'U'` (unix),
lub format daty PHP. `$gmt=true` → czas GMT.

Użycie: `finished_at = current_time('mysql')`; czas trwania liczony względem
`started_at` z działu 9.
