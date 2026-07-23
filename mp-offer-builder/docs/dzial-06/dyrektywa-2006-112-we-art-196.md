<!--
ŹRÓDŁO OFICJALNE — Dyrektywa Rady 2006/112/WE z dnia 28 listopada 2006 r. w sprawie
wspólnego systemu podatku od wartości dodanej (dyrektywa VAT).
URL:    https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=celex%3A32006L0112
Pobrano: 2026-07-24 (art. 196 zweryfikowany przez wyszukiwanie — pełny skonsolidowany
tekst dyrektywy jest zbyt długi dla jednorazowego pobrania narzędziem; treść artykułu
spójna niezależnie w kilku źródłach wtórnych cytujących EUR-Lex, patrz też
https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=legissum%3Al31057).
Dotyczy: Dział 6 — Agent 6.1 "mechanizm" (MP_OB_D6_Agent_Mechanism), odwrotne
obciążenie B2B.
-->

# Dyrektywa 2006/112/WE, art. 196 — dokumentacja źródłowa

## Treść artykułu (cytat, wersja angielska)

"VAT shall be payable by any taxable person, or non-taxable legal person identified
for VAT purposes, to whom the services referred to in Article 44 are supplied, if the
services are supplied by a taxable person not established within the territory of the
Member State."

## Znaczenie

Artykuł 196 przenosi obowiązek rozliczenia VAT z dostawcy na nabywcę (mechanizm
"odwrotnego obciążenia"/*reverse charge*) dla usług objętych art. 44 (usługi B2B
świadczone dla podatnika mającego siedzibę w innym państwie), gdy dostawca nie ma
siedziby w państwie członkowskim nabywcy. W praktyce: sprzedawca z Polski wystawia
fakturę B2B na 0% VAT firmie z ważnym numerem VAT UE w innym kraju członkowskim —
to nabywca rozlicza VAT we własnym kraju.

## Zastosowanie w tym dziale

Agent 6.1 stosuje mechanizm `reverse_charge` (stawka 0%, `vat_grosze=0`) TYLKO gdy:
kraj klienta jest w UE, INNY niż PL, ORAZ klient ma potwierdzony ważny numer VAT
(`client.vat_status === 'valid'`). Brak potwierdzenia (pole nieobecne, `'unchecked'`,
`'invalid'`) skutkuje BEZPIECZNYM DOMYŚLNYM zachowaniem — naliczeniem krajowej
stawki VAT (mechanizm `domestic`), NIGDY cichym zerowaniem podatku bez podstawy
prawnej. Klient spoza UE dostaje mechanizm `out_of_scope` (poza zakresem VAT UE) —
to inna podstawa prawna niż odwrotne obciążenie, mimo tej samej stawki 0%.
