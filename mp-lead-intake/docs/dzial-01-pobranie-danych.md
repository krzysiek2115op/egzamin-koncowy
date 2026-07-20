# Dział 1 — Pobranie danych z bazy (BD-3)

> Kod: [`includes/pipeline/departments/class-mp-department-01.php`](../includes/pipeline/departments/class-mp-department-01.php)

## Cel
Jednym przebiegiem (zgodnie z zasadą **1 AJAX**) pobrać z BD-3 dane potrzebne dalej
w pipeline: istniejące **leady** pasujące do zgłaszającej firmy (po NIP), powiązane
z nimi **oferty** oraz **historię aktywności**. Dzięki temu kolejne działy (m.in.
wykrywanie duplikatów w dziale 7) mają komplet kontekstu.

**Dział tylko czyta** — nie wykonuje żadnych zapisów do bazy.

## Wejście (kontekst / JSON)
Surowe pola zgłoszenia, w szczególności:

| Klucz | Opis |
|---|---|
| `nip` | NIP firmy (jeszcze niezwalidowany — służy do wyszukania istniejących rekordów) |

## Agenci i krytycy
| Agent | Zadanie | Krytyk |
|---|---|---|
| **1.1** Pobiera leady | `SELECT` z `wp_mp_leads` gdzie `nip = ?` i `deleted_at IS NULL` | **K1.1** — sprawdza, że wynik zawiera tablicę `leads` |
| **1.2** Pobiera oferty | `SELECT` z `wp_mp_offers` dla znalezionych `lead_id` | **K1.2** — sprawdza tablicę `offers` |
| **1.3** Pobiera historię | `SELECT` z `wp_mp_activity_log` dla `lead_id` (ostatnie 50) | **K1.3** — sprawdza tablicę `activity_log` |

Każdy agent ma dokładnie 1 krytyka. Jeśli krytyk wykryje złą strukturę wyniku → **STOP**.

## Bramka jakości (po dziale)
- **QA Agent 1** — kontrola kompletności: w kontekście muszą być `leads`, `offers`
  i `activity_log` (każde jako tablica).
- **QA Krytyk 1** — akceptuje lub odrzuca wynik całego działu.

## Wyjście (JSON)
```json
{
  "leads": [ /* wiersze leadów pasujących po NIP (może być puste) */ ],
  "offers": [ /* oferty tych leadów */ ],
  "activity_log": [ /* ostatnie wpisy historii */ ]
}
```

## Obsługa błędów
Błąd krytyka lub bramki jakości → **STOP pipeline** + wpis do `wp_mp_activity_log`
(`action = pipeline_error`) + (docelowo, krok 4) powiadomienie administratora.

## Tabele BD-3
Odczyt: `wp_mp_leads`, `wp_mp_offers`, `wp_mp_activity_log`. Zapisów: brak.

## Powiązanie z kryteriami odbioru
Dostarcza dane wejściowe do **wykrywania duplikatów firmy** (dedup po NIP w dziale 7)
oraz do odtwarzania **historii operacji**.
