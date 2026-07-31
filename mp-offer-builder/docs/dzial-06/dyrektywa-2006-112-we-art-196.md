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

---

# ISO 3166-1 alpha-2 — kody krajów (drugie źródło działu)

Odniesienie: https://www.iso.org/iso-3166-country-codes.html (pobrano 2026-07-21)

Ta sama norma, którą cytuje `mp-lead-intake/docs/dzial-04/iso-3166-1-kody-krajow.md`.
Dopisana tutaj, bo Dział 6 rozstrzyga na jej podstawie **czy kod kraju w ogóle
istnieje** — a od tego zależy, czy oferta dostanie 0% VAT.

## Dlaczego lista jest wbudowana w kod, a nie brana z WooCommerce

Kontrola „nieznanego kodu kraju" wykonywała się wcześniej tylko wtedy, gdy dostępne
było `WC()->countries`. To inny warunek niż ten, który zapewnia Dział 2
(`class_exists( 'WC_Tax' )`): `WC()->countries` zapełnia się dopiero na haku `init`.
Zabezpieczenie przed cichym 0% VAT nie może zależeć od tego, jak daleko zaszedł
bootstrap sklepu.

Drugi powód: sklep może zawęzić listę krajów filtrem `woocommerce_countries`. Brak
kodu na tej liście znaczy wtedy „ten sklep tam nie sprzedaje", a nie „taki kraj nie
istnieje" — do rozstrzygania o istnieniu kraju ta lista się więc nie nadaje.

## Zastosowanie w tym dziale

Agent 6.1 odrzuca kod spoza normy błędem `unknown_country`. Literówka o poprawnym
kształcie („DR" zamiast „DE") przechodziła wcześniej regex `^[A-Z]{2}$`, nie trafiała
na listę UE i wpadała do gałęzi „poza UE": 0% VAT z podstawą prawną, która nie
istnieje. Kod prawdziwego kraju spoza UE („US", „NO") nadal dostaje `out_of_scope`.
