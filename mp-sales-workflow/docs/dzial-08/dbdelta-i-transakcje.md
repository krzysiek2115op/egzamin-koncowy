<!--
ŹRÓDŁA OFICJALNE:
1. dbDelta() — WordPress Plugin Handbook, "Creating Tables with Plugins".
   URL:     https://developer.wordpress.org/plugins/creating-tables-with-plugins/
   Pobrano: 2026-07-27.
2. START TRANSACTION / COMMIT / ROLLBACK — MySQL 8.4 Reference Manual.
   URL:     https://dev.mysql.com/doc/refman/8.4/en/commit.html
   Pobrano: 2026-07-27.
Dotyczy: Działu 8 (ZAPIS — JEDNA TRANSAKCJA) diagramu LP.3 oraz schematu BD-1
w includes/db/class-mp-sales-workflow-db.php, gdzie reguły dbDelta() są
zastosowane przy CREATE TABLE.
-->

# dbDelta() i transakcje MySQL — dokumentacja źródłowa

## dbDelta() — wymogi formatowania SQL (cytaty)

"You must put each field on its own line in your SQL statement."

"You must have two spaces between the words PRIMARY KEY and the definition
of your primary key."

"You must use the key word KEY rather than its synonym INDEX and you must
include at least one KEY."

"You must not use any apostrophes or backticks around field names."

"Field types must be all lowercase."

"SQL keywords, like CREATE TABLE and UPDATE, must be uppercase."

"You must specify the length of all fields that accept a length parameter.
int(11), for example."

## Transakcje MySQL (cytaty)

"START TRANSACTION or BEGIN start a new transaction."

"COMMIT commits the current transaction, making its changes permanent."

"ROLLBACK rolls back the current transaction, canceling its changes."

"By default, MySQL runs with autocommit mode enabled. This means that, when
not otherwise inside a transaction, each statement is atomic, as if it were
surrounded by START TRANSACTION and COMMIT."

"SET autocommit disables or enables the default autocommit mode for the
current session."
