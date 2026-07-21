<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/functions/wp_remote_get/
Pobrano: 2026-07-21
Dotyczy: Dział 3 — agenci 3.2 i 3.3 (wywołania HTTP do VIES / Białej listy).
-->

# wp_remote_get() — dokumentacja oficjalna

## Sygnatura

```php
wp_remote_get( string $url, array $args = array() ): array|WP_Error
```

## Opis

"Performs an HTTP request using the GET method and returns its response." — wykonuje
żądanie HTTP metodą GET i zwraca odpowiedź.

**Uwaga bezpieczeństwa:** jeśli URL pochodzi od użytkownika, użyj `wp_safe_remote_get()`.

## Parametry

- `$url` (string, wymagany) — adres do pobrania.
- `$args` (array, opcjonalny) — argumenty żądania (`WP_Http::request()`), m.in.:
  - `timeout` — czas otwarcia połączenia w sekundach (domyślnie: 5),
  - `headers` — tablica nagłówków,
  - `sslverify` — weryfikacja SSL (domyślnie: true).

## Zwraca

Odpowiedź (tablica z `body`, `headers`, statusem) lub `WP_Error` przy błędzie.

## Przykład

```php
$response = wp_remote_get( 'http://www.example.com/index.html' );

if ( is_array( $response ) && ! is_wp_error( $response ) ) {
    $body = wp_remote_retrieve_body( $response );
    // użyj treści
}
```
