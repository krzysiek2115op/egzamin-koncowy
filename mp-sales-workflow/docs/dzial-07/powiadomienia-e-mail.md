<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 7 — POWIADOMIENIA E-MAIL.
Jeden plik na dział (zasada projektu).

ŹRÓDŁO OFICJALNE:
wp_mail() — WordPress Code Reference.
URL:     https://developer.wordpress.org/reference/functions/wp_mail/
Pobrano: 2026-07-28.

Dotyczy par Działu 7 diagramu LP.3: A7.1 "adresaci", A7.2 "treść", A7.3
"kolejka" / K7.3 "zero-wysyłki-w-żądaniu". Pierwszy cytat jest wprost powodem,
dla którego kolumna `status` w tabeli powiadomień ma stany kolejki i licznik
prób: zwrócenie prawdy przez wp_mail() NIE oznacza, że wiadomość dotarła.
-->

# Dział 7 — powiadomienia e-mail: dokumentacja źródłowa

## Co robi funkcja i czego NIE gwarantuje (cytat)

"Sends an email, similar to PHP’s mail function."

"A true return value does not automatically mean that the user received the
email successfully. It just only means that the method used was able to process
the request without any errors."

## Typ treści (cytat)

"The default content type is ‘text/plain’ which does not allow using HTML."

## Nadawca — filtry (cytat)

"‘wp_mail_from‘ and ‘wp_mail_from_name‘ are run on the sender email address and
name. The return values are reassembled into a ‘from’ address. If only
‘wp_mail_from‘ returns a value, then just the email address will be used with
no name."
