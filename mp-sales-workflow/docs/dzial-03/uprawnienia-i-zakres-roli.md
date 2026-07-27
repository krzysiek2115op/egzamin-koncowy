<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 3 — UPRAWNIENIA I ZAKRES ROLI.
Jeden plik na dział (zasada projektu).

ŹRÓDŁO OFICJALNE:
Roles and Capabilities — WordPress Plugin Handbook, dział "Users".
URL:     https://developer.wordpress.org/plugins/users/roles-and-capabilities/
Pobrano: 2026-07-28.

Dotyczy par Działu 3 diagramu LP.3: A3.1 "aktor" / K3.1 "prawo-do-operacji"
(operacja spoza uprawnień roli = 403, nigdy ciche zawężenie) oraz A3.2
"zakres" / K3.2 "cięcie-zakresu". Odnosi się też do kryterium odbioru ze
zlecenia: "Działające role: administrator, manager sprzedaży, handlowiec".
-->

# Dział 3 — uprawnienia i zakres roli: dokumentacja źródłowa

## Czym jest rola (cytat)

"A role defines a set of capabilities for a user. For example, what the user
may see and do in his dashboard. By default, WordPress have six roles: Super
Admin, Administrator, Editor, Author, Contributor, Subscriber. More roles can
be added and the default roles can be removed."

## Dodawanie uprawnień do roli (cytat)

"```
function wporg_simple_role_caps() {
	// Gets the simple_role role object.
	$role = get_role( 'simple_role' );

	// Add a new capability.
	$role->add_cap( 'edit_others_posts', true );
}
// Add simple_role capabilities, priority must be after the initial role definition.
add_action( 'init', 'wporg_simple_role_caps', 11 );
```
"

## Sprawdzanie uprawnienia bieżącego użytkownika (cytat)

"current_user_can() is a wrapper function for user_can() using the current user
object as the $user parameter. Use this in scenarios where back-end and
front-end areas should require a certain level of privileges to access and/or
modify.

```
current_user_can( $capability );
```
"
