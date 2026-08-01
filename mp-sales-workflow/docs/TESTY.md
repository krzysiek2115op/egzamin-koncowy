# Testy — MP Sales Workflow (LP.3)

Plik zbiorczy wymagany przez Golden Rule #3. Opisuje testy **wykonane**, nie
planowane, z podaniem środowiska i tego, co każdy z nich naprawdę sprawdza.

**Data ostatniego pełnego przebiegu:** 2026-08-01 (wydanie 1.3.4)
**Wersja wtyczki:** 1.3.4 · schemat bazy 0.4.0

Przebieg z 01.08.2026 wykonany na **świeżo zainstalowanej** bazie — instalacja od
zera w kolejności 1 → 2 → 3, potem cały zestaw. To istotne rozróżnienie: baza
gromadząca dane z wcześniejszych przebiegów potrafi ukryć błąd instalacji, bo
brakująca tabela albo kolumna już tam stoi z poprzedniego razu.

| Zestaw | Wynik |
|---|---|
| Pliki testowe trzech wtyczek (`tests/koncowe`, `tests/naprawy`, `tests/security`) | **50 / 50 plików PASS** |
| Świeża instalacja + pierwszy przebieg (`test-swieza-instalacja`) | 16 / 16 PASS |
| Harness procesu LP.1 | 7 / 7 PASS |
| Harness procesu LP.2 | 110 / 110 PASS |
| PHPCS (cały projekt) | 0 błędów, 3 ostrzeżenia (stan bazowy) |
| Bramka audytu `--glebokosc=pelny` | WERDYKT **GO** |

Tabela w sekcji 3 opisuje starszy przebieg skryptów deweloperskich z 28.07.2026
i zostaje jako zapis tamtego stanu — to inny zestaw niż pliki testowe wyżej.

---

## Środowisko

| Element | Wartość |
|---|---|
| WordPress | 7.0.2 |
| Baza | MariaDB 11.8.8 (InnoDB, prawdziwy MySQL — nie SQLite) |
| PHP | 8.x w kontenerze `wordpress:cli` |
| Aktywne wtyczki | mp-lead-intake 1.2.3 · mp-offer-builder 1.0.5 · mp-sales-workflow 1.0.0 · WooCommerce 10.9.4 |

Baza działa w kontenerze podman `--network=none`; klient WP-CLI dołącza do jej
przestrzeni sieciowej, więc `DB_HOST=127.0.0.1` idzie po loopbacku. Brak wyjścia
w internet jest **celowy** — wymusza sprawdzenie, że wtyczka nie potrzebuje
połączeń sieciowych w trakcie obsługi żądania.

---

## 1. Testy końcowe — 10 scenariuszy na żywym WordPressie

Plik: `tests/koncowe/scenariusze-1-10.php` · uruchomienie: `wp eval-file …`

**Wynik: 97 / 97 PASS. Dwa niezależne przebiegi, oba ALL_PASS** (drugi przebieg
potwierdza, że test nie zależy od stanu zostawionego przez pierwszy).

| # | Scenariusz | Co realnie sprawdza | Kryterium |
|---|---|---|---|
| S1 | Instalacja i schemat | 5 tabel, wersja schematu w bazie zgodna z `MP_Sales_Workflow_DB::DB_VERSION`, oba więzy `ON DELETE CASCADE`, silnik InnoDB, obie role, oba zadania cron, kolumny `claim_token`/`claimed_at` | — |
| S2 | Lead przez trzy wtyczki | **Prawdziwy pipeline LP.1** (11 działów, z tokenem CSRF) → `mp_lead_created` → LP.2 zakłada szkic oferty → LP.3 zakłada proces. Dokładnie jeden lead, jeden proces | 5.1 |
| S3 | Przypisanie handlowca | Lead z PL trafia do handlowca obsługującego PL; kraj bez obsługi (FR) nie zostaje bez opiekuna — działa awaryjne przekazanie | 5.4 |
| S4 | Oferta z LP.2 | `mp_offer_created` przestawia status i zapisuje `offer_id`; ten sam typ zdarzenia **z kanału ręcznego odrzucony** | 5.1 |
| S5 | E-mail po akceptacji | `mp_offer_approved` → kolejka → `wp_mail()` **po COMMIT**; adres z bazy LP.1, temat bez CR/LF, w treści podpisany link, brak odnośnika do `wp-admin`, zapisana wersja szablonu. Dodatkowo **zatwierdzenie z pulpitu** (formularz POST, ten sam token sprawdzany dwa razy): status przechodzi na *oferta wysłana*, powiadomienie ląduje w kolejce, panel potwierdza operację; podrobiony token niczego nie zapisuje | **4.4** |
| S6 | Podpisany link | HMAC-SHA256 (64 znaki hex), ważność ≤ 14 dni; podmiana podpisu, przesunięcie terminu i podstawienie innej oferty — każde unieważnia link | — |
| S7 | Follow-up d+3 / d+7 | Zadania zaplanowane z wartownikiem; **zadanie z niepasującym wartownikiem nie zmienia procesu**; najwyżej jedno otwarte zadanie danego typu; zamiatanie poza kontekstem crona nic nie robi | **4.5** |
| S8 | Role i zakres | Handlowiec: własne. Manager: zespół, bez całej firmy. Administrator: wszystko. **Cudzy proces = 404, identycznie jak nieistniejący**; właściciel nie do podmiany | **5.4** |
| S9 | Dziennik | Zawiera zmianę statusu i wysyłkę powiadomienia; **bez adresu e-mail i bez IP**; każdy wpis zna sprawcę; wpis przeżywa nieistniejący proces. Dziennik **widoczny w panelu**, a obcy użytkownik nie zobaczy cudzej historii | **5.5** |
| S10 | Idempotencja i wyścigi | Powtórzony `event_id` nie zapisuje drugi raz i nie wysyła drugiego e-maila; jeden wiersz w rejestrze; zapis ze starym tokenem blokady rusza 0 wierszy; handlowiec nie przepisze sobie zespołu | 5.1 |

### Błąd znaleziony i naprawiony w trakcie tych testów

**S4 — koperta gubiła `offer_id` przy `status.change`.** Dział 1 budował encję
zdarzenia wyłącznie z pól **wymaganych** dla danego typu, a dla `status.change`
wymagany jest sam `lead_id`. Skutek: `mp_offer_created` z wtyczki 2 przestawiał
proces na „oferta w przygotowaniu", ale proces nie zapamiętywał, **której oferty**
dotyczy. Ponieważ `mp_offer_approved` nie jest w tej wersji przez nikogo
emitowane, brak nigdy by się nie uzupełnił — na pulpicie zostawał proces bez
numeru oferty.

Naprawa: `MP_SW_D1::optional_entity_fields()` — pola nieobowiązkowe przepisywane
z zamkniętej listy dla danego typu. Lista pozostaje zamknięta, więc wywołujący
nadal nie wstrzyknie do koperty dowolnego klucza.

---

## 2. Kompatybilność trzech wtyczek na jednej instalacji

Plik: `tests/koncowe/kompatybilnosc-3-wtyczek.php`

**Wynik: 62 / 62 PASS.** Każda sekcja bada jedną przestrzeń nazw, w której
wtyczki WordPressa realnie się zderzają.

| Sekcja | Wynik |
|---|---|
| 1. Trzy wtyczki aktywne, klasy załadowane | PASS |
| 2. Tabele — trzy rozłączne zestawy (3 + 5 + 5), żadna nazwa się nie powtarza; `mp_offers` (LP.1) ≠ `mp_ob_offers` (LP.2) | PASS |
| 3. Opcje `wp_options` — brak opcji `mp_*` poza trzema prefiksami wtyczek | PASS |
| 4. Zadania cron — każde ma jednoznacznego właściciela | PASS |
| 5. Role — obie role są **wspólne i tak ma być**; każda wtyczka dołożyła swoje uprawnienia, żadna nie skasowała cudzych; administrator zachował `manage_options`, `edit_posts`, `activate_plugins`, `read`. **Żadne dwie role `mp_*` nie mają tej samej nazwy wyświetlanej** — kontrola dołożona w v1.1.0, bo poprzednia wersja testu przepuściła dwa Managery sprzedaży | PASS |
| 6. Haki integracyjne — LP.2 i LP.3 słuchają **tego samego** `mp_lead_created`, nie odbierając go sobie; LP.3 nie wpina się w wewnętrzne haki LP.1 | PASS |
| 7. Punkty AJAX — brak zdublowanych akcji; jedyny publiczny to `mp_lead_intake_submit` (LP.1, formularz); punkt LP.3 **niedostępny dla niezalogowanych** | PASS |
| 8. Menu panelu — jedna pozycja, bez nadpisywania | PASS |
| 9. Klasy — 115 (LP.3) + 84 (LP.2) + 79 (LP.1/wspólne), **żadna zadeklarowana dwa razy** | PASS |
| 10. Metadane użytkownika — brak nieoczekiwanych kluczy `mp_sw_*`; strażnik przepuszcza zapis administratora, więc nie blokuje konfiguracji LP.1 | PASS |
| 11. Izolacja danych — wszystkie trzy bazy mają dane po wspólnym przebiegu; **żaden więz nie przechodzi między bazami różnych wtyczek**; LP.3 domyślnie nie kasuje danych przy deinstalacji | PASS |
| 12. Powtórna rejestracja nie dubluje słuchaczy — ani na hakach, ani na punkcie AJAX | PASS |

> Sekcja 12 celowo **nie** odpala ponownie `init`/`admin_init`: w kontekście
> WP-CLI wywraca się na tym WooCommerce (brak ekranu panelu), co nie ma związku
> z naszymi wtyczkami. Zamiast tego sprawdzana jest idempotencja rejestracji —
> bo najgroźniejsza kolizja nie polega na tym, że coś przestaje działać, tylko
> że dzieje się **dwa razy**: dwóch słuchaczy `mp_lead_created` to dwa procesy
> i dwa e-maile do klienta.

### Współistnienie kluczy metadanych

W bazie stoją obok siebie klucze LP.1 (`mp_country`, `mp_team`) i LP.3
(`mp_sw_country`, `mp_sw_langs`, `mp_sw_team`, `mp_sw_active`). To **nie jest
kolizja** — to dwa różne zestawy. Strażnik metadanych LP.3 chroni cały prefiks
`mp_`, więc obejmuje też klucze LP.1, i przepuszcza zapis wykonywany przez
użytkownika z uprawnieniem `promote_users`.

---

## 3. Testy wewnętrzne (regresja) — przebieg z 28.07.2026

Skrypty deweloperskie (`~/mp-test-env/scr`), uruchamiane na tej samej instalacji
przez `wp eval-file`. Zestaw INNY niż wersjonowane pliki testowe z nagłówka:
te skrypty nie są częścią wtyczki i nie wchodzą do paczki dla klienta.

| Zestaw | Zakres | Wynik |
|---|---|---|
| `test-d1` … `test-d9` | Każdy dział osobno (agenci, krytycy, bramka) | 36 · 35 · 19 · 20 · 27 · 36 · 44 · 53 · 47 |
| `test-pipeline` | Przebieg D1→D9, STOP krytyka, STOP bramki, granice transakcji | 41 |
| `soak` | Przebieg kontrolny, wiele zdarzeń pod rząd | 26 |
| `test-bramka` | Integracja P1→P2→P3 (F2 słownik VAT, F3 właściciel szkicu, F4 `lead_id` w zdarzeniu oferty) | 23 |
| `test-sec` | Scenariusze bezpieczeństwa S1–S12 + raport inwariantów I-1…I-6 | 98 |
| **Razem LP.3** | | **505 / 505 PASS** |
| Harness LP.1 (proces poza WP) | 7 scenariuszy + niezmienniki | 7 / 7 PASS |
| Harness LP.2 (proces poza WP) | scenariusze + niezmienniki | 110 / 110 PASS |

### Poprawka w teście bramki integracyjnej

`test-bramka` po naprawie z sekcji 1 zaczął zgłaszać `F4`. Powód: test emitował
`mp_offer_created` ze **zmyślonym** numerem oferty (5501), którego nie ma w
`wp_mp_ob_offers`. Wcześniej `offer_id` był po drodze gubiony, więc kontrola
dziedzinowa Działu 2 nigdy się nie uruchamiała; po naprawie oferta jest
sprawdzana i numer wzięty z sufitu jest **poprawnie odrzucany** jako próba
podszycia.

Poprawiony został test, nie kod: fixture używa teraz prawdziwego identyfikatora
szkicu utworzonego przez wtyczkę 2. Zachowanie produkcyjne — odmowa dla
nieistniejącej oferty — jest zamierzone i zostaje.

---

## 4. Statyczna analiza

| Narzędzie | Zakres | Wynik |
|---|---|---|
| `php -l` | pliki zmienione w tej rundzie | bez błędów |
| PHPCS (WordPress, ruleset `.phpcs.xml.dist`) | `includes/` — cały kod produkcyjny | **0 błędów**, 3 ostrzeżenia |

Trzy ostrzeżenia to nieużywane parametry w sygnaturach filtrów WordPressa
(`class-mp-sw-meta-guard.php`, `class-mp-sw-privacy.php`) — sygnatury narzuca
rdzeń, więc parametrów nie da się usunąć. Katalog `tests/` jest z rulesetu
wyłączony (harness świadomie łamie WPCS).

---

## 5. Czego te testy NIE sprawdzają

Uczciwa granica zakresu:

- **Realna wysyłka SMTP.** Kontener nie ma wyjścia sieciowego; `wp_mail()` jest
  przechwytywane filtrem `pre_wp_mail`, więc sprawdzamy treść i adresata, a nie
  dostarczalność. Konfigurację SPF/DKIM/DMARC weryfikuje się po wdrożeniu —
  procedura w `docs/WDROZENIE.md`.
- **Weryfikacja NIP w rejestrach zewnętrznych (LP.1).** Bez sieci status VAT
  osiada na `pending`/`unknown` po wyczerpaniu ponowień. Nie wpływa to na
  scenariusze LP.3, bo proces sprzedażowy nie zależy od statusu VAT.
- **Wygląd pulpitu w przeglądarce.** Sprawdzane są uprawnienia i zapytania stojące
  za widokiem, nie warstwa wizualna.
- **Fizyczne pobranie pliku PDF przez HTTP.** Sprawdzany jest podpis, termin
  ważności i odporność na podmianę; samo strumieniowanie pliku wymaga serwera WWW,
  którego w tym środowisku nie ma.
