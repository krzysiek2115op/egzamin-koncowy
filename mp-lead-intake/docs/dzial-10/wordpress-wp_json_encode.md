<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://developer.wordpress.org/reference/functions/wp_json_encode/
Pobrano: 2026-07-21
Dotyczy: Dział 10 — agent 10.3 (finalizacja payloadu JSON).
-->

# wp_json_encode() — dokumentacja oficjalna

## Sygnatura

```php
wp_json_encode( mixed $value, int $flags, int $depth = 512 ): string|false
```

## Opis

"Encodes a variable into JSON, with some confidence checks." — koduje zmienną do JSON
z dodatkowymi kontrolami.

## Parametry

- `$value` (mixed, wymagany) — zmienna (zwykle tablica/obiekt) do zakodowania.
- `$flags` (int, opcjonalny) — opcje przekazywane do `json_encode()`. Domyślnie: 0.
- `$depth` (int, opcjonalny) — maksymalna głębokość (>0). Domyślnie: 512.

## Zwraca

Łańcuch JSON przy powodzeniu, albo `false` przy błędzie.

## Przykład

```php
$out = array( 'options' => array( 'option-1', 'option-2' ), 'content' => 'content example' );
return wp_json_encode( $out );
```

## Uwaga
Od wersji 6.5.0 nazwy parametrów zmieniono z `$data`/`$options` na `$value`/`$flags`.
