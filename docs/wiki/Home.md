[Polski](#polski) | [English](#english)

# Polski

# Ordo Automation — Wiki

Marketing automation działający wewnątrz zwykłego Magento Open Source — bez licencji Adobe
Commerce B2B, bez zewnętrznej subskrypcji MA. Wyzwalacze liczone są z danych, które Magento już
ma (zamówienia, koszyki, klienci), albo z niewielkiego, własnego modelu danych dołożonego obok.

Ta wiki opisuje każdą wysłaną funkcję modułu — jak wygląda w panelu administracyjnym, jak
faktycznie działa (zweryfikowane w kodzie), i gdzie ją skonfigurować.

## Strony

- [Dashboard](Dashboard) — ekran startowy modułu, karty nawigacyjne, statystyki.
- [Kampanie](Campaigns) — silnik reguł trigger → warunki → akcje, kanwa Flow.
- [Segmenty](Segments) — zapisane, wielokrotnego użytku warunki klienta.
- [RFM i lead scoring](RFM-and-Lead-Scoring) — raport Recency/Frequency/Monetary i punktacja leadów.
- [Gratisy](Free-Gifts) — progi wartości koszyka z kaskadowymi gratisami.
- [Akceptacja zamówień](Order-Approval) — wstrzymywanie zamówień powyżej limitu wydatków.
- [Śledzenie i popupy](Tracking-and-Popups) — śledzenie zachowań on-site, popupy, powiadomienia, NPS.
- [Cykle ponownych zakupów](Reorder-Cycles) — wykrywanie wzorców zakupowych i przypomnienia.

Więcej: [README.md](https://github.com/michalper/ordo/blob/main/README.md) (instalacja, pełna
lista funkcji), [ROADMAP.md](https://github.com/michalper/ordo/blob/main/ROADMAP.md) (co jeszcze
otwarte), [docs/CHANGELOG.md](https://github.com/michalper/ordo/blob/main/docs/CHANGELOG.md)
(historia zmian).

---

# English

# Ordo Automation — Wiki

Marketing automation that runs inside stock Magento Open Source — no Adobe Commerce B2B license,
no external MA subscription. Triggers are computed from data Magento already has (orders, carts,
customers), or from a small first-party data model added alongside it.

This wiki documents every shipped capability of the module — what it looks like in the admin,
what it actually does (verified against the code), and where to configure it.

## Pages

- [Dashboard](Dashboard) — the module's home screen, nav cards, stats.
- [Campaigns](Campaigns) — the trigger → conditions → actions rule engine, the Flow canvas.
- [Segments](Segments) — saved, reusable customer conditions.
- [RFM & Lead Scoring](RFM-and-Lead-Scoring) — the Recency/Frequency/Monetary report and lead scoring.
- [Free Gifts](Free-Gifts) — cascading cart-subtotal gift tiers.
- [Order Approval](Order-Approval) — holding orders above a spend limit for admin decision.
- [Tracking & Popups](Tracking-and-Popups) — on-site behavior tracking, popups, notifications, NPS.
- [Reorder Cycles](Reorder-Cycles) — purchase-pattern detection and reminders.

More: [README.md](https://github.com/michalper/ordo/blob/main/README.md) (install, full feature
list), [ROADMAP.md](https://github.com/michalper/ordo/blob/main/ROADMAP.md) (what's still open),
[docs/CHANGELOG.md](https://github.com/michalper/ordo/blob/main/docs/CHANGELOG.md) (implementation
history).
