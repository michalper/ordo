# ADR 0037: Exit-intent campaign trigger

## Status

Accepted.

## Context

Every live, browser-detected signal this module reacts to today goes through one of two
pipelines: tag-crossing (`page_view`/`product_view`/`category_view`/`element_clicked` accumulate
in `ordo_visitor_event`, and `VisitorAggregator` turns N occurrences of the same `event_key` into
a tag once a threshold is crossed) or a scheduled cron scan (`browse_abandoned`, `price_drop`,
`back_in_stock`, ...). Neither fits "the visitor's mouse just left toward the top of the
viewport" - it's a one-shot, keyless signal with no natural SKU/category to key a tag on, and it
needs to fire the moment it happens, not on the next cron tick.

## Decision

- **A brand new trigger type, `TRIGGER_EXIT_INTENT`, not a repurposed tag/cron.** Forcing this
  through `VisitorAggregator`'s threshold path would mean inventing a synthetic `event_key` for a
  signal that has none, and sharing `getTrackingViewThreshold()`/`getTrackingClickThreshold()`
  would couple an unrelated feature's behavior to this one's. A dedicated trigger keeps the fit
  honest.
- **Controller\Track\Event fires a Magento event (`ordo_visitor_exit_intent`), observed by a new
  `Observer\DispatchExitIntentCampaigns` that publishes to the queue** - same two-hop shape
  `VisitorTagManager`/`CustomerTagManager` use for `ordo_visitor_tag_added`/`ordo_customer_tag_added`,
  but for a different reason: those two have an actual DI-cycle risk (`HasTag`/`VisitorHasTag`
  conditions depend on the tag managers, so the tag managers can't depend back on the dispatcher).
  There's no such cycle here - the controller fires an event anyway, purely for consistency with
  the rest of the module's "triggers publish, don't call" discipline (`CLAUDE.md`), and because
  there's no Magento-core event for "the tracking endpoint was called" to hook a separate observer
  into instead; the controller is the only place that ever sees this signal.
- **Context carries only `visitor_id`, never `customer_id`, matching `TRIGGER_VISITOR_TAG_ADDED`'s
  own shape exactly - not "customer_id when logged in, absent otherwise".** A single, predictable
  shape means every existing condition that expects `customer_id` in context (e.g. `EventOccurred`'s
  `is_numeric($context['customer_id'] ?? null)` guard) already degrades safely to "doesn't match"
  rather than needing new null-handling, the same way it already does for `visitor_tag_added`.
  Conditions that need to recognize a logged-in visitor use `VisitorHasTag` against tags already
  stitched onto that `visitor_id`, not a `customer_id` this trigger doesn't carry.
- **`tracker.js` fires on `mouseout` with `event.relatedTarget === null && event.clientY <= 0`** -
  the standard, widely-used exit-intent heuristic (mouse left the document entirely, via the top
  edge, which is where the tab bar/address bar/bookmarks live - the gesture that precedes closing
  a tab or clicking away). Exiting sideways or downward isn't treated as exit intent; those happen
  constantly during ordinary scrolling/reading and would make this fire on everyday interaction
  instead of the one gesture it's meant to catch.
- **Fires at most once per page load, with no further cross-page dedup.** A session that visits
  several pages could in principle fire this once per page - deliberately not suppressed further,
  because `Model\Campaign\Action\ShowPopup`'s existing `popup_frequency_cap_hours` already caps how
  often a popup actually reaches the screen regardless of trigger source. Building a second,
  trigger-specific dedup layer on top would be solving an already-solved problem.
- **Its own config toggle (`tracking > exit_intent_enabled`, default off) gates whether the
  listener is even attached**, not just whether a campaign reacts to it - same reasoning
  `isPollEnabled()` already applies to the poll loop: a store with no exit-intent campaign
  shouldn't pay for an always-on `mouseout` listener either.

## Consequences

- A store can build a "last-chance" popup/discount campaign off genuine exit intent, using the
  existing Flow canvas, condition set, and `Show Popup` action unchanged - no new action type or
  admin UI needed.
- Anonymous-only by design, same limitation `visitor_tag_added` already has and for the same
  reason - a store wanting to target logged-in customers differently layers a `VisitorHasTag`
  condition on top, same as any other visitor-side trigger.
- `mouseout`-based exit-intent detection is a well-known heuristic with known false
  negatives/positives (it can't detect a keyboard-driven tab close, for instance) - accepted as
  the right-sized trade-off for a dependency-free `<script>` snippet; nothing here claims it's
  perfectly accurate.
