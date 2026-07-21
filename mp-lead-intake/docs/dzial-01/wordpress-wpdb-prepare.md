<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, bez zmian merytorycznych)
URL:    https://developer.wordpress.org/reference/classes/wpdb/prepare/
Pobrano: 2026-07-21
Dotyczy: Dział 1 — bezpieczne budowanie zapytań (agenci 1.1/1.2/1.3 i krytycy).
-->

# wpdb::prepare() — dokumentacja oficjalna

## Sygnatura

```php
wpdb::prepare( string $query, mixed $args ): string|void
```

## Opis

"Prepares a SQL query for safe execution" — przygotowuje zapytanie SQL do bezpiecznego
wykonania. Metoda używa składni w stylu `sprintf()` z następującymi placeholderami:

- `%d` — liczba całkowita (integer),
- `%f` — liczba zmiennoprzecinkowa (float),
- `%s` — łańcuch znaków (string),
- `%i` — identyfikator (np. nazwa tabeli/pola).

"All placeholders MUST be left unquoted in the query string. A corresponding argument
MUST be passed for each placeholder." — placeholdery muszą pozostać bez cudzysłowów,
a dla każdego trzeba przekazać odpowiadający argument.

"Literal percentage signs (`%`) in the query string must be written as `%%`." — dosłowne
znaki procentu zapisujemy jako `%%`. Dla operacji `LIKE` znaki wieloznaczne (`%`) przekazuje
się przez argumenty podstawienia, a nie wpisuje wprost w zapytaniu.

## Parametry

| Parametr | Typ | Opis |
|----------|-----|------|
| `$query` | string | Zapytanie z placeholderami w stylu `sprintf()` |
| `$args`  | mixed | Zmienne podstawiane w miejsce placeholderów |

## Zwraca

Zsanityzowane zapytanie (string), lub `void`, gdy nie ma czego przygotować.

## Przykład

```php
$wpdb->prepare(
    "SELECT * FROM `table` WHERE `column` = %s AND `field` = %d OR `other_field` LIKE %s",
    array( 'foo', 1337, '%bar' )
);
```
