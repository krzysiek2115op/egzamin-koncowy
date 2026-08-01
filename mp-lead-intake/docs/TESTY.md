# Testy — MP Lead Intake

Zbiorczy rejestr wszystkich testów wykonanych podczas budowy wtyczki (Golden Rule #3).
Wersja wtyczki w momencie zamknięcia testów końcowych: **1.2.1**.

**Stan na wydanie 1.3.6 (01.08.2026).** Testy opisane niżej nie są archiwum —
wszystkie są nadal uruchamiane. Ostatni pełny przebieg wykonano na **świeżo
zainstalowanej** bazie (instalacja od zera, w kolejności 1 → 2 → 3): pliki
testowe trzech wtyczek **56 / 56 PASS**, świeża instalacja **16 / 16 PASS**,
harness procesu LP.1 **7 / 7 PASS**, `php -l` na 768 plikach bez błędu,
PHPCS 0 błędów (3 ostrzeżenia, stan bazowy). W 1.3.6 doszły dwa pliki testowe
tej wtyczki — `numer-vat-ue.php` (P1-Z1: numery VAT z całej Unii, 22 asercje)
i `czas-nie-zalezy-od-witryny.php` (P1-Z2: doba Białej listy liczona po
polsku, niezależnie od strefy ustawionej w panelu) — oraz sekcje dołożone do
`komunikat-nip.php`, `biala-lista-niepelna-odpowiedz.php`,
`alarm-mowi-prawde.php` i `ostrzezenia-aktywacji.php`. Wcześniej, w 1.3.5,
przybyły `archiwum-bez-odczytu.php` (P1-G12) oraz rozszerzenia
`vies-brak-pola-isvalid.php` (P1-G13) i harnessu o niezmiennik stref
czasowych 26b (P1-G14). Testy dołożone po 1.2.1 leżą w `tests/naprawy/` — każdy powstał
razem z naprawą konkretnego defektu i FAIL-ował przed nią.

Naprawa P1-Z1 jest przykładem tego, po co ta reguła istnieje. Pierwsza wersja
testu przechodziła na kodzie **przed** naprawą, bo sprawdzała tylko treść
komunikatu o błędzie — a tę zmieniono już w 1.3.5. Dopiero asercja na tym, że
numer z Niderlandów przechodzi w całości (`NL123456789B01`, z literą w środku),
pokazała prawdziwy zakres: czyszczenie usuwało z numeru wszystko poza cyframi
w siedmiu miejscach, więc do systemu VIES szedł numer, którego nikt nie wpisał.
Sama zmiana komunikatu wysłałaby do VIES okaleczony numer i dostała w odpowiedzi
„nieważny" — czyli ten sam skutek, tylko trudniejszy do zdiagnozowania.

Podobnie przy P1-Z2: audyt wskazał **jedno** miejsce liczące dobę strefą
witryny, a sonda znalazła **trzy**. Test porównuje klucz pamięci podręcznej przy
dwóch strefach witryny oddalonych o 22 godziny (Pacific/Honolulu i
Pacific/Auckland) — przy tej samej chwili UTC dają one różne dni kalendarzowe,
więc kod pytający Białą listę o „dziś" wg panelu pytał o inny dzień niż ten,
którego dotyczy odpowiedź rejestru.

## 1. Statyka kodu

| Test | Wynik | Narzędzie |
|---|---|---|
| `php -l` (składnia) — wszystkie pliki PHP | **PASS** (38/38, później rozszerzane wraz z nowymi plikami) | Przenośny PHP 8.3.32 CLI |
| PHPCS (standard WordPress/WPCS) — kod produkcyjny | **PASS** (0 błędów, 0 ostrzeżeń) | WPCS 3.4 + PHPCS 3.13, ruleset `.phpcs.xml.dist` |

Pierwsze uruchomienie PHPCS zwróciło 295 błędów + 15 ostrzeżeń — po naprawach (nonce w AJAX,
docblocki, itd.) i udokumentowanym złagodzeniu kilku sniffów (np. jeden plik = jeden dział zamiast
jedna klasa, DB-sniffy wyłączone tam gdzie wtyczka celowo operuje na własnych tabelach) osiągnięto
zero błędów na kodzie produkcyjnym. Katalog `tests/` jest świadomie wykluczony z rulesetu.

## 2. Automatyczny harness procesu (`tests/process-harness/`)

Osobny, uruchamiany poza WordPressem proces (`php run-process.php`) budujący pełny pipeline
(11 działów) na stubach WP i przepuszczający przez niego serię scenariuszy + sprawdzający
niezmienniki procesu w pętli `while`. Rozwijany równolegle z kodem — finalnie:

- **7/7 scenariuszy** (happy path, pusty formularz, zły NIP, zły e-mail, brak RODO, honeypot,
  zły nonce) — PASS.
- **20/20 niezmienników procesu**, w tym: jednokierunkowość pipeline'u, dedup po NIP, reaktywacja
  zarchiwizowanego leada, transakcyjność (ROLLBACK przy awarii zapisu / COMMIT przy sukcesie),
  anonimizacja IP (RODO), asynchroniczna weryfikacja VAT w tle (cache-miss/worker/idempotencja/
  zły VAT/kolejkowanie/reconcile/reset przy reaktywacji), zachowanie `company_status` przy
  częściowej awarii usług zewnętrznych, oraz licznik rate-limit liczący próby odrzucone
  wcześnie w pipeline (dodane po buqu ze scenariusza 8, patrz niżej).

## 3. Audyty wielo-agentowe ("psy")

Dwie pełne rundy niezależnych, równoległych subagentów (Opus), każdy z inną soczewką na cały
kod + dokumentację wtyczki:

- **Runda 1** (przed transakcjami/RODO) — 6 agentów, 13 naprawionych znalezisk (m.in. pre-gate
  DoS honeypot+rate-limit, reaktywacja zarchiwizowanego NIP, VIES `MS_UNAVAILABLE`). Raport:
  `docs/AUDYT.md`.
- **Runda 2 — audyt ostateczny przed produkcją** (po P-1 async i zmianach menu/SEO) — 6 agentów
  (bezpieczeństwo, architektura, jakość kodu, wydajność, dokumentacja/Golden Rule #2, kryteria
  odbioru/zakres). Oceny: bezpieczeństwo 90/100, architektura 86/100, jakość kodu 86/100,
  wydajność 86/100 (z 70 — potwierdzony fix async VAT), dokumentacja 70/100, kryteria odbioru
  78/100. 6 znalezisk naprawionych (m.in. cicha utrata scoringu przy WL-down, niespójność stref
  czasowych, "lepka" flaga menu, martwe odczyty działu 1, brakujące pola KROK 1 formularza).
  Raport: `docs/DEBUG-RAPORT.md` §16.

## 4. Testy końcowe — 10 scenariuszy na żywym WordPressie

Zgodnie z Golden Rule #1 (kryteria odbioru: min. 10 scenariuszy testowych) i Golden Rule #3
(testy końcowe dopiero na gotowym produkcie, na żywym WP) — wykonane **2026-07-22** na
WordPress Playground, motyw **kredyt-kompas** (rzeczywisty motyw docelowy, bez rejestracji
`register_nav_menu()`), wtyczka w wersji **1.2.1**.

Pomocniczo zbudowane narzędzie diagnostyczne **MP Test Viewer** (`tools/mp-test-viewer/`,
osobna wtyczka, NIGDY nie pakowana do paczki klienta) — read-only podgląd `wp_mp_leads`,
`wp_mp_activity_log`, `wp_mp_offers` i zaplanowanych zadań WP-Cron, potrzebny bo formularz w
przeglądarce z zasady pokazuje tylko ogólny komunikat sukcesu/błędu (bez ujawniania szczegółów
klientowi — świadoma decyzja bezpieczeństwa), a testy chciały potwierdzić fakty na poziomie bazy
(dedup, scoring, log zdarzeń).

| # | Scenariusz | Wynik | Uwagi |
|---|---|---|---|
| 1 | Instalacja/aktywacja na czysto | **PASS** | Role i zadania cron utworzone; jawne ostrzeżenie o braku menu w motywie zadziałało |
| 2 | Happy path, firma PL | **PASS** | Lead utworzony, `vat_status=pending`, handlowiec przypisany |
| 3 | Firma zagraniczna UE (DE) | **PASS** | Pole kraju z formularza poprawnie zapisane i użyte |
| 4 | Błędny NIP (zła suma kontrolna) | **PASS** | Odrzucone, zero wierszy w bazie |
| 5 | Duplikat (ten sam NIP drugi raz) | **PASS** | Zero nowych wierszy — kryterium odbioru "brak duplikatów" potwierdzone |
| 6 | Brak wymaganej zgody RODO | **PASS** | Backend odrzuca niezależnie od walidacji przeglądarki |
| 7 | Honeypot (antyspam) | **PASS** | Odrzucone w pre-gate, przed pipeline'em |
| 8 | Rate-limit | **PASS*** | Bug znaleziony i naprawiony w trakcie — patrz sekcja 5 |
| 9 | Async VAT w tle + log zdarzeń | **PASS** | Bounded retry (max 5 prób), pełna historia w logu aktywności |
| 10 | Menu + responsywność + SEO | **PASS** | Menu i responsywność potwierdzone live; SEO meta zweryfikowane w kodzie (brak dostępu do podglądu źródła strony w tej sesji) |

**Środowiskowe ograniczenie:** WordPress Playground (PHP-WASM w przeglądarce) nie ma realnego
dostępu do internetu, więc VIES/Biała lista VAT nigdy realnie nie odpowiadają w tym środowisku —
mechanizm bounded-retry (scenariusz 9) i tak został w pełni potwierdzony (5 prób → `unknown`,
zgodnie z projektem), a poprawny wybór kraju dla VIES (scenariusz 3) jest dodatkowo pokryty
harnessem z kontrolowanymi odpowiedziami HTTP.

## 5. Bug znaleziony w testach manualnych (naprawiony)

**Scenariusz 8 (rate-limit)** ujawnił, że licznik zgłoszeń nigdy nie blokował floodu zgłoszeń
z błędnymi danymi (np. NIP z niepoprawną sumą kontrolną) — inkrement licznika siedział wyłącznie
w dziale 5, do którego takie zgłoszenia nigdy nie docierały (odpadały wcześniej, w dziale 3).
Naprawione przeniesieniem inkrementu do pre-gate w `class-mp-ajax.php` (obok istniejącego
sprawdzenia honeypota), tak by liczyła się każda próba niezależnie od tego, gdzie później
odpadnie w pipeline. Potwierdzone ponownie live po wgraniu poprawki (wersja 1.2.1). Pełny opis
techniczny: `docs/DEBUG-RAPORT.md` §17.

## Podsumowanie

Wszystkie **10/10** scenariuszy końcowych zaliczone. Golden Rule #3 spełniona. Jedyny defekt
znaleziony podczas tej fazy (rate-limit) został naprawiony i ponownie zweryfikowany zarówno
automatycznie (nowy niezmiennik harnessu), jak i live na żywym WordPressie — dowód wartości
testów manualnych jako niezależnej warstwy weryfikacji, uzupełniającej audyty i harness.

---

## Stan na wydanie 1.3.7 (01.08.2026)

Pełna regresja: **65 / 65 PASS** (63 pliki testowe przez `wp eval-file`
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