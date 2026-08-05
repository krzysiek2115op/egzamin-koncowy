Wydanie porzadkowe. Ta wtyczka nie dostala zadnej zmiany w DZIALANIU — poprawione zostaly wylacznie dwa uchybienia stylu kodu (WordPress Coding Standards), ktore weszly przy naprawach z 1.3.11 i zostaly wtedy przeoczone: brak pustej linii przed blokiem komentarza w Dziale 6 i tablica z kluczami zapisana w jednej linii.

SPROSTOWANIE DO 1.3.11: „PHPCS: kod wyjscia 0" bylo nieprawda. PHPCS na tagu `v1.3.11` konczy sie kodem 2 — piec bledow we wszystkich trzech wtyczkach, z czego dwa w tej. Sprawdzenie kosztowalo jedno polecenie, ktorego nikt nie uruchomil po ostatniej zmianie tamtego dnia.

DLACZEGO NUMER MIMO TO ROSNIE. Regula projektu mowi, ze wtyczka bez zmian nie dostaje nowego numeru — i to nadal obowiazuje. Tutaj zmiana jednak jest: pliki wysylane klientowi roznia sie od tych z paczek 1.3.11. Dwa rozne zestawy plikow pod jednym numerem wersji to gorszy problem niz wydanie o drobnym zakresie, bo nie da sie potem odpowiedziec na pytanie „ktore 1.3.11 masz zainstalowane".

Aktualizacja z 1.3.11 nie wymaga zadnych dzialan poza podmiana katalogu wtyczki. Schemat bazy, kontrakty z pozostalymi wtyczkami i wyglad dokumentow bez zmian.

Regresja calego projektu: 98 plikow testowych, wszystkie PASS. PHPCS: kod wyjscia 0 — zmierzony po ostatniej zmianie.
