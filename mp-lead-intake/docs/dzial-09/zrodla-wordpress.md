<!--
ŹRÓDŁA OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
- https://developer.wordpress.org/reference/classes/wpdb/insert/  (pobrano 2026-07-21, ponownie zweryfikowano 2026-07-22)
- https://developer.wordpress.org/reference/functions/current_time/ (pobrano 2026-07-21, ponownie zweryfikowano 2026-07-22)
Dotyczy: Dział 9 — agent 9.3 (zapis stanu) i 9.2 (znacznik czasu).
-->

# Źródła WordPress dla działu 9

## wpdb::insert()

Sygnatura:
```php
wpdb::insert( string $table, array $data, string[]|string $format = null ): int|false
```

Opis (cytat): "Inserts a row into the table."

Parametry (cytaty z tabeli parametrów):
- `$table` (string, wymagany) — "Table name."
- `$data` (array, wymagany) — "Data to insert (in column => value pairs). Both $data columns and $data
  values should be "raw" (neither should be SQL escaped). Sending a null value will cause the column
  to be set to NULL – the corresponding format is ignored in this case."
- `$format` (string[]|string, opcjonalny, domyślnie `null`) — "An array of formats to be mapped to each
  of the value in $data. If string, that format will be used for all of the values in $data. A format
  is one of '%d', '%f', '%s' (integer, float, string). If omitted, all values in $data will be treated
  as strings unless otherwise specified in wpdb::$field_types."

Zwraca (cytat): "The number of rows inserted, or false on error."

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
