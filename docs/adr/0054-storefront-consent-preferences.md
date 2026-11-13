# ADR 0054: Storefront consent preference center

## Status

Accepted.

## Context

A third-round brainstorm candidate (2026-11-12) flagged a real, long-standing gap:
`Model\ConsentManager` was only ever touched by `Controller\Adminhtml\Gdpr\*` (an admin looking up
one customer by email) and `Controller\Email\StatusCallback`'s webhook-driven STOP-equivalent
revocation. There was no frontend controller or GraphQL mutation anywhere that let a logged-in
customer see or change their own channel (email/SMS/WhatsApp/push/RCS) or topic
(promotional/account-updates) consent. `docs/adr/0026-per-topic-consent-granularity.md` already
named this explicitly in its own Consequences section ("no storefront self-service page").

## Decision

- **One service, three transports.** `Api\ConsentPreferencesManagementInterface`
  (`getMyPreferences()`/`setMyChannelConsent()`/`setMyTopicConsent()`) is the only place consent
  logic for the authenticated customer lives — a REST route (`GET/PUT
  /V1/ordo/consent-preferences/mine*`), three GraphQL resolvers (`ordoConsentPreferences`/
  `setOrdoConsent`/`setOrdoConsentTopic`), and a plain storefront account page
  (`Controller\Consent\*`) are all thin adapters over it, the same "no logic duplicated into a
  resolver or controller" rule every other GraphQL resolver in this module already follows.
- **Resolves identity by transport, same split as every other customer-scoped Ordo endpoint.**
  REST/frontend-controller dispatch reads `UserContextInterface::getUserId()` directly (`null` for
  a guest in both paths — confirmed, no 0-vs-null quirk here). GraphQL goes through the shared
  `Model\GraphQl\CustomerIdResolver`, since `ContextInterface::getUserId()` returns `0` for a
  guest, not `null`, on that transport specifically (ADR 0032's confirmed quirk) — the resolver
  checks this itself before ever calling the management service, the same guard CreditLimit's own
  resolver already needs.
- **Discovered mid-implementation: Magento's WebAPI output serializer cannot correctly serialize a
  bare associative array.** The first cut of `Api\Data\ConsentPreferencesInterface` returned plain
  `array<string, bool>` maps (`channel_consent`, `topic_consent`), mirroring
  `ConsentManager::getConsentStates()`'s own return shape. Live-tested against a real customer
  token: `channel_consent` lost every string key and came back as a bare JSON array of 6 booleans
  with no way to tell which bool was which channel; `topic_consent` came back as an array of
  literal JSON-encoded **strings** (e.g. `"{\"promotional\":false,\"account_updates\":true}"`)
  instead of nested objects. Root cause: Magento's reflection-based `ServiceOutputProcessor`/
  `TypeProcessor` only correctly handles scalars, typed `Api\Data\*Interface` objects, or arrays of
  such objects — never a bare associative array of mixed scalar/array values. Fixed by giving the
  per-channel shape its own typed interface, `Api\Data\ConsentChannelPreferenceInterface`
  (`consented`/`promotional`/`account_updates` bools), with `ConsentPreferencesInterface` exposing
  one typed getter/setter pair per channel (`getEmail()`/`setEmail(ConsentChannelPreferenceInterface)`,
  etc.) plus a plain `getAds()`/`setAds(bool)` (not a delivery channel, no topic concept) — the
  same pattern `OrderApprovalDecisionLinksInterface` and every other nested Api\Data structure in
  this module already use, never a raw array.
- **Every `@return`/`@param` in the new `Api\Data\*Interface`/`Api\*Interface` nested-object tags
  uses the fully-qualified class name**, matching the existing convention in e.g.
  `CampaignActionSearchResultsInterface::getItems()`. Confirmed necessary, not just stylistic:
  Magento's reflection resolves a docblock type tag against its own naive parsing, not real PHP
  `use`-import resolution — a short, unqualified type name in the tag (even one correctly imported
  and type-hinted in the method signature itself) throws `Class "X" does not exist` at dispatch
  time, caught live while testing `GET /V1/ordo/consent-preferences/mine`.
- **The storefront page reads through the same management service, not `ConsentManager`
  directly.** `Block\Frontend\ConsentPreferencesViewModel` depends on
  `ConsentPreferencesManagementInterface`, not `ConsentManager` — the channel-loop/topic-default
  logic stays in exactly one place, and the page shows exactly what REST/GraphQL would return for
  the same customer.

## Consequences

- A customer can now see and change their own consent across every channel/topic this module
  tracks, from any of REST, GraphQL, or a server-rendered storefront account page — closing the
  gap ADR 0026 named and leaving `Controller\Adminhtml\Gdpr\*` as an admin-override surface rather
  than the only way consent is ever read or changed.
- The bare-associative-array REST serialization limitation is now a documented, verified fact
  about this Magento version rather than a one-off workaround: any future REST-exposed structure
  in this module with more than scalar values should default to a typed nested `Api\Data\*Interface`
  from the start, not a generic array map discovered to be broken only after a live test.
