# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for implementation history/verification
detail see [CHANGELOG.md](docs/CHANGELOG.md) and [VERIFICATION.md](VERIFICATION.md), for the REST API reference see
[API.md](API.md), and the `Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## Test coverage

- **Ad-audience sync (`Cron\SyncAdAudiences`) has no test against a real Google Ads/Meta account.** Unit
  tests drive the request-building/response-parsing logic via a fake `Curl`, and the integration test uses
  real DI/database but swaps `SyncClientInterface` for a `RecordingSyncClient` — the actual HTTP calls
  (OAuth token exchange, offline user data job lifecycle, Custom Audience creation/replace) have never been
  exercised against live credentials.
- **`send_whatsapp` / WhatsApp templates have no test against a real Meta WhatsApp Business Account.** Same
  shape: unit tests cover the Graph API request/response logic via a fake `Curl`, and the webhook signature
  check uses a real HMAC-SHA256 — but template submission, approval polling, and an actual template send
  have never been exercised against a live WABA/phone number.

### MFTF/scenario coverage

Full inventory with what's covered and why: `Test/Mftf/SCENARIOS.md`. Two open rows as of 2026-09-23, both from
brand-new features (not gaps in existing coverage): `AutoPickCampaignSplitWinner` (§28) and
`SendAbandonedCartFallbackReminders` (§11), both unit-tested only, no MFTF/Integration yet. Kept as the standing
scope check for anything newly added to the module (new trigger/condition/action/controller/cron gets a row
there before it's considered done).

## Documentation

- **GitHub Wiki** (`github.com/michalper/ordo/wiki`, source staged in `docs/wiki/`) — still open: native PL
  review of the drafted text.

## Candidate new features

Not gaps in something existing — genuinely new capabilities, proposed after checking they don't already
exist in some form. Not prioritized against each other; listed for later scoping.

- **Campaign template library** — a curated gallery of ready-to-use campaign starting points (e.g. welcome
  series, cart abandonment, win-back, VIP upsell), each a pre-filled trigger/condition/action graph an admin
  can pick and adapt instead of building from scratch. `Model\Campaign\CampaignImporter` already accepts the
  JSON shape a template would need; this is the curated library + picker UI on top of it, not new import
  plumbing. Medium scope, B2C/B2B.

## Priority of next steps

Order reflects severity (financial bugs > reliability debt > UX > topics dependent on external
resources):

1. **New features (the "Candidate new features" section above)** — to be prioritized together
   with the business/product side.
2. **Tests against live accounts (Google Ads/Meta/WhatsApp)** — dependent on availability of real
   test credentials.
