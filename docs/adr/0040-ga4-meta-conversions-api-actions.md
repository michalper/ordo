# ADR 0040: GA4 Measurement Protocol / Meta Conversions API campaign actions

## Status

Accepted.

## Context

`ROADMAP.md` listed "GA4/Meta Conversions API webhook template" as a candidate, framed as:
"`Model\Campaign\Action\SendWebhook` already exists as a generic action; a documented,
ready-to-wire payload shape ... would close the loop between Ordo's segmentation and a store's ad
platforms." Reading `SendWebhook` itself before implementing showed that framing doesn't hold:
that action always wraps its payload as `{"event", "campaign_id", "context"}`, always signs it
with this store's own HMAC-SHA256 secret, and always posts to one store-wide configured URL -
none of which GA4's Measurement Protocol or Meta's Conversions API accept. Both platforms require
their own exact JSON shape, their own auth scheme (a `measurement_id`/`api_secret` or
`pixel_id`/`access_token` pair, as query parameters, not a header), and Meta additionally requires
a hashed customer identifier for user matching that a generic webhook payload has no concept of.
A "documented payload shape" bolted onto `SendWebhook` via its `event` param would mean the
action either abandons the HMAC-signing-to-one-URL behavior some other campaign already depends
on, or the new behavior has to live somewhere else - the latter is what this ADR does.

Why this matters at all: browser-based ad tracking (the regular on-page `gtag.js`/Pixel tag) is
increasingly unreliable - ad blockers, Safari ITP, declined cookie consent, iOS privacy changes
can all silently stop it from ever firing. Server-to-server reporting bypasses all of that, since
Ordo's own server, not the customer's browser, makes the call.

## Decision

- **Two new, separate campaign actions** (`send_ga4_event`, `send_meta_conversion_event`), not
  one shared class with a "provider" param and not a param added to `SendWebhook` - each
  platform's payload shape, auth, and required fields differ enough (Meta needs a hashed email,
  GA4 doesn't; Meta's currency is lowercase ISO 4217, GA4's is uppercase; the auth scheme differs)
  that a shared abstraction would mostly be `if ($provider === ...)` branches, the same reasoning
  this module already applies to keeping `SendEmail`/`SendSms`/`SendWhatsApp`/`SendPush` as
  separate classes rather than one parameterized "send message" action.
- **Each posts directly to the platform's own public endpoint** with the platform's own query-
  string auth, using the existing `Model\Http\JsonApiClient::postJson()` for the HTTP mechanics
  only (same reuse `GoogleAdsSyncClient`/`MetaSyncClient`/`WhatsAppSender` already make of it) -
  `SendWebhook`'s own signing/payload-wrapping is not reused at all.
- **Context must include `order_id`** (and, for Meta, `customer_id`) - both actions load the real
  order for an authoritative `grand_total`/`order_currency_code`/`increment_id` rather than
  trusting whatever a trigger's own context happened to carry (`order_placed`'s context has
  `order_total` but no currency code at all). Intended for the `order_placed` trigger (optionally
  gated by a value condition, per the original roadmap framing), but works from any trigger/
  condition chain that still has a real order in context by the time this action runs. Fails
  closed (logs, does not throw) when `order_id`/`customer_id` is missing or the entity can't be
  loaded, same as every other action.
- **Shared `Model\Campaign\Action\OrderContextResolver`** for that order-loading step - both
  actions' own "read order_id from context, load it, null on anything wrong" logic was byte-for-
  byte identical, caught by SonarCloud's new-code duplication gate during review (10.1% on a
  2-file PR) rather than left as two copies that would quietly drift apart the next time either
  one needed to change.
- **Reuses `Model\AdAudience\PiiHasher::hashEmail()`** for Meta's required `user_data.em` field -
  that class's own docblock already establishes it as the one shared email-hashing chokepoint for
  both Google Ads Customer Match and Meta Custom Audiences; Conversions API's own hashing spec
  (trim, lowercase, SHA-256 hex) is identical, so this is a third consumer of the same helper, not
  a second, easy-to-get-subtly-wrong copy of the same normalization.
- **Its own credentials, deliberately separate from the existing Ad Audience Sync integration's**
  Meta fields (`Helper\Config::getMetaAccessToken()`/`getMetaAdAccountId()`) - that integration
  pushes customer lists for ad *targeting* (Custom Audiences); this reports individual purchases
  for ad *measurement/optimization* (Conversions API). Different Meta products, different
  permission scopes, so a store configures them independently rather than this silently reusing a
  token that may not even be allowed to call the other API. New `conversion_tracking` group
  (`ordo_integrations` section, next to `ad_audience_sync`): `ga4_enabled`/`ga4_measurement_id`/
  `ga4_api_secret`, `meta_capi_enabled`/`meta_capi_pixel_id`/`meta_capi_access_token`.
- **No real GA4 `client_id`** - there is no browser/cookie value available server-side. Uses a
  stable `"ordo-customer-<id>"` string, which GA4 itself documents as an acceptable stand-in for
  a server-to-server hit with no existing web session to attach to. Documented honestly in the
  class's own docblock as a real limitation: this does not stitch the event to whatever ad
  click/session actually drove the visit unless the store's own GA4 property already links that
  customer via User-ID tracking - this action reports the conversion, it does not solve
  attribution modeling by itself.
- **Rate-limited through the existing `Helper\Config::getWebhookMaxRequestsPerSecond()` setting**
  (each action on its own independent `OutboundRateLimiter` channel), rather than two more config
  fields - GA4/Meta's own APIs tolerate far higher throughput than a typical store's own webhook
  consumer, so a shared, already-exposed pacing knob is enough; its own comment was updated to
  say so rather than silently repurposing it undocumented.
- **No GA4 `items[]` line-item data** - `order_placed`'s own context has no per-product line data,
  and loading full order items for every send would be a real cost increase for a feature whose
  whole value proposition here is "the aggregate purchase value reached GA4/Meta reliably," not a
  full commerce data feed. `transaction_id`/`value`/`currency` is GA4's own documented minimum for
  a usable purchase event.

## Consequences

- A store can now report real purchase conversions to GA4/Meta from the server side, closing the
  gap `ROADMAP.md` described, without needing the full Ad Audience Sync integration's $50k-spend-
  gated Google Ads Customer Match access (see `ROADMAP.md`'s own "Test coverage" section) - these
  two actions work on any GA4 property/Meta Pixel, regardless of ad spend.
- Two more provider credentials for a store to configure, independent of the existing Ad Audience
  Sync ones - more setup surface, but avoids a token-scope failure mode that would be far more
  confusing to debug than one extra config group.
- Neither action builds a full Measurement Protocol/Conversions API client (batching, GA4's debug
  endpoint, Meta's test event code, deduplication via `event_id`) - a single order's own `order_id`
  is already a stable natural key a store's own GA4/Meta-side deduplication can use if needed; a
  fuller client is real future scope if a store needs more than a one-shot purchase report.
