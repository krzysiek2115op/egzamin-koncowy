<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 4 — PRZYPISANIE HANDLOWCA.
Jeden plik na dział (zasada projektu).

ŹRÓDŁO OFICJALNE:
Working with User Metadata — WordPress Plugin Handbook, dział "Users".
URL:     https://developer.wordpress.org/plugins/users/working-with-user-metadata/
Pobrano: 2026-07-28.

Dotyczy par Działu 4 diagramu LP.3: A4.1 "dobór" (kraj × język × zakres),
A4.2 "rotacja" (po ostatnim przypisaniu), A4.3 "uzasadnienie". Konfiguracja
handlowca — kraj, język, zespół, ostatnie przypisanie — trzymana jest w polach
dodatkowych użytkownika (usermeta `mp_*`), które Dział 2 wczytuje jednym
strzałem; Dział 4 pracuje już wyłącznie na tym snapshocie.
-->

# Dział 4 — przypisanie handlowca: dokumentacja źródłowa

## Funkcje pól dodatkowych użytkownika (cytat)

"add_user_meta(), update_user_meta(), delete_user_meta() and get_user_meta()."

## Dodawanie wartości (cytat)

"```
add_user_meta( int $user_id, string $meta_key, mixed $meta_value, bool $unique = false );
```
Please refer to the Function Reference about add_user_meta() for full
explanation about the used parameters."

## Aktualizacja wartości (cytat)

"```
update_user_meta( int $user_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' );
```
"
