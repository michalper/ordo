[Polski](#polski) | [English](#english)

# Polski

# Dokumentacja API — integracja z custom frontendem

Ta strona jest dla deweloperów budujących własny frontend (headless PWA, aplikacja mobilna,
integracja z zewnętrznym CRM/ERP) na module Ordo Automation. Opisuje wszystkie publiczne
endpointy HTTP: REST API (`/rest/V1/ordo/...`) oraz publiczne, niezalogowane endpointy śledzące
(`/ordo/track/...`), z których korzysta natywny skrypt `tracker.js`, ale które może wołać
dowolny klient.

Endpointy panelu admina (`admin/ordo/...`) nie są tu opisane — to interfejs administracyjny
Magento, wymagający sesji admina i ACL, nie przeznaczony do integracji.

## Model uwierzytelniania

Ten moduł ma **dwie oddzielne** granice bezpieczeństwa, w zależności od grupy endpointów:

1. **REST API** (`/rest/V1/ordo/...`) — standardowe uwierzytelnianie Magento: token OAuth/Bearer
   (integracja) albo token klienta (`customer` token, przez `/rest/V1/customers/me` lub logowanie).
   Każdy route ma zadeklarowany zasób ACL (`resources` w `webapi.xml`):
   - `Ordo_Automation::campaigns`, `Ordo_Automation::config`, `Ordo_Automation::free_gifts` —
     wymagają tokena **admina** z odpowiednim ACL.
   - `self` — wymaga tokena **klienta** (dowolnego zalogowanego), własność konkretnego zasobu
     (np. koszyka, oferty) jest sprawdzana wewnątrz serwisu, nie przez ACL.
   - `anonymous` — brak wymogu tokena. Tylko dwa endpointy: zatwierdzenie/odrzucenie zamówienia
     przez token z linku e-mail (patrz sekcja *Order Approval* niżej).
2. **Endpointy śledzące** (`/ordo/track/...`) — brak tokena, brak CSRF (poza wyjątkiem opisanym
   przy rejestracji push/price-watch). Identyfikacja odbywa się przez:
   - cookie `ordo_visitor_id` (UUID, ustawiane przez `tracker.js`, żywotność 365 dni) — dla
     anonimowych odwiedzających, przekazywane jako parametr `visitor_id`;
   - sesję klienta Magento (`Magento\Customer\Model\Session`) — jeśli klient jest zalogowany,
     jego `customer_id` jest brane automatycznie z sesji (ciasteczko sesyjne PHP), nie trzeba go
     przekazywać w żądaniu.

   To ten sam model zaufania co dowolny "piksel" analityczny — każdy, kto ma poprawny
   `visitor_id`, może odpytać te endpointy. Nie umieszczaj w nich nic wrażliwego.

## Endpointy śledzące (`/ordo/track/...`)

Wszystkie POST-y jako `application/x-www-form-urlencoded` (`fetch` z `URLSearchParams` albo
klasyczny `FormData`). Odpowiedzi zawsze `Content-Type: application/json`.

### `POST /ordo/track/event`

Loguje surowe zdarzenie zachowania (podgląd produktu/kategorii, kliknięcie elementu) — źródło
automatycznego tagowania po zachowaniu (patrz [Śledzenie i popupy](Tracking-and-Popups)).

| Parametr | Typ | Wymagany | Opis |
|---|---|---|---|
| `visitor_id` | string | tak | UUID odwiedzającego |
| `event_type` | string | tak | jedno z: `page_view`, `product_view`, `category_view`, `element_clicked` |
| `event_key` | string | nie | identyfikator kontekstu, np. SKU produktu; max 255 znaków, dłuższy jest ucinany |

Odpowiedź: `{"ok": true}` albo `{"ok": false, "reason": "tracking_disabled" | "invalid_payload"}`.

### `GET /ordo/track/popup?visitor_id=...`

Odpytuje o jednorazowy popup zakolejkowany przez akcję kampanii `show_popup`. **Konsumuje**
wynik — ten sam popup nigdy nie wróci drugi raz (claim-before-deliver), więc trzeba pollować
regularnie (natywny klient robi to co 25s, pierwszy raz po 2s).

Odpowiedź: `{"popup": {"headline": "...", "body": "...", "cta_label": "...", "cta_url": "..."} | null}`.

### `GET /ordo/track/notification?visitor_id=...`

Odpytuje o **wszystkie** nieprzeczytane, nieprzedawnione trwałe powiadomienia (akcja kampanii
`show_notification`). W odróżnieniu od popupu, **nie konsumuje** niczego — ten sam zestaw wraca
przy każdym poll, aż zostanie jawnie odrzucony.

Odpowiedź: `{"notifications": [{"id": 123, "headline": "...", "body": "...", "cta_label": "...", "cta_url": "..."}]}`.

### `POST /ordo/track/dismissnotification`

Odrzuca powiadomienie (ustawia `read_at`).

| Parametr | Typ | Wymagany |
|---|---|---|
| `visitor_id` | string | tak (dla anonimowego) |
| `notification_id` | int | tak |

Odpowiedź: `{"ok": true}` albo `{"ok": false}` — w tym gdy `notification_id` nie należy do
wołającego (sprawdzane po `customer_id` lub `visitor_id`).

### `GET /ordo/track/survey?visitor_id=...`

Odpytuje o jednorazową ankietę NPS/satysfakcji (akcja kampanii `nps_survey`). Claim-before-deliver,
jak popup.

Odpowiedź: `{"survey": {"id": 456, "question": "..."} | null}`.

### `POST /ordo/track/submitsurveyresponse`

Zapisuje odpowiedź na ankietę.

| Parametr | Typ | Wymagany | Opis |
|---|---|---|---|
| `visitor_id` | string | tak (dla anonimowego) | |
| `survey_id` | int | tak | `id` z odpowiedzi endpointu `survey` |
| `score` | int | tak | 0-10 |

Odpowiedź: `{"ok": true}` albo `{"ok": false}` — odrzucane gdy ankieta już odpowiedziana, nie
istnieje, albo nie należy do wołającego.

### `POST /ordo/track/registerpushsubscription`

Rejestruje subskrypcję Web Push (po `PushManager.subscribe()` w przeglądarce).

| Parametr | Typ | Wymagany |
|---|---|---|
| `endpoint` | string | tak |
| `p256dh` | string | tak |
| `auth` | string | tak |

Odpowiedź: `{"ok": true}` albo `{"ok": false, "reason": "push_disabled" \| "invalid_payload" \| "invalid_endpoint"}`.

**Uwaga o CSRF**: dla zalogowanego klienta ten endpoint sprawdza nagłówek `Origin`/`Referer`
(brak klasycznego `form_key`, bo to gołe `fetch()`) — musi zgadzać się z domeną sklepu.
Anonimowe rejestracje (brak sesji) nie mają tego wymogu.

### `POST /ordo/track/unregisterpushsubscription`

| Parametr | Typ | Wymagany |
|---|---|---|
| `endpoint` | string | tak |

Odpowiedź: `{"ok": true}` albo `{"ok": false, "reason": "invalid_payload"}`.

### `POST /ordo/track/registerpricewatch`

Rejestruje subskrypcję "powiadom mnie" na PDP (spadek ceny / powrót na stan).

| Parametr | Typ | Wymagany | Opis |
|---|---|---|---|
| `product_id` | int | tak | |
| `watch_type` | string | tak | `price_drop` albo `back_in_stock` |

Odpowiedź: `{"ok": true}` albo `{"ok": false, "reason": "price_watch_disabled" \| "invalid_payload" \| "invalid_product"}`.
Ten sam model CSRF co `registerpushsubscription`.

## REST API (`/rest/V1/ordo/...`)

Standardowe REST API Magento — `Content-Type: application/json`, nagłówek
`Authorization: Bearer <token>`. Pełny, aktualny opis parametrów/typów zawsze dostępny przez
Swagger Magento (`/rest/V1/schema?services=ordoAutomationAll`), tu tylko mapa endpointów.

### Kampanie (ACL admina: `Ordo_Automation::campaigns`)
- `GET/POST /V1/ordo/campaigns`, `GET/PUT/DELETE /V1/ordo/campaigns/:entityId`
- `GET/POST /V1/ordo/campaign-triggers`, `GET/PUT/DELETE /V1/ordo/campaign-triggers/:entityId`
- `GET/POST /V1/ordo/campaign-conditions`, `GET/PUT/DELETE /V1/ordo/campaign-conditions/:entityId`
- `GET/POST /V1/ordo/campaign-actions`, `GET/PUT/DELETE /V1/ordo/campaign-actions/:entityId`

Triggery/warunki/akcje są płaskimi zasobami filtrowanymi po `campaign_id` przez standardowe
`searchCriteria` Magento (`?searchCriteria[filterGroups][0][filters][0][field]=campaign_id&...`),
nie zagnieżdżonym URL-em.

### Tagi klientów (ACL admina: `Ordo_Automation::campaigns`)

Podstawowy mechanizm segmentacji ("wyślij do wszystkich z tagiem X") — headless storefront/CRM
może zarządzać tagami bez sesji admina w panelu.

- `GET /V1/ordo/customers/:customerId/tags` — lista tagów klienta
- `GET /V1/ordo/customers/:customerId/tags/:tag` — czy klient ma dany tag (bool)
- `PUT /V1/ordo/customers/:customerId/tags/:tag` — dodaje tag (odpala trigger `tag_added`)
- `DELETE /V1/ordo/customers/:customerId/tags/:tag` — usuwa tag
- `GET /V1/ordo/tags/:tag/customers` — lista ID klientów z danym tagiem

### Oferty z terminem wygaśnięcia (offer expiry)
- `GET/POST /V1/ordo/offers`, `GET/PUT/DELETE /V1/ordo/offers/:entityId` — ACL admina
  (`Ordo_Automation::config`)
- `POST /V1/ordo/offers/:offerId/self-extend` — **`self`**, token dowolnego zalogowanego
  klienta; własność konkretnej oferty sprawdzana wewnątrz (nie ACL) — klient może samodzielnie
  przedłużyć **swoją** ofertę o kolejny okres.

### Zatwierdzanie zamówień (order approval)
- `GET /V1/ordo/order-approvals`, `GET /V1/ordo/order-approvals/:entityId` — tylko odczyt, ACL
  admina (`Ordo_Automation::config`). Sam token decyzyjny nigdy nie jest tu zwracany.
- `POST /V1/ordo/order-approvals/:token/approve` i `.../:token/reject` — **`anonymous`**,
  bez żadnego tokena OAuth/customer. Posiadanie `:token` (ten sam, co w linku e-mail do admina)
  jest jedynym poświadczeniem — identyczny model zaufania jak link "kliknij, aby zatwierdzić" w
  mailu.
- `GET /V1/ordo/order-approvals/:entityId/decision-links` — ACL admina; zwraca gotowe URL-e
  approve/reject (z wbudowanym tokenem) bez odsłaniania samego tokena jako osobnego pola —
  przydatne np. dla aplikacji mobilnej handlowca.

### Free gift (prezent za zamówienie)
- CRUD dla `free-gift-offers`, `free-gift-offer-tiers`, `free-gift-offer-products` — ACL admina
  (`Ordo_Automation::free_gifts`)
- `GET /V1/ordo/carts/:cartId/free-gift-eligibility` — **`self`**; działa też dla koszyka gościa
  (bez sprawdzania własności, gdy nie ma zalogowanego klienta)
- `PUT /V1/ordo/carts/:cartId/free-gifts` — **`self`**; wybór konkretnych SKU z dostępnego puli

### Limit kredytowy (B2B)
- `GET /V1/ordo/credit-limit/mine` — **`self`**; rozwiązuje klienta z samego tokena, żaden
  `customerId` nie jest potrzebny (i nie da się "zgadnąć" cudzego limitu)
- `GET /V1/ordo/customers/:customerId/credit-limit` — ACL admina (`Ordo_Automation::config`)

### Cykle odkupu (reorder cycles) — tylko odczyt
- `GET /V1/ordo/reorder-cycles`, `GET /V1/ordo/reorder-cycles/:entityId` — ACL admina
  (`Ordo_Automation::config`); liczone przez cron, nie zapisywalne przez API.

## Webhook przychodzący (integracja z ERP/CRM/PIM)

### `POST /ordo/webhook/receive`

Zasila trigger kampanii `webhook_received` — dowolny zewnętrzny system (ERP, CRM, PIM) może
odpalić kampanię, wysyłając dowolny JSON.

**Uwierzytelnianie**: nagłówek `X-Ordo-Signature: sha256=<hex>` — HMAC-SHA256 liczony z
surowego ciała żądania, kluczem jest sekret ustawiony w konfiguracji (**Sklepy → Konfiguracja →
Ordo Automation → Webhooks**). Ten sam format co Meta/WhatsApp (`X-Hub-Signature-256`).

Przykład liczenia sygnatury (Node.js):
```js
const crypto = require('crypto');
const signature = 'sha256=' + crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
```

Odpowiedzi: `200 {"ok": true}` / `401 {"ok": false}` (błędna sygnatura) / `404 {"ok": false}`
(webhook wyłączony w konfiguracji) / `500 {"ok": false}` (błąd dispatchu kampanii).

Payload JSON trafia do kontekstu kampanii jako `webhook_payload` — warunki/akcje kampanii mogą
go odczytać przez pole "Params (JSON)".

## Przykład: pełny flow trackingu po stronie custom frontendu

```js
// 1. Odczytaj/wygeneruj visitor_id (jeśli tracker.js nie jest ładowany, trzeba to zrobić samemu
//    i ustawić jako cookie ordo_visitor_id, żeby kampanie widziały tego samego odwiedzającego).
const visitorId = getOrCreateVisitorIdCookie();

// 2. Zaloguj zdarzenie
await fetch('/ordo/track/event', {
  method: 'POST',
  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
  body: new URLSearchParams({visitor_id: visitorId, event_type: 'product_view', event_key: sku}),
});

// 3. Pollowanie popupu (co 25s, pierwszy raz po 2s)
setInterval(async () => {
  const res = await fetch(`/ordo/track/popup?visitor_id=${visitorId}`);
  const {popup} = await res.json();
  if (popup) renderPopup(popup);
}, 25000);
```

---

# English

# API documentation — integrating a custom frontend

This page is for developers building their own frontend (headless PWA, mobile app, an
integration with an external CRM/ERP) against the Ordo Automation module. It documents every
public HTTP endpoint: the REST API (`/rest/V1/ordo/...`) and the public, unauthenticated
tracking endpoints (`/ordo/track/...`) that the bundled `tracker.js` script uses — but that any
client can call directly.

Admin-panel endpoints (`admin/ordo/...`) are out of scope here — those are Magento's own admin
UI, requiring an admin session and ACL, not meant for integration.

## Authentication model

This module has **two separate** trust boundaries, depending on the endpoint group:

1. **REST API** (`/rest/V1/ordo/...`) — standard Magento auth: an OAuth/integration token, or a
   customer token (via `/rest/V1/customers/me` login). Every route declares an ACL resource
   (`resources` in `webapi.xml`):
   - `Ordo_Automation::campaigns`, `Ordo_Automation::config`, `Ordo_Automation::free_gifts` —
     require an **admin** token with the matching ACL.
   - `self` — requires **any logged-in customer's** token; ownership of the specific resource
     (a cart, an offer) is checked inside the service, not by ACL.
   - `anonymous` — no token at all. Only two routes use this: approving/rejecting an order via
     the email-link token (see *Order Approval* below).
2. **Tracking endpoints** (`/ordo/track/...`) — no token, no CSRF (except where noted for
   push/price-watch registration). Identity comes from:
   - the `ordo_visitor_id` cookie (a UUID set by `tracker.js`, 365-day lifetime) — for anonymous
     visitors, passed as the `visitor_id` parameter;
   - the Magento customer session (`Magento\Customer\Model\Session`) — if the visitor is logged
     in, their `customer_id` is read automatically from the session (PHP session cookie), no
     need to pass it in the request.

   Same trust model as any analytics "pixel" — anyone who has a valid `visitor_id` can query
   these endpoints. Don't put anything sensitive in them.

## Tracking endpoints (`/ordo/track/...`)

All POSTs are `application/x-www-form-urlencoded` (a `fetch` call with `URLSearchParams`, or
classic `FormData`). Responses are always `Content-Type: application/json`.

### `POST /ordo/track/event`

Logs a raw behavior event (product/category view, element click) — the source data for
automatic behavior-based tagging (see [Tracking & Popups](Tracking-and-Popups)).

| Param | Type | Required | Notes |
|---|---|---|---|
| `visitor_id` | string | yes | the visitor's UUID |
| `event_type` | string | yes | one of: `page_view`, `product_view`, `category_view`, `element_clicked` |
| `event_key` | string | no | a context identifier, e.g. a product SKU; max 255 chars, longer is truncated |

Response: `{"ok": true}` or `{"ok": false, "reason": "tracking_disabled" | "invalid_payload"}`.

### `GET /ordo/track/popup?visitor_id=...`

Polls for a one-shot popup queued by a campaign's `show_popup` action. This **consumes** the
result — the same popup is never returned twice (claim-before-deliver), so poll regularly (the
bundled client polls every 25s, first poll after 2s).

Response: `{"popup": {"headline": "...", "body": "...", "cta_label": "...", "cta_url": "..."} | null}`.

### `GET /ordo/track/notification?visitor_id=...`

Polls for **every** unread, unexpired persistent notification (a campaign's `show_notification`
action). Unlike the popup endpoint, this does **not** consume anything — the same set comes back
on every poll until explicitly dismissed.

Response: `{"notifications": [{"id": 123, "headline": "...", "body": "...", "cta_label": "...", "cta_url": "..."}]}`.

### `POST /ordo/track/dismissnotification`

Dismisses a notification (sets `read_at`).

| Param | Type | Required |
|---|---|---|
| `visitor_id` | string | yes (for anonymous visitors) |
| `notification_id` | int | yes |

Response: `{"ok": true}` or `{"ok": false}` — including when `notification_id` doesn't belong to
the caller (checked against `customer_id` or `visitor_id`).

### `GET /ordo/track/survey?visitor_id=...`

Polls for a one-shot NPS/satisfaction survey (a campaign's `nps_survey` action).
Claim-before-deliver, same as the popup endpoint.

Response: `{"survey": {"id": 456, "question": "..."} | null}`.

### `POST /ordo/track/submitsurveyresponse`

Records a survey answer.

| Param | Type | Required | Notes |
|---|---|---|---|
| `visitor_id` | string | yes (for anonymous visitors) | |
| `survey_id` | int | yes | the `id` from the `survey` endpoint's response |
| `score` | int | yes | 0-10 |

Response: `{"ok": true}` or `{"ok": false}` — rejected if the survey was already answered,
doesn't exist, or doesn't belong to the caller.

### `POST /ordo/track/registerpushsubscription`

Registers a Web Push subscription (after `PushManager.subscribe()` in the browser).

| Param | Type | Required |
|---|---|---|
| `endpoint` | string | yes |
| `p256dh` | string | yes |
| `auth` | string | yes |

Response: `{"ok": true}` or `{"ok": false, "reason": "push_disabled" \| "invalid_payload" \| "invalid_endpoint"}`.

**On CSRF**: for a logged-in customer, this endpoint checks the `Origin`/`Referer` header
(there's no classic `form_key` — this is a bare `fetch()` call) — it must match the store's own
domain. Anonymous registrations (no session) skip this check.

### `POST /ordo/track/unregisterpushsubscription`

| Param | Type | Required |
|---|---|---|
| `endpoint` | string | yes |

Response: `{"ok": true}` or `{"ok": false, "reason": "invalid_payload"}`.

### `POST /ordo/track/registerpricewatch`

Registers a "notify me" subscription from a PDP (price drop / back in stock).

| Param | Type | Required | Notes |
|---|---|---|---|
| `product_id` | int | yes | |
| `watch_type` | string | yes | `price_drop` or `back_in_stock` |

Response: `{"ok": true}` or `{"ok": false, "reason": "price_watch_disabled" \| "invalid_payload" \| "invalid_product"}`.
Same CSRF model as `registerpushsubscription`.

## REST API (`/rest/V1/ordo/...`)

Standard Magento REST — `Content-Type: application/json`, `Authorization: Bearer <token>`
header. The full, always-current parameter/type schema is available via Magento's own Swagger
(`/rest/V1/schema?services=ordoAutomationAll`) — this is just the endpoint map.

### Campaigns (admin ACL: `Ordo_Automation::campaigns`)
- `GET/POST /V1/ordo/campaigns`, `GET/PUT/DELETE /V1/ordo/campaigns/:entityId`
- `GET/POST /V1/ordo/campaign-triggers`, `GET/PUT/DELETE /V1/ordo/campaign-triggers/:entityId`
- `GET/POST /V1/ordo/campaign-conditions`, `GET/PUT/DELETE /V1/ordo/campaign-conditions/:entityId`
- `GET/POST /V1/ordo/campaign-actions`, `GET/PUT/DELETE /V1/ordo/campaign-actions/:entityId`

Triggers/conditions/actions are flat resources filtered by `campaign_id` via Magento's standard
`searchCriteria` (`?searchCriteria[filterGroups][0][filters][0][field]=campaign_id&...`), not a
nested URL.

### Customer tags (admin ACL: `Ordo_Automation::campaigns`)

The core segmentation primitive ("send to everyone tagged X") — a headless storefront/CRM can
manage tags without an admin panel session.

- `GET /V1/ordo/customers/:customerId/tags` — a customer's tags
- `GET /V1/ordo/customers/:customerId/tags/:tag` — whether a customer has a given tag (bool)
- `PUT /V1/ordo/customers/:customerId/tags/:tag` — adds a tag (fires the `tag_added` trigger)
- `DELETE /V1/ordo/customers/:customerId/tags/:tag` — removes a tag
- `GET /V1/ordo/tags/:tag/customers` — customer IDs holding a given tag

### Offer expiry
- `GET/POST /V1/ordo/offers`, `GET/PUT/DELETE /V1/ordo/offers/:entityId` — admin ACL
  (`Ordo_Automation::config`)
- `POST /V1/ordo/offers/:offerId/self-extend` — **`self`**, any logged-in customer's token;
  ownership of the specific offer is checked inside the service (not by ACL) — a customer can
  extend **their own** offer by one more period.

### Order approval
- `GET /V1/ordo/order-approvals`, `GET /V1/ordo/order-approvals/:entityId` — read-only, admin
  ACL (`Ordo_Automation::config`). The decision token itself is never returned here.
- `POST /V1/ordo/order-approvals/:token/approve` and `.../:token/reject` — **`anonymous`**, no
  OAuth/customer token at all. Possessing `:token` (the same one from the admin's email link) is
  the only credential — identical trust model to any "click to approve" email link.
- `GET /V1/ordo/order-approvals/:entityId/decision-links` — admin ACL; returns the ready-made
  approve/reject URLs (token baked in) without ever exposing the token as its own field — useful
  for e.g. a sales-rep mobile app.

### Free gift
- CRUD for `free-gift-offers`, `free-gift-offer-tiers`, `free-gift-offer-products` — admin ACL
  (`Ordo_Automation::free_gifts`)
- `GET /V1/ordo/carts/:cartId/free-gift-eligibility` — **`self`**; also works for a guest cart
  (ownership check is skipped when there's no logged-in customer)
- `PUT /V1/ordo/carts/:cartId/free-gifts` — **`self`**; picks specific SKUs from the eligible pool

### Credit limit (B2B)
- `GET /V1/ordo/credit-limit/mine` — **`self`**; resolves the customer from the token itself, no
  `customerId` needed (and no way to guess someone else's limit)
- `GET /V1/ordo/customers/:customerId/credit-limit` — admin ACL (`Ordo_Automation::config`)

### Reorder cycles — read-only
- `GET /V1/ordo/reorder-cycles`, `GET /V1/ordo/reorder-cycles/:entityId` — admin ACL
  (`Ordo_Automation::config`); computed by a cron job, not writable via the API.

## Inbound webhook (ERP/CRM/PIM integration)

### `POST /ordo/webhook/receive`

Feeds the `webhook_received` campaign trigger — any external system (ERP, CRM, PIM) can fire a
campaign by sending arbitrary JSON.

**Authentication**: the `X-Ordo-Signature: sha256=<hex>` header — an HMAC-SHA256 over the raw
request body, keyed by a secret set in configuration (**Stores → Configuration → Ordo
Automation → Webhooks**). Same shape as Meta/WhatsApp's `X-Hub-Signature-256`.

Signing example (Node.js):
```js
const crypto = require('crypto');
const signature = 'sha256=' + crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
```

Responses: `200 {"ok": true}` / `401 {"ok": false}` (bad signature) / `404 {"ok": false}`
(webhook disabled in configuration) / `500 {"ok": false}` (campaign dispatch error).

The JSON payload lands in the campaign's context as `webhook_payload` — campaign
conditions/actions can read it via the "Params (JSON)" field.

## Example: a full tracking flow from a custom frontend

```js
// 1. Read/create the visitor_id (if tracker.js isn't loaded, you need to do this yourself and
//    set it as the ordo_visitor_id cookie so campaigns see the same visitor consistently).
const visitorId = getOrCreateVisitorIdCookie();

// 2. Log an event
await fetch('/ordo/track/event', {
  method: 'POST',
  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
  body: new URLSearchParams({visitor_id: visitorId, event_type: 'product_view', event_key: sku}),
});

// 3. Poll for a popup (every 25s, first poll after 2s)
setInterval(async () => {
  const res = await fetch(`/ordo/track/popup?visitor_id=${visitorId}`);
  const {popup} = await res.json();
  if (popup) renderPopup(popup);
}, 25000);
```
