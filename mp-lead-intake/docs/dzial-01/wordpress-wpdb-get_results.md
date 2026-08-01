<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
Jeden plik na dział (zasada projektu).
URL:    https://developer.wordpress.org/reference/classes/wpdb/get_results/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 1 — agenci 1.1/1.2/1.3 (odczyt z BD-3) i ich krytycy.
-->

# wpdb::get_results() — dokumentacja oficjalna

## Sygnatura

```php
public function get_results( $query = null, $output = OBJECT ): array|object|null
```

## Opis (cytat)

"Executes a SQL query and returns the entire SQL result."

## Parametry (cytaty z tabeli parametrów)

- `$query` (string, opcjonalny) — "SQL query." Domyślnie: `null`.
- `$output` (string, opcjonalny) — "Any of ARRAY_A | ARRAY_N | OBJECT | OBJECT_K constants."
  Domyślnie: `OBJECT`. Znaczenie poszczególnych stałych (cytaty):
  - `ARRAY_A` — "an associative array (column => value, …)"
  - `ARRAY_N` — "a numerically indexed array (0 => value,…)"
  - `OBJECT` — "an object ( ->column = value )"
  - `OBJECT_K` — "return an associative array of row objects keyed by the value of
    each row's first column's value. Duplicate keys are discarded."

## Zwraca (cytat)

"Database query results." Typ zwracany: `array|object|null`, zależnie od `$output` i wyniku
zapytania.

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

---

<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/classes/wpdb/prepare/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 1 — bezpieczne budowanie zapytań (agenci 1.1/1.2/1.3 i krytycy).
-->

# wpdb::prepare() — dokumentacja oficjalna

## Sygnatura

```php
wpdb::prepare( string $query, mixed $args ): string|void
```

## Opis (cytat)

"Prepares a SQL query for safe execution. Uses `sprintf()`-like syntax. The following
placeholders can be used in the query string:

* `%d` (integer)
* `%f` (float)
* `%s` (string)
* `%i` (identifier, e.g. table/field names)

All placeholders MUST be left unquoted in the query string. A corresponding argument MUST
be passed for each placeholder."

## Parametry (cytaty)

- `$query` (string, wymagany) — "Query statement with `sprintf()`-like placeholders."
- `$args` (mixed, wymagany) — "Further variables to substitute into the query's placeholders
  if being called with individual arguments."

## Zwraca (cytat)

"Sanitized query string, if there is a query to prepare."

## Przykład

```php
$wpdb->prepare(
    "SELECT * FROM `table` WHERE `column` = %s AND `field` = %d OR `other_field` LIKE %s",
    array( 'foo', 1337, '%bar' )
);
```
