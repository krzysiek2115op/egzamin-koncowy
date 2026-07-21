<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/functions/sanitize_email/
Pobrano: 2026-07-21
Dotyczy: Dział 2 — agent 2.2 (normalizacja adresu e-mail).
-->

# sanitize_email() — dokumentacja oficjalna

## Sygnatura

```php
sanitize_email( string $email ): string
```

## Opis

"Strips out all characters that are not allowable in an email." — usuwa wszystkie znaki
niedozwolone w adresie e-mail.

## Parametry

- `$email` (string, wymagany) — adres e-mail do przefiltrowania.

## Zwraca

Przefiltrowany adres e-mail (string).

## Uwagi (z dokumentacji)

- Funkcja używa zestawu znaków bardziej restrykcyjnego niż RFC 5322 — może zmienić
  niektóre technicznie poprawne adresy.
- Dozwolone znaki wg wzorca: `/[^a-z0-9+_.@-]/i`.
- Dla niepoprawnego adresu zwraca pusty łańcuch (nie `false`).

## Przykład

```php
<?php
$sanitized_email = sanitize_email( '     admin@example.com!     ' );
echo $sanitized_email; // 'admin@example.com'
?>
```
