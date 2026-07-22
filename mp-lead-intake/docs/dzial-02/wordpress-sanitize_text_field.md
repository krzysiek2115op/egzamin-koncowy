<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/functions/sanitize_text_field/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 2 — agent 2.2 (normalizacja pól tekstowych) i krytyk K2.2.
-->

# sanitize_text_field() — dokumentacja oficjalna

## Sygnatura

```php
sanitize_text_field( string $str ): string
```

## Opis (cytat)

"Sanitizes a string from user input or from the database."

Funkcja wykonuje (cytaty):
- "Checks for invalid UTF-8"
- "Converts single `<` characters to entities"
- "Strips all tags"
- "Removes line breaks, tabs, and extra whitespace"
- "Strips percent-encoded characters"

## Parametry (cytat)

- `$str` (string, wymagany) — "String to sanitize."

## Zwraca (cytat)

"Sanitized string."

## Przykład

```php
<?php sanitize_text_field( $str ) ?>
```
