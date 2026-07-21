<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/functions/sanitize_text_field/
Pobrano: 2026-07-21
Dotyczy: Dział 2 — agent 2.2 (normalizacja pól tekstowych) i krytyk K2.2.
-->

# sanitize_text_field() — dokumentacja oficjalna

## Sygnatura

```php
sanitize_text_field( string $str ): string
```

## Opis

"Sanitizes a string from user input or from the database." — sanityzuje łańcuch
pochodzący od użytkownika lub z bazy. Funkcja wykonuje:

- "Checks for invalid UTF-8" — sprawdza niepoprawny UTF-8,
- "Converts single `<` characters to entities" — zamienia pojedyncze `<` na encje,
- "Strips all tags" — usuwa wszystkie tagi,
- "Removes line breaks, tabs, and extra whitespace" — usuwa złamania linii, tabulatory
  i nadmiarowe białe znaki,
- "Strips percent-encoded characters" — usuwa znaki zakodowane procentowo.

## Parametry

- `$str` (string, wymagany) — łańcuch do sanityzacji.

## Zwraca

Zsanityzowany łańcuch (string).

## Przykład

```php
<?php sanitize_text_field( $str ) ?>
```
