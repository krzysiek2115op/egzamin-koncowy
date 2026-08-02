#!/usr/bin/env python3
"""Wyjatek na tag opublikowany zwalnia z JEDNEJ niezgodnosci, nie z kontroli.

Rzecz, ktora ten test pilnuje, jest wazniejsza niz sama lista wyjatkow: gdyby
wpis dzialal „po nazwie tagu", przesuniecie opublikowanego tagu na inne drzewo
przeszloby bez slowa — a to dokladnie ten wypadek, dla ktorego kontrola wersji
istnieje. Wpis pinuje zastane wartosci, wiec kazda ich zmiana budzi bramke.
"""
import json
import os
import sys

KAT = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(os.path.dirname(KAT), 'bin'))
import repo_wyjatki  # noqa: E402

pass_, fail = 0, 0


def sprawdz(warunek, opis):
    global pass_, fail
    if warunek:
        pass_ += 1
        print('  [PASS] %s' % opis)
    else:
        fail += 1
        print('  [FAIL] %s' % opis)


WPISY = [{'tag': 'wtyczka/v1.2.1', 'naglowek': '1.2.1', 'stable': '1.0.0',
          'powod': 'przyklad'}]

print('=== A. wpis zwalnia z zastanej niezgodnosci ===')
sprawdz(repo_wyjatki.przyjeta('wtyczka/v1.2.1', '1.2.1', '1.0.0', WPISY),
        'dokladnie ta niezgodnosc jest przyjeta')
sprawdz(repo_wyjatki.powod('wtyczka/v1.2.1', WPISY) == 'przyklad',
        'powod przyjecia jest dostepny do wydruku')

print('=== B. wpis NIE zwalnia z niczego innego ===')
sprawdz(not repo_wyjatki.przyjeta('wtyczka/v1.2.1', '1.2.1', '1.1.0', WPISY),
        'inny Stable tag pod tym samym tagiem to juz nie ten sam wypadek')
sprawdz(not repo_wyjatki.przyjeta('wtyczka/v1.2.1', '9.9.9', '1.0.0', WPISY),
        'inny naglowek pod tym samym tagiem budzi bramke')
sprawdz(not repo_wyjatki.przyjeta('wtyczka/v1.3.0', '1.3.0', '1.0.0', WPISY),
        'sasiedni tag z ta sama niezgodnoscia NIE jest przyjety')
sprawdz(not repo_wyjatki.przyjeta('wtyczka/v1.2.1', '1.2.1', '1.0.0', []),
        'pusty rejestr nie zwalnia z niczego')

print('=== C. rejestr w repozytorium jest kompletny ===')
wpisy = repo_wyjatki.wczytaj()
sprawdz(len(wpisy) >= 1, 'rejestr ma co najmniej jeden wpis')
sprawdz(all(w.get('powod', '').strip() for w in wpisy),
        'kazdy wpis ma niepusty powod — wyjatek bez uzasadnienia to nie wyjatek')
sprawdz(all({'tag', 'naglowek', 'stable', 'powod'} <= set(w) for w in wpisy),
        'kazdy wpis pinuje tag, naglowek i Stable tag')
sprawdz(len({w['tag'] for w in wpisy}) == len(wpisy),
        'zaden tag nie ma dwoch wpisow — inaczej pierwszy przeslanialby drugi')

print('=== D. rejestr jest poprawnym JSON-em z opisem ===')
with open(repo_wyjatki.REJESTR, encoding='utf-8') as f:
    surowy = json.load(f)
sprawdz(surowy.get('opis', '').strip() != '',
        'rejestr tlumaczy, po co istnieje')

print()
print('----- PASS: %d / FAIL: %d -----' % (pass_, fail))
print('VERDICT_ALL_PASS' if fail == 0 else 'VERDICT_FAIL')
sys.exit(1 if fail else 0)
