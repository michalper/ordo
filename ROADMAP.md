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

## Admin UI/UX audit (2026-09-25, round 2)

Focused pass over the admin UI surface (grids, forms, CSS, confirmation dialogs), grounded in the
actual `ui_component`/`Controller/Adminhtml`/`view/adminhtml/web` code.

- **Grids with no export and no mass actions/filters**: `ordo_adminactionlog_listing.xml`,
  `ordo_cronrunlog_listing.xml`, `ordo_orderapproval_listing.xml`, `ordo_productfeedrunlog_listing.xml`.
  No listing in the module has a standard `exportButton` at all — CSV/XML export only exists as bespoke
  controllers for Campaign/Segment. Real gap for audit/compliance use cases (GDPR log review, admin action
  audit, order-approval history).
- **Remaining stylesheets without a media query**: `campaign-form.css`, `segment-form.css`,
  `whatsapp-template-form.css`, `free-gift-offer-form.css`. Lower priority than the ones already fixed —
  these already lean heavily on `flex-wrap: wrap` for their multi-item rows, so they degrade reasonably on
  narrow viewports without one; worth a real-browser check at tablet width before deciding whether they
  need their own breakpoint too. (`dashboard.css`, `campaign_calendar.css`, `campaign_schedule_calendar.css`
  got real breakpoints in the round-2 pass; `flow.css` already had one.)
- **No admin visibility into push-subscription / price-watch opt-in state**: `Controller/Track/*`
  (RegisterPushSubscription, RegisterPriceWatch) collects this data, but there's no
  `Controller/Adminhtml` grid to view or manage it — only inferable indirectly via logs/emails.
- **Flat top-level admin menu** (`etc/adminhtml/menu.xml`): a single "Ordo Automation" entry, all other
  screens reached via dashboard cards — no menu-driven path to e.g. Message Log or RFM. Note, not a
  confirmed bug: may be an intentional design choice; revisit only if it proves to be a real navigation
  pain point.

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
- **Multi-step drip sequences / delay-as-a-graph-step** — today a campaign is a single-shot
  trigger→conditions→actions run (`Model/CampaignDispatcher`); there's no native Flow canvas node for
  "wait N days, then continue" — a 5-email onboarding drip needs 5 separate campaigns chained manually.
  Large scope: new node type, scheduling, canvas UX.
- **Email template versioning/drafts** — `SendEmail` sends a configured template with no version history or
  draft/publish state; A/B testing exists at the campaign level (`SplitWinnerCalculator`) but not at the
  template-content level. Medium scope.
- **Referral/advocacy program** — `GenerateCoupon`/`AddPoints` already exist as building blocks, but there's
  no dedicated "invite a friend" flow (referral code, tracking, reward-on-referral). Medium scope, B2C.
- **Review-request campaigns** — no trigger/action tied to Magento's native `module-review` for post-purchase
  review requests, despite the module already having the delayed post-purchase pattern (win-back, reorder
  reminders) to build on. Small-medium scope.
- **Customer 360 / CDP view** — segments, tags, RFM/CLV, lead score, NPS, loyalty tier already exist as
  separate condition inputs, but there's no single admin screen aggregating them per customer; today it's
  queryable per-condition, not visualized per-customer. Medium-large scope.
- **Campaign revenue/LTV dashboard** — `CampaignFunnelStats`/`AttributionCalculator` already compute
  attribution data, but there's no dedicated reporting view for revenue-per-campaign or cohort/LTV analysis
  built on top of it. Medium scope.
- **Granular preference center** — channel opt-out today looks to be binary per channel (SMS/WhatsApp), not
  a full per-topic/per-channel preference center. Needs confirming current opt-out granularity before
  scoping. Medium scope.
- **Inbound webhooks** — `send_webhook` (action) only sends outbound; there's no trigger that receives
  external events (e.g. from Zapier/Make) into the campaign engine. Medium scope, needs auth/security design.

## Priority of next steps

Order reflects severity (financial bugs > reliability debt > UX > topics dependent on external
resources):

1. **New features (the "Candidate new features" section above)** — to be prioritized together
   with the business/product side.
2. **Tests against live accounts (Google Ads/Meta/WhatsApp)** — dependent on availability of real
   test credentials.
