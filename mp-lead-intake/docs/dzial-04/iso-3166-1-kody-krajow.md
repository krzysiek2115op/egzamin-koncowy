<!--
ŹRÓDŁO OFICJALNE — standard ISO 3166-1 alpha-2 (kody krajów).
Jeden plik na dział (zasada projektu).
Odniesienie: https://www.iso.org/iso-3166-country-codes.html (pobrano 2026-07-21)
Dotyczy: Dział 4 — agent 4.1 (kod kraju).
-->

# ISO 3166-1 alpha-2 — kody krajów (dokumentacja źródłowa)

Kod kraju to **dwuliterowy** kod wg normy **ISO 3166-1 alpha-2**.

Przykłady istotne dla projektu:

| Kraj | Kod |
|---|---|
| Polska | `PL` |
| Niemcy | `DE` |
| Czechy | `CZ` |
| Francja | `FR` |

## Zastosowanie w dziale 4
Agent 4.1 przyjmuje kod z kontekstu, jeśli jest poprawny (`^[A-Z]{2}$`), a w przeciwnym
razie ustawia **`PL`** (numer NIP jest polski). Kod kraju jest później używany m.in. przy
weryfikacji VAT UE (VIES) — patrz dział 3.

---

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

## „Rynek" ze zlecenia — gdzie jest w kodzie

Krok 1 procesu w zleceniu wymienia pole **rynek**. W bazie ani w kodzie nie ma
kolumny o tej nazwie i celowo nie będzie: „rynek" to pojęcie handlowe, które ta
wtyczka rozkłada na dwie mierzalne dane.

| Zlecenie | Formularz | Kolumna w BD-3 | Kto z tego korzysta |
|---|---|---|---|
| rynek — GDZIE klient działa | „Rynek (kraj klienta)" | `country` (ISO 3166-1 alpha-2) | Wtyczka 2: mechanizm VAT (krajowy / odwrotne obciążenie / poza zakresem). Wtyczka 3: dobór handlowca po kraju |
| rynek — W CZYM klient działa | „Segment / branża" | `segment` | Wtyczka 3: dobór handlowca po branży, treść powiadomień |

Rozdzielenie nie jest kosmetyką. Kraj musi być kodem ISO, bo od niego zależy
naliczony podatek — pomyłka kosztuje pieniądze klienta. Segment jest tekstem,
bo służy do kierowania sprawy do właściwego człowieka i pomyłka kosztuje jeden
telefon. Wspólne pole „rynek" wymuszałoby albo rygor ISO na branży, albo
swobodę tekstu na kraju; obie wersje są gorsze od dwóch osobnych pól.
