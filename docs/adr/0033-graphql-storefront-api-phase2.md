# ADR 0033: GraphQL storefront API, My Offers (Phase 2)

## Status

Accepted.

## Context

`ROADMAP.md`'s "GraphQL storefront API" section phases the work; ADR 0032 covers Phase 1 (core
tracking). This ADR covers Phase 2: `ordoOffers`, `extendOrdoOffer`, `ordoCreditLimit` — the
storefront "My Offers" page and credit-limit status, both of which already have an existing
REST/frontend-controller path (`Controller\Offer\*`, `/V1/ordo/offers/:offerId/self-extend`,
`/V1/ordo/credit-limit/mine`).

## Decision

- **`extendOrdoOffer` and `ordoCreditLimit` call the existing `Api\OfferManagementInterface` /
  `Api\CreditLimitManagementInterface` services directly** — no new business logic, same "second
  transport onto the same service" approach Phase 1 established.
- **`ordoOffers` does not reuse `Block\Frontend\OfferViewModel`.** That view model resolves the
  customer from `Magento\Customer\Model\Session`, which (per ADR 0032) a decoupled/PWA storefront
  doesn't carry. The resolver rebuilds the same search-criteria-plus-`canSelfExtend()` logic
  itself, scoped by `Model\GraphQl\CustomerIdResolver` instead.

## A real, non-obvious finding verifying this against a live instance

`OfferManagement::selfExtend()` and `CreditLimitManagement::getMyStatus()` both resolve the caller
via a DI-injected `Magento\Authorization\Model\UserContextInterface`, not the GraphQL
`ContextInterface` their own resolver receives as `$context`. This looked risky going in — Magento
core's own GraphQL resolvers (e.g. `CustomerGraphQl\Model\Resolver\Customer`) deliberately avoid
depending on an ambient, DI-injected user context and instead always thread the resolver's own
`$context` through explicitly, which suggested the ambient one might not be reliably populated for
a GraphQL request. Checked empirically rather than assumed: a resolver calling
`CreditLimitManagement::getMyStatus()` with a real customer token correctly resolved that
customer's own data, confirming `UserContextInterface` *is* populated correctly for a GraphQL
customer-token request in this Magento version, same as it already is for REST and for an
ordinary session-based frontend controller request (via
`Magento\Customer\Model\Authorization\CustomerSessionUserContext`). So both services could be
reused as-is with no new identity-plumbing — but this is empirically confirmed, not something to
assume holds for every future Magento version this module supports.

One real gap it exposed: `CreditLimitManagement::getMyStatus()`'s own guest guard checks
`$customerId === null`, but `UserContextInterface::getUserId()` returns `0` for a guest, not
`null` (same convention `CustomerIdResolver`'s own docblock already notes for `ContextInterface`).
For REST this is masked by `etc/webapi.xml`'s `resource="self"` ACL, which rejects an
unauthenticated request before the service method ever runs. GraphQL has no equivalent built-in
gate on an arbitrary `@resolver` field, so an unguarded guest request fell through to a confusing
`NoSuchEntityException("No such entity with customerId = 0")` instead of a clean authorization
error. Fixed in `Model\GraphQl\Resolver\CreditLimit` itself, which now checks
`CustomerIdResolver::resolveCustomerId()` for `null` first and throws
`GraphQlAuthorizationException` — the same explicit-guest-check convention
`CustomerGraphQl\Model\Resolver\Customer` uses, not a fix to the REST-safe service itself.

## Consequences

- A headless/PWA Studio storefront can now list/extend offers and read credit-limit status
  without a server-rendered page or REST client.
- `extendOrdoOffer` and `dismissOrdoNotification`/`submitOrdoSurveyResponse` (Phase 1) use
  different failure shapes on purpose: an ordinary business-rule failure (already at the
  extension cap, lost a race) returns `{ok: false, reason}`, but a missing/not-owned id is a hard
  GraphQL error (`graphql-no-such-entity`) — consistent with the privacy-conscious "same message
  for missing and not-owned" `OfferManagement::selfExtend()` already throws, not a new decision.
- Phase 3 (price-watch/push subscriptions) remains open in `ROADMAP.md`.
