<!--
ŹRÓDŁO ORYGINALNE — konfiguracja projektu (deterministyczne reguły).
Jeden plik na dział (zasada projektu).
Dotyczy: Dział 7 — agent 7.2 (scoring + przypisanie handlowca).
-->

# Scoring i przypisanie handlowca — konfiguracja projektu (dokumentacja źródłowa)

Reguły deterministyczne (bez zgadywania per zgłoszenie). Utrzymywane w kodzie agenta 7.2.

## Scoring leada (punkty)

| Warunek | Punkty |
|---|---|
| VAT UE ważny (VIES) | +30 |
| Status firmy = `Czynny` (Biała lista) | +20 |
| Podany telefon | +10 |
| Zgoda marketingowa | +10 |
| Segment ∈ {Produkcja, IT, Budownictwo} | +15 |

Wynik zapisywany w `wp_mp_leads.score`.

## Przypisanie handlowca

**Od 1.3.7 ta wtyczka handlowca NIE WYBIERA — pyta o niego.**

- Pytanie idzie filtrem `mp_lead_assign_salesman` z kompletem danych leada
  (NIP, kraj, język, segment, e-mail).
- Odpowiada ten, kto ma czym odpowiedzieć: wtyczka 3 (MP Sales Workflow, Dział 4)
  dobiera handlowca po kraju, języku, zespole i obciążeniu.
- Brak odpowiedzi → `salesman_id = NULL`. Puste pole jest uczciwsze niż wpisany
  przypadkowy człowiek; przypisania dokonuje się później ręcznie.
- Rotacja i przepisanie procesu przez managera docierają tu zdarzeniem
  `mp_sw_flow_updated` (pole `assigned_user_id`), więc BD-3 nadąża za BD-1.

Wynik zapisywany w `wp_mp_leads.salesman_id`.

### Co było wcześniej i dlaczego to był błąd

Do 1.3.6 włącznie wybór robiła ta wtyczka, deterministycznie:
`index = abs(crc32(nip)) % liczba_handlowców`. Hasz numeru NIP rozrzucał leady
równomiernie po wszystkich kontach z rolą handlowca — bez kraju, języka, zespołu
i obciążenia, czyli **bez niczego, co przesądza, czy handlowiec jest odpowiedni**.
Ponieważ wtyczka 3 równolegle dobierała właściciela procesu naprawdę, jedno
zgłoszenie kończyło się DWOMA różnymi handlowcami: innym w BD-3 i innym w BD-1.
Żaden test tego nie widział, bo każda wtyczka **z osobna** była spójna.

## Uwaga
Rola `mp_handlowiec` (oraz administrator / manager sprzedaży) — utworzenie ról to część
spraw technicznych (krok 4). Rolę definiują wtyczki 1 i 3, a uprawnienia każda
dokłada **własne**, po `add_role()` — przy istniejącej roli `add_role()` nic nie robi,
więc inaczej komplet dostawałaby tylko ta aktywowana pierwsza.

---

<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/classes/wpdb/insert/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 7 — agent 7.3 (zapis leada); także działy 8/9 (zapis logu).
-->

# wpdb::insert() — dokumentacja oficjalna

## Sygnatura

```php
wpdb::insert( string $table, array $data, string[]|string $format = null ): int|false
```

## Opis (cytat)

"Inserts a row into the table."

## Parametry (cytaty)

- `$table` (string, wymagany) — "Table name."
- `$data` (array, wymagany) — "Data to insert (in column => value pairs). Both `$data`
  columns and `$data` values should be "raw" (neither should be SQL escaped). Sending a
  null value will cause the column to be set to NULL – the corresponding format is ignored
  in this case."
- `$format` (string[]|string, opcjonalny) — "An array of formats to be mapped to each of
  the value in `$data`. If string, that format will be used for all of the values in
  `$data`. A format is one of `'%d'`,`'%f'`, `'%s'` (integer, float, string). If omitted,
  all values in `$data` will be treated as strings unless otherwise specified in
  wpdb::$field_types."

## Zwraca (cytat)

"The number of rows inserted, or false on error."

## Przykład

```php
global $wpdb;
$wpdb->insert(
    'my_table',
    array( 'name' => 'John', 'age' => 30 ),
    array( '%s', '%d' )
);
$id = $wpdb->insert_id;
```
