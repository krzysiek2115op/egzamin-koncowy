#!/usr/bin/env python3
"""Odswieza assety strony pokazowej po wydaniu.

    python3 tools/wydanie/demo-assety.py 1.3.10 --katalog KAT [--sucho]

Blueprint WordPress Playground (`tools/strona-pokazowa/blueprint.json`) pobiera
wtyczki i motyw z wydania o tagu `demo-egzamin`. Assety tego wydania NIE
odswiezaja sie same — po kazdym wydaniu produktu trzeba je wgrac na nowo, bo
inaczej egzaminator oglada demo starszego kodu niz ten, ktory dostaje klient.
Robilismy to recznie i dwa razy o tym zapomnielismy; dlatego jest tu skrypt.

ZIP-y wtyczek pochodza z WNETRZA paczek wydania, a nie z katalogu roboczego.
Opis wydania obiecuje, ze demo uruchamia „dokladnie te same bajty, ktore dostaje
klient" — wyciagniecie ich z gotowej paczki jest jedynym sposobem, zeby ta
obietnica byla prawdziwa z definicji, a nie z checi.
"""
import argparse
import io
import os
import sys
import zipfile

KAT = os.path.dirname(os.path.abspath(__file__))
REPO_KAT = os.path.dirname(os.path.dirname(KAT))
sys.path.insert(0, KAT)

import publikuj  # noqa: E402  (import po ustawieniu sciezki)

TAG = 'demo-egzamin'
MOTYW = os.path.join(REPO_KAT, 'tools', 'strona-pokazowa', 'motyw')
WTYCZKI = ('mp-lead-intake', 'mp-offer-builder', 'mp-sales-workflow')


def zip_motywu():
    """Motyw spakowany tak, jak wymaga tego Playground: korzeniem jest katalog."""
    pary = []

    for koren, _, pliki in os.walk(MOTYW):
        for nazwa in sorted(pliki):
            pelna = os.path.join(koren, nazwa)
            wzgledna = os.path.relpath(pelna, MOTYW)
            with open(pelna, 'rb') as f:
                pary.append(('kredyt-kompas/' + wzgledna.replace(os.sep, '/'), f.read()))

    if not pary:
        sys.exit('pusty motyw w %s' % MOTYW)

    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w', zipfile.ZIP_DEFLATED) as z:
        for nazwa, dane in sorted(pary):
            info = zipfile.ZipInfo(nazwa, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, dane)

    return buf.getvalue(), len(pary)


def zebierz(wersja, katalog, swoje):
    """Assety demo: trzy ZIP-y wtyczek z paczek wydania + motyw.

    Demo pokazuje zawsze wszystkie trzy wtyczki, ale wydanie niekoniecznie
    wszystkie rusza — wtyczka bez zmian nie dostaje numeru ani wlasnej paczki
    (regula z 1.3.9). Jej ZIP jest wtedy w paczce calosci, ktora tez powstaje
    z tagu, wiec obietnica „demo uruchamia bajty, ktore dostaje klient" zostaje
    prawdziwa: to dokladnie ten plik, ktory klient rozpakuje.

    :param str        wersja:  numer wydania.
    :param str        katalog: katalog z paczkami z `buduj-zipy.py`.
    :param tuple|list swoje:   wtyczki z wlasna paczka.
    """
    assety = {}
    calosc = os.path.join(katalog, 'egzamin-koncowy-%s.zip' % wersja)

    for p in WTYCZKI:
        sciezka = os.path.join(katalog, '%s-%s.zip' % (p, wersja)) if p in swoje else calosc

        if not os.path.exists(sciezka):
            sys.exit('brak paczki %s — najpierw buduj-zipy.py' % sciezka)

        with zipfile.ZipFile(sciezka) as z:
            assety['%s.zip' % p] = z.read('%s.zip' % p)

    motyw, ile = zip_motywu()
    assety['kredyt-kompas.zip'] = motyw
    print('motyw kredyt-kompas: %d plikow' % ile)

    return assety


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('wersja')
    ap.add_argument('--katalog', default=os.path.join(KAT, 'paczki'))
    ap.add_argument('--sucho', action='store_true')
    args = ap.parse_args()

    swoje = publikuj.wydania.wlasne_wydanie(
        args.wersja, publikuj.wydania.tagi_lokalne(REPO_KAT))
    assety = zebierz(args.wersja, args.katalog, swoje)

    if args.sucho:
        for n, d in sorted(assety.items()):
            print('  %-26s %8.1f kB' % (n, len(d) / 1024.0))
        return

    publikuj.TOKEN = publikuj.token()
    kod, wyd = publikuj.zadanie(
        'GET', '%s/repos/%s/releases/tags/%s' % (publikuj.API, publikuj.REPO, TAG))

    if kod != 200:
        sys.exit('nie ma wydania %s (%s): %s' % (TAG, kod, wyd))

    tresc = publikuj.notatka('demo-egzamin', args.wersja) \
        if os.path.exists(os.path.join(KAT, 'notatki', args.wersja, 'demo-egzamin.md')) \
        else None

    if tresc:
        publikuj.zadanie(
            'PATCH', '%s/repos/%s/releases/%d' % (publikuj.API, publikuj.REPO, wyd['id']),
            {'body': tresc})

    stare = {a['name']: a['id'] for a in wyd.get('assets', [])}

    for nazwa, dane in sorted(assety.items()):
        if nazwa in stare:
            publikuj.zadanie('DELETE', '%s/repos/%s/releases/assets/%d'
                             % (publikuj.API, publikuj.REPO, stare[nazwa]))

        kod, odp = publikuj.zadanie(
            'POST',
            'https://uploads.github.com/repos/%s/releases/%d/assets?name=%s'
            % (publikuj.REPO, wyd['id'], nazwa),
            dane, {'Content-Type': 'application/zip'}, surowe=True)

        if kod not in (200, 201):
            sys.exit('%s: nie udalo sie wgrac (%s): %s' % (nazwa, kod, odp))

        print('  wgrano %-26s %8.1f kB' % (nazwa, len(dane) / 1024.0))

    print(wyd['html_url'])


if __name__ == '__main__':
    main()
