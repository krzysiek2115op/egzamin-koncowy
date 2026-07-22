# Audyt brancha `mp-lead-intake`

> ⚠️ **Sekcje 1-5 poniżej opisują stan v1.0.0** (runda 1, 6 agentów, 13 poprawek). Od tego
> czasu wtyczka przeszła **rundę 2** (v1.2.0 — async VAT/WP-Cron, transakcyjność 7-9, RODO,
> rozszerzenie formularza; szczegóły `DEBUG-RAPORT.md` §16, nieudokumentowane tu w swoim
> czasie), fix rate-limitu z testów manualnych (v1.2.1, `DEBUG-RAPORT.md` §17) oraz
> **rundę 3** (v1.2.2 — patrz sekcja 6 niżej). Aktualny stan: `DEBUG-RAPORT.md`, `TESTY.md`,
> sekcja 6 tego pliku.

**Wtyczka:** MP Lead Intake v1.0.0 (WordPress, PHP 7.4+) — pierwszy element procesu formularz → oferta.
**Zakres:** 38 plików PHP (~4000 linii): pipeline 11 działów (agent → krytyk → bramka QA), BD-3, warstwa AJAX/formularz.
**Metoda (segmentowa):**
1. Rozpoznanie + baseline `php -l` (38/38 czyste).
2. 6 adversarialnych subagentów ("psy") na Opusie — każdy na wąskim wycinku, audyt A→Z (rdzeń, działy 1–3, 4–6, 7–9, 10–11 + krytycy, warstwa WP/bezpieczeństwo).
3. Runtime-weryfikacja procesu pętlą `while` (harness poza WordPressem — `tests/process-harness/`).
4. Triage: każde zgłoszenie zweryfikowane na kodzie.
5. Fixowanie + re-audyt zmian osobnym psem-weryfikatorem (potwierdził 0 regresji, wskazał 2 dokrętki — naprawione).

---

## 1. Runtime — weryfikacja procesu (pętla `while`)

Harness buduje pełny pipeline przez `MP_Pipeline_Factory` i pętlą `while` przepuszcza scenariusze formularz → lead. Poprawny NIP generowany samą funkcją wtyczki (`MP_D3_Agent_Nip::checksum_valid`).

| Scenariusz | Wynik | STOP w dziale | Kod |
|---|---|---|---|
| happy_path (poprawne B2B) | ok, `lead_id` ustawione | przeszedł 11/11 | — |
| empty_form | STOP | 2 (walidacja) | required_ok |
| bad_nip (zła suma) | STOP | 3 (NIP) | nip_valid |
| bad_email | STOP | 2 | email pusty po norm. |
| no_rodo | STOP | 6 (zgody) | rodo_ok |
| honeypot | STOP | 5 (antyspam) | antispam_ok |
| bad_nonce | STOP | 5 (CSRF) | csrf_ok |
| duplikat NIP (aktywny) | 1.ok / 2.STOP | 7 | dedup |
| rate-limit (>5/min) | blok po 6. zgłoszeniu | 5 | — |

**Niezmienniki (8/8 PASS):** jednokierunkowość (happy-path nie gubi kluczy) · domknięcie (`lead_id`) · log przy każdym STOP · hook `mp_lead_created` · `duration_ms` liczony poprawnie · duplikat aktywny STOP · **reaktywacja zarchiwizowanego NIP** · **pre-gate DoS (`over_limit`)**.

**Wniosek:** rdzeń procesu jest spójny — happy-path i wszystkie ścieżki STOP działają zgodnie z kontraktem; dział 5 realnie weryfikuje nonce w pipeline (defense-in-depth ponad `check_ajax_referer` w handlerze).

---

## 2. Naprawione (13 poprawek)

| Plik | Problem | Fix |
|---|---|---|
| dz.01 | dedup po SUROWYM NIP → „ślepnie" na klienta przy zapisie `123-456-32-18` | normalizacja `preg_replace('/\D+/','')` przed zapytaniem |
| context `from_json` | round-trip gubił `errors`; brak walidacji `json_decode` | odtworzenie `errors` + guard `is_array` |
| department `process` | brak twardego guardu na fail agenta; szczegóły błędów pól gubione | guard `!is_ok()` przed krytykiem + `data['errors']` do logu diagnostycznego |
| dz.02 (2.3) | pusty NIP (same myślniki) przechodził; brak limitów długości | NIP wymagany + limity długości (email/company/phone) |
| dz.02 (2.3) | `mb_strlen` bez guardu → fatal bez mbstring | helper `str_len()` z fallbackiem `strlen` |
| dz.03 `checksum_valid` | placeholder `0000000000` przechodził; `^..$` dopuszczał `\n` | odrzucenie same-cyfry `(\d)\1{9}` + `\A..\z` |
| dz.03 (3.2 VAT) | VIES `isValid=false` przy `MS_UNAVAILABLE` → odrzucenie legalnego leada (cache 24h) | STOP tylko przy jawnym `INVALID`, inaczej `null` bez cache |
| dz.04 (4.2) | `strtolower` gubi `ł` → błędna segmentacja „USŁUGI" | `mb_strtolower(...,'UTF-8')` z guardem |
| dz.09 + dz.11 | `duration_ms` zawsze 0/ujemny (rozjazd stref) | monotoniczny `started_ts = microtime(true)` |
| dz.11 (11.3) | brak hooka integracyjnego dla pluginu 2/3 | `do_action('mp_lead_created', $lead_id, $payload)` |
| dz.11 (11.3) | hook przekazywał `$context->all()` (nonce, honeypot, dane cudzych leadów) | wąski, świadomy `$payload` |
| **ajax + dz.05** | **[WYS] rate-limit/honeypot PO kosztownych callach HTTP (dz.3) → DoS/amplifikacja** | **pre-gate honeypot + `over_limit()` w handlerze PRZED pipeline; dz.5 zostaje jako defense-in-depth (jedyny inkrement — brak podwójnego liczenia)** |
| **db + dz.07** | **[WYS] zarchiwizowany (soft-delete) NIP blokował ponowne zgłoszenie (UNIQUE)** | **`get_archived_lead_by_nip()` + `reactivate_lead()`; dz.7 reaktywuje zamiast INSERT** |

Po fixach: **php -l 38/38 OK**, **harness 7/7 scenariuszy + 8/8 niezmienników PASS**, re-audyt zmian: **0 regresji**.

---

## 3. Zrealizowane po audycie (kolejne iteracje)

- **[WYS] Transakcyjność zapisów działów 7–9** — `MP_Pipeline::set_transactional_from(7)`: COMMIT na sukces, ROLLBACK na STOP przed logowaniem. Koniec osieroconych leadów. (commit `577f44f`)
- **[ŚR] RODO — anonimizacja + retencja IP** — `anonymize_ip()` (truncacja) przy zapisie w loggerze/dz.8/dz.9; `purge_old_ip_addresses()` (dzienny cron, 90 dni); `anonymize_lead_ips()` (erasure on-demand). (commit `963423c`)

## 4. Świadomie NIE naprawione — rekomendacje na później

- **[NIS] `$wpdb->insert` bez `$format`** (dz.7/8/9, logger) — NIE SQLi (wartości przez `prepare`, klucze z kodu); ryzyko tylko typów w STRICT. Zostawione świadomie (zmienne `$data` grozi niedopasowaniem formatu).
- **[NIS] `MP_Context::merge` bez ochrony kolizji kluczy** — harness potwierdził brak zgubień na happy-path; namespacing to większy refactor bez udowodnionej regresji.
- **[NIS] Rate-limit: read-modify-write (nieatomowy) + klucz po `REMOTE_ADDR`** — miękki przy współbieżności; za proxy wspólny kubełek. Znane ograniczenie wzorca transientowego.
- **[NIS] Dział 1 „martwe odczyty" — CZĘŚCIOWO naprawione (rozstrzygnięte w rundzie 3).**
  `leads` (agent 1.1) JEST konsumowany — dedup w dz.7.1 reużywa go zamiast drugiego
  zapytania. `offers`/`activity_log` (agenci 1.2/1.3) NADAL czytane bez żadnego
  konsumenta w całym repo — 2 zbędne zapytania SQL na każde zgłoszenie trafiające w
  istniejący NIP. Podłączyć do scoringu/returning-customer albo usunąć.
- **[NIS] `code`/`errors` w odpowiedzi AJAX** — drobne ujawnienie etapu STOP (pre-gate DoS już zwraca generyczne `request_rejected`).

---

## 5. Potwierdzenia bezpieczeństwa (SPRAWDZONE-OK)

- **SQLi:** wszystkie zapytania sparametryzowane (`prepare %s/%d`, `IN(...)` z `array_fill`+`absint`, `LIMIT %d`); nazwy tabel z `$wpdb->prefix` (kod), nie z wejścia.
- **XSS:** JS wstawia odpowiedzi przez `textContent` (zero `innerHTML`); PHP przez `esc_html_e`/`esc_attr`.
- **CSRF:** `check_ajax_referer(...,false)` fail-closed + `wp_send_json_error(403)` przed pipeline; nazwy akcji/pola spójne form↔ajax; dz.5 jako druga warstwa.
- **Sanityzacja:** każde `$_POST`/`REMOTE_ADDR` przez `wp_unslash`+`sanitize_*`.
- **Guard'y:** `ABSPATH` we wszystkich plikach; `WP_UNINSTALL_PLUGIN` w uninstall; poprawna kolejność `require_once` (bez fatala).
- **Deinstalacja/FK:** DROP dzieci→rodzic, `ON DELETE RESTRICT`; throttling maili admina (15 min/dział).
- **Struktura kontraktu:** każdy z 11 działów = pary {agent+krytyk} + dokładnie 1 bramka (1 QA agent + 1 QA krytyk); STOP jednokierunkowy.

---

## 6. Runda 3 — finalny audyt segmentowy (10 sub-agentów, 2026-07-22)

**Metoda:** hierarchiczny podział na 10 niezależnych segmentów (rdzeń pipeline; działy
1-3; 4-6; 7-9; 10-11 + spójność całościowa; AJAX+bezpieczeństwo; baza danych; worker VAT
async+frontend; testy/CI/production-readiness; zgodność dokumentacji z kodem) — każdy z
pełnym 10-wymiarowym przeglądem (architektura/SRP/zależności/bezpieczeństwo/wydajność/
jakość/edge case'y/błędy logiczne/awarie/refaktoryzacja) + checklisty OWASP Top 10/ASVS/
WPCS/PSR-12/SOLID/DRY/KISS. Segment 10-11 dodatkowo zrobił inwentaryzację wszystkich 11
działów pod kątem kontraktu "N par agent+krytyk + dokładnie 1 bramka QA" — zero odchyleń.
Łącznie ~110 znalezisk, część potwierdzona **niezależnie przez 2+ agentów** (silny sygnał
realności — patrz pierwsza pozycja w tabeli niżej).

### Naprawione w tej rundzie

| Problem | Plik(i) | Fix |
|---|---|---|
| **[WYS] Race condition: reaktywacja zarchiwizowanego leada.** Dwa równoległe zgłoszenia (np. podwójny klik "wyślij") tego samego, zarchiwizowanego NIP mogły OBA "wygrać" (zwykły `UPDATE` bez blokady) — drugie cicho nadpisywało dane pierwszego i podwójnie odpalało hook `mp_lead_created`. Znalezione niezależnie przez 2 sub-agentów (segment 7-9 i segment 10-11) — sprzeczne z ówczesnym zapisem w §"Naprawione" wyżej ("reaktywacja bezpieczna" — prawdziwe tylko dla retry sekwencyjnego, nie wyścigu równoległego). | `class-mp-db.php` (`reactivate_lead`) | Atomowy claim (`UPDATE ... WHERE deleted_at IS NOT NULL`, sprawdzenie affected rows) PRZED nadpisaniem reszty danych — ten sam wzorzec co `insert_lead()`/`UNIQUE(nip)` dla świeżych zgłoszeń. Nowy niezmiennik harnessu #21. |
| **[WYS] Cross-country kolizja NIP.** Klucz unikalności obejmował sam `nip`, nie `(country, nip)` — lokalny numer firmowy dwóch różnych krajów UE mógł się cyfrowo pokrywać. Gorszy przypadek: reaktywacja nadpisywałaby dane zupełnie obcej firmy z innego kraju pod tym samym ID. | `class-mp-db.php`, dz.1, dz.7 | `UNIQUE KEY uq_country_nip(country, nip)` (DB_VERSION 1.3.0→**1.4.0**), jawna migracja usuwająca stary `uq_nip` (dbDelta nie usuwa indeksów samodzielnie — bez tego stary klucz zostałby aktywny na już zainstalowanych bazach). `get_leads_by_nip()`/`get_archived_lead_by_nip()` przyjmują `$country`. Nowy niezmiennik #22. |
| **[ŚR] Brak try/finally wokół transakcji dz.7-11.** Nieoczekiwany wyjątek/fatal (np. w przyszłym subskrybencie `do_action('mp_lead_created')` z pluginu 2/3) omijał ROLLBACK i log — klient dostawał nieprzechwycony fatal zamiast udokumentowanego generycznego JSON-a. | `class-mp-pipeline.php`, `class-mp-pipeline-logger.php`, `class-mp-ajax.php` | `try/catch(\Throwable)/finally`-owy ROLLBACK + nowa `MP_Pipeline_Logger::log_exception()` (log BD-3 + mail admina, jak `log_failure()`); `class-mp-ajax.php` łapie i gwarantuje kontrakt "zawsze JSON". |
| **[ŚR] Aktywacja nie weryfikowała sukcesu `dbDelta()`.** Cicha porażka (np. brak uprawnień `CREATE TABLE` na hostingu) zostawała trwale oznaczona jako "zainstalowane poprawnie" — pierwszym objawem byłby nieczytelny błąd przy pierwszym zgłoszeniu formularza. | `class-mp-db.php`, `mp-lead-intake.php` | Nowa `MP_Lead_Intake_DB::tables_exist()`; `install()` zapisuje `DB_VERSION_OPTION` tylko gdy tabele faktycznie istnieją (inaczej `maybe_upgrade()` spróbuje ponownie); aktywacja przerywa się czytelnym `wp_die()` przy porażce. |
| **[NIS] `readme.txt` nieaktualny.** `Stable tag: 1.0.0`, martwy tag `woocommerce` (brak jakiejkolwiek integracji), changelog urwany na 1.0.0 mimo 4 kolejnych wersji kodu. | `readme.txt` | Zaktualizowane Stable tag/Tags + dopisany changelog 1.2.0-1.2.2. |

Wersja wtyczki: 1.2.1 → **1.2.2**. Wersja schematu bazy: 1.3.0 → **1.4.0**. Po fixach:
`php -l` 45/45 czyste, PHPCS/WPCS 0 błędów, harness **7/7 scenariuszy + 22/22 niezmienników
PASS** (dodano #21 i #22 pod nowe fixy; poprawiono też fixture niezmiennika #7, który
"przechodził" wcześniej wyłącznie dzięki luce w starym stubie testowym — patrz commit).

### Świadomie NIE naprawione w tej rundzie — rekomendacje na "dopracowywanie repozytorium"

Reszta z ~110 znalezisk to Low/Medium bez natychmiastowego ryzyka bezpieczeństwa/integralności
danych — świadomie udokumentowane jako rekomendacje na kolejną fazę, nie zaimplementowane teraz:

- **[ŚR] Dział 1: `offers`/`activity_log` nadal martwe odczyty** — patrz sekcja 4 wyżej.
- **[ŚR] Brak CI/CD** — harness i `.phpcs.xml.dist` gotowe pod bramkę, nic nie uruchamia ich automatycznie przy push/PR; regresja może wejść bez wymuszenia testów.
- **[ŚR] Wiring workera VAT (WP-Cron) nietestowany end-to-end** — stuby `add_action`/`do_action` w harnessie to no-op; błąd w `MP_Lead_Intake_Vat_Verifier::register()` przeszedłby niezauważony mimo 22/22 "PASS".
- **[ŚR] Walidacja pól formularza** — brak formatu telefonu; kraj niewalidowany w dz.2 (whitelist dopiero w dz.4, więc dz.1/dz.3 chwilowo widzą surową wartość); NIP zakłada wyłącznie Polskę (10 cyfr, suma kontrolna PL) mimo parametryzacji VIES per-kraj.
- **[ŚR] Segmentacja branżowa** — needle `'it'` (2 znaki, bez granicy wyrazu) fałszywie trafia w "Architektura", "Kapitałowa" i inne pospolite słowa.
- **[ŚR] `consent_version`** nie jest mechanicznie powiązane z realną wersją `docs/POLITYKA-PRYWATNOSCI-WZOR.md` — osłabia wartość dowodową zgody RODO przy sporze.
- **[NIS]** Rate-limit: nieatomowy read-modify-write transientu (realne ryzyko rośnie tylko, gdy ktoś wyłączy domyślny tryb async filtrem `mp_lead_intake_async_verification`); honeypot zależny wyłącznie od zewnętrznego CSS (brak inline fallbacku); regex wstrzykiwania linku do menu motywu może "osierocić" link przy niektórych strukturach zagnieżdżonych `<nav>`; `vat_checked_at`/`deleted_at` w workerze VAT nadal lokalny czas WP, nie GMT (mimo że `updated_at` już naprawiony wcześniej — częściowy nawrót tej samej klasy błędu); i18n niekompletne (komunikaty AJAX nieopakowane w `__()`); dz.9 nazwa "Rozpoczęcie procesu" myląca względem treści (czysta telemetria czasu, wykonuje się PO utworzeniu leada w dz.7); dz.10 buduje odpowiedź, której `class-mp-ajax.php` nie konsumuje.

Pełna lista wszystkich ~110 znalezisk (ID, lokalizacja, przyczyna, ryzyko, rekomendacja z
przykładem kodu, poziom pewności) — w transkrypcie audytu tej sesji; powyżej synteza tego,
co ma realną wartość decyzyjną dla kolejnej fazy.

### Ocena końcowa (runda 3, 2026-07-22)

| Obszar | Ocena | Uzasadnienie |
|---|---|---|
| Bezpieczeństwo | 9/10 | Jedyny publiczny endpoint (AJAX) — 0 Critical/High na dedykowanym audycie OWASP Top 10; rate-limit fix z v1.2.1 potwierdzony niezależnie 3× w tej rundzie. Naprawione dziś: 2 realne luki integralności danych. |
| Architektura | 8/10 | Kontrakt 11×(agent+krytyk+bramka) egzekwowany strukturalnie (type-hinty, nie konwencją) — zero odchyleń na 11/11 działów. Drobne: nazewnictwo dz.9, martwe wyjście dz.10. |
| Wydajność | 8/10 | P-1 (async VAT) potwierdzone: 0 wywołań HTTP w ścieżce żądania. Drobny narzut: martwe odczyty dz.1.2/1.3, brak indeksu złożonego dla historii aktywności przy większej skali. |
| Jakość kodu | 8/10 | PHPCS/WPCS 0 błędów na 45/45 plikach, `php -l` czyste. DRY: normalizacja NIP/kraju świadomie zduplikowana w kilku miejscach (dz.1 działa przed normalizującymi działami). |
| Skalowalność | 7/10 | Brak CI, częściowe pokrycie testowe (wiring workera VAT, kilka działów tylko pośrednio) — ale sam proces (transakcyjność, idempotencja, async) zaprojektowany poprawnie pod wzrost ruchu. |
| **Production Readiness** | **8/10** | Gotowe do dalszej pracy nad repozytorium; pozostałe luki (CI, pełne pokrycie testowe workera, walidacja pól) to rozsądny zakres kolejnej fazy, nie blokery. |

---

*Uruchomienie weryfikacji procesu:* `tests/process-harness/README.md`.
