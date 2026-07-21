# Polityka prywatności — WZÓR (formularz zapytania ofertowego)

> **⚠️ TO JEST WZÓR, NIE GOTOWY DOKUMENT PRAWNY.**
> Uzupełnij wszystkie `[PLACEHOLDERY]`, dostosuj do rzeczywistych procesów i
> **skonsultuj z prawnikiem / IOD** przed publikacją. Zakres poniżej odpowiada temu,
> co technicznie robi wtyczka `mp-lead-intake` — jeśli zmienisz kod, zaktualizuj politykę.

---

## 1. Administrator danych

Administratorem danych osobowych jest **[PEŁNA NAZWA FIRMY]**, [ADRES], NIP [NIP FIRMY],
e-mail: **[kontakt@twojadomena.pl]**. [Jeśli powołano: Inspektor Ochrony Danych — [IOD, e-mail].]

## 2. Jakie dane zbieramy (formularz zapytania ofertowego)

- **Dane firmy/kontaktowe:** nazwa firmy, NIP, adres e-mail, numer telefonu, segment/branża.
- **Zgody:** zgoda RODO (wymagana), zgoda marketingowa (opcjonalna) — wraz ze znacznikiem czasu i wersją zgody.
- **Dane techniczne (log audytowy):** adres IP w formie **zanonimizowanej** (obcięty — patrz §6),
  identyfikator zdarzenia, znacznik czasu. IP w pełnej formie **nie jest przechowywane**.

## 3. Cel i podstawa prawna (art. 6 RODO)

| Cel | Podstawa prawna |
|---|---|
| Obsługa zapytania ofertowego, kontakt handlowy | art. 6 ust. 1 lit. b (czynności przed zawarciem umowy) oraz lit. f (prawnie uzasadniony interes) |
| Weryfikacja NIP/VAT (VIES, Biała lista VAT) | art. 6 ust. 1 lit. f (należyta staranność, przeciwdziałanie nadużyciom) |
| Komunikacja marketingowa | art. 6 ust. 1 lit. a (zgoda — jeśli wyrażona) |
| Bezpieczeństwo i log audytowy (antyspam, rate-limit) | art. 6 ust. 1 lit. f |

## 4. Odbiorcy danych

- **Ministerstwo Finansów — Biała lista podatników VAT** (`wl-api.mf.gov.pl`) — weryfikacja statusu NIP.
- **Komisja Europejska — VIES** (`ec.europa.eu`) — weryfikacja numeru VAT UE.
- Dostawca hostingu/poczty jako podmiot przetwarzający (na podstawie umowy powierzenia).
- [Ewentualne inne narzędzia — uzupełnij.]

*(NIP jest przekazywany do powyższych rejestrów publicznych wyłącznie w celu weryfikacji.)*

## 5. Okres przechowywania (retencja)

| Kategoria | Okres |
|---|---|
| Leady (zapytania) | [np. do 24 mies. od ostatniego kontaktu / do wycofania zgody] |
| Log aktywności | [np. 12 mies.] |
| Adres IP w logu | **90 dni** (potem automatyczna anonimizacja — cron wtyczki) |
| Dane marketingowe | do wycofania zgody |

## 6. Anonimizacja IP

Adres IP jest zapisywany w postaci **zanonimizowanej** (obcięcie: IPv4 — ostatni oktet do `0`,
IPv6 — pozostawienie 48 bitów sieci), a po **90 dniach** usuwany całkowicie. Na żądanie usunięcia
danych IP powiązane z danym zapytaniem są anonimizowane.

## 7. Prawa osób (art. 15–22 RODO)

Masz prawo do: dostępu do danych, sprostowania, usunięcia („prawo do bycia zapomnianym"),
ograniczenia przetwarzania, przenoszenia, sprzeciwu oraz **wycofania zgody** w dowolnym momencie
(bez wpływu na zgodność z prawem przetwarzania sprzed wycofania). Wnioski: **[kontakt@twojadomena.pl]**.
Masz też prawo skargi do **Prezesa UODO**.

## 8. Zautomatyzowane podejmowanie decyzji

Wtyczka wykonuje **scoring leada** (punktacja pomocnicza dla handlowca na podstawie m.in. statusu VAT,
segmentu, kompletności danych). **Nie** wywołuje to skutków prawnych ani nie jest w pełni zautomatyzowaną
decyzją w rozumieniu art. 22 RODO — służy wyłącznie wsparciu obsługi. [Zweryfikuj zgodnie z realnym procesem.]

## 9. Dobrowolność

Podanie danych jest dobrowolne, ale niezbędne do obsługi zapytania. Zgoda RODO jest wymagana do wysłania formularza.

---

*Wersja wzoru spójna z wtyczką mp-lead-intake. Data ostatniej aktualizacji: [DATA].*
