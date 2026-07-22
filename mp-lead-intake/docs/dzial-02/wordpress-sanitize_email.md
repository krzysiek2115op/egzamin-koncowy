<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/functions/sanitize_email/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 2 — agent 2.2 (normalizacja adresu e-mail).
-->

# sanitize_email() — dokumentacja oficjalna

## Sygnatura

```php
sanitize_email( string $email ): string
```

## Opis (cytat)

"Strips out all characters that are not allowable in an email."

## Parametry (cytaty)

- `$email` (string, wymagany) — "Email address to filter."

## Zwraca (cytat)

"Filtered email address."

## Uwagi (cytaty)

"This function uses a smaller allowable character set than the set defined by RFC 5322.
Some legal email addresses may be changed." Dozwolone znaki (wyrażenie regularne, cytat):
"Allowed character regular expression: `/[^a-z0-9+_.@-]/i`."

## Przykład

```php
<?php
$sanitized_email = sanitize_email( '     admin@example.com!     ' );
echo $sanitized_email; // 'admin@example.com'
?>
```
