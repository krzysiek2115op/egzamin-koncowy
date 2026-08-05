#!/usr/bin/env python3
"""Publikuje wydania na GitHubie z paczek zbudowanych przez `buduj-zipy.py`.

    python3 tools/wydanie/publikuj.py 1.3.10 --katalog KAT [--sucho]

Tresc notatek pochodzi z `tools/wydanie/notatki/<wersja>/<nazwa>.md`, czyli
z repozytorium — tak jak `PRZECZYTAJ-MNIE.txt`. Do 1.3.9 skrypt publikujacy
i teksty wydan zyly w katalogu tymczasowym i nie dalo sie ich zrecenzowac
w commicie ani odtworzyc po restarcie maszyny (katalog tymczasowy jest w tmpfs).

Wydanie wtyczki dostaje DOKLADNIE JEDNA paczke — swoja wlasna — plus odnosnik do
wydania calosci. Wczesniej niosło tez `egzamin-koncowy-<wersja>.zip`, czyli komplet
trzech wtyczek zdublowany na czterech stronach; pobierajacy nie wiedzial, ktory
plik jest tym wlasciwym.
"""
import argparse
import io
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request

KAT = os.path.dirname(os.path.abspath(__file__))
REPO_KAT = os.path.dirname(os.path.dirname(KAT))
sys.path.insert(0, KAT)

import wydania  # noqa: E402  (import po ustawieniu sciezki)

REPO = 'krzysiek2115op/egzamin-koncowy'
API = 'https://api.github.com'

# (plik notatki, tag, tytul, nazwa paczki) — %s to numer wersji.
CALOSC = ('calosc', 'v%s', 'Egzamin koncowy %s — caly projekt', 'egzamin-koncowy-%s.zip')

TYTULY = {
    'mp-lead-intake': 'MP Lead Intake',
    'mp-offer-builder': 'MP Offer Builder',
    'mp-sales-workflow': 'MP Sales Workflow',
}


def plan_wydan(wersja, swoje):
    """Co publikujemy: zawsze calosc, plus wtyczki z wlasnym tagiem.

    Sztywna lista czterech wydan zakladala, ze kazda wersja rusza wszystkie trzy
    wtyczki. Wtyczka bez zmian nie dostaje numeru (regula z 1.3.9), wiec nie ma
    ani tagu, ani paczki — i publikacja stanelaby w polowie, z tagiem projektu
    juz na GitHubie. Kod wtyczki pominietej jedzie w paczce calosci.

    :param str        wersja: numer wydania.
    :param tuple|list swoje:  wtyczki z wlasnym wydaniem (`wydania.wlasne_wydanie`).
    :return list: krotki (nazwa notatki, tag, tytul, nazwa paczki) — juz z wersja.
    """
    plan = [(CALOSC[0], CALOSC[1] % wersja, CALOSC[2] % wersja, CALOSC[3] % wersja)]

    for p in wydania.WTYCZKI:
        if p in swoje:
            plan.append((p, '%s/v%s' % (p, wersja),
                         '%s %s' % (TYTULY[p], wersja), '%s-%s.zip' % (p, wersja)))

    return plan

WSTEP = (
    'KOMPLET TRZECH WTYCZEK: https://github.com/%s/releases/tag/v%%s\n'
    'Ta strona ma wylacznie paczke TEJ jednej wtyczki. Wtyczki dzialaja razem '
    'i instaluje sie je w kolejnosci 1 -> 2 -> 3, wiec do wdrozenia potrzebna '
    'jest paczka calosci spod odnosnika wyzej.\n\n---\n\n'
) % REPO


def token():
    """Token z zapisanych danych logowania git."""
    sciezka = os.path.expanduser('~/.git-credentials')

    if not os.path.exists(sciezka):
        sys.exit('brak ~/.git-credentials — nie ma czym uwierzytelnic zadania')

    with io.open(sciezka, encoding='utf-8') as f:
        for linia in f:
            m = re.search(r'https://[^:]+:([^@]+)@github\.com', linia)
            if m:
                return m.group(1)

    sys.exit('w ~/.git-credentials nie ma wpisu dla github.com')


def zadanie(metoda, url, dane=None, naglowki=None, surowe=False):
    """Zadanie do API. Zwraca (kod, odpowiedz_json_albo_bajty)."""
    tresc = dane if surowe else (json.dumps(dane).encode('utf-8') if dane else None)
    req = urllib.request.Request(url, data=tresc, method=metoda)
    req.add_header('Authorization', 'Bearer ' + TOKEN)
    req.add_header('Accept', 'application/vnd.github+json')

    for k, v in (naglowki or {}).items():
        req.add_header(k, v)

    try:
        with urllib.request.urlopen(req) as o:
            body = o.read()
            return o.status, (json.loads(body) if body else {})
    except urllib.error.HTTPError as e:
        body = e.read()
        try:
            return e.code, json.loads(body)
        except ValueError:
            return e.code, {'raw': body[:400].decode('utf-8', 'replace')}


def notatka(nazwa, wersja):
    """Tekst wydania ze zrodla w repozytorium."""
    sciezka = os.path.join(KAT, 'notatki', wersja, nazwa + '.md')

    if not os.path.exists(sciezka):
        sys.exit('brak notatki %s — wydanie bez opisu nie mowi nikomu, co sie zmienilo'
                 % sciezka)

    with io.open(sciezka, encoding='utf-8') as f:
        tekst = f.read().strip()

    # Wtyczka dostaje na gorze odnosnik do kompletu. Doklejamy go tutaj, zeby
    # nie zalezal od tego, czy ktos pamietal wkleic go do czterech plikow.
    return (tekst if nazwa == 'calosc' else (WSTEP % wersja) + tekst) + '\n'


def tag_istnieje(tag):
    wynik = subprocess.run(['git', '-C', REPO_KAT, 'ls-remote', '--tags', 'origin',
                            'refs/tags/' + tag], stdout=subprocess.PIPE)
    return bool(wynik.stdout.strip())


def opublikuj(nazwa, tag, tytul, paczka, wersja, katalog, sucho):
    sciezka = os.path.join(katalog, paczka)

    if not os.path.exists(sciezka):
        sys.exit('brak paczki %s — najpierw buduj-zipy.py' % sciezka)

    if not tag_istnieje(tag):
        sys.exit('tag %s nie jest wypchniety — wydanie wskazywaloby w prozne' % tag)

    tresc = notatka(nazwa, wersja)

    if sucho:
        print('%-34s tag=%-28s paczka=%.1f kB, opis=%d znakow'
              % (tytul, tag, os.path.getsize(sciezka) / 1024.0, len(tresc)))
        return

    kod, dane = zadanie('POST', '%s/repos/%s/releases' % (API, REPO), {
        'tag_name': tag,
        'name': tytul,
        'body': tresc,
        'draft': False,
        'prerelease': False,
    })

    if kod == 422:  # wydanie dla tego tagu juz jest — poprawiamy je zamiast dublowac
        kod2, istniejace = zadanie(
            'GET', '%s/repos/%s/releases/tags/%s' % (API, REPO, tag))
        if kod2 != 200:
            sys.exit('%s: nie da sie ani utworzyc, ani odczytac wydania: %s' % (tag, dane))
        kod, dane = zadanie(
            'PATCH', '%s/repos/%s/releases/%d' % (API, REPO, istniejace['id']),
            {'name': tytul, 'body': tresc})

    if kod not in (200, 201):
        sys.exit('%s: blad API (%s): %s' % (tag, kod, dane))

    rel_id = dane['id']

    # Zalacznik o tej samej nazwie blokuje wgranie nowego — usuwamy stary.
    for a in dane.get('assets', []):
        if a['name'] == paczka:
            zadanie('DELETE', '%s/repos/%s/releases/assets/%d' % (API, REPO, a['id']))

    with open(sciezka, 'rb') as f:
        bajty = f.read()

    kod, odp = zadanie(
        'POST',
        'https://uploads.github.com/repos/%s/releases/%d/assets?name=%s'
        % (REPO, rel_id, paczka),
        bajty, {'Content-Type': 'application/zip'}, surowe=True)

    if kod not in (200, 201):
        sys.exit('%s: nie udalo sie wgrac %s (%s): %s' % (tag, paczka, kod, odp))

    print('%-34s %s  (%.1f kB)' % (tytul, dane['html_url'], len(bajty) / 1024.0))


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('wersja')
    ap.add_argument('--katalog', default=os.path.join(KAT, 'paczki'))
    ap.add_argument('--sucho', action='store_true',
                    help='pokaz, co zostaloby opublikowane, i nie ruszaj GitHuba')
    args = ap.parse_args()

    global TOKEN
    TOKEN = 'x' if args.sucho else token()

    swoje = wydania.wlasne_wydanie(args.wersja, wydania.tagi_lokalne(REPO_KAT))
    pominiete = wydania.bez_wlasnego(args.wersja, wydania.tagi_lokalne(REPO_KAT))

    for p in pominiete:
        print('%-34s bez zmian w %s — kod jedzie w paczce calosci'
              % (p, args.wersja))

    for nazwa, tag, tytul, paczka in plan_wydan(args.wersja, swoje):
        opublikuj(nazwa, tag, tytul, paczka, args.wersja, args.katalog, args.sucho)


if __name__ == '__main__':
    main()
