# ADR 0076: On-site search ("no results") campaign trigger

## Status

Accepted.

## Context

`ROADMAP.md`'s 2026-11-22 brainstorm found a real gap, grep-verified against the actual code
rather than trusted from prose: no `Controller/Track/*` endpoint, event, or `TRIGGER_*` constant
anywhere reacts to on-site search at all. A store running Ordo has no way to react to "a customer
searched for something and found nothing" - a classic, high-intent signal every established
marketing-automation platform's feature set treats as table stakes (frequently paired with "notify
me" or "we don't carry this, here's something close" follow-ups). This round's own research also
checked for a more reliable, JS-independent signal: Magento core's `search_query` table does carry
a `num_results` column, but it's a per-query-text/per-store aggregate with no `visitor_id`/
`customer_id` column at all, and that column is only (re)computed as a side effect of the active
theme's own `.phtml` template calling `Block\Result::getResultCount()` during rendering - not a
reliable, customer-attributable signal a server-side observer could hook instead of the frontend.

## Decision

- **A new trigger type, `TRIGGER_SEARCH_NO_RESULTS`, wired exactly like `TRIGGER_EXIT_INTENT`**
  (`Controller\Track\Event` fires a Magento event, `ordo_visitor_search_no_results`, observed by a
  new `Observer\DispatchSearchNoResultsCampaigns` that publishes to the queue) - same two-hop shape,
  for the same reason: there's no Magento-core event for "the tracking endpoint was called," and
  the controller is the only place that ever sees this signal. `window.ordoTrack('search_no_results',
  queryText)` is a theme-integration point, same convention `product_view`/`category_view`/
  `checkout_start` already established in `tracker.js` - this module has no automatic hook into
  Magento's own search-results page template, for the `num_results`-reliability reason in Context
  above, so the theme reports it only when it rendered zero results.
- **Unlike `TRIGGER_EXIT_INTENT`'s deliberately anonymous-only context, `customer_id` rides along
  whenever the originating request is authenticated** - a search is just as meaningful a signal for
  a logged-in customer as an anonymous visitor, with no reason to throw that identity away the way
  exit-intent's own ADR 0037 deliberately does for a different signal. `visitor_id` is always
  present; `customer_id` is present only when resolved (same `VisitorIdentityResolver::
  resolveCustomerId()` call this same controller already makes for every other event type),
  omitted from context entirely rather than carried as an explicit `null` - `CampaignDispatcher::
  customerIdFromContext()`'s `$context['customer_id'] ?? null` already treats a missing key and an
  explicit `null` identically, so this is a style choice, not a compatibility requirement.
- **No schema change.** `event_key` on `ordo_visitor_event` is already a generic, type-reused
  varchar(255) slot (SKU for `product_view`, category id for `category_view`) - the search query
  text fits it directly, truncated to 255 chars by the same `Controller\Track\Event` logic every
  other event type already goes through. "Zero results" is encoded as its own `event_type` value
  (`search_no_results`) rather than as a stored numeric field, since there is no generic payload
  column to put one in, and none is needed: `event_occurred`'s resolver only ever does an exact
  `(event_type, event_key)` match, never a numeric comparison.
- **Immediately usable as a segment/campaign condition with zero new condition code** - added to
  `Model\Config\Source\EventType`'s admin dropdown (the `event_occurred` condition's own event-type
  picker) alongside `cart_add`/`wishlist_add`, since `Controller\Track\Event` writes this event_type
  into `ordo_visitor_event` the same way as every other tracked type, live triggers included.

## Consequences

- A store can build an on-site-search-driven campaign (e.g. "sorry, nothing matched - here's our
  bestsellers" email/popup) using the existing Flow canvas, condition set, and action types
  unchanged - no new admin UI, action type, or database table.
- Theme-integration-only, same limitation `product_view`/`category_view`/`checkout_start` already
  have: nothing fires automatically without the theme calling `window.ordoTrack('search_no_results',
  queryText)` on its search-results template. Documented in `tracker.js`'s own top-of-file
  docblock, same place those three are documented.
- No MFTF scenario needed - same reasoning `SCENARIOS.md` already gives for `exit_intent`/
  `visitor_tag_added`: nothing new is browser-observable beyond what the existing admin campaign
  scenario tests already cover (building a trigger/condition/action chain in the Flow canvas), and
  `Test\Integration\DispatchSearchNoResultsCampaignsQueueWiringTest` proves the real event → publish
  → queue → consumer → dispatcher chain end to end, including the customer_id-carrying case
  `exit_intent`'s own equivalent test doesn't need to cover.
