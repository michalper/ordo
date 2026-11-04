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

## GitHub issue #261 — PHP 8.3 / Hyvä / multi-store / performance

A real pre-adoption inquiry (`github.com/michalper/ordo/issues/261`, Wayfarer.cz) asking before
trying Ordo: production references, PHP 8.3 support, Hyvä frontend compatibility, multi-store
support, catalog-scale performance, and long-term maintenance. Working through points 2–5 with
actual code, not just a reply. Full gap analysis (3 parallel codebase scans) found the real work
is concentrated in multi-store; PHP 8.3 and Hyvä were mostly already fine or trivial.

1. ~~**PHP 8.3 floor**~~ — done (`fix(ci): pin rector to 2.8.3, lower PHP floor to 8.3`, commit
   `d56c026`): `composer.json` dropped to `>=8.3 <8.6`, the only 3 call sites using PHP-8.4-only
   `array_find_key`/`array_all`/`array_any` rewritten to plain `foreach`, `rector.php`'s
   `withPhpSets` switched to `php83`. Docs (`CLAUDE.md`, README badges, `USER-GUIDE.md`) updated.
2. ~~**Multi-currency revenue sum**~~ — done (`fix(multi-currency): sum base_grand_total, not
   grand_total`, commit `bae15b5`): `CampaignOutcomeLogger::getStats()` and
   `RfmCalculator::getMonetaryTotal()`/`getAggregatesForAllCustomers()` now sum
   `base_grand_total`, same reasoning as `CreditLimitCalculator`'s existing `base_total_due` use.
3. ~~**Hyvä**~~ — done: Ordo was already Hyvä-compatible by design (every storefront JS/controller
   is vanilla `fetch`/DOM, no jQuery/Knockout/`x-magento-init`/RequireJS anywhere under
   `view/frontend/`). Added Tailwind utility classes alongside the existing Luma ones in
   `offer/index.phtml` (inert on Luma, styles the same markup on Hyvä) and a `## Compatibility`
   section in `README.md`/`README.pl.md` plus `docs/marketplace/USER-GUIDE.md`'s Requirements,
   including the caveat that a Hyvä theme purging unused Tailwind classes needs this module's
   `view/frontend` added to its `tailwind.config.js` `content` array.
4. ~~**Multi-store store-scoped sends**~~ — done (`docs/adr/0013-store-scoped-customer-facing-sends.md`):
   `SendEmail`/`SendSms` and the `SendReorderReminders`/`SendWinBackEmails`/`SendOfferExpiryReminders`/
   `SendCreditLimitAlerts` reminder crons now resolve the recipient customer's own store (via new
   `Model\CustomerStoreResolver`, needed because `CustomerInterface::getStoreId()` has no return
   type and can come back as a numeric string - confirmed via a real integration-test `TypeError`)
   instead of Magento's ambient "current store" or an un-scoped config read.
   `SendAbandonedCartReminders` uses the abandoned quote's own store id directly.
   `Observer\HoldOrderForApproval::isOrderApprovalEnabled()` now also receives the order's own
   store id. `Cron\SendSalesRepDigest` is deliberately left as-is (see the ADR: it aggregates
   across potentially many different customers' stores into one per-rep digest, so there's no
   single customer store to resolve for it). Two integration test fixtures
   (`CampaignSendSmsActionTest`, `SendAbandonedCartFallbackRemindersTest`) needed a matching fix:
   they set `ordo_channels/sms/enabled` via `MutableScopeConfig::setValue(..., SCOPE_STORE)` with
   no explicit scope code, which only ever worked by coincidence against the previous, un-scoped
   production read.
5. ~~**Campaign/segment/score-rule website scoping**~~ — done, see
   `docs/adr/0014-website-scoping-link-tables.md`. `ordo_campaign`/`ordo_segment`/
   `ordo_score_rule` each got their own linking table (`*_website`), an admin multiselect
   (`Model\Config\Source\Website`, shared across all three), and a filter at the point each
   entity actually matches a customer — `CampaignDispatcher::filterByWebsite()`,
   `SegmentMemberResolver::filterByWebsite()`, `ScoreRuleEvaluator`'s own guard. Empty selection
   = unscoped (matches every website), the backward-compatible default for every entity that
   predates these tables — a deliberate departure from Magento core's own `salesrule_website`/
   EntityManager pattern, explained in the ADR.
6. **Performance — no code needed, just say so.** Every catalog-touching cron/controller already
   batches correctly (`CatalogFeedProductFetcher::fetchByPage()` at 500, `AbstractPriceWatchScanCron`'s
   `LIMIT` + rotating cursor, SKU autocomplete's `setPageSize(20)`). Worth stating in the issue
   reply, not worth any speculative code change.
7. **Reply to #261 itself** — nothing posted yet. Once 3–6 above are far enough along, draft a
   reply covering all six of the asker's original questions (including #1, production references,
   and #6, roadmap/maintenance — both honest "not yet" answers, not silence) for the maintainer to
   review before posting.

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
   A second real gap surfaced only by an actual failed submission (not documented anywhere in
   Adobe's public guidelines): the technical review rejects a package containing `.gitignore` or
   `.gitattributes` ("Deprecated File found"), so the script excludes both alongside `.github/`
   and `docs/marketplace/`.
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
