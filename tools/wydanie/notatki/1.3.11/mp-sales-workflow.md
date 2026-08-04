SEGMENT KLIENTA NIE DOCIERAL DO PROCESU. Wtyczka 1 przekazuje segment razem ze zgloszeniem, kolumna `segment` istniala w schemacie od poczatku, ale dzial zapisujacy proces jej nie wypelnial — skladal wiersz z nazwy i adresu, a segment pomijal. Dobor handlowca i tresc powiadomien pracowaly wiec na pustej wartosci przy KAZDYM nowym procesie.

Naprawa samego zapisu nie wystarczyla. Dzial, ktory segment WYSWIETLA, biegnie w pipeline PRZED dzialem, ktory go zapisuje: przy pierwszym zdarzeniu procesu czytal wiersz, ktorego jeszcze nie ma, i widzial pustke niezaleznie od tego, co zapis zrobi chwile pozniej. Oba konce dostaly zrodlo zapasowe w kopercie zdarzenia.

CZAS LICZONY STREFA, KTOREJ NIKT NIE WYBIERAL. Termin SLA i termin zadania powstawaly z daty GMT czytanej bez oznaczenia strefy — a taki lancuch interpretuje strefa domyslna PHP, po czym wynik wraca przez funkcje GMT. WordPress ustawia UTC sam i dlatego na typowym serwerze wychodzilo dobrze; wystarczy jednak jedna wtyczka albo jeden hosting wolajacy `date_default_timezone_set()`, zeby SLA calego procesu zjechalo o przesuniecie strefy, a w dobie zmiany czasu na letni o godzine wiecej.

Wazniejsze od skutku jest to, ze harmonogram TEJ SAMEJ wtyczki liczyl to poprawnie od poczatku. Jedna wtyczka miala dwie odpowiedzi na to samo pytanie; teraz jedna.

ODMOWA ZMIANY STATUSU ROZROZNIA DWA BLEDY. Agent maszyny statusow liczy, czy status docelowy w ogole istnieje w slowniku, i wklada te informacje do wyniku. Krytyk jej nie czytal — kazda odmowe opisywal jako nielegalne PRZEJSCIE. Literowka w nazwie statusu („wygrny" zamiast „wygrany") kierowala wiec uwage na regule przejscia, podczas gdy naprawa jest w tresci zadania. Pole policzone i wyrzucane; teraz uzyte. Kod odmowy bez zmian, bo wywolujacy ma zareagowac tak samo.

STOPKI MATERIALOW Z NUMEREM SPRZED CZTERECH WYDAN. Mowily „v1.0.0" przy wtyczce na 1.3.10, bo numer byl wpisany w zrodlach z reki. Jest teraz czytany z naglowka `Version:` pliku glownego przy budowaniu.

Regresja: 89 plikow testowych, wszystkie PASS. PHPCS: kod wyjscia 0.
