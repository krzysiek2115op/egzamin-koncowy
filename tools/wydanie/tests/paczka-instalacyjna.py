#!/usr/bin/env python3
"""Paczka instalacyjna nie wynosi na serwer klienta plikow roboczych.

    python3 tools/wydanie/tests/paczka-instalacyjna.py

Do 1.3.9 ZIP wtyczki 1 mial 101 plikow, z czego 47 deweloperskich: 27 testow
i 20 dokumentow z `docs/`. Wszystkie ladowaly do `wp-content/plugins/...`, czyli
w miejsce, o ktorym `PRZECZYTAJ-MNIE.txt` sam pisze, ze jest publiczne.

Strazniki `ABSPATH` (naprawa z tego samego wydania) zamykaja tylko PLIKI PHP.
Na `.md` nie robia nic — serwer oddaje je jako tekst i nie uruchamia. Zadanie
HTTP na `docs/SECURITY.md` odpowiadalo 200 i 9966 bajtow typu `text/markdown`.
Dlatego druga polowa naprawy jest w budowaniu paczki, nie w kodzie wtyczki.
"""
import importlib.util
import os
import sys

KAT = os.path.dirname(os.path.abspath(__file__))

# Modul nazywa sie `buduj-zipy.py` — myslnik nie jest legalny w nazwie modulu,
# wiec zwykly `import` go nie widzi. Ladujemy go po sciezce.
_spec = importlib.util.spec_from_file_location(
    'buduj_zipy', os.path.join(os.path.dirname(KAT), 'buduj-zipy.py'))
bz = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(bz)

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


DISTIGNORE = (
    b'tests/\ntests/*\ndocs/\nblueprint/\nmaterialy-src/\n*.md\n'
    b'composer.json\ncomposer.lock\nphpcs.xml*\n.phpcs.xml*\n.distignore\n.gitattributes\n'
)


def przyklad():
    """Zestaw plikow taki, jaki naprawde siedzial w tagu 1.3.9."""
    return {
        'mp-lead-intake/.distignore': DISTIGNORE,
        'mp-lead-intake/mp-lead-intake.php': b'<?php // glowny',
        'mp-lead-intake/readme.txt': b'=== MP Lead Intake ===',
        'mp-lead-intake/uninstall.php': b'<?php // odinstalowanie',
        'mp-lead-intake/includes/class-mp-form.php': b'<?php // kod',
        'mp-lead-intake/languages/mp-lead-intake.pot': b'msgid ""',
        'mp-lead-intake/assets/css/mp-form.css': b'.mp{}',
        'mp-lead-intake/tests/process-harness/run-process.php': b'<?php // harness',
        'mp-lead-intake/tests/naprawy/grupa-a.php': b'<?php // test',
        'mp-lead-intake/docs/AUDYT.md': b'# Audyt wewnetrzny',
        'mp-lead-intake/docs/DEBUG-RAPORT.md': b'# Debug',
        'mp-lead-intake/docs/TESTY.md': b'# Testy',
        'mp-lead-intake/docs/dzial-06/rodo-zgody.md': b'# Zrodla',
        'mp-lead-intake/docs/SECURITY.md': b'# Bezpieczenstwo',
        'mp-lead-intake/docs/POLITYKA-PRYWATNOSCI-WZOR.md': b'# Wzor',
        'mp-lead-intake/docs/security.txt': b'Contact: ...',
        'mp-lead-intake/blueprint/diagram.html': b'<html>',
        'mp-lead-intake/materialy-src/build.sh': b'#!/bin/sh',
        'mp-lead-intake/composer.json': b'{}',
    }


print('=== A. pliki robocze nie wchodza do paczki ===')

kod, dokumenty = bz.rozdziel('mp-lead-intake', przyklad())

ok(
    not [n for n in kod if '/tests/' in n],
    'zaden plik z tests/ nie trafia do paczki instalacyjnej',
    ', '.join(n for n in kod if '/tests/' in n),
)

wewnetrzne = ('AUDYT.md', 'DEBUG-RAPORT.md', 'TESTY.md', 'rodo-zgody.md')
zostaly = [n for n in kod if any(w in n for w in wewnetrzne)]
ok(not zostaly, 'dokumentacja wewnetrzna zostaje poza paczka', ', '.join(zostaly))

# `.distignore` wymienia tez to, czego sam bym nie wpisal — i o to chodzi:
# zrodlem prawdy jest deklaracja wtyczki, nie lista w skrypcie budujacym.
for katalog in ('blueprint/', 'materialy-src/'):
    ok(
        not [n for n in kod if '/' + katalog in n],
        'katalog %s wykluczony zgodnie z .distignore' % katalog,
        ', '.join(n for n in kod if '/' + katalog in n),
    )

ok(
    'mp-lead-intake/composer.json' not in kod,
    'composer.json nie jedzie na serwer klienta',
)
ok(
    'mp-lead-intake/.distignore' not in kod,
    'sam .distignore tez nie — wyklucza siebie',
)

print()
print('=== B. ale to, co dla klienta, nie ginie ===')

ok(
    'POLITYKA-PRYWATNOSCI-WZOR.md' in dokumenty,
    'wzor polityki prywatnosci przechodzi do materialow',
    'materialy=' + ', '.join(sorted(dokumenty)),
)
ok('SECURITY.md' in dokumenty, 'dokument o bezpieczenstwie przechodzi do materialow')
ok('security.txt' in dokumenty, 'security.txt przechodzi do materialow')
ok(
    dokumenty.get('POLITYKA-PRYWATNOSCI-WZOR.md') == b'# Wzor',
    'i przechodzi z trescia, a nie sama nazwa',
)

print()
print('=== C. kontr-asercje: filtr nie wycial za duzo ===')

for plik in (
    'mp-lead-intake/mp-lead-intake.php',
    'mp-lead-intake/readme.txt',
    'mp-lead-intake/uninstall.php',
    'mp-lead-intake/includes/class-mp-form.php',
    'mp-lead-intake/languages/mp-lead-intake.pot',
    'mp-lead-intake/assets/css/mp-form.css',
):
    ok(plik in kod, 'w paczce zostaje ' + plik[len('mp-lead-intake/'):])

ok(
    kod.get('mp-lead-intake/includes/class-mp-form.php') == b'<?php // kod',
    'pliki kodu przechodza bez zmiany tresci',
)

print()
print('=== D. samokontrola budowania ===')

# Zaslepka za sys.exit — chcemy sprawdzic, ZE przerywa, nie zakonczyc test.
class Przerwane(Exception):
    pass


def zamiast_exit(msg):
    raise Przerwane(msg)


prawdziwy_exit = bz.sys.exit
bz.sys.exit = zamiast_exit

try:
    bz.sprawdz_paczke('mp-lead-intake', kod)
    ok(True, 'poprawna paczka przechodzi kontrole bez slowa')
except Przerwane as e:
    ok(False, 'poprawna paczka przechodzi kontrole bez slowa', str(e))

skazona = dict(kod)
skazona['mp-lead-intake/tests/naprawy/grupa-a.php'] = b'<?php'
try:
    bz.sprawdz_paczke('mp-lead-intake', skazona)
    ok(False, 'przemycony plik testowy PRZERYWA budowanie')
except Przerwane as e:
    ok('tests/naprawy/grupa-a.php' in str(e),
       'przemycony plik testowy PRZERYWA budowanie', str(e))

okrojona = {n: d for n, d in kod.items() if not n.endswith('/mp-lead-intake.php')}
try:
    bz.sprawdz_paczke('mp-lead-intake', okrojona)
    ok(False, 'paczka bez pliku glownego PRZERYWA budowanie')
except Przerwane as e:
    ok('pliku glownego' in str(e),
       'paczka bez pliku glownego PRZERYWA budowanie', str(e))

# Milczenie nie moze znaczyc „wyslij wszystko": wtyczka bez deklaracji
# nie ma jak powiedziec, czego nie wysylac, wiec budowanie ma stanac.
try:
    bz.rozdziel('mp-bez-deklaracji', {'mp-bez-deklaracji/plik.php': b'<?php'})
    ok(False, 'wtyczka bez .distignore PRZERYWA budowanie')
except Przerwane as e:
    ok('.distignore' in str(e), 'wtyczka bez .distignore PRZERYWA budowanie', str(e))

bz.sys.exit = prawdziwy_exit

print()
print('=== E. wtyczka 3: WDROZENIE.md tez jest dla klienta ===')

kod3, dok3 = bz.rozdziel('mp-sales-workflow', {
    'mp-sales-workflow/.distignore': DISTIGNORE,
    'mp-sales-workflow/mp-sales-workflow.php': b'<?php',
    'mp-sales-workflow/docs/WDROZENIE.md': b'# Wdrozenie',
    'mp-sales-workflow/docs/TESTY.md': b'# Testy',
})
ok('WDROZENIE.md' in dok3, 'instrukcja wdrozenia idzie do materialow')
ok('TESTY.md' not in dok3 and not [n for n in kod3 if 'TESTY' in n],
   'a dokument testow zostaje w repozytorium')

print()
print('----- PASS: %d / FAIL: %d -----' % (PASS[0], FAIL[0]))
print('VERDICT_ALL_PASS' if FAIL[0] == 0 else 'VERDICT_HAS_FAILURES')
sys.exit(1 if FAIL[0] else 0)
