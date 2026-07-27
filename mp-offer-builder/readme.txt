=== MP Offer Builder ===
Contributors: krzysiek2115op
Tags: oferty, pdf, woocommerce, cennik
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Kalkulacja cenowa, integracja z WooCommerce, generowanie ofert PDF.

== Description ==

Druga z trzech wtyczek procesu "formularz → oferta". Odbiera zakwalifikowanego
lead-a z MP Lead Intake, dobiera wariant cenowy na bazie cen WooCommerce,
generuje ofertę PDF wraz z numeracją i historią wersji.

== Installation ==

1. Wgraj katalog wtyczki do `wp-content/plugins/` (lub zainstaluj ZIP przez
   Wtyczki → Dodaj nową → Wyślij wtyczkę) i aktywuj.
2. Wymagane WooCommerce z WŁĄCZONYMI podatkami oraz zdefiniowaną krajową
   stawką VAT (dla polskiej firmy: stawka standardowa PL 23% w WooCommerce →
   Ustawienia → Podatki → Stawki standardowe).
3. WAŻNE — kraj bazowy sklepu musi odpowiadać krajowi stawki krajowej.
   Wtyczka pobiera stawkę VAT z kraju bazowego sklepu (WooCommerce →
   Ustawienia → Ogólne → „Adres sklepu / Kraj"). Dla polskiej firmy ustaw
   kraj bazowy na Polskę — inaczej oferta nie znajdzie krajowej stawki VAT.
   Prawdziwy polski sklep ma tę wartość domyślnie; wymaga uwagi tylko na
   świeżym/testowym WooCommerce (domyślnie USA).

== Changelog ==

= 1.0.4 =
* Ostateczna runda debug (8 równoległych sub-audytów + przegląd krzyżowy) — naprawione:
* [Wysoki] Podwójny klik / równoległy zapis tej samej oferty = JEDNA oferta: identyfikator
  żądania stały na formularz + blokada przycisku na czas zapisu; wyścig po stronie serwera
  zwraca istniejącą ofertę zamiast błędu (mp_offer_created nie odpala się dwa razy).
* [Ważne] Bezpieczeństwo: usunięty stored XSS przez nazwę produktu w prefillu edycji; pobieranie
  PDF ma twardą kontrolę ścieżki (realpath w katalogu prywatnym) i nagłówki no-cache.
* [Ważne] VAT: produkt zwolniony (tax_status=none) daje 0% zamiast stawki krajowej; kod kraju
  poprawny formatem, lecz nieznany WooCommerce (np. "DR") jest ODRZUCANY, nie udaje "poza UE" 0%.
* [Ważne] Promocje z harmonogramem: wygasła/nieaktywna promocja nie zaniża już ceny (is_on_sale).
* [Ważne] Strefa czasu: rok w numerze oferty i data w PDF z zegara sklepu (nie UTC) — poprawny
  reset licznika o północy sylwestrowej.
* [Średni] Render PDF w try/catch (błąd generatora = kontrolowany błąd, nie HTTP 500); nieudany
  COMMIT nie ogłasza "oferty-widma"; tymczasowy PDF sprzątany przy krytycznym błędzie i nieudanym
  zapisie pliku.
* [Średni] Dane klienta zawierające "{{...}}" nie blokują już oferty (neutralizacja nawiasów);
  kwoty i pola dodatkowo escapowane w PDF. Historia wersji po ponownej numeracji zawiera właściwy numer.
* Szkic z leada: pola przycięte do limitów kolumn (kraj do 2 znaków) + blokada per-lead przeciw
  duplikatowi szkicu. Nieznany wariant cenowy odrzucany jawnie (zamiast cichego 0% rabatu).
* Harness 108/108, PHPCS/WPCS 0/0. Świadome kompromisy: patrz docs/TESTY.md.

= 1.0.3 =
* Ostateczny audyt (4 równoległe sub-audyty + przegląd krzyżowy) — naprawione:
* [Ważne] VAT liczony PER KLASĘ PODATKOWĄ — koszyk mieszający stawki (np. 23% + 8%)
  wcześniej naliczał jedną stawkę na całości (zawyżenie/zaniżenie podatku). Rabat
  z sumy dzielony proporcjonalnie na klasy; stawka zapisywana per pozycja.
* [Średni] Cena ujemna produktu odrzucana jawnym błędem (wcześniej dawała ujemne
  kwoty w ofercie); usunięta martwa „flaga ceny zero".
* [Średni] Retry po kolizji numeru oferty liczy kolejny numer w pamięci (odporny na
  snapshot REPEATABLE READ) + więcej podejść — równoległe tworzenie ofert nie kończy
  się już błędem u „przegranego".
* [Średni] Deinstalacja usuwa teraz też wygenerowane PDF-y ofert, katalog prywatny,
  sekret nazw plików i capability ze wszystkich ról (nie tylko admina).
* Twardsza granica AJAX (capability na wejściu), limit długości statusu VAT z leada,
  indeks bazodanowy pod sortowanie listy ofert (DB_VERSION 0.7.0).
* Harness 102/102, PHPCS/WPCS 0/0.

= 1.0.2 =
* Ręczny formularz oferty ma teraz pole „VAT UE potwierdzony" — oświadczenie
  handlowca (klient z UE z ważnym VAT UE, np. zweryfikowanym w VIES) włącza
  odwrotne obciążenie (reverse_charge, 0% VAT) także dla ofert zakładanych
  ręcznie, nie tylko ze szkicu z leada.
* [Poprawność] Status VAT klienta (`vat_status`) jest teraz UTRWALANY w BD-2
  (nowa kolumna `client_vat_status`, DB_VERSION 0.6.0). Wcześniej gubił się
  przy każdym odczycie ze snapshotu — korekta oferty UE oraz dokończenie
  szkicu leada z ważnym VAT UE cicho spadały do stawki krajowej. Teraz
  reverse_charge przetrwa korektę i round-trip przez bazę.
* Instrukcja instalacji (== Installation ==): udokumentowany prerekwizyt
  „kraj bazowy sklepu = kraj stawki krajowej (PL)".

= 1.0.1 =
* Testy na żywym WordPressie/WooCommerce (WordPress Playground) — naprawione
  5 problemów niewykrywalnych w testach jednostkowych ze stubami:
* [Krytyczny] Stawka VAT: pobierana przez WC_Tax::get_base_tax_rates()
  (deterministycznie z bazy sklepu) zamiast get_rates() (zależnego od sesji
  klienta) — wcześniej każda oferta padała z „brak stawki VAT".
* [Krytyczny] Domyślne szablony oferty (PL+EN) zakładane przy aktywacji —
  bez nich świeża instalacja nie mogła wygenerować żadnej oferty.
* Kolumna „Akcje" w liście ofert (Edytuj / Pobierz PDF) — naprawiona
  widoczność metody renderującej.
* Ekran edycji wczytuje teraz istniejące pozycje oferty.
* Usunięty komunikat deprecation na PHP 8.3.
* Wszystkie 12 scenariuszy odbioru — PASS na żywym WooCommerce (docs/TESTY.md).

= 1.0.0 =
* Pipeline 11 działów kompletny: kontrakt/uprawnienia, integracja WooCommerce
  (ceny, stawki VAT), rabaty, mechanizm VAT (krajowy/odwrotne obciążenie/poza
  zakresem), szablon i treść oferty, numeracja i wersjonowanie, render PDF
  (Dompdf), zapis transakcyjny, odpowiedź i przekazanie zdarzenia.
* Panel wp-admin (lista ofert, budowa oferty, wyszukiwanie produktów) i
  chroniony endpoint pobierania PDF (nonce + capability + właściciel).
* Automatyczny szkic oferty z leada Pluginu 1 (hook `mp_lead_created`).
* Pełny audyt bezpieczeństwa i jakości (14 subagentów + dwa niezależne
  re-audyty), wszystkie ustalenia Critical/High/Medium naprawione: IDOR w
  3 miejscach, blokada optymistyczna przed nadpisaniem współbieżnej zmiany,
  ochrona przed duplikacją pozycji przy korekcie, kontrola wyników
  zapisu/kasowania w bazie, bezpieczne nazwy plików PDF, siatka bezpieczeństwa
  na twarde fatale PHP.
* Harness: 98/98 scenariuszy PASS. PHPCS (WPCS): 0 błędów/ostrzeżeń.

= 0.1.0 =
* Szkielet wtyczki — branch utworzony, architektura w trakcie ustalania.
