<!--
DOKUMENTACJA ŹRÓDŁOWA DZIAŁU 7 — POWIADOMIENIA E-MAIL.
Jeden plik na dział (zasada projektu).

ŹRÓDŁA OFICJALNE — dokumentacja techniczna narzędzi używanych przez ten dział:
1. wp_mail() — WordPress Code Reference.
   URL:     https://developer.wordpress.org/reference/functions/wp_mail/
   Pobrano: 2026-07-28.
2. wp_mail_content_type — WordPress Code Reference (filtr).
   URL:     https://developer.wordpress.org/reference/hooks/wp_mail_content_type/
   Pobrano: 2026-07-28.
3. phpmailer_init — WordPress Code Reference (akcja).
   URL:     https://developer.wordpress.org/reference/hooks/phpmailer_init/
   Pobrano: 2026-07-28.

Dotyczy par Działu 7:
 - A7.1 "adresaci" / K7.1 "dobór-szablonu",
 - A7.2 "treść" / K7.2 "puste-pola" — treść HTML wymaga zmiany typu zawartości
   (źródło 2), bo domyślnie wp_mail() wysyła czysty tekst,
 - A7.3 "kolejka" / K7.3 "zero-wysyłki-w-żądaniu" — pierwszy cytat ze źródła 1
   jest wprost powodem, dla którego tabela powiadomień ma stany kolejki,
   licznik prób i kolumnę błędu: zwrócenie prawdy przez wp_mail() NIE oznacza,
   że wiadomość została doręczona. Konfiguracja SMTP odbywa się przez akcję ze
   źródła 3 — poza pipeline'em, w kolejce uruchamianej po COMMIT.
-->

# Dział 7 — powiadomienia e-mail: dokumentacja źródłowa

## Co robi funkcja i czego NIE gwarantuje (cytat, źródło 1)

"Sends an email, similar to PHP’s mail function."

"A true return value does not automatically mean that the user received the
email successfully. It just only means that the method used was able to process
the request without any errors."

## Typ treści (cytat, źródło 1)

"The default content type is ‘text/plain’ which does not allow using HTML."

## Nadawca — filtry (cytat, źródło 1)

"‘wp_mail_from‘ and ‘wp_mail_from_name‘ are run on the sender email address and
name. The return values are reassembled into a ‘from’ address. If only
‘wp_mail_from‘ returns a value, then just the email address will be used with
no name."

## Zmiana typu zawartości na HTML (cytat, źródło 2)

"Filters the wp_mail() content type."

"The default content type for email sent through the wp_mail() function is
‘text/plain‘ which does not allow using HTML. However, you can use the
wp_mail_content_type filter to change the default content type of the email."

Parametr (cytat): "$content_type string — Default wp_mail() content type."

## Dostęp do obiektu wysyłki — konfiguracja SMTP (cytat, źródło 3)

"Fires after PHPMailer is initialized."

"The wp_mail() function relies on the PHPMailer class to send email through
PHP’s mail function. The phpmailer_init action hook allows you to hook to the
phpmailer object and pass in your own arguments."

Parametr (cytat): "$phpmailer PHPMailer — The PHPMailer instance (passed by
reference)."
