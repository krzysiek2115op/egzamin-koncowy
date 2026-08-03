"""Audyt kompletnosci: czy scalenie do main, tagi i releasy niczego nie zgubily.

Porownuje BLOBY (sha zawartosci), nie daty ani sciezki — plik uznaje sie za
obecny tylko wtedy, gdy w main lezy dokladnie ta sama zawartosc.
"""
import collections, hashlib, io, json, os, re, subprocess, sys, urllib.request, zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import repo_wydania
import repo_wyjatki

REPO = "/home/krzysiek/3 pluginy 3 bazy danych "
SLUGI = ["mp-lead-intake", "mp-offer-builder", "mp-sales-workflow"]
OWNER_REPO = "krzysiek2115op/egzamin-koncowy"
bledy, ostrzezenia, ok = [], [], []

# Pliki korzenia SCALANE swiadomie: na main maja byc suma trzech wersji, wiec
# ich blob z zalozenia rozni sie od kazdej z galezi. Dla nich sprawdzamy nie
# rownosc, tylko czy scalona wersja zawiera to, co wnosila galaz.
SCALANE = {".gitignore", ".phpcs.xml.dist", "README.md", "composer.json", "composer.lock"}

# Niezgodnosci wersji w tagach JUZ OPUBLIKOWANYCH — patrz repo_wyjatki.
WYJATKI_WERSJI = repo_wyjatki.wczytaj()


def git(*a):
    return subprocess.run(["git", "-C", REPO] + list(a), capture_output=True, text=True).stdout


def drzewo(ref):
    d = {}
    for l in git("ls-tree", "-r", "--format=%(objectname) %(path)", ref).strip().split("\n"):
        if l:
            sha, path = l.split(" ", 1)
            d[path] = sha
    return d


print("=" * 72)
print("1. SCALENIE — czy kazdy plik z galezi wtyczek jest w main")
print("=" * 72)

main = drzewo("main")
main_po_sha = collections.defaultdict(list)
for p, s in main.items():
    main_po_sha[s].append(p)

# ZMIENIONE PO SCALENIU to NIE JEST utrata pliku.
#
# Porownanie idzie po blobach, wiec kazdy plik poprawiony na main po scaleniu
# przestaje sie zgadzac z wersja zamrozona na galezi wtyczki. Wczesniej wszystkie
# takie pliki ladowaly w kubelku "BRAK W MAIN" i podnosily BLAD — czyli narzedzie
# meldowalo "scalenie zgubilo pliki" za kazdym razem, gdy ktos cokolwiek naprawil.
# Po 1.3.4 bylo to 48 z 51 zgloszen. Alarm, ktory zapala sie zawsze, przestaje
# cokolwiek znaczyc i uczy, zeby go ignorowac — a wtedy nie zauwazymy prawdziwej
# utraty.
#
# Utrata to: sciezki NIE MA w main i tej samej TRESCI tez nie ma nigdzie indziej.
for slug in SLUGI:
    galaz = drzewo("refs/heads/" + slug)
    brak, przeniesione, scalone, zmienione = [], [], [], []
    for p, sha in galaz.items():
        if main.get(p) == sha:
            continue
        if sha in main_po_sha:
            przeniesione.append((p, main_po_sha[sha][0]))
        elif p in SCALANE and p in main:
            scalone.append(p)
        elif p in main:
            zmienione.append(p)
        else:
            brak.append(p)
    print("\n  %s: %d plikow na galezi" % (slug, len(galaz)))
    print("     w main, tresc identyczna   : %d" % (len(galaz) - len(brak) - len(przeniesione) - len(scalone) - len(zmienione)))
    print("     w main, tresc poprawiona   : %d  <- rozwoj po scaleniu, nie utrata" % len(zmienione))
    print("     scalone swiadomie          : %d (%s)" % (len(scalone), ", ".join(sorted(scalone))))
    print("     w main pod INNA sciezka    : %d" % len(przeniesione))
    for a, b in sorted(przeniesione)[:4]:
        print("        %s -> %s" % (a, b))
    if len(przeniesione) > 4:
        print("        ... i %d dalszych (paczka kliencka)" % (len(przeniesione) - 4))
    if brak:
        # UWAGA, nie BLAD — i to nie jest lagodzenie, tylko zgodnosc wagi
        # z dowodem. Porownujemy ZAMROZONA galaz z RUCHOMYM mainem. Plik
        # przeniesiony, a POTEM poprawiony, gubi zarowno sciezke, jak i bloba,
        # wiec wyglada identycznie jak plik zgubiony. Tego rozroznienia to
        # narzedzie zrobic nie moze i nie ma prawa orzekac utraty, ktorej nie
        # udowodnilo. Czlowiek sprawdza kazda pozycje raz i zamyka temat.
        ostrzezenia.append(
            "%s: %d plikow galezi nieodnalezionych w main ani po sciezce, ani po tresci "
            "— sprawdz, czy zostaly przeniesione i pozniej poprawione" % (slug, len(brak)))
        print("     NIEODNALEZIONE             : %d  <- do potwierdzenia przez czlowieka" % len(brak))
        for p in sorted(brak)[:20]:
            print("        ? %s" % p)
    else:
        ok.append("%s: zaden plik galezi nie zaginal w main" % slug)
        print("     NIE MA W MAIN              : 0  <- nic nie zaginelo")

print("\n  --- czy scalone pliki korzenia zawieraja wklad kazdej galezi ---")
phpcs = git("show", "main:.phpcs.xml.dist")
gi = git("show", "main:.gitignore")
for slug in SLUGI:
    czy = ["<file>%s</file>" % slug in phpcs, '<element value="%s"/>' % slug in phpcs]
    print("     .phpcs.xml.dist  %-20s zakres:%s  text_domain:%s"
          % (slug, "TAK" if czy[0] else "NIE", "TAK" if czy[1] else "NIE"))
    if not all(czy):
        bledy.append(".phpcs.xml.dist na main nie obejmuje %s" % slug)
if "*/vendor/*" not in phpcs:
    ostrzezenia.append(".phpcs.xml.dist nie wyklucza vendor/")
for slug in SLUGI:
    if "/paczka-klienta/*/%s/" % slug not in gi:
        ostrzezenia.append(".gitignore nie ma reguly paczki dla %s" % slug)
lock_ok = git("show", "main:composer.json"), git("show", "main:composer.lock")
import json as _j
cj, cl = _j.loads(lock_ok[0]), _j.loads(lock_ok[1])
zgodne = all(_j.loads(git("show", "%s:composer.json" % b)).get("require-dev") == cj.get("require-dev") for b in SLUGI)
print("     composer.json    require-dev identyczne jak na trzech galeziach: %s" % ("TAK" if zgodne else "NIE"))
if not zgodne:
    bledy.append("composer.json na main ma inne require-dev niz galezie (lock bedzie nieaktualny)")
else:
    ok.append("composer.json/lock spojne")

print("\n" + "=" * 72)
print("2. TAGI — czy kazdy tag wskazuje istniejacy commit i jest na remote")
print("=" * 72)

lokalne = set(x for x in git("tag", "-l").split() if x)
zdalne = set(re.sub(r"\^\{\}$", "", l.split("refs/tags/")[1])
             for l in git("ls-remote", "--tags", "origin").strip().split("\n") if "refs/tags/" in l)
tylko_lokalnie = sorted(lokalne - zdalne)
tylko_zdalnie = sorted(zdalne - lokalne)
print("  tagow lokalnie: %d, na remote: %d" % (len(lokalne), len(zdalne)))
if tylko_lokalnie:
    bledy.append("%d tagow nie wypchnietych" % len(tylko_lokalnie))
    print("  NIE WYPCHNIETE: %s" % ", ".join(tylko_lokalnie))
else:
    ok.append("wszystkie tagi lokalne sa na remote")
    print("  wszystkie lokalne tagi sa na remote")
if tylko_zdalnie:
    ostrzezenia.append("%d tagow tylko na remote: %s" % (len(tylko_zdalnie), ", ".join(tylko_zdalnie[:5])))
    print("  tylko na remote: %s" % ", ".join(tylko_zdalnie[:5]))

martwe = [t for t in sorted(lokalne) if not git("rev-parse", "--verify", "-q", t + "^{commit}").strip()]
if martwe:
    bledy.append("tagi bez commita: %s" % ", ".join(martwe))
print("  tagow wskazujacych nieistniejacy commit: %d" % len(martwe))

print("\n" + "=" * 72)
print("3. WERSJE — naglowek wtyczki kontra tag kontra readme.txt")
print("=" * 72)

for slug in SLUGI:
    tagi = sorted([t for t in lokalne if re.fullmatch(r"%s/v\d+\.\d+\.\d+" % re.escape(slug), t)],
                  key=lambda t: [int(x) for x in t.rsplit("/v", 1)[1].split(".")])
    zle, przyjete = [], []
    for t in tagi:
        wer = t.rsplit("/v", 1)[1]
        glowny = git("show", "%s:%s/%s.php" % (t, slug, slug))
        m = re.search(r"^ \* Version:\s+(\S+)", glowny, re.M)
        naglowek = m.group(1) if m else "?"
        rt = git("show", "%s:%s/readme.txt" % (t, slug))
        m2 = re.search(r"^Stable tag:\s*(\S+)", rt, re.M)
        stable = m2.group(1) if m2 else "?"
        if naglowek != wer or stable != wer:
            opis = "%s: naglowek=%s stable=%s" % (t, naglowek, stable)
            if repo_wyjatki.przyjeta(t, naglowek, stable, WYJATKI_WERSJI):
                przyjete.append(opis)
            else:
                zle.append(opis)
    print("  %-20s %2d tagow wersyjnych, niezgodnosci: %d%s"
          % (slug, len(tagi), len(zle),
             "  (+%d przyjetych)" % len(przyjete) if przyjete else ""))
    for z in zle:
        print("     ! %s" % z)
    for z in przyjete:
        print("     ~ %s" % z)
        print("       przyjete: %s" % repo_wyjatki.powod(z.split(":")[0], WYJATKI_WERSJI))
    if zle:
        bledy.append("%s: niezgodnosc wersji w %d tagach" % (slug, len(zle)))
    elif przyjete:
        ok.append("%s: wersje zgodne poza %d tagiem przyjetym swiadomie (rejestr)"
                  % (slug, len(przyjete)))
    else:
        ok.append("%s: wersje zgodne we wszystkich tagach" % slug)

print("\n" + "=" * 72)
print("4. RELEASY — czy kazdy tag wersyjny ma release i czy zalaczniki sa cale")
print("=" * 72)


def tok():
    for l in io.open(os.path.expanduser("~/.git-credentials"), encoding="utf-8"):
        m = re.match(r"https://([^:]+):([^@]+)@github\.com", l.strip())
        if m:
            return m.group(2)


T = tok()


def api(u):
    r = urllib.request.Request(u)
    r.add_header("Authorization", "Bearer " + T)
    r.add_header("Accept", "application/vnd.github+json")
    return json.loads(urllib.request.urlopen(r).read().decode())


rel = api("https://api.github.com/repos/%s/releases?per_page=100" % OWNER_REPO)
tagi_rel = {r["tag_name"]: r for r in rel}
wersyjne = sorted(t for t in lokalne if re.fullmatch(r"(%s)/v\d+\.\d+\.\d+" % "|".join(SLUGI), t))
brak_rel = [t for t in wersyjne if t not in tagi_rel]
print("  tagow wersyjnych: %d, releasow: %d" % (len(wersyjne), len(rel)))
if brak_rel:
    bledy.append("tagi wersyjne bez release: %s" % ", ".join(brak_rel))
    print("  BEZ RELEASE: %s" % ", ".join(brak_rel))
else:
    ok.append("kazdy tag wersyjny ma release")
    print("  kazdy tag wersyjny ma swoj release")

# Wydania PROJEKTOWE (`vX.Y.Z`, bez sluga) sa zamierzone — obejmuja komplet
# trzech wtyczek. Zglaszanie ich jako "release bez tagu wersyjnego" bylo falszywym
# alarmem powtarzajacym sie przy kazdym wydaniu.
projektowe = sorted(t for t in tagi_rel if re.fullmatch(r"v\d+\.\d+\.\d+", t))
nadmiarowe = [t for t in tagi_rel if t not in wersyjne and t not in projektowe]
print("  wydan projektowych (komplet trzech wtyczek): %d" % len(projektowe))
if nadmiarowe:
    ostrzezenia.append("release bez tagu wersyjnego: %s" % ", ".join(nadmiarowe))
puste = [r["tag_name"] for r in rel if len((r["body"] or "").strip()) < 80]
if puste:
    ostrzezenia.append("releasy z krotkim opisem: %s" % ", ".join(puste))
print("  releasow z opisem ponizej 80 znakow: %d" % len(puste))

print("\n" + "=" * 72)
print("5. ZALACZNIKI — czy paczki zgadzaja sie z drzewem gita")
print("=" * 72)

WY = sys.argv[1] if len(sys.argv) > 1 else None

# Wersja badana byla wpisana na sztywno ("1.3.2") w DWOCH miejscach tej sekcji.
# Skutek: od wydania 1.3.3 sekcja sprawdzala paczki SPRZED dwoch wydan i milczala
# o biezacym — kontrola, ktora nie mogla zadzialac dla tego, co akurat wysylamy.
# Teraz bierzemy najwyzszy tag wersyjny, jaki widzi sekcja 3, albo wartosc podana
# jako drugi argument (przydatne, gdy chcesz sprawdzic starsze wydanie).
def najwyzsza_wersja():
    numery = set()
    for t in wersyjne:
        m = re.search(r"/v(\d+)\.(\d+)\.(\d+)$", t)
        if m:
            numery.add(tuple(int(x) for x in m.groups()))
    return ".".join(str(x) for x in max(numery)) if numery else None


WERSJA = sys.argv[2] if len(sys.argv) > 2 else najwyzsza_wersja()
print("  badana wersja: %s%s" % (WERSJA, "" if len(sys.argv) > 2 else " (najwyzszy tag wersyjny)"))

def wersja_w_naglowku(ref, slug):
    """Wersja zadeklarowana w pliku glownym wtyczki pod wskazanym refem."""
    tresc = git("show", "%s:%s/%s.php" % (ref, slug, slug))
    m = re.search(r"^ \* Version:\s+(\S+)", tresc, re.M)

    return m.group(1) if m else None


for slug in SLUGI:
    r = tagi_rel.get("%s/v%s" % (slug, WERSJA))
    if not r:
        # Wtyczka bez zmian nie dostaje nowego numeru — patrz repo_wydania.
        wlasna = wersja_w_naglowku("v" + WERSJA, slug)
        ma_swoje = bool(tagi_rel.get("%s/v%s" % (slug, wlasna))) if wlasna else False

        if repo_wydania.wolno_pominac_wydanie(WERSJA, wlasna, ma_swoje):
            print("  %-20s bez wlasnego wydania w %s — zostaje na %s (wydanie tamtej wersji istnieje)"
                  % (slug, WERSJA, wlasna))
            ok.append("%s: brak zmian w %s, zostaje na %s — kod jest w opublikowanym wydaniu"
                      % (slug, WERSJA, wlasna))
            continue

        bledy.append("%s: brak release dla wersji %s" % (slug, WERSJA))
        print("  %-20s BRAK RELEASE dla %s" % (slug, WERSJA))
        continue
    if not r:
        continue
    nazwy = sorted(a["name"] for a in r["assets"])
    print("  %-20s zalaczniki: %s" % (slug, ", ".join(nazwy)))
    if len(nazwy) != 2:
        bledy.append("%s: oczekiwano 2 zalacznikow, jest %d" % (slug, len(nazwy)))

if WY and os.path.isdir(WY):
    for slug in SLUGI:
        z = os.path.join(WY, "%s.zip" % slug)
        if not os.path.exists(z):
            continue
        w_zip = {}
        for i in zipfile.ZipFile(z).infolist():
            if not i.is_dir():
                w_zip[i.filename] = hashlib.sha1(
                    b"blob %d\0" % i.file_size + zipfile.ZipFile(z).read(i.filename)).hexdigest()
        ref = "%s/v%s" % (slug, WERSJA)
        w_gicie = {p: s for p, s in drzewo(ref).items() if p.startswith(slug + "/")}
        rozne = [p for p in w_gicie if w_zip.get(p) != w_gicie[p]]
        brakuje = [p for p in w_gicie if p not in w_zip]
        nadmiar = [p for p in w_zip if p not in w_gicie]
        print("     %s: plikow w gicie %d, w ZIP %d, roznych %d, brakuje %d, nadmiarowych %d"
              % (os.path.basename(z), len(w_gicie), len(w_zip), len(rozne), len(brakuje), len(nadmiar)))
        if rozne or brakuje or nadmiar:
            bledy.append("%s: ZIP nie zgadza sie z drzewem gita" % slug)
        else:
            ok.append("%s: ZIP identyczny z drzewem tagu" % slug)

print("\n" + "=" * 72)
print("WYNIK")
print("=" * 72)
for b in bledy:
    print("  BLAD        %s" % b)
for w in ostrzezenia:
    print("  UWAGA       %s" % w)
print("\n  potwierdzonych zgodnosci: %d" % len(ok))
for o in ok:
    print("     + %s" % o)
print("\n  WERDYKT: %s" % ("BLEDY DO NAPRAWY" if bledy else "wszystko sie zgadza"))
sys.exit(1 if bledy else 0)
