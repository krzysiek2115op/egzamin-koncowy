# Plan napraw po audycie z 31.07.2026

Stan na: **31.07.2026, przerwane przed rozpoczęciem Grupy A.**
Dokument jest punktem wznowienia po `/clear` — zawiera wszystko, co potrzebne,
żeby kontynuować bez powtarzania ustaleń.

---

## 1. Co jest zrobione

### Narzędzie audytowe (branch `audyt-projektu`)
- **37 par agent+krytyk**: Dział 1 = 26, Dział 2 = 11.
- Trzy głębokości: `szybki` (~1 s), `pelny` (~90 s), `gleboki` (~2,5 h).
- Tagi: `v2.0-36-par`, `v2.1-potwierdzanie`, `v2.2-raport`.
- Rejestr `audyt/rejestr/znane-bledy.json` — 14 wpisów.

### Pełny przebieg głęboki (31.07, 2 h 32 min, 65 zapytań do modelu)
- Dział 1: **26/26** par, Dział 2: **11/11**, obie bramki zaliczone.
- **265 ustaleń**: 9 krytycznych, 99 średnich, 84 drobne, 73 obserwacje.
- Para 2.11 oceniła 36 ustaleń modelu, podniosła **23** do `potwierdzone`.
- Raport tekstowy: `audyt/raporty/raport-ostatni.txt` (kopia: `/tmp/RAPORT-AUDYTU-2026-07-31.txt`).
- **JSON tego przebiegu przepadł** (błąd zerobajtowego zapisu, naprawiony w v2.2).
  Następny przebieg będzie miał obie wersje.

### Ręczna weryfikacja dziewiątki krytycznej — ZAKOŃCZONA
Wynik: **3 fałszywe alarmy, 1 nieosiągalny, 5 realnych średniej wagi.**
Żaden nie blokuje wydania. Właściwy werdykt: **GO WITH FIXES**.

---

## 2. Co robimy dalej — kolejność uzgodniona z klientem

**A → B → C → średnie → drobne → obserwacje.** Po jednym kroku naraz,
każdy domknięty w 100%, żeby nie wracać do tego samego.

### GRUPA A — „sukces, który nie jest sukcesem" (3 poprawki)
Wspólna zasada: **brak potwierdzenia to nie jest potwierdzenie.**
To ta sama rodzina, która dała 8 błędów krytycznych w lipcu.

| Lp. | Plik | Linia | Co jest źle | Naprawa |
|---|---|---|---|---|
| A1 | `mp-sales-workflow/includes/pipeline/departments/class-mp-sw-department-08.php` | ~315 | `(!$poszlo && $odpadlo) ? UNDELIVERED : DONE` — trzeci przypadek (nic nie poszło **i** nic nie odpadło) zamyka zadanie jako WYKONANE | Rozdzielić trzeci przypadek: brak powiadomienia w obu listach → `undelivered`, nie `done` |
| A2 | `mp-sales-workflow/includes/pipeline/departments/class-mp-sw-department-09.php` | 110-115, 175 | `schedule_queue()` zwraca `false` i przy „już zaplanowane", i przy nieudanym `wp_schedule_single_event()` | Trzy stany zamiast dwóch (`zaplanowano` / `juz_bylo` / `blad`); Dział 9 ma reagować na trzeci |
| A3 | `mp-lead-intake/includes/pipeline/departments/class-mp-department-10.php` | ~87 | `wp_json_encode($r)` bez sprawdzenia; `result_ready` z `$r['success']` | Sprawdzić wynik kodowania; `result_ready` tylko przy udanym `wp_json_encode()` |

**A1 to mój własny kod z 29.07** — przy wyborze statusu zamknięcia rozpatrzyłem
dwa przypadki zamiast trzech. Zapisane wprost, żeby nie zginęło.

### GRUPA B — kontrakt zdarzeń (2 poprawki)

| Lp. | Plik | Linia | Co jest źle | Naprawa |
|---|---|---|---|---|
| B1 | `mp-sales-workflow/includes/pipeline/departments/class-mp-sw-department-05.php` | 160 | `status.change` bez `to_status` → `allowed=true`, status się nie zmienia, sukces raportowany | `fail('missing_target_status', 400)`. Gałąź `'' === $to` zostaje **wyłącznie** dla `task.due` i `dashboard.view` |
| B2 | `mp-sales-workflow/includes/pipeline/departments/class-mp-sw-department-01.php` | ~171 | `actor.user_id` brany z koperty, nigdy porównany z `get_current_user_id()` | Przy `SOURCE_MANUAL` porównać; rozbieżność = odmowa **albo** zapis obu wartości. **Wymaga decyzji klienta** — patrz §5 |

Uprawnienia są sprawdzane przez `current_user_can()`, więc **nie ma eskalacji** —
problem dotyczy wiarygodności dziennika (kto zmienił status).

### GRUPA C — jednosłowna (1 poprawka)

| Lp. | Plik | Linia | Naprawa |
|---|---|---|---|
| C1 | `mp-lead-intake/includes/pipeline/departments/class-mp-department-11.php` | 96 | Domyślne `'checked'` → `'unknown'`. Dziś nieosiągalne (dz. 7 zawsze ustawia status), ale zła wartość domyślna czeka na pierwszą zmianę |

### PO GRUPACH — do uzgodnienia z klientem
- **99 ustaleń średnich**, **84 drobne**, **73 obserwacje** z raportu.
- Przejrzeć grupami tematycznymi, nie po kolei — pary 1.16 (14 ustaleń o zapytaniach
  w pętli) i 1.25/1.26 (180 ustaleń modelu) dominują liczbowo.

---

## 3. Reguły wykonania — obowiązują dla każdej poprawki

1. **Test przed naprawą.** Test musi FAIL-ować na kodzie sprzed poprawki —
   dowód metodą `git stash push` na pliku źródłowym, uruchomienie testu, `git stash pop`.
2. **Test i naprawa w JEDNYM commicie** (pilnuje tego para 2.6 audytu).
3. Po każdej grupie: dopisać wpisy do `audyt/rejestr/znane-bledy.json`,
   żeby para 1.15 pilnowała pokrycia testami.
4. Commit + tag + push automatycznie, konwencja `<wtyczka>/<etykieta>`.
5. Po każdej grupie: przebieg audytu `--glebokosc=pelny` dla potwierdzenia,
   że nic się nie cofnęło (para 2.8 porówna z poprzednim raportem).

---

## 4. Środowisko — stan i przeszkody

| Element | Stan |
|---|---|
| PHP przenośny | `$SCRATCH/php83/php` (pobrać ponownie po zmianie sesji) |
| PHPCS | `$SCRATCH/phpcs-inst/phpcs` — wrapper; podawać przez `MP_AU_PHPCS=` |
| Kontener WP | `lt_wp` (podman, up 2 tyg.) |
| Baza | `lt_db`, dodatkowo `mp-sw-db` (do sprawdzenia, czym jest) |
| **PRZESZKODA** | **W `lt_wp` zainstalowana jest TYLKO `mp-lead-intake`.** `mp-offer-builder` i `mp-sales-workflow` NIE SĄ w katalogu wtyczek — a Grupa A i B dotyczą głównie P3 |
| Pamięć PHP | `wp` wymaga `php -d memory_limit=768M` — bez tego fatal na WooCommerce |

**Pierwszy krok następnej sesji:** ustalić, jak uruchamiać testy końcowe P2 i P3.
Albo podmontować katalogi wtyczek do `lt_wp`, albo sprawdzić, czy `mp-sw-db`
należy do osobnego środowiska. Bez tego nie da się spełnić reguły nr 1.

Wywołanie wp-cli: `podman exec lt_wp php -d memory_limit=768M /usr/local/bin/wp --allow-root --path=/var/www/html <polecenie>`

---

## 5. Otwarte decyzje dla klienta

1. **B2 — podszywanie się pod innego użytkownika.** Czy administrator ma mieć
   prawo wykonać akcję „w imieniu" handlowca (wtedy zapisujemy obie wartości:
   aktora deklarowanego i faktycznie zalogowanego), czy rozbieżność ma być
   twardą odmową?
2. **P1-K1 z lipca** — formularz deklaruje 27 krajów UE, walidacja przyjmuje
   wyłącznie polski NIP. Nadal nierozstrzygnięte.

---

## 6. Poprawki w samym narzędziu audytowym (osobna ścieżka)

- **Dossier pary 1.25 jest za ciasne.** Zawiera jeden plik działu, więc model nie
  widzi `hooks.php` ani sąsiednich działów. **Trzy z dziewięciu fałszywych alarmów
  wzięły się wyłącznie stąd.** Dołączyć do dossier: plik haków wtyczki, słownik
  statusów, definicje zdarzeń.
- Para 2.11 zdążyła ocenić 36 z 203 ustaleń modelu — rozważyć wyższy budżet
  albo priorytet dla ustaleń krytycznych i średnich.
- 5 z 65 zapytań wróciło puste. Sprawdzić, czy ponowna próba wystarcza.
