CICHE 0% VAT NA DOKUMENCIE KLIENTA. Dzial zapisujacy oferte bierze stawke podatku dla kazdej pozycji z mapy przygotowanej przez dzial podatkowy, a gdy mapy nie ma — z jednej stawki zbiorczej oferty. Komentarz obok tej galezi mowil wprost, ze brak calej mapy to udokumentowany przypadek dwoch mechanizmow: odwrotnego obciazenia i sprzedazy poza zakresem dyrektywy, gdzie zero wynika z prawa. Warunek jednak MECHANIZMU NIE SPRAWDZAL. Oferta krajowa, ktora doszlaby tam bez mapy — bo dzial podatkowy odmowil, bo kontekst przyszedl niekompletny — dostawala stawke z domyslnego zera i trafialaby na papier klienta jako 0% VAT. Jedyna informacja o tym bylby brak informacji.

Pusta mapa przechodzi teraz wylacznie przy tych dwoch mechanizmach. Przy sprzedazy opodatkowanej to blad pozycji — tak samo jak mapa istniejaca, ale bez tej jednej pozycji, bo to ten sam rodzaj szkody.

Kontr-asercja, ktora tego pilnowala, deklarowala w komentarzu odwrotne obciazenie, a URUCHAMIALA kontekst krajowy. Przechodzila, bo kod mechanizmu nie sprawdzal — czyli test chronil dokladnie ten blad, przed ktorym mial bronic. Zawezona do przypadku, ktory zawsze deklarowala, plus nowa asercja na przypadek krajowy.

ODMOWA MOWILA O BRAKU, KTOREGO NIE BYLO. Bramka zatwierdzenia sprawdza „brak numeru ALBO brak pliku PDF", a odmowe opisywala jednym zdaniem: „Oferta nie ma jeszcze numeru I pliku PDF". Przy ofercie, ktora numer MA, bylo to po prostu nieprawdziwe — handlowiec czytal o brakujacej numeracji i szukal usterki tam, gdzie jej nie ma, zamiast wygenerowac dokument. Trzy przypadki maja teraz trzy zdania, a komunikat wyswietlany po przekierowaniu (gdzie zostaje sam kod bledu) mowi „albo" zamiast zgadywac.

STOPKI MATERIALOW Z NUMEREM SPRZED CZTERECH WYDAN. Mowily „v1.0.3" przy wtyczce na 1.3.10, bo numer byl wpisany w zrodlach z reki. Jest teraz czytany z naglowka `Version:` pliku glownego przy budowaniu — materialy zbudowane po podbiciu wersji same mowia prawde. W legendzie schematu bazy oznaczenie kolumn dodanych w audycie przestalo wygladac jak stempel wersji dokumentu.

Regresja: 89 plikow testowych, wszystkie PASS. PHPCS: kod wyjscia 0.
