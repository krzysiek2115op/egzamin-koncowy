<!--
ŹRÓDŁO OFICJALNE — standard ISO 3166-1 alpha-2 (kody krajów).
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
