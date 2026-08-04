# Zapis z przebiegu testów odbioru

**Data przebiegu:** 4 sierpnia 2026 · **Wersja:** 1.3.10 · **Gałąź:** `main`

Kryteria odbioru mówią o dziesięciu scenariuszach. Repozytorium zawierało kod
tych scenariuszy i nie zawierało **ani jednego zapisu z ich wykonania** (U-7):
o tym, że przeszły, wiadomo było wyłącznie z opisu w changelogu. Ten plik jest
surowym wyjściem narzędzi, nie streszczeniem — łącznie z ostrzeżeniami.

Odtworzenie u siebie:

```
tools/test-env/wp.sh eval-file /scr/test-swieza-instalacja.php   # instalacja od zera
tools/test-env/regresja.sh                                        # cała regresja
tools/test-env/wp.sh eval-file wp-content/plugins/mp-sales-workflow/tests/koncowe/scenariusze-1-10.php
```

Środowisko: WordPress + MariaDB (podman), trzy wtyczki podmontowane z drzewa
roboczego gałęzi `main`, PHP 8.3. Ten sam zestaw plików testowych uruchamia CI
przy każdym pushu — zadanie `integracja` w `.github/workflows/ci.yml`.

Przebieg wykonano na bazie postawionej od zera **i na świeżych kontach**.
To dwie różne rzeczy: `test-swieza-instalacja.php` kasuje tabele, ale nie
użytkowników, a konta pozostałe po wcześniejszych testach przez całą serię
wydań ukrywały błąd pierwszego zgłoszenia (U-18).

W tym przebiegu regresja **złapała porażkę** — pierwszą od wielu wydań — i to
w sondzie, nie w produkcie: test Białej listy liczył „dzisiejszą datę" w UTC,
podczas gdy wtyczka liczy dobę tego rejestru w strefie polskiej. Różnica jest
widoczna wyłącznie między północą a drugą w nocy czasu polskiego. Szczegóły
w rejestrze znanych błędów, wpis U-27.

---

## 1. Instalacja od zera (kolejność 1 → 2 → 3)

```
=== CZYSZCZENIE: stan sprzed pierwszej instalacji ===
  [PASS] stan czysty: zadna tabela wtyczek nie istnieje
  [PASS] stan czysty: rola handlowca nie istnieje

=== INSTALACJA: kolejnosc 1 -> 2 -> 3 ===
  [PASS] P1: tabele BD-3 utworzone
  [PASS] P1: rola handlowca zalozona
  [PASS] P2: tabele BD-2 utworzone
  [PASS] P2: wersja schematu zapisana
  [PASS] P2: zasiane DWA szablony oferty (PL + EN) — bez nich nie powstanie zadna oferta
  [PASS] P2: swiezy szablon PL ma numer oferty na dokumencie (kryt. 5.3)
  [PASS] P3: tabele BD-1 utworzone
  [PASS] P3: role zainstalowane
  [PASS] P3: harmonogram zaplanowany
  [PASS] P3: wiezy obce zalozone (InnoDB)

=== PIERWSZY PRZEBIEG NA SWIEZEJ INSTALACJI ===
  [PASS] P3: proces sprzedazowy powstal na swiezej instalacji
  [PASS] P2: szkic oferty powstal na swiezej instalacji
  [PASS] P2: prosba klienta przeszla z formularza (bez recznego kopiowania)
  [PASS] P3: dziennik aktywnosci zapisany od pierwszego zdarzenia (kryt. 5.5)

----- PASS: 16 / FAIL: 0 -----
VERDICT_ALL_PASS
```

---

## 2. Dziesięć scenariuszy odbioru

```
[03-Aug-2026 12:40:46 UTC] [MP Sales Workflow] level=SECURITY code=MP3-E111 at=2026-08-03T12:40:46+00:00 type=task.due source=cron reason=sweep_outside_cron user_id=1668 ip_hash=db0f9b80a3fc6a3a18579fdfe68c724b2f448afe3eea94b2c0015e01c89549a2
[03-Aug-2026 12:40:46 UTC] [MP Sales Workflow] level=SECURITY code=MP3-E111 at=2026-08-03T12:40:46+00:00 type=task.due source=cron reason=sweep_outside_cron user_id=1668 ip_hash=db0f9b80a3fc6a3a18579fdfe68c724b2f448afe3eea94b2c0015e01c89549a2
[03-Aug-2026 12:40:46 UTC] [MP Sales Workflow] level=SECURITY code=MP3-E101 at=2026-08-03T12:40:46+00:00 reason=protected_meta user_id=1668 ip_hash=db0f9b80a3fc6a3a18579fdfe68c724b2f448afe3eea94b2c0015e01c89549a2

=== S1/10 — instalacja i schemat na zywym WP ===
  [PASS] tabela wp_mp_sw_flow istnieje
  [PASS] tabela wp_mp_sw_tasks istnieje
  [PASS] tabela wp_mp_sw_notifications istnieje
  [PASS] tabela wp_mp_sw_activity istnieje
  [PASS] tabela wp_mp_sw_events istnieje
  [PASS] wersja schematu w bazie = MP_Sales_Workflow_DB::DB_VERSION (0.4.0)
  [PASS] wiez fk_mp_sw_tasks_flow zalozony
  [PASS] wiez fk_mp_sw_notifications_flow zalozony
  [PASS] silnik tabeli procesow to InnoDB
  [PASS] rola mp_handlowiec istnieje
  [PASS] rola mp_manager_sprzedazy istnieje
  [PASS] zadanie cron mp_sw_sweep_tasks zaplanowane
  [PASS] zadanie cron mp_sw_retention zaplanowane
  [PASS] tabela zadan ma claim_token i claimed_at

=== S2/10 — lead z LP.1 przechodzi przez wszystkie trzy wtyczki ===
  [PASS] konta testowe zalozone (manager + 2 handlowcow)
  [PASS] pipeline LP.1 zakonczony sukcesem
  [PASS] lead zapisany w BD-3 (wp_mp_leads)
  [PASS] dokladnie JEDEN nowy lead
  [PASS] LP.2 zalozyl szkic oferty dla leada (reakcja na ten sam hak)
  [PASS] LP.3 zalozyl proces sprzedazowy dla tego samego leada
  [PASS] dokladnie JEDEN nowy proces
  [PASS] proces zna adres klienta (z bazy, nie z koperty)
  [PASS] proces ma przypisanego handlowca
  [PASS] proces ma token blokady optymistycznej

=== S3/10 — przypisanie handlowca po kraju i jezyku (kryt. 5.4) ===
  [PASS] lead z PL dostal opiekuna
  [PASS] opiekun obsluguje kraj klienta (albo zadzialal fallback do managera)
  [PASS] proces dla kraju bez obslugi (FR) mimo wszystko powstal
  [PASS] proces NIE zostal bez opiekuna
  [PASS] zadzialal fallback (manager / oznaczenie awaryjne)

=== S4/10 — oferta z LP.2 przestawia status procesu ===
  [PASS] fixture: numer i dokument ustawione na ofercie
  [PASS] proces zapamietal identyfikator oferty
  [PASS] status procesu zmienil sie po zdarzeniu z LP.2
  [PASS] token blokady wzrosl przy zapisie
  [PASS] offer.approved z panelu (kanal reczny) jest niedozwolone
  [PASS] offer.approved z haka systemowego jest dozwolone

=== S5/10 — e-mail do klienta po akceptacji oferty (kryt. 4.4) ===
  [PASS] powiadomienia trafily do kolejki
  [PASS] w kolejce jest wiadomosc do KLIENTA
  [PASS] wiadomosc ma temat
  [PASS] temat bez znakow konca wiersza (anty-wstrzykniecie naglowka)
  [PASS] tresc zawiera PODPISANY link do oferty
  [PASS] temat zawiera NUMER oferty
  [PASS] tresc zawiera NUMER oferty
  [PASS] numer oferty zapisany w wierszu procesu (zrodlo dla przypomnien)
  [PASS] tresc do klienta NIE zawiera odnosnika do panelu
  [PASS] zapisano wersje szablonu (kryt. K2.5)
  [PASS] kolejka przetworzona
  [PASS] wp_mail() faktycznie wywolane po COMMIT
  [PASS] wiadomosc poszla na adres klienta z BAZY LP.1
  [PASS] pulpit renderuje formularz akcji
  [PASS] formularz niesie token CSRF
  [PASS] formularz pozwala wybrac status docelowy
  [PASS] proces z oferta robocza ma przycisk zatwierdzenia
  [PASS] zatwierdzenie z pulpitu przestawilo status na oferta wyslana
  [PASS] zatwierdzenie z pulpitu dolozylo powiadomienie do kolejki
  [PASS] pulpit potwierdzil operacje komunikatem
  [PASS] zmiana statusu z podrobionym tokenem odrzucona
  [PASS] odrzucona proba niczego nie zapisala

=== S6/10 — podpisany link do oferty ===
  [PASS] link do oferty zbudowany (klucz MP_SW_LINK_KEY obecny)
  [PASS] podpis to HMAC-SHA256 (64 znaki hex)
  [PASS] link ma termin waznosci w przyszlosci
  [PASS] waznosc nie przekracza 14 dni
  [PASS] podpis zgadza sie z przeliczonym na nowo
  [PASS] podmieniony podpis nie przechodzi weryfikacji
  [PASS] przesuniecie terminu waznosci uniewaznia podpis
  [PASS] podpis nie przenosi sie na inna oferte
  [PASS] link nie prowadzi do panelu (klient nie ma konta)

=== S7/10 — follow-up d+3 / d+7 tylko przy niezmienionym statusie (kryt. 4.5) ===
  [PASS] zadania follow-up zaplanowane
  [PASS] kazde zadanie ma wartownika statusu
  [PASS] najwyzej JEDNO otwarte zadanie danego typu na proces (krytyk K6.2)
  [PASS] zadanie z niepasujacym wartownikiem NIE zmienilo procesu
  [PASS] zadanie obsluzone bez uszkodzenia procesu
  [PASS] zamiatanie zadan poza kontekstem crona nic nie zmienia

=== S8/10 — role i zakres widoku (kryt. 5.4) ===
  [PASS] handlowiec ma widok wlasnych procesow
  [PASS] handlowiec NIE ma widoku zespolu
  [PASS] handlowiec NIE ma widoku calej firmy
  [PASS] handlowiec nie moze przypisywac procesow
  [PASS] manager ma widok zespolu
  [PASS] manager NIE widzi calej firmy
  [PASS] manager moze przypisywac procesy
  [PASS] administrator widzi wszystko
  [PASS] administrator ma dostep do ustawien
  [PASS] obcy handlowiec nie zmienil cudzego procesu
  [PASS] obcy proces zwraca 404, nie 403
  [PASS] odpowiedz dla obcego i dla NIEISTNIEJACEGO procesu jest identyczna
  [PASS] wlasciciel procesu nie zostal podmieniony przez obcego

=== S9/10 — dziennik odtwarza historie statusow i wysylek (kryt. 5.5) ===
  [PASS] dziennik ma wpisy dla procesu
  [PASS] dziennik zawiera zmiane statusu
  [PASS] dziennik zawiera wysylke powiadomienia
  [PASS] dziennik NIE zawiera adresu e-mail (RODO)
  [PASS] dziennik NIE zawiera adresu IP (RODO)
  [PASS] kazdy wpis wie, KTO go wywolal (actor_type)
  [PASS] pulpit wyswietla dziennik wybranego procesu
  [PASS] w dzienniku widac zmiane statusu
  [PASS] obcy uzytkownik nie zobaczy dziennika cudzego procesu
  [PASS] wpis dziennika dla NIEISTNIEJACEGO procesu przeszedl (audyt bez wiezu)

=== S10/10 — idempotencja i wspolbieznosc ===
  [PASS] pierwsze zdarzenie obsluzone
  [PASS] POWTORKA tego samego event_id nie zapisala niczego drugi raz
  [PASS] w rejestrze zdarzen dokladnie jeden wiersz na event_id
  [PASS] powtorka nie wygenerowala drugiego powiadomienia
  [PASS] kontrola pozytywna: ten sam UPDATE z WLASCIWYM tokenem rusza dokladnie 1 wiersz
  [PASS] zapis ze starym tokenem blokady nie ruszyl zadnego wiersza (0 wierszy)
  [PASS] handlowiec nie przepisal sobie zespolu (chronione metadane)

================================================
WYNIK: PASS=102  FAIL=0  RAZEM=102
STATUS: ALL_PASS
```

---

## 3. Pełna regresja — wszystkie wersjonowane pliki testowe

```
=== Harnessy (przenośne PHP, bez WordPressa) ===
  [OK ] mp-lead-intake/tests/process-harness/run-process.php
  [OK ] mp-offer-builder/tests/process-harness/run-process.php

=== Narzędzia wydania (Python, bez WordPressa) ===
  [OK ] tools/wydanie/tests/paczka-instalacyjna.py

=== Pliki testowe (WordPress + trzy wtyczki, wp eval-file) ===
  [OK ] mp-lead-intake/tests/koncowe/granica-transakcji-i-role.php
  [OK ] mp-lead-intake/tests/koncowe/relacja-lead-oferta.php
  [OK ] mp-lead-intake/tests/naprawy/a3-payload-json.php
  [OK ] mp-lead-intake/tests/naprawy/alarm-administratora.php
  [OK ] mp-lead-intake/tests/naprawy/alarm-mowi-prawde.php
  [OK ] mp-lead-intake/tests/naprawy/archiwum-bez-odczytu.php
  [OK ] mp-lead-intake/tests/naprawy/biala-lista-niepelna-odpowiedz.php
  [OK ] mp-lead-intake/tests/naprawy/c1-vat-status-domyslny.php
  [OK ] mp-lead-intake/tests/naprawy/czas-nie-zalezy-od-witryny.php
  [OK ] mp-lead-intake/tests/naprawy/dedup-bez-odczytu.php
  [OK ] mp-lead-intake/tests/naprawy/ekran-leadow.php
  [OK ] mp-lead-intake/tests/naprawy/komunikat-nip.php
  [OK ] mp-lead-intake/tests/naprawy/limity-pol-formularza.php
  [OK ] mp-lead-intake/tests/naprawy/numer-vat-ue.php
  [OK ] mp-lead-intake/tests/naprawy/ostrzezenia-aktywacji.php
  [OK ] mp-lead-intake/tests/naprawy/ostrzezenie-o-stronie.php
  [OK ] mp-lead-intake/tests/naprawy/role-wspoldzielone.php
  [OK ] mp-lead-intake/tests/naprawy/status-vat-nieznany.php
  [OK ] mp-lead-intake/tests/naprawy/strona-awaria-nie-milczy.php
  [OK ] mp-lead-intake/tests/naprawy/vies-brak-pola-isvalid.php
  [OK ] mp-lead-intake/tests/naprawy/vies-nie-nadpisuje-potwierdzonego.php
  [OK ] mp-offer-builder/tests/koncowe/ceny-brutto-netto.php
  [OK ] mp-offer-builder/tests/koncowe/pdf-pl-en-numer.php
  [OK ] mp-offer-builder/tests/koncowe/zatwierdzenie-oferty.php
  [OK ] mp-offer-builder/tests/naprawy/alarm-administratora.php
  [OK ] mp-offer-builder/tests/naprawy/alarm-mowi-prawde.php
  [OK ] mp-offer-builder/tests/naprawy/audyt-gleboki.php
  [OK ] mp-offer-builder/tests/naprawy/cena-ujemna.php
  [OK ] mp-offer-builder/tests/naprawy/dokument-ktorego-nie-ma.php
  [OK ] mp-offer-builder/tests/naprawy/ekran-regul-rabatowych.php
  [OK ] mp-offer-builder/tests/naprawy/finalizacja-pdf-a-zdarzenie.php
  [OK ] mp-offer-builder/tests/naprawy/kod-kraju-lista-iso.php
  [OK ] mp-offer-builder/tests/naprawy/komunikat-z-adresu.php
  [OK ] mp-offer-builder/tests/naprawy/plan-zapisu-dzial-10.php
  [OK ] mp-offer-builder/tests/naprawy/podstawa-vat-dwie-skladowe.php
  [OK ] mp-offer-builder/tests/naprawy/podstawa-vat.php
  [OK ] mp-offer-builder/tests/naprawy/produkty-jedna-partia.php
  [OK ] mp-offer-builder/tests/naprawy/promocja-i-podatek-sklepu.php
  [OK ] mp-offer-builder/tests/naprawy/rozdzial-rabatu-i-tozsamosc.php
  [OK ] mp-offer-builder/tests/naprawy/snapshot-cen-mowi-prawde.php
  [OK ] mp-offer-builder/tests/naprawy/status-przez-stala.php
  [OK ] mp-offer-builder/tests/naprawy/stawki-wielokrotne.php
  [OK ] mp-offer-builder/tests/naprawy/strefy-czasu.php
  [OK ] mp-offer-builder/tests/naprawy/wlasciciel-oferty-i-wersji.php
  [OK ] mp-offer-builder/tests/naprawy/zatwierdzenie-komunikaty.php
  [OK ] mp-offer-builder/tests/naprawy/zatwierdzenie-mowi-prawde.php
  [OK ] mp-offer-builder/tests/naprawy/zero-zmienionych-wierszy.php
  [OK ] mp-sales-workflow/tests/koncowe/bramka-integracyjna-p1-p2-p3.php
  [OK ] mp-sales-workflow/tests/koncowe/kompatybilnosc-3-wtyczek.php
  [OK ] mp-sales-workflow/tests/koncowe/link-do-oferty.php
  [OK ] mp-sales-workflow/tests/koncowe/paczka-bez-kodu-uruchamialnego.php
  [OK ] mp-sales-workflow/tests/koncowe/powiadomienia-odbiorcy.php
  [OK ] mp-sales-workflow/tests/koncowe/rodo-anonimizacja.php
  [OK ] mp-sales-workflow/tests/koncowe/scenariusze-1-10.php
  [OK ] mp-sales-workflow/tests/koncowe/szablon-tlumaczen.php
  [OK ] mp-sales-workflow/tests/naprawy/alarm-administratora.php
  [OK ] mp-sales-workflow/tests/naprawy/dokumentacja-jeden-plik.php
  [OK ] mp-sales-workflow/tests/naprawy/ekrany-i-konfiguracja.php
  [OK ] mp-sales-workflow/tests/naprawy/eksport-danych-osobowych.php
  [OK ] mp-sales-workflow/tests/naprawy/grupa-a.php
  [OK ] mp-sales-workflow/tests/naprawy/grupa-b.php
  [OK ] mp-sales-workflow/tests/naprawy/handlowiec-jeden-wybor.php
  [OK ] mp-sales-workflow/tests/naprawy/handlowiec-konfigurowalny-z-panelu.php
  [OK ] mp-sales-workflow/tests/naprawy/kody-odmowy.php
  [OK ] mp-sales-workflow/tests/naprawy/kolejka-klamra.php
  [OK ] mp-sales-workflow/tests/naprawy/komunikaty-dla-czlowieka.php
  [OK ] mp-sales-workflow/tests/naprawy/krytyk-skutkow.php
  [OK ] mp-sales-workflow/tests/naprawy/nadawca-poczty.php
  [OK ] mp-sales-workflow/tests/naprawy/odbiorcy-niekompletni.php
  [OK ] mp-sales-workflow/tests/naprawy/okno-bezpiecznika.php
  [OK ] mp-sales-workflow/tests/naprawy/pierwszy-lead-bez-handlowcow.php
  [OK ] mp-sales-workflow/tests/naprawy/schemat-bez-obietnic.php
  [OK ] mp-sales-workflow/tests/naprawy/status-przez-stala.php
  [OK ] mp-sales-workflow/tests/naprawy/tlumaczenia-ladowane.php
  [OK ] mp-sales-workflow/tests/naprawy/znaczniki-szablonu.php
  [OK ] mp-sales-workflow/tests/security/scenariusze-s1-s12.php

=== Strona pokazowa (motyw demo, wp eval-file) ===
  [OK ] tools/strona-pokazowa/tests/demo-nie-lamie-wlasnej-polityki.php
  [OK ] tools/strona-pokazowa/tests/motyw-poza-korzeniem.php

====================================================
PRZESZLO: 81   NIE PRZESZLO: 0   BEZ WERDYKTU: 0
```

---

## 4. PHPCS wspólnym `.phpcs.xml.dist`

Uruchamiany **bez argumentu ścieżki** — zestaw reguł ma własną listę plików.
Liczy się kod wyjścia, nie samo podsumowanie: PHPCS kończy się jedynką także
przy samych ostrzeżeniach.

```
............................................................  60 / 160 (38%)
............................................................ 120 / 160 (75%)
........................................                     160 / 160 (100%)


Time: 23.54 secs; Memory: 38MB
echo $?  # 0
```
