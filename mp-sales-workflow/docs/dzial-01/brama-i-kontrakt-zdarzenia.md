<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 1 — BRAMA I KONTRAKT ZDARZENIA.
Jeden plik na dział (zasada projektu).

ŹRÓDŁA OFICJALNE:
1. Nonces — WordPress Common APIs Handbook, "Nonces".
   URL:     https://developer.wordpress.org/apis/security/nonces/
   Pobrano: 2026-07-28.
2. UNIQUE index — MySQL 8.4 Reference Manual, "CREATE INDEX Statement".
   URL:     https://dev.mysql.com/doc/refman/8.4/en/create-index.html
   Pobrano: 2026-07-27.
3. current_user_can() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/functions/current_user_can/
   Pobrano: 2026-07-28.
4. wp_generate_uuid4() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/functions/wp_generate_uuid4/
   Pobrano: 2026-07-28.
5. wp_is_uuid() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/functions/wp_is_uuid/
   Pobrano: 2026-07-28.

Dotyczy par Działu 1 diagramu LP.3:
 - A1.2 "źródło" / K1.2 "kto-woła"  — wywołanie ręczne wymaga nonce'a i
   uprawnienia; bez nich 403 PRZED wykonaniem pracy (źródła 1 i 3),
 - A1.3 "idempotencja" / K1.3 "klucz-idempotencji" — ten sam event_id nigdy
   nie obsłuży zdarzenia dwa razy; realizuje to indeks UNIQUE na kolumnie
   event_id, nie sprawdzenie w kodzie (źródło 2).
Ten sam cytat o wielu NULL-ach stoi za kolumną open_key w tabeli zadań
(Krytyk K6.2) — patrz includes/db/class-mp-sales-workflow-db.php.
-->

# Dział 1 — brama i kontrakt zdarzenia: dokumentacja źródłowa

## Czym jest nonce (cytat, źródło 1)

"A nonce is a “number used once” to help protect URLs and forms from certain
types of misuse, malicious or otherwise. Technically, WordPress nonces aren’t
strictly numbers; they are a hash made up of numbers and letters. Nor are they
used only once: they have a limited “lifetime” after which they expire."

## Weryfikacja nonce'a i reakcja na porażkę (cytat, źródło 1)

"wp_verify_nonce() specifying the nonce and the string representing the action.
For example: `wp_verify_nonce( $_POST['my_nonce'], 'process-comment'.$comment_id );`
If the result is false, do not continue processing the request. Instead, take
some appropriate action. The usual action is to call wp_nonce_ays(), which sends
a “403 Forbidden” response."

## Weryfikacja przy wywołaniu AJAX (cytat, źródło 1)

"check_ajax_referer() specifying the string representing the action. For
example: `check_ajax_referer( 'process-comment' );` This call checks the nonce
(but not the referrer), and if the check fails then by default it terminates
script execution."

## Co gwarantuje UNIQUE (cytat, źródło 2)

"A `UNIQUE` index creates a constraint such that all values in the index must
be distinct. An error occurs if you try to add a new row with a key value that
matches an existing row."

## UNIQUE a wartości NULL (cytat, źródło 2)

"A `UNIQUE` index permits multiple `NULL` values for columns that can contain
`NULL`."

## Sprawdzenie uprawnienia bieżącego użytkownika (cytat, źródło 3)

"Returns whether the current user has the specified capability."

"This function also accepts an ID of an object to check against if the
capability is a meta capability. Meta capabilities such as edit_post and
edit_user are capabilities used by the map_meta_cap() function to map to
primitive capabilities that a user or role has."

## Generowanie identyfikatora zdarzenia (cytat, źródło 4)

"Generates a random UUID (version 4)."

Zwraca (cytat): "string UUID."

## Walidacja identyfikatora zdarzenia (cytat, źródło 5)

Sygnatura: `wp_is_uuid( mixed $uuid, int $version = null ): bool`

"Validates that a UUID is valid."

Parametry (cytat): "$uuid mixed required — UUID to check. $version int optional
— Specify which version of UUID to check against. Default is none, to accept
any UUID version. Otherwise, only version allowed is 4."

Zwraca (cytat): "bool The string is a valid UUID or false on failure."
