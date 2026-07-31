<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 5 — MASZYNA STATUSÓW.
Jeden plik na dział (zasada projektu).

ŹRÓDŁA OFICJALNE — dokumentacja techniczna narzędzi używanych przez ten dział:
1. match — PHP Manual, "Control Structures / match".
   URL:     https://www.php.net/manual/en/control-structures.match.php
   Pobrano: 2026-07-28.
2. current_time() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/functions/current_time/
   Pobrano: 2026-07-28.
3. wpdb::update() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/classes/wpdb/update/
   Pobrano: 2026-07-28.
4. in_array() — PHP Manual, "Function Reference / Array Functions".
   URL:     https://www.php.net/manual/en/function.in-array.php
   Pobrano: 2026-07-28.
   UWAGA: wtyczka deklaruje "Requires PHP: 7.4", a `match` istnieje dopiero od
   PHP 8.0. Słownik przejść sprawdzamy więc przez in_array() z włączonym
   trybem ścisłym — to daje tę samą własność, o którą chodzi w cytacie o
   `match`: porównanie typów, a nie luźne. Cytat o `match` zostaje, bo opisuje
   ZASADĘ (identyczność + twardy błąd zamiast cichego przejścia dalej).

Dotyczy par Działu 5:
 - A5.1 "przejście" / K5.1 "legalność-przejścia" — słownik dozwolonych przejść
   i odmowa przy przejściu spoza słownika (źródło 1: porównanie tożsamościowe
   `===` i twardy błąd przy braku dopasowania, zamiast cichego przejścia dalej),
 - A5.2 "skutki" / K5.2 "komplet-skutków" — wyliczenie nowego SLA wymaga
   jednego źródła czasu w GMT (źródło 2),
 - przygotowanie zmiany statusu wiersza procesu; sam zapis wykonuje Dział 8
   jedną transakcją (źródło 3).
-->

# Dział 5 — maszyna statusów: dokumentacja źródłowa

## Wyrażenie match — porównanie tożsamościowe (cytat, źródło 1)

"The match expression branches evaluation based on an identity check of a
value. Similarly to a switch statement, a match expression has a subject
expression that is compared against multiple alternatives. Unlike switch, it
will evaluate to a value much like ternary expressions. Unlike switch, the
comparison is an identity check (`===`) rather than a weak equality check
(`==`)."

## Brak dopasowania kończy się błędem, nie przemilczeniem (cytat, źródło 1)

"UnhandledMatchError is thrown."

```php
$condition = 5;

try {
    match ($condition) {
        1, 2 => foo(),
        3, 4 => bar(),
    };
} catch (\UnhandledMatchError $e) {
    var_dump($e);
}
```

## Sprawdzenie wartości w słowniku — tryb ścisły (cytaty, źródło 4)

Sygnatura: `in_array(mixed $needle, array $haystack, bool $strict = false): bool`

"Checks if a value exists in an array. Searches for needle in haystack using
loose comparison unless strict is set."

"If the third parameter strict is set to true then the in_array() function will
also check the types of the needle in the haystack."

"Note: Prior to PHP 8.0.0, a string needle will match an array value of 0 in
non-strict mode, and vice versa. That may lead to undesireable results."

## Jedno źródło czasu — current_time() (cytat, źródło 2)

"Retrieves the current time based on specified type. The ‘mysql’ type will
return the time in the format for MySQL DATETIME field. The ‘timestamp’ or ‘U’
types will return the current timestamp or a sum of timestamp and timezone
offset, depending on $gmt. Other strings will be interpreted as PHP date
formats (e.g. ‘Y-m-d’). If $gmt is a truthy value then both types will use GMT
time."

Sygnatura (cytat): `current_time( string $type, int|bool $gmt = false ): int|string`

## Aktualizacja wiersza — wpdb::update() (cytat, źródło 3)

"Updates a row in the table."

```php
$wpdb->update(
	'table',
	array(
		'column1' => 'foo',
		'column2' => 1337,
	),
	array( 'ID' => 1 ),
	array( '%s', '%d' ),
	array( '%d' )
);
```
