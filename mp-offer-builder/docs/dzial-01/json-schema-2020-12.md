<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z json-schema.org)
Jeden plik na dział (zasada projektu).
URL:    https://json-schema.org/draft/2020-12/json-schema-core
Pobrano: 2026-07-24
Dotyczy: Dział 1 — Agent 1.1 "kontrakt" (MP_OB_D1_Agent_Contract), koncepcja walidacji
         schematu wejścia (whitelist kluczy client/items, "pola spoza schematu odrzucone
         jawnie" = analog additionalProperties:false).
-->

# JSON Schema 2020-12 (Core) — dokumentacja oficjalna

## Abstrakt (cytat)

"JSON Schema defines the media type 'application/schema+json', a JSON-based format for
describing the structure of JSON data. JSON Schema asserts what a JSON document must look
like, ways to extract information from it, and how to interact with it."

## Keyword independence — additionalProperties (cytat, §10.1)

Specyfikacja wymienia `additionalProperties` jako jeden z wyjątków od niezależności słów
kluczowych schematu: "additionalProperties, whose behavior is defined in terms of
'properties' and 'patternProperties'".

Innymi słowy: `additionalProperties` waliduje te właściwości obiektu, które NIE zostały
jawnie objęte przez `properties` ani `patternProperties` — ustawienie go na `false`
odrzuca każdą właściwość spoza jawnie wymienionej listy.

## Zastosowanie w tym dziale

Kontrakt żądania (`{"input": {"client": {...}, "items": [...], "wariant": "...", "lang": "..."}}`)
nie jest walidowany formalnym schematem JSON Schema (biblioteka walidatora to zależność,
której ten projekt świadomie unika dla jednego prostego kontraktu — patrz Golden Rule
o unikaniu zbędnych abstrakcji), ale Agent 1.1 odwzorowuje TĘ SAMĄ zasadę ręcznie w PHP:
`array_intersect_key()` na dozwolonej liście kluczy `client`/`items` = odpowiednik
`additionalProperties: false` — pola spoza schematu są jawnie odrzucane, nie ciche
przepuszczane dalej.

---

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

---

<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/functions/current_user_can/
Pobrano: 2026-07-24
Dotyczy: Dział 1 — Agent 1.2 "uprawnienie" (MP_OB_D1_Agent_Permission), weryfikacja
         capability 'mp_offer_builder_manage_offers' przed dopuszczeniem do pipeline'u.
-->

# current_user_can() — dokumentacja oficjalna

## Sygnatura

```php
current_user_can( string $capability, mixed $args ): bool
```

## Opis (cytat)

"Returns whether the current user has the specified capability."

Dodatkowo (cytat): "The function also accepts an ID of an object to check against if the
capability is a meta capability. Meta capabilities such as `edit_post` and `edit_user` are
capabilities used by the `map_meta_cap()` function to map to primitive capabilities that
a user or role has."

## Parametry (cytaty)

- `$capability` (string, wymagany) — "Capability name."
- `$args` (mixed, opcjonalny) — "Optional further parameters, typically starting with an
  object ID."

## Zwraca (cytat)

"bool — Whether the current user has the given capability. If `$capability` is a meta cap
and `$object_id` is passed, whether the current user has the given meta capability for the
given object."

## Ważne uwagi (cytaty)

- "While checking against particular roles in place of a capability is supported in part,
  this practice is discouraged as it may produce unreliable results."
- "Will always return true if the current user is a super admin, unless specifically denied."

## Zastosowanie w tym dziale

Zgodnie z drugą uwagą powyżej ("checking against roles... discouraged") Dział 1 sprawdza
WŁASNĄ capability (`mp_offer_builder_manage_offers`), nie rolę — capability jest przypisana
roli `administrator` przy aktywacji wtyczki (`mp_offer_builder_activate()`), analogicznie
do typowego wzorca "custom capability" w pluginach WP.
