#!/usr/bin/env python3
"""Wydanie, w ktorym zmienila sie JEDNA wtyczka, przechodzi cala droge.

    python3 tools/wydanie/tests/wydanie-jednej-wtyczki.py

Regula „wtyczka bez zmian nie dostaje nowego numeru" powstala w 1.3.9 i przez
trzy wydania nie byla uzyta ani razu — kazde ruszalo wszystkie trzy wtyczki.
`buduj-zipy.py` mial ja u siebie i pomijal paczke, `audyt/bin/repo_wydania.py`
przyjmowal brak wydania, ale `publikuj.py` i `demo-assety.py` nadal chodzily po
sztywnej liscie czterech wydan i trzech paczek wtyczek.

Koszt tej luki byl odroczony: skrypty zatrzymalyby sie dopiero PO zbudowaniu
paczek i PO wypchnieciu tagow, czyli w polowie publikacji, z tagiem `v1.3.12`
juz na GitHubie i bez ani jednego wydania. Test sprawdza wlasnie ten przypadek —
1.3.12, gdzie zmienila sie tylko wtyczka 1.

Bez sieci i bez gita: tagi podajemy jako zbior, paczki budujemy w katalogu
tymczasowym.
"""
import importlib.util
import io
import os
import sys
import tempfile
import zipfile

KAT = os.path.dirname(os.path.abspath(__file__))
WYDANIE = os.path.dirname(KAT)
sys.path.insert(0, WYDANIE)

import wydania  # noqa: E402  (import po ustawieniu sciezki)
import publikuj  # noqa: E402


def zaladuj(nazwa, plik):
    """Modul o nazwie z myslnikiem — zwykly `import` go nie widzi."""
    spec = importlib.util.spec_from_file_location(nazwa, os.path.join(WYDANIE, plik))
    modul = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(modul)
    return modul


demo = zaladuj('demo_assety', 'demo-assety.py')

PASS = [0]
FAIL = [0]


def ok(warunek, opis, info=''):
    if warunek:
        PASS[0] += 1
        print('  [PASS] ' + opis)
        return True

    FAIL[0] += 1
    print('  [FAIL] ' + opis + (' -- ' + info if info else ''))
    return False


# Stan repozytorium w chwili wydania 1.3.12: projekt ma swoj tag, wtyczka 1 tez,
# dwie pozostale zostaja na tagach z 1.3.11.
TAGI = {
    'v1.3.11', 'v1.3.12',
    'mp-lead-intake/v1.3.11', 'mp-lead-intake/v1.3.12',
    'mp-offer-builder/v1.3.11',
    'mp-sales-workflow/v1.3.11',
}

print('=== A. ktore wtyczki maja wlasne wydanie ===')

swoje = wydania.wlasne_wydanie('1.3.12', TAGI)

ok(swoje == ('mp-lead-intake',),
   'A1: w 1.3.12 wlasne wydanie ma tylko wtyczka 1', 'swoje=%s' % (swoje,))
ok(wydania.bez_wlasnego('1.3.12', TAGI) == ('mp-offer-builder', 'mp-sales-workflow'),
   'A2: dwie pozostale jada tylko w paczce calosci')
# KONTR-ASERCJA: pominiecie ma byc waskie. Te same tagi, poprzednia wersja —
# tam komplet byl pelny i ma taki zostac, bo pomijanie zalezy od TAGU, a nie od
# tego, ktora wersja jest najnowsza.
ok(wydania.wlasne_wydanie('1.3.11', TAGI)
   == ('mp-lead-intake', 'mp-offer-builder', 'mp-sales-workflow'),
   'A3: KONTR-ASERCJA — 1.3.11 nadal wydaje wszystkie trzy wtyczki')
ok(wydania.wlasne_wydanie('1.3.99', TAGI) == (),
   'A4: wersja bez zadnego tagu nie wydaje niczego')

# O wydaniu rozstrzyga TAG, nie naglowek: wtyczka moze deklarowac 1.3.11 i miec
# w tej wersji swoje wydanie, i to jest caly powod, dla ktorego wolno ja pominac
# w 1.3.12 — kod z paczki calosci da sie doprowadzic do opublikowanego wydania.
ok('mp-offer-builder/v1.3.11' in TAGI,
   'A5: wtyczka pomijana w 1.3.12 ma opublikowane wydanie swojej wersji')

print()
print('=== B. publikuj.py: plan wydan bez paczek, ktorych nie ma ===')

plan = publikuj.plan_wydan('1.3.12', swoje)
nazwy = [n for n, _, _, _ in plan]

ok('calosc' in nazwy, 'B1: paczka calosci jest zawsze — niesie caly kod')
ok('mp-lead-intake' in nazwy, 'B2: wtyczka ze zmiana dostaje swoje wydanie')
ok('mp-offer-builder' not in nazwy and 'mp-sales-workflow' not in nazwy,
   'B3: wtyczki bez zmian NIE dostaja wydania', 'plan=%s' % nazwy)
ok(len(plan) == 2, 'B4: dwa wydania, nie cztery', 'len=%d' % len(plan))

pelny = publikuj.plan_wydan('1.3.11', ('mp-lead-intake', 'mp-offer-builder',
                                       'mp-sales-workflow'))
ok(len(pelny) == 4,
   'B5: KONTR-ASERCJA — komplet zmian nadal daje cztery wydania', 'len=%d' % len(pelny))

tagi_planu = dict((n, t) for n, t, _, _ in plan)
ok(tagi_planu['calosc'] == 'v1.3.12', 'B6: tag calosci to v1.3.12')
ok(tagi_planu['mp-lead-intake'] == 'mp-lead-intake/v1.3.12',
   'B7: tag wtyczki to mp-lead-intake/v1.3.12')

print()
print('=== C. demo-assety.py: ZIP-y wtyczek bez wlasnej paczki ===')

# Paczki wydania budujemy w katalogu tymczasowym w tym samym ksztalcie, w jakim
# robi je `buduj-zipy.py`: koperta z `PRZECZYTAJ-MNIE.txt` i ZIP-ami w srodku.
KOD = {
    'mp-lead-intake': b'PK-lead-intake-1.3.12',
    'mp-offer-builder': b'PK-offer-builder-1.3.11',
    'mp-sales-workflow': b'PK-sales-workflow-1.3.11',
}


def zip_z(pary):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w') as z:
        for nazwa, dane in pary:
            z.writestr(nazwa, dane)
    return buf.getvalue()


with tempfile.TemporaryDirectory() as kat:
    with open(os.path.join(kat, 'mp-lead-intake-1.3.12.zip'), 'wb') as f:
        f.write(zip_z([
            ('PRZECZYTAJ-MNIE.txt', b'tekst'),
            ('mp-lead-intake.zip', KOD['mp-lead-intake']),
            ('mp-lead-intake-materialy.zip', b'materialy'),
        ]))

    with open(os.path.join(kat, 'egzamin-koncowy-1.3.12.zip'), 'wb') as f:
        f.write(zip_z(
            [('PRZECZYTAJ-MNIE.txt', b'tekst')]
            + [('%s.zip' % p, d) for p, d in KOD.items()]
            + [('%s-materialy.zip' % p, b'materialy') for p in KOD]
        ))

    assety = demo.zebierz('1.3.12', kat, swoje)

    ok(set(assety) == {'mp-lead-intake.zip', 'mp-offer-builder.zip',
                       'mp-sales-workflow.zip', 'kredyt-kompas.zip'},
       'C1: demo dostaje komplet trzech wtyczek i motyw', 'ma=%s' % sorted(assety))

    for p, dane in KOD.items():
        ok(assety['%s.zip' % p] == dane,
           'C2 (%s): bajty z paczki wydania, nie z katalogu roboczego' % p)

    # Obietnica z opisu wydania: demo uruchamia te same bajty, ktore dostaje
    # klient. Dla wtyczki bez wlasnej paczki jedynym zrodlem jest paczka calosci
    # — i to ma byc ta sama zawartosc, ktora klient z niej rozpakuje.
    with zipfile.ZipFile(os.path.join(kat, 'egzamin-koncowy-1.3.12.zip')) as z:
        ok(assety['mp-offer-builder.zip'] == z.read('mp-offer-builder.zip'),
           'C3: wtyczka bez wlasnego wydania idzie z paczki calosci')

    # KONTR-ASERCJA: brak paczki calosci to nadal blad, a nie ciche pominiecie.
    os.remove(os.path.join(kat, 'egzamin-koncowy-1.3.12.zip'))
    prawdziwy_exit = demo.sys.exit

    class Przerwane(Exception):
        pass

    def podmieniony(komunikat=''):
        raise Przerwane(str(komunikat))

    demo.sys.exit = podmieniony
    try:
        demo.zebierz('1.3.12', kat, swoje)
        ok(False, 'C4: brak paczki calosci PRZERYWA wgrywanie assetow')
    except Przerwane as e:
        ok('egzamin-koncowy' in str(e),
           'C4: brak paczki calosci PRZERYWA wgrywanie assetow', str(e))
    finally:
        demo.sys.exit = prawdziwy_exit

print()
print('----- PASS: %d / FAIL: %d -----' % (PASS[0], FAIL[0]))
print('VERDICT_ALL_PASS' if FAIL[0] == 0 else 'VERDICT_HAS_FAILURES')
sys.exit(1 if FAIL[0] else 0)
