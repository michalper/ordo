[Polski](#polski) | [English](#english)

# Polski

# Cykle ponownych zakupów

Ta funkcja obserwuje historię zamówień klienta i sama wykrywa, że kupuje dany produkt regularnie —
np. co 30 dni zamawia ten sam odżywczy suplement. Gdy zbliża się przewidywana data kolejnego
zamówienia, klient dostaje przypomnienie, zanim sam sobie o tym przypomni (albo kupi u
konkurencji).

To ekran wyłącznie do podglądu — nie edytujesz tu ręcznie wykrytych wzorców, tylko widzisz co
system ustalił i możesz zareagować.

## Siatka

![Siatka cykli ponownych zakupów](images/reorder-cycles-grid.png)

Dla każdego klienta i produktu widzisz: średni odstęp między zamówieniami (w dniach), datę
ostatniego zamówienia, przewidywaną datę następnego, ile zamówień system wziął pod uwagę przy
wyliczeniu, i czy przypomnienie już zostało wysłane oraz czy klient faktycznie zamówił ponownie.

Przykład z prawdziwych danych: klient zamawiający co 10 dni, na podstawie 3 poprzednich zamówień,
z jednym wysłanym przypomnieniem, jeszcze bez ponownego zakupu.

## Co możesz zrobić z poziomu siatki

- **Wyślij przypomnienie teraz** — nie czekasz na automatyczny harmonogram, wysyłasz od ręki.
- **Zbuduj koszyk** — jednym kliknięciem tworzysz dla tego klienta gotowe zamówienie z produktem,
  który zwykle kupuje, i przechodzisz od razu do ekranu tworzenia zamówienia w jego imieniu
  (przydatne dla obsługi klienta/handlowców przy zamówieniach telefonicznych).

## Konfiguracja

**Sklepy → Konfiguracja → Ordo Automation → Reorder Reminder**: minimalna liczba zamówień
potrzebna, żeby system w ogóle rozpoznał wzorzec, oraz ile dni przed przewidywaną datą wysłać
przypomnienie.

---

# English

# Reorder Cycles

This feature watches a customer's order history and detects on its own that they buy a given
product on a regular schedule — e.g. reordering the same supplement every 30 days. As the
predicted next-order date approaches, the customer gets a reminder before they think to reorder
themselves (or buy from a competitor instead).

This screen is read-only — you don't manually edit the detected pattern, you see what the system
found and can act on it.

## Grid

![Reorder Cycles grid](images/reorder-cycles-grid.png)

For each customer and product you see: the average interval between orders (in days), the date of
their last order, the predicted next date, how many past orders the system used to calculate this,
and whether a reminder has already been sent and whether the customer actually reordered.

Example from real data: a customer reordering every 10 days, based on their 3 most recent orders,
with one reminder already sent and no reorder yet.

## What you can do from the grid

- **Send Reminder Now** — skip the automatic schedule and send one immediately.
- **Build Cart** — one click creates a ready-made order for this customer with the product they
  usually buy, and takes you straight to the order-creation screen on their behalf (handy for
  customer service or sales reps handling phone orders).

## Configuration

**Stores → Configuration → Ordo Automation → Reorder Reminder**: the minimum number of orders
needed before the system will recognize a pattern at all, and how many days before the predicted
date to send the reminder.
