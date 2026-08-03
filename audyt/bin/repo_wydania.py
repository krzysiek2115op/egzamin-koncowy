"""Kiedy wolno wydac projekt BEZ wydania jednej z wtyczek.

Do 1.3.9 kontrola zalacznikow zakladala, ze wszystkie trzy wtyczki wydaje sie
zawsze razem, i kazdy brak traktowala jako blad. Zalozenie bylo wygodne, ale
falszywe: wtyczka, ktora nie dostala w danym wydaniu ZADNEJ zmiany, nie powinna
dostawac nowego numeru — podbicie wersji przy identycznym kodzie to obietnica,
ktorej kod nie dotrzymuje.

Zawezenie, a nie zniesienie. Brak wydania wolno przyjac wylacznie wtedy, gdy
wtyczka deklaruje INNA wersje niz audytowana i TAMTA wersja ma swoje wydanie —
czyli kazdy kod z paczki calosci da sie doprowadzic do opublikowanego wydania.
Gdy wtyczka deklaruje audytowana wersje, brak jej wydania pozostaje bledem: to
juz jest wersja obiecana i nieopublikowana.
"""


def wolno_pominac_wydanie(wersja_audytu, wersja_wtyczki, ma_wydanie_wlasnej):
    """Czy brak wydania wtyczki dla audytowanej wersji jest dopuszczalny.

    :param str|None wersja_audytu:     Wersja, ktora wlasnie wydajemy.
    :param str|None wersja_wtyczki:    Wersja zadeklarowana w naglowku wtyczki.
    :param bool     ma_wydanie_wlasnej: Czy wersja wtyczki ma swoje wydanie.
    :return bool
    """
    if not wersja_audytu or not wersja_wtyczki:
        return False

    if wersja_wtyczki == wersja_audytu:
        return False

    return bool(ma_wydanie_wlasnej)
