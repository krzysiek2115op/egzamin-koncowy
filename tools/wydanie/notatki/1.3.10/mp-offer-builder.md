PACZKA POSLUSZNA WLASNEJ DEKLARACJI. Plik `.distignore` tej wtyczki od poczatku wymienial `tests/`, `docs/`, `blueprint/`, `materialy-src/`, `*.md` i `composer.*` jako rzeczy, ktore nie maja jechac do klienta. Nikt go nie czytal, bo paczki skladalismy recznie, a nie `wp dist-archive` — 74 pliki deweloperskie jechaly wiec na serwer produkcyjny mimo pisemnego zakazu lezacego obok nich. Budowanie czyta teraz te deklaracje zamiast wlasnej listy, a wtyczka bez `.distignore` przerywa prace: milczenie nie moze znaczyc „wyslij wszystko”.

Wyciecie sprawdzone tam, gdzie boli — generowaniem PDF-a z odchudzonej paczki. Biblioteka dompdf nie potrzebowala niczego, co znikneło.

SZABLON TLUMACZEN, KTOREGO NIE BYLO. Wtyczka wolala `load_plugin_textdomain( ..., '/languages' )`, a katalogu `languages/` nie miala wcale — tak jak nie miala pliku `.pot` ani naglowka `Domain Path`. 110 ciagow czekalo na tlumacza, ktory nie mial z czym usiasc.

`Tested up to` mowilo 6.8, podczas gdy regresja chodzi na WordPressie 7.0. Wymaganie PHP 8.1 — wyzsze niz w pozostalych dwoch wtyczkach — trafilo wreszcie do tabeli wymagan w README, bo na serwerze z PHP 8.0 klient dostalby proces bez srodkowego kroku, czyli bez ofert.
