<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/classes/wpdb/insert/
Pobrano: 2026-07-21
Dotyczy: Dział 8 — agent 8.3 (zapis wpisu do wp_mp_activity_log).
-->

# wpdb::insert() — dokumentacja oficjalna

## Sygnatura

```php
wpdb::insert( string $table, array $data, string[]|string $format = null ): int|false
```

## Opis

Wstawia wiersz do tabeli; automatycznie sanityzuje dane.

## Parametry

- `$table` (string, wymagany) — nazwa tabeli.
- `$data` (array, wymagany) — pary „kolumna => wartość" (surowe). `null` → NULL.
- `$format` (string[]|string, opcjonalny) — `'%d'`, `'%f'`, `'%s'`.

## Zwraca

Liczba wstawionych wierszy lub `false`. ID: `$wpdb->insert_id`.

## Przykład

```php
$wpdb->insert( 'my_table', array( 'name' => 'John', 'age' => 30 ), array( '%s', '%d' ) );
$id = $wpdb->insert_id;
```
