[Polski](#polski) | [English](#english)

# Polski

# Kampanie

Kampania to reguła w stylu **"gdy zdarzy się X, i jeśli spełniony jest warunek Y, zrób Z"**.
Przykład: gdy klient porzuci koszyk (X), a wartość koszyka wynosi co najmniej 100 zł (Y), wyślij
mu e-mail z przypomnieniem po godzinie (Z).

## Lista kampanii

![Siatka kampanii](images/campaigns-grid.png)

Widzisz tu wszystkie swoje kampanie, czy są włączone, i jakie mają wyzwalacze. Możesz je masowo
włączać/wyłączać/usuwać, a przyciskiem u góry — zaimportować gotową kampanię.

## Co może wyzwolić kampanię

- Klient złożył zamówienie
- Klient się zarejestrował
- Klientowi dodano tag (ręcznie albo automatycznie przez inną funkcję)
- Klient porzucił koszyk
- Klient przeglądał produkt, ale nic nie kupił
- Anonimowy odwiedzający dostał tag (np. na podstawie zachowania na stronie)
- Wynik punktowy klienta przekroczył próg
- Konkretna data/godzina, albo harmonogram cykliczny (np. co poniedziałek)
- Sygnał z zewnętrznego systemu (ERP/CRM), jeśli macie taką integrację
- Cena obserwowanego produktu spadła, albo produkt wrócił na stan

Jedna kampania może mieć kilka wyzwalaczy naraz — każdy z nich prowadzi do tego samego zestawu
warunków i akcji.

## Kreator wizualny (Flow)

Edycja kampanii otwiera graficzną kanwę: bloki wyzwalaczy, warunków i akcji, które przeciągasz z
palety po lewej i łączysz liniami. Zasada jest prosta: **każdy wyzwalacz musi prowadzić aż do
jakiejś akcji** — jeśli zostawisz coś niepodłączone, kanwa podświetli to na czerwono i nie pozwoli
zapisać, dopóki nie poprawisz.

![Kanwa Flow — kampania "[Demo] Cart Abandonment Recovery" z węzłem triggera "Cart Abandoned" połączonym z akcją "Send Email"](images/flow-canvas.png)

Kilka przydatnych funkcji kanwy:
- **Cofnij/ponów** (Ctrl+Z / Ctrl+Shift+Z) — jeśli coś przypadkiem przesuniesz albo usuniesz.
- **Duplikowanie bloku** — kopiuje ustawienia bez kopiowania połączeń, przydatne przy podobnych
  wariantach.
- **Wyślij test** — na blokach e-mail/SMS/WhatsApp możesz od razu wysłać testową wiadomość do
  siebie, zanim uruchomisz kampanię na prawdziwych klientach.

### Warunki, które możesz ustawić

Np.: klient ma konkretny tag, suma jego zamówień przekracza kwotę, jego wynik punktowy jest
wystarczająco wysoki, minęło (albo nie minęło) tyle dni od ostatniego zamówienia, klient jest
w top N% pod względem wydatków/częstotliwości zamówień, klient należy (albo nie należy) do danego
segmentu, klient kupił konkretny produkt/kategorię. Warunki można łączyć: "wszystkie muszą być
spełnione" (AND) albo "wystarczy jeden" (OR) — a nawet zagnieżdżać grupy jedno w drugim (np. "ma
tag VIP ORAZ (wynik ≥ 999 LUB wydał ≥ 1000 zł)").

### Akcje, które może wykonać kampania

Dodaj tag, wyślij e-mail (opcjonalnie o najlepszej porze dla danego klienta — patrz niżej),
wygeneruj kupon, pokaż popup na stronie, dodaj punkty lojalnościowe, dodaj rekomendacje
produktowe, wstaw dynamiczną treść do maila, wyślij SMS, wyślij trwałe powiadomienie na stronie,
wyślij ankietę NPS, wyślij WhatsApp, wyślij powiadomienie push, podziel ruch na dwa warianty (test
A/B), albo wywołaj zewnętrzny system przez webhook.

### Wysyłka o najlepszej porze dla klienta

Przy akcji "Wyślij e-mail" możesz włączyć opcję wysyłki o optymalnej porze — system sprawdza,
o której godzinie dany klient najczęściej otwiera/klika w Twoje maile (na podstawie jego własnej
historii) i czeka z wysyłką do tej pory, zamiast wysyłać od razu. Potrzeba minimum kilku
zarejestrowanych otwarć/kliknięć tego klienta, żeby to zadziałało — jeśli danych jest za mało,
e-mail po prostu idzie od razu, tak jak dotychczas.

## Kalendarze

Dwa osobne widoki: **Oś czasu akcji kampanii** pokazuje względne opóźnienia (np. "60 minut po
triggerze"), a **Zaplanowany kalendarz kampanii** to prawdziwa siatka miesięczna dla kampanii
uruchamianych o konkretnej dacie/cyklicznie.

---

# English

# Campaigns

A campaign is a rule shaped like **"when X happens, and condition Y is true, do Z."** Example:
when a customer abandons their cart (X), and the cart is worth at least $100 (Y), send them a
reminder email an hour later (Z).

## Campaign list

![Campaigns grid](images/campaigns-grid.png)

You'll see every campaign here, whether it's enabled, and its trigger(s). You can bulk enable/
disable/delete them, and the button at the top lets you import a ready-made campaign.

## What can trigger a campaign

- Customer placed an order
- Customer registered
- Customer got a tag (added manually, or automatically by another feature)
- Customer abandoned a cart
- Customer browsed a product but never ordered
- An anonymous visitor got a tag (e.g. based on on-site behavior)
- A customer's score crossed a threshold
- A specific date/time, or a recurring schedule (e.g. every Monday)
- A signal from an external system (ERP/CRM), if you have that integration set up
- A watched product's price dropped, or it came back in stock

One campaign can have several triggers at once — every one of them leads into the same shared
conditions and actions.

## Visual builder (Flow)

Editing a campaign opens a graphical canvas: trigger, condition, and action blocks you drag from
the palette on the left and connect with lines. The rule is simple: **every trigger must lead all
the way to an action** — if you leave something disconnected, the canvas highlights it in red and
won't let you save until you fix it.

![Flow canvas — the "[Demo] Cart Abandonment Recovery" campaign with a "Cart Abandoned" trigger node connected to a "Send Email" action node](images/flow-canvas.png)

A few useful canvas features:
- **Undo/redo** (Ctrl+Z / Ctrl+Shift+Z) — if you accidentally move or delete something.
- **Duplicate block** — copies the settings without copying its connections, handy for similar
  variants.
- **Send test** — on email/SMS/WhatsApp blocks, you can fire off a real test message to yourself
  before running the campaign on real customers.

### Conditions you can set

For example: the customer has a specific tag, their total order value exceeds an amount, their
score is high enough, a number of days has (or hasn't) passed since their last order, they're in
the top N% by spend/order frequency, they're in (or not in) a given segment, or they've bought a
specific product/category. Conditions can be combined: "all must match" (AND) or "any one is
enough" (OR) — and you can even nest groups inside each other (e.g. "has tag VIP AND (score ≥ 999
OR spent ≥ $1000)").

### Actions a campaign can take

Add a tag, send an email (optionally at the best time for that specific customer — see below),
generate a coupon, show a popup on the site, add loyalty points, add product recommendations,
insert dynamic content into an email, send an SMS, send a persistent on-site notification, send an
NPS survey, send a WhatsApp message, send a push notification, split traffic into two variants
(A/B test), or call an external system via a webhook.

### Sending at the customer's best time

On the "Send Email" action you can turn on optimal send-time delivery — the system checks what
hour this specific customer usually opens/clicks your emails (from their own history) and waits
to send until that hour, instead of sending right away. It needs a handful of that customer's own
past opens/clicks to work — if there isn't enough data yet, the email just goes out immediately,
same as before.

## Calendars

Two separate views: the **Campaign Action Timeline** shows relative delays (e.g. "60 minutes after
the trigger"), and the **Scheduled Campaign Calendar** is a real month-grid for campaigns that run
on a specific date or recurring schedule.
