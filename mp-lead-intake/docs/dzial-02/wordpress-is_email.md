<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/functions/is_email/
Pobrano: 2026-07-21
Dotyczy: Dział 2 — agent 2.3 (walidacja formatu e-mail) i krytyk K2.3.
-->

# is_email() — dokumentacja oficjalna

## Sygnatura

```php
is_email( string $email, bool $deprecated = false ): string|false
```

## Opis

"Verifies that an email is valid." — sprawdza, czy adres e-mail jest poprawny.
Uwaga z dokumentacji: "Does not grok i18n domains. Not RFC compliant." (nie obsługuje
domen i18n, nie jest w pełni zgodna z RFC).

## Parametry

- `$email` (string, wymagany) — adres e-mail do weryfikacji.
- `$deprecated` (bool, opcjonalny) — parametr przestarzały. Domyślnie: `false`.

## Zwraca

Poprawny adres e-mail (string) przy sukcesie, albo `false` przy niepowodzeniu.

## Przykład

```php
if ( is_email( 'email@domain.com' ) ) {
	echo 'email address is valid.';
}
```

## Ograniczenie (z dokumentacji)

Funkcja "does not correctly test for invalid characters" i nie odróżnia niektórych
niepoprawnych formatów, np. "123.dot@domain.com".
