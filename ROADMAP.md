# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for the REST API reference see
[API.md](API.md), for implementation history/verification detail see [VERIFICATION.md](VERIFICATION.md) and the
`Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## In progress

- **MFTF CI pipeline (`.github/workflows/mftf.yml`)** — 8 of 9 tests confirmed green against a real, fresh GitHub
  Actions runner. The redirect-to-dashboard root cause from earlier sessions is resolved (three real, previously
  undocumented bugs — see AGENTS.md's "MFTF w prawdziwym CI" section: `admin/security/use_form_key` on by default,
  two `ui_component` XSD violations 500ing instead of failing visibly, and PHP's ini parser truncating
  `sendmail_path` at an embedded `=`). `AdminApproveOrderViaEmailTest.xml` is now confirmed green. Only
  `AdminCampaignScenarioEndToEndTest.xml` still fails — not a form/save bug (a direct `ordo_campaign*` table dump
  confirmed the campaign, trigger, condition, and action all persist exactly as configured) but the
  `ordo.automation.campaign.dispatch` queue message never reaches status 3 (complete) in `queue_message_status`,
  with no matching exception anywhere — actively being diagnosed with `-vvv` on the consumer command to surface
  whatever's being swallowed.

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
- ~~Psalm and/or Infection (mutation testing)~~ — decided: skip Psalm (overlaps heavily with PHPStan `level: max` +
  `bitexpert/phpstan-magento`, not worth a second baseline to maintain for marginal extra signal). Infection added
  (`infection.json5`, `mutation-testing` job in `coverage.yml`) — non-blocking (`continue-on-error`, `minMsi`/
  `minCoveredMsi` at 0) until a real first Mutation Score Indicator has actually been reported; raise the thresholds
  and drop `continue-on-error` once that number exists.
- ~~SonarQube Cloud's Hunter/Remediation agents~~ — decided: skip. Both require a paid tier (Hunter needs Enterprise;
  Remediation needs the "Sonar Agent Essentials" add-on on Team or Enterprise) — this org is on SonarCloud's Free
  plan, and Remediation additionally appears to run on top of GitHub Copilot's coding agent infrastructure
  (`COPILOT_MCP_SONARQUBE_ORG`/`COPILOT_MCP_SONARQUBE_PROJECT_KEY` variables), a second dependency beyond SonarCloud
  itself. Revisit only if the org upgrades off Free.
- **PHPUnit 13** (currently on `^12.0`) — blocked, not just a version bump: PHPUnit 13 requires PHP 8.4+, but every
  workflow (`ci.yml`, `coverage.yml`, `mftf.yml`) runs PHP 8.3, and composer.json's own supported range starts at
  `~8.2.0`. Needs a deliberate decision to move the whole CI matrix (and the module's minimum supported PHP version)
  to 8.4 first — not something to fold into an unrelated change. PHPUnit 13 also requires the suite to already run
  clean with zero deprecation warnings under PHPUnit 12.5 first, which `ci.yml`'s `--fail-on-*-deprecation` flags
  already enforce today, so that half of the prerequisite is already satisfied.

## Gaps vs. a full-market MA platform

Not a code review — a capability comparison against the category. Each is a real, separate stream of work:

- **Product recommendations** — no "recommended for you" blocks anywhere; every email is static content.
- **Lead scoring** — the points model (accumulate/read/gate-on-threshold) exists. Missing: demographic-attribute
  scoring rules, an admin UI for managing scoring rules declaratively, and a trigger that fires the instant a
  threshold is crossed rather than opportunistically on whatever trigger already ran.
- **Popup targeting** — frequency capping exists. Missing: event-driven triggers finer than a tag threshold (e.g. "this
  element was clicked").
- **Dynamic content blocks** — reusable snippets, RSS newsletters, product feeds inside a campaign email. Not built.
- **Segments** — bulk actions on a segment's current members now ship (add tag / add points, resolved via
  `SegmentMemberResolver` and applied async), as do a standalone RFM report across the whole customer base
  (`ordo/rfm/index`, a SQL-paged grid with per-metric quintiles) and percentile-based RFM conditions
  (`recency_percentile_at_least` / `order_frequency_percentile_at_least` / `monetary_percentile_at_least`, alongside the
  original absolute thresholds). Still open: quintile-based conditions expressed as R/F/M *scores* (e.g. "555"), and
  scheduled recomputation so a large customer base doesn't rank on every dispatch.
- **Multichannel recovery** — SMS/WhatsApp/push. `cart_abandoned`/win-back only ever send email.
