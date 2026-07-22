<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/functions/wp_verify_nonce/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 5 — agent 5.2 (ochrona CSRF).
-->

# wp_verify_nonce() — dokumentacja oficjalna

## Sygnatura

```php
wp_verify_nonce( string $nonce, string|int $action = -1 ): int|false
```

## Opis (cytat)

"Verifies that a correct security nonce was used with time limit."

## Parametry (cytaty)

- `$nonce` (string, wymagany) — "Nonce value that was used for verification, usually via
  a form field."
- `$action` (string|int, opcjonalny) — "Should give context to what is taking place and be
  the same when nonce was created." Domyślnie: `-1`.

## Zwraca (cytat)

"1 if the nonce is valid and generated between 0-12 hours ago, 2 if the nonce is valid and
generated between 12-24 hours ago. False if the nonce is invalid."

## Przykład

```php
$nonce = $_REQUEST['_wpnonce'];
if ( ! wp_verify_nonce( $nonce, 'my-nonce' ) ) {
    die( __( 'Security check', 'textdomain' ) );
} else {
    // kontynuuj akcję
}
```
