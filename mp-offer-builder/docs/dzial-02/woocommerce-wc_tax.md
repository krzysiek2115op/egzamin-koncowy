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
