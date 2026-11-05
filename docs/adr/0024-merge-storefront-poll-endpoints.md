# ADR 0024: Merge the three storefront poll endpoints into one

## Status

Accepted.

## Context

`view/frontend/web/js/tracker.js` ran three independent `startPolling()` loops — popup (default
15s), notification (default 20s), NPS survey (default 25s) — each hitting its own GET endpoint
(`Controller\Track\{Popup,Notification,Survey}`), on an open tab, forever. Each poll is a full
Magento bootstrap for a request that, most of the time, finds nothing new. Three separate timers
meant up to three separate bootstraps per interval window instead of one, even though every
storefront page loads all three loops together (`tracker.phtml` renders one `<script>` tag with
all three `data-*-enabled`/`data-*-poll-interval` pairs - the three loops were never independently
deployed or loaded, just independently timed).

## Decision

- The three GET controllers collapse into one: `Controller\Track\Poll`
  (`GET /ordo/track/poll?visitor_id=...`), returning all three concerns in one JSON body —
  `{"popup": {...}|null, "notifications": [...], "survey": {...}|null}`. A concern's key is
  omitted entirely when its own feature flag (`isPopupEnabled()`/`isNotificationEnabled()`/
  `isNpsSurveyEnabled()`) is off, mirroring each old controller's own early-return shape.
- Each concern's pre-merge semantics are preserved exactly, not just its JSON shape:
  popup/survey stay claim-once (`Model\Track\DeliveryClaimer`, `delivered_at`, the same
  5-candidate claim-race fallback), notifications stay un-claimed — every unread, unexpired row
  returned on every poll, same as before (dismissal is still the separate
  `Controller\Track\DismissNotification` action, untouched by this merge). Nothing about *how* a
  popup/notification/survey is resolved changed, only *where* the three resolutions are
  triggered from.
- `view/frontend/web/js/tracker.js`'s three `startPolling()` calls collapse into one. The
  three features' three independently-configurable poll intervals didn't disappear — each is
  still its own `Helper\Config` getter, still independently admin-configurable in
  Stores → Configuration — but since all three now ride the same single request, there's only one
  client-side timer left to configure. `Block\Frontend\TrackerViewModel::getPollIntervalSeconds()`
  computes it as the **minimum** of whichever concerns are actually enabled: no enabled feature
  ever polls less often than its own configured interval asked for, and polling an enabled-but-
  more-patient concern (or a disabled one, which costs nothing - its key is just omitted
  server-side) slightly more often than strictly necessary is free once it's riding one request
  anyway. `isPollEnabled()` is true the moment any one of the three is on; the client only polls
  at all when that's true.
- `data-popup-enabled`/`data-popup-poll-interval`/`data-notification-enabled`/
  `data-notification-poll-interval`/`data-nps-survey-enabled`/`data-nps-survey-poll-interval`
  (six attributes) collapse into `data-poll-enabled`/`data-poll-interval` (two) on the tracker
  `<script>` tag. `data-push-enabled`/`data-push-vapid-public-key` are untouched - Web Push
  subscription is a separate, explicit-user-gesture flow, not part of this poll loop at all.
- `Controller\Track\DismissNotification` and `Controller\Track\SubmitSurveyResponse` (the two
  POST, state-mutating, single-entity action endpoints the rendered widgets call on an explicit
  click) are unchanged and stay separate — the roadmap item and this ADR are scoped to the three
  GET *poll* endpoints only. They were never on a timer and have no reason to share a request with
  each other or with the merged poll.
- This repo has no public production install yet (low switching cost for a breaking change, see
  memory), so the old three endpoints are deleted outright rather than kept as deprecated aliases
  — `/ordo/track/popup`, `/ordo/track/notification`, `/ordo/track/survey` now 404. A custom
  frontend integration built directly against the old per-concern endpoints (`docs/wiki/
  API-Reference.md`'s documented use case) needs to switch to the merged one; that doc was updated
  in the same change.

## Consequences

- An open storefront tab now makes one `/ordo/track/poll` request per tick instead of up to three,
  at the fastest interval any enabled feature actually needs — fewer full Magento bootstraps for
  the same (or better) per-feature freshness.
- `FeedGeneratorInterface`-style "add a new poll concern without touching the orchestrator"
  extensibility was deliberately not attempted here: `Controller\Track\Poll` hardcodes its three
  concerns inline (three private `resolve*()` methods) rather than introducing a registry/pool the
  way `Model\ProductFeed\FeedGeneratorPool` does for feed formats. Three concerns, unlikely to grow
  often, and each has materially different claim semantics (claim-once vs. never-claimed) - a pool
  abstraction here would be speculative generality for a "small scope" roadmap item, not something
  this change actually needed.
- Existing tests touching the old 3-endpoint contract all moved: `Test/Unit/Controller/Track/
  {Popup,Notification,Survey}Test.php` became one `PollTest.php`; `Test/js/tracker.test.js`
  dropped its three-interval assertions for single-interval ones;
  `AdminCampaignPopupClaimedOnceTest.xml` now polls `ordo/track/poll` (its raw-body
  `"popup":null` substring assertion needed no change — that key's shape is unchanged, just no
  longer the whole response).
