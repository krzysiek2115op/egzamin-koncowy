<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
Jeden plik na dział (zasada projektu).
URL:    https://developer.wordpress.org/reference/functions/set_transient/
        https://developer.wordpress.org/reference/functions/get_transient/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 5 — agent 5.3 (rate limit); także cache w dziale 3.
-->

# set_transient() / get_transient() — dokumentacja oficjalna

## set_transient()

### Sygnatura

```php
set_transient( string $transient, mixed $value, int $expiration = 0 ): bool
```

### Opis (cytat)

"Sets/updates the value of a transient."

### Parametry (cytaty)

- `$transient` (string, wymagany) — "Transient name. Expected to not be SQL-escaped.
  Must be 172 characters or fewer in length."
- `$value` (mixed, wymagany) — "Transient value. Must be serializable if non-scalar.
  Expected to not be SQL-escaped."
- `$expiration` (int, opcjonalny) — "Time until expiration in seconds. Default 0
  (no expiration)."

### Zwraca (cytat)

"True if the value was set, false otherwise."

### Uwagi (cytaty)

"Transients that never expire are autoloaded, whereas transients with an expiration time
are not autoloaded." Dodatkowo: "If a transient exists, this function will update the
transient's expiration time."

### Przykład

```php
set_transient( 'latest_posts', $posts_array, DAY_IN_SECONDS );
```

## get_transient()

### Sygnatura

```php
get_transient( string $transient ): mixed
```

### Opis (cytat)

"Retrieves the value of a transient."

### Parametry (cytat)

- `$transient` (string, wymagany) — "Transient name. Expected to not be SQL-escaped."

### Zwraca (cytat)

"Value of transient."

---

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
