=== MP Sales Workflow ===
Contributors: krzysiek2115op
Tags: sprzedaz, crm, workflow, follow-up
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Przypisanie handlowca, statusy procesu, powiadomienia e-mail, zadania follow-up,
dashboard i dziennik aktywności.

== Description ==

Trzecia z trzech wtyczek procesu "formularz → oferta". Domyka proces: prowadzi
lead-a i wygenerowaną ofertę przez statusy sprzedażowe, pilnuje przypisania
handlowca, wysyła powiadomienia e-mail, zakłada automatyczne zadania follow-up
i udostępnia dashboard z dziennikiem aktywności.

Wtyczka jest konsumentem zdarzeń dwóch pozostałych modułów — nie przyjmuje
formularzy (to MP Lead Intake) i nie buduje ofert (to MP Offer Builder).

== Installation ==

1. Wgraj katalog wtyczki do `wp-content/plugins/` (lub zainstaluj ZIP przez
   Wtyczki → Dodaj nową → Wyślij wtyczkę) i aktywuj.

Wdrozenie produkcyjne wymaga dodatkowo ustawien spoza wtyczki: stalych
`MP_HASH_PEPPER` i `MP_SW_LINK_KEY` w `wp-config.php`, systemowego crona,
rekordow SPF/DKIM/DMARC oraz blokady katalogu z ofertami PDF. Pelna checklista:
`docs/WDROZENIE.md`. Bez klucza `MP_SW_LINK_KEY` wtyczka celowo wstrzyma wysylke
powiadomien do klientow.

== Changelog ==

= 1.3.12 =
* Wydanie porządkowe. Wtyczka nie dostała żadnej zmiany w działaniu — poprawione
  zostało wyłącznie jedno uchybienie stylu kodu (WordPress Coding Standards), które
  weszło przy naprawie słownika przejść w 1.3.11 i zostało wtedy przeoczone: brak
  pustej linii przed blokiem komentarza.
* Numer wersji rośnie mimo braku zmian w działaniu, bo pliki wysyłane klientowi
  różnią się od tych z 1.3.11. Dwa różne zestawy plików pod jednym numerem to
  gorszy problem niż wydanie z drobnym zakresem.

= 1.3.11 =
* Segment klienta dociera wreszcie do procesu. Wtyczka 1 przekazuje go razem
  ze zgłoszeniem, kolumna w bazie istniała, ale zapis jej nie wypełniał,
  a ekran procesu czytał ją z wiersza, którego przy pierwszym zdarzeniu
  jeszcze nie ma. Dobór handlowca i treść powiadomień pracowały na pustym
  segmencie za każdym razem, gdy proces powstawał.
* Termin SLA i termin zadania nie zależą już od tego, jaką strefę czasową
  ustawił ktoś inny. Obliczenia czytały datę GMT bez oznaczenia strefy, więc
  interpretowała ją strefa domyślna PHP — a w dobie zmiany czasu na letni
  różnica rosła o dodatkową godzinę. Harmonogram tej samej wtyczki liczył to
  poprawnie od początku; teraz wszystkie trzy miejsca mówią jednym głosem.
* Odmowa zmiany statusu rozróżnia dwa różne błędy. Literówka w nazwie statusu
  dostawała komunikat o nielegalnym przejściu, choć problem był w treści
  żądania, a nie w regule. Kod odmowy bez zmian.
* Stopki materiałów dla klienta biorą numer wersji z nagłówka wtyczki. Mówiły
  „v1.0.0" przy wtyczce na 1.3.10.
* Druga oferta dla tego samego procesu przestała ginąć po cichu. Poprawiona
  oferta zatwierdzona dla procesu, który już jest w statusie „oferta wysłana",
  kończyła się odpowiedzią „przyjęte" bez żadnego skutku: klient nie dostawał
  powiadomienia o nowej ofercie, a zadania kontaktowe nie powstawały.
* Status spoza słownika nie jest już potwierdzany sukcesem. Proces zapisany
  przez starszą wersję maszyny statusów albo poprawiony ręcznie w bazie
  dostawał „stan potwierdzony" zamiast odmowy, więc nigdy nie zgłaszał się
  jako zepsuty.
* Zdarzenie o nieobsługiwanym typie dostaje własny komunikat, zamiast rady
  dotyczącej pola, którego nadawca nie wysyłał.
* Wynik maszyny statusów ma ten sam komplet pól we wszystkich przypadkach —
  wcześniej zdarzenia niezmieniające statusu oddawały tablicę uboższą o jedno
  pole, choć komentarz obok zapowiadał co innego.
* Proces, który został w statusie „nowy" (bo w chwili zgłoszenia nie było
  żadnego handlowca), da się teraz oznaczyć jako przegrany. Wcześniej jedynym
  wyjściem z tego statusu było „przypisany", więc taki proces trzeba było
  najpierw przypisać komuś na niby.

= 1.3.10 =
* Paczka instalacyjna schudla ze 117 plikow do 52 — testy i dokumentacja wewnetrzna nie jada juz do publicznie dostepnego katalogu wtyczek. Instrukcja wdrozenia przeszla do paczki materialow.
* Handlowca da się skonfigurować z panelu. Dział 4 dobiera właściciela procesu
  po polach `mp_sw_country`, `mp_sw_langs` i `mp_sw_active`, a ustawić je dało
  się wyłącznie przez WP-CLI albo bezpośrednio w bazie. Konto z samą rolą
  „Handlowiec" nie jest kandydatem dla żadnego procesu, więc system po
  instalacji przyjmował zgłoszenia i po cichu nie robił z nimi nic. Pola są
  teraz na standardowym ekranie profilu użytkownika — z kontrolą uprawnień,
  nonce i normalizacją kraju do ISO-2.
* Szablon tłumaczeń. Wtyczka wołała `load_plugin_textdomain()` ze wskazaniem na
  katalog `languages`, którego nie dostarczała — nagłówka `Domain Path` też nie
  deklarowała. 186 ciągów było „przygotowanych do tłumaczenia" w kodzie i nie do
  przetłumaczenia w praktyce.
* Bramka integracyjna i scenariusze bezpieczeństwa nie wykonują się już po
  wejściu na ich adres z przeglądarki.
* `Tested up to` mówi 7.0 — tyle, ile wynosi WordPress, na którym chodzi
  regresja.

= 1.3.8 =
* BRAMKA K5.2 SPRAWDZAŁA ZGODNOŚĆ PRZEJŚCIA ZE ZDARZENIEM JEDNOSTRONNIE —
  tylko wtedy, gdy koperta sama przyznawała, że zmienia status. Przypadku
  odwrotnego nie badał nikt: gdy `changes_status` było fałszywe albo tablicy
  `transition` w ogóle nie było, oczekiwane skutki były pustą listą, agent też
  oddawał pustą i para kończyła się sukcesem — mimo że zdarzenie żądało zmiany
  statusu. Wywołujący dostawał „przyjęte", a status zostawał na miejscu. Brak
  zmiany jest teraz zgodny tylko wtedy, gdy zdarzenie statusu nie rusza albo
  żądany status już obowiązuje.

= 1.3.7 =
* PIERWSZE ZGLOSZENIE PO INSTALACJI GINELO, GDY NIE BYLO JESZCZE HANDLOWCOW.
  Bramka Dzialu 4 wymagala wlasciciela procesu ZAWSZE i wywracala cale
  zdarzenie, gdy nie bylo komu go przypisac — a to stan zupelnie normalny tuz
  po instalacji, zanim administrator zalozy konta. Lead zapisywal sie w BD-3,
  wtyczka 2 robila szkic oferty, a procesu w BD-1 nie bylo w ogole: ani wiersza,
  ani wpisu w dzienniku. Teraz proces powstaje zawsze, zostaje w statusie
  „nowy", ma pusta kolumne wlasciciela, a powod trafia do dziennika
  (`lead.unassigned`). Bramka pilnuje juz nie „zawsze ktos przypisany", tylko
  „brak wlasciciela musi miec podany powod" — cisza pozostaje zabroniona.
  Przypisanie kogos nieaktywnego nadal jest niemozliwe.
* Zdarzenie `mp_sw_flow_updated` niesie `assigned_user_id`, a wtyczka 1 sie na
  nie wpina — dzieki temu przypisanie handlowca w BD-3 nadaza za rotacja
  i przepisaniem procesu przez managera.
* Wtyczka odpowiada na filtr `mp_lead_assign_salesman` tym samym doborem
  (kraj, jezyk, zespol, obciazenie), ktorego uzywa Dzial 4 dla procesu.
* Manager sprzedazy ma wlasny widok: „Podsumowanie zespolu" z rozkladem
  statusow, obciazeniem handlowcow i liczba procesow po terminie SLA.
  Liczone z wierszy JUZ POBRANYCH — zasada „jeden strzal odczytu" zostaje.
* Kod bledu w panelu jest podpisany („Kod do zgloszenia awarii"), a nie
  doklejony za zdaniem bez wyjasnienia.
* Naglowki pulpitu przechodza przez funkcje tlumaczaca.
* Wtyczka ma wreszcie testy w CI (zadanie `integracja`: WordPress + MySQL).
= 1.3.6 =

* POWIADOMIENIE E-MAIL BYWAŁO UZNAWANE ZA NIEGOTOWE Z POWODU WŁASNEJ TREŚCI.
  Wtyczka pilnuje, żeby w wysyłanej wiadomości nie został niewypełniony
  znacznik szablonu — ale szukała go w tekście już wypełnionym. Jeśli nazwa
  firmy, stanowisko albo inna podstawiona wartość zawierała klamry, wyglądało
  to jak znacznik, którego nie udało się wypełnić: wysyłka była wstrzymywana,
  a proces sprzedażowy nie przechodził do kolejnego statusu. Niewypełnione
  znaczniki wyliczane są teraz z samego szablonu, zanim cokolwiek zostanie
  w nim podstawione, więc treść danych klienta nie ma już na to wpływu.

= 1.3.5 =

* ŻĄDANIE WYDANIA DANYCH OSOBOWYCH (RODO) pomijało tę wtyczkę. Usuwanie danych
  działało od początku, ale eksport — czyli prawo klienta do otrzymania kopii
  swoich danych — obejmował tylko dwie pozostałe wtyczki. Raport z narzędzia
  WordPressa wyglądał na kompletny, mimo że brakowało w nim procesów
  sprzedażowych i historii wysłanych powiadomień. Wtyczka wydaje teraz oba
  komplety danych, wyłącznie dla adresu, którego dotyczy żądanie.

* TŁUMACZENIA nie były w ogóle wczytywane, choć wtyczka ma 176 tekstów
  przygotowanych do przetłumaczenia i prowadzi korespondencję w kilku językach.
  Na witrynie polskiej nie było tego widać, bo teksty źródłowe są po polsku;
  problem ujawniłby się dopiero przy pierwszym tłumaczeniu.

* DWA TESTY zaliczały się również wtedy, gdy sprawdzana funkcja była zepsuta —
  obie oczekiwały wyniku "zero", a zero pojawia się także po awarii. Doszły
  kontrole, które najpierw potwierdzają, że sprawdzana droga w ogóle działa.

= 1.3.4 =

* KONTROLA SKUTKÓW ZMIANY STATUSU nie sprawdzała tego, co miała. Kontroler liczył
  oczekiwane następstwa z dokładnie tego samego miejsca, z którego brał je
  wykonawca, więc porównanie zawsze wychodziło na zero. Nikt nie pilnował
  najważniejszego: czy status, na który proces przechodzi, jest tym, o który
  prosi zdarzenie. Podmieniona wartość przechodziła bez słowa, a powiadomienia
  i zamknięcie zadań wykonywały się dla CUDZEGO statusu. Kontroler wyprowadza
  teraz status docelowy niezależnie, wprost z rodzaju zdarzenia.
* SCHEMAT BAZY obiecywał, że powtórka tego samego zdarzenia dostanie odtworzoną
  odpowiedź z pierwszego przebiegu. Kolumna na tę odpowiedź istniała, ale nic jej
  nigdy nie zapisywało ani nie czytało. Obietnica znika ze schematu; zabezpieczenie
  przed podwójną obsługą działa jak dotąd i nigdy na tej kolumnie nie stało.
  Aktualizacja podnosi schemat bazy do 0.4.0 i usuwa martwą kolumnę — dane
  klientów są nietknięte, kolumna była pusta w każdej instalacji.

= 1.3.3 =

* RODO: anonimizacja nie usuwała nazwy firmy klienta z powiadomień wysłanych do
  handlowca — czyściła wyłącznie wiadomości do klienta. Nazwa zostawała w bazie
  na stałe, bo tabela powiadomień nie ma retencji, a żądanie usunięcia danych
  kończyło się komunikatem o powodzeniu. Adres pracownika zostaje bez zmian:
  to dane firmowe, a wiersz jest śladem wysyłki.
* KOLEJKA WIADOMOŚCI mogła wysłać tę samą ofertę dwa razy. Dwa równoległe
  przebiegi zadań cyklicznych dostawały tę samą paczkę, bo licznik prób rósł
  dopiero przy wysyłce. Teraz przejęcie wiersza i podbicie licznika to jedna
  operacja — wysyła ten, kto pierwszy przejął.

= 1.3.2 =

* Nieudana wysylka alarmu do administratora zostawia slad w dzienniku
  technicznym. Wczesniej przy zepsutej poczcie ostrzezenie o wstrzymanej
  kolejce powiadomien ginelo bez zadnego sladu.
* Status wiersza zdarzenia ma wlasna stala w warstwie schematu bazy.
  Wczesniej byl napisem, a najblizsza istniejaca stala nalezala do slownika
  ZADAN follow-up — jej uzycie zwiazaloby ze soba dwie niezalezne tabele.
* Dokumentacja i testy: identyfikatory bledow z rejestru w naglowkach testow
  regresji, statyczny test pilnujacy slownika statusow.

= 1.3.1 =

* Zadanie follow-up bylo domykane jako WYKONANE takze wtedy, gdy powiadomienia
  nie bylo ani w kolejce, ani na liscie pominietych. Handlowiec nic nie
  dostawal, a pulpit pokazywal zadanie jako zrobione i nikt do niego nie
  wracal. Teraz o statusie decyduje dowod wyslania, nie dowod porazki.
* Zlecenie przebiegu kolejki powiadomien zwracalo ten sam wynik przy „termin
  juz stoi" i przy nieudanym zaplanowaniu. Drugi przypadek oznacza kolejke,
  ktora nigdy nie ruszy — teraz jest osobnym stanem, trafia do dziennika
  (`queue.schedule_failed`), a przeglad crona co 5 minut sam zamawia zalegla
  kolejke.
* `status.change` bez statusu docelowego konczyl sie POTWIERDZENIEM, mimo ze
  status sie nie zmienial — a proces i tak byl zapisywany, wiec poprawiona
  proba z tym samym kluczem zdarzenia odbijala sie jako powtorka. Teraz odmowa
  400 (MP3-E121); zdarzenia bez statusu docelowego to wylacznie `task.due`
  i podglad pulpitu.
* Aktor zdarzenia recznego jest porownywany z zalogowanym uzytkownikiem.
  Rozbieznosc = odmowa 403 przed jakimkolwiek zapisem, z wlasnym kodem bledu
  (MP3-E102). Dziennik nie moze przypisac zmiany statusu komus, kto jej nie
  wykonal. Zdarzenia systemowe i harmonogramu bez zmian.
* Odmowa zostawiala w kontekscie zakres widoku podany w zadaniu. Sciezka
  odmowy czysci go teraz razem z lista czlonkow zespolu.

= 1.3.0 =

* PODPISANY LINK DO OFERTY W OGOLE NIE DZIALAL. `MP_SW_Download::register()`
  bylo wolane z wnetrza callbacka `init` (priorytet 10) i dopinalo handler na
  priorytecie 5 — a WordPress pomija callback dodany na priorytecie juz
  minietym. Klient klikal link z e-maila i dostawal zwykla strone. Ostatni krok
  procesu „oferta zatwierdzona -> wysylka do klienta" byl martwy.
* WYCIEK CUDZEJ OFERTY. Zapytanie `WHERE request_id = %s OR id = %d` z uchwytem
  UUID rzutowanym na int trafialo w cudzy wiersz — klient z waznym, poprawnie
  podpisanym linkiem dostawal dokument innej firmy. Naprawione RAZEM z punktem
  wyzej, bo wyciek byl przez tamten blad zamaskowany.
* Klient dostawal „oferte nr ." — numer oferty nigdy nie trafial z migawki do
  wiersza procesu, a powiadomienia czytaly wylacznie stamtad.
* RODO: anonimizacja kolejki filtrowala po kolumnie, ktorej nie ma w schemacie.
  Zapytanie padalo po cichu, wiec zadanie usuniecia danych konczylo sie
  „sukcesem", a adres klienta zostawal w bazie razem z trescia wiadomosci.
* Zanonimizowany adres nie wraca juz z wtyczki 1 przy kolejnym powiadomieniu.
* Zadanie follow-up, ktorego przypomnienie nie doszlo do handlowca, konczy sie
  jako `undelivered`, a nie „wykonane".
* Licencja GPL-2.0-or-later: pelny tekst w repozytorium i w paczce klienckiej.

= 1.2.0 =

* NIEDOSTARCZALNY ADRES WEWNETRZNY NIE BLOKUJE JUZ PROCESU. Krytyk 7.2 odrzucal
  CALA koperte, gdy ktorykolwiek odbiorca mial zly adres — takze wtedy, gdy
  chodzilo o handlowca. Skutek byl nieproporcjonalny: JEDNO konto bez e-maila
  blokowalo przyjmowanie leadow. Klient wypelnial formularz, po jego stronie
  wszystko bylo poprawne, a lead nie powstawal, bo ktos w firmie mial
  niedokonczony profil.
* Teraz rozroznienie idzie po tym, CZYJ kontakt zawodzi. Brak dojscia do KLIENTA
  nadal unieważnia zdarzenie „wyslij oferte" — bez adresu klienta nie ma ono
  sensu. Brak dojscia do wlasnego pracownika to usterka administracyjna:
  pomijamy to jedno powiadomienie i prowadzimy proces dalej.
* Pominiete powiadomienie NIE znika po cichu — trafia do dziennika jako
  `notification.skipped`, z rola odbiorcy, szablonem i powodem (brak adresu /
  adres niepoprawny). Bez adresu, tak jak reszta dziennika (RODO). Dzieki temu
  kryterium odbioru 5.5 („odtworzenie historii wysylek") zostaje prawdziwe
  wlasnie tam, gdzie jest najbardziej potrzebne.

= 1.1.1 =

* SAMONAPRAWA HARMONOGRAMU. Zadania cykliczne (przeglad follow-upow, retencja)
  zakladala WYLACZNIE aktywacja wtyczki, wiec raz skasowane NIE WRACALY nigdy.
  Skasowac je potrafi wtyczka do zarzadzania cronem („wyczysc wszystkie"),
  `wp cron event delete`, albo odtworzenie bazy z kopii sprzed aktywacji.
  Objawu nie bylo widac: zaden blad, po prostu przestawaly powstawac zadania
  follow-up d+3/d+7 i nie dzialala retencja. Teraz harmonogram odtwarza sie sam
  przy kazdym zaladowaniu wtyczki — ta sama filozofia, co aktualizacja schematu
  bazy na `plugins_loaded` (hak aktywacji nie odpala sie przy podmianie plikow).
* Dzial 2 rozroznia oferte podana w KOPERCIE od numeru wzietego z wiersza
  procesu. Twarda walidacja dotyczy tylko koperty (to jest wektor podszycia);
  numer z procesu bywa nieaktualny, bo oferte da sie usunac w module ofertowym —
  przy poprzednim zachowaniu handlowiec nie mogl oznaczyc sprzedazy jako
  przegranej, bo pipeline odbijal sie o nieistniejacy dokument.

= 1.1.0 =

Audyt zgodnosci ze zleceniem — trzy braki zamkniete.

* JEDNA rola managera. Wtyczka zakladala wlasne `mp_manager` z taka sama nazwa
  wyswietlana, co `mp_manager_sprzedazy` z MP Lead Intake: administrator widzial
  w liscie rol „Manager sprzedazy" dwa razy i nie mial jak ich odroznic. Teraz
  uzywamy sluga z wtyczki 1, a aktywacja przenosi konta ze starej roli i ja kasuje.
* Deinstalacja NIE kasuje juz rol — obie dzielimy z MP Lead Intake, wiec ich
  usuniecie zabieraloby handlowcom dostep do leadow. Zdejmowane sa wylacznie
  uprawnienia tej wtyczki.
* Pulpit dostal AKCJE. Wczesniej byl tylko do odczytu: punkt AJAX dzialal, ale nic
  w interfejsie go nie wywolywalo, wiec handlowiec nie mial jak przesunac procesu.
  Teraz kazdy wiersz ma wybor statusu docelowego (z maszyny statusow, nie z osobnej
  listy) oraz nazwany przycisk „Zatwierdz i wyslij oferte" — krok 4 zlecenia.
  Formularz bez JavaScriptu, jeden token CSRF sprawdzany dwa razy.
* Dziennik aktywnosci widoczny w panelu (kryterium odbioru 5.5) — klikniecie leada
  otwiera historie procesu. Zakres sprawdzany przez przynaleznosc do juz pobranej
  listy, bez dodatkowego zapytania.
* Zatwierdzenie oferty bez gotowego PDF ma wlasny kod MP3-E190 i komunikat
  wskazujacy modul ofertowy, zamiast bledu wewnetrznego MP3-E500.
* Dzial 2 bierze numer oferty z procesu, gdy koperta go nie niesie — reczne
  zatwierdzenie podaje sam `lead_id`, a handlowiec nie wpisuje numeru z pamieci.

= 1.0.0 =

Pierwsze wydanie produkcyjne. Schemat bazy: 0.3.0.

Proces (pipeline 9 dzialow, pary Agent+Krytyk 1:1, bramka jakosci po kazdym dziale):

* Dzial 1 — brama zdarzen i kontrakt koperty; powtarzalne klucze zdarzen (idempotencja).
* Dzial 2 — jeden strzal odczytu BD-1 (migawka: role, zespol, lead, oferta).
* Dzial 3 — uprawnienia i zakres roli (WLASNE / ZESPOL / WSZYSTKIE).
* Dzial 4 — przypisanie handlowca.
* Dzial 5 — maszyna statusow procesu.
* Dzial 6 — zadania follow-up d+3 / d+7.
* Dzial 7 — powiadomienia e-mail (szablony wersjonowane, PL/EN).
* Dzial 8 — zapis jedna transakcja + dziennik aktywnosci.
* Dzial 9 — wyjscie i uruchomienie kolejki poczty po COMMIT.

Warstwa techniczna: punkt AJAX, cron zadan i retencji, kolejka e-mail z ponowieniami,
haki wejsciowe z MP Lead Intake i MP Offer Builder, pulpit z dziennikiem.

Utwardzenie przed produkcja:

* Macierz pochodzenia zdarzen — typ zdarzenia zwiazany z kanalem, ktorym przyszlo;
  domknieta domyslnie (nieznany typ nie ma zadnego dozwolonego zrodla).
* Sciezka HTTP wylacznie dla zalogowanych; jedyny publiczny punkt to podpisany
  link do oferty (HMAC-SHA256, waznosc 14 dni, limit prob).
* Autoryzacja obiektowa: siegniecie po cudzy proces zwraca 404, nie 403.
* Szesc uprawnien zamiast jednego; manager widzi ZESPOL, nie cala firme.
* Adres odbiorcy i sciezka dokumentu czytane z bazy — koperta niesie same
  identyfikatory.
* Ochrona przed wstrzykiwaniem naglowkow e-mail; bezpiecznik 200 wiadomosci/h.
* Atomowe klamrowanie zadan crona; wartownik statusu wewnatrz transakcji zapisu.
* Slownik kodow MP3-Exxx — odpowiedz nie ujawnia regul ani zapytan; dziennik
  techniczny do error_log, nie do bazy.
* RODO: anonimizacja bez kasowania historii, dziennik bez adresu i bez IP.
* Deinstalacja kasuje dane tylko po wlaczeniu opcji `mp_sw_delete_data`.
* Checklista wdrozenia produkcyjnego: `docs/WDROZENIE.md`.

= 0.1.0 =
* Szkielet wtyczki (nagłówek, stałe, deinstalacja).
