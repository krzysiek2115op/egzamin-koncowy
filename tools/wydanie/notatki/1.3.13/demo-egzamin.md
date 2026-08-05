To NIE jest wydanie produktu. Wydania produktu maja tagi `v1.3.13` oraz `mp-sales-workflow/v1.3.13`. Wtyczki 1 i 2 nie mialy w tym wydaniu zmian i zostaja na `mp-lead-intake/v1.3.12` oraz `mp-offer-builder/v1.3.12`.

Tutaj leza wylacznie pliki potrzebne stronie pokazowej uruchamianej w WordPress Playground: trzy wtyczki jako ZIP-y gotowe do instalacji (korzeniem archiwum jest katalog wtyczki — takiego ksztaltu wymaga Playground) oraz motyw Kredyt Kompas.

Zawartosc ZIP-ow to DOKLADNIE te same bajty, ktore znajduja sie w paczkach wydania 1.3.13. Dla wtyczek 1 i 2, ktore nie maja wlasnej paczki w tym wydaniu, zrodlem jest archiwum calosci — czyli ten sam plik, ktory klient z niego rozpakuje.

## Uruchomienie

https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/krzysiek2115op/egzamin-koncowy/main/tools/strona-pokazowa/blueprint.json

Instalacja w przegladarce trwa **2-4 minuty** (WordPress, WooCommerce i trzy wtyczki kompilowane do WebAssembly; sama wtyczka 2 z generatorem PDF to ~5 MB). Ekran „Preparing WordPress" z paskiem postepu to normalny stan, nie zawieszenie.

Playground loguje automatycznie jako administrator i otwiera ekran procesow sprzedazowych.

## Konta do sprawdzenia rol

Zlecenie wymaga trzech dzialajacych rol, a widok kazdej z nich jest inny. Zeby wejsc na ktores z kont ponizej, wyloguj sie z konta administratora (menu w prawym gornym rogu panelu).

| Login | Rola | Kim jest | Haslo |
|---|---|---|---|
| `admin` | Administrator | widzi wszystko | (zalogowany automatycznie) |
| `handlowiec` | Handlowiec | Anna Kowalska, rynek PL — to do niej trafil proces demonstracyjny | `demo-egzamin-2026` |
| `handlowiec_de` | Handlowiec | Markus Weber, rynek DE — celowo NIE dostal polskiego procesu | `demo-egzamin-2026` |
| `manager` | Manager sprzedazy | Marian Nowak, widok zespolu | `demo-egzamin-2026` |

Do tego wydania konta te dostawaly haslo LOSOWE i nikt z zewnatrz nie mogl sie na nie zalogowac — ogladajacy widzial wylacznie panel administratora. Akurat dostep rol do wlasnych ekranow byl miejscem bledu naprawionego w 1.3.13, wiec demo nie pozwalalo sprawdzic dokladnie tego, co najbardziej warto sprawdzic.

Haslo jest jawne swiadomie: to instalacja pokazowa dzialajaca w przegladarce ogladajacego i kasowana po zamknieciu karty — nie ma tam zadnych cudzych danych. Na wdrozeniu produkcyjnym takie konta nie powstaja, zaklada je administrator recznie (`docs/WDROZENIE.md`).

## Czego demo z natury nie pokaze

- **Poczty** — Playground nie ma serwera SMTP. Kod zleca wysylke i zapisuje ja w dzienniku, ale zadna wiadomosc nie wychodzi.
- **Follow-upow d+3 / d+7** — wymagaja uplywu czasu i systemowego crona.
- **Podpisanych linkow do oferty** — potrzebuja stalej `MP_SW_LINK_KEY` w `wp-config.php`, ktorej instalacja pokazowa nie ma.

Te trzy rzeczy sa sprawdzone testami na zywym WordPressie; zapis w `raporty/ODBIOR-1.3.12.md` w repozytorium.
