# ADR 0036: GTM dataLayer bridge

## Status

Accepted.

## Context

`tracker.js` already tracks a rich event taxonomy (`page_view`, `product_view`, `category_view`,
`checkout_start`, and whatever custom events a theme fires via `window.ordoTrack()`), but all of
it is one-way: it only ever reaches Ordo's own `/ordo/track/event` endpoint. A store that already
runs Google Tag Manager for its GA4/Google Ads/Meta Pixel tags has no way to see any of this -
building a GA4 audience or an ad platform's custom audience from an Ordo-specific signal (a
segmentation tag, a particular tracked event) currently means waiting on this module's own
ad-audience sync (`Cron\SyncAdAudiences`), which is itself gated on real-account access most
small stores won't have (see `ROADMAP.md`'s "Test coverage" section).

## Decision

- **Mirror existing `track()` calls onto `window.dataLayer`, gated by its own toggle
  (`tracking > gtm_bridge_enabled`, default off).** Every call to `track(eventType, eventKey)`
  also does `window.dataLayer.push({event: 'ordo_' + eventType, ordoEventKey: eventKey || null})`.
  This needs zero backend changes - it's a pure mirror of what the browser already knows at the
  moment it already calls `track()`.
- **This module never loads GTM itself.** `window.dataLayer = window.dataLayer || []` defends
  against GTM not being on the page yet (script order) or not being on the page at all (the
  pushes become inert, harmless no-ops), but this is a bridge *to* a container the store is
  assumed to already run, not a GTM installer. A store with no GTM container gets nothing extra
  and nothing broken.
- **Scoped to events the browser already tracks - segmentation tags and campaign-trigger events
  are explicitly out of scope for this pass.** Those are computed server-side
  (`SegmentMemberResolver`, `CampaignDispatcher`) well after the request that caused them, with no
  synchronous round-trip back to the browser that triggered them. Surfacing those to `dataLayer`
  too would need a new channel back to the browser (e.g. a new field on the existing
  `/ordo/track/poll` response) and is real additional scope, not a mirror - left for a later ADR
  if there's real demand, rather than bundled into this one's "zero backend changes" shape.
- **One naming scheme for every mirrored event** (`ordo_<type>`), not a per-event-type mapping to
  GA4/Ads' own event vocabulary. A store builds its own GTM triggers/variables off this prefix;
  Ordo doesn't guess what the destination platform's own event should be called.

## Consequences

- A store's own GTM container can build GA4 custom events, Ads remarketing audiences, or Meta
  Pixel custom events from Ordo's own event taxonomy, without this module's ad-audience sync.
- Off by default, same "every feature has its own config, no master switch" rule as the rest of
  this module - a store with no GTM container pays nothing for this (the toggle is off, so
  `pushToDataLayer()` returns immediately without even checking `window.dataLayer`).
- `ordo_page_view` fires on every page load same as the existing tracked `page_view` already does
  - a store that doesn't want that noise in its own GTM triggers simply doesn't build a trigger on
  it; nothing forces a destination tag to fire off every mirrored event.
- If real demand shows up for segmentation-tag/campaign-trigger visibility in `dataLayer` too,
  that's new scope for a follow-up ADR, not a retrofit of this one.
