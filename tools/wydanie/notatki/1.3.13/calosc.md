Wydanie z JEDNEJ naprawy, znalezionej przy odbiorze wydania poprzedniego — i to nie czytaniem kodu, tylko wejsciem na ekran prawdziwym logowaniem, na czystej instalacji postawionej z POBRANYCH paczek 1.3.12.

HANDLOWIEC I MANAGER NIE DOCIERALI DO SWOICH EKRANOW. Zadanie `GET /wp-admin/admin.php?page=mp-sales-workflow` zalogowanym handlowcem konczylo sie przekierowaniem `302` na `/my-account/`. Powod stal pietro wyzej niz nasz kod: WooCommerce filtrem `woocommerce_prevent_admin_access` wyrzuca z panelu kazdego, kto nie ma uprawnienia do edycji wpisow ani zarzadzania sklepem. Role sprzedazowe maja `read` i uprawnienia wlasne — i tak ma zostac, bo handlowiec nie ma powodu edytowac wpisow bloga. Skutek: na KAZDEJ instalacji zgodnej z wymaganiami produktu (wtyczka 2 wymaga WooCommerce) dwie z trzech rol nie mialy dostepu do jedynego ekranu, ktory wtyczka dla nich robi.

Naprawa jest waska: odpowiadamy WooCommerce „ten uzytkownik ma u nas robote w panelu" wylacznie posiadaczom NASZYCH uprawnien i wylacznie wtedy, gdy WooCommerce chce kogos wypchnac. Nikomu niczego nie dodajemy — `edit_posts` role nadal nie maja i wpisow nie zobacza. Sprawdzone kontr-asercjami: subskrybent zostaje wypchniety, uzytkownik bez uprawnienia dostaje `403` na ekranie zgloszen, a gdy WooCommerce nikogo nie wypycha, wtyczka milczy.

DLACZEGO ZADNE NARZEDZIE TEGO NIE ZLAPALO. Regresja wola funkcje, nie klika — `current_user_can()` odpowiadalo poprawnie, bo uprawnienia byly poprawne. Audyt czyta trzy nasze wtyczki i nie oglada cudzych filtrow. Bramka repo porownuje wersje i paczki. Zaden z tych przyrzadow nie zadaje pytania „czy czlowiek w tej roli dojdzie do tego ekranu", bo zeby je zadac, trzeba sie zalogowac i wejsc.

WTYCZKI 1 I 2 NIE MAJA W TYM WYDANIU ZADNEJ ZMIANY i zostaja na 1.3.12. To pierwszy raz, gdy regula „wtyczka bez zmian nie dostaje nowego numeru" naprawde sie wykonuje — do 1.3.12 istniala w kodzie i nie byla uzyta ani razu. Ich paczki sa w archiwum calosci ponizej.

ODBIOR 1.3.12: przejscie przez HTTP (formularz gosciem, lancuch trzech wtyczek, ekrany, role, poczta) 42/42 po naprawie. Dziesiec scenariuszy odbioru: 102 asercje, wszystkie PASS. Demo z opublikowanego blueprintu: 27/27. Zapis w `raporty/ODBIOR-1.3.12.md`.

Regresja: 99 plikow testowych, wszystkie PASS. PHPCS: kod wyjscia 0 — zmierzony po ostatniej zmianie.
