<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 6 — ZADANIA FOLLOW-UP (d+3 / d+7).
Jeden plik na dział (zasada projektu).

ŹRÓDŁA OFICJALNE:
1. WP-Cron — WordPress Plugin Handbook, "What is WP-Cron".
   URL:     https://developer.wordpress.org/plugins/cron/
   Pobrano: 2026-07-28.
2. Hooking WP-Cron Into the System Task Scheduler — WordPress Plugin Handbook.
   URL:     https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/
   Pobrano: 2026-07-28.

Dotyczy par Działu 6 diagramu LP.3: A6.1 "harmonogram" / K6.1 "warunek-4.5"
(zadanie aktywuje się TYLKO gdy status niezmieniony) oraz A6.2 "deduplikacja"
/ K6.2 "brak-duplikatów-zadań". Cytat ze źródła 1 jest powodem, dla którego
termin d+3 i d+7 NIE może być traktowany jako gwarancja czasu wykonania, a
źródło 2 opisuje jedyny sposób, by wykonanie faktycznie następowało o czasie.
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
