# Haki wtyczki MP Sales Workflow

Spis wszystkiego, co ta wtyczka **wystawia** i czego **słucha**. Powstał, bo
audyt zgłosił cztery zdarzenia „wystawiane, ale nikt ich nie słucha w projekcie"
i miał rację, że bez dokumentu nie da się tego rozstrzygnąć: brak odbiorcy
znaczy albo świadomy punkt rozszerzeń, albo odbiorcę zgubionego przy refaktorze.

## Czego wtyczka słucha (wejście procesu)

| Hak | Skąd | Co robi |
|---|---|---|
| `mp_lead_created` | wtyczka 1 | zakłada proces sprzedaży dla nowego zgłoszenia |
| `mp_offer_approved` | wtyczka 2 | przesuwa proces do „oferta wysłana" i planuje follow-upy |

## Zdarzenie z odbiorcą

| Hak | Argumenty | Kiedy | Odbiorca |
|---|---|---|---|
| `mp_sw_flow_updated` | `$payload` | Dział 9, PO `COMMIT` — dokładnie raz na zdarzenie | **Wtyczka 1** — `MP_Lead_Intake_Salesman_Sync` synchronizuje przypisanie handlowca |

To zdarzenie idzie przez emiter (`MP_SW_D9_Emitter::emit()`), który woła
`do_action( $hook, ... )` z nazwą podaną **zmienną**. Kontrola statyczna nie ma
jak tego rozstrzygnąć i zgłasza je jako nasłuch bez emisji — stąd ten wiersz
w dokumencie. Emisja jest w `class-mp-sw-department-09.php:82`.

## Zdarzenia (`do_action`) — punkty rozszerzeń bez odbiorcy

| Hak | Argumenty | Kiedy |
|---|---|---|
| `mp_sw_notification_sent` | `$id`, `$row` | powiadomienie z kolejki poszło do adresata |
| `mp_sw_notification_failed` | `$id`, `$error` | poczta odmówiła; wiersz zostaje w kolejce z powodem |
| `mp_sw_queue_halted` | `$count` | bezpiecznik zatrzymał kolejkę po serii nieudanych wysyłek |
| `mp_sw_flow_anonymized` | `$lead_id`, `$flow_id` | proces zanonimizowany na żądanie RODO |
| `mp_sw_log_entry` | `$entry` | wpis dziennika technicznego, już po odfiltrowaniu białej listy pól — miejsce na przekierowanie logów do systemu zewnętrznego |

Żadne z nich nie ma odbiorcy wewnątrz projektu i tak ma zostać. Wtyczka reaguje
na te sytuacje sama — w bazie i w dzienniku — a hak istnieje po to, żeby wpiąć
w nie monitoring albo integrację (np. wiadomość na czacie firmowym przy
zatrzymanej kolejce). Zdarzenie o anonimizacji jest przy tym jedynym sposobem,
by system zewnętrzny dowiedział się, że ma skasować swoją kopię danych.

## Zdarzenie sterujące

| Hak | Argumenty | Do czego |
|---|---|---|
| `mp_sw_sweep_tasks` | — | ręczne uruchomienie przeglądu zadań (to samo, co robi cron); przydatne przy diagnostyce i w testach |

## Filtr

| Filtr | Domyślnie | Do czego |
|---|---|---|
| `mp_sw_read_offer` | `null` (`$offer_id`, `$lead_id`) | podstaw własne źródło danych oferty zamiast odczytu z bazy wtyczki 2 — punkt wpięcia dla instalacji, w której oferty prowadzi inny system |

## Zasada

Zdarzenia z tabel wyżej są **kontraktem**: zmiana nazwy albo argumentów to
zmiana łamiąca zgodność i wymaga wpisu w changelogu. Filtr ma domyślną wartość
`null`, czyli „nie nadpisuję" — wtyczka działa poprawnie bez ani jednego
`add_filter`.
