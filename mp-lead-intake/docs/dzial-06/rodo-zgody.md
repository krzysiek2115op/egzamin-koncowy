<!--
ŹRÓDŁO OFICJALNE — RODO (RODO/GDPR), Rozporządzenie (UE) 2016/679.
Odniesienie: art. 6 ust. 1 lit. a) oraz art. 7 (warunki wyrażenia zgody).
Dotyczy: Dział 6 — zapis zgód (marketing + RODO) z dowodem zgody.
-->

# RODO — zgody (dokumentacja źródłowa)

Podstawa: **Rozporządzenie (UE) 2016/679 (RODO/GDPR)**.

## Zasady istotne dla działu 6
- **Art. 6 ust. 1 lit. a)** — przetwarzanie jest zgodne z prawem, jeśli osoba wyraziła
  **zgodę** na przetwarzanie swoich danych.
- **Art. 7** — administrator musi być w stanie **wykazać**, że osoba wyraziła zgodę
  (dowód zgody). Zgoda musi być dobrowolna, konkretna, świadoma i jednoznaczna.

## Konsekwencje w implementacji
- Zgoda **RODO jest wymagana** do dalszego przetwarzania (brak → STOP, dział 6).
- Zapisujemy **dowód zgody**: fakt zgody (`consent_rodo`), **znacznik czasu**
  (`consent_rodo_at`) oraz **wersję treści** zgody (`consent_version`).
- Zgoda **marketingowa** jest osobna i **opcjonalna** (`consent_marketing`,
  `consent_marketing_at`).

Kolumny w BD-3 (`wp_mp_leads`): `consent_marketing`, `consent_rodo`,
`consent_marketing_at`, `consent_rodo_at`, `consent_version`.
