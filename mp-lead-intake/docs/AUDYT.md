# Audyt brancha `mp-lead-intake`

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

## 3. Świadomie NIE naprawione — rekomendacje na później (decyzja użytkownika)

- **[WYS] Brak transakcji wokół zapisów działów 7–9.** Awaria zapisu logu (dz.8/9) po utworzeniu leada (dz.7) → osierocony, niekompletny rekord. Kod sam oznacza to jako „możliwe rozszerzenie (krok 4)". *Rekomendacja:* `START TRANSACTION`/`ROLLBACK` wokół 7–9.
- **[ŚR] RODO: IP w logu bez zadeklarowanej anonimizacji/retencji.** Komentarz w `class-mp-db.php` obiecuje anonimizację, mechanizmu brak. *Rekomendacja:* hashowanie/skracanie IP + TTL wpisów.
- **[NIS] `$wpdb->insert` bez `$format`** (dz.7/8/9, logger) — NIE SQLi (wartości przez `prepare`, klucze z kodu); ryzyko tylko typów w STRICT. Zostawione świadomie (zmienne `$data` grozi niedopasowaniem formatu).
- **[NIS] `MP_Context::merge` bez ochrony kolizji kluczy** — harness potwierdził brak zgubień na happy-path; namespacing to większy refactor bez udowodnionej regresji.
- **[NIS] Rate-limit: read-modify-write (nieatomowy) + klucz po `REMOTE_ADDR`** — miękki przy współbieżności; za proxy wspólny kubełek. Znane ograniczenie wzorca transientowego.
- **[NIS] Dział 1 „martwe odczyty"** (leads/offers/activity_log nie konsumowane) — narzut I/O; podłączyć do scoringu/returning-customer albo usunąć.
- **[NIS] `code`/`errors` w odpowiedzi AJAX** — drobne ujawnienie etapu STOP (pre-gate DoS już zwraca generyczne `request_rejected`).

---

## 4. Potwierdzenia bezpieczeństwa (SPRAWDZONE-OK)

- **SQLi:** wszystkie zapytania sparametryzowane (`prepare %s/%d`, `IN(...)` z `array_fill`+`absint`, `LIMIT %d`); nazwy tabel z `$wpdb->prefix` (kod), nie z wejścia.
- **XSS:** JS wstawia odpowiedzi przez `textContent` (zero `innerHTML`); PHP przez `esc_html_e`/`esc_attr`.
- **CSRF:** `check_ajax_referer(...,false)` fail-closed + `wp_send_json_error(403)` przed pipeline; nazwy akcji/pola spójne form↔ajax; dz.5 jako druga warstwa.
- **Sanityzacja:** każde `$_POST`/`REMOTE_ADDR` przez `wp_unslash`+`sanitize_*`.
- **Guard'y:** `ABSPATH` we wszystkich plikach; `WP_UNINSTALL_PLUGIN` w uninstall; poprawna kolejność `require_once` (bez fatala).
- **Deinstalacja/FK:** DROP dzieci→rodzic, `ON DELETE RESTRICT`; throttling maili admina (15 min/dział).
- **Struktura kontraktu:** każdy z 11 działów = pary {agent+krytyk} + dokładnie 1 bramka (1 QA agent + 1 QA krytyk); STOP jednokierunkowy.

---

*Uruchomienie weryfikacji procesu:* `tests/process-harness/README.md`.
