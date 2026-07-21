# Bezpieczeństwo — MP Lead Intake

Dokument opisuje **rzeczywistą** powierzchnię ataku wtyczki, wdrożone zabezpieczenia,
rejestr zagrożeń (OWASP/CWE + ryzyko/wpływ/prawdopodobieństwo/wykrywanie/naprawa/priorytet)
oraz — uczciwie — obszary **nie dotyczące** tej wtyczki (bez teatru bezpieczeństwa).

> Zakres: wtyczka `mp-lead-intake` (odbiór leada z 1 formularza). NIE obejmuje utwardzania
> całego WordPressa/serwera — te elementy oznaczono jako rekomendacje warstwy infrastruktury.

---

## 1. Powierzchnia ataku (rzeczywista)

| Wektor | Stan w tej wtyczce |
|---|---|
| **Frontend** | Shortcode `[mp_lead_intake_form]`, render server-side (`esc_html_e`/`esc_attr`). JS: vanilla, `fetch` same-origin, wynik przez `textContent` (brak DOM XSS). |
| **AJAX** | **Jeden** endpoint `wp_ajax(_nopriv)_mp_lead_intake_submit` (publiczny). Nonce + Origin/Referer + honeypot + rate-limit + limit rozmiaru + pipeline. |
| **REST API** | Wtyczka **nie rejestruje** własnych tras REST. |
| **Baza (BD-3)** | `wp_mp_leads`, `wp_mp_offers`, `wp_mp_activity_log`. Prepared statements, FK, UNIQUE, transakcja zapisów 7–9. |
| **Cron** | `mp_lead_intake_ip_retention` (dzienny) — retencja IP (RODO). |
| **Role/uprawnienia** | Role `mp_manager_sprzedazy`, `mp_handlowiec` + capabilities. Brak jeszcze ekranu admina, więc brak akcji uprzywilejowanej do nadużycia. |
| **wp_options** | `mp_lead_intake_db_version`, `mp_lead_intake_page_id`; transienty `mp_*` (rate-limit, cache VIES/WL, throttle). |
| **Nie występują** | Upload plików, generator PDF, integracja WooCommerce, obsługa logowania/sesji/ciasteczek, własne nagłówki globalne (chyba że opt-in). |

---

## 2. Wdrożone zabezpieczenia

**Wejście / CSRF / anti-automation**
- Nonce (`check_ajax_referer`, fail-closed) + **Origin/Referer** (defense-in-depth) — CWE-352.
- Honeypot (`mp_hp`) + **rate-limit pre-gate** w handlerze PRZED kosztownym działem 3 (VIES/Biała lista) — CWE-400/799.
- Limit rozmiaru żądania (413) + whitelist kluczy POST — CWE-400.
- Sanityzacja wszystkich `$_POST`/`$_SERVER` (`wp_unslash` + `sanitize_*`).

**Wyjście / dane**
- XSS: `esc_html*/esc_attr` (PHP) + `textContent` (JS) — CWE-79.
- SQLi: `$wpdb->prepare` (`%s/%d`), `IN()` z `array_fill`+`absint`, nazwy tabel z kodu — CWE-89.
- Info-disclosure: generyczny błąd + **correlation ID** (`request_id`); wewnętrzne kody/pola tylko w logu BD-3 — CWE-209.

**Dane osobowe (RODO)**
- Anonimizacja IP przy zapisie (truncacja) + retencja (cron 90 dni) + erasure on-demand — CWE-359/312.

**Integralność danych**
- UNIQUE(nip) + dedup + **reaktywacja** zarchiwizowanego NIP; **transakcja** zapisów 7–9 (COMMIT/ROLLBACK) — CWE-362.

**Dostęp do plików**
- `ABSPATH` guard we wszystkich plikach; `index.php` „Silence is golden" w każdym katalogu; `WP_UNINSTALL_PLUGIN` w uninstall — CWE-548.

**Nagłówki**
- Na odpowiedzi AJAX: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Cache-Control: no-store`, `X-Robots-Tag: noindex`.
- **Opt-in** globalne (OFF domyślnie): pełny zestaw + CSP — patrz §6.

---

## 3. Rejestr zagrożeń (pentest)

Ryzyko = wypadkowa wpływu i prawdopodobieństwa (skala: Niskie/Średnie/Wysokie). Status po wdrożeniach.

| ID | Zagrożenie | OWASP / CWE | Wpływ | Prawd. | Ryzyko rezyd. | Wykrywanie | Naprawa / status | Priorytet |
|---|---|---|---|---|---|---|---|---|
| T1 | CSRF na AJAX | A01 / CWE-352 | Śr | Niskie | **Niskie** | Log `invalid_nonce/origin` | Nonce + Origin/Referer ✅ | — |
| T2 | XSS (stored/reflected/DOM) | A03 / CWE-79 | Wys | Niskie | **Niskie** | Przegląd wyjścia | esc_* + textContent ✅ | — |
| T3 | SQL Injection | A03 / CWE-89 | Wys | Niskie | **Niskie** | PHPCS DB sniffs | Prepared statements ✅ | — |
| T4 | DoS/amplifikacja przez calle zewn. (dz.3) | A04/A05 / CWE-400 | Śr | Śr | **Niskie** | Wzrost ruchu wych./log | Pre-gate rate-limit+honeypot ✅ | — |
| T5 | Zapychanie `activity_log` (nopriv) | A04 / CWE-400 | Śr | Śr | **Średnie** | Rozmiar tabeli | Rate-limit przed logiem; *rekom.:* próg/retencja logu | P2 |
| T6 | Ekspozycja PII/IP w logu | A02 / CWE-312/359 | Wys | Niskie | **Niskie** | Audyt RODO | Anonimizacja+retencja IP ✅ | — |
| T7 | Info-disclosure w błędach | A05 / CWE-209 | Niski | Śr | **Niskie** | Przegląd odpowiedzi | Generyczny błąd + request_id ✅ | — |
| T8 | Broken access control | A01 / CWE-284 | Wys | Niskie | **Niskie** | — | Endpoint robi tylko swoje; *rekom.:* `current_user_can` gdy powstanie panel | P2 |
| T9 | Wyścig/duplikat leada | A04 / CWE-362 | Śr | Niskie | **Niskie** | UNIQUE errors | UNIQUE+dedup+reaktywacja+transakcja ✅ | — |
| T10 | Słaba anti-automation (nonce reużywalny dla gości) | A07 / CWE-799 | Śr | Śr | **Średnie** | Anomalie ruchu | Honeypot+rate-limit; *rekom.:* CAPTCHA/PoW przy nadużyciach | P2 |
| T11 | Direct file access / listing | A05 / CWE-548 | Niski | Niskie | **Niskie** | Skan katalogów | ABSPATH + index.php ✅ | — |
| T12 | SSRF (VIES/Biała lista) | A10 / CWE-918 | Śr | Niskie | **Niskie** | Log żądań wych. | URL do stałych domen gov; NIP→cyfry; timeout ✅ | P3 |
| T13 | Insecure deserialization | A08 / CWE-502 | Wys | b.niskie | **Niskie** | — | Brak `unserialize` na danych z zewnątrz ✅ | — |
| T14 | Supply chain | A06 / CWE-1104 | Śr | Niskie | **Niskie** | — | Zero zależności zewn. (vanilla PHP/JS) ✅ | — |
| T15 | `$wpdb->insert` bez `$format` | — / CWE-704 | Niski | Niskie | **Niskie** | STRICT mode DB | *Rekom.:* dodać `$format` | P3 |

**Do wdrożenia (priorytety):** P2 → T5 (retencja/próg logu), T8 (capability gdy panel), T10 (CAPTCHA opcjonalnie). P3 → T12 (walidacja `country` do ISO gdy pole trafi z formularza), T15 (`$format`).

---

## 4. Mapowanie OWASP Top 10 (2021)

| Kategoria | Status |
|---|---|
| A01 Broken Access Control | Niskie — endpoint nopriv o wąskiej funkcji; caps zdefiniowane (T8). |
| A02 Cryptographic Failures | Anonimizacja IP; HSTS opt-in; brak przechowywania sekretów. |
| A03 Injection | Prepared statements + escaping (T2/T3). |
| A04 Insecure Design | Pipeline z bramkami QA, transakcje, rate-limit by design. |
| A05 Security Misconfiguration | ABSPATH/index.php; nagłówki; opt-in CSP. |
| A06 Vulnerable Components | Brak zależności zewnętrznych. |
| A07 Ident./Auth Failures | Nie obsługuje auth (rdzeń WP); anti-automation: honeypot+rate-limit (T10). |
| A08 Software/Data Integrity | Brak niebezpiecznej deserializacji; UNIQUE/FK/transakcje. |
| A09 Logging/Monitoring | `activity_log` + correlation ID; *rekom.:* alerty/retencja (T5). |
| A10 SSRF | Calle tylko do stałych domen gov, dane wejściowe sanityzowane (T12). |

**OWASP API Top 10 (istotne):** API1 BOLA — brak obiektowego dostępu po ID w publicznym API (jedyny endpoint tworzy zasób). API4 Resource Consumption — rate-limit+limit rozmiaru. API8 Misconfiguration — nagłówki/CSP opt-in.

---

## 5. Obszary N/A (świadomie NIE „zabezpieczane" — nie ma ich w pluginie)

| Obszar | Dlaczego N/A | Gdzie realnie należy |
|---|---|---|
| Upload/MIME/Magic Bytes/Virus Scan/Path Traversal | Wtyczka nie przyjmuje plików | — (gdyby doszło: `wp_check_filetype_and_ext`, allowlist MIME) |
| Generator PDF | Nie istnieje | Przyszły moduł ofert (plugin 2) |
| WooCommerce | Brak integracji (tag w readme mylący — do usunięcia) | — |
| Login/Session/Cookies/Remember-Me/Fixation | Rdzeń WordPress, wtyczka nie ustawia ciasteczek | WP core / wtyczka 2FA |
| Geo/ASN/Country/TOR/VPN blocking | Wymaga zewn. feedów, inwazyjne dla RODO | WAF / Cloudflare / reverse-proxy |
| Backup / Disaster Recovery | Warstwa infrastruktury/hostingu | Plan backupów serwera + `wp db export` |
| Brute-force login monitoring | Dotyczy `wp-login`, nie tej wtyczki | Wtyczka security (np. limit login) |

Frameworki (NIST CSF / CIS Benchmark / ISO 27001) to poziom **organizacji/serwera**, nie
pojedynczej wtyczki. Ta wtyczka wspiera je technicznie: PR.DS (ochrona danych — anonimizacja IP),
PR.AC (kontrola dostępu — role/caps), DE.CM (monitoring — activity_log). Pełne SoA/benchmark
realizuje się na poziomie hostingu.

---

## 6. Opt-in: globalne nagłówki + CSP

Domyślnie **wyłączone** (lead-intake nie narzuca polityki całej witrynie). Włączenie:

```php
// wp-config.php lub mu-plugin:
define( 'MP_LEAD_INTAKE_SECURITY_HEADERS', true );
// albo:
add_filter( 'mp_lead_intake_send_security_headers', '__return_true' );
```

CSP i zestaw nagłówków są filtrowalne (`mp_lead_intake_csp`, `mp_lead_intake_security_header_list`).
**Zalecenie:** produkcyjnie nagłówki ustawiać na poziomie serwera (Nginx/Apache) — patrz
`docs/security-headers.conf.example`. CSP wymaga strojenia pod motyw (inline styles/scripts).

---

## 6.1. Aktywne utwardzenie WordPressa (wybór właściciela)

Domyślnie **włączone**, każde **filtrowalne** (można wyłączyć pojedynczo):

- **Wyłączenie XML-RPC** — `xmlrpc_enabled=false` + usunięcie metod pingback i nagłówka `X-Pingback`.
  Chroni przed brute-force amplification (`system.multicall`) i pingback DDoS/SSRF.
  Wyłącz (gdy używasz apki mobilnej WP / Jetpack): `add_filter('mp_lead_intake_disable_xmlrpc','__return_false')`.
- **`DISALLOW_FILE_EDIT`** — brak edytora plików w kokpicie (admin nie wgra PHP przez panel).
  Wyłącz: `mp_lead_intake_disallow_file_edit`.
- **Ukrycie wersji WP** (`wp_generator`, `the_generator`) — mniej fingerprintingu.
  Wyłącz: `mp_lead_intake_hide_wp_version`.

*Heartbeat i twardy blok REST świadomie pominięto (Heartbeat = wydajność nie bezpieczeństwo;
blok REST psuje edytor bloków/oEmbed — celniejsza byłaby blokada samej enumeracji userów).*

## 7. Zgłaszanie podatności

Patrz `docs/security.txt` (skopiuj do `/.well-known/security.txt` na serwerze).
Robots: `docs/robots.txt.example`. Polityka prywatności (RODO): `docs/POLITYKA-PRYWATNOSCI-WZOR.md`.
