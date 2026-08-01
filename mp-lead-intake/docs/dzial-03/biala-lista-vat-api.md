<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
Jeden plik na dział (zasada projektu).
URL:    https://wl-api.mf.gov.pl/  (API Wykazu podatników VAT — Biała lista)
Pobrano: 2026-07-21
Dotyczy: Dział 3 — agent 3.3 (status firmy).
-->

# Biała lista VAT — API (dokumentacja oficjalna)

Host: `https://wl-api.mf.gov.pl`

## Endpoint — wyszukanie po NIP

```
GET /api/search/nip/{nip}?date=YYYY-MM-DD
```
- `{nip}` — numer NIP (10 cyfr).
- `date` (wymagany) — data w formacie `YYYY-MM-DD`.

Przykład: `/api/search/nip/1111111111?date=2019-11-19`

## Odpowiedź (JSON) — struktura

Dane opakowane w obiekt `result`:

- **`subject`** — obiekt podmiotu:
  - `name` — nazwa firmy/osoby,
  - `nip` — numer NIP,
  - `statusVat` — status VAT: **`Czynny`**, **`Zwolniony`**, **`Niezarejestrowany`**,
  - `regon`, `krs`, `accountNumbers`, `residenceAddress`, `workingAddress`.
- **`requestDateTime`** — znacznik czasu odpowiedzi,
- **`requestId`** — identyfikator zapytania.

## Zastosowanie w dziale 3
Agent 3.3 pobiera `statusVat` dla NIP na dzień bieżący, z **timeoutem**, **cache**
(transient) i **fallbackiem** (awaria → status „nieustalony", bez STOP). Status jest
informacyjny (nie przerywa pipeline).

---

<!--
ŹRÓDŁA — algorytm sumy kontrolnej NIP (standard krajowy, powszechnie stosowany).
- https://pl.wikibooks.org/wiki/Kody_%C5%BAr%C3%B3d%C5%82owe/Implementacja_NIP (pobrano 2026-07-22)
- http://www.algorytm.org/numery-identyfikacyjne/nip.html (zweryfikowano 2026-07-22, opisuje ten sam algorytm)
Uwaga uczciwości (Golden Rule #2): nie znaleziono jednego kanonicznego URL-a rządowego
publikującego wprost ten wzór (podstawa prawna to struktura NIP wg przepisów o ewidencji
podatników — Ministerstwo Finansów/KAS nie publikuje wzoru w formie jednej strony referencyjnej
analogicznej do developer.wordpress.org). Powyższe źródła niezależnie potwierdzają IDENTYCZNY
algorytm (wagi, modulo, regułę ważności) — cytowane jako zweryfikowane, publicznie dostępne
opisy powszechnie stosowanego standardu, nie jako oficjalny akt prawny.
Dotyczy: Dział 3 — agent 3.1 (weryfikacja NIP offline).
-->

# NIP — algorytm sumy kontrolnej (dokumentacja źródłowa)

NIP składa się z **10 cyfr**. Ostatnia (10.) cyfra jest **cyfrą kontrolną**
wyliczaną z pierwszych 9 cyfr.

## Algorytm

1. Wagi dla 9 pierwszych cyfr: **`6, 5, 7, 2, 3, 4, 5, 6, 7`**.
2. Suma = Σ (cyfra_i × waga_i) dla i = 1..9.
3. Reszta = suma **mod 11**.
4. Jeśli reszta = **10** → NIP jest **niepoprawny** (taka cyfra kontrolna nie istnieje).
5. W przeciwnym razie NIP jest poprawny, gdy **reszta == cyfra kontrolna** (10. cyfra).

## Przykład (pseudokod)

```
nip = "1234563218"
wagi = [6,5,7,2,3,4,5,6,7]
suma = 1*6 + 2*5 + 3*7 + 4*2 + 5*3 + 6*4 + 3*5 + 2*6 + 1*7
kontrola = suma % 11
poprawny = (kontrola != 10) && (kontrola == 8)
```

## Uwaga
Ten dział sprawdza wyłącznie **sumę kontrolną** (poprawność formalna numeru).
Fakt rejestracji/aktywności podatnika weryfikuje osobno **Biała lista VAT** (agent 3.3),
a ważność VAT UE — **VIES** (agent 3.2).

---

<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie)
URL:    https://ec.europa.eu/taxation_customs/vies/  (REST API)
Pobrano: 2026-07-21
Dotyczy: Dział 3 — agent 3.2 (weryfikacja VAT UE).
-->

# VIES REST API — weryfikacja numeru VAT UE (dokumentacja oficjalna)

## Endpoint

```
GET /taxation_customs/vies/rest-api/ms/{countryCode}/vat/{vatNumber}
```
Host: `https://ec.europa.eu`

- `{countryCode}` — dwuliterowy kod państwa UE (np. `PL`).
- `{vatNumber}` — numer VAT (dla PL: 10 cyfr NIP).

## Odpowiedź (JSON) — kluczowe pola

| Pole | Typ | Opis |
|------|-----|------|
| `isValid` | boolean | Czy numer VAT jest ważny |
| `requestDate` | string | Znacznik czasu (ISO 8601) |
| `userError` | string | Kod błędu, gdy walidacja się nie powiedzie (np. `INVALID`) |
| `name` | string | Nazwa firmy (`---` gdy niedostępna) |
| `address` | string | Adres firmy (`---` gdy niedostępny) |
| `vatNumber` | string | Zweryfikowany numer VAT |
| `originalVatNumber` | string | Przesłany numer VAT |

Gdy numer jest niepoprawny → `"isValid": false` oraz wartości `---` w polach firmy.

## Zastosowanie w dziale 3
Agent 3.2 wywołuje endpoint dla `PL` (lub kraju z kontekstu), z **timeoutem**,
**cache** (transient) i **łagodnym fallbackiem** — awaria VIES nie zatrzymuje pipeline
(wynik „nieustalony"), a jednoznaczny `isValid=false` → STOP (krytyk 3.2).

---

<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie z developer.wordpress.org)
URL (przegląd):  https://developer.wordpress.org/plugins/cron/
URL (funkcje):   https://developer.wordpress.org/reference/functions/wp_schedule_single_event/
                 https://developer.wordpress.org/reference/functions/wp_next_scheduled/
                 https://developer.wordpress.org/reference/functions/wp_schedule_event/
                 https://developer.wordpress.org/reference/functions/wp_clear_scheduled_hook/
Pobrano: 2026-07-21
Dotyczy: Dział 3 — weryfikacja VAT/statusu firmy W TLE (async). Planowanie i sprzątanie
         zadań w MP_Lead_Intake_Vat_Verifier (enqueue / reconcile) oraz w aktywacji/
         deaktywacji/uninstall wtyczki.
-->

# WP-Cron — dokumentacja oficjalna

## Przegląd (Plugin Handbook — „WP-Cron")

Czym jest: "WP-Cron is how WordPress handles scheduling time-based tasks in WordPress."

Różnica względem crona systemowego: "WP-Cron does not run constantly as the system cron
does; it is only triggered on page load."

Konsekwencja zależności od odsłon: "Scheduling errors could occur if you schedule a task
for 2:00PM and no page loads occur until 5:00PM."

Przewaga nad cronem systemowym: "With the system scheduler, if the time passes and the task
did not run, it will not be re-attempted. With WP-Cron, all scheduled tasks are put into a
queue and will run at the next opportunity (meaning the next page load)."

---

## wp_schedule_single_event()

### Sygnatura

```php
wp_schedule_single_event( int $timestamp, string $hook, array $args = array(), bool $wp_error = false ): bool|WP_Error
```

### Opis

"Schedules a hook which will be triggered by WordPress at the specified UTC time. The action
will trigger when someone visits your WordPress site if the scheduled time has passed."

"Note that scheduling an event to occur within 10 minutes of an existing event with the same
action hook will be ignored unless you pass unique `$args` values for each scheduled event."

### Parametry

- `$timestamp` (int, wymagany): "Unix timestamp (UTC) for when to next run the event."
- `$hook` (string, wymagany): "Action hook to execute when the event is run."
- `$args` (array, opcjonalny, domyślnie `array()`): "Array containing arguments to pass to the
  hook's callback function. Each value in the array is passed to the callback as an individual
  parameter."
- `$wp_error` (bool, opcjonalny, domyślnie `false`): "Whether to return a WP_Error on failure."

### Zwraca

"True if event successfully scheduled. False or WP_Error on failure."

### Przykład

```php
wp_schedule_single_event( time() + 3600, 'my_new_event', array( $arg1, $arg2, $arg3 ) );
```

---

## wp_next_scheduled()

### Sygnatura

```php
wp_next_scheduled( string $hook, array $args = array() ): int|false
```

### Opis

"Retrieves the timestamp of the next scheduled event for the given hook."

### Parametry

- `$hook` (string, wymagany): "Action hook of the event."
- `$args` (array, opcjonalny, domyślnie `array()`): "Array containing each separate argument to
  pass to the hook's callback function. Although not passed to a callback, these arguments are
  used to uniquely identify the event, so they must match those used when originally scheduling
  the event. If the arguments do not match exactly, the event will not be found."

### Zwraca

"The Unix timestamp (UTC) of the next time the event will occur. False if the event doesn't exist."

### Przykład

```php
$args = array( false );
if ( ! wp_next_scheduled( 'myevent', $args ) ) {
	wp_schedule_event( time(), 'daily', 'myevent', $args );
}
```

---

## wp_schedule_event()

### Sygnatura

```php
wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array(), bool $wp_error = false ): bool|WP_Error
```

### Opis

"Schedules a hook which will be triggered by WordPress at the specified interval. The action
will trigger when someone visits your WordPress site if the scheduled time has passed. Valid
values for the recurrence are 'hourly', 'twicedaily', 'daily', and 'weekly'. These can be
extended using the 'cron_schedules' filter in wp_get_schedules(). Use wp_next_scheduled() to
prevent duplicate events. Use wp_schedule_single_event() to schedule a non-recurring event."

### Parametry

- `$timestamp` (int, wymagany): "Unix timestamp (UTC) for when to next run the event."
- `$recurrence` (string, wymagany): "How often the event should subsequently recur. See
  wp_get_schedules() for accepted values."
- `$hook` (string, wymagany): "Action hook to execute when the event is run."
- `$args` (array, opcjonalny, domyślnie `array()`): "Array containing arguments to pass to the
  hook's callback function...These arguments are used to uniquely identify the scheduled event
  and must match those used when the event was originally scheduled."
- `$wp_error` (bool, opcjonalny, domyślnie `false`): "Whether to return a WP_Error on failure."

### Zwraca

"True if event successfully scheduled. False or WP_Error on failure."

---

## wp_clear_scheduled_hook()

### Sygnatura

```php
wp_clear_scheduled_hook( string $hook, array $args = array(), bool $wp_error = false ): int|false|WP_Error
```

### Opis

"Unschedules all events attached to the hook with the specified arguments."

"This function may return boolean false, but may also return a non-boolean value which
evaluates to false. For information about casting to booleans see the PHP documentation. Use
the `===` operator for testing the return value of this function."

### Parametry

- `$hook` (string, wymagany): "Action hook, the execution of which will be unscheduled."
- `$args` (array, opcjonalny, domyślnie `array()`): "Array containing each separate argument to
  pass to the hook's callback function. Although not passed to a callback, these arguments are
  used to uniquely identify the event, so they must match those used when originally scheduling
  the event. If the arguments do not match exactly, the event will not be found."
- `$wp_error` (bool, opcjonalny, domyślnie `false`): "Whether to return a WP_Error on failure."

### Zwraca

"On success an integer indicating number of events unscheduled (0 indicates no events were
registered with the hook and arguments combination), false or WP_Error if unscheduling one or
more events fail."

---

<!--
ŹRÓDŁO OFICJALNE (skopiowane wiernie, cytaty z developer.wordpress.org)
URL:    https://developer.wordpress.org/reference/functions/wp_remote_get/
Pobrano: 2026-07-21, ponownie zweryfikowano 2026-07-22
Dotyczy: Dział 3 — agenci 3.2 i 3.3 (wywołania HTTP do VIES / Białej listy).
-->

# wp_remote_get() — dokumentacja oficjalna

## Sygnatura

```php
wp_remote_get( string $url, array $args = array() ): array|WP_Error
```

## Opis (cytat)

"Performs an HTTP request using the GET method and returns its response."

## Parametry (cytaty)

- `$url` (string, wymagany) — "URL to retrieve."
- `$args` (array, opcjonalny) — "Request arguments. See WP_Http::request() for information
  on accepted arguments." Domyślnie: `array()`.

## Zwraca (cytat)

"The response or WP_Error on failure. See WP_Http::request() for information on return value."

## Przykład

```php
$response = wp_remote_get( 'https://example.com/api' );
if ( is_wp_error( $response ) ) {
	$error_message = $response->get_error_message();
} else {
	$body = wp_remote_retrieve_body( $response );
}
```
