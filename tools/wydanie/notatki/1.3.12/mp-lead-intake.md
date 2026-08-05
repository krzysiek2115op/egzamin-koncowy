Trzy naprawy, wszystkie tej samej rodziny: komunikat opisuje operacje, ktora nie zaszla. Zadna nie dotyka pieniedzy ani danych — dotykaja tego, co czyta czlowiek, gdy cos poszlo nie tak.

„NIP JEST WYMAGANY" DLA POLA, KTORE KLIENT WYPELNIL. Sprawdzenie ogladalo numer PO kanonizacji, a ta dla Polski zostawia same cyfry — wpis „---" albo „brak" znikal do pustego lancucha, nierozroznialny od pola, ktorego nikt nie dotknal. Nadawca widzial w formularzu swoj wpis i obok zdanie twierdzace, ze go nie ma; jedyne, co mogl zrobic, to wpisac to samo jeszcze raz. Rozroznienie bierzemy z wartosci surowej (`nip_surowy`): wpis bez ani jednej cyfry dostaje prosbe o przepisanie numeru z dokumentu firmy, a komunikat o braku zostaje dla pol faktycznie pustych.

KRYTYK NORMALIZACJI OPISYWAL PRZELICZENIE, KTOREGO NIE BYLO. `normalize_failed` i „Puste pole po normalizacji" padalo takze dla pol nigdy niewypelnionych — czyli tam, gdzie normalizacja nie miala na czym sie wykonac. Diagnoza kierowala do czyszczenia danych zamiast do brakujacej odpowiedzi w formularzu. Od braku pola jest `required_missing`, od pola scietego do pustego zostaje `normalize_failed`; test pilnuje, zeby te dwa kody pozostaly rozne.

KOMUNIKAT O NIEUDANYM ZALOZENIU STRONY MILKL W POLOWIE. Podawal przyczyne i konczyl sie, choc blizniaczy komunikat obok zawsze konczy sie instrukcja wstawienia formularza krotkim kodem. Administrator dostawal diagnoze bez wyjscia — w sytuacji, w ktorej wtyczka nie ma jak dzialac dalej sama.

Kontr-asercja z wczesniejszej rundy, ktora utrwalala stary kod odmowy, zostala POPRAWIONA, a nie usunieta: jej intencja bylo rozroznienie „puste od poczatku" od „sciete do pustego" i przy tej intencji zostala.

SPROSTOWANIE DO 1.3.11: „PHPCS: kod wyjscia 0" bylo nieprawda. PHPCS na tagu `v1.3.11` konczy sie kodem 2, a dwa z pieciu bledow sa w tej wtyczce — brak pustej linii przed blokiem komentarza w Dziale 2. Bledy sa stylistyczne i kod dziala, ale zdanie w raporcie nie bylo prawdziwe. Poprawione tutaj.

Regresja: 98 plikow testowych, wszystkie PASS. PHPCS: kod wyjscia 0 — zmierzony po ostatniej zmianie.
