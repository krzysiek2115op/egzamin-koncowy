"""Ktore wtyczki dostaja wlasne wydanie w danej wersji.

Regula pochodzi z 1.3.9 i jest zapisana w bramce repozytorium
(`audyt/bin/repo_wydania.py`): wtyczka, ktora nie dostala w wydaniu ZADNEJ
zmiany, nie dostaje nowego numeru — podbicie wersji przy identycznym kodzie to
obietnica, ktorej kod nie dotrzymuje.

Do 1.3.11 regula nie byla nigdy uzyta: kazde wydanie ruszalo wszystkie trzy
wtyczki naraz. `buduj-zipy.py` mial ja u siebie i dzialal, ale `publikuj.py`
i `demo-assety.py` nadal zakladaly komplet — pierwsze wydanie jednej wtyczki
(1.3.12) zatrzymaloby sie na „brak paczki mp-offer-builder-1.3.12.zip", i to
DOPIERO po zbudowaniu paczek i wypchnieciu tagow. Regula mieszka teraz w jednym
miejscu, a wszystkie trzy skrypty ja czytaja.

Rozstrzyga TAG, nie naglowek wtyczki: tag `<wtyczka>/v<wersja>` to jedyna
deklaracja „ta wersja tej wtyczki jest wydawana", ktora istnieje przed
zbudowaniem czegokolwiek.
"""
import subprocess

WTYCZKI = ('mp-lead-intake', 'mp-offer-builder', 'mp-sales-workflow')


def tagi_lokalne(repo):
    """Zbior nazw tagow w repozytorium."""
    wynik = subprocess.run(['git', '-C', repo, 'tag'],
                           check=True, stdout=subprocess.PIPE).stdout.decode()
    return set(wynik.split())


def wlasne_wydanie(wersja, tagi):
    """Wtyczki, ktore maja wlasny tag dla TEJ wersji.

    :param str wersja: numer wydania, np. „1.3.12".
    :param set tagi:   nazwy tagow (zwykle z `tagi_lokalne`).
    :return tuple
    """
    return tuple(p for p in WTYCZKI if '%s/v%s' % (p, wersja) in tagi)


def bez_wlasnego(wersja, tagi):
    """Odwrotnosc `wlasne_wydanie` — wtyczki jadace tylko w paczce calosci."""
    swoje = wlasne_wydanie(wersja, tagi)
    return tuple(p for p in WTYCZKI if p not in swoje)
