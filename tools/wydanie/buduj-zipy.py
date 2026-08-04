#!/usr/bin/env python3
"""Buduje paczki wydania dla klienta.

    python3 tools/wydanie/buduj-zipy.py 1.3.10 [--wyjscie KATALOG]

Do wydania 1.3.9 ten skrypt zyl w katalogu tymczasowym, a tekst
`PRZECZYTAJ-MNIE.txt` — czyli PIERWSZA STRONA, ktora widzi klient po rozpakowaniu
— nie istnial w repozytorium wcale: przepisywany byl z paczki poprzedniego
wydania z podmiana numeru wersji. Nikt nie mogl go zrecenzowac w commicie, nikt
nie mogl go poprawic bez archeologii w archiwach, a po restarcie maszyny (katalog
tymczasowy jest w tmpfs) nie dalo sie go odtworzyc w ogole. Zrodla leza teraz
w `tools/wydanie/przeczytaj-mnie/` i sa czescia repozytorium jak reszta dostawy.

Zrodlem KODU jest zawsze `git archive <tag>`, nigdy katalog roboczy — inaczej do
paczki trafiaja pliki robocze, ktorych nikt nie zamierzal wysylac.
"""
import argparse
import io
import os
import subprocess
import sys
import tarfile
import zipfile

KAT = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(KAT))
TEKSTY = os.path.join(KAT, 'przeczytaj-mnie')
WTYCZKI = ('mp-lead-intake', 'mp-offer-builder', 'mp-sales-workflow')

# Stempel czasu w ZIP-ie jest staly, zeby ta sama zawartosc dawala ten sam plik.
STEMPEL = (2026, 1, 1, 0, 0, 0)


def z_gita(tag, sciezka):
    """Zwraca {sciezka_w_repo: bajty} dla podkatalogu w tagu."""
    tar = subprocess.run(
        ['git', '-C', REPO, 'archive', '--format=tar', tag, sciezka],
        check=True, stdout=subprocess.PIPE).stdout
    pliki = {}
    with tarfile.open(fileobj=io.BytesIO(tar)) as t:
        for czlon in t.getmembers():
            if czlon.isfile():
                pliki[czlon.name] = t.extractfile(czlon).read()
    return pliki


def zapisz_zip(pary):
    """pary: [(nazwa_w_zipie, bajty)] -> bajty ZIP-a."""
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w', zipfile.ZIP_DEFLATED) as z:
        for nazwa, dane in sorted(pary):
            info = zipfile.ZipInfo(nazwa, date_time=STEMPEL)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, dane)
    return buf.getvalue()


def przeczytaj_mnie(nazwa, wersja):
    """Tekst dla klienta ze zrodla w repozytorium, z wstawionym numerem wersji."""
    sciezka = os.path.join(TEKSTY, nazwa + '.txt')

    with io.open(sciezka, encoding='utf-8') as f:
        tekst = f.read()

    if '{WERSJA}' not in tekst:
        sys.exit('%s: brak znacznika {WERSJA} — numer wydania nie zostalby wstawiony' % sciezka)

    tekst = tekst.replace('{WERSJA}', wersja)

    # Podkreslenie naglowka musi miec dlugosc naglowka, a ta zalezy od dlugosci
    # numeru wersji (1.3.9 -> 1.3.10 to jeden znak wiecej). Liczymy je tutaj,
    # zamiast wymagac, zeby ktos pamietal o poprawieniu go w zrodle.
    linie = tekst.split('\n')
    if len(linie) > 1 and linie[1] and set(linie[1]) == {'='}:
        linie[1] = '=' * len(linie[0])
        tekst = '\n'.join(linie)

    return tekst.encode('utf-8')


def wlasne_wydanie(wersja):
    """Wtyczki, ktore maja wlasny tag dla TEJ wersji.

    Wtyczka bez zmian nie dostaje nowego numeru (regula z 1.3.9), wiec jej ZIP
    wchodzi tylko do paczki calosci i budowany jest z tagu projektowego.
    """
    tagi = subprocess.run(['git', '-C', REPO, 'tag'],
                          check=True, stdout=subprocess.PIPE).stdout.decode()
    dostepne = set(tagi.split())

    return tuple(p for p in WTYCZKI if '%s/v%s' % (p, wersja) in dostepne)


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('wersja', help='numer wydania, np. 1.3.10')
    ap.add_argument('--wyjscie', default=os.path.join(KAT, 'paczki'),
                    help='katalog na gotowe paczki')
    args = ap.parse_args()

    wersja = args.wersja
    wyj = args.wyjscie
    swoje = wlasne_wydanie(wersja)

    os.makedirs(wyj, exist_ok=True)
    wewnetrzne = {}

    for p in WTYCZKI:
        tag = ('%s/v%s' % (p, wersja)) if p in swoje else ('v' + wersja)

        pliki = z_gita(tag, p)
        if not pliki:
            sys.exit('puste archiwum dla %s (tag %s)' % (p, tag))
        zip_wtyczki = zapisz_zip(list(pliki.items()))

        mat = z_gita(tag, 'paczka-klienta/%s' % p)
        przedrostek = 'paczka-klienta/%s/' % p
        pary_mat = [('%s-materialy/%s' % (p, n[len(przedrostek):]), d)
                    for n, d in mat.items()]
        if not pary_mat:
            sys.exit('brak materialow dla %s (tag %s)' % (p, tag))
        zip_mat = zapisz_zip(pary_mat)

        wewnetrzne['%s.zip' % p] = zip_wtyczki
        wewnetrzne['%s-materialy.zip' % p] = zip_mat

        if p not in swoje:
            print('%-34s bez wlasnego wydania — tylko w paczce calosci' % p)
            continue

        paczka = zapisz_zip([
            ('PRZECZYTAJ-MNIE.txt', przeczytaj_mnie(p, wersja)),
            ('%s.zip' % p, zip_wtyczki),
            ('%s-materialy.zip' % p, zip_mat),
        ])
        cel = os.path.join(wyj, '%s-%s.zip' % (p, wersja))
        with open(cel, 'wb') as f:
            f.write(paczka)
        print('%-34s %8.1f kB  (wtyczka: %d plikow, materialy: %d)'
              % (os.path.basename(cel), len(paczka) / 1024.0,
                 len(pliki), len(pary_mat)))

    calosc = [('PRZECZYTAJ-MNIE.txt', przeczytaj_mnie('calosc', wersja))]
    calosc += list(wewnetrzne.items())
    cel = os.path.join(wyj, 'egzamin-koncowy-%s.zip' % wersja)
    with open(cel, 'wb') as f:
        f.write(zapisz_zip(calosc))
    print('%-34s %8.1f kB  (%d pozycji)'
          % (os.path.basename(cel), os.path.getsize(cel) / 1024.0, len(calosc)))


if __name__ == '__main__':
    main()
