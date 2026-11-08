# ADR 0032: GraphQL storefront API, starting with core tracking (Phase 1)

## Status

Accepted.

## Context

Ordo had zero GraphQL coverage. Every customer-facing storefront touchpoint
(`Controller\Track\*`'s `tracker.js`-polled/posted endpoints, `Controller\Offer\*`) is a plain
frontend controller, and `etc/webapi.xml`'s REST routes are mostly admin/integration CRUD, not
storefront-facing. A headless/PWA Studio storefront has no server-rendered page for `tracker.js`
to load from and no cookie-based session the way a Luma tab has, so it can't reach either shape
the way it reaches core Magento's own GraphQL schema. See `ROADMAP.md`'s "GraphQL storefront API"
section for the full phased plan this closes the first slice of.

## Decision

- **Scope phased, not all-at-once.** `ROADMAP.md` lists three phases (core tracking, My Offers,
  price-watch/push). This ADR covers Phase 1 only — `ordoTrackerPoll`, `trackOrdoEvent`,
  `dismissOrdoNotification`, `submitOrdoSurveyResponse` — the biggest PWA blocker, since without
  it no on-site behavior is tracked at all on a headless storefront.
- **Resolvers thin-wrap the existing controller logic, not a rewrite.** Each
  `Model\GraphQl\Resolver\*` class reuses the exact same collection factories/claimer/logger the
  matching `Controller\Track\*` already calls — the business logic (claim-first popups/surveys,
  event-type allowlist, ownership checks via `belongsTo()`) lives in one place either way; the
  resolver is just a second transport onto it.
- **Identity comes from `ContextInterface::getUserId()`, not `Model\Track\VisitorIdentityResolver`.**
  That existing helper reads `Magento\Customer\Model\Session`, which assumes a cookie-based
  session a decoupled/PWA frontend doesn't carry. A new, small
  `Model\GraphQl\CustomerIdResolver` reads the GraphQL customer-token context instead
  (`getUserId()`, 0/null for a guest) — the correct identity source for this transport, not a
  style preference. `visitorId` stays an explicit argument either way, same as
  `Controller\Track\Poll`'s own `resolveVisitorIdFromParam()` fallback already uses (a visitor
  cookie can't be read cross-origin from a decoupled frontend either).
- **New composer/module dependency**: `magento/module-graph-ql` added to `composer.json`, and
  `Magento_GraphQl` added to `etc/module.xml`'s `<sequence>` — the same explicit-dependency
  convention every other platform module this module touches already follows.

## A real, non-obvious Magento trap found verifying this against a live instance

A literal `{`/`}` character inside an `@doc(description: "...")` string in `schema.graphqls`
breaks Magento's own schema reader with a cascading `GraphQL\Error\SyntaxError: Unterminated
string` — not from the real GraphQL-PHP lexer parsing the string itself (which handles braces
inside strings fine per the GraphQL spec), but from a brace-balance pass Magento's own schema
stitching does *ahead of* that parser, which doesn't know braces can appear inside a string
literal and mis-splits the file at the wrong boundary. Confirmed directly: an isolated minimal
schema with the same description text reproduced the exact same syntax error at the exact same
reported position; removing only the literal braces (rewording "merged {popup, notifications,
survey} shape" to "merged popup/notifications/survey shape") fixed it with no other change. Not
documented anywhere in Magento's own `schema.graphqls` authoring guides found while debugging
this — worth remembering for every future `@doc` description in this module's own schema files.

## Consequences

- A headless/PWA Studio storefront can now track on-site behavior and read/act on
  popups/notifications/surveys without any server-rendered Magento page.
- `Test/GraphQl/` is a new, portable e2e suite (same shape as `Test/Api/`, reusing its customer
  token endpoint) — `Test/Api/README.md`'s own "why not `dev/tests/api-functional`" reasoning
  applies identically here.
- Phases 2 (My Offers) and 3 (price-watch/push subscriptions) remain open in `ROADMAP.md`, same
  thin-wrapper approach.
