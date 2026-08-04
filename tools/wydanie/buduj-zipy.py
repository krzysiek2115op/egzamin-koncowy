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
import fnmatch
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

# Czego nie wysylac, mowi kazda wtyczka sama — plikiem `.distignore`.
#
# `PRZECZYTAJ-MNIE.txt` ostrzega klienta, ze katalog wtyczki jest dostepny
# publicznie — i ma racje. Paczka niosla tam jednak 47 plikow deweloperskich na
# 101: testy, raport z audytu, raport z debugowania, dokumentacje wewnetrzna
# wszystkich dzialow. Strazniki `ABSPATH` chronia PLIKI PHP; na `.md` nie dzialaja
# w ogole, bo serwer oddaje je jako zwykly tekst. Sprawdzone zadaniem HTTP:
# `wp-content/plugins/mp-lead-intake/docs/SECURITY.md` odpowiadal 200 i 9966 B
# typu `text/markdown` — czyli dokument opisujacy zabezpieczenia produktu byl
# do pobrania przez kazdego, kto zgadl adres.
#
# Najgorsze, ze projekt sam to wiedzial: `mp-offer-builder/.distignore` wymienia
# dokladnie te katalogi od poczatku. Plik lezal nieczytany, bo paczki budowalismy
# recznie, a nie `wp dist-archive`. Zamiast wpisywac wlasna liste w skrypcie
# (drugie zrodlo prawdy, ktore rozjedzie sie z pierwszym), czytamy deklaracje
# wtyczki. Brak `.distignore` PRZERYWA budowanie — milczenie nie moze znaczyc
# „wyslij wszystko".
#
# Katalogi zostaja w repozytorium (to dorobek i dowod pracy), znikaja tylko
# z tego, co sie instaluje. Zaden kod wykonywany ich nie czyta — odwolania
# w zrodlach sa wylacznie w komentarzach cytujacych zrodla (Golden Rule #2).
DISTIGNORE = '.distignore'

# Dokumenty z `docs/` pisane DLA KLIENTA. Nie gina razem z reszta katalogu —
# przenosza sie tam, gdzie klient i tak zaglada, czyli do paczki materialow.
# Tam sa do przeczytania, a nie do pobrania z cudzej przegladarki.
DLA_KLIENTA = (
    'docs/POLITYKA-PRYWATNOSCI-WZOR.md',
    'docs/SECURITY.md',
    'docs/security.txt',
    'docs/robots.txt.example',
    'docs/security-headers.conf.example',
    'docs/WDROZENIE.md',
)


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


def wzorce(wtyczka, pliki):
    """Wzorce wykluczen z `.distignore` wtyczki (format `wp dist-archive`)."""
    klucz = wtyczka + '/' + DISTIGNORE

    if klucz not in pliki:
        sys.exit('%s: brak %s — nie wiadomo, czego NIE wysylac do klienta'
                 % (wtyczka, DISTIGNORE))

    lista = []
    for linia in pliki[klucz].decode('utf-8').splitlines():
        linia = linia.strip()
        if linia and not linia.startswith('#'):
            lista.append(linia.rstrip('/'))

    return lista


def pomijany(wzgledna, lista):
    """Czy sciezka wzgledem katalogu wtyczki pasuje do ktoregos wzorca."""
    for wzor in lista:
        if fnmatch.fnmatch(wzgledna, wzor):
            return True
        if fnmatch.fnmatch(os.path.basename(wzgledna), wzor):
            return True
        # Wzorzec katalogu obejmuje wszystko pod nim, na dowolnej glebokosci.
        if wzgledna.startswith(wzor + '/') or ('/' + wzor + '/') in ('/' + wzgledna):
            return True

    return False


def rozdziel(wtyczka, pliki):
    """Dzieli pliki wtyczki na instalowane i te tylko dla klienta.

    Zwraca (kod, dokumenty), gdzie `kod` ma klucze jak w repozytorium, a
    `dokumenty` — same nazwy plikow, bo trafiaja plasko do materialow.
    """
    lista = wzorce(wtyczka, pliki)
    kod = {}
    dokumenty = {}
    przedrostek = wtyczka + '/'

    for nazwa, dane in pliki.items():
        wzgledna = nazwa[len(przedrostek):] if nazwa.startswith(przedrostek) else nazwa

        # Kolejnosc ma znaczenie: dokumenty dla klienta leza w `docs/`, ktore
        # `.distignore` wyklucza. Najpierw je ratujemy, dopiero potem filtrujemy.
        if wzgledna in DLA_KLIENTA:
            dokumenty[os.path.basename(wzgledna)] = dane
        elif not pomijany(wzgledna, lista):
            kod[nazwa] = dane

    return kod, dokumenty


def sprawdz_paczke(wtyczka, kod):
    """Przerywa budowanie, jesli do paczki instalacyjnej cos sie przemycilo.

    Kontrola jest w SKRYPCIE, a nie tylko w tescie obok, bo to ostatnie miejsce
    przed wyslaniem plikow do klienta — a pomylka tutaj konczy sie dokumentem
    wewnetrznym na cudzym serwerze produkcyjnym.
    """
    przedrostek = wtyczka + '/'
    obce = sorted(n for n in kod
                  if n[len(przedrostek):].startswith(('tests/', 'docs/')))
    if obce:
        sys.exit('%s: pliki robocze w paczce instalacyjnej: %s'
                 % (wtyczka, ', '.join(obce)))

    # Kontrola w druga strone: gdyby filtr wycial za duzo, paczka bylaby cicho
    # pusta i nikt by tego nie zauwazyl przed instalacja u klienta. Wtyczka bez
    # pliku glownego nie jest dla WordPressa wtyczka w ogole.
    glowny = przedrostek + wtyczka + '.php'
    if glowny not in kod:
        sys.exit('%s: w paczce brakuje pliku glownego %s' % (wtyczka, glowny))


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

        kod, dokumenty = rozdziel(p, pliki)
        sprawdz_paczke(p, kod)
        zip_wtyczki = zapisz_zip(list(kod.items()))

        mat = z_gita(tag, 'paczka-klienta/%s' % p)
        przedrostek = 'paczka-klienta/%s/' % p
        pary_mat = [('%s-materialy/%s' % (p, n[len(przedrostek):]), d)
                    for n, d in mat.items()]
        if not pary_mat:
            sys.exit('brak materialow dla %s (tag %s)' % (p, tag))
        pary_mat += [('%s-materialy/dokumentacja/%s' % (p, n), d)
                     for n, d in dokumenty.items()]
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
        print('%-34s %8.1f kB  (wtyczka: %d z %d plikow, materialy: %d)'
              % (os.path.basename(cel), len(paczka) / 1024.0,
                 len(kod), len(pliki), len(pary_mat)))

    calosc = [('PRZECZYTAJ-MNIE.txt', przeczytaj_mnie('calosc', wersja))]
    calosc += list(wewnetrzne.items())
    cel = os.path.join(wyj, 'egzamin-koncowy-%s.zip' % wersja)
    with open(cel, 'wb') as f:
        f.write(zapisz_zip(calosc))
    print('%-34s %8.1f kB  (%d pozycji)'
          % (os.path.basename(cel), os.path.getsize(cel) / 1024.0, len(calosc)))


if __name__ == '__main__':
    main()
