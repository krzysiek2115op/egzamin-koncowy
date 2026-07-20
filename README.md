# Egzamin końcowy — automatyzacja zapytań ofertowych (WordPress / WooCommerce)

Projekt składa się z **3 wtyczek** i **3 baz danych** (zestawów tabel MySQL),
które razem tworzą spójny proces obsługi zapytań ofertowych z witryny WordPress.

## Cel biznesowy

Skrócenie czasu obsługi zapytań ofertowych. System ma automatycznie:

1. **kwalifikować lead-a** z formularza,
2. **tworzyć kartę klienta**,
3. **dobierać właściwy wariant cenowy**,
4. **generować ofertę PDF**,
5. **kierować zadanie do właściwego handlowca**.

Efekt: spójny przepływ **formularz → oferta**, bez ręcznego przepisywania
danych między formularzem, WooCommerce i pocztą.

## Wtyczki

| # | Wtyczka | Branch | Opis |
|---|---------|--------|------|
| 1 | `mp-lead-intake` | `mp-lead-intake` | Przyjęcie i kwalifikacja lead-a z formularza |
| 2 | _(do ustalenia)_ | — | — |
| 3 | _(do ustalenia)_ | — | — |

## Struktura repozytorium

Każda wtyczka mieszka we własnym katalogu w korzeniu repo (zgodnie z konwencją
wtyczek WordPress). Prace nad każdą wtyczką prowadzimy na dedykowanym branchu.

## Workflow

- Praca nad wtyczką 1 toczy się na branchu `mp-lead-intake`.
- Commity powstają automatycznie przy każdym większym kroku.
