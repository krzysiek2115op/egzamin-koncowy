Wydanie porzadkowe. Ta wtyczka nie dostala zadnej zmiany w DZIALANIU — poprawione zostalo wylacznie jedno uchybienie stylu kodu (WordPress Coding Standards), ktore weszlo przy naprawie slownika przejsc w 1.3.11 i zostalo wtedy przeoczone: brak pustej linii przed blokiem komentarza w Dziale 5.

SPROSTOWANIE DO 1.3.11: „PHPCS: kod wyjscia 0" bylo nieprawda. PHPCS na tagu `v1.3.11` konczy sie kodem 2 — piec bledow we wszystkich trzech wtyczkach, z czego jeden w tej. Sprawdzenie kosztowalo jedno polecenie, ktorego nikt nie uruchomil po ostatniej zmianie tamtego dnia.

DLACZEGO NUMER MIMO TO ROSNIE. Regula projektu mowi, ze wtyczka bez zmian nie dostaje nowego numeru — i to nadal obowiazuje. Tutaj zmiana jednak jest: pliki wysylane klientowi roznia sie od tych z paczek 1.3.11. Dwa rozne zestawy plikow pod jednym numerem wersji to gorszy problem niz wydanie o drobnym zakresie, bo nie da sie potem odpowiedziec na pytanie „ktore 1.3.11 masz zainstalowane".

Sprawa otwarta bez zmian: status `assigned` pozostaje osiagalny przez `status.change` bez wlasciciela (rejestr `OTW-2`). Zawezone w 1.3.11 dopisaniem przejscia `new -> lost`; otwarte zostaje pytanie o SLA startujacy dla nikogo i decyzja nalezy do klienta.

Aktualizacja z 1.3.11 nie wymaga zadnych dzialan poza podmiana katalogu wtyczki. Schemat bazy, kontrakty z pozostalymi wtyczkami i tresc powiadomien bez zmian.

Regresja calego projektu: 98 plikow testowych, wszystkie PASS. PHPCS: kod wyjscia 0 — zmierzony po ostatniej zmianie.
