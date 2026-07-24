<!--
ŹRÓDŁO OFICJALNE — WordPress Developer Reference.
URL:     https://developer.wordpress.org/reference/functions/wp_generate_password/
Pobrano: 2026-07-24.
Dotyczy: MP_Offer_Builder_Storage::file_secret() — sekret per-instalację
wmieszany (HMAC) w nazwę pliku PDF na dysku (patrz docblock
final_pdf_path() w includes/class-mp-offer-builder-storage.php), żeby
nazwa pliku nie była odgadywalna z samego numeru/wersji oferty.
-->

# wp_generate_password() — dokumentacja źródłowa

## Opis (cytat)

"Generates a random password drawn from the defined set of characters."

Funkcja "Uses wp_rand() to create passwords with far less predictability
than similar native PHP functions like rand() or mt_rand()."

## Parametry (cytat)

1. `$length` (int, optional) — "The length of password to generate." Domyślnie: 12.
2. `$special_chars` (bool, optional) — "Whether to include standard special characters." Domyślnie: true.
3. `$extra_special_chars` (bool, optional) — "Whether to include other special characters. Used when generating secret keys and salts." Domyślnie: false.

## Wartość zwracana (cytat)

"string The random password."

## Zastosowanie w tym pluginie

`wp_generate_password( 64, false, false )` — 64 znaki, bez znaków
specjalnych (sekret trafia do `wp_options` i do `hash_hmac()`, nie musi
być "hasłem" w sensie UI). Generowany RAZ, przy pierwszym użyciu, i
zapisywany w opcji (autoload=false) — kolejne wywołania odczytują tę samą
wartość.
