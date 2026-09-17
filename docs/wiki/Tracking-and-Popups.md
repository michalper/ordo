[Polski](#polski) | [English](#english)

# Polski

# Śledzenie zachowań i popupy

Niewielki skrypt na Twojej witrynie zauważa, co klienci oglądają — jakie produkty, jakie
kategorie, w co klikają — i zamienia to w tagi, których możesz użyć w segmentach i kampaniach.
Nic nie trzeba instalować osobno, wystarczy włączyć w konfiguracji.

## Automatyczne tagowanie po zachowaniu

Gdy klient obejrzy ten sam produkt (albo kliknie w ten sam element) określoną liczbę razy, dostaje
automatyczny tag — np. "obejrzał produkt X 3 razy, ale nie kupił". Ten tag możesz potem użyć jako
warunek w kampanii, np. do wysłania przypomnienia albo rabatu.

Progi (ile wyświetleń/kliknięć wystarczy) ustawiasz w konfiguracji.

## Popupy, powiadomienia i ankiety — jako akcje kampanii

To nie jest osobny system popupów — to trzy dodatkowe akcje dostępne w kreatorze kampanii (patrz
[Kampanie](Campaigns)):

- **Popup** — jednorazowe okienko na stronie, np. "Wróć do koszyka i skończ zamówienie".
- **Trwałe powiadomienie** — w odróżnieniu od popupu, zostaje widoczne na kolejnych stronach,
  dopóki klient go nie zamknie albo nie wygaśnie.
- **Ankieta NPS/satysfakcji** — proste pytanie z oceną 0-10, np. po zamówieniu.

Każda z tych akcji ma własny limit częstotliwości, żeby nie zasypać tego samego klienta kilkoma
popupami naraz.

## Konfiguracja

![Konfiguracja śledzenia on-site](images/tracking-config.png)

**Sklepy → Konfiguracja → Ordo Automation → On-Site Behavior Tracking**:
- **Włączone** — główny przełącznik śledzenia; bez niego nic się nie zbiera.
- **Próg wyświetleń** — ile razy trzeba obejrzeć ten sam produkt, żeby dostać tag.
- **Próg kliknięć** — analogicznie dla kliknięć.
- **Retencja danych** — po ilu dniach usuwać surowe zdarzenia (same tagi, już wyliczone, zostają
  na stałe — czyszczone są tylko surowe logi zdarzeń, dla oszczędności miejsca).
- Osobne przełączniki i interwały odpytywania dla popupów, powiadomień i ankiety NPS.

---

# English

# On-Site Behavior Tracking and Popups

A small script on your storefront notices what customers browse — which products, which
categories, what they click — and turns that into tags you can use in segments and campaigns.
Nothing extra to install, just turn it on in configuration.

## Automatic tagging from behavior

When a customer views the same product (or clicks the same element) a set number of times, they
get an automatic tag — e.g. "viewed product X 3 times without buying." You can then use that tag
as a campaign condition, say to send a reminder or a discount.

The thresholds (how many views/clicks are enough) are set in configuration.

## Popups, notifications, and surveys — as campaign actions

This isn't a separate popup system — it's three extra actions available in the campaign builder
(see [Campaigns](Campaigns)):

- **Popup** — a one-time on-page banner, e.g. "Come back and finish your order."
- **Persistent notification** — unlike a popup, this stays visible across page loads until the
  customer dismisses it or it expires.
- **NPS/satisfaction survey** — a simple 0-10 rating question, e.g. after an order.

Each of these has its own frequency cap, so the same customer isn't flooded with several popups at
once.

## Configuration

![On-site tracking configuration](images/tracking-config.png)

**Stores → Configuration → Ordo Automation → On-Site Behavior Tracking**:
- **Enabled** — the master tracking switch; nothing is collected without it.
- **View threshold** — how many views of the same product earn a tag.
- **Click threshold** — same idea, for clicks.
- **Data retention** — after how many days raw events are deleted (the tags already derived from
  them stay permanently — only the raw event log is cleaned up, to save space).
- Separate on/off switches and polling intervals for popups, notifications, and the NPS survey.
