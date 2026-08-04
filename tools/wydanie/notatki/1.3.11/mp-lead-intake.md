MENU PROWADZILO DO STRONY, KTOREJ NIE MA. Metoda oddajaca adres strony z formularzem zwracala odnosnik dla KAZDEGO wpisu — takze w koszu, w szkicu i prywatnego. Korzysta z niej awaryjne dokladanie pozycji do nawigacji, wiec gosc widzial w menu wejscie do calego procesu i trafial na 404. Wtyczka wykrywala przy tym, ze strona zniknela, i zapisywala to w panelu; brakowalo jednego sprawdzenia w miejscu, ktore adres buduje.

DZIENNIK NIE MOWIL, CO SIE STALO. Wpis o zatrzymaniu pipeline'u skladal opis z numeru dzialu i MASZYNOWEGO kodu bledu („Blad w dziale 3 (vat), kod: vat_invalid"). Komunikaty dla czlowieka szly do kolumny `meta_json`, o ktorej ten sam plik dwukrotnie stwierdza, ze lista wpisow w panelu jej nie pokazuje. Administrator ogladal dziennik pelen kodow, majac powod awarii zapisany obok, poza zasiegiem wzroku. Blizniacza metoda dla wyjatkow te naprawe miala.

CACHE SPRZED AKTUALIZACJI ODRZUCAL LEGALNE ZGLOSZENIA. Starsza wersja zapisywala do pamieci podrecznej VIES skalar 1/0, przy czym zero szlo tam takze dla odpowiedzi „nie dalo sie ustalic". Odczyt po aktualizacji tlumaczyl to na twardy werdykt „numer niewazny", a krytyk robi na nim STOP. Przez dobe po wgraniu nowej wersji — tyle zyje wpis — legalne zgloszenia byly odrzucane jako `vat_invalid`, czyli dokladnie tak, jak przed naprawa, ktora mialy chronic. Wpis w starym ksztalcie NIE ROZSTRZYGA: pipeline pyta rejestr jeszcze raz.

MARTWA METODA Z WLASNYM SLOWNICTWEM. Metoda przewidujaca los alarmu oddawala lancuch „wysylany", a etykiety rozpoznaja „wyslany". Galaz etykiety nie mogla wypasc nigdy — i nie wypadala, bo od 1.3.9 metody nikt juz nie wolal: los alarmu zapisujemy PO probie dostarczenia, wiec prognoza nie miala czego opisywac. Refaktor ja osierocil i zostawil. Usunieta, a trzy stany alarmu dostaly stale: literowka w stalej jest bledem PHP, w lancuchu — cicha etykieta „los nieustalony".

KROTKI KOD FORMULARZA WPISANY Z REKI. Stala z nazwa krotkiego kodu istnieje i polowa pliku obslugujacego strone z niej korzysta. Druga polowa wpisywala nazwe wprost: w tresci zakladanej strony i w radzie dla administratora, ktoremu strona zniknela. Zmiana stalej rozjechalaby te miejsca z tym, co wtyczka faktycznie rejestruje.

MATERIALY BEZ ZRODEL. Dziewiec plikow PDF i draw.io lezalo w paczce dla klienta jako artefakty, ktorych nie da sie odtworzyc — wtyczka nie miala katalogu `materialy-src`, ktory wtyczki 2 i 3 mialy od poczatku. Stad stopka „MP Lead Intake v1.2.3" przy wtyczce na 1.3.10: nie bylo czego poprawic. Zrodla odtworzone w calosci, a numer wersji jest czytany z naglowka wtyczki przy budowaniu.

Ponadto: dokumentacja Dzialu 4 pokazuje wprost, gdzie w kodzie siedzi „rynek" ze zlecenia — `country` (kod ISO, bo od niego zalezy naliczony VAT) plus `segment` (tekst, bo sluzy do skierowania sprawy do wlasciwego czlowieka).

Regresja: 89 plikow testowych, wszystkie PASS. PHPCS: kod wyjscia 0.
