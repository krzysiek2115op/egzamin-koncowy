#!/usr/bin/env bash
# Buduje materiały klienckie Pluginu 1 (MP Lead Intake) z ŹRÓDEŁ w tym katalogu.
# Jedno źródło prawdy: generatory .js (schematy) + pliki .json (instrukcje).
#
# Wymagania: node, rsvg-convert (pakiet librsvg), czcionki DejaVu Sans w systemie
#            (/usr/share/fonts/TTF/DejaVuSans*.ttf — używane przez gen-doc.js).
#
# Użycie:
#   ./build.sh          # buduje wszystko do ./out
#   ./build.sh deploy   # dodatkowo kopiuje 9 gotowych plików do
#                       #   ../../paczka-klienta/mp-lead-intake/materialy  oraz
#                       #   ~/Pulpit/mp-lead-intake-materialy
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p out

if [ ! -d node_modules ]; then
  echo "== Instaluję zależności (pdf-lib, fontkit) =="
  npm install --no-audit --no-fund
fi

echo "== Schematy (draw.io + SVG → PDF) =="
for s in schemat-nietechniczny schemat-techniczny schemat-bazy-danych; do
  node "$s.js" "out/$s"
  rsvg-convert "out/$s.svg" -f pdf -o "out/$s.pdf"
  echo "  -> out/$s.drawio + out/$s.pdf"
done

echo "== Instrukcje (JSON → PDF) =="
for d in instrukcja-instalacji-nietechniczna instrukcja-instalacji-techniczna jak-dziala-plugin; do
  node gen-doc.js "$d.json" "out/$d.pdf"
  echo "  -> out/$d.pdf"
done

if [ "${1:-}" = "deploy" ]; then
  # Katalog paczki JEST nazwany slugiem wtyczki. Do scalenia galezi kazda
  # wtyczka miala wlasne repo-galaz i wszystkie trzy pisaly do wspolnego
  # `paczka-klienta/materialy` — po scaleniu ta sama sciezka oznaczalaby, ze
  # trzy komplety materialow o identycznych nazwach plikow nadpisuja sie
  # nawzajem, po cichu, bo `mkdir -p` sam utworzylby brakujacy katalog.
  DEST_REPO="../../paczka-klienta/mp-lead-intake/materialy"
  DEST_PULPIT="$HOME/Pulpit/mp-lead-intake-materialy"
  echo "== Deploy → $DEST_REPO + $DEST_PULPIT =="
  mkdir -p "$DEST_REPO" "$DEST_PULPIT"
  for f in out/schemat-nietechniczny.drawio out/schemat-techniczny.drawio out/schemat-bazy-danych.drawio \
           out/schemat-nietechniczny.pdf out/schemat-techniczny.pdf out/schemat-bazy-danych.pdf \
           out/instrukcja-instalacji-nietechniczna.pdf out/instrukcja-instalacji-techniczna.pdf out/jak-dziala-plugin.pdf; do
    cp "$f" "$DEST_REPO/"
    cp "$f" "$DEST_PULPIT/"
  done
  echo "  skopiowano 9 plików do obu lokalizacji"
fi

echo "OK — materiały w ./out"
