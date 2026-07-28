#!/usr/bin/env bash
# Buduje materiały klienckie Pluginu 3 (MP Sales Workflow) z ŹRÓDEŁ w tym katalogu.
# Jedno źródło prawdy: generatory .js (schematy) + pliki .json (dokumenty).
#
# Wymagania: node, rsvg-convert (pakiet librsvg), czcionki DejaVu Sans w systemie
#            (/usr/share/fonts/TTF/DejaVuSans*.ttf — używane przez gen-doc.js).
#
# Użycie:
#   ./build.sh          # buduje wszystko do ./out
#   ./build.sh deploy   # dodatkowo kopiuje 9 gotowych plików do
#                       #   ../../paczka-klienta/materialy-p3  oraz
#                       #   ~/Pulpit/mp-sales-workflow-materialy
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

echo "== Dokumenty (JSON → PDF) =="
for d in instrukcja-instalacji-nietechniczna instrukcja-instalacji-techniczna jak-dziala-system; do
  node gen-doc.js "$d.json" "out/$d.pdf"
  echo "  -> out/$d.pdf"
done

if [ "${1:-}" = "deploy" ]; then
  DEST_REPO="../../paczka-klienta/materialy-p3"
  DEST_PULPIT="$HOME/Pulpit/mp-sales-workflow-materialy"
  echo "== Deploy → $DEST_REPO + $DEST_PULPIT =="
  mkdir -p "$DEST_REPO" "$DEST_PULPIT"
  for f in out/schemat-nietechniczny.drawio out/schemat-techniczny.drawio out/schemat-bazy-danych.drawio \
           out/schemat-nietechniczny.pdf out/schemat-techniczny.pdf out/schemat-bazy-danych.pdf \
           out/instrukcja-instalacji-nietechniczna.pdf out/instrukcja-instalacji-techniczna.pdf out/jak-dziala-system.pdf; do
    cp "$f" "$DEST_REPO/"
    cp "$f" "$DEST_PULPIT/"
  done
  echo "  skopiowano 9 plików do obu lokalizacji"
fi

echo "OK — materiały w ./out"
