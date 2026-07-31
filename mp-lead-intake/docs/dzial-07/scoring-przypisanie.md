<!--
ŹRÓDŁO ORYGINALNE — konfiguracja projektu (deterministyczne reguły).
Dotyczy: Dział 7 — agent 7.2 (scoring + przypisanie handlowca).
-->

# Scoring i przypisanie handlowca — konfiguracja projektu (dokumentacja źródłowa)

Reguły deterministyczne (bez zgadywania per zgłoszenie). Utrzymywane w kodzie agenta 7.2.

## Scoring leada (punkty)

| Warunek | Punkty |
|---|---|
| VAT UE ważny (VIES) | +30 |
| Status firmy = `Czynny` (Biała lista) | +20 |
| Podany telefon | +10 |
| Zgoda marketingowa | +10 |
| Segment ∈ {Produkcja, IT, Budownictwo} | +15 |

Wynik zapisywany w `wp_mp_leads.score`.

## Przypisanie handlowca

- Kandydaci: użytkownicy WordPress z rolą **`mp_handlowiec`**.
- Wybór deterministyczny: `index = abs(crc32(nip)) % liczba_handlowców`.
- Brak handlowców → `salesman_id = NULL` (przypisanie ręczne później).

Wynik zapisywany w `wp_mp_leads.salesman_id`.

## Uwaga
Rola `mp_handlowiec` (oraz administrator / manager sprzedaży) — utworzenie ról to część
spraw technicznych (krok 4). Do tego czasu przy braku użytkowników z rolą przypisanie = NULL.
