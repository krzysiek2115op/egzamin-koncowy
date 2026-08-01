# Wdrożenie produkcyjne — MP Sales Workflow (LP.3)

Checklista dla osoby, która wgrywa wtyczkę na serwer klienta. Dotyczy warstwy
**infrastruktury** — rzeczy, których kod wtyczki nie jest w stanie sobie
zapewnić sam.

> **Dlaczego akurat ta wtyczka ma osobną checklistę.** LP.1 i LP.2 przyjmują dane
> i produkują dokument — działają do wewnątrz. LP.3 jako jedyna **wysyła ruch na
> zewnątrz** (e-mail do klienta) i jako jedyna **udostępnia plik osobie bez konta
> w WordPressie** (link do PDF). Obie te powierzchnie stoją na ustawieniach spoza
> kodu: kluczu w `wp-config.php`, konfiguracji SMTP i regule serwera WWW. Wtyczka
> wgrana bez nich uruchomi się bez błędu, ale albo przestanie wysyłać e-maile do
> klientów, albo wystawi katalog z ofertami do publicznego pobrania.

---

## 0. Kolejność wdrożenia

1. **Kopia zapasowa bazy** (patrz §6) — aktualizacja podnosi schemat do `0.4.0`.
2. Stałe w `wp-config.php` (§1) — **przed** aktywacją.
3. HTTPS (§2), cron (§3), poczta (§4), blokada katalogu PDF (§5).
4. Wgranie i aktywacja wtyczki.
5. Smoke-test uprawnień (§7).

---

## 1. Stałe w `wp-config.php` (obowiązkowe)

Obie wartości mają mieć **minimum 32 bajty losowe** i muszą trafić do
`wp-config.php` — nigdy do kodu wtyczki ani do bazy (inwariant I-6).

```php
/* --- MP Sales Workflow --- */
define( 'MP_HASH_PEPPER',  '…64 znaki hex…' );   // sól do haszowania IP w dzienniku technicznym
define( 'MP_SW_LINK_KEY',  '…64 znaki hex…' );   // klucz podpisu linków do ofert PDF
```

Generowanie (każda stała **osobno**, nie ta sama wartość dwa razy):

```bash
openssl rand -hex 32
```

Plik `wp-config.php` powinien mieć uprawnienia `640` (lub `600`) i właściciela
użytkownika serwera WWW.

### Co się dzieje, gdy stałej brakuje

| Stała | Brak = |
|---|---|
| `MP_SW_LINK_KEY` | Wtyczka **nie zbuduje** linku do oferty. Krytyk K7.2 odrzuci powiadomienie do klienta (`unresolved_markers`, 500) — czyli **e-maile do klientów przestaną wychodzić**. Celowo: lepszy brak wysyłki niż wysyłka z martwym odnośnikiem. |
| `MP_HASH_PEPPER` | Dziennik techniczny zapisuje pusty hasz IP zamiast haszować bez soli. Celowo: hasz IPv4 bez soli odwraca się tęczową tablicą w kilka minut, więc byłby zapisem adresu IP wprost — a tego RODO tu nie uzasadnia. |

### Rotacja `MP_SW_LINK_KEY`

Zmiana klucza **unieważnia wszystkie linki wysłane wcześniej** (ważność linku to
14 dni). Klient, który dostał ofertę wczoraj, zobaczy „Link jest nieprawidłowy".
Rotuj tylko przy podejrzeniu wycieku i uprzedź handlowców, że trzeba wysłać
oferty ponownie.

---

## 2. HTTPS na całej witrynie

Wymagane, nie zalecane. Link do oferty niesie podpis HMAC w adresie — po HTTP
podpis wędruje jawnie i każdy pośrednik po drodze może go przechwycić i pobrać
ofertę. To samo dotyczy ciasteczka logowania handlowca.

- Certyfikat na domenie głównej, przekierowanie 301 z `http://` na `https://`.
- `WP_HOME` i `WP_SITEURL` ze schematem `https://`.
- Nagłówek HSTS na poziomie serwera:

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

---

## 3. Cron: `DISABLE_WP_CRON` + systemowy crontab

Domyślny `wp-cron` WordPressa odpala się **przy okazji odwiedzin strony**. Na
witrynie z małym ruchem oznacza to, że zadania follow-up nie odpalą się w porę,
a przy dużym — że odpalą się kilka razy naraz.

### 3.1. Wyłącz cron „przy okazji"

```php
define( 'DISABLE_WP_CRON', true );
```

### 3.2. Wpis w systemowym crontabie

Zadania wtyczki: `mp_sw_sweep_tasks` (co godzinę), `mp_sw_retention` (raz
dziennie), `mp_sw_run_queue` (jednorazowe, planowane na +60 s po zapisie, gdy w
kolejce zostały wiadomości). Kolejka e-mail wychodzi więc z opóźnieniem równym
**odstępowi w crontabie** — dlatego co minutę, nie co 15.

```cron
* * * * * cd /var/www/html && /usr/local/bin/wp cron event run --due-now --quiet >/dev/null 2>&1
```

WP-CLI ustawia `DOING_CRON`, więc wartownik kontekstu w `MP_SW_Cron::sweep_tasks()`
przepuszcza takie wywołanie (sprawdzone: `wp cron event run --due-now` →
`wp_doing_cron() === true`). Gdyby WP-CLI nie było dostępne, zadziała też
wywołanie lokalne:

```cron
* * * * * curl -s -o /dev/null http://127.0.0.1/wp-cron.php?doing_wp_cron
```

### 3.3. Zablokuj `wp-cron.php` z zewnątrz

Bez tego dowolna osoba z internetu odpala harmonogram w dowolnym momencie i
dowolną liczbę razy.

**nginx:**

```nginx
location = /wp-cron.php {
    allow 127.0.0.1;
    allow ::1;
    deny  all;
}
```

**Apache** (`.htaccess` w katalogu głównym WordPressa):

```apache
<Files "wp-cron.php">
    Require local
</Files>
```

Sprawdzenie: `curl -I https://domena/wp-cron.php` z zewnątrz ma zwrócić `403`.

> Samo odpalanie zadań przez `wp-cron.php` nie pozwala wysłać żadnego
> powiadomienia z podstawionymi danymi — pilnuje tego macierz pochodzenia
> zdarzeń. Blokada jest po to, żeby nikt nie wywoływał harmonogramu w kółko.

---

## 4. Poczta: SMTP + SPF / DKIM / DMARC

Wtyczka wysyła przez `wp_mail()`. Domyślnie PHP nadaje pocztę z serwera, który
najczęściej nie jest wpisany w rekordy nadawcze domeny — takie wiadomości lądują
w spamie albo są odrzucane, a **oferta do klienta po prostu nie dociera**.

### 4.1. SMTP

Uwierzytelniona wysyłka przez serwer poczty klienta lub dostawcę
transakcyjnego. Hasło SMTP trzymaj w `wp-config.php`, nie w bazie.

Nadawca ustawiany przez wtyczkę: `oferty@<domena-witryny>` — ten adres musi
**istnieć** i mieć skrzynkę, żeby odpowiedzi klientów gdzieś trafiały.

### 4.2. Rekordy DNS

| Rekord | Po co |
|---|---|
| **SPF** | Wskazuje, które serwery mogą wysyłać w imieniu domeny. Jeden rekord `TXT` na domenę, z serwerem SMTP z §4.1. |
| **DKIM** | Podpis kryptograficzny wiadomości — klucz publiczny w DNS, prywatny na serwerze poczty. |
| **DMARC** | Mówi odbiorcom, co zrobić z pocztą, która nie przejdzie SPF/DKIM. Startuj od `p=none` + adres raportów, dopiero po tygodniu podnoś do `p=quarantine`. |

Weryfikacja po wdrożeniu: wyślij ofertę testową na Gmaila i sprawdź w nagłówkach
`Authentication-Results` — mają być `spf=pass` i `dkim=pass`.

### 4.3. Bezpiecznik wysyłki

Wtyczka pilnuje limitu **200 wiadomości na godzinę**. Po przekroczeniu ustawia
opcję `mp_sw_queue_halted`, zatrzymuje kolejkę i pokazuje administratorowi
komunikat w panelu z przyciskiem wznowienia. Nadmiarowa wysyłka to jedyna szkoda
w tym systemie, której nie da się cofnąć — po wpisaniu domeny na czarną listę
w spam nie trafia jedna wiadomość, tylko cała późniejsza poczta firmy.

Limit podnosi się filtrem `mp_sw_mail_max_per_hour`, gdy klient realnie wysyła
więcej ofert:

```php
add_filter( 'mp_sw_mail_max_per_hour', function () { return 500; } );
```

---

## 5. Blokada katalogu z ofertami PDF

Pliki PDF generuje LP.2 do katalogu:

```
wp-content/uploads/mp-offer-builder-private/
```

LP.2 zakłada tam `.htaccess` z `Require all denied` — **to działa wyłącznie na
Apache'u**. Nginx plików `.htaccess` nie czyta w ogóle, więc na nim katalog
pozostaje otwarty i oferty wszystkich klientów są do pobrania po zgadnięciu
nazwy pliku.

**nginx** — dodaj do bloku `server`:

```nginx
location ^~ /wp-content/uploads/mp-offer-builder-private/ {
    deny all;
    return 404;
}
```

**Apache** — sprawdź, że `.htaccess` w tym katalogu istnieje i że
`AllowOverride` nie jest ustawione na `None` (przy `None` plik jest ignorowany).

Sprawdzenie po wdrożeniu:

```bash
curl -I https://domena/wp-content/uploads/mp-offer-builder-private/dowolna.pdf
# oczekiwane: 403 albo 404 — NIE 200 i NIE listing katalogu
```

Klient pobiera ofertę wyłącznie linkiem podpisanym HMAC, który wtyczka podaje
przez `wp-load` — nie potrzebuje bezpośredniego dostępu do katalogu.

---

## 6. Kopia zapasowa bazy przed migracją

Aktualizacja z wcześniejszej wersji podnosi schemat do `0.4.0`. Migracja idzie na
haku `plugins_loaded`, czyli **przy pierwszym wejściu na witrynę po wgraniu
plików** — nie ma osobnego przycisku „aktualizuj bazę".

| Krok | Co się zmienia |
|---|---|
| `0.2.0 → 0.3.0` | tabela zadań dostaje kolumny `claimed_at`, `claim_token` i indeks `idx_claim` (przez `dbDelta`) |
| `0.3.0 → 0.4.0` | z tabeli zdarzeń **znika** kolumna `result_json` — martwa od początku, nikt jej nie zapisywał ani nie czytał |

Usunięcie kolumny idzie osobnym `ALTER TABLE`, bo `dbDelta()` kolumn nie kasuje.
Krok jest osłonięty: hosting bez uprawnienia `ALTER` zostaje z nadmiarową kolumną
i wtyczka działa normalnie — kod jej nie dotyka. Danych to nie dotyczy, kolumna
była pusta w każdej instalacji.

```bash
wp db export kopia-przed-0.4.0.sql
```

Kopię zrób także dlatego, że dziennik aktywności (`wp_mp_sw_activity`) jest
kryterium odbioru 5.5 — to jedyny zapis tego, kto i kiedy zmienił status procesu.

Retencja: zdarzenia starsze niż **90 dni** kasuje `mp_sw_retention`. Dziennik
aktywności **nie jest** czyszczony nigdy.

---

## 7. Smoke-test uprawnień po wdrożeniu

Załóż **konto testowe handlowca** (rola „Handlowiec", `mp_handlowiec`) i przejdź
poniższe kroki zalogowany jako ono. Test zajmuje kilka minut i wyłapuje
najczęstszy błąd wdrożeniowy: role założone przy aktywacji, ale uprawnienia
nadpisane wtyczką do zarządzania rolami.

| # | Krok | Oczekiwany wynik |
|---|---|---|
| 1 | Panel → **Procesy sprzedażowe** (`admin.php?page=mp-sales-workflow`) | Strona się otwiera, widać wyłącznie procesy przypisane do tego konta |
| 2 | W adresie podmień identyfikator procesu na cudzy | **404 / „Nie znaleziono procesu"** — nie 403, bo 403 potwierdzałby, że taki proces istnieje |
| 3 | Wyloguj się i otwórz `admin-ajax.php?action=mp_sw_event` | Odmowa — żaden punkt wejścia LP.3 nie jest dostępny bez zalogowania (I-1) |
| 4 | Otwórz link do oferty z e-maila wysłanego w kroku 6 | PDF się pobiera |
| 5 | Zmień jeden znak w parametrze `mp_sw_sig` tego linku | „Link jest nieprawidłowy" |
| 6 | Zatwierdź ofertę testową w LP.2 | E-mail dociera do skrzynki klienta, z linkiem — **nie** z odnośnikiem do `wp-admin` |
| 7 | Zaloguj się jako manager | Widać procesy **swojego zespołu** (`mp_sw_team`), nie całej firmy |

Po teście **usuń albo dezaktywuj konto testowe**.

> Uwaga przy aktualizacji z wcześniejszej wersji: manager tracił dotąd
> ograniczenie zespołu (miał `mp_sw_manage_all`). Aktywacja synchronizuje
> uprawnienia w obie strony, więc zawężenie zadziała samo. Jeśli klient chce
> managera z widokiem całej firmy, nadaj mu jawnie `mp_sw_view_all`.

---

## 8. Odinstalowanie

Usunięcie wtyczki **nie kasuje danych**. Tabele i role znikają dopiero, gdy
administrator świadomie włączy opcję `mp_sw_delete_data`:

```bash
wp option update mp_sw_delete_data 1
```

Domyślnie wyłączone, bo dziennik aktywności jest kryterium odbioru, a „usuń
wtyczkę" bywa kliknięte przez pomyłkę. Zadania cron, opcja `mp_sw_queue_halted`
i transienty poczty są sprzątane zawsze.

---

## 9. Diagnostyka

Odmowy trafiają do **dziennika technicznego** (`error_log` PHP), nigdy do bazy —
zalanie odrzucanego punktu wejścia nie może zapełnić dysku. W odpowiedzi HTTP
widać wyłącznie kod ze słownika, bez nazwy reguły i bez treści zapytania.

| Kod | Znaczenie |
|---|---|
| `MP3-E110` | Zdarzenie przyszło niewłaściwym kanałem (macierz pochodzenia) |
| `MP3-E111` | Próba odpalenia zadań poza kontekstem crona — sprawdź §3 |
| `MP3-E140` | Proces nie istnieje **albo** należy do kogoś innego (celowo nierozróżnialne) |
| `MP3-E170` / `MP3-E171` | Błąd wysyłki / zadziałał bezpiecznik kolejki — patrz §4.3 |
| `MP3-E180` / `MP3-E181` | Zły podpis linku / link wygasł — jeden komunikat dla obu, patrz §1 |
