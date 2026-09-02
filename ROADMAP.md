# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for the REST API reference see
[API.md](API.md), for implementation history/verification detail see [VERIFICATION.md](VERIFICATION.md) and the
`Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## In progress

- **MFTF CI pipeline (`.github/workflows/mftf.yml`)** — being debugged against a real, fresh GitHub Actions runner.
  Real infra fixes landed this session (nginx + PHP-FPM replacing `php -S`, selenium-container-to-host reachability,
  ACL/PHP-FPM worker/cache-warming dead ends ruled out with real evidence) but the root cause of the
  redirect-to-dashboard failure across all 8 existing tests is still unconfirmed. `AdminApproveOrderViaEmailTest.xml`
  (order-approval round trip via a real MailHog-caught email) is written and wired in, not yet confirmed green.

## Admin UI

- **Typography** — still stock Magento admin typography; no brand-specific type scale defined.

## Test coverage

- **MFTF: the tracking snippet posting an event.** `StorefrontTrackerSetsVisitorCookieTest` covers the cookie; nothing
  covers `window.ordoTrack()` actually posting to `/ordo/track/event`, since no theme in this environment calls it —
  would need either a real PDP/PLP hook or a scripted `executeJS` call plus a DB-assertion custom action.
- **A confirmed green run of `AdminCampaignScenarioEndToEndTest.xml`** — was blocked on local-sandbox host memory
  contention; now blocked on the CI pipeline issue above instead.
- **Load/soak test** for Phase 7's dispatch performance work — the architectural bottlenecks (N+1, sync blocking,
  unbounded cron) are fixed, but no test has put a concurrent-throughput number on it.

## Tooling ideas, not yet actioned

- **Split the MFTF pipeline into a matrix by functional area** (Campaign, Segment, Dashboard/ReorderCycle, Tracking)
  using MFTF's own `<group>` tags. Deliberately deferred until the single pipeline has had one confirmed green run.
- **Psalm and/or Infection (mutation testing)** — undecided. Psalm overlaps heavily with PHPStan; Infection would test
  whether the suite actually fails when the code is mutated, a different signal than line coverage.
- **SonarQube Cloud's Hunter/Remediation agents** — not yet investigated whether they need a paid tier or additional
  GitHub App permissions beyond what's configured.

## Gaps vs. a full-market MA platform

Not a code review — a capability comparison against the category. Each is a real, separate stream of work:

- **Product recommendations** — no "recommended for you" blocks anywhere; every email is static content.
- **Lead scoring** — the points model (accumulate/read/gate-on-threshold) exists. Missing: demographic-attribute
  scoring rules, an admin UI for managing scoring rules declaratively, and a trigger that fires the instant a
  threshold is crossed rather than opportunistically on whatever trigger already ran.
- **Popup targeting** — frequency capping exists. Missing: event-driven triggers finer than a tag threshold (e.g. "this
  element was clicked").
- **Dynamic content blocks** — reusable snippets, RSS newsletters, product feeds inside a campaign email. Not built.
- **Segments** — bulk actions on a segment's current members (today a segment is only ever read one customer at a
  time), a standalone RFM report across the whole customer base, and percentile/quintile-based RFM scoring (today's
  conditions are absolute thresholds only).
- **Multichannel recovery** — SMS/WhatsApp/push. `cart_abandoned`/win-back only ever send email.
