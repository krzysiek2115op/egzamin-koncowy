# Dział 1 — Pobranie danych z bazy (BD-3)

> Kod: [`includes/pipeline/departments/class-mp-department-01.php`](../includes/pipeline/departments/class-mp-department-01.php)
>
> **Zasada (Golden Rule #2):** agenci i krytycy tego działu korzystają **wyłącznie
> z oryginalnych/oficjalnych źródeł** wymienionych niżej — nigdy z danych zmyślonych,
> wpisanych na sztywno ani wtórnych.

## Źródła (oficjalne)
Dział 1 czyta dane **wyłącznie z autorytatywnego źródła projektu — bazy BD-3** — przez
oficjalne API bazodanowe WordPressa:

| Źródło | Rola | Odnośnik |
|---|---|---|
| **BD-3** (źródło prawdy) | tabele `wp_mp_leads`, `wp_mp_offers`, `wp_mp_activity_log` | schemat: [`class-mp-db.php`](../includes/db/class-mp-db.php) |
| WordPress `wpdb` | oficjalny dostęp do bazy (odczyt przez `prepare`/`get_results`) | https://developer.wordpress.org/reference/classes/wpdb/ |
| WordPress Plugin Handbook | oficjalna dokumentacja wtyczek | https://developer.wordpress.org/plugins/ |

Dział **tylko czyta** — nie wykonuje żadnych zapisów.

## Cel
Jednym przebiegiem (zasada **1 AJAX**) pobrać z BD-3 dane istniejące dla zgłaszającej firmy,
aby kolejne działy miały pełny kontekst.

## Wejście (kontekst)
| Klucz | Źródło | Opis |
|---|---|---|
| `nip` | zgłoszenie z formularza | NIP firmy (na tym etapie jeszcze niezwalidowany) |

## Agenci i krytycy
Wszyscy agenci odczytują dane **bezpośrednio z BD-3** przez `wpdb` (patrz „Źródła"):

| Agent | Odczyt z oficjalnego źródła (BD-3) | Krytyk |
|---|---|---|
| **1.1** Pobiera leady | `wp_mp_leads` — rekordy o `nip` ze zgłoszenia, `deleted_at IS NULL` | **K1.1** — sprawdza, że wynik to tablica `leads` |
| **1.2** Pobiera oferty | `wp_mp_offers` — dla `lead_id` znalezionych leadów | **K1.2** — sprawdza tablicę `offers` |
| **1.3** Pobiera historię | `wp_mp_activity_log` — wpisy dla `lead_id` | **K1.3** — sprawdza tablicę `activity_log` |

Każdy agent ma dokładnie 1 krytyka. Zła struktura wyniku → **STOP**.

## Bramka jakości (po dziale)
- **QA Agent 1** — kontrola kompletności: w kontekście są `leads`, `offers`, `activity_log`.
- **QA Krytyk 1** — akceptuje lub odrzuca wynik działu.

## Wyjście (JSON)
```json
{ "leads": [ ... ], "offers": [ ... ], "activity_log": [ ... ] }
```
Dane pochodzą 1:1 z BD-3 (bez przekształceń poza odczytem).

## Obsługa błędów
Błąd krytyka/bramki → **STOP** + wpis do `wp_mp_activity_log` (`action = pipeline_error`)
+ (krok 4) powiadomienie administratora.

## Powiązanie z kryteriami odbioru
Dostarcza dane z autorytatywnego źródła (BD-3) do wykrywania duplikatów firmy oraz do
odtwarzania historii operacji.
