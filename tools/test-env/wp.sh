#!/bin/sh
# Klient WP-CLI dla środowiska testowego trzech wtyczek (P1 + P2 + P3).
#
# Uruchamia kontener `wordpress:cli` w przestrzeni sieciowej bazy `mp-sw-db`
# (baza stoi z `--network=none`, więc DB_HOST=127.0.0.1 idzie po loopbacku).
# Katalogi wtyczek są podmontowane z drzew roboczych git, więc `git stash push`
# na pliku źródłowym natychmiast zmienia kod widziany przez testy — na tym
# opiera się reguła „test musi FAIL-ować przed naprawą”.
#
# Użycie:  tools/test-env/wp.sh plugin list
#          tools/test-env/wp.sh eval-file /scr/scenariusze-1-10.php
#
# Zmienne:  MP_TEST_ENV  — katalog środowiska (domyślnie $HOME/mp-test-env)
#           MP_SCR       — katalog montowany jako /scr (domyślnie $MP_TEST_ENV/scr)
set -eu

ENV_DIR="${MP_TEST_ENV:-$HOME/mp-test-env}"
SCR="${MP_SCR:-$ENV_DIR/scr}"
DB_CT=mp-sw-db

# Katalog repo — wyliczony ze ścieżki skryptu (nazwa katalogu repo ma spację na końcu).
#
# Wszystkie TRZY wtyczki montujemy z tego samego drzewa roboczego. Wcześniej
# wtyczki 1 i 2 szły z osobnych worktree gałęzi (`wt-p1`, `wt-p2`), bo każda
# żyła na własnej gałęzi. Po scaleniu do `main` leżą obok siebie — a montowanie
# ich dalej z gałęzi znaczyłoby, że poprawka zrobiona na `main` jest dla testów
# NIEWIDZIALNA, a wynik i tak wygląda na prawdziwy. To pułapka gorsza niż błąd:
# test przechodzi, sprawdziwszy nie ten kod.
SELF=$(readlink -f "$0")
REPO=$(dirname "$(dirname "$(dirname "$SELF")")")

if [ ! -d "$ENV_DIR/wp" ]; then
	echo "BŁĄD: brak $ENV_DIR/wp — zob. tools/test-env/README.md (odtworzenie środowiska)." >&2
	exit 1
fi

# Wszystkie trzy wtyczki musza byc w tym drzewie roboczym. Po scaleniu leza obok
# siebie na `main`; na starych galeziach wtyczek — nie. Bez tej kontroli podman
# zamontowalby puste katalogi i testy „przeszlyby" na nieistniejacym kodzie.
for _wt in mp-lead-intake mp-offer-builder mp-sales-workflow; do
	if [ ! -d "$REPO/$_wt" ]; then
		echo "BŁĄD: w $REPO nie ma katalogu $_wt." >&2
		echo "Środowisko testuje komplet trzech wtyczek, a te leżą razem na gałęzi 'main'." >&2
		echo "Przełącz się na main albo uruchom skrypt z drzewa roboczego maina." >&2
		exit 1
	fi
done
mkdir -p "$SCR"

# Baza musi stać; po restarcie maszyny kontener jest zatrzymany, ale wolumen zostaje.
if [ "$(podman inspect -f '{{.State.Status}}' "$DB_CT" 2>/dev/null || true)" != "running" ]; then
	podman start "$DB_CT" >/dev/null 2>&1 || {
		echo "BŁĄD: nie mogę wystartować kontenera bazy $DB_CT." >&2
		echo "Odtworzenie: zob. tools/test-env/README.md" >&2
		exit 1
	}
	# MariaDB potrzebuje chwili na przyjęcie połączeń.
	i=0
	while [ "$i" -lt 30 ]; do
		podman exec "$DB_CT" mariadb -uwp -pwp -e 'SELECT 1' wp >/dev/null 2>&1 && break
		i=$((i + 1))
		sleep 1
	done
fi

# Kod wyjścia WP-CLI trzeba przenieść przez potok (grep zjada ostrzeżenie podmana
# o sterowniku magazynu, a jego własny status nic tu nie znaczy).
RC_FILE=$(mktemp)
{
	podman run --rm --network="container:$DB_CT" --user root \
		-v "$ENV_DIR/wp:/var/www/html:Z" \
		-v "$SCR:/scr:Z" \
		-v "$REPO/mp-lead-intake:/var/www/html/wp-content/plugins/mp-lead-intake:Z" \
		-v "$REPO/mp-offer-builder:/var/www/html/wp-content/plugins/mp-offer-builder:Z" \
		-v "$REPO/mp-sales-workflow:/var/www/html/wp-content/plugins/mp-sales-workflow:Z" \
		docker.io/library/wordpress:cli --allow-root --path=/var/www/html "$@" 2>&1
	echo $? >"$RC_FILE"
} | grep -v 'level=error msg="User-selected' || true

RC=$(cat "$RC_FILE")
rm -f "$RC_FILE"
exit "$RC"
