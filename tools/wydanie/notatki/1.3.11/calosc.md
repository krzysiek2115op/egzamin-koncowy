Runda uruchomiona z zalozeniem, ze bledy NADAL sa, i z pytaniem, ktorego dotad nie zadawalismy wprost: gdzie nasze narzedzia z definicji nie patrza. Regresja sprawdza wylacznie nasze dawne pomylki, bramka audytu porownuje kod z rejestrem tych pomylek — zadne nie pyta „czy zrobilismy to, co zamowiono" i zadne nie oglada ARTEFAKTU: tresci PDF, tresci maila, wiersza w bazie, materialow dla klienta, demo jako strony. Trzy z jedenastu ustalen przyszly wlasnie stamtad.

Jedenascie napraw, kazda poprzedzona testem uruchomionym po to, zeby zobaczyc, jak pada.

SEGMENT KLIENTA NIE DOCIERAL DO PROCESU SPRZEDAZY. Wtyczka 1 przekazuje segment razem ze zgloszeniem, kolumna w bazie istniala od poczatku, ale dzial zapisujacy jej nie wypelnial. Naprawa samego zapisu nic by nie dala: dzial, ktory segment WYSWIETLA, biegnie PRZED dzialem, ktory go zapisuje — przy pierwszym zdarzeniu procesu czytal wiec wiersz, ktorego jeszcze nie ma. Dobor handlowca i tresc powiadomien pracowaly na pustym segmencie za kazdym razem, gdy proces powstawal. Naprawa objela oba konce.

MENU PROWADZILO DO STRONY, KTOREJ NIE MA. Metoda oddajaca adres strony z formularzem zwracala odnosnik dla KAZDEGO wpisu — takze w koszu, w szkicu i prywatnego. Korzysta z niej awaryjne dokladanie pozycji do nawigacji, wiec gosc widzial w menu wejscie do calego procesu i trafial na 404. Wtyczka wykrywala przy tym, ze strona zniknela, i zapisywala to w panelu; brakowalo jednego sprawdzenia w miejscu, ktore adres buduje.

DZIENNIK NIE MOWIL, CO SIE STALO. Wpis o zatrzymaniu pipeline'u skladal opis z numeru dzialu i MASZYNOWEGO kodu bledu. Komunikaty dla czlowieka szly do kolumny `meta_json`, o ktorej ten sam plik dwukrotnie stwierdza, ze lista wpisow w panelu jej nie pokazuje. Administrator ogladal dziennik pelen kodow, majac powod awarii zapisany obok, poza zasiegiem wzroku. Blizniacza metoda dla wyjatkow te naprawe miala — trzeci raz w tym projekcie naprawilismy jedna z dwoch rownoleglych sciezek.

CACHE SPRZED AKTUALIZACJI ODRZUCAL LEGALNE ZGLOSZENIA. Starsza wersja zapisywala do pamieci podrecznej VIES skalar 1/0, przy czym zero szlo tam takze dla „nie dalo sie ustalic". Odczyt po aktualizacji tlumaczyl to na twardy werdykt „numer niewazny", a krytyk robi na nim STOP. Przez dobe po wgraniu nowej wersji (tyle zyje wpis) legalne zgloszenia byly odrzucane — czyli dokladnie ten skutek, przed ktorym bronila nowa straz przy ZAPISIE. Zmiana ksztaltu danych w cache to migracja: wpis w starym ksztalcie nie rozstrzyga, pytamy rejestr jeszcze raz.

CICHE 0% VAT NA DOKUMENCIE KLIENTA. Dzial zapisujacy oferte wybaczal brak calej mapy stawek podatkowych KAZDEMU mechanizmowi, choc komentarz obok wymienial dwa, w ktorych zero wynika z prawa. Oferta krajowa, ktora doszlaby do tego dzialu bez mapy, dostalaby stawke z domyslnego zera i trafila na papier klienta jako 0% VAT bez zadnego sladu. Pusta mapa przechodzi teraz tylko przy odwrotnym obciazeniu i sprzedazy poza zakresem dyrektywy.

Kontr-asercja, ktora tego pilnowala, deklarowala w komentarzu odwrotne obciazenie, a URUCHAMIALA kontekst krajowy. Przechodzila, bo kod mechanizmu nie sprawdzal. Test chronil wiec dokladnie ten blad, przed ktorym mial bronic.

CZAS LICZONY STREFA, KTOREJ NIKT NIE WYBIERAL. Termin SLA i termin zadania powstawaly z daty GMT czytanej bez oznaczenia strefy, wiec interpretowala ja strefa domyslna PHP. WordPress ustawia UTC sam i dlatego na typowym serwerze wychodzilo dobrze — ale harmonogram TEJ SAMEJ wtyczki doklejal ' UTC' od poczatku, czyli jedna wtyczka miala dwie odpowiedzi na to samo pytanie. Jedna wtyczka wolajaca `date_default_timezone_set()` przesuwa SLA calego procesu, a w dobie zmiany czasu na letni o godzine wiecej.

KOMUNIKAT MOWIACY „I" PRZY WARUNKU „ALBO". Bramka zatwierdzenia sprawdza „brak numeru ALBO brak pliku PDF", a odmowe opisywala zdaniem „Oferta nie ma jeszcze numeru I pliku PDF". Oferta z nadanym numerem kierowala handlowca do numeracji zamiast do generowania dokumentu — a najdrozsza czesc kazdej awarii to szukanie po zlej stronie.

DANE POLICZONE I WYRZUCONE. Agent maszyny statusow liczy, czy status docelowy w ogole istnieje w slowniku, i wklada te informacje do wyniku. Krytyk jej nie czytal: literowka w nazwie statusu dostawala komunikat o nielegalnym PRZEJSCIU, choc naprawa jest w tresci zadania. Dwa rozne bledy dostaly dwa rozne zdania; kod odmowy bez zmian.

MARTWA METODA Z WLASNYM SLOWNICTWEM. Metoda przewidujaca los alarmu oddawala lancuch „wysylany", a etykiety rozpoznaja „wyslany". Galaz etykiety nie mogla wypasc nigdy — i nie wypadala, bo od wydania 1.3.9 metody nikt juz nie wolal. Refaktor osierocil ja i zostawil: martwy kod czekajacy, az ktos uzyje go w dobrej wierze. Usunieta, a trzy stany alarmu dostaly stale zamiast luznych lancuchow.

MATERIALY DLA KLIENTA Z NUMEREM SPRZED CZTERECH WYDAN. Stopki mowily v1.1.0, v1.2.1, v1.2.3, v1.0.3 i v1.0.0 przy wtyczkach na 1.3.10, bo numer byl wpisany w zrodlach z reki. Gorzej: wtyczka 1 nie miala W OGOLE zrodel swoich materialow — dziewiec plikow PDF i draw.io lezalo w paczce jako artefakty, ktorych nie da sie odtworzyc ani poprawic. Katalog zrodel odtworzony w calosci (trzy generatory schematow, trzy dokumenty, skrypt budujacy), a numer wersji jest teraz CZYTANY z naglowka wtyczki przy budowaniu — wpisany z reki starzeje sie po cichu, bo nic go nie sprawdza.

MOTYW POKAZOWY UCZYL ZLEGO NAWYKU. `<html lang="pl">` i `<meta charset="UTF-8">` wpisane wprost zamiast standardowych funkcji WordPressa: instalacja postawiona po angielsku oglaszala czytnikom ekranu i wyszukiwarkom polski. Trzy ikony spolecznosciowe byly odnosnikami prowadzacymi do samego krzyzyka — uzytkownik klawiatury dostawal trzy przystanki bez celu. Firma z pokazu jest fikcyjna i profili nie ma, wiec zostaly same ikony, oznaczone jako dekoracja.

PO TYCH JEDENASTU NAPRAWACH audyt gleboki poszedl JESZCZE RAZ, jako bramka zakonczenia — i przyniosl kolejne ustalenia. Dwie rundy triage'u zamknely go do konca.

DWIE SLEPOTY WLASNEGO NARZEDZIA. Para „kontrakty hakow" szukala emisji wylacznie po literale `do_action( 'nazwa'`, wiec hak wystawiany przez stala byl dla niej niewidzialny; para „rejestr kontra testy" czytala pole `test` jak sciezke, a ono jest zdaniem („a.php + b.php", „x.php, sekcja L") i uzywa dwoch konwencji katalogu. Czterdziesci jeden wpisow ze stu dwudziestu jeden zglaszalo brak straznika tam, gdzie straznik stoi. Po naprawie: piecdziesiat ustalen spadlo do jednego. Narzedzie, ktore produkuje falszywe alarmy, uczy ignorowania alarmow.

KWOTY NAGLOWKA OFERTY SZLY DO ZAPISU Z CICHYM ZEREM. Niekompletny kontekst konczyl sie oferta na 0 zl zamiast bledem. Straznik odroznia teraz brak klucza od legalnego zera i zatrzymuje zapis.

KOMUNIKAT O STRONIE NADPISYWANY PRZY WEJSCIU DO PANELU. Pelne zdanie z rada zapisane przy aktywacji zastepowala wersja bez wskazania miejsca — naprawa z poprzedniego wydania zyla do pierwszego wejscia administratora w panel.

DRUGA OFERTA DLA TEGO SAMEGO PROCESU GINELA PO CICHU. Handlowiec robi w wtyczce 2 poprawiona oferte i zatwierdza ja. Nowy identyfikator zdarzenia, wiec bramka idempotencji nie zatrzymuje. Proces jest juz w statusie „oferta wyslana", wiec galaz „przejscie w to samo miejsce" oddawala sukces z PUSTA lista skutkow: klient bez powiadomienia o nowej ofercie, zadania kontaktowe nie powstaja, a wtyczka 2 widzi HTTP 200 i uznaje, ze oferte wyslano. Rozroznienie, ktorego brakowalo: powtorka TEGO SAMEGO zdarzenia (od tego jest klucz idempotencji) to co innego niz NOWE zdarzenie prowadzace w ten sam status.

STATUS SPOZA SLOWNIKA POTWIERDZANY SUKCESEM. Ta sama galaz nie pytala, czy status w ogole istnieje — a wynik tego sprawdzenia byl policzony linijke wyzej. Proces zapisany przez starsza wersje maszyny albo poprawiony recznie w bazie dostawal „stan potwierdzony" zamiast odmowy, wiec nigdy nie zglaszal sie jako zepsuty.

KOMUNIKAT O POLU, KTOREGO NIKT NIE WYSLAL. Typ zdarzenia bez reguly w maszynie statusow odbijal sie zdaniem „Zmiana statusu bez statusu docelowego" i polem bledu `to_status`, choc wywolujacy o zadna zmiane statusu nie prosil.

STATUS SUMY KONTROLNEJ NIP TWIERDZIL, ZE CYFRA SIE NIE ZGADZA — takze dla numeru, ktorego nikt nie podal. Sumy w trzech przypadkach w ogole nie liczono. Komunikat dla czlowieka rozroznial te przypadki od dawna; pole statusu bylo dwuwartosciowe i mowilo swoje.

DWA KRYTERIA PRAWDZIWOSCI NA JEDNEJ ZMIENNEJ. Wynik weryfikacji VAT porownywano raz scisle, a dwie linie nizej luzno. Wartosc `1` z pamieci podrecznej dawala kolumne „wazny" obok statusu „niepotwierdzony" — i nikt takiego wiersza nie prostowal, bo weryfikator w tle bierze wylacznie wiersze nierozstrzygniete.

SUROWY IDENTYFIKATOR STATUSU W POLSKIM ZDANIU. Ostrzezenie o stronie z formularzem wstawialo `post_status` i odsylalo do „Strony → Wszystkie strony" dla wszystkiego poza koszem. Na instalacji z wlasnymi statusami wpisow rada prowadzila na liste, ktora tej strony nie pokazuje.

TRZY ZMIANY COFNIETE, BO ISTNIEJACE TESTY POKAZALY, ZE SA ZLE. Straznik IDOR mial pytac o tryb uruchomienia — pod WP-CLI stala `WP_CLI` jest zdefiniowana ZAWSZE, wiec obrona zniknelaby takze dla obcego uzytkownika zalogowanego. Etykieta miejsca awarii miala byc dokladniejsza dla znanego dzialu — kubelek wyciszania ma wtedy grubszy podzial, wiec dwa opisy trafilyby w jeden kubelek i drugi zniknalby bez sladu. Powody odrzucenia zapisano w kodzie i w testach: inaczej nastepna runda popelni ten sam blad, majac przed soba to samo ustalenie.

JEDEN FALSZYWY ALARM POTWIERDZONY POMIAREM. Ustalenie mowilo, ze nieudany zapis leada nadpisze cudzy wiersz dziennika. Sonda zmierzyla, ze nieudany `insert()` ZERUJE `insert_id`, wiec straznik wystarcza. Test i tak powstal — pilnuje tego niezmiennika, bo gdyby przyszla wersja WordPressa go zmienila, scenariusz z ustalenia stalby sie prawdziwy.

AUDYT PO TYCH NAPRAWACH — I ZNOWU CZTERY SREDNIE. Puszczony jako bramka zakonczenia, przyniosl kolejne ustalenia; dwa z nich dotyczyly kodu dopisanego GODZINE WCZESNIEJ. Pisanie kodu i pisanie bledow to ta sama czynnosc, wiec bramka musi chodzic po kazdej rundzie, nie po ostatniej.

WARIANT WYCOFANEGO PRODUKTU WCHODZIL DO OFERTY. Kontrola publikacji pytala o status samego wariantu, a WooCommerce przy wycofaniu produktu zmiennego z katalogu zmienia status TYLKO wpisu glownego — warianty zostaja osobnymi wpisami w stanie „opublikowany". Zmierzone sonda, nie zalozone: po przelaczeniu rodzica na szkic wariant nadal raportuje `publish`, a status rodzica siedzi obok, w `get_parent_data()`. Handlowiec wstawial wiec do oferty pozycje, ktorej nie ma w katalogu, i szla ona na dokument dla klienta.

OBRONA W GLAB WYLACZALA SIE DLA KAZDEGO ZADANIA BEZ SESJI. Kontrola wlasciciela oferty pomijala sie, gdy nie bylo zalogowanego uzytkownika — nie tylko dla zadan w tle, ale i dla zwyklego zadania HTTP od goscia. Czyli dokladnie w sytuacji, dla ktorej ta warstwa powstala. Regula „kto jest podmiotem obcym" zostala przy okazji WYJETA do osobnej metody, bo inaczej nie da sie jej sprawdzic: testy chodza pod WP-CLI, gdzie stala `WP_CLI` jest zdefiniowana zawsze, wiec przebieg z definicji wyglada tam na bezsesyjny.

KOMUNIKAT O STRONIE — NIEROZPOZNANY STATUS DOSTAWAL NAJGORSZA RADE. Naprawa z poprzedniej rundy obejmowala statusy zarejestrowane; status po wylaczonej wtyczce trafial nadal na liste, ktora go nie pokazuje, i to bez etykiety, bo etykiety nie ma skad wziac. Dwie polowy komunikatu bezuzyteczne naraz.

CACHE VIES PYTAL O OBECNOSC POLA, NIE O JEGO TRESC. Wpis bez rozstrzygniecia liczyl sie jako werdykt „numer niewazny". Uczciwie: zaden zapis w tym repozytorium nie tworzy dzis takiego wpisu — to zabezpieczenie, nie naprawa dzialajacej awarii. Zostaje, bo caly ten rodzaj bledu wzial sie z ksztaltu cache, ktory nie odrozniał „niewazny" od „nie wiadomo".

TRZY USTERKI DZIENNIKA. Jedna galaz opisu miejsca awarii nie przechodzila przez tlumaczenie, choc wynik idzie do tematu i tresci maila. Czas wyciszenia alarmu byl wpisany w zdanie jako „15 minut", a ustawialy go dwa inne miejsca tego samego pliku. Nieudany zapis losu alarmu nie zostawial sladu — wpis mowil „los nieustalony" i nic nie wskazywalo, ze to skutek awarii zapisu, a nie decyzji.

WYSCIG ZATWIERDZEN OPISANY JAKO AWARIA. „Stan spoza slownika zglos administratorowi" doklejalo sie bezwarunkowo, takze wtedy, gdy przyczyna byla najzwyklejsza: ktos zatwierdzil oferte pierwszy. Handlowiec dostawal polecenie zglaszania sytuacji normalnej, a prawdziwa informacja do niego nie docierala.

INWARIANT ZADEKLAROWANY I NIEDOTRZYMANY. Komentarz w maszynie statusow nazywa komplet pol wyniku inwariantem niezaleznym od galezi — a galaz dla zdarzen niezmieniajacych statusu oddawala tablice ubozsza o jedno pole. Ustalenie z audytu na kodzie dopisanym tego samego dnia.

DWA TESTY, KTORE KLAMALY O KODZIE. Oba klasy „test zalezny od czegos, co nie jest kodem". Test ekranu leadow renderowal JEDEN ekran i szukal w nim zasianych leadow — czyli zakladal, ze cala tabela miesci sie na jednej stronie; przy 25 leadach z wyzsza punktacja zasiany lead wypadal na strone druga i test zaczynal oskarzac kod o blad, ktorego nie ma, w bramce kryterium odbioru. Test zatwierdzania oferty budowal dwa numery z tego samego znacznika czasu dwoma roznymi wzorami; gdy szesciocyfrowa seria zaczyna sie od dziewiatki, oba daja TEN SAM numer, zapis odbija sie o klucz unikalnosci i zatwierdzenie odmawia. Okno trwa okolo 28 godzin i wraca co 11,6 dnia — zmierzone wprost: „Duplicate entry 'OF/2999/901437-1'".

FUNKCJA WORDPRESSA W KODZIE, KTORY CHODZI BEZ WORDPRESSA. Naprawa obrony w glab wprowadzila `wp_doing_cron()`, a przenosny harness procesu uruchamia ten pipeline na wlasnych zaslepkach. Caly harness — 110 niezmiennikow — konczyl sie bledem krytycznym zamiast werdyktem, a regresja pokazywala to jako „bez werdyktu", nie jako porazke.

TRZECI PRZEBIEG BRAMKI — I NAJCIEZSZE ZNALEZISKO CALEJ RUNDY.

ADRES, KTOREGO KLIENT NIGDY NIE PODAL. Zgloszenie normalizowalo e-mail funkcja WordPressa, a walidacja sprawdzala JUZ WERSJE PRZEPISANA. Ta funkcja nie czysci adresu — ona go ZMIENIA, wycinajac znaki spoza swojego zbioru, i oddaje wynik, ktory kontrola poprawnosci przyjmuje bez zastrzezen. Zmierzone wprost: „zażółć@firma.pl" staje sie „za@firma.pl", „Jan Kowalski@firma.pl" staje sie „JanKowalski@firma.pl". Caly proces konczyl sie wiec SUKCESEM, a w bazie ladowal inny adres niz wpisany: oferta szla na skrzynke, ktorej klient nigdy nie podal. Bez sladu w dzienniku, bez informacji dla klienta, bez informacji dla handlowca — po obu stronach wygladalo to jak powodzenie. Zadna wczesniejsza runda tego nie zlapala, bo nie bylo awarii do zlapania.

Przy okazji: czesc zgloszenia byla nieprawdziwa. Apostrof w adresie („o'brien@firma.pl") jest legalny i przechodzi bez zmian — test to utrwala, zeby nikt tego nie „naprawil".

POLA OPISOWE BEZ NORMALIZACJI. „Segment / branza" i „Przewidywany wolumen" mialy wylacznie limit dlugosci, mimo ze opis dzialu deklaruje normalizacje oficjalnymi funkcjami WordPressa. Do bazy szla wartosc surowa — ze znacznikami i zlamaniami linii — a stamtad do oferty i do PDF-a, gdzie znacznik przestaje byc tekstem.

PUSTY SLAD ZNACZYL „STRONA JEST". Slad po nieudanym utworzeniu strony powstawal z komunikatu bledu bez sprawdzenia, czy komunikat w ogole jest. Wtyczka blokujaca zapis bez podania tresci zostawiala wiec slad NIEODROZNIALNY od powodzenia: strony nie ma, panel milczy, administrator widzi „Wtyczka wlaczona".

JEDNO USTALENIE ZOSTAWIONE SWIADOMIE. Status „przypisany" da sie osiagnac zmiana statusu bez wskazania handlowca i wtedy termin SLA startuje dla nikogo. Naprawa przez odmowe nie wchodzi: to JEDYNE wyjscie ze statusu „nowy" w slowniku, wiec odmowa uwiezilaby procesy zalozone wtedy, gdy nie ma zadnego handlowca — czyli odtworzylaby blad naprawiony w 1.3.7. Istniejacy test juz raz obronil przed „naprawa" w te strone i ma na to pomiar. Sprawa jest w rejestrze jako OTW-2, z dwiema droznymi do wyboru przez klienta; zgadywanie kosztowaloby wiecej niz milczenie.

Regresja: 95 plikow testowych, wszystkie PASS, zero bez werdyktu. PHPCS: 160 plikow, kod wyjscia 0. Audyt gleboki: 37 par, bramka 26/26 i 11/11, pokrycie 100%.
