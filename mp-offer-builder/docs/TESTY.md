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

**Stan na wydanie 1.3.6 (01.08.2026).** Zapowiedziany wyżej re-run integracyjny
został wykonany i od tamtej pory jest powtarzany przy każdym wydaniu — na żywym
WordPressie z trzema wtyczkami naraz. Ostatni przebieg na **świeżo
zainstalowanej** bazie: pliki testowe trzech wtyczek **56 / 56 PASS** (w tym
18 plików tej wtyczki), świeża instalacja **16 / 16 PASS**, harness procesu
LP.2 **110 / 110 PASS**, PHPCS 0 błędów. W 1.3.6 rozrosły się dwa pliki:
`cena-ujemna.php` o sekcję dla sklepu z cennikiem **brutto** (P2-Z2)
i `podstawa-vat.php` o kontrolę podstawy przy odwrotnym obciążeniu, eksporcie
i zwolnieniu z VAT oraz o pozycję bez odpowiadającego produktu (P2-Z1).
Wcześniej, w 1.3.5, doszedł `tests/naprawy/finalizacja-pdf-a-zdarzenie.php` —
regresja dla P2-S4, czyli dla zabezpieczenia, które działało w kodzie, ale nie
było niczym pilnowane. Testy dołożone po 1.0.4 leżą w `tests/koncowe/`
i `tests/naprawy/` — każdy z `tests/naprawy/` powstał razem z naprawą
konkretnego defektu i FAIL-ował przed nią.

Sekcja o cenie ujemnej zasługuje na słowo osobno, bo zaczęła się od **błędnej
oceny z mojej strony**: uznałem zgłoszenie audytu za fałszywy alarm, rozumując,
że znak liczby przeżywa dzielenie przez stawkę podatku. Sonda z ustawieniem
`woocommerce_prices_include_tax = yes` pokazała co innego —
`wc_get_price_excluding_tax()` nie jest dzieleniem i dla wartości ujemnej nie
zwraca wartości ujemnej. Kontrola stojąca **za** przeliczeniem oglądała już
liczbę dodatnią. Wniosek na przyszłość: rozumowanie o kodzie nie zastępuje jego
uruchomienia, a najdroższe pomyłki to te, w których „wiadomo, jak to działa".

Naprawa podstawy VAT jest z kolei przykładem sporu z istniejącym testem.
`podstawa-vat.php` asertuje wprost, że odwrotne obciążenie **bez pozycji** ma
przechodzić; audyt żądał kontroli podstawy w każdym mechanizmie. Rozstrzygnięcie
nie polegało na wybraniu strony, tylko na znalezieniu warunku, przy którym oba
zdania są prawdziwe: kontrola obejmuje każdy mechanizm, ale wyłącznie dla
pozycji **niepustych**.

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

## Wersja 1.1.0 — zgodność ze zleceniem (2026-07-29)

Dwa braki wskazane w audycie zlecenia: prośba klienta nie docierała do ekranu
budowy oferty (#3b) i nikt nie wystawiał zdarzenia `mp_offer_approved`, choć
wtyczka 3 na nie czekała (#4, krok 4 zlecenia).

Nowy plik: `tests/koncowe/zatwierdzenie-oferty.php` — **41/41 PASS** na żywym
WordPressie (WP 7.0.2 + MariaDB 11.8.8, trzy wtyczki + WooCommerce naraz), dwa
niezależne przebiegi.

| Sekcja | Co sprawdza | Asercji |
|---|---|---|
| A | Produkty i wolumen z formularza trafiają do szkicu; reaktywacja odświeża opis; puste zdarzenie starszej wtyczki 1 niczego nie kasuje; brak opisu nie blokuje szkicu | 11 |
| B | Odmowy: szkic bez numeru i PDF-a, oferta nieistniejąca; żadna odmowa nie wystawia zdarzenia | 4 |
| C | Zatwierdzenie: status `approved`, zdarzenie **dokładnie raz**, komplet pól w payloadzie, wpis w dzienniku, powtórka bez drugiego zdarzenia | 13 |
| D | Zamrożenie: pipeline odmawia edycji zatwierdzonej oferty; cudza i nieistniejąca oferta dają identyczny komunikat (brak wyroczni własności) | 4 |
| E | Trzy wtyczki razem: wskaźnik w BD-3 przechodzi na `approved` (aktualizacja, nie duplikat), kwota przeliczona z groszy, proces w module sprzedażowym przechodzi na „oferta wysłana" | 9 |

### Regresja po zmianach (wszystko na tej samej instalacji)

| Zestaw | Wynik |
|---|---|
| Harness pipeline'u tej wtyczki | 110/110 |
| Harness wtyczki 1 | 7/7 |
| Regresja wtyczki 3 (14 zestawów) | 543/543 |
| Scenariusze końcowe wtyczki 3 | 97/97 |
| Bezpieczeństwo wtyczki 3 (S1–S12) | 98/98 |
| Kompatybilność trzech wtyczek | 62/62 |
| Bramka integracyjna P1→P2→P3 | 23/23 |
| Relacja lead → oferta (wtyczka 1) | 22/22 |

PHPCS/WPCS na plikach zmienionych w tej wersji: **0 błędów**.

### Czego te testy NIE sprawdzają

- Zatwierdzenia przez **przeglądarkę** (kliknięcie „Zatwierdź" w liście ofert).
  Testowana jest warstwa dziedzinowa `approve()` oraz kontrola własności;
  sam `admin_post` (nonce + uprawnienie + przekierowanie) wymaga żywej sesji
  wp-admin i pozostaje do sprawdzenia ręcznego.
- Wyglądu bloku „Czego szukał klient" na ekranie budowy oferty — sprawdzana
  jest zawartość kolumn w bazie, nie render HTML.
- Zachowania przy **równoległym** zatwierdzeniu z dwóch żądań. Jednokrotność
  opiera się na warunku `WHERE status = 'draft'` w samym UPDATE (baza wybiera
  zwycięzcę); test wymusza tę ścieżkę sekwencyjnie, nie współbieżnie.

## Wersja 1.2.0 — kryterium 5.3 zweryfikowane na gotowym pliku (2026-07-29)

Kryterium odbioru mówi: „Generowanie PDF w języku polskim i angielskim
z właściwymi cenami oraz numerem oferty". Sprawdzenie **treści wygenerowanego
dokumentu** (a nie samego kodu) ujawniło dwie usterki — obie naprawione.

Nowy plik: `tests/koncowe/pdf-pl-en-numer.php` — **31/31 PASS** na żywym
WordPressie z WooCommerce (kraj PL, waluta PLN, VAT 23%, produkty proste).
Generuje obie oferty pełnym pipelinem (11 działów, prawdziwy Dompdf), a treść
gotowych PDF-ów odczytano narzędziem `pdftotext` po stronie hosta.

### Usterka 1 — numeru oferty nie było na dokumencie

Numer trafiał wyłącznie do **metadanych** PDF i do nazwy pliku. Kontrola
w Dziale 9 (`contains_pdf_info_string`) sprawdzała właśnie metadane, więc brak
przechodził niezauważony przez wszystkie dotychczasowe testy.

Dowód po naprawie (`pdftotext`):

```
Oferta handlowa nr OF/2026/000005
Commercial offer no. OF/2026/000006
```

### Usterka 2 — polska oferta z angielskim formatem kwot

Polski dokument pokazywał `2,099.98 zł` — angielskie separatory przy polskim
symbolu waluty. Przyczyna nie była oczywista: rozszerzenie `intl` **było**
załadowane, ale ICU nie miało danych dla polskiego. `NumberFormatter` w takim
przypadku **nie zgłasza błędu** — po cichu używa `en_US`:

```
pl_PL wynik: [2,099.98]   VALID_LOCALE: en_US   ICU: 78.1   dane "pl": NIE
```

Własny fallback formatował poprawnie (`2 099,98`), ale nigdy się nie uruchamiał,
bo warunek sprawdzał wyłącznie `class_exists('NumberFormatter')`. Teraz pytamy
formatter, jaką lokalizację naprawdę dostał.

Dowód po naprawie: `450,50 zł` (PL) obok `450.50 PLN` (EN) w tych samych pozycjach.

### Regresja po zmianach

| Zestaw | Wynik |
|---|---|
| PDF PL/EN + numer (nowy) | 31/31 |
| Harness pipeline'u tej wtyczki | 110/110 |
| Zatwierdzenie oferty | 41/41 |
| Regresja wtyczki 3 | 543/543 |
| Scenariusze / bezpieczeństwo wtyczki 3 | 97/97, 98/98 |
| Kompatybilność 3 wtyczek / bramka | 62/62, 23/23 |
| Wtyczka 1: harness / relacja | 7/7, 22/22 |

PHPCS na plikach zmienionych w tej wersji: **0 błędów, 0 ostrzeżeń**.

### Czego ten test NIE sprawdza

- Wyglądu dokumentu (układ, łamanie stron) — weryfikowana jest obecność numeru
  i konwencja separatorów, nie typografia.
- Zachowania na serwerze, gdzie ICU **ma** dane `pl` — tam idzie gałąź
  `NumberFormatter`, a nasz fallback pozostaje nieużywany. Obie ścieżki dają tę
  samą twardą spację (U+00A0) jako separator tysięcy, ale test na tym środowisku
  przechodzi wyłącznie ścieżką fallbacku.

---

## Stan na wydanie 1.3.9 (03.08.2026)

Pełna regresja: **75 / 75 PASS** (73 pliki testowe przez `wp eval-file`
+ 2 harnessy na własnym shimie). Świeża instalacja **16 / 16 PASS**, scenariusze
odbioru **102 / 102 PASS**. PHPCS wspólnym `.phpcs.xml.dist`: **kod wyjścia 0**.
Surowe wyjście leży w [`raporty/PRZEBIEG-TESTOW.md`](../../raporty/PRZEBIEG-TESTOW.md).

Doszły cztery pliki testowe z rundy po audycie końcowym; każdy **padał przed**
naprawą, którą pilnuje:

| Plik | Czego pilnuje |
|------|---------------|
| `mp-offer-builder/tests/naprawy/wlasciciel-oferty-i-wersji.php` | „brak właściciela = NULL" w obu tabelach; kontrola własności wymaga obu stron |
| `mp-offer-builder/tests/naprawy/promocja-i-podatek-sklepu.php` | promocja musi być niższa od ceny regularnej; cennik brutto przy wyłączonych podatkach to odmowa |
| `mp-offer-builder/tests/naprawy/rozdzial-rabatu-i-tozsamosc.php` | kontrola tożsamości pozycji nie milczy; bezpiecznik zakresu przy rozdziale rabatu |
| `mp-lead-intake/tests/naprawy/strona-awaria-nie-milczy.php` | awaria strony formularza gasi flagę menu i mówi, gdzie szukać |

Rozbudowano też `mp-lead-intake/tests/naprawy/alarm-mowi-prawde.php` (sekcja L —
los alarmu jako fakt, nie prognoza) oraz poprawiono
`biala-lista-niepelna-odpowiedz.php`, który liczył dobę inaczej niż produkt.

**Dwie sekcje testowe opisują ustalenia ODRZUCONE** i przechodziły przed
jakąkolwiek naprawą: sekcja E pliku `wlasciciel-oferty-i-wersji.php` (wartownik
statusu w zdaniu WHERE) oraz sekcja D pliku `rozdzial-rabatu-i-tozsamosc.php`
(zwolnienie z VAT obejmuje też status „tylko wysyłka"). Powody odrzucenia
zapisane w `audyt/rejestr/znane-bledy.json` (U-34, U-35).

## Stan na wydanie 1.3.8 (02.08.2026)

Pełna regresja: **71 / 71 PASS** (69 plików testowych przez `wp eval-file`
+ 2 harnessy na własnym shimie). Świeża instalacja **16 / 16 PASS**, scenariusze
odbioru **102 / 102 PASS**, scenariusze bezpieczeństwa S1–S12 **99 / 99 PASS**.
PHPCS wspólnym `.phpcs.xml.dist`: **kod wyjścia 0**. Surowe wyjście leży
w [`raporty/PRZEBIEG-TESTOW.md`](../../raporty/PRZEBIEG-TESTOW.md).

Przebieg wykonano na bazie postawionej od zera **i na świeżych kontach** —
`test-swieza-instalacja.php` kasuje tabele, ale nie użytkowników, a konta
pozostałe po wcześniejszych testach potrafią ukryć błąd pierwszego zgłoszenia
(tak przez całą serię wydań ukrywało się U-18).

Doszło pięć plików testowych, wszystkie z napraw po audycie głębokim; każdy
**padał przed** naprawą, którą pilnuje:

| Plik | Czego pilnuje |
|------|---------------|
| `mp-offer-builder/tests/naprawy/ekran-regul-rabatowych.php` | pusta tabela reguł nie może zapisać „0% dla każdej oferty"; komunikat odpowiada temu, co zaszło |
| `mp-offer-builder/tests/naprawy/plan-zapisu-dzial-10.php` | plan zapisu nie przepuszcza danych, których baza nie obroni |
| `mp-offer-builder/tests/naprawy/podstawa-vat-dwie-skladowe.php` | rabat i stawka w zakresie, pozycja zgodna z produktem oferty |
| `mp-offer-builder/tests/naprawy/snapshot-cen-mowi-prawde.php` | brak ustawienia „ceny zawierają podatek" to odmowa, nie domysł |
| `mp-offer-builder/tests/naprawy/zatwierdzenie-komunikaty.php` | komunikat zatwierdzenia podaje status zastany i nie wyprzedza faktów |

Rozbudowano też `mp-sales-workflow/tests/naprawy/krytyk-skutkow.php` (sekcja F —
dwustronna bramka K5.2) oraz `mp-lead-intake/tests/naprawy/alarm-mowi-prawde.php`
(sekcje I/J/K — los alarmu, pochodzenie wyjątku, wyjątek bez komunikatu).

**Jedna sekcja testowa opisuje ustalenie ODRZUCONE.** Sekcja B pliku
`podstawa-vat-dwie-skladowe.php` przechodziła **przed** jakąkolwiek naprawą —
audyt zgłosił brak kontroli podstawy w gałęzi niekrajowej, a kontrola tam jest,
tylko świadomie zawężona do pozycji niepustych. Sekcja została jako straż, żeby
następny przegląd nie „naprawił" tej różnicy przez nieuwagę. Powód odrzucenia
zapisany w `audyt/rejestr/znane-bledy.json` (U-24).

## Stan na wydanie 1.3.7 (01.08.2026)

Pełna regresja: **66 / 66 PASS** (64 pliki testowe przez `wp eval-file`
+ 2 harnessy na własnym shimie). Scenariusze odbioru **102 / 102 PASS**.
PHPCS wspólnym `.phpcs.xml.dist`: **0 błędów, 0 ostrzeżeń, kod wyjścia 0**.
Surowe wyjście obu narzędzi leży w [`raporty/PRZEBIEG-TESTOW.md`](../../raporty/PRZEBIEG-TESTOW.md).

Uruchomienie całości jednym poleceniem:

```
tools/test-env/regresja.sh
```

Skrypt bierze listę z katalogów `tests/`, więc nowy plik dołącza do regresji
przez samo powstanie. Ten sam skrypt uruchamia CI (zadanie `integracja`) —
z innym klientem WP-CLI, ale z tą samą regułą oceny werdyktu.

### Dwie rzeczy, których poprzednie wydania nie sprawdzały

**CI była CZERWONA od chwili powstania, z wydaniem 1.3.6 włącznie.** Brama
wydania meldowała „PHPCS 0 błędów" i to była prawda; nikt nie sprawdził KODU
WYJŚCIA. PHPCS kończy się jedynką także przy samych OSTRZEŻENIACH, więc trzy
ostrzeżenia opisywane jako „stan bazowy" wywracały bramkę przy każdym pushu.
Naprawione u źródła, nie wyciszone.

**Wtyczka 3 nie miała w CI żadnego testu wykonywanego.** Nie z przeoczenia — jej
testy wymagają WordPressa z bazą — ale skutek był taki sam: trzecia wtyczka
i cała integracja między trzema wtyczkami przechodziły przez bramkę na
podstawie składni i stylu kodu. Zadanie `integracja` stawia WordPressa z MySQL
i uruchamia komplet.