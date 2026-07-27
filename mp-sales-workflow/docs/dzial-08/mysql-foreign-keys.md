<!--
ŹRÓDŁA OFICJALNE:
1) FOREIGN KEY Constraints — MySQL 8.4 Reference Manual, sekcja 15.1.20.5.
   URL:     https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html
   Pobrano: 2026-07-27.
2) CREATE TABLE Statement — MySQL 8.4 Reference Manual, sekcja 15.1.20.
   URL:     https://dev.mysql.com/doc/refman/8.4/en/create-table.html
   Pobrano: 2026-07-27.

Dotyczy: integralności referencyjnej między tabelą procesów (wp_mp_sw_flow) a
tabelami zależnymi — zadaniami follow-up (Dział 6) i kolejką powiadomień
(Dział 7). Więzy zakłada Dział 8 (zapis jedną transakcją) przez metodę
MP_Sales_Workflow_DB::maybe_add_foreign_keys(), bo dbDelta() ich nie tworzy —
patrz docs/dzial-08/dbdelta-i-transakcje.md oraz
includes/db/class-mp-sales-workflow-db.php.

Dziennik aktywności (wp_mp_sw_activity) celowo NIE dostaje więzu — audyt ma
przetrwać usunięcie procesu (kryterium odbioru: "log zdarzeń umożliwiający
odtworzenie historii zmian statusów i wysyłek").
-->

# Klucze obce w MySQL — dokumentacja źródłowa

## Składnia więzu (cytat, źródło 1)

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

## Akcja ON DELETE CASCADE (cytat, źródło 1)

"When an `UPDATE` or `DELETE` operation affects a key value in the parent table
that has matching rows in the child table, the result depends on the
_referential action_ specified by `ON UPDATE` and `ON DELETE` subclauses of the
`FOREIGN KEY` clause. Referential actions include:

- `CASCADE`: Delete or update the row from the parent table and automatically
  delete or update the matching rows in the child table. Both `ON DELETE
  CASCADE` and `ON UPDATE CASCADE` are supported."

## Warunki i ograniczenia (cytaty, źródło 1)

"Parent and child tables must use the same storage engine, and they cannot be
defined as temporary tables."

"Creating a foreign key constraint requires the `REFERENCES` privilege on the
parent table."

"Corresponding columns in the foreign key and the referenced key must have
similar data types. The size and sign of fixed precision types such as `INTEGER`
and `DECIMAL` must be the same."

"MySQL requires indexes on foreign keys and referenced keys so that foreign key
checks can be fast and not require a table scan."

## Dodawanie więzu przez ALTER TABLE (cytat, źródło 1)

"When you add a foreign key constraint to a table using `ALTER TABLE`, remember
to first create an index on the column(s) referenced by the foreign key."

## Usuwanie więzu (cytat, źródło 1)

"You can drop a foreign key constraint using the following `ALTER TABLE` syntax:

```
ALTER TABLE tbl_name DROP FOREIGN KEY fk_symbol;
```
"

## Silniki inne niż InnoDB (cytat, źródło 2)

"For other storage engines, MySQL Server parses and ignores the `FOREIGN KEY`
syntax in `CREATE TABLE` statements."
