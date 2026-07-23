<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z php.net)
URL:    https://www.php.net/manual/en/function.bcmul.php
Pobrano: 2026-07-24
Dotyczy: Dział 4 — Agent 4.1 "jednostkowe" (MP_OB_D4_Agent_Unit_Price), przeliczenie
         ceny (string z WooCommerce) na grosze BEZ arytmetyki zmiennoprzecinkowej.
-->

# bcmul() — dokumentacja oficjalna

## Sygnatura

```php
bcmul( string $num1, string $num2, ?int $scale = null ): string
```

## Opis (cytat)

"Multiply two arbitrary precision numbers. Multiply num1 by num2."

## Parametry (cytaty)

- `num1` — "The left operand, as a string."
- `num2` — "The right operand, as a string."
- `scale` — "This parameter is used to set the number of digits after the decimal
  place in the result. If null, it will default to the default scale set with
  bcscale(), or fallback to the value of the bcmath.scale INI directive."

## Zwraca (cytat)

"Returns the result as a string."

## Zastosowanie w tym dziale

`WC_Product::get_regular_price()`/`get_sale_price()` zwracają cenę jako STRING
(np. `"129.99"`) — idealny wsad dla BCMath, które operuje na łańcuchach znaków,
nie na `float`. `bcmul( $price, '100', 0 )` daje grosze jako liczbę całkowitą BEZ
przejścia przez reprezentację zmiennoprzecinkową w ogóle — inaczej niż
`(int) ( (float) $price * 100 )`, gdzie np. `19.99 * 100` w IEEE-754 daje
`1998.9999999999998`, a rzutowanie na `int` obcina to do `1998` zamiast `1999`
(realny błąd o 1 grosz na pozycji). To dokładnie przypadek z gate'u Działu 4:
"0.1 + 0.2 ≠ 0.3 — float rozjeżdża grosze w PDF". Skala `0` jest bezpieczna, bo
ceny sklepowe mają maksymalnie 2 miejsca po przecinku (grosze to już najmniejsza
jednostka), więc `bcmul` nigdy nie musi zaokrąglać — tylko przesuwa przecinek.
