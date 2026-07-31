# Środowisko testowe trzech wtyczek (P1 + P2 + P3)

Żywy WordPress z prawdziwą bazą MariaDB i trzema wtyczkami naraz. Potrzebne,
żeby spełnić regułę **„test musi FAIL-ować przed naprawą”** — bez uruchamialnego
kodu P2 i P3 nie da się tego udowodnić.

## Uruchomienie

```sh
tools/test-env/wp.sh plugin list
tools/test-env/wp.sh eval-file /var/www/html/wp-content/plugins/mp-sales-workflow/tests/koncowe/scenariusze-1-10.php
```

Skrypt sam startuje kontener bazy, jeśli stoi zatrzymany (po restarcie maszyny).

## Co gdzie leży

| Element | Ścieżka | Uwagi |
|---|---|---|
| Rdzeń WordPressa + WooCommerce | `~/mp-test-env/wp` | **poza `/tmp`** — `/tmp` to tmpfs, restart kasuje |
| Wtyczka P1 | `~/mp-test-env/wt-p1` | drzewo robocze git, gałąź `mp-lead-intake` |
| Wtyczka P2 | `~/mp-test-env/wt-p2` | drzewo robocze git, gałąź `mp-offer-builder` |
| Narzędzie audytowe | `~/mp-test-env/wt-audyt` | drzewo robocze git, gałąź `audyt-projektu` |
| Wtyczka P3 | katalog repo | montowana wprost z bieżącej gałęzi |
| Skrypty deweloperskie | `~/mp-test-env/scr` → `/scr` | `test-d1.php` … `test-sec.php`, `soak.php` |
| PHPCS/WPCS, przenośny PHP | `~/mp-test-env/narzedzia` | odtworzenie: `docs`/pamięć projektu |
| Baza | kontener podman `mp-sw-db` | MariaDB 11.8, `--network=none`, baza/użytkownik `wp`/`wp` |

Baza stoi bez dostępu do sieci **celowo** — to sprawdza, że wtyczka nie wymaga
połączeń wychodzących w trakcie obsługi żądania. Klient WP-CLI dołącza do jej
przestrzeni sieciowej, więc `DB_HOST=127.0.0.1` idzie po loopbacku.

## Dowód „test FAIL-uje przed naprawą”

Katalogi wtyczek są podmontowane z drzew roboczych, więc zmiana pliku
natychmiast zmienia kod widziany przez WordPressa:

```sh
git stash push -- <plik źródłowy>          # cofa samą naprawę, test zostaje
tools/test-env/wp.sh eval-file /scr/<test> # oczekiwany FAIL
git stash pop                              # naprawa wraca
tools/test-env/wp.sh eval-file /scr/<test> # oczekiwany PASS
```

Dla P1 i P2 to samo, tyle że `git -C ~/mp-test-env/wt-p1 stash push …`.

## Stan odniesienia (31.07.2026, przed Grupą A)

| Zestaw | Wynik |
|---|---|
| P3 `scenariusze-1-10` | 101/101 |
| P3 `kompatybilnosc-3-wtyczek` | 62/62 |
| P3 `bramka-integracyjna-p1-p2-p3` | 23/23 |
| P3 `rodo-anonimizacja` | 24/24 |
| P3 `powiadomienia-odbiorcy` | 18/18 |
| P3 `link-do-oferty` | 16/16 |
| P3 `security/scenariusze-s1-s12` | 98/98 |
| P2 `zatwierdzenie-oferty` | 48/48 |
| P2 `ceny-brutto-netto` | 10/10 |
| P2 `pdf-pl-en-numer` | 31/31 |

Razem **P3 342/342, P2 89/89** — wszystko na żywym WordPressie 7.0.2,
MariaDB 11.8.8, WooCommerce 10.9.4, trzy wtyczki aktywne jednocześnie.

## Odtworzenie od zera

Gdyby zniknął katalog `~/mp-test-env`:

1. Baza: `podman run -d --name mp-sw-db --network=none -e MARIADB_ROOT_PASSWORD=root
   -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp docker.io/library/mariadb:11.8`
2. Rdzeń: rozpakować WordPressa do `~/mp-test-env/wp`, `wp-config.php` z
   `DB_HOST=127.0.0.1`, `DB_NAME/USER/PASSWORD = wp`; instalacja przez
   `tools/test-env/wp.sh core install …`; doinstalować WooCommerce.
3. Drzewa robocze:
   `git worktree add ~/mp-test-env/wt-p1 mp-lead-intake` (analogicznie `wt-p2`, `wt-audyt`).
4. Wtyczki aktywować: `tools/test-env/wp.sh plugin activate mp-lead-intake mp-offer-builder mp-sales-workflow woocommerce`.
