#!/usr/bin/env python3
"""Brak wydania wtyczki wolno przyjac TYLKO wtedy, gdy nie miala zmian.

Rzecz, ktorej ten test pilnuje, jest wazniejsza niz sama regula: zawezenie ma
przepuszczac wylacznie wtyczke, ktora zostala na starszej, OPUBLIKOWANEJ wersji.
Gdyby przepuszczalo cokolwiek wiecej, kontrola zalacznikow przestalaby wykrywac
przypadek najgrozniejszy — wersje obiecana w naglowku i nigdy niewydana.
"""
import os
import sys

KAT = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(os.path.dirname(KAT), 'bin'))
import repo_wydania  # noqa: E402

pass_, fail = 0, 0


def sprawdz(warunek, opis):
    global pass_, fail
    if warunek:
        pass_ += 1
        print('  [PASS] %s' % opis)
    else:
        fail += 1
        print('  [FAIL] %s' % opis)


print('=== A. przypadek, dla ktorego zawezenie powstalo ===')
sprawdz(repo_wydania.wolno_pominac_wydanie('1.3.9', '1.3.8', True),
        'wtyczka bez zmian zostaje na 1.3.8, a tamta wersja ma wydanie')

print('=== B. czego zawezenie NIE przepuszcza ===')
sprawdz(not repo_wydania.wolno_pominac_wydanie('1.3.9', '1.3.9', False),
        'wersja obiecana w naglowku i nieopublikowana to dalej blad')
sprawdz(not repo_wydania.wolno_pominac_wydanie('1.3.9', '1.3.9', True),
        'takze wtedy, gdy istnieje wydanie o tym samym numerze gdzie indziej')
sprawdz(not repo_wydania.wolno_pominac_wydanie('1.3.9', '1.3.8', False),
        'starsza wersja BEZ wydania nie usprawiedliwia niczego')
sprawdz(not repo_wydania.wolno_pominac_wydanie('1.3.9', None, True),
        'nieznana wersja wtyczki to nie jest zgoda')
sprawdz(not repo_wydania.wolno_pominac_wydanie(None, '1.3.8', True),
        'nieznana wersja audytu tez nie')

print()
print('----- PASS: %d / FAIL: %d -----' % (pass_, fail))
print('VERDICT_ALL_PASS' if fail == 0 else 'VERDICT_FAIL')
sys.exit(1 if fail else 0)
