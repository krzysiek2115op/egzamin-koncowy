<!--
ŹRÓDŁO OFICJALNE — Dompdf, oficjalne README biblioteki.
URL:     https://github.com/dompdf/dompdf (README.md, gałąź master)
Pobrano: 2026-07-24.
Dotyczy: Dział 9 — Agent 9.1 "render" (MP_OB_D9_Agent_Render) i Agent 9.2
"kontrola" (MP_OB_D9_Agent_Control). Zależność RUNTIME zainstalowana realnie
(composer require dompdf/dompdf ^3.1, commit vendor/ — patrz uzasadnienie
w docblocku class-mp-ob-department-09.php).
-->

# Dompdf — dokumentacja źródłowa

## Podstawowe użycie (cytat z README)

```php
use Dompdf\Dompdf;

$dompdf = new Dompdf();
$dompdf->loadHtml('hello world');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream();
```

## Fonty (cytat, znaczenie dla diakrytyków ą ć ę ł ń ó ś ź ż)

Dompdf domyślnie dołącza "DejaVu TrueType fonts", które zapewniają "decent
Unicode character coverage" (przyzwoite pokrycie Unicode). Żeby ich użyć,
wystarczy odwołać się do nich w arkuszu stylów, np. `body { font-family:
DejaVu Sans; }` — TAK właśnie robi Dział 7 (treść dokumentu, bez własnego
ustawienia fontu = domyślny DejaVu Sans, embedded, nie systemowy).

Potwierdzone fizycznie w `vendor/dompdf/dompdf/lib/fonts/`: pliki
`DejaVuSans*.ttf` są częścią paczki — spełnia to wprost wymóg diagramu
"fonty DejaVu osadzone" bez żadnej dodatkowej konfiguracji.

## Metadane PDF (addInfo) i weryfikacja treści — decyzja projektowa

`Dompdf::addInfo(string $label, string $value)` dopisuje pole do słownika
`/Info` wygenerowanego PDF (Title, Subject, albo DOWOLNY własny klucz).
Zweryfikowane eksperymentalnie (2026-07-24): CPDF (domyślny backend Dompdf)
zapisuje wartości `/Info` jako zwykły, NIESKOMPRESOWANY tekst PDF w kodowaniu
UTF-16BE (z BOM `\xFE\xFF`) — w przeciwieństwie do strumieni treści strony
(operatory rysowania tekstu), które SĄ kompresowane (FlateDecode) i — przy
osadzonych/podzbiorowych fontach TrueType (Identity-H) — używają
zremapowanych identyfikatorów glifów, więc nie da się z nich odzyskać
czytelnego tekstu prostym przeszukiwaniem bajtów (wymagałoby to pełnego
parsera PDF, jak np. smalot/pdfparser — kolejna ciężka zależność runtime,
świadomie NIE dodana wyłącznie dla tej jednej kontroli).

Dlatego krytyk "zawartość-pdf" (kryt. Działu 9: "numer i kwota wyciągnięte
z pliku = zgodne z kopertą") weryfikuje przez METADANE, nie przez treść
strony: Agent 9.1 zapisuje `Title` = numer oferty ORAZ własny klucz
`MPOfferGross` = `gross_grosze` (string), Agent 9.2 wyciąga OBIE wartości
z surowych bajtów wygenerowanego pliku PDF (dekodując UTF-16BE) i porównuje
z "kopertą" (danymi w kontekście pipeline'u) — realna, deterministyczna
weryfikacja treści wygenerowanego pliku, bez dodatkowej zależności.
