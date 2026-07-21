<!--
ŹRÓDŁO OFICJALNE — algorytm sumy kontrolnej NIP (standard krajowy).
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
