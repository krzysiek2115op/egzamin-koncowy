#!/usr/bin/env python3
"""Wydanie wtyczki musi zawierac paczke TEJ wtyczki — paczka calosci jest dodatkiem.

Regula zastapila dawne „DOKLADNIE dwa zalaczniki". Wazniejsze od samej zmiany
jest to, czego nowa regula nadal NIE przepuszcza: wydania bez wlasnej paczki.
Liczenie zalacznikow tego nie pilnowalo — dwa zalaczniki o dowolnych nazwach
przechodzily, wiec wydanie z sama paczka calosci wygladalo na zdrowe, a nie
zawieralo tego, co ma w nazwie.
"""
import os
import sys

KAT = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(os.path.dirname(KAT), 'bin'))
import repo_zalaczniki  # noqa: E402

pass_, fail = 0, 0


def sprawdz(warunek, opis):
    global pass_, fail
    if warunek:
        pass_ += 1
        print('  [PASS] %s' % opis)
    else:
        fail += 1
        print('  [FAIL] %s' % opis)


def ocen(nazwy):
    return repo_zalaczniki.ocen_zalaczniki('mp-offer-builder', '1.3.9', nazwy)


print('=== A. zestawy poprawne ===')
sprawdz(ocen(['mp-offer-builder-1.3.9.zip']) == [],
        'sama paczka wtyczki wystarczy — po komplet prowadzi odnosnik, nie kopia')
sprawdz(ocen(['mp-offer-builder-1.3.9.zip', 'egzamin-koncowy-1.3.9.zip']) == [],
        'paczka calosci dalej dozwolona — maja ja wszystkie wydania sprzed 1.3.9')

print('=== B. czego regula NIE przepuszcza ===')
sprawdz(len(ocen(['egzamin-koncowy-1.3.9.zip'])) == 1,
        'sama paczka calosci to blad: wydanie nie zawiera tego, co ma w nazwie')
sprawdz(len(ocen([])) == 1,
        'wydanie bez zalacznikow to blad')
sprawdz(len(ocen(None)) == 1,
        'brak listy zalacznikow czytamy jak pusta, a nie jak zgode')
sprawdz(len(ocen(['mp-offer-builder-1.3.8.zip'])) == 2,
        'paczka w INNEJ wersji nie jest paczka tego wydania (brak wlasnej + zalacznik obcy)')
sprawdz(len(ocen(['mp-lead-intake-1.3.9.zip'])) == 2,
        'paczka INNEJ wtyczki to dwa bledy: brak wlasnej i zalacznik nie z tego wydania')
sprawdz(len(ocen(['mp-offer-builder-1.3.9.zip', 'notatki.txt'])) == 1,
        'zalacznik, ktorego nikt nie umie nazwac, zostaje bledem')

print('=== C. regula liczy nazwy, nie sztuki ===')
sprawdz(ocen(['mp-offer-builder-1.3.9.zip']) == []
        and len(ocen(['egzamin-koncowy-1.3.9.zip', 'notatki.txt'])) == 2,
        'jeden wlasciwy zalacznik przechodzi, dwa niewlasciwe nie')

print()
print('----- PASS: %d / FAIL: %d -----' % (pass_, fail))
print('VERDICT_ALL_PASS' if fail == 0 else 'VERDICT_FAIL')
sys.exit(1 if fail else 0)
