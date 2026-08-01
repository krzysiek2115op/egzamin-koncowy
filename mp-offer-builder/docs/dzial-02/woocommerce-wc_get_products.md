<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.woocommerce.com oraz
Jeden plik na dział (zasada projektu).
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

Poprawka Golden Rule #2 (2026-07-24): poniższe sekcje "Opis"/"Parametry"/
"Zwracane wartości" wcześniej zawierały PARAFRAZĘ po polsku oznaczoną błędnie
jako "cytat" — naprawione na VERBATIM angielski cytat ze strony źródłowej,
z osobnym, jawnie oznaczonym tłumaczeniem (nie cytatem).

## Opis (cytat, verbatim, developer.woocommerce.com)

"`wc_get_products` and `WC_Product_Query` provide a standard way of
retrieving products that is safe to use and will not break due to database
changes in future WooCommerce versions."

Tłumaczenie (NIE cytat): `wc_get_products` i `WC_Product_Query` dają
standardowy, bezpieczny sposób pobierania produktów, który nie zepsuje się
na skutek zmian w bazie danych w przyszłych wersjach WooCommerce.

## Parametry użyte w tym pliku (cytat, verbatim)

- `status` — "Accepts a string or array of strings: one or more of
  `'draft'`, `'pending'`, `'private'`, `'publish'`, or a custom status."
- `limit` — "Accepts an integer: maximum number of results to retrieve or
  `-1` for unlimited. Default: site `posts_per_page` setting."

## Zwracane wartości (cytat, verbatim)

"Return type. Accepts a string: `'ids'` or `'objects'`. Default: `'objects'`."

Tłumaczenie (NIE cytat): parametr `return` domyślnie zwraca obiekty
`WC_Product` (`'objects'`); z `'return' => 'ids'` zwróciłby same ID — tu
NIE używane, bo Agent potrzebuje `get_name()`/`get_id()` do wyniku
wyszukiwania.

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

---

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

---

<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z woocommerce.github.io/code-reference)
URL:    https://woocommerce.github.io/code-reference/classes/WC-Tax.html
Pobrano: 2026-07-24
Dotyczy: Dział 2 — Agent 2.3 "podatki" (MP_OB_D2_Agent_Tax) — odczyt stawek VAT
         wyłącznie przez oficjalne API WC_Tax, wg klasy podatkowej produktu.
-->

# WC_Tax — dokumentacja oficjalna (wybrane metody)

## Opis klasy (cytat)

"Performs tax calculations and loads tax rates"

## get_rates() (cytat)

"Get's an array of matching rates for a tax class."

Sygnatura: `public static get_rates( string $tax_class = '', object $customer = null )`

## get_base_tax_rates() (cytat)

"Get's an array of matching rates for the shop's base country."

Sygnatura: `public static get_base_tax_rates( string $tax_class = '' )`

## find_rates() (cytat)

"Searches for all matching country/state/postcode tax rates."

Sygnatura: `public static find_rates( array $args = array() )`

## Zastosowanie w tym dziale

Agent 2.3 woła `WC_Tax::get_rates( $tax_class )` dla każdej UNIKALNEJ klasy podatkowej
występującej wśród pozycji żądania (nie osobno per produkt — jedna klasa podatkowa
= jedna stawka). Brak wyniku dla danej klasy = błąd (`missing_tax_rate`), NIGDY
podstawiana domyślna stawka (23%) — zgodnie z krytykiem "stawka-istnieje" z diagramu.
Mechanizm VAT (krajowa stawka / odwrotne obciążenie UE / poza zakresem VAT) to
osobna decyzja Działu 6 — Dział 2 dostarcza tylko SUROWĄ stawkę krajową jako dane
wejściowe do tamtej kalkulacji.
