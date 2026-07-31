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
