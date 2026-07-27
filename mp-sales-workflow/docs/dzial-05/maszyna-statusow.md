<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 5 — MASZYNA STATUSÓW.
Jeden plik na dział (zasada projektu).

ŹRÓDŁO ORYGINALNE:
Diagram LP.3 "MP Sales Workflow + BD-1", rewizja 2.0 z 27.07.2026, sekcja
Działu 5 — dostarczony przez klienta i przechowywany w repozytorium:
blueprint/LP3_diagram_wizualny.html
Odczytano: 2026-07-28.

Dlaczego akurat to źródło: diagram wskazuje dla tego działu "maszyna stanów wg
zlecenia", a nie zewnętrzne API — WordPress nie dostarcza mechanizmu maszyny
stanów, więc jedynym wiążącym źródłem słownika przejść jest specyfikacja
klienta. Poniżej dosłowny zapis z diagramu.
-->

# Dział 5 — maszyna statusów: zapis źródłowy

## Zakres działu (cytat z diagramu)

"MASZYNA STATUSÓW" — "pkt 2: statusy procesu"

## Para A5.1 / K5.1 (cytat z diagramu)

Agent "przejście": "Sprawdza przejście w słowniku: nowy → przypisany →
oferta_robocza → oferta_wyslana → negocjacje → wygrany / przegrany"

Krytyk "legalność-przejścia": "Przejście spoza słownika = odmowa z kodem, stan
bez zmian"

## Para A5.2 / K5.2 (cytat z diagramu)

Agent "skutki": "Wylicza skutki przejścia: nowe SLA, zamknięcie oczekujących
zadań, powiadomienia do wysłania"

Krytyk "komplet-skutków": "Każdy skutek ma pokrycie w regule przejścia — nic
„przy okazji”"

## Operacje działu (cytat z diagramu)

"Słownik dozwolonych przejść (wersjonowany)"

"Skutki przejścia wyliczone jawnie"

## Bramka jakości (cytat z diagramu)

QA Agent: "legalność-przejścia + komplet skutków"

QA Krytyk: "stan zmienia się tylko przez maszynę, nigdy wprost"

## Kształt danych wyjściowych działu (cytat z diagramu)

```json
{ "transition": {
   "from": "oferta_robocza",
   "to": "oferta_wyslana",
   "allowed": true,
   "machine_version": "v3" },
  "effects": [
   "schedule_followups",
   "notify_client",
   "close_tasks" ] }
```
