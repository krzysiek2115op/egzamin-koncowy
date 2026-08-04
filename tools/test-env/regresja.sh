#!/bin/sh
# Pełna regresja trzech wtyczek: wszystkie wersjonowane pliki testowe naraz.
#
# Dotąd każdy przebieg regresji był ręczny — kilkadziesiąt wywołań `wp eval-file`
# przepisywanych z pamięci przy każdym wydaniu. Kosztowało to czas i milczało
# o tym, czego NIE uruchomiono: pominięty plik wyglądał tak samo jak plik, którego
# nie ma. Ten skrypt bierze listę z katalogów `tests/`, więc nowy test dołącza do
# regresji przez samo powstanie.
#
# Użycie:  tools/test-env/regresja.sh              # wszystko
#          tools/test-env/regresja.sh handlowiec   # tylko pasujące do wzorca
#
# Zmienne:  MP_TEST_ENV — katalog środowiska (domyślnie $HOME/mp-test-env)
#           MP_PHP      — przenośne PHP do harnessów (domyślnie z $MP_TEST_ENV/narzedzia)
#           MP_WP_CMD   — klient WP-CLI (domyślnie tools/test-env/wp.sh na podmanie).
#                         W CI nie ma podmana, za to WordPress stoi lokalnie — wtedy
#                         np. MP_WP_CMD="wp --allow-root --path=$PWD/wp". Dzięki temu
#                         reguła oceny werdyktu jest JEDNA i tu, i na GitHubie.
#           MP_PLUGINS_PATH — przedrostek ścieżki testu przekazywanej do eval-file
#                         (domyślnie "wp-content/plugins/").
#
# Kod wyjścia: 0 gdy wszystko przeszło, 1 przy pierwszej porażce w podsumowaniu.
set -u

SELF=$(readlink -f "$0")
HERE=$(dirname "$SELF")
REPO=$(dirname "$(dirname "$SELF")")
REPO=$(dirname "$REPO")
ENV_DIR="${MP_TEST_ENV:-$HOME/mp-test-env}"
PHP="${MP_PHP:-$ENV_DIR/narzedzia/php83/php}"
WP_CMD="${MP_WP_CMD:-}"
PLUGINS_PATH="${MP_PLUGINS_PATH:-wp-content/plugins/}"
WZORZEC="${1:-}"

OK=0
ZLE=0
POMINIETE=0
LISTA_ZLYCH=""

# Werdykt z wyjścia testu. Formaty są trzy, bo pisały je trzy różne zestawy —
# szukanie tylko jednego pokazywało zdrowe pliki jako porażki (pułapka z audytu:
# 12/12 wyglądało jak 5/12).
ocen() {
	_out="$1"
	if printf '%s' "$_out" | grep -qE 'VERDICT_HAS_FAILURES|STATUS: SA_(KOLIZJE|BLEDY)|WYNIK: FAIL|WYNIK: wykryto'; then
		return 1
	fi
	# Harnessy kończą zdaniem, nie stałą — i każdy własnym ("proces spójny wg
	# niezmienników", "rusztowanie pipeline'u … spójne wg niezmienników"). Dopasowanie
	# do pełnego zdania przegapiłoby drugi z nich mimo 110/110 PASS.
	if printf '%s' "$_out" | grep -qE 'VERDICT_ALL_PASS|STATUS: ALL_PASS|WYNIK: PASS|WYNIK: .*sp(ó|o)jn.* wg niezmiennik'; then
		return 0
	fi
	return 2
}

raport() {
	_nazwa="$1"
	_out="$2"
	ocen "$_out"
	case $? in
		0)
			OK=$((OK + 1))
			printf '  [OK ] %s\n' "$_nazwa"
			;;
		1)
			ZLE=$((ZLE + 1))
			LISTA_ZLYCH="$LISTA_ZLYCH
  $_nazwa"
			printf '  [ZLE] %s\n' "$_nazwa"
			printf '%s\n' "$_out" | grep -E '^\[FAIL|FAIL\]' | head -8 | sed 's/^/        /'
			;;
		*)
			POMINIETE=$((POMINIETE + 1))
			printf '  [ ? ] %s — brak rozpoznanego werdyktu\n' "$_nazwa"
			printf '%s\n' "$_out" | tail -3 | sed 's/^/        /'
			;;
	esac
}

echo "=== Harnessy (przenośne PHP, bez WordPressa) ==="
for h in mp-lead-intake/tests/process-harness/run-process.php \
	mp-offer-builder/tests/process-harness/run-process.php; do
	[ -f "$REPO/$h" ] || continue
	case "$h" in *"$WZORZEC"*) ;; *) [ -n "$WZORZEC" ] && continue ;; esac
	OUT=$(cd "$REPO/$(dirname "$h")" && "$PHP" "$(basename "$h")" 2>&1)
	raport "$h" "$OUT"
done

echo
echo "=== Narzędzia wydania (Python, bez WordPressa) ==="
for t in "$REPO"/tools/wydanie/tests/*.py; do
	[ -f "$t" ] || continue
	REL=${t#"$REPO/"}
	case "$REL" in *"$WZORZEC"*) ;; *) [ -n "$WZORZEC" ] && continue ;; esac
	OUT=$(python3 "$t" 2>&1)
	raport "$REL" "$OUT"
done

echo
echo "=== Pliki testowe (WordPress + trzy wtyczki, wp eval-file) ==="
for p in mp-lead-intake mp-offer-builder mp-sales-workflow; do
	for t in "$REPO/$p"/tests/*/*.php; do
		[ -f "$t" ] || continue
		case "$t" in
			*/index.php | */wp-stubs.php | */process-harness/*) continue ;;
		esac
		REL=${t#"$REPO/"}
		case "$REL" in *"$WZORZEC"*) ;; *) [ -n "$WZORZEC" ] && continue ;; esac
		if [ -n "$WP_CMD" ]; then
			# shellcheck disable=SC2086
			OUT=$($WP_CMD eval-file "${PLUGINS_PATH}$REL" 2>&1)
		else
			OUT=$(MP_TEST_ENV="$ENV_DIR" "$HERE/wp.sh" eval-file "${PLUGINS_PATH}$REL" 2>&1)
		fi
		raport "$REL" "$OUT"
	done
done

# Strona pokazowa jest częścią dostawy — egzaminator ogląda WŁAŚNIE ją, a przez
# trzy wydania nie sprawdzał jej żaden test. Motyw demo wymaga aktywacji, więc
# ta sekcja idzie na końcu i przywraca poprzedni motyw.
DEMO_KAT="$REPO/tools/strona-pokazowa/tests"
if [ -n "${MP_DEMO_PATH:-}" ]; then
	DEMO_PATH="${MP_DEMO_PATH}"
elif [ -n "$WP_CMD" ]; then
	DEMO_PATH="tools/strona-pokazowa/tests/"
else
	# Podman montuje `tools/strona-pokazowa` jako /demo (zob. wp.sh).
	DEMO_PATH="/demo/tests/"
fi

wp_klient() {
	if [ -n "$WP_CMD" ]; then
		# shellcheck disable=SC2086
		$WP_CMD "$@"
	else
		MP_TEST_ENV="$ENV_DIR" "$HERE/wp.sh" "$@"
	fi
}

if [ -d "$DEMO_KAT" ]; then
	echo
	echo "=== Strona pokazowa (motyw demo, wp eval-file) ==="

	MOTYW_BYL=$(wp_klient theme list --field=name --status=active 2>/dev/null | tr -d '\r' | head -1)
	wp_klient theme activate kredyt-kompas >/dev/null 2>&1 || true

	for t in "$DEMO_KAT"/*.php; do
		[ -f "$t" ] || continue
		REL=${t#"$REPO/"}
		case "$REL" in *"$WZORZEC"*) ;; *) [ -n "$WZORZEC" ] && continue ;; esac
		OUT=$(wp_klient eval-file "${DEMO_PATH}$(basename "$t")" 2>&1)
		raport "$REL" "$OUT"
	done

	if [ -n "$MOTYW_BYL" ] && [ "$MOTYW_BYL" != "kredyt-kompas" ]; then
		wp_klient theme activate "$MOTYW_BYL" >/dev/null 2>&1 || true
	fi
fi

echo
echo "===================================================="
printf 'PRZESZLO: %d   NIE PRZESZLO: %d   BEZ WERDYKTU: %d\n' "$OK" "$ZLE" "$POMINIETE"
if [ -n "$LISTA_ZLYCH" ]; then
	echo "Nie przeszly:$LISTA_ZLYCH"
fi

[ "$ZLE" -eq 0 ] && [ "$POMINIETE" -eq 0 ]
