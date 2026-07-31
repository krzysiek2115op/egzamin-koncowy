<!--
ŹRÓDŁA OFICJALNE — WordPress Developer Reference.
1. do_action()            — https://developer.wordpress.org/reference/functions/do_action/
2. wp_send_json_success()  — https://developer.wordpress.org/reference/functions/wp_send_json_success/
Pobrano: 2026-07-24.
Dotyczy: Dział 11 — Agent 11.1 "zdarzenie" (do_action) i Agent 11.2 "odpowiedź"
(kształt JSON), krytyk "jednokrotność" (did_action).
-->

# do_action() / wp_send_json_success() — dokumentacja źródłowa

## do_action() (cytat)

"Calls the callback functions that have been added to an action hook."

Dokumentacja wprost potwierdza (kod źródłowy funkcji, globalna tablica
`$wp_actions`): KAŻDE wywołanie `do_action()` z danym tagiem zwiększa licznik
tego hooka — funkcja `did_action( $tag )` odczytuje ten licznik. To podstawa
weryfikacji krytyka 11.1 "jednokrotność": Agent 11.1 odczytuje `did_action()`
PRZED i PO własnym wywołaniu `do_action( 'mp_offer_created', ... )` — różnica
MUSI wynosić dokładnie 1 (nie 0 — zdarzenie pominięte; nie >1 — zdarzenie
wystawione wielokrotnie w jednym przebiegu).

## wp_send_json_success() (cytat)

"Sends a JSON response back to an Ajax request, indicating success."

Sygnatura: `wp_send_json_success( mixed $value = null, int $status_code = null, int $flags )`.
Kształt odpowiedzi: `{ "success": true }` (bez danych) albo
`{ "success": true, "data": $value }` (z danymi) — funkcja kończy żądanie
(`wp_die()` wewnątrz), więc jest ostatnią rzeczą wykonywaną w handlerze AJAX.

## Zastosowanie w tym dziale

Agent 11.1 wystawia `do_action( 'mp_offer_created', $offer_id, $payload )`
DOKŁADNIE RAZ, WYŁĄCZNIE po tym, jak Dział 10 realnie zakończył się sukcesem
— co (dzięki poprawce `MP_OB_Pipeline::set_transactional_until()`, patrz
docblock Działu 10) oznacza: PO faktycznym SQL COMMIT, nie tylko po zwrocie
z Działu 10 wewnątrz PHP. Agent 11.2 buduje finalną odpowiedź JSON zgodną
z kontraktem: `success, offer_id, offer_number, version, pdf_url, status,
trace_id` — `MP_Offer_Builder_Ajax` (istniejący endpoint z Kroku 2) opakowuje
ten wynik w `wp_send_json_success()`/`wp_send_json_error()` zależnie od
`is_ok()` wyniku pipeline'u. `pdf_url` wskazuje na CHRONIONY endpoint pobierania
(decyzja architektoniczna C) — kontrakt URL (nazwa akcji + nonce per-oferta)
ustalony już teraz, handler z pełną kontrolą dostępu (capability + właściciel
oferty) powstaje w Kroku 4.
