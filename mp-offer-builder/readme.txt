=== MP Offer Builder ===
Contributors: krzysiek2115op
Tags: oferty, pdf, woocommerce, cennik
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Kalkulacja cenowa, integracja z WooCommerce, generowanie ofert PDF.

== Description ==

Druga z trzech wtyczek procesu "formularz → oferta". Odbiera zakwalifikowanego
lead-a z MP Lead Intake, dobiera wariant cenowy na bazie cen WooCommerce,
generuje ofertę PDF wraz z numeracją i historią wersji.

== Installation ==

1. Wgraj katalog wtyczki do `wp-content/plugins/` (lub zainstaluj ZIP przez
   Wtyczki → Dodaj nową → Wyślij wtyczkę) i aktywuj.
2. Wymagane WooCommerce z WŁĄCZONYMI podatkami oraz zdefiniowaną krajową
   stawką VAT (dla polskiej firmy: stawka standardowa PL 23% w WooCommerce →
   Ustawienia → Podatki → Stawki standardowe).
3. WAŻNE — kraj bazowy sklepu musi odpowiadać krajowi stawki krajowej.
   Wtyczka pobiera stawkę VAT z kraju bazowego sklepu (WooCommerce →
   Ustawienia → Ogólne → „Adres sklepu / Kraj"). Dla polskiej firmy ustaw
   kraj bazowy na Polskę — inaczej oferta nie znajdzie krajowej stawki VAT.
   Prawdziwy polski sklep ma tę wartość domyślnie; wymaga uwagi tylko na
   świeżym/testowym WooCommerce (domyślnie USA).

== Security & Privacy ==

Poufność ofert (indywidualne ceny B2B) jest chroniona wielowarstwowo: pliki PDF
leżą w katalogu prywatnym, a pobieranie przechodzi przez endpoint z weryfikacją
nonce + uprawnienia + właściciela oferty (nigdy publiczny link). Każde pobranie i
odmowa są logowane (rozliczalność). Endpoint wysyła nagłówki nosniff / X-Frame-
Options: DENY / Referrer-Policy / no-store.

Zalecenia wdrożeniowe (poziom serwera, poza wtyczką):

* HTTPS + HSTS: skonfiguruj na poziomie serwera/hostingu (nagłówek Strict-
  Transport-Security dla całej witryny) — wtyczka nie ustawia nagłówków
  ogólnowitrynowych, żeby nie kolidować z konfiguracją serwera.
* nginx: pliki .htaccess są ignorowane. Dodaj do konfiguracji blok blokujący
  bezpośredni dostęp do katalogu ofert (wtyczka pokazuje to ostrzeżenie w panelu):
  `location ~* /uploads/mp-offer-builder-private/ { deny all; return 403; }`

RODO/GDPR:

* Dane osobowe klienta (nazwa, e-mail, NIP, kraj) obsługują wbudowane narzędzia
  WordPressa: Narzędzia → Eksport danych osobowych oraz Narzędzia → Usuń dane
  osobowe (po adresie e-mail). Usunięcie ANONIMIZUJE dane w ofertach, redaguje
  snapshoty wersji i kasuje pliki PDF; sam wiersz oferty zostaje jako dokument
  handlowy o wartości dowodowej.
* Retencja: wtyczka NIE usuwa ofert automatycznie. Politykę retencji (jak długo
  przechowywać oferty/logi) określa administrator zgodnie z wymogami prawnymi.
* Sugerowana treść polityki prywatności jest dodawana w Ustawienia → Prywatność.

== Changelog ==

= 1.3.1 =

* BEZ ZMIAN W KODZIE tej wtyczki. Wersja podniesiona razem z pozostalymi
  dwiema, zeby komplet mial jeden numer — naprawy 1.3.1 dotycza wtyczek
  MP Lead Intake i MP Sales Workflow. Zgodnosc na stykach (haki
  `mp_lead_created`, `mp_lead_verified`, `mp_offer_created`,
  `mp_offer_approved`) sprawdzona testami integracyjnymi: 89/89.

= 1.3.0 =

* CENY BRUTTO Z WOOCOMMERCE BYLY LICZONE JAK NETTO. Sklep z ustawieniem „Ceny
  wprowadzone z podatkiem" (typowa konfiguracja w Polsce) dostawal oferte
  drozsza o cala stawke VAT: produkt 100,00 zl brutto trafial na dokument jako
  netto 100,00 + VAT 23,00 = 123,00 zl. Blad byl CICHY — arytmetyka byla
  wewnetrznie spojna, wiec wszystkie bramki jakosci przechodzily.
* ZATWIERDZENIE OFERTY BYLO NIEWIDZIALNE DLA BLOKADY OPTYMISTYCZNEJ. Trwajacy
  w tle przebieg nadpisywal oferte juz zatwierdzona i WYSLANA: cofal ja do
  szkicu i podmienial PDF, ktory klient wlasnie dostal, a kolejne kliniecie
  „Zatwierdz" wystawialo zdarzenie po raz drugi.
* Wyjatek nasluchu leada nie wychodzi juz do wtyczki 1 — wczesniej awaria
  modulu ofertowego niszczyla leada i konczyla sie bledem 500 dla klienta.
* Licencja GPL-2.0-or-later: pelny tekst w repozytorium i w paczce klienckiej.

= 1.2.0 =

Kryterium odbioru 5.3 („PDF w jezyku polskim i angielskim z wlasciwymi cenami
oraz numerem oferty") — dwie usterki znalezione przez odczytanie gotowego pliku,
a nie samego kodu. Schemat bazy: 0.9.0, szablony: 1.1.0.

* NUMER OFERTY NA DOKUMENCIE. Numer trafial wylacznie do metadanych PDF
  (`addInfo('Title', ...)`) i do nazwy pliku — klient otwieral oferte i numeru
  na stronie NIE WIDZIAL. Kontrola w Dziale 9 sprawdzala wlasnie metadane, wiec
  luka przechodzila niezauwazona. Szablony PL/EN maja teraz numer w naglowku.
* Wymagalo to pojecia ZNACZNIKA ODROCZONEGO: numer powstaje w Dziale 8, a
  szablon scalany jest w Dziale 7 — w chwili scalania numeru fizycznie nie ma.
  `{{offer_number}}` przechodzi przez bramke „zaden znacznik nie zostaje pusty"
  nietkniety, a wypelnia go Dzial 9 tuz przed renderem i tam bramka domyka sie
  juz bez wyjatkow. Alternatywa (numeracja przed scaleniem) ruszalaby sprawdzona
  kolejnosc dzialow dla jednego pola.
* Instalacje sprzed tej wersji dostaja nowy szablon przez aktualizacje: warunek
  „wstaw, jesli nie ma wiersza w tym jezyku" nigdy by sie nie spelnil. Nadpisujemy
  WYLACZNIE szablon o naszej nazwie domyslnej i starszej wersji — recznie
  podmieniona tresc zostaje nietknieta.
* FORMAT KWOT W POLSKIEJ OFERCIE. Polski PDF pokazywal „2,099.98 zl", czyli
  angielskie separatory przy polskim symbolu waluty. Przyczyna byla podstepna:
  rozszerzenie intl BYLO zaladowane, ale ICU nie mialo danych dla polskiego, a
  NumberFormatter w takim wypadku NIE zglasza bledu — po cichu uzywa en_US.
  Wlasny fallback (formatujacy poprawnie) nigdy sie nie uruchamial, bo warunek
  sprawdzal tylko istnienie klasy. Teraz pytamy formatter, jaka lokalizacje
  NAPRAWDE dostal.
* Nowy test `tests/koncowe/pdf-pl-en-numer.php` (31/31) generuje obie oferty
  pelnym pipelinem na zywym WooCommerce; tresc gotowych PDF-ow zweryfikowana
  narzedziem pdftotext.

= 1.1.0 =

Audyt zgodnosci ze zleceniem — dwa braki zamkniete. Schemat bazy: 0.8.0.

* ZATWIERDZANIE OFERTY (krok 4 zlecenia). Wtyczka 3 od poczatku nasluchiwala
  zdarzenia `mp_offer_approved`, ale NIKT go nie wystawial: pipeline konczyl
  oferte w statusie `draft` i proces sie tam urywal. Lista ofert ma teraz akcje
  „Zatwierdz" (token CSRF per-oferta, uprawnienie, kontrola wlasciciela), ktora
  przenosi oferte w status `approved` i wystawia zdarzenie dokladnie raz.
* Przejscie jest warunkowe w samym UPDATE (`WHERE status = 'draft'`), wiec przy
  dwoch rownoleglych zadaniach zdarzenie wychodzi raz — o zwyciezcy decyduje baza,
  nie kontrola w PHP.
* Nie da sie zatwierdzic oferty bez numeru i pliku PDF. Wczesniej taka proba
  konczylaby sie odmowa dopiero we wtyczce 3 (MP3-E190), czyli o jeden modul
  za pozno; teraz blad pada tam, gdzie mozna go naprawic.
* Oferta zatwierdzona jest ZAMROZONA: Dzial 1 przyjmowal offer_id wylacznie w
  statusie draft, wiec dzialo sie to samo z siebie — ekran budowy mowi to jednak
  od razu, zamiast odbijac zapis po wypelnieniu calego formularza. Znika tez
  odnosnik „Edytuj" przy ofercie zatwierdzonej.
* Dopiero teraz ma jak zadzialac odswiezanie statusu VAT wylacznie w szkicach
  (`on_lead_verified`) — wczesniej ZADNA oferta nie wychodzila ze stanu draft.
* PROSBA KLIENTA W SZKICU. Wtyczka 1 zbiera w formularzu „Produkty / zakres
  zainteresowania" i „Przewidywany wolumen", ale zdarzenie ich nie nioslo —
  handlowiec musial otworzyc liste leadow w drugiej wtyczce i przepisac tresc
  recznie. Szkic ma teraz kolumny `lead_products` / `lead_est_volume`, a ekran
  budowy pokazuje je tylko do odczytu.
* Swiadomie NIE zamieniamy tego tekstu na pozycje oferty: „500 szt. filtrow,
  moze tez obudowy" nie da sie bezblednie zmapowac na product_id, a pomylka
  oznaczalaby PDF z cena za nie ten towar. Pozycje nadal wybiera handlowiec.
* Powtorne zdarzenie dla tego samego leada (reaktywacja) odswieza opis prosby w
  istniejacym szkicu; pusty payload starszej wtyczki 1 niczego nie kasuje.
* Kolumna „Status" pokazuje nazwy po polsku (Szkic / Zatwierdzona).

= 1.0.5 =
* Pelny Security Hardening (5 niezaleznych Security Reviewerow per segment + cross-review):
* [Bezp.] Rate-limiting endpointu budowy oferty (20/min/uzytkownik) + twardy limit
  rozmiaru zadania (256 KB, glebokosc JSON) i liczby pozycji (max 200) — ochrona przed
  floodem i DoS przez rozdmuchane wejscie.
* [Bezp.] Koniec enumeracji: korekta/podgladanie cudzego szkicu po sekwencyjnym offer_id
  oraz cudzy request_id zwracaja TEN SAM wynik co 'nie istnieje' (brak wyroczni wlasnosci).
* [Bezp.] Audit-log pobran PDF (kto/kiedy pobral oferte + prob odmowy) — rozliczalnosc dostepu.
* [Bezp.] Naglowki pobierania: X-Content-Type-Options: nosniff, X-Frame-Options: DENY,
  Referrer-Policy: no-referrer, Cache-Control: private, no-store.
* [Bezp.] Dompdf: jawnie wylaczone isPhpEnabled i isJavascriptEnabled (obrona w glab PDF).
* [RODO] Integracja prywatnosci WordPressa: eksporter + anonimizacja (eraser) danych klienta
  po e-mailu (kasowanie PDF, redakcja snapshotu), sugerowana tresc polityki prywatnosci.
* [Bezp.] Zapis oferty: 0 zmienionych wierszy przy UPDATE = konflikt (nigdy cichy sukces);
  blokada per-lead zwalniana tylko gdy zdobyta; containment przy finalizacji PDF.
* [Bezp.] Ostrzezenie dla nginx (gdzie .htaccess nie dziala) z gotowym blokiem konfiguracji;
  guard CLI na harnessie; .distignore wykluczajacy pliki dev z paczki.
* Harness 110/110, PHPCS/WPCS 0/0. Zero luk krytycznych/wysokich; wszystkie srednie domkniete.

= 1.0.4 =
* Ostateczna runda debug (8 równoległych sub-audytów + przegląd krzyżowy) — naprawione:
* [Wysoki] Podwójny klik / równoległy zapis tej samej oferty = JEDNA oferta: identyfikator
  żądania stały na formularz + blokada przycisku na czas zapisu; wyścig po stronie serwera
  zwraca istniejącą ofertę zamiast błędu (mp_offer_created nie odpala się dwa razy).
* [Ważne] Bezpieczeństwo: usunięty stored XSS przez nazwę produktu w prefillu edycji; pobieranie
  PDF ma twardą kontrolę ścieżki (realpath w katalogu prywatnym) i nagłówki no-cache.
* [Ważne] VAT: produkt zwolniony (tax_status=none) daje 0% zamiast stawki krajowej; kod kraju
  poprawny formatem, lecz nieznany WooCommerce (np. "DR") jest ODRZUCANY, nie udaje "poza UE" 0%.
* [Ważne] Promocje z harmonogramem: wygasła/nieaktywna promocja nie zaniża już ceny (is_on_sale).
* [Ważne] Strefa czasu: rok w numerze oferty i data w PDF z zegara sklepu (nie UTC) — poprawny
  reset licznika o północy sylwestrowej.
* [Średni] Render PDF w try/catch (błąd generatora = kontrolowany błąd, nie HTTP 500); nieudany
  COMMIT nie ogłasza "oferty-widma"; tymczasowy PDF sprzątany przy krytycznym błędzie i nieudanym
  zapisie pliku.
* [Średni] Dane klienta zawierające "{{...}}" nie blokują już oferty (neutralizacja nawiasów);
  kwoty i pola dodatkowo escapowane w PDF. Historia wersji po ponownej numeracji zawiera właściwy numer.
* Szkic z leada: pola przycięte do limitów kolumn (kraj do 2 znaków) + blokada per-lead przeciw
  duplikatowi szkicu. Nieznany wariant cenowy odrzucany jawnie (zamiast cichego 0% rabatu).
* Harness 108/108, PHPCS/WPCS 0/0. Świadome kompromisy: patrz docs/TESTY.md.

= 1.0.3 =
* Ostateczny audyt (4 równoległe sub-audyty + przegląd krzyżowy) — naprawione:
* [Ważne] VAT liczony PER KLASĘ PODATKOWĄ — koszyk mieszający stawki (np. 23% + 8%)
  wcześniej naliczał jedną stawkę na całości (zawyżenie/zaniżenie podatku). Rabat
  z sumy dzielony proporcjonalnie na klasy; stawka zapisywana per pozycja.
* [Średni] Cena ujemna produktu odrzucana jawnym błędem (wcześniej dawała ujemne
  kwoty w ofercie); usunięta martwa „flaga ceny zero".
* [Średni] Retry po kolizji numeru oferty liczy kolejny numer w pamięci (odporny na
  snapshot REPEATABLE READ) + więcej podejść — równoległe tworzenie ofert nie kończy
  się już błędem u „przegranego".
* [Średni] Deinstalacja usuwa teraz też wygenerowane PDF-y ofert, katalog prywatny,
  sekret nazw plików i capability ze wszystkich ról (nie tylko admina).
* Twardsza granica AJAX (capability na wejściu), limit długości statusu VAT z leada,
  indeks bazodanowy pod sortowanie listy ofert (DB_VERSION 0.7.0).
* Harness 102/102, PHPCS/WPCS 0/0.

= 1.0.2 =
* Ręczny formularz oferty ma teraz pole „VAT UE potwierdzony" — oświadczenie
  handlowca (klient z UE z ważnym VAT UE, np. zweryfikowanym w VIES) włącza
  odwrotne obciążenie (reverse_charge, 0% VAT) także dla ofert zakładanych
  ręcznie, nie tylko ze szkicu z leada.
* [Poprawność] Status VAT klienta (`vat_status`) jest teraz UTRWALANY w BD-2
  (nowa kolumna `client_vat_status`, DB_VERSION 0.6.0). Wcześniej gubił się
  przy każdym odczycie ze snapshotu — korekta oferty UE oraz dokończenie
  szkicu leada z ważnym VAT UE cicho spadały do stawki krajowej. Teraz
  reverse_charge przetrwa korektę i round-trip przez bazę.
* Instrukcja instalacji (== Installation ==): udokumentowany prerekwizyt
  „kraj bazowy sklepu = kraj stawki krajowej (PL)".

= 1.0.1 =
* Testy na żywym WordPressie/WooCommerce (WordPress Playground) — naprawione
  5 problemów niewykrywalnych w testach jednostkowych ze stubami:
* [Krytyczny] Stawka VAT: pobierana przez WC_Tax::get_base_tax_rates()
  (deterministycznie z bazy sklepu) zamiast get_rates() (zależnego od sesji
  klienta) — wcześniej każda oferta padała z „brak stawki VAT".
* [Krytyczny] Domyślne szablony oferty (PL+EN) zakładane przy aktywacji —
  bez nich świeża instalacja nie mogła wygenerować żadnej oferty.
* Kolumna „Akcje" w liście ofert (Edytuj / Pobierz PDF) — naprawiona
  widoczność metody renderującej.
* Ekran edycji wczytuje teraz istniejące pozycje oferty.
* Usunięty komunikat deprecation na PHP 8.3.
* Wszystkie 12 scenariuszy odbioru — PASS na żywym WooCommerce (docs/TESTY.md).

= 1.0.0 =
* Pipeline 11 działów kompletny: kontrakt/uprawnienia, integracja WooCommerce
  (ceny, stawki VAT), rabaty, mechanizm VAT (krajowy/odwrotne obciążenie/poza
  zakresem), szablon i treść oferty, numeracja i wersjonowanie, render PDF
  (Dompdf), zapis transakcyjny, odpowiedź i przekazanie zdarzenia.
* Panel wp-admin (lista ofert, budowa oferty, wyszukiwanie produktów) i
  chroniony endpoint pobierania PDF (nonce + capability + właściciel).
* Automatyczny szkic oferty z leada Pluginu 1 (hook `mp_lead_created`).
* Pełny audyt bezpieczeństwa i jakości (14 subagentów + dwa niezależne
  re-audyty), wszystkie ustalenia Critical/High/Medium naprawione: IDOR w
  3 miejscach, blokada optymistyczna przed nadpisaniem współbieżnej zmiany,
  ochrona przed duplikacją pozycji przy korekcie, kontrola wyników
  zapisu/kasowania w bazie, bezpieczne nazwy plików PDF, siatka bezpieczeństwa
  na twarde fatale PHP.
* Harness: 98/98 scenariuszy PASS. PHPCS (WPCS): 0 błędów/ostrzeżeń.

= 0.1.0 =
* Szkielet wtyczki — branch utworzony, architektura w trakcie ustalania.
