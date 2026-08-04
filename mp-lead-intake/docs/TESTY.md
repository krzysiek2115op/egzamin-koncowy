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

## Stan na wydanie 1.3.11 (04.08.2026)

Regresja **89/89** na bazie od zera, PHPCS kod wyjścia **0**. Cztery nowe pliki
testowe, każdy napisany PRZED naprawą i uruchomiony, żeby zobaczyć, jak pada:

- `mp-sales-workflow/tests/naprawy/segment-dociera-do-procesu.php` (11 asercji) —
  segment ze zgłoszenia trafia do wiersza procesu i na ekran, także przy
  **pierwszym** zdarzeniu, gdy wiersza jeszcze nie ma.
- `mp-lead-intake/tests/naprawy/link-do-strony-ktorej-nie-ma.php` (10) — adres
  strony z formularzem nie powstaje dla wpisu w koszu, w szkicu ani prywatnego.
- `mp-lead-intake/tests/naprawy/dziennik-mowi-co-sie-stalo.php` (10) — opis wpisu
  o zatrzymaniu niesie powód słowami, nie sam kod maszynowy.
- `mp-lead-intake/tests/naprawy/stary-cache-vies-nie-rozstrzyga.php` (6) — wpis
  w cache sprzed aktualizacji nie udaje rozstrzygniętego werdyktu.

Do tego trzy pliki z rundy drobnych ustaleń:
`mp-sales-workflow/tests/naprawy/czas-i-slownik-statusow.php` (19),
`mp-lead-intake/tests/naprawy/slownik-alarmu-i-krotki-kod.php` (18),
`mp-offer-builder/tests/naprawy/stawka-vat-i-komunikat-dokumentu.php` (16)
oraz test motywu demo `tools/strona-pokazowa/tests/demo-nie-klamie-przegladarce.php` (12).

### Reguła, która chroniła błąd

Kontr-asercja D3 w `mp-offer-builder/tests/naprawy/plan-zapisu-dzial-10.php`
deklarowała w komentarzu odwrotne obciążenie, a uruchamiała kontekst **krajowy**.
Przechodziła — bo kod nie sprawdzał mechanizmu. Pilnowała więc dokładnie tego
cichego 0% VAT, przed którym miała bronić. Zawężona do przypadku, który zawsze
deklarowała, plus nowa asercja D4 na przypadek krajowy.

Metoda z poprzednich rund bez zmian: **kod zły → naprawa; reguła zła → zawężenie
reguły i test na to zawężenie.**

## Stan na wydanie 1.3.10 (04.08.2026)

Regresja **79/79** na bazie od zera. Trzy nowe pliki testowe w tym wydaniu:

- `mp-sales-workflow/tests/koncowe/paczka-bez-kodu-uruchamialnego.php` — żaden plik
  PHP z paczki instalacyjnej nie wykonuje się po wejściu na jego adres
  z przeglądarki. Przed naprawą: cztery pliki, w tym harness procesu tej wtyczki
  i benchmark, oba działające **bez WordPressa**.
- `mp-sales-workflow/tests/koncowe/szablon-tlumaczen.php` — każda wtyczka dostarcza
  `languages/<slug>.pot`, deklaruje `Domain Path` i ma szablon z TEJ wersji.
- `mp-sales-workflow/tests/naprawy/handlowiec-konfigurowalny-z-panelu.php` —
  konfiguracja handlowca z panelu, bez konsoli.

Pułapka wychwycona przy pisaniu pierwszego z nich: sonda czytała 2000 **bajtów**
początku pliku, szukając strażnika. Polskie znaki w komentarzu zajmują po dwa
bajty, więc okno kończyło się przed linią, której szukała — i zgłosiła jako
bezbronny plik, który strażnika ma w linii 39. Mierzyła długość komentarza,
nie obecność zabezpieczenia.

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