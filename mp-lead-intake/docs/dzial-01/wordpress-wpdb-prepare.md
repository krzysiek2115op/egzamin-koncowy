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
