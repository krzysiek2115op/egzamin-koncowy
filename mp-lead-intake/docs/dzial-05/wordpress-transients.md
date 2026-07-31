<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
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
