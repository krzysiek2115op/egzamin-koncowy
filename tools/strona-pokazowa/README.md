# Strona pokazowa dla egzaminatora

Jeden odnośnik, po którego kliknięciu w przeglądarce staje kompletny WordPress
z **trzema wtyczkami tego projektu**, WooCommerce skonfigurowanym po polsku
i jednym przebiegiem procesu widocznym we wszystkich trzech bazach.

**Odnośnik:**

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/krzysiek2115op/egzamin-koncowy/main/tools/strona-pokazowa/blueprint.json
```

Nic nie jest hostowane: WordPress uruchamia się w przeglądarce oglądającego
(WebAssembly), a dane żyją tylko w jego karcie. Zamknięcie karty kasuje wszystko,
więc kolejne wejście zawsze zaczyna od czystego stanu — i nikt nie zepsuje
demonstracji drugiej osobie.

## Co widać po wejściu

Strona otwiera się na **Procesach sprzedażowych** (wtyczka 3). Oglądający jest
zalogowany jako administrator.

W menu panelu są **trzy** pozycje tego projektu — po jednej na wtyczkę:

| Gdzie | Co tam jest |
|---|---|
| Leady (wtyczka 1) | Zgłoszenia z formularza wprost z BD-3: firma, NIP, kontakt, kraj, segment, **punktacja**, status VAT, handlowiec i liczba ofert |
| Procesy sprzedażowe (wtyczka 3) | Proces „Zakłady Metalowe Wisła", przypisany handlowiec Anna Kowalska, dziennik aktywności |
| MP Offer Builder (wtyczka 2) | Oferta `OF/2026/000001` z gotowym PDF-em do pobrania i przyciskiem „Zatwierdź" |
| `/zapytanie-ofertowe/` (wtyczka 1) | Publiczny formularz — wejście do całego procesu |

**Ekran „Leady" pokazuje punktację** — liczbę, którą wtyczka 1 wylicza dla
każdego zgłoszenia (ważny numer VAT, aktywna firma, podany telefon, zgoda
marketingowa, segment). Do wersji 1.3.6 była liczona i nie pokazywał jej żaden
widok; zlecenie wymienia scoring jako element kwalifikacji leada.

**Widok zależy od roli.** Administrator i manager sprzedaży widzą wszystkie leady,
handlowiec — tylko własne. Żeby to zobaczyć, zaloguj się jako `handlowiec`
(hasło ustawiane losowo przy zasiewie; najprościej podejrzeć rolę na liście
użytkowników).

### Dwie rzeczy warte kliknięcia

**1. Wyślij formularz.** Otwórz `/zapytanie-ofertowe/`, wypełnij i wyślij.
Natychmiast po wysłaniu w „Procesach sprzedażowych" pojawia się nowy proces
z przypisanym handlowcem, a w „MP Offer Builder" nowy szkic oferty. Ten jeden
klik pokazuje wszystkie trzy bazy naraz: proces nie mógłby powstać, gdyby
wtyczka 1 nie zapisała leada do BD-3 i nie wystawiła zdarzenia.

Uwaga: **jedna firma = jedno zgłoszenie**. Para „kraj + NIP" jest w BD-3 kluczem
niepowtarzalnym, więc powtórne wysłanie tego samego NIP-u zostanie odrzucone.
Do własnej próby użyj innego numeru niż `5252248481` (ten jest już zajęty przez
zasiane zgłoszenie).

**2. Zatwierdź ofertę.** W „MP Offer Builder" kliknij „Zatwierdź" przy ofercie
`OF/2026/000001`. Proces we wtyczce 3 przechodzi z „oferta robocza" na „oferta
wysłana" i sam zakłada dwa zadania follow-up — na trzeci i siódmy dzień. Zdarzenie
przechodzi z wtyczki 2 do wtyczki 3 bez żadnego ręcznego kroku.

## Skąd bierze się kod

Wtyczki instalują się z paczek wydania **1.3.6**, z archiwów pod tagiem
`demo-egzamin`. To te same bajty, co w paczkach dla klienta — różni je wyłącznie
brak zewnętrznej warstwy archiwum, której WordPress Playground nie rozpakuje.
Strona pokazowa uruchamia więc ten sam kod, który dostaje klient.

## Czego demonstracja NIE pokazuje

- **Więzów obcych w bazie.** Playground używa SQLite, a wtyczka 3 zakłada je
  tylko na InnoDB. Kod jest na to przygotowany — więzy są dodatkiem, a spójności
  pilnuje transakcja w Dziale 8 — ale na produkcyjnym MySQL-u baza pilnuje jej
  dodatkowo sama.
- **Wysyłki poczty.** W przeglądarce nie ma serwera SMTP, więc powiadomienia
  zostają w kolejce ze statusem `queued`. Widać, że powstały i do kogo są
  adresowane, ale nigdzie nie wychodzą.
- **Odpowiedzi z VIES i Białej listy MF.** Status VAT zasianego zgłoszenia to
  `pending` — wtyczka celowo nie zgaduje, gdy nie dostała rozstrzygnięcia.

## Jak to zbudować od nowa

```sh
npx @wp-playground/cli@latest server --blueprint=blueprint.json
```

Zmiana zasiewu: krok `runPHP` na końcu `blueprint.json`. Zmiana wersji wtyczek:
podmienić archiwa pod tagiem `demo-egzamin` (tag NIE jest wersyjny i nie dotyczy
wydań produktu — te mają tagi `v1.3.6` oraz `<wtyczka>/v1.3.6`).

---

## Skąd bierze się motyw

Do wersji 1.3.6 motyw „Kredyt Kompas" istniał **wyłącznie na maszynie autora**
— archiwum w wydaniu `demo-egzamin` powstawało z katalogu spoza repozytorium.
Nikt poza autorem nie mógł go odtworzyć ani sprawdzić, co dokładnie ogląda
egzaminator. Źródła leżą teraz w [`motyw/`](motyw/) i wydanie budowane jest z nich.

Przebudowa archiwum:

```
cd tools/strona-pokazowa
python3 -c "import shutil; shutil.make_archive('/tmp/kredyt-kompas','zip','motyw','.')"
```

(`zip` na tej maszynie nie ma — stąd Python.)

### Co w motywie poprawiono przy 1.3.7

- **„Panel" zniknął z nawigacji.** Pozycja prowadziła wprost do logowania
  WordPressa, a komentarz przy samej podstronie opisywał ją jako „dyskretne
  wejście, którego nie ma w nawigacji". Kod i jego opis mówiły dwie różne rzeczy;
  prawdziwy był opis. Sama podstrona `/panel/` zostaje.
- **Martwe formularze.** Dwa z siedmiu miały
  `action="https://formspree.io/f/YOUR_FORM_ID"` — nigdy nieuzupełniony
  placeholder, widoczny w źródle strony. Wszystkie siedem to atrapy obsługiwane
  po stronie przeglądarki i teraz wyglądają tak samo.
- **Ślad po innym projekcie.** Komentarz w `functions.php` powoływał się na
  „Importer Motocykli / IAAI" — wtyczkę z zupełnie innego zlecenia.
- **Lokalizacja menu.** Motyw rejestruje `register_nav_menu( 'glowne' )` i dokłada
  pozycje z menu WordPressa. Bez tego MP Lead Intake nie miał gdzie dopisać swojej
  podstrony i sięgał po ścieżkę awaryjną: bufor HTML na **każdej** stronie
  frontendu i wstrzyknięcie odnośnika w `<nav>` wyrażeniem regularnym.
- **Jeden NIP zamiast dwóch.** Zasiew zapisywał firmę do BD-3 z numerem
  `5252248481`, a przy budowie oferty podawał `1234563218` — ta sama firma
  z dwoma numerami w jednym przebiegu, który ma pokazywać spójność danych.

### Co w motywie poprawiono po 1.3.9 (motyw 1.0.2)

**WordPress w Playground nie stoi w korzeniu domeny.** Playground serwuje witrynę
pod prefiksem ścieżki `/scope:<id>/` — dla WordPressa to zwykła instalacja
w podkatalogu. Motyw zakładał korzeń domeny w dwóch miejscach i oba dawały
odnośniki prowadzące w pustkę:

- **Pozycja menu od wtyczki 1.** Motyw brał z pozycji menu **całą** ścieżkę URL
  jako „slug", a potem doklejał ją z powrotem do `home_url()`. W korzeniu domeny
  obie operacje się znoszą; w podkatalogu prefiks instalacji liczony jest dwa razy
  — `/scope:0.77/scope:0.77/zapytanie-ofertowe/`. Pozostałe pozycje nawigacji są
  w motywie „na sztywno" i budują się z samego sluga, więc działały. Nie działała
  dokładnie ta jedna, która przychodzi z menu WordPressa: **podstrona formularza**.
  Awaria wyglądała więc na usterkę tej podstrony, a nie nawigacji.
- **19 odnośników w treści stron.** Fragmenty w `parts/*.html` pochodzą ze
  statycznego oryginału i mają postać `href="/kontakt/"`. Wiodący ukośnik znaczy
  „korzeń domeny", a nie „korzeń witryny". Przeliczane są teraz przy renderowaniu
  (`kk_tresc_z_adresami()`), a nie przy zasiewie — adres witryny w Playground
  zmienia się między sesjami, więc adres wpisany do bazy byłby prawdziwy raz.

Trzecia rzecz wyszła dopiero wtedy, gdy test pojechał na **świeżym** WordPressie
w CI: zasiew ustawiał ładne permalinki przez `update_option()`, co zapisuje opcję,
ale zostawia obiekt przepisywania w stanie sprzed zmiany — do końca tego żądania
WordPress dalej buduje adresy `?page_id=`. Na produkcyjnym demo nie było tego
widać, bo zasiew i pierwsze wejście na stronę to dwa różne żądania; kod działał
przez okoliczność, a nie przez to, co robi. Teraz idzie przez
`WP_Rewrite::set_permalink_structure()`.

Motyw miał też **dwie różne wersje we własnych plikach**: `style.css` deklarował
1.0.0, a `KK_VERSION` w `functions.php` — 1.0.1. Obie mówią teraz 1.0.2.

Regresja tego nie łapała, bo demo nie miało **żadnego** testu, a lokalny przebieg
stawiał je zawsze w korzeniu (`localhost:8080`), gdzie ten błąd jest niewidoczny.
Test [`tests/motyw-poza-korzeniem.php`](tests/motyw-poza-korzeniem.php) przestawia
`home`/`siteurl` na podkatalog i sprawdza jedno i drugie; `regresja.sh` uruchamia
go na końcu, aktywując motyw i przywracając poprzedni.
