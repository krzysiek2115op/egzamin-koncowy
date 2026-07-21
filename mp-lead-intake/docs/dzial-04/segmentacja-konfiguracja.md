<!--
ŹRÓDŁO ORYGINALNE — konfiguracja projektu (deterministyczny słownik segmentów).
Dotyczy: Dział 4 — agenci 4.2 (segment) i 4.3 (kategoria klienta).
-->

# Segmentacja — konfiguracja projektu (dokumentacja źródłowa)

Segment i kategoria klienta ustalane są **deterministycznie** — z danych formularza lub
z poniższego słownika projektu (brak zgadywania „per zgłoszenie", brak zewnętrznych źródeł).

## Słownik segmentów (fragment nazwy firmy → segment)

| Fragment (lowercase) | Segment |
|---|---|
| `produkc`, `fabry` | Produkcja |
| `usług`, `serwis` | Usługi |
| `handel`, `sklep` | Handel |
| `transport`, `logist` | Transport |
| `budow` | Budownictwo |
| `it`, `software`, `tech` | IT |
| (brak dopasowania) | Inne |

Jeśli formularz przekazał `segment` wprost — używamy go bez zmian.

## Kategoria klienta
Formularz jest **B2B**, więc `client_category` domyślnie = **`B2B`** (lub wartość
przekazana wprost z formularza).

## Uwaga
To jest konfiguracja projektu (dana referencyjna), utrzymywana w kodzie agenta 4.2
(`MP_D4_Agent_Segment::MAP`). Zmiana słownika = zmiana tej konfiguracji, nie logiki.
