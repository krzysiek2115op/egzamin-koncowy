<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/functions/wp_verify_nonce/
Pobrano: 2026-07-21
Dotyczy: Dział 5 — agent 5.2 (ochrona CSRF).
-->

# wp_verify_nonce() — dokumentacja oficjalna

## Sygnatura

```php
wp_verify_nonce( string $nonce, string|int $action = -1 ): int|false
```

## Opis

"Verifies that a correct security nonce was used with time limit." — weryfikuje poprawny
nonce (token bezpieczeństwa, ochrona CSRF) z limitem czasu. Nonce jest ważny domyślnie
12–24 godziny. Dokumentacja ostrzega: "nonces should never be relied on for authentication
or authorization, access control" — do autoryzacji używaj `current_user_can()`.

## Parametry

- `$nonce` (string, wymagany) — weryfikowana wartość nonce (zwykle z pola formularza).
- `$action` (string|int, opcjonalny) — kontekst akcji; musi zgadzać się z użytym przy
  tworzeniu nonce (domyślnie: -1).

## Zwraca

- **1** — nonce poprawny, utworzony 0–12 h temu,
- **2** — nonce poprawny, utworzony 12–24 h temu,
- **false** — nonce niepoprawny.

## Przykład

```php
$nonce = $_REQUEST['_wpnonce'];
if ( ! wp_verify_nonce( $nonce, 'my-nonce' ) ) {
    die( __( 'Security check', 'textdomain' ) );
} else {
    // kontynuuj akcję
}
```
