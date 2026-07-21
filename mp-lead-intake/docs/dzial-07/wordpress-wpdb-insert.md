<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/classes/wpdb/insert/
Pobrano: 2026-07-21
Dotyczy: Dział 7 — agent 7.3 (zapis leada); także działy 8/9 (zapis logu).
-->

# wpdb::insert() — dokumentacja oficjalna

## Sygnatura

```php
wpdb::insert( string $table, array $data, string[]|string $format = null ): int|false
```

## Opis

Wstawia wiersz do tabeli. Metoda automatycznie sanityzuje dane (w przeciwieństwie do
`$wpdb->query()`, które wymaga `$wpdb->prepare()`).

## Parametry

- `$table` (string, wymagany) — nazwa tabeli.
- `$data` (array, wymagany) — pary „kolumna => wartość" (surowe, nieescapowane). Wartość
  `null` ustawia kolumnę na NULL (ignoruje format).
- `$format` (string[]|string, opcjonalny) — specyfikatory formatu: `'%d'`, `'%f'`, `'%s'`.

## Zwraca

Liczba wstawionych wierszy lub `false` przy błędzie. ID wstawionego wiersza:
`$wpdb->insert_id`.

## Przykład

```php
global $wpdb;
$wpdb->insert(
    'my_table',
    array( 'name' => 'John', 'age' => 30 ),
    array( '%s', '%d' )
);
$id = $wpdb->insert_id;
```
