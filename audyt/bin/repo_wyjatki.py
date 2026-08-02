"""Swiadomie przyjete niezgodnosci wersji w tagach juz opublikowanych.

Osobny modul, zeby dalo sie go zaimportowac w tescie bez uruchamiania calego
audytu. Zasada jest jedna i wazniejsza niz sama lista: wpis zwalnia wylacznie
z TEJ niezgodnosci, ktora zostala zastana i opisana. Inny naglowek albo inny
`Stable tag:` pod tym samym tagiem to juz co innego — wtedy bramka ma sie
odezwac, bo znaczy to, ze ktos przesunal opublikowany tag.
"""
import json
import os

REJESTR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                       'rejestr', 'wersje-historyczne.json')


def wczytaj(sciezka=REJESTR):
    with open(sciezka, encoding='utf-8') as f:
        return json.load(f)['wpisy']


def przyjeta(tag, naglowek, stable, wpisy):
    """Czy ta konkretna niezgodnosc zostala swiadomie przyjeta."""
    for w in wpisy:
        if w['tag'] == tag:
            return w['naglowek'] == naglowek and w['stable'] == stable
    return False


def powod(tag, wpisy):
    for w in wpisy:
        if w['tag'] == tag:
            return w['powod']
    return ''
