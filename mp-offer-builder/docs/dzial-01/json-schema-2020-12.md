<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z json-schema.org)
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
