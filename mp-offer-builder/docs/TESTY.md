# Testy końcowe — MP Offer Builder (Plugin 2)

Testy na **żywym WordPressie + WooCommerce** (WordPress Playground CLI, realny
WP 7.x, PHP 8.3, WooCommerce z katalogu wordpress.org), przeprowadzone
2026-07-25 na wersji pluginu **1.0.1**.

Środowisko testowe: waluta PLN, baza sklepu PL, stawka VAT PL 23%, 3 produkty
proste (100,00 / 250,50 / 999,99 zł), rola testowa `mp_ob_test_handlowiec`
(capability `mp_offer_builder_manage_offers` bez `manage_options`) + userzy
`handlowiec_a`/`handlowiec_b` do scenariusza IDOR. Podgląd BD-2 przez pomocniczą
wtyczkę MP Test Viewer (read-only, nigdy nie pakowaną do klienta).

## Wynik: 12/12 scenariuszy PASS

| # | Scenariusz | Metoda | Wynik |
|---|---|---|---|
| 1 | Instalacja/aktywacja, 5 tabel BD-2, capability admina | przeglądarka | PASS |
| 2 | Nowa oferta krajowa PL (happy path), PDF, numeracja | przeglądarka | PASS |
| 3 | Odwrotne obciążenie (UE + ważny VAT) → VAT 0 | pipeline (żywe WC) | PASS |
| 4 | Poza zakresem VAT (spoza UE) → VAT 0 | przeglądarka | PASS |
| 5 | Błędny kod kraju → odrzucenie (`invalid_country`) | przeglądarka | PASS |
| 6 | Korekta oferty → wersja 2, ten sam numer, bez duplikatów | przeglądarka | PASS |
| 7 | Blokada optymistyczna (stary zapis odrzucony) | żywa baza | PASS |
| 8 | IDOR / własność oferty (obcy nie widzi/nie pobiera) | żywa baza | PASS |
| 9 | Pobieranie PDF (chroniony endpoint) | przeglądarka | PASS |
| 10 | Wyszukiwanie produktów (fraza / pusta) | przeglądarka | PASS |
| 11 | Numeracja bez kolizji (OF/2026/000001 → 000002) | przeglądarka | PASS |
| 12 | Log aktywności (offer.created / offer.versioned) | przeglądarka | PASS |

### Wybrane potwierdzenia liczbowe

- **S2:** `OF/2026/000001`, netto 100,00 / VAT 23,00 / brutto 123,00 zł,
  `tax_mechanism=domestic`, PDF 22 KB z poprawnymi polskimi znakami.
- **S3:** DE + `vat_status=valid` → `reverse_charge`, netto 200,00 / VAT **0**.
- **S4:** US → `out_of_scope`, VAT **0**.
- **S6:** korekta ilości 1→3 → wersja **2**, `lock_version` 2, brutto 369,00 zł,
  2 wpisy w `wp_mp_ob_offer_versions`, 1 pozycja (bez duplikatów).
- **S7:** `UPDATE ... WHERE lock_version=<stara>` → **0 wierszy** (odrzucony),
  z aktualną → 1 wiersz; dane nienadpisane przez stary zapis.
- **S8:** oferta handlowca A — A pobiera (true), **B (obcy) nie pobiera (false)**,
  admin pobiera (true, `manage_options`); lista B **nie** zawiera cudzej oferty.

## Błędy znalezione i naprawione podczas testów żywych

Wszystkie były niewykrywalne w harnessie ze stubami (nie renderuje `WP_List_Table`,
a stub `WC_Tax` nie odtwarzał lokalizacyjnej logiki stawek):

1. **[Krytyczny]** Dział 2 pobierał stawkę VAT przez `WC_Tax::get_rates()`
   (zależne od lokalizacji klienta/sesji) — po stronie serwera zwracało pustkę,
   więc każda oferta padała z `missing_tax_rate`. Zmiana na
   `WC_Tax::get_base_tax_rates()` (deterministycznie z bazy sklepu). Stub w
   harnessie odwzorowuje teraz to zachowanie, by łapać regresję.
2. **[Krytyczny]** Świeża instalacja nie miała żadnego szablonu oferty, a nic go
   nie tworzyło → żadna oferta nie mogła powstać. Dodane seedowanie domyślnych
   szablonów PL+EN w `install()` (idempotentne, DB_VERSION 0.5.0).
3. Kolumna „Akcje" w liście ofert renderowała się pusta — metoda
   `column_actions()` była `private`, niewidoczna dla `WP_List_Table`. → `public`.
4. Ekran „Edytuj" nie wczytywał istniejących pozycji oferty. Dodane
   `get_offer_items()` + prefill formularza (PHP + JS).
5. Komunikat deprecation na PHP 8.3 (implicit-nullable parametr konstruktora
   pipeline). → jawne `?MP_OB_Pipeline_Logger`.

## Uwagi / świadome ograniczenia

- **Odwrotne obciążenie z ręcznego formularza — DOMKNIĘTE w 1.0.2:** ekran
  budowy nowej oferty ma teraz checkbox „VAT UE potwierdzony" (oświadczenie
  handlowca), który ustawia `vat_status=valid` → `reverse_charge`. Ponadto
  `vat_status` jest utrwalany w BD-2 (kolumna `client_vat_status`, DB_VERSION
  0.6.0), więc mechanizm przetrwa korektę i round-trip przez bazę — wcześniej
  gubił się przy odczycie ze snapshotu (dotyczyło też ścieżki leada z VIES).
- **Prerekwizyt wdrożeniowy — UDOKUMENTOWANY w 1.0.2:** baza sklepu WooCommerce
  musi być ustawiona na kraj krajowej stawki VAT (dla polskiej firmy: PL) —
  plugin bierze stawkę z bazy sklepu (`WC_Tax::get_base_tax_rates()`). Opisane
  w `readme.txt` → `== Installation ==`. Realny polski sklep ma tę bazę domyślnie.
- Testy integracji z Pluginem 1 (`mp_lead_created` → auto-draft) świadomie
  odłożone do osobnej rundy testów całego procesu.

## Narzędzia

- Środowisko: `@wp-playground/cli` (lokalny WordPress, kod wtyczek montowany
  z dysku — omija limit `upload_max_filesize` przeglądarkowego Playground).
- Harness jednostkowy (poza WP): `tests/process-harness/run-process.php` —
  **98/98 PASS** na wersji 1.0.1. PHPCS/WPCS: 0 błędów/ostrzeżeń (46 plików).
