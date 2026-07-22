<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
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
