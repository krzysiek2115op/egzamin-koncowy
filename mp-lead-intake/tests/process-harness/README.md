# Process harness — runtime-weryfikacja pipeline (pętla `while`)

Uruchamia **cały pipeline 11 działów poza WordPressem** i pętlą `while` przepuszcza
scenariusze formularz → lead, sprawdzając niezmienniki procesu. Nie wymaga bazy ani
sieci — `wp-stubs.php` dostarcza minimalny shim WP (stałe, funkcje, fałszywy `$wpdb`,
transienty w pamięci); `wp_remote_get` zwraca `WP_Error`, więc dział 3 idzie łagodnym
fallbackiem (bez VIES/Białej listy).

## Uruchomienie

```bash
php tests/process-harness/run-process.php
```

Katalog wtyczki wykrywany automatycznie (dwa poziomy w górę). Można wymusić:

```bash
MP_PLUGIN_DIR=/ścieżka/do/mp-lead-intake php tests/process-harness/run-process.php
```

Wymaga tylko CLI PHP ≥ 7.4. Kod wyjścia `0` = proces spójny, `1` = wykryto naruszenie.

## Co weryfikuje

- **Scenariusze:** happy-path (przejście 11/11 + `lead_id`) oraz ścieżki STOP
  (pusty formularz, zły NIP/e-mail, brak RODO, honeypot, zły nonce) — każdy zatrzymuje
  się we właściwym dziale.
- **Niezmienniki:** jednokierunkowość (brak zgubienia kluczy), domknięcie (`lead_id`),
  log przy STOP, hook `mp_lead_created`, `duration_ms`, duplikat aktywnego NIP (STOP),
  reaktywacja zarchiwizowanego NIP, pre-gate rate-limit (`over_limit`).

## Pliki

- `wp-stubs.php` — shim WordPressa + fałszywy `$wpdb` (sterowanie: `$GLOBALS['__mp_cfg']`).
- `run-process.php` — generuje poprawny NIP funkcją wtyczki i uruchamia pętlę `while`.

Poprawny NIP jest generowany samą funkcją wtyczki (`MP_D3_Agent_Nip::checksum_valid`),
więc test pozostaje zgodny z produkcyjnym algorytmem sumy kontrolnej.
