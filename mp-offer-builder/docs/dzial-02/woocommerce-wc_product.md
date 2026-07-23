<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z woocommerce.github.io/code-reference)
URL:    https://woocommerce.github.io/code-reference/classes/WC-Product.html
Pobrano: 2026-07-24
Dotyczy: Dział 2 — Agent 2.1 "produkty" i Agent 2.2 "ceny" (MP_OB_D2_Agent_Products,
         MP_OB_D2_Agent_Prices) — odczyt WYŁĄCZNIE przez oficjalne API, nigdy
         surowym SQL po wp_postmeta (patrz uwaga w blueprint/LP2_diagram_wizualny.html,
         Dział 2: "ceny czyta się przez WC_Product / meta_lookup, nie surowym SQL —
         układ tabel zmienia się między wersjami / HPOS, stabilnym kontraktem jest API").
-->

# WC_Product — dokumentacja oficjalna (wybrane metody)

## Opis klasy (cytat)

"The WooCommerce product class handles individual product data." (od wersji 3.0.0)

## get_status()

"Get product status." Zwraca string (obsługuje konteksty 'view'/'edit'); wartość
`'publish'` = produkt opublikowany.

## is_purchasable()

"Returns false if the product cannot be bought."

## get_regular_price()

"Returns the product's regular price." Zwraca string z ceną (kontekst 'view'/'edit').

## get_sale_price()

"Returns the product's sale price." Zwraca string z ceną (kontekst 'view'/'edit').

## get_price()

"Returns the product's active price." (cena efektywna — regularna albo promocyjna,
gdy promocja aktywna).

## Zastosowanie w tym dziale

`wc_get_product( $id )` (funkcja pomocnicza WooCommerce, zwraca instancję `WC_Product`
albo `false`) działa identycznie dla produktu prostego I wariantu (ID wariantu zwraca
`WC_Product_Variation extends WC_Product`) — dlatego Agent 2.1/2.2 nie rozróżnia
przypadków `product_id` / `variation_id`, tylko wybiera właściwe ID do przekazania.
Agent 2.2 liczy `on_sale` samodzielnie (`sale_price < regular_price`) zamiast wołać
`get_price()`, żeby jawnie zapisać ŹRÓDŁO ceny (`sale`/`regular`) w snapshocie —
wymóg kryt. 5.3 diagramu ("źródło ceny... zapisane przy pozycji").
