# Zgodność produktu z treścią zlecenia

Rozbicie zlecenia na pojedyncze wymogi i sprawdzenie każdego wprost w kodzie.
Powstało po tym, jak egzaminator zgłosił 11 błędów, których nasze narzędzia nie
wykryły: siatka regresyjna sprawdza wyłącznie nasze dawne pomyłki, a bramka
audytu porównuje kod z rejestrem tych pomyłek — żadne z nich nie pyta, **czy
zrobiliśmy to, co zamówiono**.

Stan kodu: `942aa27` na `main`, wersja wtyczek 1.3.6.

Legenda: **OK** — spełnione i sprawdzone w kodzie · **CZĘŚCIOWO** — działa, ale
nie tak, jak opisuje zlecenie · **BRAK** — nie znalazłem realizacji.

---

## 1. Cel biznesowy

> „System ma automatycznie kwalifikować lead, tworzyć kartę klienta, dobierać
> właściwy wariant cenowy, generować ofertę PDF i **kierować zadanie do
> odpowiedniego handlowca**."

| Wymóg | Werdykt | Dowód |
|---|---|---|
| kwalifikacja leada | OK | `mp-lead-intake/includes/pipeline/class-mp-lead-scoring.php:26-62` |
| karta klienta | OK | `wp_mp_leads`, `mp-lead-intake/includes/db/class-mp-db.php:495-535` |
| dobór wariantu cenowego | OK | `mp-offer-builder/includes/pipeline/departments/class-mp-ob-department-05.php:26-64` |
| oferta PDF | OK | `mp-offer-builder/includes/pipeline/departments/class-mp-ob-department-09.php:48-113` |
| **odpowiedni handlowiec** | **CZĘŚCIOWO** | patrz **U-1** niżej |
| bez ręcznego kopiowania między formularzem, WooCommerce i pocztą | OK | zdarzenia `mp_lead_created` → `mp_offer_created` → `mp_offer_approved` |

---

## 2. Zakres techniczny — podział na trzy wtyczki

Zlecenie przypisuje funkcje do konkretnych modułów. Sprawdzone co do pozycji.

### MP Lead Intake

| Pozycja z zlecenia | Werdykt | Dowód |
|---|---|---|
| Formularz B2B | OK | `includes/class-mp-form.php:114-150` |
| Walidacja NIP / VAT UE | OK | `includes/class-mp-vat-number.php`, dział 03 |
| Scoring leada | OK | `includes/pipeline/class-mp-lead-scoring.php:26-62` |
| Przypisanie **kraju i segmentu** | OK | `class-mp-form.php:114-150` (`country`, `segment`) |
| Ochrona antyspamowa | OK | honeypot + rate-limit, dział 05 |
| Zapis zgód | OK | `consent_*_at` w schemacie |

**Zlecenie NIE umieszcza tu przypisania handlowca.** Kod je jednak zawiera —
`includes/pipeline/departments/class-mp-department-07.php:138-149`. Patrz **U-1**.

### MP Offer Builder

| Pozycja z zlecenia | Werdykt | Dowód |
|---|---|---|
| Kalkulacja rabatu | CZĘŚCIOWO | `class-mp-ob-department-05.php:35-64` — reguły zaszyte w `const RULES`, brak ekranu ustawień (**U-4**) |
| Pobranie cen z WooCommerce | OK | `class-mp-ob-department-02.php` |
| Szablony ofert | OK | `wp_mp_ob_offer_templates`, zasiew PL + EN |
| Generowanie PDF | OK | dział 09, Dompdf |
| Numeracja ofert | OK | `class-mp-ob-department-08.php:36,65` — `OF/RRRR/NNNNNN` |
| Historia wersji | OK | `wp_mp_ob_offer_versions` |

### MP Sales Workflow

| Pozycja z zlecenia | Werdykt | Dowód |
|---|---|---|
| Przypisanie handlowca | OK | `class-mp-sw-department-04.php:57-90` — dobór po kraju, języku, obciążeniu |
| Statusy procesu | OK | `wp_mp_sw_flow` |
| Powiadomienia e-mail | OK | dział 07 + `class-mp-sw-mailer.php:73-78` |
| Zadania follow-up | OK | `class-mp-sw-department-06.php:37-40,151-202` |
| Dashboard | OK | `includes/admin/class-mp-sw-admin.php:38-48` |
| Dziennik aktywności | OK | `wp_mp_sw_activity` |

---

## 3. Zależności baz danych — trzy obszary

Zlecenie definiuje BD-1/2/3 jako **obszary danych**, nie osobne bazy MySQL.
Sprawdzone: mapowanie jest zgodne.

| Obszar | Zlecenie | Realizacja | Werdykt |
|---|---|---|---|
| **BD-1** | `wp_users` + `wp_usermeta`; powiązanie użytkownika z krajem, zespołem, językiem i zakresem obsługi | usermeta `mp_sw_country`, `mp_sw_langs`, `mp_sw_team`, `mp_sw_active`; zakres SCOPE_OWN/TEAM/ALL — `class-mp-sw-department-03.php:32-38` | OK |
| **BD-2** | WooCommerce `wp_posts`/`wp_postmeta` lub HPOS; produkty, warianty, ceny bazowe, waluty, dane podatkowe | warianty — `class-mp-ob-products.php:100,122`; waluta — `class-mp-ob-department-02.php:396` | OK |
| **BD-3** | `wp_mp_leads` + `wp_mp_offers` + `wp_mp_activity_log`; relacja lead → oferta → aktywność | wszystkie trzy tabele istnieją i są zapisywane; lustro ofert — `includes/class-mp-offer-registry.php:119,164-170` | OK |

---

## 4. Automatyzacja procesu — pięć kroków

| Krok | Werdykt | Uwaga |
|---|---|---|
| 1. Formularz: **rynek**, produkty, wolumen, dane firmy | CZĘŚCIOWO | Pola o nazwie „rynek" nie ma. Rolę pełnią `country` i `segment` — `class-mp-form.php:114-150`. Patrz **U-6** |
| 2. Walidacja, scoring, duplikaty, **właściwy handlowiec** | CZĘŚCIOWO | duplikaty OK (`UNIQUE(country, nip)`); handlowiec — patrz **U-1** |
| 3. Produkty i ceny, reguły rabatowe, PDF | CZĘŚCIOWO | rabaty nie do skonfigurowania — **U-4** |
| 4. Zatwierdzenie → wysyłka → historia | OK | `class-mp-offer-builder-approval.php:29-90` |
| 5. Follow-up po 3 i 7 dniach, o ile status niezmieniony | OK | wartownik porównuje status — `class-mp-sw-department-06.php:151,167-202` |

---

## 5. Kryteria odbioru

| Kryterium | Werdykt | Uwaga |
|---|---|---|
| Lead i oferta dla min. 10 scenariuszy | CZĘŚCIOWO | Zestaw istnieje — `mp-sales-workflow/tests/koncowe/scenariusze-1-10.php:197-716` — ale w repozytorium **nie ma zapisu z przebiegu**. Patrz **U-7** |
| Brak duplikatów | OK | `class-mp-department-07.php:22-98` + UNIQUE w bazie |
| PDF PL i EN, ceny i numer oferty | OK | `mp-offer-builder/tests/koncowe/pdf-pl-en-numer.php:82-195` |
| Role: administrator, manager sprzedaży, handlowiec | CZĘŚCIOWO | Role mają realnie różne uprawnienia (`class-mp-sw-roles.php:123-159`), ale manager nie ma własnego widoku — **U-5** |
| Log zdarzeń odtwarzający historię statusów i wysyłek | OK | `class-mp-sales-workflow-db.php:617-632` |

---

## Ustalenia

### U-1 — Ten sam lead ma DWÓCH różnych handlowców, a ten z BD-3 jest wylosowany

Waga: **duża**.

Wtyczka 1 przypisuje handlowca tak:

```php
$index = abs( crc32( (string) $nip ) ) % count( $users );
```
`mp-lead-intake/includes/pipeline/departments/class-mp-department-07.php:148-149`

Wynik trafia do `wp_mp_leads.salesman_id` (BD-3) — `class-mp-department-07.php:213`.
Nie ma w tym doborze ani kraju, ani języka, ani zespołu, ani obciążenia. To hasz
z NIP-u, czyli wybór deterministyczny, ale **przypadkowy względem sprawy**.

Równolegle wtyczka 3 dobiera handlowca poprawnie — po kraju, języku i obciążeniu,
z danych, które zlecenie umieszcza w BD-1 (`class-mp-sw-department-04.php:57-90`).
Koperta zdarzenia `mp_lead_created` **nie niesie** `salesman_id`
(`class-mp-sw-hooks.php:58-73`), więc te dwa mechanizmy nie widzą się nawzajem.

Skutek: dla jednego zgłoszenia w bazie leadów stoi jeden handlowiec, a w procesie
sprzedażowym — inny. Dla klienta z Niemiec BD-3 wskaże z grubsza połowę razy
handlowca polskiego, który nie mówi po niemiecku.

Trzy zdania ze zlecenia mówią, że tak być nie powinno:
- cel biznesowy: „kierować zadanie do **odpowiedniego** handlowca";
- zakres wtyczki 1: przypisania handlowca **tam nie ma** — jest „przypisanie kraju
  i segmentu"; przypisanie handlowca należy do wtyczki 3;
- BD-1 istnieje właśnie po to: „logiczne powiązanie użytkownika z krajem,
  zespołem, językiem i zakresem obsługi".

### U-2 — Scoring jest liczony, ale nigdzie go nie widać

Waga: **średnia**.

`class-mp-lead-scoring.php:26-62` wylicza punktację, `class-mp-department-07.php:219`
zapisuje ją do bazy. Nie pokazuje jej żaden ekran: wtyczka 1 nie ma w ogóle panelu,
lista ofert (`class-mp-offer-builder-list-table.php:39-49`) i dashboard procesów
(`class-mp-sw-admin.php:285-316`) nie mają takiej kolumny. Zlecenie stawia scoring
w zakresie wtyczki 1 i w kroku 2 procesu — a kwalifikacja, której nikt nie widzi,
nie skraca nikomu czasu obsługi.

### U-3 — Wtyczka 1 nie ma żadnego ekranu w panelu

Waga: **mała**.

W całym module brak `add_menu_page`. Leadów nie da się nigdzie obejrzeć —
ani sprawdzić scoringu, ani statusu VAT, ani zgód.

### U-4 — Reguł rabatowych nie da się skonfigurować

Waga: **mała**.

`class-mp-ob-department-05.php:35-64` — progi i procenty w stałej `const RULES`.
Zmiana rabatu wymaga edycji kodu i wgrania wtyczki od nowa. Zlecenie wymienia
„kalkulację rabatu" jako funkcję modułu, a nie jako stałą w źródle.

### U-5 — Manager sprzedaży nie ma własnego widoku

Waga: **mała**.

`class-mp-sw-admin.php:38-48` — jeden dashboard dla wszystkich ról, różnicowany
zakresem danych. Rola z kryteriów odbioru istnieje i ma własne uprawnienia, ale
nie ma nic, co byłoby dla niej zrobione.

### U-6 — Pola „rynek" nie ma pod tą nazwą

Waga: **mała**.

Krok 1 procesu wymienia „rynek" wprost. Formularz zbiera `country` i `segment`
(`class-mp-form.php:114-150`); słowo „rynek" nie występuje ani w kodzie, ani
w dokumentacji.

### U-7 — Brak zapisu z przebiegu dziesięciu scenariuszy

Waga: **mała**.

Kryterium odbioru mówi o poprawnym utworzeniu leada i oferty dla minimum
10 scenariuszy. Zestaw istnieje, ale repozytorium nie zawiera raportu z jego
wykonania — jest tylko opis w `docs/TESTY.md`, bez wyniku konkretnego przebiegu.

### U-8 — Wtyczka 3 nie ma żadnego testu w CI

Waga: **mała**.

`.github/workflows/ci.yml:52-56` uruchamia harness wtyczek 1 i 2. Wtyczka 3 —
ta, która realizuje kroki 4 i 5 procesu oraz dwa z pięciu kryteriów odbioru —
nie ma w CI żadnego dowodu działania.

### U-9 — Odmowa przy duplikacie nie mówi, o co chodzi

Waga: **mała**.

Powtórne zgłoszenie tej samej firmy kończy się komunikatem „Nie udało się
przetworzyć zgłoszenia. Sprawdź dane i spróbuj ponownie." Kryterium odbioru mówi
o braku duplikatów, więc odmowa jest poprawna — ale nadawca nie dowiaduje się, że
jego firma jest już zarejestrowana, i będzie poprawiał dane, które są dobre.

### U-10 — Teksty, które użytkownik widzi najczęściej, nie są przygotowane do tłumaczenia

Waga: **mała**.

Komunikaty zwracane nadawcy formularza są wpisane na sztywno, bez funkcji
tłumaczącej — `mp-lead-intake/includes/class-mp-ajax.php:61,73,85,118,144,156,167`,
podobnie `mp-offer-builder/includes/class-mp-offer-builder-ajax.php:80,93,104,119,195,247`.
Nagłówki głównej tabeli pulpitu wtyczki 3 również:

```php
$naglowki = array( 'Lead', 'Klient', 'Status', 'Handlowiec', 'Termin SLA', 'Otwarte zadania', 'Aktualizacja' );
```
`mp-sales-workflow/includes/admin/class-mp-sw-admin.php:287,290`

W tych samych plikach, kilkanaście linii wyżej, inne teksty przechodzą przez
`esc_html__()`. Wydanie 1.3.5 naprawiło wczytywanie tłumaczeń wtyczki 3 i changelog
mówi o „176 tekstach przygotowanych do przetłumaczenia" — nagłówki jej własnego
pulpitu do tej liczby nie należą. Przy pierwszym tłumaczeniu zostaną po polsku.

### U-11 — Wewnętrzny kod błędu pokazywany użytkownikowi

Waga: **mała**.

```php
esc_html( MP_SW_Errors::message( $kod ) ) . ' <code>' . esc_html( $kod ) . '</code>'
```
`mp-sales-workflow/includes/admin/class-mp-sw-admin.php:182-184`

Obok zdania po polsku panel drukuje `MP3-Exxx`. Dla zgłoszenia awarii to bywa
przydatne, ale wtedy powinno być podpisane, a nie doklejone bez wyjaśnienia.

### U-12 — „Jeden plik na dział (zasada projektu)" deklarowana, ale niestosowana

Waga: **drobna**.

Każdy plik dokumentacji wtyczki 3 nosi ten nagłówek —
`mp-sales-workflow/docs/dzial-01/brama-i-kontrakt-zdarzenia.md:3` — podczas gdy
wtyczka 1 trzyma w `docs/dzial-03/` pięć osobnych plików. Albo zasada obowiązuje
wszędzie, albo nie jest zasadą projektu.

---

## Odrzucone w triage'u

Zapisane, żeby nikt nie wracał do nich drugi raz.

**„`DEBUG-RAPORT.md` odsyła do nieistniejącego `bootstrap.php`" — FAŁSZYWY ALARM.**
Plik istnieje: `mp-lead-intake/includes/pipeline/bootstrap.php`. Dokumentacja ma
rację. Recenzent szukał go w korzeniu wtyczki, a leży w `includes/pipeline/`.

**„Cztery pliki dokumentacji to własny opis autora, wbrew zasadzie o oficjalnych
źródłach" — FAŁSZYWY ALARM.** Wszystkie cztery deklarują to wprost w pierwszej
linii: `ŹRÓDŁO ORYGINALNE — konfiguracja projektu (deterministyczny słownik…)`.
Dotyczą słownika segmentów, reguł rabatowych i limitów koszyka — czyli decyzji
biznesowych, dla których zewnętrzne źródło nie istnieje i istnieć nie może.
Zasada zabrania danych zmyślonych i „z pamięci", a nie dokumentowania własnej
konfiguracji **oznaczonej jako własna**. Dopisanie im fikcyjnego URL-a byłoby
dokładnie tym naruszeniem, przed którym ta zasada chroni.

---

## Sprawdzone i zgodne — bez zastrzeżeń

Żeby było wiadomo, czego **nie** trzeba ruszać:

- podział na trzy wtyczki odpowiada tabeli z zlecenia co do pozycji (poza **U-1**);
- mapowanie BD-1/BD-2/BD-3 jest zgodne — w tym warianty produktów, waluta i dane
  podatkowe z WooCommerce, oraz relacja lead → oferta → aktywność w BD-3;
- duplikaty, PDF PL/EN z numerem oferty, follow-up D+3 i D+7 z wartownikiem statusu,
  dziennik aktywności — wszystkie sprawdzone w kodzie i pokryte testami.
