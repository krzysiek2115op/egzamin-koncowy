=== MP Lead Intake ===
Contributors: krzysiek2115op
Tags: leads, formularz, b2b, nip, vat
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Przyjęcie i kwalifikacja lead-a z formularza ofertowego WordPress.

== Description ==

Pierwsza z trzech wtyczek procesu "formularz → oferta". Odpowiada za odbiór
zgłoszenia z formularza, wstępną kwalifikację lead-a i zapis do dedykowanej bazy.

== Changelog ==

= 1.3.11 =
* Menu przestało prowadzić do strony, której nie ma. Metoda oddająca adres
  strony z formularzem zwracała odnośnik także dla wpisu w koszu, w szkicu
  i prywatnego — a korzysta z niej awaryjne dokładanie pozycji do nawigacji.
  Gość widział w menu wejście do całego procesu i trafiał na 404.
* Dziennik mówi, CO się stało. Wpis o zatrzymaniu pipeline'u niósł numer
  działu i maszynowy kod błędu, a komunikat dla człowieka szedł do kolumny,
  której lista wpisów w panelu nie pokazuje. Powód awarii był zapisany obok,
  poza zasięgiem wzroku administratora.
* Zgłoszenia przestały być odrzucane przez cache sprzed aktualizacji. Starszy
  format wpisu w pamięci podręcznej VIES nie odróżniał „numer nieważny" od
  „nie dało się ustalić"; po aktualizacji odczyt tłumaczył go na twardy
  werdykt „nieważny" i przez dobę zatrzymywał legalne zgłoszenia. Wpis
  w starym kształcie nie rozstrzyga — pytamy rejestr jeszcze raz.
* Krótki kod formularza pochodzi ze stałej także tam, gdzie był wpisany z ręki:
  w treści zakładanej strony i w radzie dla administratora, któremu strona
  zniknęła.
* Usunięta martwa metoda przewidująca los alarmu — miała własne słownictwo,
  niezgodne z resztą pliku, więc jej etykieta nie mogła wypaść nigdy. Trzy
  stany alarmu mają teraz stałe i jeden słownik.
* Materiały dla klienta wróciły do repozytorium jako źródła. Dziewięć plików
  PDF i draw.io leżało w paczce bez możliwości odtworzenia — stąd stopka
  mówiąca „v1.2.3" przy wtyczce na 1.3.10. Numer wersji jest teraz czytany
  z nagłówka wtyczki przy budowaniu.
* Status sumy kontrolnej NIP przestał twierdzić, że cyfra kontrolna się nie
  zgadza, gdy sumy w ogóle nie policzono — przy pustym polu, złej długości
  albo numerze z samych powtórzonych cyfr. Komunikat dla człowieka rozróżniał
  te przypadki od dawna, pole statusu dopiero teraz.
* Wynik weryfikacji VAT jest sprowadzany do jednej postaci raz, przed
  rozgałęzieniem. Wartość prawdziwa, która nie była literalnym „true" —
  a taka wraca z pamięci podręcznej i z bazy — rozjeżdżała wiersz: kolumna
  mówiła „ważny", status obok „niepotwierdzony". Nikt takiego wiersza potem
  nie prostował.
* Ostrzeżenie o stronie z formularzem podaje nazwę statusu zamiast jego
  technicznego identyfikatora i wskazuje miejsce, które tę stronę naprawdę
  pokazuje. Na instalacji z własnymi statusami wpisów rada prowadziła na listę
  bez tej strony.
* Wpis w pamięci podręcznej VIES bez rozstrzygnięcia nie udaje werdyktu
  „numer nieważny". Odczyt pytał o obecność pola, a nie o jego treść.
* Adres e-mail z polskimi znakami albo ze spacją nie jest już po cichu
  przepisywany. WordPress wycina z takiego adresu znaki, których nie obsługuje,
  i oddaje adres **inny, ale poprawny** — a walidacja sprawdzała już tę wersję.
  Zgłoszenie kończyło się sukcesem, oferta szła na skrzynkę, której klient nigdy
  nie podał, i nikt tego nie widział. Teraz formularz prosi o poprawienie adresu.
* Pola „Segment / branża" i „Przewidywany wolumen" przechodzą normalizację jak
  reszta pól. Wcześniej miały tylko limit długości, więc znaczniki HTML i złamania
  linii szły do bazy, a stamtąd do oferty i do PDF-a.
* Ślad po nieudanym utworzeniu strony nigdy nie jest pusty. Wtyczka blokująca
  zapis bez podania treści błędu zostawiała ślad nieodróżnialny od powodzenia —
  strony nie było, a panel milczał.
* Dziennik: opis miejsca awarii jest tłumaczony we wszystkich przypadkach (jedna
  gałąź wychodziła po polsku niezależnie od języka witryny), czas wyciszenia
  alarmu bierze się ze stałej zamiast z tekstu, a nieudany zapis losu alarmu
  zostawia ślad zamiast milczeć.

= 1.3.10 =
* Paczka instalacyjna schudla ze 101 plikow do 54 — testy i dokumentacja wewnetrzna (w tym AUDYT.md i DEBUG-RAPORT.md) nie jada juz do publicznie dostepnego katalogu wtyczek. Dokumenty dla klienta przeszly do paczki materialow.
* Szablon tłumaczeń. Wtyczka wołała `load_plugin_textdomain()` ze wskazaniem na
  katalog `languages`, którego nie dostarczała — nagłówka `Domain Path` też nie
  deklarowała. 71 ciągów było „przygotowanych do tłumaczenia" w kodzie i nie do
  przetłumaczenia w praktyce. Jest katalog, jest `.pot`, jest nagłówek.
* Harness procesu i benchmark nie wykonują się już po wejściu na ich adres
  z przeglądarki. Oba działają bez WordPressa, więc do tej pory serwer po prostu
  je uruchamiał każdemu, kto trafił na adres. Bliźniaczy plik wtyczki 2 miał tę
  ochronę od SR5-03; ten jej nie dostał.
* `Tested up to` mówi 7.0 — tyle, ile wynosi WordPress, na którym chodzi
  regresja. Wcześniej deklarowane 6.6 było o dwa wydania główne w tyle.

= 1.3.9 =
* Awaria strony z formularzem przestała być cicha. Gałęzie awaryjne zapisywały
  powód, ale nie gasiły flagi, od której zależy ostrzeżenie w panelu — a flaga
  nieustawiona domyślnie znaczy „wszystko w porządku". Panel wyglądał więc
  zdrowo przy zapisanym powodzie awarii.
* Komunikat odsyła tam, gdzie tę stronę naprawdę widać: strona w koszu kieruje
  do Strony → Kosz, a nie do „Wszystkie strony", gdzie kosza nie ma.
* Nieudane założenie strony podaje powód od WordPressa. Zapis był wywoływany bez
  trybu zgłaszania błędów, więc przy porażce wracało zwykłe zero zamiast opisu —
  i administrator dostawał „bez podania przyczyny" także wtedy, gdy przyczyna
  była znana.
* Komunikat o usuniętej stronie podaje krótki kod formularza wprost, zamiast
  kazać go zgadywać.
* Los alarmu w dzienniku to teraz FAKT, nie prognoza. Stan był odczytywany
  przed wysyłką, więc wpis meldował „wysłany" także wtedy, gdy poczta zaraz
  potem odmówiła. Po próbie dostarczenia wpis dostaje jedną z trzech wartości —
  wysłany, wyciszony albo nieudany — i to w opisie wpisu, czyli w polu, które
  listy pokazują, a nie tylko w metadanych.

= 1.3.8 =
* Nierozstrzygnięta Biała lista nie uchodzi już za sprawdzoną. Warunek statusu
  patrzył wyłącznie na `vat_valid`, a odczytany `company_status` nie był używany
  do niczego. Awaria HTTP, odpowiedź bez `subject` i błędne ciało dają `null` —
  w trybie synchronicznym BEZ flagi `company_status_pending`, bo ta powstaje
  tylko przy chybieniu cache'u w trybie asynchronicznym. Lead dostawał
  „sprawdzone", nigdy nie wracał do weryfikatora, a nieznany status firmy i tak
  wchodził do punktacji (+20). Firma spoza Polski to nadal NIE jest przypadek
  nierozstrzygnięty — Biała lista jej nie obejmuje.
* Przejęcie istniejącej strony dopasowywało po TEKŚCIE. Parametr `s` to
  wyszukiwarka WordPressa: przeszukuje też tytuł i zajawkę, dzieli frazę na
  słowa i dopasowuje częściowo. Strona ze skrótem w samym TYTULE gasiła
  ostrzeżenie o braku formularza i wprowadzała do menu link do strony, na której
  formularza nie ma. Rozstrzyga teraz `has_shortcode()` na treści — ta sama
  funkcja, którą WordPress decyduje, czy skrót zostanie wykonany.
* Odświeżanie statusu menu wykrywało awarię i milczało: widziało stronę w koszu
  albo w szkicu i wychodziło przez `return`, zostawiając flagę z czasów, gdy
  strona była opublikowana. Panel wyglądał na w pełni zdrowy. Teraz zapisuje
  ślad i zeruje flagę, a na ścieżce zdrowej ten ślad kasuje.
* Komunikat nie radzi już „aktywuj wtyczkę ponownie". Ponowna aktywacja wchodzi
  w tę samą gałąź, nadpisuje ten sam ślad i kończy się bez zmiany.
* Cache VIES trzymał samą wartość logiczną, więc odpowiedź z trafienia nie
  miała `vat_name` — ten sam numer dawał dwa różne zestawy danych zależnie
  wyłącznie od tego, czy transient akurat żyje. Odczyt obsługuje oba kształty,
  bo wpisy sprzed aktualizacji dożywają jeszcze dobę.
* Wyciszony alarm zostawia ślad. Wpis o awarii wyglądał identycznie jak ten,
  przy którym alarm poszedł do administratora; `meta_json` niesie teraz los
  alarmu (wysyłany/wyciszony) na obu ścieżkach — wyjątku i zatrzymania działu.
* Wyjątek spoza znanego działu podaje plik i linię. Pochodzenie było liczone do
  klucza ogranicznika, ale nie docierało ani do `meta_json`, ani do tekstu dla
  człowieka: dwie różne awarie dawały maile o identycznym temacie, a stopka
  obiecywała wyciszenie „z tego samego miejsca".
* Wyjątek bez komunikatu nie urywa już opisu na dwukropku — w to miejsce idzie
  klasa wyjątku, a mail nie ma pustej linii „Komunikat:".
* Dokumentacja Działu 7 opisywała `crc32(NIP)` jako regułę „utrzymywaną w kodzie
  agenta 7.2" — czyli dokładnie to, co usunęło wydanie 1.3.7.

= 1.3.7 =
* Handlowca wybiera wtyczka 3, ta o niego PYTA (filtr `mp_lead_assign_salesman`).
  Do 1.3.6 stal tu hasz `crc32(NIP) % liczba_handlowcow` — bez kraju, jezyka
  i zespolu — wiec jeden lead mial dwoch roznych handlowcow: innego w BD-3,
  innego w BD-1. Brak wtyczki 3 zostawia kolumne pusta zamiast zmyslac wybor.
* Nowy ekran „Leady" w panelu. Wtyczka nie miala dotad ZADNEGO ekranu, mimo ze
  od poczatku zakladala dwie role i trzy uprawnienia. Ekran pokazuje PUNKTACJE —
  liczona przy kazdym zgloszeniu i niewidoczna dotad nigdzie.
* Handlowiec widzi wlasne leady, manager i administrator wszystkie.
* Uprawnienia wtyczki trafiaja na role rowniez wtedy, gdy zalozyla je wtyczka 3
  (wspolne slugi `mp_handlowiec` / `mp_manager_sprzedazy`). Dotad `add_role()`
  milczal przy istniejacej roli i wynik zalezal od KOLEJNOSCI aktywacji wtyczek.
* Odmowa mowi, o co chodzi: powtorzone zgloszenie tej samej firmy dostaje
  komunikat o duplikacie zamiast „sprawdz dane". Odmowy Dzialu 5 (antyspam,
  CSRF, limit) pozostaja celowo generyczne.
* Komunikaty AJAX przechodza przez funkcje tlumaczaca.
* Ostrzezenie o nieutworzonej stronie gasnie takze po recznym zalozeniu strony
  ze skrotem — czyli po DRUGIEJ z dwoch drog, ktore samo zaleca — i da sie je
  zamknac. Strona w koszu nie uchodzi juz za istniejaca.
* Pole formularza nazywa sie „Rynek (kraj klienta)".
= 1.3.6 =

* FORMULARZ POZWALAŁ WYBRAĆ KRAJ Z CAŁEJ UNII, ale zgłoszenie z numerem VAT
  innego kraju nie miało jak przejść: sprawdzanie żądało dziesięciu cyfr, czyli
  reguły polskiej. Firma z Niemiec czy Czech dostawała komunikat o błędnym
  numerze — poprawnie przepisanym z własnej faktury. Wtyczka sprawdza teraz
  tylko to, czy numer w ogóle wygląda na numer (długość, obecność cyfr, brak
  ciągu z jednego znaku), a o jego ważności orzeka unijny system VIES, który
  i tak był już pytany osobno dla każdego kraju. Numery zagraniczne przestały
  też tracić litery: dotąd czyszczenie usuwało z nich wszystko poza cyframi,
  więc do VIES szedł numer, którego nikt nie wpisał. Numer polski jest
  sprawdzany jak dotąd — dziesięć cyfr i suma kontrolna.

* SPRAWDZANIE FIRMY NA BIAŁEJ LIŚCIE MINISTERSTWA FINANSÓW pytało o dzień
  wyznaczony strefą czasową ustawioną w panelu WordPressa. Biała lista jest
  rejestrem polskim i jej doba jest polska, więc witryna ustawiona na inną
  strefę — albo pozostawiona na domyślnym UTC — potrafiła zapytać o dzień
  wczorajszy lub jutrzejszy i zapamiętać taką odpowiedź na całą dobę. Dzień
  liczy się teraz zawsze według czasu w Polsce, niezależnie od ustawień
  witryny.

* DWA KOMUNIKATY DLA ADMINISTRATORA MÓWIŁY NIEPRAWDĘ. Pierwszy zapowiadał, że
  podaje krótki kod do wstawienia na stronie — i nie podawał żadnego. Drugi
  informował, że wtyczka "spróbowała dołożyć" pozycję do menu, choć przy braku
  wskazanego menu żadnej próby nie było. Oba mówią teraz to, co się naprawdę
  wydarzyło; pierwszy pokazuje kod i powód zgłoszony przez WordPressa.

* WYCISZANIE POWIADOMIEŃ O BŁĘDACH WEWNĘTRZNYCH obejmowało wszystkie awarie
  naraz. Wtyczka ogranicza częstotliwość takich powiadomień, żeby jeden
  powtarzający się błąd nie zasypał skrzynki administratora — ale licznik był
  wspólny dla całego przetwarzania. Pierwsza awaria wyciszała na jakiś czas
  powiadomienia o wszystkich pozostałych, więc o drugim, niezwiązanym
  problemie administrator mógł się w ogóle nie dowiedzieć. Licznik jest teraz
  osobny dla każdego etapu, a awarie spoza rozpoznanego etapu dostają licznik
  wyliczony z miejsca w kodzie, zamiast dzielić jeden wspólny. Ślad
  w dzienniku niesie identyfikator, po którym widać, którego licznika dotyczy.

= 1.3.5 =

* SPRAWDZANIE, CZY FIRMA BYŁA JUŻ KIEDYŚ KLIENTEM, myliło dwie różne sytuacje:
  "takiej firmy nie ma w archiwum" i "zapytanie do bazy się nie powiodło".
  Skutek dotyczył wyłącznie firm POWRACAJĄCYCH: przy chwilowej awarii bazy
  wtyczka uznawała, że firma jest nowa, próbowała założyć ją od zera, odbijała
  się o wcześniejszy wpis i zwracała ogólny komunikat o nieudanym zapisie.
  Powracający klient nie był reaktywowany i nie dowiadywał się, co naprawdę
  zaszło. Teraz brak wpisu i nieudany odczyt to dwie różne odpowiedzi, a wtyczka
  przy awarii mówi wprost, że nie sprawdziła archiwum.

* WERYFIKACJA NUMERU VAT W UNIJNYM SYSTEMIE VIES traktowała odpowiedź bez
  rozstrzygnięcia jak odpowiedź "numer nieważny". Gdy VIES odpowiadał, ale nie
  podawał werdyktu, poprawny numer bywał uznawany za błędny — i to na całą dobę,
  bo wynik trafiał do pamięci podręcznej. Wtyczka wymaga teraz jednoznacznej
  odpowiedzi; wszystko inne znaczy "nie ustalono" i jest ponawiane.

* DATY W KARCIE LEADA zapisywały się w dwóch różnych strefach czasowych: moment
  przypisania handlowca w czasie lokalnym witryny, a moment sprawdzenia VAT
  w czasie uniwersalnym. Kolumny jednego wiersza nie dawały się porównywać
  między sobą, co przy zestawieniach i raportach dawałoby ciche przesunięcie
  o różnicę stref. Wszystkie daty idą teraz w jednym czasie.

= 1.3.4 =

* SPRAWDZENIE, CZY FIRMA JUŻ ISTNIEJE, opierało się na tym, że w kopercie danych
  nie ma leadów — a to samo znaczyło "takiej firmy nie ma", "nikt nie pytał"
  i "zapytanie do bazy padło". Sam WordPress zwraca po nieudanym odczycie pustą
  listę, więc awaria bazy wyglądała dokładnie jak nowy klient: zgłoszenie szło
  do zapisu, tam odbijało się o klucz unikalności i zgłaszający dostawał ogólne
  "nie udało się utworzyć leada" zamiast informacji, że baza nie odpowiada.
  Dział 1 potwierdza teraz, że odczyt SIĘ ODBYŁ, a Dział 7 tego wymaga.
  Uczciwie o skali: pozostałe drogi do tego stanu były zamknięte (zgłoszenie bez
  NIP-u nie dochodzi do dedupu, a baza ma klucz unikalny na parze kraj+NIP), więc
  to wzmocnienie obrony, a nie naprawa przepuszczanych duplikatów.

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
