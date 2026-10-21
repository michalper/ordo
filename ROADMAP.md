# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for implementation history/verification
detail see [CHANGELOG.md](docs/CHANGELOG.md) and [VERIFICATION.md](VERIFICATION.md), for the REST API reference see
[API.md](API.md), and the `Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## Test coverage

- Ad-audience sync (`Cron\SyncAdAudiences`) has no test against a real Google Ads/Meta account — request/response
  logic is unit-tested via a fake `Curl`, but OAuth token exchange and Custom Audience creation/replace have never
  hit the real API.
- `send_whatsapp`/WhatsApp templates have no test against a real Meta WhatsApp Business Account — same shape,
  template submission/approval polling/send have never hit a live WABA.

### MFTF/scenario coverage

Full inventory: `Test/Mftf/SCENARIOS.md`. Open rows: `AutoPickCampaignSplitWinner` (§28) and
`SendAbandonedCartFallbackReminders` (§11), unit-tested only, no MFTF/Integration yet.

## Admin UX

- Add `exportButton` to `ordo_adminactionlog_listing.xml`, `ordo_cronrunlog_listing.xml`,
  `ordo_orderapproval_listing.xml`, `ordo_productfeedrunlog_listing.xml`.
- Add responsive breakpoints to `campaign-form.css`, `segment-form.css`, `whatsapp-template-form.css`,
  `free-gift-offer-form.css` — check at tablet width first, they already lean on `flex-wrap` throughout.

## Documentation

- GitHub Wiki (`github.com/michalper/ordo/wiki`, source staged in `docs/wiki/`) — needs native PL review.

## Adobe Commerce Marketplace listing

Goal: get Ordo listed on `commercemarketplace.adobe.com`. Gap list against Adobe's Extension Quality
Program (technical review guidelines + submission requirements), checked 2026-10-21 — worked through in
order; all 8 items below are done as of 2026-10-22. What's left is the actual submission click-through
(create the listing from `LISTING-CONTENT.md`, upload the zip `build-submission-zip.sh` produces from a
tagged release, submit both the Technical and Marketing review tracks) — a one-time action for whoever
actually publishes, not something to pre-do here.

1. ~~**Licensing decision**~~ — done: switched MIT → OSL-3.0 (`LICENSE`, `composer.json`,
   `README.md`/`README.pl.md`) with an Adobe, Inc. credit per Adobe's requirement. No installed
   user base yet (module never publicly released — see memory), so no switching-cost concern;
   left for last anyway since it was originally treated as a business/legal call.
2. ~~**PDF user/setup guide**~~ — done: `docs/marketplace/USER-GUIDE.md` (source) and
   `generate-pdf.py` (renders it) produce `Ordo-User-Guide.pdf` — install/config/feature overview,
   linking out to each `docs/wiki/` page per feature, per Adobe's "PDF, or PDF linking to a wiki"
   rule.
3. ~~**Release notes formatted for the listing**~~ — done: `docs/marketplace/RELEASE-NOTES.md`, plain
   text and merchant-facing (unlike `docs/CHANGELOG.md`'s internal PR-by-PR log) — add a new entry
   above the current one for every future version submitted to the Marketplace.
4. ~~**Marketplace Code Sniffer pass**~~ — done: zero severity-10 violations repo-wide (the 16
   found — all in `Test/` — were fixed, not excluded), and `composer phpcs-marketplace-check`
   now runs the exact EQP command as a CI step so it can't regress.
5. ~~**MFTF run under Marketplace's actual test conditions**~~ — done: `magento-ordo-test/` got a
   Varnish service (`docker/varnish/default.vcl`, Magento's own `varnish:vcl:generate` output) and
   `deploy:mode:set production`; confirmed Varnish actually caches (`X-Magento-Cache-Debug: MISS`
   → `HIT` → `HIT`, before and after the mode switch). Getting there required a clean
   `setup:di:compile`, which caught two real bugs: the audit-plugin virtualTypes (item 4's
   cleanup) don't resolve for Magento's plugin-list compiler the way the button virtualTypes do
   (fixed — real subclasses again, see `docs/adr/0009`'s Consequences section), and
   `Controller\Adminhtml\Customer360\Index` type-hinted the wrong `Context` class (a pre-existing
   bug since #224, harmless in default mode, hard compile error in production — fixed). All three
   of `Test/Mftf/SCENARIOS.md`'s key scenarios (`AdminCreateMultiTriggerCampaignTest`,
   `AdminCreateCampaignWithConditionsAndActionsTest`, and the full-checkout
   `AdminCampaignScenarioEndToEndTest` — previously never finished in this sandbox due to host
   memory pressure, see `AGENTS.md`) pass under Varnish + production mode. Only ever run on one
   PHP/Magento combo (whatever this sandbox has installed) — running the matrix across every
   version the listing claims to support is for whoever actually submits.
6. ~~**Package the zip**~~ — done: `docs/marketplace/build-submission-zip.sh` builds it (`git archive`
   of a tagged commit, minus `.github/` and `docs/marketplace/` itself — neither is something a
   merchant's store needs or that belongs inside its own submission paperwork). 7.57 MB, well under
   the 30 MB limit. One real gap it caught: `composer.json` deliberately carries no `version` key in
   this repo (versioned by git tag only), but Adobe's technical review statically checks the
   submitted `composer.json` for `name`/`type`/*`version`* with no VCS to derive one from — the
   script stamps a version (from the exact git tag HEAD must be on) into the packaged copy only,
   never into the tracked file. `composer validate` confirmed the packaged `composer.json` is valid.
7. ~~**Marketplace seller account**~~ — done: Developer Portal account, Master/Developer Terms accepted,
   profile photo and Privacy Policy URL set (`docs/wiki/Privacy-Policy.md`, live on the GitHub wiki), PayPal
   payout email set, Tax Forms completed (US/Poland tax treaty — moot either way since Ordo ships free).
   EU Trader Information: answered "Yes" (maintainer chose to make listings available to EU users as a
   sole trader, their own personal name/address as the trader info — publicly shown on EU-viewed listing
   pages per the EU Digital Services Act, a tradeoff the maintainer was walked through and accepted
   explicitly, not a default). Pricing model: free (confirmed by maintainer, decided at actual listing
   creation time, not an account-level setting).
8. ~~**Listing content**~~ — done: `docs/marketplace/LISTING-CONTENT.md` has the title, category, short
   and long description (2,259 of the 25,989-character limit), version compatibility, and video decision
   (none yet, optional) ready to paste into the submission form. `docs/marketplace/assets/icon.svg` /
   `icon-512.png` — the same mark/gradient as the README hero and admin menu icon, no slogan or
   Adobe/Magento branding, per Adobe's icon content rule. `docs/marketplace/screenshots/` has the 2
   required gallery images, captured live from `magento-ordo-test/`'s Flow canvas (not saved as a real
   campaign) rather than mocked up.

## Candidate new features

Not prioritized against each other; listed for later scoping with the business/product side.

- **Campaign template library** — curated gallery of ready-to-use campaign starting points (welcome series,
  cart abandonment, win-back, VIP upsell), picked and adapted instead of built from scratch.
  `Model\Campaign\CampaignImporter` already accepts the needed JSON shape. Medium scope, B2C/B2B.
- **Email template drafts** — version history is done (`Model\EmailTemplateVersion`, snapshot-on-save +
  restore). What's left: editing a template's content as a separate draft without touching the live
  version `send_email` already reads, then publishing it when ready. Medium scope.
- **Cross-campaign revenue/LTV comparison view** — ranks/compares campaigns against each other, or does
  cohort/LTV analysis over time, on top of the existing per-campaign funnel view. Medium scope.
- **Per-topic preference granularity within a channel** — split a channel's opt-in further (e.g. "promotional"
  vs "product update" email) beyond today's per-channel consent. Confirm real merchant demand first. Medium
  scope.
- **Merge the three storefront poll endpoints** (`/ordo/track/{popup,notification,survey}`) into one —
  each open tab currently makes three separate requests on independent 15/20/25s timers, each a full
  Magento bootstrap. The JS side already shares one `startPolling()` helper (and pauses on hidden
  tabs); merging the endpoints is a frontend/backend contract change deferred from the 2026-10
  simplification pass. Small scope.
- **Single catalog walk for all product feeds** — `Cron\RefreshProductFeed` currently walks the whole
  catalog once per generator per store (3x); feeding all registered generators from one paged walk
  needs the `FeedGeneratorInterface::generate()` contract reshaped (deferred from the 2026-10
  simplification pass for that reason). Small/medium scope.
