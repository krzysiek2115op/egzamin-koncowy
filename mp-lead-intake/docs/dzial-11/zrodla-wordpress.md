<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/functions/current_time/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 11 — agent 11.3 (znacznik zakończenia, czas trwania).
-->

# Źródła WordPress dla działu 11

## current_time()

Sygnatura:
```php
current_time( string $type, bool $gmt = false ): int|string
```

Opis (cytat): "Retrieves the current time based on specified type."
- "The 'mysql' type will return the time in the format for MySQL DATETIME field."
- "The 'timestamp' or 'U' types will return the current timestamp or a sum of timestamp and timezone
  offset, depending on $gmt."
- "Other strings will be interpreted as PHP date formats (e.g. 'Y-m-d')."
- "If $gmt is a truthy value then both types will use GMT time, otherwise the output is adjusted with
  the GMT offset for the site."

Parametry (cytaty z tabeli parametrów):
- `$type` (string, wymagany) — "Type of time to retrieve. Accepts 'mysql', 'timestamp', 'U', or PHP
  date format string (e.g. 'Y-m-d')."
- `$gmt` (bool, opcjonalny, domyślnie `false`) — "Whether to use GMT timezone."

Zwraca (cytat): "Integer if $type is 'timestamp' or 'U', string otherwise."

Użycie w tym dziale: `finished_at = current_time('mysql')`; czas trwania liczony względem
`started_at` z działu 9.
