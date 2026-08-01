<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
Jeden plik na dział (zasada projektu).
URL:    https://developer.wordpress.org/reference/functions/is_email/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 2 — agent 2.3 (walidacja formatu e-mail) i krytyk K2.3.
-->

# is_email() — dokumentacja oficjalna

## Sygnatura

```php
is_email( string $email, bool $deprecated = false ): string|false
```

## Opis (cytat)

"Verifies that an email is valid."

## Ograniczenia (cytaty)

"Does not grok i18n domains. Not RFC compliant." Funkcja "does not correctly test for
invalid characters."

## Parametry (cytaty)

- `$email` (string, wymagany) — "Email address to verify."
- `$deprecated` (bool, opcjonalny) — "Deprecated." Domyślnie: `false`.

## Zwraca (cytat)

"Valid email address on success, false on failure."

## Przykład

```php
if ( is_email( 'email@domain.com' ) ) {
	echo 'email address is valid.';
}
```

---

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

---

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
