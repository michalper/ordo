# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for implementation history/verification
detail see [CHANGELOG.md](docs/CHANGELOG.md) and [VERIFICATION.md](VERIFICATION.md), for the REST API reference see
[API.md](API.md), and the `Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## Test coverage

- **Ad-audience sync (`Cron\SyncAdAudiences`) has no test against a real Google Ads/Meta account.** Note:
  a since-fixed bug (docs/CHANGELOG.md "Fixed") meant `getGoogleAdsClientSecret()`/`getGoogleAdsRefreshToken()`/
  `getGoogleAdsDeveloperToken()`/`getMetaAccessToken()` returned ciphertext at runtime, not the decrypted
  secret — every real API call would have failed auth regardless of this gap. Same shape as
  `send_sms`'s equivalent gap, already closed (see docs/CHANGELOG.md): unit tests
  (`GoogleAdsSyncClientTest`/`MetaSyncClientTest`/`GoogleOAuthTokenProviderTest`)
  drive the real request-building/response-parsing logic via a fake `Curl`, and the integration test
  (`SyncAdAudiencesTest`) uses real DI/database (real segment/tag/customer rows, real `SegmentMemberResolver`
  query, real `PiiHasher`) but swaps `SyncClientInterface` for a `RecordingSyncClient` — so the actual HTTP
  calls to `googleads.googleapis.com`/`graph.facebook.com` (OAuth token exchange, offline user data job
  lifecycle, Custom Audience creation/replace) have never been exercised against live credentials.
- **`send_whatsapp` / WhatsApp templates have no test against a real Meta WhatsApp Business Account.** Note:
  the same since-fixed bug meant `getWhatsAppAccessToken()`/`getWhatsAppAppSecret()` returned ciphertext at
  runtime — every real Graph API call and every webhook signature check would have failed regardless of this
  gap. Same shape again: unit tests (`WhatsAppSenderTest`/`WhatsAppTemplateClientTest`) drive the real Graph API
  request-building/response-parsing logic via a fake `Curl`, and `WhatsAppSignatureValidatorTest`/`WebhookTest`
  use a real HMAC-SHA256 signature — but template submission (`SubmitForReview`), approval polling
  (`RefreshStatus`), and an actual template message send have never been exercised against a live WABA/phone
  number, and the webhook receiver has never received a genuine callback from Meta.

### MFTF/scenario coverage

Full inventory with what's covered and why: `Test/Mftf/SCENARIOS.md`. Every row there is currently ✅ — no open
gaps. Kept as the standing scope check for anything newly added to the module (new trigger/condition/action/
controller/cron gets a row there before it's considered done).

## Full-codebase improvement audit (2026-09-10)

Five independent passes over the whole module (campaign engine, segmentation/RFM/scoring,
communication channels, commerce features, admin platform/UX/API), each grounded in the actual
code rather than guesswork. Organized by domain below. Tiers 0-4 from the original pass are all
fully closed — see docs/CHANGELOG.md for the full history of each.

### Campaign engine (`Model/CampaignDispatcher.php`, `Model/Queue/*`, Flow canvas)

Flow canvas UX (undo/redo, node duplication, palette search/filter, inline "send test") is now
fully closed — see docs/CHANGELOG.md.

### Communication channels (Email/SMS/WhatsApp/Push)

- Every send is still one synchronous, unbatched HTTP call per customer inline in the dispatch
  path — no concurrency control (client-side pacing per provider now exists, see docs/CHANGELOG.md
  — that's throttling one process's own call rate, not coordinating concurrency across multiple
  queue consumers/cron processes hitting the same provider at once).
- `send_push`'s per-subscription sends still have no persisted retry once `SendRetrier`'s 3
  in-process attempts are exhausted (`send_email`/`send_sms`/`send_whatsapp` now do, see
  docs/CHANGELOG.md) — a whole-action retry would risk re-sending to subscriptions that already
  succeeded the first time; a per-subscription retry queue would be needed to close this safely,
  not attempted yet.

### Commerce features (free gifts, order approval, reorder cycles, GDPR, product feed, dashboard)

*(the "Free Gift never applies to a cart" and "guest checkout bypasses approval" items are listed
as bugs above, not repeated here)*

- Product feed is still single-format (Google RSS only) — multi-store and health/history are now
  covered (see docs/CHANGELOG.md). A second feed format (beyond Google Merchant's RSS+g: namespace)
  is a bigger, separate abstraction.

### Admin platform, UX consistency & API

- No bulk/mass-action on MessageLog, ReorderCycle, and Rfm's grids (Campaign, Segment,
  ContentBlock, FreeGiftOffer, ScoreRule, and AdAudience now have enable/disable/delete mass
  actions, and WhatsAppTemplate has mass-delete — see docs/CHANGELOG.md) — those three remaining
  grids are read-only/log/diagnostic views without an "enabled" concept to toggle, so a mass
  action there would need its own new capability first, not just wiring up an existing one.
## Scheduled (date-based) campaigns: calendar view

Both the backend (`ScheduledTriggerScanner`/`DispatchScheduledCampaignTriggers`) and the admin UI
to configure `scheduled_at`/`recurring_schedule` triggers are done — see docs/CHANGELOG.md. What's
left is optional polish: an actual date-grid calendar view plotting when each scheduled campaign
will (or did) fire. Worth revisiting whether "Campaign Action Timeline" should grow a
calendar-view toggle for this, or stay a separate screen.

## Localization

- **Native-speaker review of the 10 machine-translated locales** (`de_DE`, `fr_FR`, `es_ES`, `it_IT`, `pt_BR`,
  `zh_Hans_CN`, `ja_JP`, `ru_RU`, `uk_UA`, `nl_NL`) — shipped as a machine-translated first pass (see
  docs/CHANGELOG.md),
  not yet signed off by a human reviewer per locale. Highest priority: launch-blocking strings (error messages,
  delete confirmations) over descriptive/help text.

## Documentation

- **GitHub Wiki covering every feature, bilingual PL/EN, with screenshots.** Not started. One walkthrough page per
  shipped capability (campaigns, segments, RFM, lead scoring, free gifts, order approval, tracking/popups, reorder
  cycles, dashboard), each with a real admin-UI screenshot and PL/EN text. Needs a structure decision first —
  GitHub Wiki has no built-in i18n, so bilingual-per-page vs. a language-split page tree is a real choice.

## Multi-currency correctness (audit 2026-09-11)

Fresh pass specifically hunting for bugs not already covered above. Found three related, previously
unreported currency-handling bugs — none touched by any item above, all financially relevant on any
store with more than one allowed currency:

- **Free-gift tier `min_subtotal` is compared against the quote's display-currency subtotal, not base
  currency.** `Model/FreeGiftManagement.php` compares `Quote::getSubtotal()` (display currency) directly
  against the admin-configured `min_subtotal` (a plain numeric field with no currency selector, implicitly
  meant as base currency). On a store allowing multiple currencies, exchange-rate movement can push a cart
  across the threshold in the wrong direction — granting or denying a free gift incorrectly.
- **B2B credit-limit usage sums `sales_order.total_due` (order currency) across a customer's orders,
  not `base_total_due`.** `Model/CreditLimitCalculator::getUsedCredit()`/`getUsedCreditForCustomers()` do
  `SUM(total_due)`, mixing currencies if a customer has orders placed under more than one currency (e.g.
  after a website/currency change). This number directly drives
  `Plugin\Quote\BlockOverLimitCheckout` (hard-blocks checkout at 100%) and `Cron\SendCreditLimitAlerts` —
  a real risk of wrongly blocking or wrongly allowing an order.
- **The Google Merchant product feed emits base-currency prices mislabeled with the store's display
  currency code.** `Model/ProductFeed/GoogleMerchantFeedGenerator.php` reads `getFinalPrice()` (base
  currency, from `catalog_product_index_price`) but tags it with `$store->getCurrentCurrencyCode()`
  (display currency) — no conversion applied. On a store where display currency ≠ base currency, every
  price in the feed is off by the currency rate, which Google Merchant Center will flag as a landing-page
  price mismatch.

All three are single-currency-store-safe (no bug if base currency = only allowed currency), so they've
stayed invisible until now. Fix shape for all three: convert through `Store::getBaseCurrency()->convert()`
(or the equivalent already used elsewhere in the module) before comparing/emitting, and use
`base_total_due` instead of `total_due` for the credit-limit sum.

## Candidate new features

Not gaps in something existing — genuinely new capabilities, proposed after checking they don't already
exist in some form. Not prioritized against each other; listed for later scoping.

- **Customer Lifetime Value (CLV) scoring** — a forward-looking counterpart to the existing RFM
  (`Model/Rfm/RfmCalculator.php`), exposed as a new condition/segment criterion and dashboard KPI. Medium
  scope, fits both B2B and B2C.
- **Price-drop & back-in-stock alerts** — customer/visitor opt-in on PDP, a cron watching catalog
  price/stock changes, two new trigger types through the existing `CampaignDispatcher` pipeline.
  Medium-large scope, primarily B2C.
- **Generic outbound webhook action + inbound webhook trigger** — a `send_webhook` action and a signed
  `webhook_received` trigger, giving external systems (ERP/CRM/PIM) a two-way integration point beyond
  today's provider-status-only inbound webhooks. Medium scope, higher value for B2B (ERP/CRM integration).
- **Predictive send-time optimization** — pick each customer's historically best send hour from existing
  `ordo_message_log` open/click data and hold the action via the existing delayed-action/resume mechanism
  (`Cron\RunScheduledCampaignActions`) instead of a fixed delay. Medium scope, fits both.
- **Two-way SMS/WhatsApp conversation handling** — capture inbound replies (today only delivery-status
  callbacks are handled), with mandatory keyword-based opt-out (STOP) and a conversation view tied to the
  customer record. Large scope (compliance-sensitive once inbound is accepted at all), fits both.
- **Browse-abandonment campaigns** — a dedicated trigger/cron over the `product_view`/`category_view`
  visitor events already captured by `VisitorEventLogger`, one step earlier in the funnel than the
  existing cart-abandonment reminder. Small-medium scope, primarily B2C.
- **Multi-touch attribution / revenue-per-campaign reporting** — correlate `ordo_message_log`
  click-throughs to subsequent orders within a configurable window, surfaced as attributed revenue on the
  Campaign grid/dashboard. Medium scope, fits both.

## Priorytet kolejnych kroków

Kolejność uwzględnia wagę (błędy finansowe > dług niezawodności > UX > tematy zależne od
zasobów zewnętrznych):

1. **Bugi walutowe (sekcja "Multi-currency correctness" powyżej)** — realny wpływ finansowy
   (błędne blokowanie/odblokowywanie zamówień B2B, błędna cena w feedzie produktowym, błędna
   kwalifikacja do gratisu). Najwyższy priorytet mimo że dotyczy tylko sklepów z więcej niż
   jedną obsługiwaną walutą — trzeba to najpierw potwierdzić na produkcji (czy Sellina/klienci
   faktycznie używają multi-currency), bo jeśli tak, to blokuje realne transakcje już teraz.
2. **Bulk actions na MessageLog/ReorderCycle/Rfm** — wymaga najpierw decyzji projektowej,
   potem implementacji.
3. **Nowe funkcje (sekcja "Candidate new features" powyżej)** — do rozważenia razem z
   biznesem/produktem pod kątem priorytetu; browse-abandonment i webhook action/trigger
   wyglądają na najmniejszy koszt wejścia względem wartości.
4. **Drugi format product feedu** — większa, osobna abstrakcja; wymaga wyboru formatu
   docelowego przed implementacją.
5. **Kalendarz dat dla scheduled campaigns** — opcjonalny polish.
6. **Testy na żywych kontach (Google Ads/Meta/WhatsApp)** — zależne od dostępności realnych
   poświadczeń testowych.
7. **Recenzja natywna 10 lokalizacji** — zależna od dostępności recenzentów per język.
8. **GitHub Wiki (PL/EN, screenshots)** — wymaga wcześniej decyzji o strukturze.

Uwaga poza roadmapą: na branchu `feature/reorder-cycle-build-cart` jest niedokończona,
nie-scommitowana praca nad akcją "build reorder cart" (temat sam w sobie już częściowo
pokryty w CHANGELOGu przez manual reminder, PR #91) — do domknięcia jako osobny temat, nie
część tej rundy porządkowej.
