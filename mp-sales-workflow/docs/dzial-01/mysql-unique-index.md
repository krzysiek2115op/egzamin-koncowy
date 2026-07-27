<!--
ŹRÓDŁO OFICJALNE:
UNIQUE index — MySQL 8.4 Reference Manual, "CREATE INDEX Statement".
URL:     https://dev.mysql.com/doc/refman/8.4/en/create-index.html
Pobrano: 2026-07-27.
Dotyczy: Działu 1 (Agent A1.3 "idempotencja" / Krytyk K1.3 "klucz-idempotencji")
oraz Działu 6 (Krytyk K6.2 "brak-duplikatów-zadań") diagramu LP.3. Obie
gwarancje są w BD-1 realizowane indeksem UNIQUE, nie sprawdzeniem w kodzie —
patrz includes/db/class-mp-sales-workflow-db.php.
-->

# Indeks UNIQUE w MySQL — dokumentacja źródłowa

## Co gwarantuje UNIQUE (cytat)

"A `UNIQUE` index creates a constraint such that all values in the index must
be distinct. An error occurs if you try to add a new row with a key value that
matches an existing row."

## UNIQUE a wartości NULL (cytat)

"A `UNIQUE` index permits multiple `NULL` values for columns that can contain
`NULL`."
