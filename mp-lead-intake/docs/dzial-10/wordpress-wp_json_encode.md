<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/functions/wp_json_encode/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 10 — agent 10.3 (finalizacja payloadu JSON).
-->

# wp_json_encode() — dokumentacja oficjalna

## Sygnatura

```php
wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false
```

## Opis (cytat)

"Encodes a variable into JSON, with some confidence checks."

## Parametry (cytaty)

- `$value` (mixed, wymagany) — "Variable (usually an array or object) to encode as JSON."
- `$flags` (int, opcjonalny) — "Options to be passed to json_encode()." Domyślnie: `0`.
- `$depth` (int, opcjonalny) — "Maximum depth to walk through $value. Must be greater
  than 0." Domyślnie: `512`.

## Zwraca (cytat)

"The JSON encoded string, or false if it cannot be encoded."

## Przykład

```php
$out = array( 'options' => array( 'option-1', 'option-2' ), 'content' => 'content example' );
return wp_json_encode( $out );
```

## Uwaga
Od wersji 6.5.0 nazwy parametrów zmieniono z `$data`/`$options` na `$value`/`$flags`.
