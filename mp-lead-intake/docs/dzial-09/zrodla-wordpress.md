<!--
ŹRÓDŁA OFICJALNE (skopiowane wiernie)
- https://developer.wordpress.org/reference/classes/wpdb/insert/  (pobrano 2026-07-21)
- https://developer.wordpress.org/reference/functions/current_time/ (pobrano 2026-07-21)
Dotyczy: Dział 9 — agent 9.3 (zapis stanu) i 9.2 (znacznik czasu).
-->

# Źródła WordPress dla działu 9

## wpdb::insert()

```php
wpdb::insert( string $table, array $data, string[]|string $format = null ): int|false
```
Wstawia wiersz do tabeli, automatycznie sanityzując dane. `$data` = pary „kolumna => wartość"
(`null` → NULL). Zwraca liczbę wstawionych wierszy lub `false`; ID: `$wpdb->insert_id`.

## current_time()

```php
current_time( string $type, bool $gmt = false ): int|string
```
Zwraca bieżący czas wg typu: `'mysql'` (format DATETIME), `'timestamp'`/`'U'` (unix),
lub format daty PHP. `$gmt=true` → czas GMT.
