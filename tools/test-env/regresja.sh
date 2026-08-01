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
#
# Kod wyjścia: 0 gdy wszystko przeszło, 1 przy pierwszej porażce w podsumowaniu.
set -u

SELF=$(readlink -f "$0")
HERE=$(dirname "$SELF")
REPO=$(dirname "$(dirname "$SELF")")
REPO=$(dirname "$REPO")
ENV_DIR="${MP_TEST_ENV:-$HOME/mp-test-env}"
PHP="${MP_PHP:-$ENV_DIR/narzedzia/php83/php}"
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
echo "=== Pliki testowe (WordPress + trzy wtyczki, wp eval-file) ==="
for p in mp-lead-intake mp-offer-builder mp-sales-workflow; do
	for t in "$REPO/$p"/tests/*/*.php; do
		[ -f "$t" ] || continue
		case "$t" in
			*/index.php | */wp-stubs.php | */process-harness/*) continue ;;
		esac
		REL=${t#"$REPO/"}
		case "$REL" in *"$WZORZEC"*) ;; *) [ -n "$WZORZEC" ] && continue ;; esac
		OUT=$(MP_TEST_ENV="$ENV_DIR" "$HERE/wp.sh" eval-file "wp-content/plugins/$REL" 2>&1)
		raport "$REL" "$OUT"
	done
done

echo
echo "===================================================="
printf 'PRZESZLO: %d   NIE PRZESZLO: %d   BEZ WERDYKTU: %d\n' "$OK" "$ZLE" "$POMINIETE"
if [ -n "$LISTA_ZLYCH" ]; then
	echo "Nie przeszly:$LISTA_ZLYCH"
fi

[ "$ZLE" -eq 0 ] && [ "$POMINIETE" -eq 0 ]
