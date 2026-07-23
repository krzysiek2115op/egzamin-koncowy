<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/functions/check_ajax_referer/
Pobrano: 2026-07-24
Dotyczy: Dział 1 — Agent 1.2 "uprawnienie" / krytyk "kto-woła", weryfikacja CSRF
         w pre-gate class-mp-offer-builder-ajax.php przed uruchomieniem pipeline'u.
-->

# check_ajax_referer() — dokumentacja oficjalna

## Sygnatura

```php
check_ajax_referer( int|string $action = -1, false|string $query_arg = false, bool $stop = true ): int|false
```

## Opis (cytat)

"Verifies the Ajax request to prevent processing requests external of the blog."

## Parametry (cytaty)

- `$action` (int|string, opcjonalny) — "Action nonce." Domyślnie: `-1`.
- `$query_arg` (false|string, opcjonalny) — "Key to check for the nonce in `$_REQUEST`
  (since 2.5). If false, `$_REQUEST` values will be evaluated for `'_ajax_nonce'`, and
  `'_wpnonce'` (in that order)." Domyślnie: `false`.
- `$stop` (bool, opcjonalny) — "Whether to stop early when the nonce cannot be verified."
  Domyślnie: `true`.

## Zwraca (cytat)

"int|false 1 if the nonce is valid and generated between 0-12 hours ago, 2 if the nonce
is valid and generated between 12-24 hours ago. False if the nonce is invalid."

## Zastosowanie w tym dziale

`class-mp-offer-builder-ajax.php` wywołuje z `$stop = false` (własna obsługa błędu przez
`wp_send_json_error`, zamiast domyślnego `wp_die()`), a klucz nonce'a to `mp_ob_nonce`
(nie domyślny `_wpnonce`).
