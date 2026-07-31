<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 8 — ZAPIS: JEDNA TRANSAKCJA.
Jeden plik na dział (zasada projektu); wewnątrz tyle oficjalnych źródeł, ile
dział realnie potrzebuje.

ŹRÓDŁA OFICJALNE:
1. dbDelta() — WordPress Plugin Handbook, "Creating Tables with Plugins".
   URL:     https://developer.wordpress.org/plugins/creating-tables-with-plugins/
   Pobrano: 2026-07-27.
2. START TRANSACTION / COMMIT / ROLLBACK — MySQL 8.4 Reference Manual.
   URL:     https://dev.mysql.com/doc/refman/8.4/en/commit.html
   Pobrano: 2026-07-27.
3. FOREIGN KEY Constraints — MySQL 8.4 Reference Manual, sekcja 15.1.20.5.
   URL:     https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html
   Pobrano: 2026-07-27.
4. CREATE TABLE Statement — MySQL 8.4 Reference Manual, sekcja 15.1.20.
   URL:     https://dev.mysql.com/doc/refman/8.4/en/create-table.html
   Pobrano: 2026-07-27.

Dotyczy: Działu 8 diagramu LP.3 (pary A8.1 plan / A8.2 transakcja / A8.3
dziennik) oraz schematu BD-1 w includes/db/class-mp-sales-workflow-db.php.
-->

# Dział 8 — zapis jedną transakcją: dokumentacja źródłowa

## dbDelta() — wymogi formatowania SQL (cytaty, źródło 1)

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

## Transakcje MySQL (cytaty, źródło 2)

"START TRANSACTION or BEGIN start a new transaction."

"COMMIT commits the current transaction, making its changes permanent."

"ROLLBACK rolls back the current transaction, canceling its changes."

"By default, MySQL runs with autocommit mode enabled. This means that, when
not otherwise inside a transaction, each statement is atomic, as if it were
surrounded by START TRANSACTION and COMMIT."

"SET autocommit disables or enables the default autocommit mode for the
current session."

## Składnia więzu integralności (cytat, źródło 3)

"The essential syntax for a defining a foreign key constraint in a `CREATE
TABLE` or `ALTER TABLE` statement includes the following:

```
[CONSTRAINT [symbol]] FOREIGN KEY
    [index_name] (col_name, ...)
    REFERENCES tbl_name (col_name,...)
    [ON DELETE reference_option]
    [ON UPDATE reference_option]

reference_option:
    RESTRICT | CASCADE | SET NULL | NO ACTION | SET DEFAULT
```
"

## Akcja ON DELETE CASCADE (cytat, źródło 3)

"When an `UPDATE` or `DELETE` operation affects a key value in the parent table
that has matching rows in the child table, the result depends on the
_referential action_ specified by `ON UPDATE` and `ON DELETE` subclauses of the
`FOREIGN KEY` clause. Referential actions include:

- `CASCADE`: Delete or update the row from the parent table and automatically
  delete or update the matching rows in the child table. Both `ON DELETE
  CASCADE` and `ON UPDATE CASCADE` are supported."

## Warunki i ograniczenia więzów (cytaty, źródło 3)

"Parent and child tables must use the same storage engine, and they cannot be
defined as temporary tables."

"Creating a foreign key constraint requires the `REFERENCES` privilege on the
parent table."

"Corresponding columns in the foreign key and the referenced key must have
similar data types. The size and sign of fixed precision types such as `INTEGER`
and `DECIMAL` must be the same."

"MySQL requires indexes on foreign keys and referenced keys so that foreign key
checks can be fast and not require a table scan."

## Dodawanie i usuwanie więzu (cytaty, źródło 3)

"When you add a foreign key constraint to a table using `ALTER TABLE`, remember
to first create an index on the column(s) referenced by the foreign key."

"You can drop a foreign key constraint using the following `ALTER TABLE` syntax:

```
ALTER TABLE tbl_name DROP FOREIGN KEY fk_symbol;
```
"

## Silniki inne niż InnoDB (cytat, źródło 4)

"For other storage engines, MySQL Server parses and ignores the `FOREIGN KEY`
syntax in `CREATE TABLE` statements."
