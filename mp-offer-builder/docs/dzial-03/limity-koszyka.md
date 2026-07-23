<!--
ŹRÓDŁO ORYGINALNE — konfiguracja projektu (deterministyczne limity koszyka).
Dotyczy: Dział 3 — Agent 3.2 (ilości).
-->

# Limity koszyka — konfiguracja projektu (dokumentacja źródłowa)

Ilości i liczba pozycji na ofertę są ograniczone **deterministycznie** — stałymi
zaszytymi w kodzie agenta, nie zewnętrznym API/standardem (to reguła biznesowa
projektu, wzorem `mp-lead-intake/docs/dzial-04/segmentacja-konfiguracja.md`).

## Limity

| Reguła | Wartość |
|---|---|
| Minimalna ilość sztuk na pozycję | 1 |
| Maksymalna ilość sztuk na pozycję | 10 000 |
| Maksymalna liczba pozycji na ofertę | 50 |
| Typ ilości | liczba całkowita (ułamki odrzucane) |

## Uzasadnienie

Górne limity chronią przed dwoma odrębnymi problemami: nierealistyczną ilością
sztuk pojedynczej pozycji (literówka typu "10000000" zamiast "10") oraz zbyt
dużą liczbą pozycji w jednej ofercie (koszty renderu PDF/e-maila, czytelność
dokumentu dla klienta B2B). Wartości są konfiguracją w kodzie
(`MP_OB_D3_Agent_Quantities::MIN_QTY/MAX_QTY/MAX_ITEMS`) — zmiana limitu to
zmiana stałej, nie logiki.

## Zastosowanie w tym dziale

Agent 3.2 odrzuca CAŁY koszyk (nie pojedynczą linię) przy naruszeniu — zgodnie
z bramką jakości działu ("jeden brak unieważnia kalkulację, nie linię"), żeby
oferta nigdy nie powstała z częściowo pominiętymi pozycjami bez jawnej informacji
dla handlowca.
