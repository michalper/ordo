# ADR 0034: GraphQL storefront API, price-watch & push subscriptions (Phase 3)

## Status

Accepted.

## Context

`ROADMAP.md`'s "GraphQL storefront API" section phases the work; ADR 0032 covers Phase 1 (core
tracking), ADR 0033 covers Phase 2 (My Offers). This ADR covers Phase 3, the last planned slice:
`registerOrdoPriceWatch`/`unregisterOrdoPriceWatch` and `registerOrdoPushSubscription`/
`unregisterOrdoPushSubscription`.

## Decision

- **Register mutations are thin wrappers, same approach as every prior phase.**
  `registerOrdoPriceWatch`/`registerOrdoPushSubscription` reuse
  `Model\PriceWatch\PriceWatchSubscriptionManager::register()`/`Model\Push\PushSubscriptionManager::register()`
  exactly, including `Model\Push\PushEndpointValidator`'s SSRF defense on the push endpoint -
  the same validation `Controller\Track\RegisterPriceWatch`/`RegisterPushSubscription` already
  enforce, not a parallel reimplementation.
- **`unregisterOrdoPriceWatch` has no REST/controller equivalent to mirror.**
  `PriceWatchSubscriptionManager::unregister()` already exists and is already used internally
  (`attributeVisitorToCustomer()`'s own cleanup path), but no customer-facing transport had ever
  called it directly - `Controller\Track\RegisterPriceWatch` only registers. This resolver is
  the first customer-facing surface for that capability. `unregisterOrdoPushSubscription` DOES
  mirror an existing one (`Controller\Track\UnregisterPushSubscription`).
- **Identity model unchanged from Phase 1/2**: `Model\GraphQl\CustomerIdResolver`, not
  `Model\Track\VisitorIdentityResolver`'s cookie-based `resolveVisitorId()` - a decoupled/PWA
  frontend carries neither a session cookie nor the visitor-id cookie cross-origin, so
  `visitorId` stays an explicit argument on every one of these four mutations, same as every
  prior phase's.
- **No new shared-logic extraction needed this time.** Unlike Phase 1's `TrackerPoll` (ADR
  0032's `TrackerPollReader` lesson) or Phase 2's `OfferDataMapper` (ADR 0033), none of these
  four resolvers duplicate more than a few lines of validation already inherent to adapting one
  transport's argument shape to another's - there's no real shared block to extract.

## Consequences

- A headless/PWA Studio storefront can now manage price/back-in-stock watches and push
  subscriptions without a server-rendered page.
- `registerOrdoPriceWatch`/`registerOrdoPushSubscription` are gated by their own feature toggle
  (`Helper\Config::isPriceWatchEnabled()`/`isPushEnabled()`), each off by default - same
  "every feature has its own config, no master switch" rule as the rest of the module (see
  `CLAUDE.md`). `Test\GraphQl\PriceWatchAndPushApiTest`'s register-path tests assert the response
  *shape* rather than a specific `ok` value for this reason, same reasoning
  `TrackingApiTest::testOrdoTrackerPollReturnsTheExpectedShapeForAnonymousVisitor` already uses.
- This closes `ROADMAP.md`'s "GraphQL storefront API" section - all three planned phases are now
  shipped.
