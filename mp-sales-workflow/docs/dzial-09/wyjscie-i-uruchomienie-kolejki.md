<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 9 — WYJŚCIE I URUCHOMIENIE KOLEJKI.
Jeden plik na dział (zasada projektu).

ŹRÓDŁA OFICJALNE:
1. do_action() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/functions/do_action/
   Pobrano: 2026-07-28.
2. wp_send_json_success() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/functions/wp_send_json_success/
   Pobrano: 2026-07-28.

Dotyczy par Działu 9 diagramu LP.3: A9.1 "zdarzenia" / K9.1 "jednokrotność"
(nic nie wychodzi przed COMMIT) oraz A9.2 "odpowiedź" / K9.2
"zakres-odpowiedzi". Zdarzenia wystawiane tym mechanizmem odbierają wtyczki 1
i 2 — dokładnie tak, jak ta wtyczka odbiera `mp_lead_created` i
`mp_offer_created`.
-->

# Dział 9 — wyjście i uruchomienie kolejki: dokumentacja źródłowa

## Wystawienie zdarzenia (cytat, źródło 1)

"Calls the callback functions that have been added to an action hook."

"This function invokes all functions attached to action hook $hook_name. It is
possible to create new action hooks by simply calling this function, specifying
the name of the new hook using the $hook_name parameter."

## Odpowiedź na żądanie AJAX (cytat, źródło 2)

"Sends a JSON response back to an Ajax request, indicating success."

Parametry (cytat): "$value mixed optional — Data to encode as JSON, then print
and die. Default: null. $status_code int optional — The HTTP status code to
output. Default: null. $flags int optional — Options to be passed to
json_encode(). Default 0."
