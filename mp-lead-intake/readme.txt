=== MP Lead Intake ===
Contributors: krzysiek2115op
Tags: leads, formularz, b2b, nip, vat
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Przyjęcie i kwalifikacja lead-a z formularza ofertowego WordPress.

== Description ==

Pierwsza z trzech wtyczek procesu "formularz → oferta". Odpowiada za odbiór
zgłoszenia z formularza, wstępną kwalifikację lead-a i zapis do dedykowanej bazy.

== Changelog ==

= 1.3.3 =

* POLA "SEGMENT" I "PRZEWIDYWANY WOLUMEN" bez limitu długości powodowały UTRATĘ
  ZGŁOSZENIA. Kolumny to varchar(100); dłuższa wartość sprawiała, że zapis do
  bazy zwracał błąd zamiast się wykonać, transakcja szła w ROLLBACK, a klient
  dostawał ogólne "sprawdź dane i spróbuj ponownie" — bez wskazania pola. Każda
  kolejna próba z tym samym tekstem kończyła się tak samo. Limit liczony
  w znakach, nie w bajtach.
* DATA ZAPYTANIA DO BIAŁEJ LISTY liczona była w czasie uniwersalnym (UTC),
  a API zwraca status na konkretny dzień. W polskiej strefie między północą
  a 1:00/2:00 pytanie dotyczyło więc doby POPRZEDNIEJ: firma zarejestrowana
  jako podatnik VAT czynny od dzisiaj dostawała status "Niezarejestrowany" —
  prawdziwy, ale na wczoraj — i zapisywany jako sprawdzony. Błędny wynik
  utrwalał się w pamięci podręcznej na kolejne 12 godzin.
* WERYFIKACJA VAT W TLE kasowała potwierdzony wcześniej status, gdy VIES akurat
  nie odpowiedział: "nie wiadomo" nadpisywało "tak". Lead tracił 30 punktów
  scoringu i po kilku próbach lądował ze statusem nieznanym, przez co wtyczka 2
  nie mogła zastosować odwrotnego obciążenia. Biała lista miała to zabezpieczone
  od poprzedniego audytu — VIES nie.

= 1.3.2 =

* Odpowiedz Bialej listy bez pola `statusVat` byla raportowana jako
  „status firmy sprawdzony", a pusty wynik trafial do pamieci podrecznej
  na 12 godzin. Przez pol doby kazdy lead z tym numerem NIP dostawal
  potwierdzenie weryfikacji, ktorej nikt nie wykonal, i nie trafial do
  ponowienia w tle. Teraz brak statusu znaczy „nie ustalono".
* Odpowiedz VIES bez pola `isValid` byla traktowana jak jednoznaczne
  „numer VAT niepoprawny" — z zapisem tego werdyktu na 24 godziny.
  Zgloszenie z poprawnym numerem bylo odrzucane przez cala dobe.
  Rozstrzygnieciem jest teraz wylacznie odpowiedz, ktora to pole zawiera.
* Nieudana wysylka alarmu do administratora zostawia slad w dzienniku.
  Wczesniej przy zepsutej poczcie awaria pipeline'u nie zostawiala zadnego
  sladu: alarm nie dochodzil i nikt sie o tym nie dowiadywal.
* Dokumentacja: data pobrania przy cytowanym zrodle normy ISO 3166-1,
  identyfikatory bledow z rejestru w naglowkach testow regresji.

= 1.3.1 =

* Wynik pipeline'u byl meldowany jako gotowy, nawet gdy odpowiedzi NIE DALO
  SIE zakodowac do JSON-a — `wp_json_encode()` zwracalo `false`, a ta wartosc
  szla dalej jako gotowa odpowiedz. Przegladarka dostawala puste cialo i nic
  tego nie tlumaczylo. Teraz wynik jest gotowy tylko przy udanym kodowaniu.
* Domyslny status weryfikacji VAT w sygnale `mp_lead_created` brzmial
  „sprawdzone". Brak danych nie moze podawac sie za wynik weryfikacji, ktora
  sie nie odbyla — wartoscia zastepcza jest teraz „nie wiadomo" (`unknown`).
  Dzial 7 ustawia ten status przy kazdym przebiegu, wiec zmiana nie dotyka
  zadnej dzisiejszej sciezki; chroni pierwsza, ktora Dzial 7 ominie.

= 1.3.0 =

* ZDARZENIE `mp_lead_created` SZLO WEWNATRZ OTWARTEJ TRANSAKCJI. Skutki byly
  trzy: wtyczka 3 robila niejawny COMMIT naszej transakcji, nasz ROLLBACK
  kasowal szkic oferty w bazie wtyczki 2, a wiersz leada trzymal blokade na
  parze kraj+NIP przez caly czas pracy subskrybentow. Najgorszy przypadek:
  wyjatek subskrybenta NISZCZYL leada — klient wypelnial poprawny formularz
  i dostawal blad 500, bo cudzy modul mial usterke.
* KASOWNIK I EKSPORTER DANYCH OSOBOWYCH (RODO). Wtyczka nie miala ich wcale,
  a modul sprzedazowy czyta adres klienta na zywo z naszej tabeli — dane
  usuniete po tamtej stronie wracaly stad przy kolejnym powiadomieniu.
  Doszedl tez hak `mp_lead_anonymized`, ktorego wtyczka 3 nasluchiwala od dawna.
* Deinstalacja nie zabiera juz rol wspoldzielonych z wtyczka 3 — wczesniej
  usuniecie samej wtyczki 1 pozbawialo uprawnien wszystkich handlowcow
  i managerow w calej instalacji.
* Nasluch zdarzen oferty opakowany w try/catch: nasz wyjatek nie wywroci juz
  wystawiania oferty w module ofertowym.
* Licencja GPL-2.0-or-later: pelny tekst w repozytorium i w paczce klienckiej.

= 1.2.3 =
* Dział 2: dodana walidacja formatu telefonu i kraju (ISO 3166-1 alpha-2) — wcześniej
  błędny kraj był po cichu nadpisywany na PL dopiero w dziale 4, po tym jak dział 1/3
  już zdążyły zobaczyć surową wartość.
* Dział 4: naprawiony fałszywy trafienie segmentacji — needle "it" (2 znaki, bez
  granicy słowa) błędnie klasyfikował np. "Architektura"/"Kapitałowa" jako segment IT.
* Worker VAT: `vat_checked_at`/`deleted_at` zapisywane teraz w GMT (spójnie z
  `updated_at`) — poprzednio lokalny czas WP, częściowy nawrót wcześniej naprawionego
  błędu stref czasowych.
* Dział 1: usunięte martwe odczyty ofert/historii aktywności (nigdzie niekonsumowane
  w pipeline) — dział 1 czyta teraz wyłącznie leady, zgodnie z rzeczywistym użyciem.
* Formularz: honeypot ukrywany teraz też inline (obok arkusza stylów) — defense in
  depth na wypadek niezaładowania CSS.
* Dział 9: poprawiona myląca nazwa "Rozpoczęcie procesu" (sugerowała start całego
  procesu, a to czysta telemetria czasu wykonywana PO utworzeniu leada w dziale 7).

= 1.2.2 =
* Finalny audyt (10 równoległych sub-agentów, 2026-07-22): naprawiono race condition
  przy reaktywacji zarchiwizowanego leada (dwa równoległe zgłoszenia tego samego NIP
  mogły oba "wygrać" i nadpisać się nawzajem) — atomowy claim w reactivate_lead().
* BD-3: klucz unikalności zmieniony z UNIQUE(nip) na UNIQUE(country, nip) — numer
  firmowy różnych krajów UE mógł się cyfrowo pokrywać i kolidować (DB_VERSION 1.4.0).
* Pipeline: try/finally wokół transakcji działów 7-11 — ROLLBACK i log gwarantowane
  nawet przy nieoczekiwanym wyjątku (np. w przyszłej integracji plugin 2/3), nie tylko
  przy kontrolowanym STOP krytyka/bramki.
* Aktywacja: weryfikacja, że tabele BD-3 faktycznie powstały (dbDelta nie sygnalizuje
  awarii samodzielnie) — zamiast cichej, trwałej porażki instalacji.
* Dokumentacja: usunięto mylący tag "woocommerce" (wtyczka nie ma integracji WooCommerce).

= 1.2.1 =
* Fix znaleziony w testach manualnych na żywym WP: architektura licznika rate-limit —
  inkrement przeniesiony z działu 5 do pre-gate w class-mp-ajax.php, żeby liczyła się
  KAŻDA próba (wcześniej flood błędnych danych całkowicie omijał limit).

= 1.2.0 =
* P-1: asynchroniczna weryfikacja VIES/Biała lista (dział 3) poza ścieżką żądania —
  WP-Cron worker (class-mp-vat-verifier.php) + reconcile, re-scoring w tle.
* BD-3: kolumny vat_valid/company_status/vat_status/vat_checked_at/vat_attempts
  (DB_VERSION 1.3.0), country/products/est_volume/salesman_assigned_at, consent_*_at.
* RODO: transakcyjność zapisów działów 7-9 (koniec osieroconych leadów), anonimizacja
  i retencja adresów IP (90 dni).

= 1.0.0 =
* Krok 4 (sprawy techniczne): endpoint "1 AJAX" -> pipeline, formularz B2B (shortcode),
  pod-strona auto-tworzona (dziedziczy motyw), role WP (manager sprzedazy/handlowiec),
  powiadomienia admina (wp_mail, z throttlingiem).
* Wtyczka funkcjonalnie kompletna dla pluginu 1.

= 0.9.0 =
* Krok 3 UKOŃCZONY — działy 7-11 z realną logiką:
  7 utworzenie leada (dedup+scoring+handlowiec+INSERT), 8 log aktywności,
  9 rozpoczęcie procesu, 10 zwrócenie wyniku, 11 zakończenie pipeline.
* Fabryka: wszystkie 11 działów realne (usunięto zaślepki z rejestru).
* docs/dzial-07..11/ = oficjalne źródła (wpdb::insert, current_time, wp_json_encode).

= 0.5.0 =
* Krok 3 — Dział 2 (Walidacja wstępna): agenci 2.1/2.2/2.3 (wymagane pola,
  normalizacja sanitize_*, formaty is_email) + krytycy + QA.
* Uniwersalny MP_Flag_Critic (weryfikacja flag typu required_ok/form_valid).
* docs/dzial-02/ = oficjalna dokumentacja WordPress (is_email, sanitize_text_field, sanitize_email).

= 0.4.1 =
* docs/ przebudowane wg zasady: docs = FAKTYCZNA oficjalna dokumentacja źródeł działu
  (pobrane wiernie z URL + data), którą "czytają" agenci/krytycy. Zadania agentów są w kodzie.
* Dział 1: docs/dzial-01/ = oficjalna dokumentacja WordPress wpdb (get_results, prepare).
* Usunięto błędny plik opisujący zadania (dzial-01-pobranie-danych.md).

= 0.4.0 =
* Krok 3 — Dział 1 (Pobranie danych z BD-3): realni agenci 1.1/1.2/1.3 + krytycy + QA.
* Bazowe klasy Agent/Krytyk, uniwersalny QA Krytyk (MP_Accept_Critic).
* Metody odczytu w warstwie DB (get_leads_by_nip, get_offers/activity_by_lead_ids).
* Dokumentacja działu: docs/dzial-01-pobranie-danych.md.

= 0.3.0 =
* Krok 2: rusztowanie pipeline (11 działów). Klasy: Result, Context (JSON),
  kontrakty Agent/Krytyk, Quality Gate, Department, Pipeline, Logger, Factory.
* Agenci/krytycy jako zaślepki (logika i dokumentacja w kroku 3).

= 0.2.1 =
* Leady: kolumna deleted_at (soft delete — archiwizacja zamiast kasowania).
* Klucz obcy offers.lead_id -> leads.id (ON DELETE RESTRICT). activity_log bez FK (audyt).
* Tabele jawnie ENGINE=InnoDB (transakcje + klucze obce).

= 0.2.0 =
* Baza danych BD-3: tabele wp_mp_leads, wp_mp_offers, wp_mp_activity_log (dbDelta).
* Instalacja tabel przy aktywacji, migracja wg wersji schematu, usuwanie przy deinstalacji.

= 0.1.0 =
* Szkielet wtyczki: nagłówek, stałe, hooki aktywacji/deaktywacji, bootstrap.
