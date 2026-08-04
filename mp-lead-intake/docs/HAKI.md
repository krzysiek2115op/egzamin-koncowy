# Haki wtyczki MP Lead Intake

Spis wszystkiego, co ta wtyczka **wystawia** — zdarzeń (`do_action`) i filtrów
(`apply_filters`). Powstał, bo audyt zgłosił dwa zdarzenia „wystawiane, ale nikt
ich nie słucha w projekcie". Bez dokumentu nie da się takiego zgłoszenia
rozstrzygnąć: brak odbiorcy znaczy albo świadomy punkt rozszerzeń, albo odbiorcę
zgubionego przy refaktorze. Tutaj jest napisane, które jest które.

Przy okazji wyszło, że jedno z tych dwóch zgłoszeń było fałszywe: `mp_lead_verified`
ma odbiorcę, tylko w **innej wtyczce**, a kontrola szukała `add_action` wyłącznie
w drzewie tej samej. Sprawdzone wprost — `MP_Offer_Builder_Lead_Listener::register()`.

## Zdarzenia (`do_action`)

| Hak | Argumenty | Kiedy | Odbiorca |
|---|---|---|---|
| `mp_lead_created` | `$lead_id`, `$payload` | Dział 11, PO zamknięciu transakcji zapisu | **Wtyczka 2** — zakłada szkic oferty |
| `mp_lead_verified` | `$lead_id`, `$fields` | Po uzgodnieniu statusu VAT przez zadanie okresowe (weryfikacja asynchroniczna) | **Wtyczka 2** — aktualizuje snapshot VAT w szkicu |
| `mp_lead_intake_after_form` | — | Po wyrenderowaniu formularza, wewnątrz jego kontenera | **Punkt rozszerzeń** — bez odbiorcy, celowo |

`mp_lead_verified` niesie skutek weryfikacji, która nastąpiła **po** przyjęciu
zgłoszenia — dla wtyczki 2 to jedyny moment, w którym może poprawić mechanizm
VAT w szkicu założonym wcześniej. `mp_lead_intake_after_form` jest miejscem na
doklejenie własnej treści pod formularzem bez podmieniania szablonu.

## Filtry (`apply_filters`)

| Filtr | Domyślnie | Do czego |
|---|---|---|
| `mp_lead_intake_async_verification` | `true` | Wyłącz, żeby weryfikować VAT synchronicznie w trakcie zgłoszenia |
| `mp_lead_intake_reject_invalid_vat` | `false` (`$lead_id`, `$lead`) | Włącz, żeby lead z potwierdzonym nieważnym VAT-em był odrzucany, a nie tylko oznaczany |
| `mp_lead_intake_add_page_to_menu` | `true` | Wyłącz, jeśli pozycję menu dodajesz sam |
| `mp_lead_intake_menu_html_fallback` | `true` | Wyłącz awaryjne wstrzykiwanie odnośnika, gdy motyw nie ma przypisanego menu |
| `mp_lead_intake_show_menu_notice` | `true` | Wycisz komunikat w panelu o braku pozycji w menu |
| `mp_lead_intake_seo_meta_description` | `true` | Wyłącz dokładanie `<meta name="description">` na stronie formularza |
| `mp_lead_intake_send_security_headers` | wartość stałej `MP_LEAD_INTAKE_SECURITY_HEADERS` | Ostatnie słowo w sprawie nagłówków bezpieczeństwa |
| `mp_lead_intake_security_header_list` | lista domyślna | Podmień komplet nagłówków |
| `mp_lead_intake_csp` | polityka domyślna | Podmień samą `Content-Security-Policy` |
| `mp_lead_intake_max_request_bytes` | `65536` | Górny rozmiar żądania formularza |
| `mp_lead_intake_disable_xmlrpc` | `true` | Zostaw XML-RPC włączone |
| `mp_lead_intake_disallow_file_edit` | `true` | Nie ustawiaj `DISALLOW_FILE_EDIT` |
| `mp_lead_intake_hide_wp_version` | `true` | Zostaw numer wersji WordPressa w nagłówkach |

## Zasada

Zdarzenie z tabeli wyżej jest **kontraktem**: zmiana nazwy albo argumentów to
zmiana łamiąca zgodność i wymaga wpisu w changelogu. Filtry mają domyślne
wartości dobrane tak, żeby wtyczka działała poprawnie bez ani jednego
`add_filter` — każdy z nich odbiera coś, czego integrator może nie chcieć,
a nie dokłada czegoś, czego brakuje.
