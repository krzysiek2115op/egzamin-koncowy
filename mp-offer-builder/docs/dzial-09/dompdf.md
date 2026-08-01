<!--
ŹRÓDŁO OFICJALNE — Dompdf, oficjalne README biblioteki.
Jeden plik na dział (zasada projektu).
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

## Opcja "chroot" (cytat, źródło: vendor/dompdf/dompdf/src/Options.php)

Docblock właściwości `Options::$chroot` (kod źródłowy zainstalowanej wersji —
patrz uzasadnienie u góry pliku: `vendor/` jest commitowany, więc to samo
źródło co uruchomione w produkcji):

> "dompdf's 'chroot' — Utilized by Dompdf's default file:// protocol URI
> validation rule. All local files opened by dompdf must be in a subdirectory
> of the directory or directories specified by this option. DO NOT set this
> value to '/' since this could allow an attacker to use dompdf to read any
> files on the server. This should be an absolute path."

Bez jawnego ustawienia, `Options` domyślnie ustawia chroot na katalog
instalacyjny SAMEGO Dompdf (`setChroot([$rootDir])` w konstruktorze) — co nie
pokrywa się z intencją tej wtyczki. Agent 9.1 jawnie ustawia
`chroot` = `MP_Offer_Builder_Storage::private_dir()`, ograniczając ewentualny
dostęp do plików lokalnych wyłącznie do własnego katalogu przechowywania
(obrona w głąb — szablon Działu 7 dziś nie odwołuje się do żadnych plików
lokalnych).

---

<!--
ŹRÓDŁO OFICJALNE — WordPress Developer Reference.
URL:     https://developer.wordpress.org/reference/functions/wp_generate_password/
Pobrano: 2026-07-24.
Dotyczy: MP_Offer_Builder_Storage::file_secret() — sekret per-instalację
wmieszany (HMAC) w nazwę pliku PDF na dysku (patrz docblock
final_pdf_path() w includes/class-mp-offer-builder-storage.php), żeby
nazwa pliku nie była odgadywalna z samego numeru/wersji oferty.
-->

# wp_generate_password() — dokumentacja źródłowa

## Opis (cytat)

"Generates a random password drawn from the defined set of characters."

Funkcja "Uses wp_rand() to create passwords with far less predictability
than similar native PHP functions like rand() or mt_rand()."

## Parametry (cytat)

1. `$length` (int, optional) — "The length of password to generate." Domyślnie: 12.
2. `$special_chars` (bool, optional) — "Whether to include standard special characters." Domyślnie: true.
3. `$extra_special_chars` (bool, optional) — "Whether to include other special characters. Used when generating secret keys and salts." Domyślnie: false.

## Wartość zwracana (cytat)

"string The random password."

## Zastosowanie w tym pluginie

`wp_generate_password( 64, false, false )` — 64 znaki, bez znaków
specjalnych (sekret trafia do `wp_options` i do `hash_hmac()`, nie musi
być "hasłem" w sensie UI). Generowany RAZ, przy pierwszym użyciu, i
zapisywany w opcji (autoload=false) — kolejne wywołania odczytują tę samą
wartość.
