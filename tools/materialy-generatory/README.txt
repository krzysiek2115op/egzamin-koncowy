Generatory materiałów klienckich (schematy draw.io + PDF-y) — zestaw pierwotny.

STATUS (01.08.2026). Ten katalog jest STARSZY niż `mp-*/materialy-src/` i został
przez nie w większości zastąpiony. Trafił do repozytorium z jednego powodu:
zawiera jedyne zachowane źródło jednego z materiałów Pluginu 1.

  Plugin 3  -> mp-sales-workflow/materialy-src/   (komplet 6 źródeł + build.sh)
  Plugin 2  -> mp-offer-builder/materialy-src/    (komplet 6 źródeł + build.sh)
  Plugin 1  -> BRAK katalogu materialy-src

Materiały Pluginu 1 wydane klientowi (paczka-klienta/mp-lead-intake/materialy,
9 plików) są więc odtwarzalne tylko częściowo: `p1/jak-dziala.json` daje
`jak-dziala-plugin.pdf`, a źródeł obu instrukcji instalacji i trzech schematów
nie ma nigdzie. Gdyby schemat BD-3 się zmienił, materiał trzeba by napisać od
nowa — dokładnie ta sytuacja wyszła w Pluginie 3 przy usunięciu kolumny
`result_json`: schemat dla klienta pokazywał ją jeszcze po wydaniu 1.3.4. To jest
znany dług, nie przeoczenie; zapisany tutaj, żeby nie odkrywać go po raz drugi
pod presją.

`gen-doc.js` jest identyczny z `mp-sales-workflow/materialy-src/gen-doc.js` —
kopia zostaje, żeby ten katalog dało się uruchomić samodzielnie.

Zależności: npm install pdf-lib @pdf-lib/fontkit ; systemowe: rsvg-convert,
            pdfunite, DejaVuSans.ttf.
Diagramy:  node gen-dbschema.js OUT ; node gen-nietech.js OUT ; node gen-tech.js OUT
           -> OUT.svg + OUT.drawio ; potem: rsvg-convert -f pdf -o OUT.pdf OUT.svg
Dokumenty: node gen-doc.js content.json out.pdf   (content = bloki h1/h2/p/bullet/num/note/space/pagebreak)
Jedno źródło (spec w .js) -> .drawio (edytowalny) + SVG -> PDF (wektor). Zero rozjazdu.
