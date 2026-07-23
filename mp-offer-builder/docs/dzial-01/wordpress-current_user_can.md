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
