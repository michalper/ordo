# ADR 0085: UTM/acquisition-source capture

## Status

Accepted.

## Context

A full audit found zero UTM/acquisition-source handling anywhere in this module - the
visitor-tracking infrastructure (`Controller\Track\Event` → `VisitorEventLogger` →
`VisitorAggregator`) could already capture a landing UTM param, it just never did. "Re-target
everyone who came from campaign X" is a standard marketing-automation ask with no way to express
it here.

Investigating the gap re-framed it: this isn't a *matching* gap, it's a *capture* gap. Once a
customer/visitor is tagged `utm_source_google`, the existing `tag`/`visitor_tag` condition types
(`Model\Campaign\Condition\HasTag`/`VisitorHasTag`) already express "came from Google" - they
accept any free-text tag string today. Building a dedicated `AcquisitionSourceEquals` condition
class would duplicate matching logic `HasTag` already has, for no real benefit.

## Decision

- **Reuse `Controller\Track\Event`, not a new endpoint.** `utm_source`/`utm_campaign` added to
  its existing `ALLOWED_EVENT_TYPES` whitelist - the endpoint already does everything this needs
  (CSRF handling via `VisitorIdentityResolver`, the `tracking > enabled` gate, logging through
  `VisitorEventLogger`), so there's nothing a dedicated controller would add.
- **Automatic, same tier as `page_view` - not a theme-integration-point.** `tracker.js`'s new
  `captureAcquisitionSource()` reads `utm_source`/`utm_campaign` straight off the current page's
  own `window.location.search` and calls the existing generic `track()` function - no theme
  template needs to call anything. This naturally only fires once per real ad click/landing,
  since Magento doesn't carry query params forward across internal navigation - there's nothing
  to debounce or de-duplicate beyond that.
- **Tag on the first occurrence, not a repeated-occurrence threshold.** Every other
  `VisitorAggregator`-fed event type (`product_view`, `element_clicked`, ...) requires crossing
  `getTrackingViewThreshold()`/`getTrackingClickThreshold()` (configurable, default 3/1) before
  tagging. A UTM param appears on exactly one URL per visit, so waiting for more than one
  occurrence would mean this would effectively never tag. New `IMMEDIATE_EVENT_TYPES` constant,
  hardcoded to threshold 1 - deliberately not reusing `getTrackingClickThreshold()` even though
  its own default happens to also be 1 today: that setting is tuned for "did they click
  something on the page," a different signal with a different reason to ever change.
- **No new condition type.** `tag`/`visitor_tag` already express "has this tag" against any
  string - a campaign/segment matches "came from Google" today by setting `utm_source_google` as
  the tag in a `HasTag`/`VisitorHasTag` condition, once this capture ships.
- **Tags built lowercase** (`utm_source_google`, not `utm_source_Google`) - ad platforms are
  inconsistent about UTM param casing, and the tag string is what a campaign/segment's own tag
  condition has to match exactly, where a predictable casing matters more than preserving
  whatever the raw click happened to carry.

## Consequences

- A store can now build "re-target everyone who came from campaign X" today, with zero new
  condition types to learn - just a `tag`/`visitor_tag` condition set to the right
  `utm_source_*`/`utm_campaign_*` string.
- Flows through the exact same anonymous-then-stitched-to-customer pipeline every other tracked
  event already uses (`StitchVisitorIdentity` re-attributes the raw event on login, then
  publishes the aggregation check) - a visitor who arrives via an ad, browses anonymously, then
  registers later still ends up with the right tag on their customer account.
- Scope is deliberately `utm_source`/`utm_campaign` only, not the full `utm_medium`/`utm_term`/
  `utm_content` set - these two are what the motivating "re-target by campaign/source" use case
  needs. Extending to the rest is additive (one more entry in `ALLOWED_EVENT_TYPES`,
  `IMMEDIATE_EVENT_TYPES`, and `tracker.js`'s own `UTM_PARAMS` map), not a redesign, if a real
  need for them shows up.
