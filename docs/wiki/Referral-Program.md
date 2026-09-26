[Polski](#polski) | [English](#english)

# Polski

# Program poleceń (Referral)

Każdy klient dostaje własny, 8-znakowy kod polecający, który może udostępnić znajomym. Gdy ktoś
zarejestruje się po kliknięciu w link z tym kodem, a potem złoży swoje pierwsze zamówienie,
odpalany jest wyzwalacz kampanii `referral_converted` **na koncie polecającego** — co dalej się
stanie (kupon, punkty lojalnościowe, e-mail z podziękowaniem) ustawiasz tak samo, jak przy każdym
innym wyzwalaczu, w budowniczym kampanii.

**Uwaga:** ta funkcja nie ma własnego ekranu w panelu admina — kod polecający i link do
udostępnienia dostarcza się klientowi przez motyw sklepu (widok "Moje konto"), przez lekkie
zapytanie AJAX do wbudowanego endpointu. Poniżej opisano dokładnie co ten endpoint zwraca, żeby
łatwo można było to wpiąć w dowolny motyw.

## Jak to działa krok po kroku

1. Motyw sklepu (np. w widoku "Moje konto") woła `GET /ordo/referral/mycode` — zalogowany klient
   dostaje w odpowiedzi JSON swój kod (tworzony przy pierwszym zapytaniu, jeśli jeszcze go nie
   ma) oraz gotowy link do udostępnienia:
   ```json
   {"ok": true, "code": "ABCD1234", "share_url": "https://sklep.pl/ordo/referral/track?ref=ABCD1234"}
   ```
2. Znajomy klika link → `GET /ordo/referral/track?ref=ABCD1234` zapisuje kod w sesji przeglądarki
   i przekierowuje na stronę rejestracji konta. Nieznany/błędny kod nie wywala błędu — po prostu
   ląduje się na zwykłej stronie rejestracji.
3. Jeśli znajomy dokończy rejestrację, zapisywane jest powiązanie "polecony przez" (status
   `pending`).
4. Gdy poleconą osoba złoży swoje pierwsze zamówienie, status zmienia się na `converted` i
   odpala się wyzwalacz `referral_converted` **na koncie osoby polecającej** — to na tym koncie
   spina się nagrodę.

## Konfiguracja

**Sklepy → Konfiguracja → Ordo Automation → Referral Program**: jeden przełącznik włącz/wyłącz.
Wyłączenie po prostu przekierowuje link śledzący od razu na rejestrację, bez zapisywania
żadnego kodu.

---

# English

# Referral / advocacy program

Every customer gets their own shareable 8-character referral code. When someone registers after
clicking a link carrying that code, and then places their first order, a `referral_converted`
campaign trigger fires **on the referrer's account** — what happens next (a coupon, loyalty
points, a thank-you email) is set up exactly like any other trigger, in the campaign builder.

**Note:** this feature has no admin screen of its own — the referral code and share link are
handed to the customer through the store's theme (typically the "My Account" view), via a small
AJAX call to a built-in endpoint. Below is exactly what that endpoint returns, so it can be
wired into any theme.

## How it works, step by step

1. The store theme (e.g. in the "My Account" view) calls `GET /ordo/referral/mycode` — a logged-
   in customer gets back their code as JSON (created on first request if they don't have one
   yet) plus a ready-to-share link:
   ```json
   {"ok": true, "code": "ABCD1234", "share_url": "https://store.example/ordo/referral/track?ref=ABCD1234"}
   ```
2. A friend clicks the link → `GET /ordo/referral/track?ref=ABCD1234` stashes the code on the
   browser's session and redirects to account registration. An unknown/mistyped code doesn't
   error — it just lands on the plain registration page.
3. If the friend completes registration, a "referred by" record is created (`pending` status).
4. When the referred customer places their first order, the status becomes `converted` and the
   `referral_converted` trigger fires **on the referrer's account** — that's where a reward gets
   wired up.

## Configuration

**Stores > Configuration > Ordo Automation > Referral Program**: a single enable/disable toggle.
Disabling it makes the tracking link redirect straight to registration without recording any
code.
