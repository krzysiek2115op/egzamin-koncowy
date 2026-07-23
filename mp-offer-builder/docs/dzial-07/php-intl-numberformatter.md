<!--
ŹRÓDŁO OFICJALNE — PHP Manual, klasa NumberFormatter (rozszerzenie intl).
URL:     https://www.php.net/manual/en/numberformatter.format.php
Pobrano: 2026-07-24.
Dotyczy: Dział 7 — Agent 7.2 "scalenie" (MP_OB_D7_Agent_Merge::format_money()),
formatowanie kwot wg konwencji językowej dokumentu (pl / en).
-->

# NumberFormatter::format() — dokumentacja źródłowa

## Sygnatura (cytat)

```
public NumberFormatter::format(int|float $num, int $type = NumberFormatter::TYPE_DEFAULT): string|false
```

## Opis (cytat)

"Format a numeric value according to the formatter rules."

## Przykład z dokumentacji (lokalizacja wpływa na separatory)

```php
$fmt = new NumberFormatter( 'de_DE', NumberFormatter::DECIMAL );
var_dump( $fmt->format( 1234567.891234567890000 ) );
// string(13) "1.234.567,891"
```

Dokumentacja pokazuje wprost, że separator dziesiętny i tysięczny zależą od
locale — `de_DE` odwraca konwencję znaną z `en_US` (kropka/przecinek zamienione
rolami). To samo zjawisko dotyczy `pl_PL` (przecinek dziesiętny, spacja jako
separator tysięcy — "1 234,56") kontra `en_US`/`en_GB` (kropka dziesiętna,
przecinek tysięczny — "1,234.56"), co wprost cytuje diagram blueprintu Działu 7.

## Zastosowanie w tym dziale

`MP_OB_D7_Agent_Merge::format_money()` używa `NumberFormatter` (locale `pl_PL`
dla `lang=pl`, `en_US` dla `lang=en`), gdy rozszerzenie `intl` jest dostępne
(`class_exists('NumberFormatter')`). Środowisko testowe harnessu (`tests/
process-harness`) NIE ma rozszerzenia `intl` (potwierdzone: `php -m` bez
wpisu `intl`) — dlatego metoda ma jawny FALLBACK bez `intl`: `number_format()`
z ręcznie dobranymi separatorami wg tej samej konwencji (`,`+spacja dla pl,
`.`+przecinek dla en), analogicznie do fallbacku BCMath w Dziale 4/6
(docs/dzial-04/php-bcmath.md) — ta sama zasada: brak rozszerzenia PHP nie
może być fatalnym błędem, tylko kontrolowanym zapasowym torem tej samej
logiki. WAŻNE zastrzeżenie: to formatowanie jest WYŁĄCZNIE prezentacyjne
(tekst do PDF) — nie zasila już żadnej dalszej arytmetyki; kwoty w groszach
(`net_grosze`/`vat_grosze`/`gross_grosze`) pozostają jedynym źródłem prawdy
liczbowej aż do końca pipeline'u (zasada zero-float z Działu 4 dotyczy
KALKULACJI, nie formatowania wyjścia).
