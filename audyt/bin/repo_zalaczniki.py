"""Co musi wisiec przy wydaniu pojedynczej wtyczki.

Do 1.3.9 regula brzmiala „DOKLADNIE dwa zalaczniki": paczka wtyczki i paczka
calosci projektu. Druga byla tam po to, zeby ktos, kto dostal link wylacznie do
jednej wtyczki, mial skad wziac pozostale dwie — trzy wtyczki dzialaja tylko
razem i w kolejnosci 1 -> 2 -> 3.

Odpowiedzia na pytanie „gdzie jest komplet" jest jednak ODNOSNIK, a nie KOPIA.
Kopia kosztowala ~18 MB dubla przy kazdym wydaniu i — wazniejsze — mylila:
na stronie wydania wtyczki 2 ta sama paczka `mp-offer-builder.zip` lezala dwa
razy, raz samodzielnie i raz w srodku paczki calosci, a z samej strony nie dalo
sie zgadnac, ktora wziac. Zglosil to uzytkownik, patrzac na wlasne wydanie.

Regula nie zostaje wiec ZNIESIONA, tylko przeniesiona na to, co naprawde ma
znaczenie: wydanie o nazwie `<wtyczka>/vN` musi zawierac paczke TEJ wtyczki
w wersji N. Paczka calosci jest dozwolona (maja ja wszystkie wydania sprzed
1.3.9 i nie ma powodu ich przepisywac), ale nie jest wymagana. Cokolwiek innego
pozostaje bledem — zalacznik, ktorego nikt nie umie nazwac, to zalacznik,
o ktorym nikt nie wie, co zawiera.
"""


def ocen_zalaczniki(slug, wersja, nazwy):
    """Bledy w zestawie zalacznikow wydania wtyczki.

    :param str      slug:   Katalog/nazwa wtyczki, np. 'mp-offer-builder'.
    :param str      wersja: Wersja wydania, np. '1.3.9'.
    :param iterable nazwy:  Nazwy plikow zalaczonych do wydania.
    :return list: Lista opisow bledow; pusta oznacza zestaw poprawny.
    """
    nazwy = list(nazwy or [])

    wlasna = "%s-%s.zip" % (slug, wersja)
    calosc = "egzamin-koncowy-%s.zip" % wersja

    bledy = []

    if wlasna not in nazwy:
        bledy.append(
            "%s: wydanie nie zawiera wlasnej paczki %s (jest: %s)"
            % (slug, wlasna, ", ".join(sorted(nazwy)) or "nic")
        )

    obce = sorted(n for n in nazwy if n not in (wlasna, calosc))
    if obce:
        bledy.append(
            "%s: nierozpoznane zalaczniki: %s" % (slug, ", ".join(obce))
        )

    return bledy
