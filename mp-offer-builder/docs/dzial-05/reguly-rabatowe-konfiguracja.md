<!--
ŹRÓDŁO ORYGINALNE — konfiguracja projektu (reguły rabatowe wersjonowane).
Dotyczy: Dział 5 — Agent 5.1 (dobór) i Agent 5.2 (zastosowanie).
Wzorzec: mp-lead-intake/docs/dzial-04/iso-3166-1-kody-krajow.md (deterministyczna
konfiguracja w kodzie, nie zewnętrzne API — zgodnie z diagramem: "dok: reguły
rabatowe wersjonowane (konfiguracja wtyczki)").
-->

# Reguły rabatowe — konfiguracja projektu (dokumentacja źródłowa)

Rabat dobierany jest **deterministycznie** wg wariantu cenowego i pasma wolumenu
(suma sztuk w koszyku), ze słownika w kodzie (`MP_OB_D5_Agent_Discount_Rules::RULES`),
oznaczonego numerem wersji (`RULES_VERSION`) zapisywanym przy każdej ofercie —
warunek "odtwarzalność-rabatu" z bramki działu (ten sam koszyk + ta sama wersja
reguł = ten sam wynik).

## Wersja v1 — słownik reguł

| rule_id | wariant | próg ilości (szt.) | rabat | metoda |
|---|---|---|---|---|
| R-00 | (dowolny, catch-all) | 0 | 0% | total |
| R-01 | partner | 1 | 5% | total |
| R-02 | partner | 50 | 10% | total |
| R-03 | standard | 1 | 0% | total |

Wybierana jest reguła o NAJWYŻSZYM progu ilości spełnionym przez sumę sztuk w
koszyku, dla danego wariantu; brak dopasowania wariantu → R-00 (0%, nigdy błąd
— nieznany/nowy wariant nie blokuje budowy oferty, tylko nie dostaje rabatu).

## Metoda zastosowania rabatu

Jedna metoda na cały dokument: **`total`** (rabat liczony od `subtotal_grosze`,
nie osobno per pozycja) — zgodnie z diagramem: "jedna metoda, jawnie wybrana".

## Limit łączny

**30% `subtotal_grosze`.** Przekroczenie limitu NIE jest ciche przycinane
(diagram: "rabat ponad limit = flaga do akceptacji, nie ciche przycięcie") —
Agent 5.2 zatrzymuje dział z kodem `discount_over_limit`, wymagając jawnej
akceptacji poza tym pipeline'em (np. przyszły panel handlowca, Krok 4), zamiast
po cichu wystawiać ofertę z nieautoryzowanym rabatem.

## Uwaga o zakresie (świadomie odłożone)

Diagram wymienia "segment" jako jeden z wymiarów doboru reguły. Kontrakt
żądania (Dział 1) obecnie NIE przenosi segmentu klienta (nie ma go w JSON
`client`, a szkic z Kroku 2.5 też go nie przechowuje w `wp_mp_ob_offers`) —
dodanie tego wymiaru wymagałoby zmiany schematu BD-2 i kontraktu Działu 1,
świadomie odłożone do czasu decyzji, skąd segment ma pochodzić przy ręcznym
budowaniu oferty od zera (bez leada). Reguły v1 działają na wariancie i
wolumenie; segment może dojść jako dodatkowy wymiar w kolejnej wersji reguł
bez zmiany API tego działu.
