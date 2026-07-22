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

## CI

![MP Lead Intake CI](https://github.com/krzysiek2115op/egzamin-koncowy/actions/workflows/mp-lead-intake-ci.yml/badge.svg?branch=mp-lead-intake)

Przy każdym push/PR na branch `mp-lead-intake` GitHub Actions
(`.github/workflows/mp-lead-intake-ci.yml`) uruchamia na PHP 7.4 i 8.3:

1. `php -l` — składnia wszystkich plików wtyczki,
2. PHPCS/WPCS wg reguł z korzenia repo (`.phpcs.xml.dist`),
3. `tests/process-harness/run-process.php` — 7 scenariuszy + niezmienniki procesu.

Zależności narzędzi dev (PHPCS/WPCS) są w `composer.json`/`composer.lock` w korzeniu
repo — wspólne dla wszystkich 3 wtyczek/branchy, nie duplikowane per plugin.
