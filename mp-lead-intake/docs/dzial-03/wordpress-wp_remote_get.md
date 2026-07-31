<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/functions/wp_remote_get/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 3 — agenci 3.2 i 3.3 (wywołania HTTP do VIES / Białej listy).
-->

# wp_remote_get() — dokumentacja oficjalna

## Sygnatura

```php
wp_remote_get( string $url, array $args = array() ): array|WP_Error
```

## Opis (cytat)

"Performs an HTTP request using the GET method and returns its response."

## Parametry (cytaty)

- `$url` (string, wymagany) — "URL to retrieve."
- `$args` (array, opcjonalny) — "Request arguments. See WP_Http::request() for information
  on accepted arguments." Domyślnie: `array()`.

## Zwraca (cytat)

"The response or WP_Error on failure. See WP_Http::request() for information on return value."

## Przykład

```php
$response = wp_remote_get( 'https://example.com/api' );
if ( is_wp_error( $response ) ) {
	$error_message = $response->get_error_message();
} else {
	$body = wp_remote_retrieve_body( $response );
}
```
