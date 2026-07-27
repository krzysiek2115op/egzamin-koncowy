# Testy końcowe — MP Offer Builder (Plugin 2)

Testy na **żywym WordPressie + WooCommerce** (WordPress Playground CLI, realny
WP 7.x, PHP 8.3, WooCommerce z katalogu wordpress.org), przeprowadzone
2026-07-25 na wersji pluginu **1.0.1** (scenariusze żywe E2E). Kod rozwinięto
następnie do **1.0.4**. Zmiany 1.0.2/1.0.3 (VAT per klasa podatkowa, odrzucanie
ceny ujemnej, retry MAX_ATTEMPTS=5, pełna deinstalacja) oraz **ostateczną rundę
debug 1.0.4** (8 równoległych sub-audytów + przegląd krzyżowy; ~20 poprawek —
bezpieczeństwo, VAT zwolniony/nieznany kraj, promocje z harmonogramem, strefa
czasu, idempotencja zapisu, sprzątanie PDF) pokrywa regresja jednostkowa
**108/108** (sekcja Narzędzia). Pełny re-run E2E na żywym WP zaplanowany w rundzie
integracyjnej (razem z Pluginem 3).

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
  odłożone do osobnej rundy testów całego procesu. Dwie sprawy do domknięcia
  W TEJ rundzie (nie dotyczą trybu standalone P2):
    - **F2 — kontrakt `vat_status` P1→P2:** P1 emituje `vat_status='checked'`
      (nigdy `'valid'`), a Dział 6 wymaga `'valid'` do `reverse_charge` — więc
      odwrotne obciążenie ze ścieżki leada jest na razie nieosiągalne (szkic
      liczy VAT krajowy). Fix = uzgodnienie słownika statusów przy integracji.
    - **F3 — właściciel draftu z leada:** `lead-listener` zakłada draft z
      `created_by = NULL`, więc nie-admin nie zobaczy go na liście (widzi tylko
      swoje oferty). Fix = mapowanie `salesman_id` → `created_by` przy integracji.

## Świadome kompromisy (runda debug 1.0.4)

Pozycje przeanalizowane i ŚWIADOMIE zaakceptowane jako niski priorytet — nie są
błędami, lecz udokumentowanymi decyzjami:

- **S6-01 — render PDF wewnątrz otwartej transakcji przy retry.** Ponowny render
  po kolizji numeru dzieje się, gdy transakcja Działu 10 jest już otwarta —
  wydłuża ją o czas generowania PDF. Akceptowane przy niskim ruchu B2B (oferty
  tworzone pojedynczo). Próg powrotu: gdy wzrośnie współbieżność tworzenia ofert,
  przenieść render przed START TRANSACTION.
- **S3-03 — podwójny odczyt produktu (`wc_get_product`) w Dziale 2.** WooCommerce
  cache'uje produkt w obrębie żądania, więc oba odczyty zwracają ten sam obiekt;
  refaktor ruszałby kontrakt agent/krytyk bez realnego zysku. Niski.
- **S8-02 — rejestracja hooków „raz na przebieg".** Domknięcie per-run jest
  poprawne i idempotentne; globalny guard nie jest konieczny. Niski.
- **S8-04 — pełny kontekst w wyniku pipeline'u.** Wynik celowo niesie pełny stan
  (testy czytają `final_data`); Dział 11 i tak zawęża odpowiedź AJAX do białej
  listy pól (bez ścieżek serwera i cudzych danych). Niski.

## Security Hardening (runda 1.0.5)

Pełny security review: 5 niezależnych Security Reviewerów per segment (wejście/AJAX,
baza/zapis, PDF/storage, admin/output, config/hooki/RODO) + Cross Security Review
szwów. **Zero luk krytycznych/wysokich; wszystkie średnie domknięte.**

Wdrożone: rate-limiting submitu (20/min/user) + limit rozmiaru wejścia (256 KB /
głębokość JSON / max 200 pozycji); koniec enumeracji (cudzy szkic/`request_id` daje
ten sam wynik co „nie istnieje”); audit-log pobrań PDF (+ odmów); nagłówki
nosniff/X-Frame-Options:DENY/Referrer-Policy/no-store na pobieraniu; Dompdf
isPhpEnabled+isJavascriptEnabled jawnie false; integracja RODO (eksporter + eraser
anonimizujący + polityka prywatności); 0-wierszy przy UPDATE = konflikt; GET_LOCK
zwalniany tylko gdy zdobyty; containment przy finalizacji PDF; ostrzeżenie nginx;
guard CLI harnessu; `.distignore`.

Zweryfikowane jako bezpieczne (fałszywe tropy, nie luki): SQLi (prepare + whitelist
ORDER BY + esc_like), mass-assignment (twarde klucze insert/update), XSS stored/
reflected/DOM (esc_html/esc_attr/esc_url/textContent), CSRF (nonce wszędzie), open
redirect (brak), SSRF/XXE przez Dompdf (isRemoteEnabled=false + chroot), CSV/PDF
injection (brak eksportu; dane escapowane), path traversal (realpath containment),
zip slip (brak zip), uninstall (guard + symlink-safe).

Świadome ryzyka rezydualne (Niski): pod nginx bez reguły `deny` poufność PDF opiera
się na nazwie HMAC (obrona w głąb; ostrzeżenie w panelu + readme); flood draftów z
leada zależy od rate-limitu formularza P1 (kontrakt do_action nie uwierzytelnia
emitera); nagłówki ogólnowitrynowe (HSTS) to poziom serwera (readme).

## Narzędzia

- Środowisko: `@wp-playground/cli` (lokalny WordPress, kod wtyczek montowany
  z dysku — omija limit `upload_max_filesize` przeglądarkowego Playground).
- Harness jednostkowy (poza WP): `tests/process-harness/run-process.php` —
  **110/110 PASS** na wersji 1.0.5 (w tym 2 inwarianty rundy security: limit pozycji, jednolity kod enumeracji). Obejmuje inwarianty 1.0.3 (`inv95`/`inv96`
  VAT per klasa podatkowa + rabat proporcjonalny, `inv97` cena ujemna) oraz
  6 nowych inwariantów rundy debug 1.0.4: produkt `tax_status=none` → 0% VAT,
  kraj nieznany WooCommerce → `unknown_country`, promocja z wygasłym harmonogramem
  → cena regularna, przesunięcie strefy przez granicę roku → rok w numerze oferty,
  kolizja `request_id` → idempotentne przerwanie zapisu, `data_json` wersji po
  ponownej numeracji → właściwy numer. PHPCS/WPCS: 0 błędów/ostrzeżeń.
