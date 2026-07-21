<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, bez zmian merytorycznych)
URL:    https://developer.wordpress.org/reference/classes/wpdb/get_results/
Pobrano: 2026-07-21
Dotyczy: Dział 1 — agenci 1.1/1.2/1.3 (odczyt z BD-3) i ich krytycy.
-->

# wpdb::get_results() — dokumentacja oficjalna

## Sygnatura

```php
public function get_results( $query = null, $output = OBJECT ): array|object|null
```

## Opis

"Executes a SQL query and returns the entire SQL result" — wykonuje zapytanie SQL
i zwraca cały zbiór wyników (może zawierać wiele wierszy).

## Parametry

**`$query`** (string, opcjonalny)
- Zapytanie SQL do wykonania.
- Domyślnie: `null`.

**`$output`** (string, opcjonalny) — format zwracanych danych, jedna ze stałych:
- `ARRAY_A` — tablica tablic asocjacyjnych (kolumna => wartość),
- `ARRAY_N` — tablica tablic indeksowanych numerycznie (0 => wartość),
- `OBJECT` — tablica obiektów z właściwościami dla każdej kolumny,
- `OBJECT_K` — tablica asocjacyjna obiektów-wierszy, kluczowana wartością pierwszej
  kolumny; duplikaty kluczy są odrzucane.
- Domyślnie: `OBJECT`.

## Zwraca

`array|object|null` — wyniki zapytania, lub `null`, gdy nie podano zapytania.

## Przykład

```php
global $wpdb;
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT count( ID ) as total FROM {$wpdb->prefix}your_table WHERE field=%d",
        $some_parameter
    )
);
```
