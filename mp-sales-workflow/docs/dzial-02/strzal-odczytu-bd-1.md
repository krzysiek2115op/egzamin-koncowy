<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 2 — STRZAŁ ODCZYTU (BD-1).
Jeden plik na dział (zasada projektu).

ŹRÓDŁO OFICJALNE:
WP_User_Query — WordPress Code Reference, "Core class used for querying users".
URL:     https://developer.wordpress.org/reference/classes/wp_user_query/
Pobrano: 2026-07-28.

Dotyczy par Działu 2 diagramu LP.3: A2.1 "handlowcy" (lista kandydatów wraz z
meta), A2.4 "obciążenie" (rotacja liczona z danych, nie z pamięci procesu)
oraz bramki "jeden-odczyt: db_reads = 1". Klasa czyta wp_users i wp_usermeta
jednym zapytaniem — dlatego snapshot działu 2 powstaje w JEDNYM strzale, a
działy 3-7 pracują już wyłącznie na nim.
-->

# Dział 2 — strzał odczytu: dokumentacja źródłowa

## Do czego służy klasa (cytat)

"Core class used for querying users."

"This class allows querying WordPress database tables ‘wp_users‘ and
‘wp_usermeta‘."

## Sposób użycia (cytat)

"```
$args = array( . . . );

// The Query
$user_query = new WP_User_Query( $args );
```
"

## Zapytanie po polach dodatkowych — meta_query (cytat)

"meta_query (array) – Custom field parameters (available with Version 3.5).

- key (string) – Custom field key.
- value (string | array) – Custom field value (Note: Array support is limited
  to a compare value of ‘IN’, ‘NOT IN’, ‘BETWEEN’, ‘NOT BETWEEN’, ‘EXISTS’ or
  ‘NOT EXISTS’)
- compare (string) – Operator to test."
