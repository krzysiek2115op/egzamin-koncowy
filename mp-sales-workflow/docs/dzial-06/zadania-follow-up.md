<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 6 — ZADANIA FOLLOW-UP (d+3 / d+7).
Jeden plik na dział (zasada projektu).

ŹRÓDŁA OFICJALNE — dokumentacja techniczna narzędzi używanych przez ten dział:
1. WP-Cron — WordPress Plugin Handbook, "What is WP-Cron".
   URL:     https://developer.wordpress.org/plugins/cron/
   Pobrano: 2026-07-28.
2. Hooking WP-Cron Into the System Task Scheduler — WordPress Plugin Handbook.
   URL:     https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/
   Pobrano: 2026-07-28.
3. wp_schedule_single_event() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/functions/wp_schedule_single_event/
   Pobrano: 2026-07-28.
4. wp_next_scheduled() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/functions/wp_next_scheduled/
   Pobrano: 2026-07-28.

Dotyczy par Działu 6:
 - A6.1 "harmonogram" / K6.1 "warunek-4.5" — terminy d+3 i d+7 planowane jako
   zdarzenia jednorazowe (źródło 3); źródła 1-2 wyjaśniają, dlaczego sam
   WP-Cron NIE gwarantuje wykonania o czasie,
 - A6.2 "deduplikacja" / K6.2 "brak-duplikatów-zadań" — źródło 3 opisuje
   zarówno pułapkę (zdarzenia w odstępie 10 minut bywają pomijane), jak i
   zalecany sposób wykrycia duplikatu (źródło 4).
-->

# Dział 6 — zadania follow-up: dokumentacja źródłowa

## Czym jest WP-Cron (cytat, źródło 1)

"WP-Cron is how WordPress handles scheduling time-based tasks in WordPress.
Several WordPress core features, such as checking for updates and publishing
scheduled post, utilize WP-Cron."

## Ograniczenie: brak ciągłego działania (cytat, źródło 1)

"WP-Cron does not run constantly as the system cron does; it is only triggered
on page load. Scheduling errors could occur if you schedule a task for 2:00PM
and no page loads occur until 5:00PM."

## Rozwiązanie: harmonogram systemowy (cytat, źródło 2)

"As mentioned, WP-Cron does not run continuously, which can be an issue if
there are critical tasks that must run on time. There is an easy solution for
this. Simply set up your system’s task scheduler to run on the intervals you
desire (or at the specific time needed). The easiest solution is to use a tool
to make a web request to the wp-cron.php file."

## Wyłączenie pseudo-crona po stronie WordPressa (cytat, źródło 2)

"After scheduling the task on your system, there is one more step to complete.
WordPress will continue to run WP-Cron on each page load. This is no longer
necessary and will contribute to extra resource usage on your server. WP-Cron
can be disabled in the wp-config.php file. Open the wp-config.php file for
editing and add the following line:

```
define( 'DISABLE_WP_CRON', true );
```
"

## Planowanie zdarzenia jednorazowego (cytat, źródło 3)

Sygnatura: `wp_schedule_single_event( int $timestamp, string $hook, array $args = array(), bool $wp_error = false ): bool|WP_Error`

"Schedules an event to run only once. Schedules a hook which will be triggered
by WordPress at the specified UTC time. The action will trigger when someone
visits your WordPress site if the scheduled time has passed."

Parametr (cytat): "$timestamp int required — Unix timestamp (UTC) for when to
next run the event."

## Pułapka duplikatów i zalecane sprawdzenie (cytat, źródło 3)

"Note that scheduling an event to occur within 10 minutes of an existing event
with the same action hook will be ignored unless you pass unique $args values
for each scheduled event. Use wp_next_scheduled() to prevent duplicate events.
Use wp_schedule_event() to schedule a recurring event."

## Odczyt najbliższego terminu (cytat, źródło 4)

Sygnatura: `wp_next_scheduled( string $hook, array $args = array() ): int|false`
