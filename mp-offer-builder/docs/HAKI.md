# Haki wtyczki MP Offer Builder

Spis tego, czego wtyczka **słucha** i co **wystawia**. Ta wtyczka stoi w środku
procesu, więc ma najwięcej styków z sąsiadami — i najwięcej do stracenia, gdy
któryś kontrakt cicho się zmieni.

## Czego wtyczka słucha (wejście)

| Hak | Skąd | Co robi |
|---|---|---|
| `mp_lead_created` | wtyczka 1 | zakłada **szkic** oferty dla nowego zgłoszenia (bez numeru i bez PDF-a) |
| `mp_lead_verified` | wtyczka 1 | poprawia snapshot VAT w szkicu, gdy weryfikacja w tle zakończyła się po jego założeniu |

Drugi z nich jest łatwy do przeoczenia w audycie: wtyczka 1 wystawia go u siebie,
a jedyny `add_action` stoi tutaj. Kontrola szukająca odbiorcy w drzewie jednej
wtyczki zgłosi go jako zdarzenie bez odbiorcy — i będzie w błędzie.

## Co wtyczka wystawia (wyjście)

| Hak | Argumenty | Kiedy | Odbiorca |
|---|---|---|---|
| `mp_offer_created` | `$offer_id`, `$payload` | Dział 11, **PO** `COMMIT` transakcji zapisu | **Wtyczka 3** — zakłada/aktualizuje proces sprzedaży |
| `mp_offer_approved` | `$offer_id`, `$payload` | zatwierdzenie oferty przez handlowca, dokładnie raz | **Wtyczka 3** — przesuwa proces i planuje follow-upy |

Kolejność jest częścią kontraktu: oba zdarzenia idą **po** zamknięciu transakcji,
żeby odbiorca nigdy nie zobaczył stanu, który mógłby jeszcze zostać wycofany.
`mp_offer_approved` jest przy tym wystawiane dokładnie raz na ofertę — decyduje
o tym warunkowy `UPDATE ... WHERE status = 'draft'`, nie kontrola w kodzie.

## Zasada

Zmiana nazwy albo argumentów któregokolwiek z czterech haków wyżej łamie
zgodność między wtyczkami i wymaga wpisu w changelogu **wszystkich** stron,
których dotyczy. Testy integracyjne sprawdzają te styki przy każdym przebiegu
regresji.
