[Polski](#polski) | [English](#english)

# Polski

# Akceptacja zamówień

Funkcja B2B: zamówienia powyżej limitu wydatków przypisanego danemu klientowi są automatycznie
wstrzymywane, dopóki ktoś z Twojej firmy ich nie zatwierdzi. Zamówienie nie znika ani nie jest
anulowane — po prostu czeka.

## Jak to działa krok po kroku

1. Ustawiasz każdemu klientowi B2B (na jego karcie klienta) limit wydatków i adres e-mail osoby,
   która ma zatwierdzać jego zamówienia.
2. Gdy klient złoży zamówienie powyżej tego limitu, zamówienie dostaje status "czeka na
   akceptację" i **nie jest anulowane, nie traci rezerwacji towaru**.
3. Osoba zatwierdzająca dostaje e-mail z dwoma przyciskami: zatwierdź / odrzuć. Kliknięcie linku
   działa od razu, **bez logowania się do panelu** — wystarczy jeden klik z telefonu.
4. Jeśli nikt nie zareaguje w skonfigurowanym czasie, system wysyła przypomnienie. Po kilku
   przypomnieniach może automatycznie przekazać sprawę kolejnej osobie na liście (eskalacja) — nikt
   nigdy nie decyduje automatycznie za człowieka, system tylko przypomina i eskaluje.

## Siatka w panelu admina

Ten sam mechanizm dostępny jest też z poziomu panelu, bez potrzeby szukania maila — przydatne, gdy
np. zgubiono link albo trzeba sprawdzić historię decyzji.

![Siatka akceptacji zamówień](images/order-approval-grid.png)

Kolumny: numer zamówienia, kto został powiadomiony, status, ile przypomnień wysłano, kiedy
utworzono i kiedy podjęto decyzję.

## Konfiguracja

**Sklepy → Konfiguracja → Ordo Automation → Order Approval Workflow**: włącz/wyłącz, po ilu dniach
wysłać przypomnienie/eskalować, ile przypomnień na poziom, oraz lista e-maili do eskalacji (jeden
adres na linię — pierwszy dostaje przypomnienia, potem sprawa idzie do kolejnego).

## Powiązana funkcja: limit kredytowy

Podobny mechanizm B2B, ale surowszy: gdy klient osiągnie 100% swojego limitu kredytowego, **nowe
zamówienie jest całkowicie blokowane** przy kasie (nie czeka na akceptację — po prostu nie da się
go złożyć), dopóki limit się nie zwolni albo administrator go nie podniesie. Dodatkowo, zanim do
tego dojdzie, klient (i opcjonalnie handlowiec) dostaje ostrzegawczy e-mail przy zbliżaniu się do
progu — konfigurowalny w **Sklepy → Konfiguracja → Ordo Automation → Credit Limit Alert**.

---

# English

# Order Approval Workflow

A B2B feature: orders above a customer's assigned spend limit are automatically held until
someone at your company approves them. The order doesn't disappear or get canceled — it just
waits.

## How it works, step by step

1. You set a spend limit and an approving admin's email address on each B2B customer's own
   customer record.
2. When that customer places an order above the limit, the order is marked "pending approval" and
   **is not canceled, its stock reservation is untouched**.
3. The approver gets an email with two buttons: approve / reject. Clicking the link works
   immediately, **no login required** — one tap from a phone is enough.
4. If nobody responds within the configured window, the system sends a reminder. After enough
   reminders, it can automatically hand the decision to the next person on a list (escalation) —
   nothing is ever decided automatically for a human, the system only reminds and escalates.

## Admin grid

The same workflow is also available from the admin panel, no need to hunt for the email — useful
if a link got lost or you need to check the decision history.

![Order Approvals grid](images/order-approval-grid.png)

Columns: order number, who was notified, status, how many reminders were sent, when it was
created, and when it was decided.

## Configuration

**Stores → Configuration → Ordo Automation → Order Approval Workflow**: enable/disable, how many
days before reminding/escalating, how many reminders per tier, and the escalation email list (one
address per line — the first gets reminders, then it moves to the next).

## Related feature: credit limit

A similar B2B mechanism, but stricter: once a customer reaches 100% of their credit limit, **a new
order is blocked outright at checkout** (it doesn't wait for approval — it simply can't be placed)
until the limit frees up or an admin raises it. Before that happens, the customer (and optionally
their sales rep) gets a warning email as they approach the threshold — configurable under **Stores
→ Configuration → Ordo Automation → Credit Limit Alert**.
