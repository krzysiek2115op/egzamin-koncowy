<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.woocommerce.com oraz
kodu źródłowego WooCommerce na GitHub — jawnie oznaczone osobno poniżej)
URL 1:   https://developer.woocommerce.com/docs/extensions/core-concepts/wc-get-products/
URL 2:   https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php
Pobrano: 2026-07-24
Dotyczy: Krok 4.5 — MP_Offer_Builder_Admin::ajax_search_products() (panel wp-admin,
         NIE dział pipeline'u) — wyszukiwanie produktów do budowy oferty przez
         oficjalne API WooCommerce, zamiast wewnętrznej (niedokumentowanej)
         akcji woocommerce_json_search_products* rdzenia WC.
-->

# wc_get_products() — dokumentacja oficjalna (wybrane fragmenty)

## Opis (cytat, developer.woocommerce.com)

"wc_get_products() [jest] standardem do pobierania produktów, który jest bezpieczny
w użyciu" i "nie ulegnie awarii w przyszłych wersjach WooCommerce'a" — "najlepsza
praktyka dla programistów wtyczek i motywów do pobierania wielu produktów".

## Parametry użyte w tym pliku (cytat)

- `status` — "Akceptuje string lub tablicę: 'draft', 'pending', 'private', 'publish'
  lub status niestandardowy."
- `limit` — "Przyjmuje liczbę całkowitą: maksymalna liczba wyników do pobrania lub
  -1 dla nieograniczonego."

## Zwracane wartości (cytat)

"Standardowo zwraca obiekty produktów [WC_Product]." (parametr `return` domyślnie
`'objects'`; z `'return' => 'ids'` zwróciłby same ID — tu NIE używane, bo Agent
potrzebuje `get_name()`/`get_id()` do wyniku wyszukiwania).

## Parametr 's' (wyszukiwanie tekstowe) — potwierdzone w kodzie źródłowym

Oficjalna strona developer.woocommerce.com nie wymienia jawnie klucza `'s'` w
tabeli parametrów, ale jest to udokumentowany w kodzie, standardowy przelot do
zapytania WordPressa: `WC_Product_Data_Store_CPT::get_wp_query_args()` mapuje
jawnie tylko wybrane klucze (`status`→`post_status`, `page`→`paged`,
`include`→`post__in`, ...) — `'s'` NIE jest w tej tabeli mapowań, więc trafia
bez zmian do `parent::get_wp_query_args()` (`WC_Data_Store_WP`), który przekazuje
go dalej jako standardowy `WP_Query` `'s'` (wyszukiwanie pełnotekstowe po tytule
posta — tu: nazwie produktu). Ten sam mechanizm, którego WooCommerce używa
wewnętrznie do wyszukiwania produktów po nazwie w panelu administracyjnym.

## Zastosowanie w tym pliku

`wc_get_products( array( 's' => $term, 'status' => 'publish', 'limit' => 20 ) )` —
wyłącznie opublikowane produkty, maks. 20 wyników, wyszukiwanie po nazwie. Zwrócone
obiekty `WC_Product` (już udokumentowane w `woocommerce-wc_product.md`: `get_id()`
nie jest cytowany osobno, ale `get_name()`/`get_regular_price()` są tym samym,
oficjalnym API co w Agencie 2.1/2.2 pipeline'u — tu użyte w panelu wp-admin do
wyświetlenia wyniku wyszukiwania handlowcowi, PRZED uruchomieniem pipeline'u.
