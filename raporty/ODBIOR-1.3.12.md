# Odbior 1.3.12 — czysta instalacja z POBRANYCH paczek

Data: 2026-08-05. WordPress 7.0.2, WooCommerce 10.9.4, PHP 8.2, MariaDB 11.8.
Baza zalozona od zera; wtyczki rozpakowane z paczek pobranych z wydan GitHuba,
nie z drzewa roboczego. Adres: http://localhost (Apache w kontenerze mp-czysty-wp).

## Przejscie PRZEZ HTTP — formularz gosciem az po role

```
=== A. formularz oczami goscia (bez logowania) ===
  [PASS] A1: strona z formularzem odpowiada 200
  [PASS] A2: formularz jest w tresci strony
  [PASS] A3: formularz niesie nonce
  [PASS] A4: jest zgoda RODO i pulapka na boty
  [PASS] A5: pulapka jest ukryta przed czlowiekiem
  [PASS] A6: pole company_name jest na formularzu
  [PASS] A6: pole nip jest na formularzu
  [PASS] A6: pole email jest na formularzu
  [PASS] A6: pole country jest na formularzu
  [PASS] A6: pole products jest na formularzu
=== B. zgloszenie wyslane tak, jak wysyla je przegladarka ===
  [PASS] B1: wyslanie formularza konczy sie 200
  [PASS] B2: wtyczka potwierdza przyjecie zgloszenia
  [PASS] B3: i zadnego bledu PHP w odpowiedzi
  [PASS] B4: lead zapisany w BD-3
  [PASS] B5: KONTR-ASERCJA — wypelniona pulapka nie zaklada leada
=== C. lancuch trzech wtyczek zadzialal sam, bez pomocy ===
  [PASS] C1: wtyczka 2 zalozyla oferte dla tego leada
  [PASS] C2: nowa oferta jest szkicem
  [PASS] C2b: szkic NIE ma jeszcze numeru — numer nadaje zatwierdzenie
  [PASS] C3: wtyczka 3 zalozyla proces sprzedazy
  [PASS] C4: segment ze zgloszenia dotarl do procesu
  [PASS] C5: proces ma przypisanego handlowca
  [PASS] C6: dziennik procesu ma wpisy
=== D. ekrany panelu przez HTTP, jako administrator ===
  [PASS] D1: zalogowany jako administrator
  [PASS] D2: ekran zgloszen (wtyczka 1) otwiera sie bez bledu
  [PASS] D3: nowe zgloszenie widac na liscie zgloszen
  [PASS] D2: ekran ofert (wtyczka 2) otwiera sie bez bledu
  [PASS] D2: ekran procesow (wtyczka 3) otwiera sie bez bledu
  [PASS] D4: nowy proces widac na liscie procesow
=== E. role sprawdzone prawdziwym logowaniem ===
  [PASS] E1: zalogowany jako handlowiec
  [PASS] E2: handlowiec DOCIERA do ekranu procesow (nie jest wypychany z panelu)
  [PASS] E2b: i widzi na nim ekran wtyczki 3, a nie cokolwiek innego
  [PASS] E3: handlowiec oglada liste zgloszen — ma `mp_view_leads`
  [PASS] E3b: KONTR-ASERCJA — uzytkownik bez uprawnienia dostaje odmowe, a nie ekran
  [PASS] E4: zalogowany jako manager
  [PASS] E5: manager DOCIERA do ekranu procesow (nie jest wypychany z panelu)
  [PASS] E6: KONTR-ASERCJA — niezalogowany jest odsylany do logowania, a nie obslugiwany
=== F. poczta: co instalacja naprawde chciala wyslac ===
  [PASS] F1: instalacja zlecila wyslanie poczty
  [PASS] F2: przed zatwierdzeniem oferty NIC nie idzie do klienta — poczta do niego wychodzi dopiero po zatwierdzeniu
  [PASS] F3: zaden mail nie niesie nieuzupelnionego znacznika
  [PASS] F4: kazdy mail ma temat
  [PASS] C5: proces ma przypisanego handlowca
  [PASS] C6: dziennik procesu ma wpisy
=== D. ekrany panelu przez HTTP, jako administrator ===
  [PASS] D1: zalogowany jako administrator
  [PASS] D2: ekran zgloszen (wtyczka 1) otwiera sie bez bledu
  [PASS] D3: nowe zgloszenie widac na liscie zgloszen
  [PASS] D2: ekran ofert (wtyczka 2) otwiera sie bez bledu
  [PASS] D2: ekran procesow (wtyczka 3) otwiera sie bez bledu
  [PASS] D4: nowy proces widac na liscie procesow
=== E. role sprawdzone prawdziwym logowaniem ===
  [PASS] E1: zalogowany jako handlowiec
  [PASS] E2: handlowiec DOCIERA do ekranu procesow (nie jest wypychany z panelu)
  [PASS] E2b: i widzi na nim ekran wtyczki 3, a nie cokolwiek innego
  [PASS] E3: handlowiec oglada liste zgloszen — ma `mp_view_leads`
  [PASS] E3b: KONTR-ASERCJA — uzytkownik bez uprawnienia dostaje odmowe, a nie ekran
  [PASS] E4: zalogowany jako manager
  [PASS] E5: manager DOCIERA do ekranu procesow (nie jest wypychany z panelu)
  [PASS] E6: KONTR-ASERCJA — niezalogowany jest odsylany do logowania, a nie obslugiwany
=== F. poczta: co instalacja naprawde chciala wyslac ===
  [PASS] F1: instalacja zlecila wyslanie poczty
  [PASS] F2: przed zatwierdzeniem oferty NIC nie idzie do klienta — poczta do niego wychodzi dopiero po zatwierdzeniu
  [PASS] F3: zaden mail nie niesie nieuzupelnionego znacznika
  [PASS] F4: kazdy mail ma temat
=== G. zatwierdzenie oferty przez HTTP i jego skutki ===
  [PASS] G1: szkic BEZ dokumentu nie ma przycisku zatwierdzenia — zgodnie z regula
  [PASS] G1b: i nie ma tez odnosnika do dokumentu, ktorego nie ma
===== RAZEM PASS: 42 / FAIL: 0 =====
```

## Dziesiec scenariuszy odbioru (kryteria 4.4, 4.5, 5.1, 5.4, 5.5)

```
=== S1/10 — instalacja i schemat na zywym WP ===
=== S2/10 — lead z LP.1 przechodzi przez wszystkie trzy wtyczki ===
=== S3/10 — przypisanie handlowca po kraju i jezyku (kryt. 5.4) ===
=== S4/10 — oferta z LP.2 przestawia status procesu ===
=== S5/10 — e-mail do klienta po akceptacji oferty (kryt. 4.4) ===
=== S6/10 — podpisany link do oferty ===
=== S7/10 — follow-up d+3 / d+7 tylko przy niezmienionym statusie (kryt. 4.5) ===
=== S8/10 — role i zakres widoku (kryt. 5.4) ===
=== S9/10 — dziennik odtwarza historie statusow i wysylek (kryt. 5.5) ===
=== S10/10 — idempotencja i wspolbieznosc ===
================================================
WYNIK: PASS=102  FAIL=0  RAZEM=102
STATUS: ALL_PASS
```

Pelny zapis obu przebiegow: patrz historia commita.

## Demo z OPUBLIKOWANEGO blueprintu

```

=== A. blueprint jest tam, gdzie obiecuje odnosnik uruchomieniowy ===
  [PASS] A1: blueprint pobiera sie z gałęzi main
  [PASS] A2: blueprint jest poprawnym JSON-em
  [PASS] A3: instaluje WooCommerce i trzy nasze wtyczki
  [PASS] A3b: trzy wtyczki z naszych wydan, nie ze sklepu
  [PASS] A3c: WooCommerce ze sklepu wordpress.org
  [PASS] A4: i motyw strony pokazowej
  [PASS] A5: blueprint deklaruje srodowisko

=== B. assety, po ktore siega demo, istnieja ===
  [PASS] B1: blueprint wskazuje cztery assety
  [PASS] B2: kredyt-kompas.zip pobiera sie (53 kB)
  [PASS] B2: mp-lead-intake.zip pobiera sie (146 kB)
  [PASS] B2: mp-offer-builder.zip pobiera sie (4702 kB)
  [PASS] B2: mp-sales-workflow.zip pobiera sie (190 kB)

=== C. demo uruchamia TE SAME bajty, ktore dostaje klient ===
  [PASS] C0: mamy pobrana paczke wydania do porownania
  [PASS] C1: mp-lead-intake.zip w demo == mp-lead-intake.zip w paczce wydania 1.3.12
  [PASS] C1: mp-offer-builder.zip w demo == mp-offer-builder.zip w paczce wydania 1.3.12
  [PASS] C1: mp-sales-workflow.zip w demo == mp-sales-workflow.zip w paczce wydania 1.3.12

=== D. wersja w assetach demo zgadza sie z wydaniem ===
  [PASS] D1: mp-lead-intake w demo ma wersje 1.3.12
  [PASS] D1: mp-offer-builder w demo ma wersje 1.3.12
  [PASS] D1: mp-sales-workflow w demo ma wersje 1.3.12

=== E. korzen archiwum ma ksztalt, jakiego wymaga Playground ===
  [PASS] E1: kredyt-kompas.zip ma jeden katalog w korzeniu
  [PASS] E1: mp-lead-intake.zip ma jeden katalog w korzeniu
  [PASS] E1: mp-offer-builder.zip ma jeden katalog w korzeniu
  [PASS] E1: mp-sales-workflow.zip ma jeden katalog w korzeniu

=== F. zasiew demo nie wola rzeczy, ktorych w paczkach nie ma ===
  [PASS] F1: blueprint ma krok zasiewajacy dane
  [PASS] F2: zasiew odwoluje sie do klas wtyczek
  [PASS] F3: kazda klasa z zasiewu jest w wysylanym kodzie
  [PASS] F4: kazdy krotki kod z zasiewu jest rejestrowany przez wtyczki

----- PASS: 27 / FAIL: 0 -----
```

---

## Po naprawie: wydanie 1.3.13

Blad z sekcji E naprawiony i opublikowany jako 1.3.13 (tylko wtyczka 3).
Weryfikacja z POBRANYCH paczek 1.3.13: 48/48. Assety demo odswiezone i sprawdzone ponownie: 27/27.
Bramka repo: „wszystko sie zgadza” — 8 potwierdzen, w tym dwa nowe o wtyczkach bez zmian.
