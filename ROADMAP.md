# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for implementation history/verification
detail see [CHANGELOG.md](docs/CHANGELOG.md) and [VERIFICATION.md](VERIFICATION.md), for the REST API reference see
[API.md](API.md), and the `Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## GraphQL storefront API

Ordo has zero GraphQL coverage today — every customer-facing storefront touchpoint
(`Controller\Track\*`'s `tracker.js`-polled/posted endpoints, `Controller\Offer\*`) is a plain
frontend controller, and `etc/webapi.xml`'s REST routes are mostly admin/integration CRUD. A
headless/PWA Studio storefront can't call either shape the way it calls core Magento's own
GraphQL schema — it has no server-rendered page for `tracker.js` to load from, and no
`fetch()`-friendly cookie-based session the way a Luma tab has. This closes that gap, one
query/mutation at a time, each thin-wrapping the *same* underlying service class its existing
REST/frontend-controller equivalent already calls (no logic duplicated into the resolver).

Identity model: a logged-in customer is resolved from `ContextInterface::getUserId()` (Magento's
own GraphQL customer-token auth), not `Magento\Customer\Model\Session` — a decoupled frontend has
no PHP session cookie to carry. `visitorId` is always an explicit argument, same as
`Controller\Track\Poll`'s existing `resolveVisitorIdFromParam()` fallback (tracker.js already
can't rely on reading its own cookie cross-origin either).

Testing: a new `Test/GraphQl/` suite, same shape as `Test/Api/` (`AbstractGraphQlTestCase.php` —
raw HTTP POST to `/graphql`, real instance, no mocks, reusing `Test/Api`'s existing customer/admin
token acquisition) — not MFTF, for the same reason `Test/Api/README.md` already gives: a pure API
protocol has nothing browser-observable for Selenium to assert on.

- [x] **Phase 1 — core tracking** (the biggest PWA blocker; mirrors `tracker.js`'s own 4 calls) —
  shipped: `ordoTrackerPoll(visitorId: String): OrdoTrackerPoll`, `trackOrdoEvent`,
  `dismissOrdoNotification`, `submitOrdoSurveyResponse` (`etc/schema.graphqls`,
  `Model\GraphQl\Resolver\*`, `Test\GraphQl\TrackingApiTest`). Verified against a real running
  instance, not just unit tests: all 4 operations round-tripped over real HTTP, including a real
  customer-token-authenticated mutation that landed in the database and a real
  ownership-rejection for a mismatched identity. One real, non-obvious bug caught this way: a
  literal `{`/`}` inside an `@doc(description: "...")` string breaks Magento's `schema.graphqls`
  reader (a brace-balance pass ahead of the real GraphQL parser doesn't know about string
  literals) — not a GraphQL spec violation, a Magento-specific trap; avoid literal braces in any
  `@doc` description text.
- [x] **Phase 2 — My Offers** — shipped: `ordoOffers: [OrdoOffer]` (own query logic, scoped by
  `CustomerIdResolver` rather than `Block\Frontend\OfferViewModel`'s session-based one — see
  Phase 1's identity-model note), `extendOrdoOffer(offerId: Int!): OrdoExtendOfferOutput` and
  `ordoCreditLimit: OrdoCreditLimit` (both thin wrappers calling the *same*
  `Api\OfferManagementInterface::selfExtend()`/`Api\CreditLimitManagementInterface::getMyStatus()`
  the REST routes already call — no reimplementation). Verified against a real running instance:
  a real seeded offer row extended over real HTTP (expiry pushed back, then correctly refused at
  the configured extension cap), a real credit-limit read, and guest/not-found/already-maxed all
  producing the right response shape. One real, non-obvious finding from that verification: both
  reused services resolve the caller's identity via a DI-injected `UserContextInterface`, not the
  GraphQL `ContextInterface` the resolver itself receives — confirmed empirically (not assumed)
  that Magento populates it correctly for a GraphQL customer-token request too, same as it does
  for REST. But `CreditLimitManagementInterface::getMyStatus()`'s own guest guard
  (`$customerId === null`) doesn't fire for GraphQL, because `UserContextInterface::getUserId()`
  returns `0` for a guest, not `null` — unguarded, this fell through to a confusing "No such
  entity with customerId = 0" instead of an authorization error. Fixed in the resolver itself
  (`Model\GraphQl\Resolver\CreditLimit`), which now checks for a guest explicitly first, the same
  convention Magento's own core customer-aware resolvers use — see ADR 0032's update.
- [x] **Phase 3 — price-watch & push subscriptions** — shipped: `registerOrdoPriceWatch`/
  `unregisterOrdoPriceWatch`, `registerOrdoPushSubscription`/`unregisterOrdoPushSubscription`
  (the actual browser Push API subscription still has to happen client-side; this is just the
  "tell Ordo about it" half). All four are thin wrappers over the existing
  `PriceWatchSubscriptionManager`/`PushSubscriptionManager` services — `unregisterOrdoPriceWatch`
  is the first customer-facing transport for that one specifically, since no REST/controller
  route ever called it before. Verified against a real running instance: real register/unregister
  round-trips for both concerns, the SSRF-blocked-endpoint rejection, and the disabled-feature
  response shape. See ADR 0034 — this closes every phase of the GraphQL storefront API plan.

## Test coverage

- Ad-audience sync (`Cron\SyncAdAudiences`) has no test against a real Google Ads/Meta account — request/response
  logic is unit-tested via a fake `Curl`, but OAuth token exchange and Custom Audience creation/replace have never
  hit the real API. Attempting that verification found the Google Ads side's actual integration target had moved
  out from under it — `GoogleAdsSyncClient` migrated from the Google Ads API's now-effectively-retired
  `OfflineUserDataJobService` to the Data Manager API (three real, independent bugs fixed along the way: a sunset
  REST version, a dead partial-failure check, and a fire-and-forgotten long-running operation) — see
  `docs/adr/0035`. OAuth for the new `datamanager` scope is done (Data Manager API client id/secret/refresh
  token + Google Ads account id are configured against a real account). **Accepted as a permanent gap,
  not a pending task:** closing it end-to-end needs a Google Ads account with $50k+ lifetime spend —
  Google's own eligibility troubleshooter confirms Customer Match list creation is blocked below that
  threshold, independent of anything in this module — and that level of ad spend isn't realistic for this
  project. `ingestAudienceMembers` itself stays verified only via unit tests against a fake `Curl`; no
  real-account run against an actual Customer Match list is expected, now or later.
- `send_whatsapp`/WhatsApp templates have no test against a real Meta WhatsApp Business Account — same shape,
  template submission/approval polling/send have never hit a live WABA. Attempting that verification found and
  fixed one real bug: `WhatsAppSender`/`WhatsAppTemplateClient` were pinned to Meta Graph API `v20.0`, deprecated
  and removed 2026-09-24 — bumped to `v26.0`. **Accepted as a permanent gap, not a pending task:** generating a
  WhatsApp access token requires completing Meta's Business Login OAuth flow, which in turn requires the Facebook
  account to have at least one Facebook Page (confirmed root cause — every attempt failed with an empty
  `selected_business_id` in the OAuth callback, traced to "Twoje konto nie ma dostępu do żadnych stron na
  Facebooku" in Meta Business Suite). Tried on three different Meta apps (including one created via the classic,
  non-"use case" app-creation flow) with identical results, so this isn't an app-level bug. Creating a Facebook
  Page would very likely fix it, but the user has declined to create one — real-account verification of
  `send_whatsapp` stays untested for that reason, independent of anything in this module.

### MFTF/scenario coverage

Full inventory: `Test/Mftf/SCENARIOS.md`. ~~Open rows: `AutoPickCampaignSplitWinner` (§28) and
`SendAbandonedCartFallbackReminders` (§11), unit-tested only, no MFTF/Integration yet.~~ — stale,
already closed by commit `4870131` (PR #239, 2026-09-26): both have real
`Test/Integration/AutoPickCampaignSplitWinnerTest`/`SendAbandonedCartFallbackRemindersTest`
(real DB rows, real cron `execute()`, only the outbound SMS/WhatsApp provider call faked) and are
marked ✅ in SCENARIOS.md's own §11/§28 rows and its "Suggested next batch" closing note. Neither
needs an MFTF test on top - same reasoning SCENARIOS.md already gives for `send_sms`/`send_push`/
`SyncAdAudiences`: no admin-rendered page or grid reflects a split-test winner decision or a
fallback SMS/WhatsApp send, so there's nothing browser-observable for MFTF to assert on; an
Integration test exercising the real scan/claim/dispatch path is the correct and complete coverage
for this shape. This bullet was left behind when PR #239 landed - nothing further to do here.

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
7. ~~**Reply to #261 itself**~~ — done, posted. Covered all six original questions honestly
   (including #1, production references — still none — and #6, roadmap/maintenance), summarized
   the multi-store/performance work from items 2–6, and asked the reporter to actually try it
   against Wayfarer.cz (even a staging copy) since there's still no real production usage to point
   to. Issue left open — it's an active conversation waiting on their response, not something
   resolved to close.

## Admin UX

- ~~Add `exportButton` to `ordo_adminactionlog_listing.xml`, `ordo_cronrunlog_listing.xml`,
  `ordo_orderapproval_listing.xml`, `ordo_productfeedrunlog_listing.xml`~~ — already done (commit
  `dd42acaf`, PR #216, 2026-09-25); this bullet was stale bookkeeping, not an open gap.
- ~~Add responsive breakpoints to `campaign-form.css`, `segment-form.css`, `whatsapp-template-form.css`,
  `free-gift-offer-form.css`~~ — done for the two that actually have anything to adapt
  (`segment-form.css`, `free-gift-offer-form.css`): a new `@media (max-width: 1024px)` block in
  each trims the relevant columns' `flex-basis` values proportionally, so more of a flex-wrap row
  still fits on one line before wrapping. `campaign-form.css`/`whatsapp-template-form.css` have no
  multi-column layout at all (checked directly — neither file has a single `flex-wrap` rule), so
  there was nothing to add a breakpoint to. Checked at a real 950px browser window (not just read
  from the CSS): the conditions row itself stays within its content column with no horizontal
  overflow, before and after this change.
- ~~**Found while checking the above: every row in the Conditions `dynamicRows` list (both
  `ordo_campaign_form.xml`'s classic editor and `ordo_segment_form.xml`) is missing its own delete
  button entirely**~~ — done, and verified against the real running admin (not just read from the
  diff), which caught a second, more serious bug the button alone didn't surface. Added the same
  `<actionDelete>` element the Free Gift Offer form's Tiers list already had to both forms'
  conditions container (`ordo_campaign_form.xml`'s added even though that fieldset stays
  `visible: false` behind the Flow canvas, so the dynamicRows component is correct if it's ever
  shown again; the Actions list was out of scope for this bullet and left as-is). The button
  rendered in the right place but never actually deleted anything on a real click - confirmed with
  `elementFromPoint()` at the button's own center: `segment-form.css`'s existing
  `tr.data-row td:last-child` rule (pinning the delete button to the row's right edge) was matching
  a *different* field's `<td>` than the one holding the button. This row has ~24 dedicated fields
  (one per condition type, almost all collapsed to 0×0 by `switcherConfig`), and dumping every
  `<td>`'s own `data-index` in actual DOM order proved that order has nothing to do with the fields'
  declaration order in the XML or their own `sortOrder` - Magento positions each field visually via
  a separate `order` CSS value and leaves raw DOM child order to whatever sequence each field's own
  component finishes initializing in. `:last-child` happened to land on `match_mode` (sortOrder 16,
  nowhere near actually last) instead of the real `actionDelete` `<td>` (sortOrder 30), so
  `match_mode`'s own absolutely-positioned-on-top-by-that-same-rule column silently ate every click
  meant for the button beneath it. Fixed by matching `td:has(.action-delete)` instead of
  `td:last-child` (segment-form.css's three selectors touching that column) - verified by adding and
  removing rows at different positions (first, middle, last) in a real browser, confirming the
  right row's own data (not just the last one) is removed each time. The Free Gift Offer form's own
  Tiers/Products lists (2-3 simple fields, no `switcherConfig`) never hit this, since there's no
  field-count/async-init race for `:last-child` to get wrong there.
- ~~**`WhatsAppTemplate\Edit\SubmitForReviewButton`/`RefreshStatusButton` 404 when clicked**~~ —
  done. Same fix as `Shared\Edit\DeleteButton`/`EmailTemplateDraft\Edit\PublishButton` (see
  `docs/adr/0021-email-template-drafts.md`): both called `deleteConfirm('message', 'url')`
  (2 args), which navigates via a plain GET (`mage/adminhtml/globals.js`'s `setLocation()`); their
  controllers (`SubmitForReview`/`RefreshStatus`) implement `HttpPostActionInterface` only, so
  `app/etc/di.xml`'s HTTP-method map rejected the GET before it dispatched — a 404. Fixed by
  adding the third `{data: {}}` argument (`mage/dataPost.js`'s real-POST-with-form_key form).
  Verified in a real browser: both buttons now reach their controllers (`RefreshStatus` surfaces
  its own "nothing to refresh yet" business-logic message; `SubmitForReview` makes a real outbound
  Graph API call and surfaces *that* error) instead of 404ing.
- ~~No field anywhere documents what a WhatsApp template's `{{1}}`, `{{2}}`, ... positional
  placeholders actually mean for that specific template~~ — shipped: new nullable
  `placeholder_legend` text column on `ordo_whatsapp_template`, a free-text field on the
  WhatsApp Template edit form ("e.g. `{{1}} = first name, {{2}} = order number`"), and
  `Model\Config\Source\WhatsAppTemplateOptions` now appends it to the template's label wherever
  that option list is used — the `send_whatsapp` action's `template_id` picker in `Flow.php`'s
  canvas, the only consumer. Chose this over validating `params` count against the template's
  `{{N}}` count at save time (the other option this item originally floated): a count check
  catches a mismatch but still leaves the admin guessing what each slot *means*, which was the
  actual complaint. Verified against the real admin: a template saved with a legend shows
  `"Name (legend text)"` in the campaign canvas's own template dropdown.

## Documentation

- ~~GitHub Wiki (`github.com/michalper/ordo/wiki`, source staged in `docs/wiki/`) — needs native PL
  review.~~ — the `docs/wiki/` staging copy had drifted stale (last touched 2026-11-05) relative to
  the real, live wiki, which every edit since has gone straight to instead — removed it rather than
  resync it, since nothing actually reads from it. Audited the live wiki's PL/EN parity directly
  (heading-count check per page, every page bilingual except `Privacy-Policy.md`, now translated
  too) and backfilled several real gaps found along the way: the `customer_inactive`/
  `order_canceled`/`review_request_due`/`referral_converted` triggers, `generate_ai_content`, and
  the whole lead-routing/sales-rep-digest feature pair had zero wiki coverage.

## Candidate new features

Not prioritized against each other; listed for later scoping with the business/product side.

- ~~**Campaign template library**~~ — shipped, see `docs/adr/0020-campaign-template-library.md`.
  New `Model\Campaign\TemplateLibrary` + a gallery page (`Controller/Adminhtml/Campaign/Library.php`,
  reachable from the campaign grid's new "Browse Template Library" button) hand curated payloads
  straight to the existing `CampaignImporter::import()`. Only 3 of the 4 originally-named
  templates shipped — **win-back is not buildable with this engine's current primitives**:
  `recurring_schedule`/`scheduled_at` triggers fire once per campaign on a schedule with no
  `customer_id` in context at all (`Model\Campaign\ScheduledTriggerScanner::fire()`), so every
  per-customer condition/action on a template built that way would silently never fire. A working
  win-back template needs a new per-customer recency-based trigger primitive in the engine itself
  — real engine work, not something to fold into a template-library pass. Found and fixed a real,
  separate, pre-existing bug along the way: `ordo_campaign_importform_index.xml` and
  `ordo_segment_importform_index.xml` both had the wrong layout handle filename (an extra,
  incorrect `_index` suffix no other single-word-action layout file in this module has), so the
  "Import Campaign"/"Import Segment" pages' own upload form had never actually rendered through
  the real admin UI — caught only because copying that same (wrong) naming convention for this
  feature's own new page broke identically. Both renamed to the correct
  `ordo_campaign_importform.xml`/`ordo_segment_importform.xml`.
- ~~**Email template drafts**~~ — done, see `docs/adr/0021-email-template-drafts.md`. New
  `Model\EmailTemplateDraft` (table `ordo_email_template_draft`, one open draft per
  `email_template.template_id`, DB-enforced via a unique constraint) lets an admin edit a
  template's subject/content/styles separately from the live row `send_email` reads.
  `Controller\Adminhtml\EmailTemplateDraft\Publish` copies the draft onto the live template and
  saves it through the real `Magento\Email\Model\ResourceModel\Template` - the same
  save-through-core-resource-model trick `EmailTemplateVersion\MassRestore` already uses - so
  publishing automatically takes a fresh "before publish" snapshot via the existing
  `SnapshotEmailTemplateVersion` plugin when versioning is enabled, then deletes the draft row.
- ~~**Cross-campaign revenue/LTV comparison view, and cohort/retention analysis**~~ — done, see
  `docs/adr/0023-cross-campaign-comparison-and-cohort-retention-reports.md`. Two separate
  reports, not one page: `ordo/campaigncomparison/index` ranks campaigns against each other
  (sent/converted/conversion rate from `ordo_campaign_outcome_log`, revenue from the already-
  deduplicated `ordo_campaign_attribution`, avg. converted-customer CLV from
  `ordo_customer_clv_score`), and `ordo/cohortretention/index` groups registered customers by
  their first-order calendar month and tracks what share of each cohort placed another order N
  months later. Neither needed a new table or cron — both are pure live SQL over tables this
  module already writes, same philosophy as the revenue/channel analytics report. Cohort rows have
  no natural per-row entity (unlike every other report grid in this module), so
  `CohortRetention\Grid\Collection` is the one place that replaces its base `SearchResult`'s
  `FROM` clause outright instead of extending it with joins — flagged explicitly in the ADR as a
  deliberate one-off departure. Verified against the real admin, in Polish: both reports and their
  dashboard cards render with real data and fully translated labels (all 12 non-English locale
  CSVs updated, matching this module's established "every new UI string ships translated"
  discipline). Cohort-by-acquisition-campaign (not just by calendar month) was logged as open in
  the ADR's own Consequences section at the time — since closed, see `docs/adr/0029-cohort-by-acquisition-campaign.md`.

**The five items below are analytics/reporting gap ideas** (see issue #261 for the original
multi-store/performance thread this grew out of). Ordo's automation side (triggered sends,
segmentation, cross-sell via `ProductRecommender`, customer profiles via `Customer360`) is already
fairly complete; the real gap is on the analytics/reporting side, where Ordo's dashboard today is
operational (job health, campaign counts) rather than a BI view, and
`CampaignOutcomeLogger::getStats()` only gives per-campaign sent/converted/revenue, no breakdown by
coupon/channel/device/geography.

- ~~**Revenue/channel analytics report**~~ — done, see
  `docs/adr/0017-revenue-channel-analytics-report.md`. New read-only `ordo/revenueanalytics/index`
  report, one row per order: revenue, coupon code, the campaign credited with the conversion (if
  any — a `joinLeft` to `ordo_campaign_outcome_log`, deduped to one campaign per order since
  `markActed()` can credit an order to more than one), billing country/city (`sales_order_address`,
  never joined by this module before), and new-vs-returning (a derived `MIN(entity_id)`-per-customer
  subselect). Device was dropped, not faked — same reasoning as the product performance report's
  own per-visitor-funnel gap: nothing in this codebase or a stock Magento install tracks order-level
  device, and there's no join path to add it after the fact. Coupon-to-Ordo-campaign attribution was
  also a known gap at the time (`Model\CouponGenerator` marks a code identically to core's own
  mass-generator) — since closed, see `docs/adr/0028-coupon-to-campaign-attribution.md`.
- ~~**Product performance report**~~ — done, see `docs/adr/0015-product-performance-report.md`.
  Per-SKU views (materialized by a new incremental `Cron\CalculateProductPerformance`, since the
  source `ordo_visitor_event` rows are pruned after ~7 days) and purchases (live-joined from
  `sales_order_item`, never pruned) in a new read-only admin report
  (`ordo/productperformance/index`), with a store-wide abandon rate — not a per-visitor funnel,
  which the data model can't support honestly (no `visitor_id` on `sales_order` anywhere).
- ~~**Order-canceled trigger + recovery action**~~ — done: new `TRIGGER_ORDER_CANCELED` trigger,
  `Observer\DispatchOrderCanceledCampaigns` on Magento core's own `order_cancel_after` event
  (mirrors `DispatchOrderPlacedCampaigns`'s pattern; `CampaignDispatcher` needed zero changes,
  since trigger matching is fully data-driven). No new action class — `send_email` (optionally
  chained after `generate_coupon`) already covers a recovery/win-back send. New
  "Canceled Order Recovery" starter template in the admin Flow builder.
- ~~**Email open/click tracking**~~ — turned out mostly already done (schema, SendGrid Event
  Webhook handling, per-campaign open/click-rate reporting all shipped earlier, in `32a2955`); the
  only real gap was a segment/campaign condition reading that data, now done — see
  `docs/adr/0016-message-engagement-condition.md`. New `message_engaged` condition
  (`Model\Campaign\Condition\MessageEngaged`), usable from both the campaign Drawflow canvas and
  the segment editor.
- ~~**Message log retention policy**~~ — done. New `Cron\PruneMessageLog` (default 180 days,
  mirrors `Cron\PruneVisitorEvents`'s shape exactly) deletes old `ordo_message_log` rows;
  `ordo_message_log_event` rows are deleted automatically via that table's own `ON DELETE CASCADE`
  foreign key, no separate delete needed. New `Stores → Configuration → Ordo Automation →
  Communication Channels → Message Log Retention` config group
  (`Helper\Config::getMessageLogRetentionDays()`) — its own comment warns against setting it
  below a `message_engaged` condition's `within_days`, or that condition silently stops seeing
  older engagement once the backing rows are pruned.
- ~~**Group the condition-type dropdown into categories**~~ — done. Reported directly against a
  real screenshot: the segment editor's condition picker was one flat ~20-item `<select>` (`Has
  Tag`, `Order Total ≥`, `Opened/Clicked Campaign`, ...). `Model\Campaign\TypeLabels` gained
  `conditionCategory()`/`conditionCategoryOrder()` (4 categories: Tags & Behavior, Order &
  Purchase History, Score & Engagement, Segments — every registered type mapped, so the fallback
  "Other" bucket is unused today but still there for a future/custom type), and
  `Model\Config\Source\ConditionType::toOptionArray()` nests them into Magento_Ui's native
  `optgroup` binding shape (`Magento_Ui/js/lib/knockout/bindings/optgroup.js` — a nested array as
  a `value` is already a group header, zero new JS/UI component needed).
  `Model\Config\Source\ConditionTypeWithGroup`'s synthetic "Group" entry needed no change (still
  appends as a flat trailing option alongside the groups). The Drawflow Flow canvas's own
  condition palette is unaffected — it doesn't read `ConditionType` at all, it already has
  collapsible sections and a search box.
- ~~**Tag field as a select/autocomplete of real existing tags, not free text**~~ — done, see
  `docs/adr/0018-tag-field-multiselect.md`. Went further than the original ask: asked to choose
  between autocomplete-only and full multiselect, the user picked multiselect with a per-condition
  ANY/ALL match mode. `Has Tag`/`Visitor Has Tag`/`add_tag` all now use a removable-chip editor
  (`Ordo_Automation/js/tag-chip-editor.js`) with live autocomplete suggestions
  (`Controller/Adminhtml/Tag/Search.php`, a new shared endpoint deliberately NOT namespaced per
  feature — see the ADR for why) and free entry for a brand-new tag. `HasTag` migrated onto
  `BulkResolvableConditionInterface`, replacing a hardcoded switch arm in `SegmentMemberResolver`.
  Caught and fixed two real `Magento\Framework\DB\Select` API bugs along the way (a bare `DISTINCT`
  column string being quoted as a literal identifier; `having()` not being type-safe to chain) and
  a real saved-tags-don't-reload-on-edit bug in both `Model\Campaign\DataProvider` and
  `Model\Segment\DataProvider` — all confirmed against real data before shipping, not just reasoned
  about. One related gap found and deliberately NOT fixed in this pass, logged separately below:
  every select-type dedicated field (`tier`, `event_type`, `engagement_type`, and now `match_mode`)
  leaks its default value into every condition/action row's saved params regardless of that row's
  own type — pre-existing, harmless to correctness, but untidy.
- ~~**Dedicated-field merge leaks every select field's default into every row's params**~~ — done.
  `CampaignSaveProcessor`/`SegmentSaveProcessor`'s `normalizeRowParams()` used to merge a
  condition/action row's WHOLE set of dedicated field values regardless of the row's own type
  (Magento_Ui's dynamicRows always posts every field's current value regardless of
  switcherConfig visibility), so e.g. a `monetary_total_at_least` condition's saved params could
  pick up a useless `tier: "bronze"` key from an unrelated row's `<select>` default. Fixed with a
  `type => [relevant field names]` lookup (`CONDITION_FIELDS_BY_TYPE`/`ACTION_FIELDS_BY_TYPE`/
  `TRIGGER_FIELDS_BY_TYPE`), verified against every real condition/action class's own params
  reads, not just UI config (which turned out to be missing several registered types entirely).
  **Found a second, more serious bug the same way**: `send_whatsapp`'s `template_id`/`params` and
  `send_push`'s `title`/`url` were missing from the old flat field list *entirely* — since
  `campaign-flow-editor.js` renders only dedicated-field inputs for a type that has
  `getFieldsConfig()` descriptors (no `params_json` fallback), those four field names were
  silently dropped on every save, not just leaked — a `send_whatsapp`/`send_push` action
  configured via the Flow canvas never actually had its template/title/url persisted. Both bugs
  fixed together; verified against the real running admin (a crafted POST to the real
  `ordo/campaign/save` endpoint, not just unit tests): the leak is gone and
  `send_whatsapp`'s `template_id`/`params` now save correctly.
- ~~Five more free-text fields with the same "references real data, no autocomplete" shape the
  tag field had~~ — shipped, all five:
  - `event_key` ("SKU (optional)") on the `event_occurred` condition now gets the same
    autocomplete the `sku` field already had, in both `Flow.php`'s canvas and
    `ordo_segment_form.xml`/`ordo_campaign_form.xml`'s classic editors — one shared fix
    (`segment-sku-autocomplete.js`'s field-name regex now matches `[sku]` or `[event_key]`), two
    consumers.
  - `rule_id` ("Cart price rule") on `generate_coupon` and the content block's rule-based source
    is now a proper select, backed by a new `Model\Config\Source\CartPriceRuleOptions` (active
    rules that actually use a coupon code, excluding "No Coupon" rules). Wired into
    `ordo_campaign_form.xml`, `ordo_contentblock_form.xml`, and `Flow.php`'s canvas.
  - `category_id` in `ordo_contentblock_form.xml` now reuses the existing
    `Model\Config\Source\Category` source instead of a plain text input.
  - `template` ("Email template") on `send_email` is now a select, backed by a new
    `Model\Config\Source\EmailTemplateOptions` combining every XML-registered default template
    (`Magento\Email\Model\Template\Config::getAvailableTemplates()`, not scoped to this module)
    with every admin-saved/customized DB row. Wired into `ordo_campaign_form.xml` and `Flow.php`'s
    canvas.
  - `fallback_whatsapp_template_id` (`etc/adminhtml/system.xml`, Abandoned Cart Recovery) now
    reuses the existing `Model\Config\Source\WhatsAppTemplateOptions`, same as `send_whatsapp`'s
    own `template_id` field.

  Verified against the real running admin: created a real active, coupon-based cart price rule and
  confirmed it appears in both the Flow canvas's and the content block form's `rule_id`
  selects; confirmed the `send_email` canvas field lists both a DB-saved template and core XML
  defaults; confirmed `event_key`'s autocomplete fires a real `segment/productsearch` request and
  fills in a real SKU on selection, in the segment editor; confirmed the WhatsApp template system
  config field renders as a select with the translated label/comment. Caught along the way: the
  JS/CSV changes weren't visible in this `production`-mode sandbox until
  `setup:static-content:deploy -f pl_PL -a adminhtml` was re-run and `pub/static`'s stale copy was
  cleared — a plain `composer update`/cache-flush isn't enough for JS-side changes outside
  developer mode.
- ~~`campaign-flow-editor.js` has no i18n at all~~ — shipped (see ADR 0019): wrapped all 33
  hardcoded strings in `campaign-flow-editor.js` plus 2 in `tag-chip-editor.js`, and — found only
  by verifying against a real Polish admin session, not visible from the diff alone — fixed the
  much bigger pre-existing gap it exposed: every condition/action type label and condition-category
  group name (`Model\Campaign\TypeLabels`, 33 labels + 5 categories) had never been translated at
  all, plus 3 missing `TagMatchMode`/"Match" CSV rows from ADR 0018. 73 new phrase keys added
  across all 11 translated locale CSVs.
- ~~**Automated anomaly/insight alerts**~~ — done, see
  `docs/adr/0025-automated-anomaly-insight-alerts.md`. Scoped to per-campaign anomalies, not
  per-channel — channel isn't tracked at the campaign-outcome/attribution level at all (confirmed
  gap, same one ADR 0017 already flagged), so a channel-level alert would need a join the schema
  can't honestly support. New `Cron\DetectAnomalies` (daily) flags two things over a rolling
  7-day window vs. an equal-length trailing baseline: `campaign_revenue_drop` (a campaign's own
  revenue down ≥30% vs. its baseline) and `campaign_conversion_outlier` (a campaign's conversion
  rate ≥50% off its peers' average, either direction). Both gated behind a minimum sample size
  (default 20 sent) to avoid flagging noise on low-volume campaigns, and deduplicated via the
  existing `Model\Cron\ReminderLogStore::claim()` cooldown pattern (`SendCreditLimitAlerts`'s own
  mechanism) so the same campaign+alert-type pair won't re-fire inside its cooldown window. New
  read-only `ordo/anomalyalert/index` grid (mirrors `CronRunLog`'s - the simplest existing grid in
  this module) plus a dashboard stat card. All five thresholds are ordinary `Helper\Config` fields,
  not hardcoded — exactly so they can be retuned once there's real production data to calibrate
  against, the risk this item was originally deferred over.
- ~~**Per-topic preference granularity within a channel**~~ — done, see
  `docs/adr/0026-per-topic-consent-granularity.md`. Two fixed topics (`Model\ConsentTopic`:
  `Promotional`, `AccountUpdates`), a second opt-out register (`ordo_customer_consent_topic`)
  additive to the existing per-channel one — `ConsentManager::hasTopicConsent()` is true only when
  both say so, so every existing `hasConsent()` call site keeps working unchanged. The four
  `send_*` campaign actions gained an optional `topic` param (defaults to `Promotional`), and the
  five existing reminder crons/senders (win-back, abandoned cart, offer expiry → `Promotional`;
  credit limit alerts, reorder reminders → `AccountUpdates`) were switched onto the new
  topic-aware check with a fixed topic each — this is what actually gives a customer real control,
  not just new campaigns. Managed from a new "Topic preferences" card on the existing admin
  GDPR/Consent screen; no storefront self-service page, matching channel-level consent's own
  existing gap.
- ~~**Merge the three storefront poll endpoints**~~ — done, see
  `docs/adr/0024-merge-storefront-poll-endpoints.md`. The three GET controllers
  (`Controller\Track\{Popup,Notification,Survey}`) collapsed into one, `Controller\Track\Poll`
  (`GET /ordo/track/poll`), returning `{"popup", "notifications", "survey"}` in a single JSON
  body — each concern keeps its exact pre-merge semantics (popup/survey claim-once via
  `Model\Track\DeliveryClaimer`, notifications never claimed). `tracker.js`'s three
  `startPolling()` loops collapsed into one, gated by one `data-poll-enabled`/`data-poll-interval`
  pair (the interval is the minimum of whichever concerns are actually enabled, so none polls less
  often than its own admin-configured interval). `DismissNotification`/`SubmitSurveyResponse`
  (POST, state-mutating, triggered by an explicit click, never on a timer) were out of scope and
  untouched. Verified against the real running sandbox: loaded a real storefront page and
  confirmed, via the browser's own network panel, exactly one `/ordo/track/poll` request fires per
  tick instead of three, with the correct combined response body. `docs/wiki/API-Reference.md`
  (Polish and English) updated for the merged endpoint.
- ~~**Single catalog walk for all product feeds**~~ — done, see
  `docs/adr/0022-single-catalog-walk-for-product-feeds.md`. `FeedGeneratorInterface::generate(int
  $storeId): array` became three steps (`startFeed()`/`addProduct()`/`finishFeed()`), and new
  `Model\ProductFeed\ProductFeedRefresher` — now shared by `Cron\RefreshProductFeed` and
  `Controller\Adminhtml\ProductFeed\RefreshNow`, replacing their own duplicated double loop —
  walks `CatalogFeedProductFetcher::fetchByPage($storeId)` exactly once per store, offering each
  product to every enabled generator instead of each generator re-walking the whole catalog
  itself. A generator that throws mid-walk is dropped from that store's batch without disturbing
  the others, preserving the old "one format's failure doesn't affect another's cached feed"
  guarantee. Verified against the real sandbox, not just unit tests: enabled all three formats,
  ran the real cron against the real DB with MySQL's general query log on, and confirmed exactly
  one `catalog_product_entity`/`cataloginventory_stock_item` collection query per store (previously
  3) while all three feeds still cached correct, real content (`ordo_product_feed_cache`,
  `product_count` matching the real catalog) and the public feed route still served it
  (`/ordo/productfeed/index` → 200). Also added `CatalogFeedProductFetcherTest` (previously no
  dedicated test existed for the paging logic — it had only ever been exercised indirectly,
  duplicated across all three generators' own tests).

**The four items below came out of a competitive feature-gap pass** (comparing Ordo's shipped
surface against other marketing-automation tools' own public feature pages, then verifying each
candidate gap against this codebase before listing it — most of what was found, Ordo already has:
KPI dashboard, revenue/channel + product performance reports, cross-campaign comparison, cohort
retention, 20+ segmentation condition types, the full set of lifecycle emails, email open/click
tracking, and anomaly alerts are all already shipped, see the rest of this file). These four are
the ones that are genuinely still open, two of them already named elsewhere in this document as
known gaps.

- ~~**Coupon → campaign attribution.**~~ — shipped, see `docs/adr/0028-coupon-to-campaign-attribution.md`.
  New satellite table `ordo_coupon_campaign_log`, keyed by `coupon_id` (not `code`), populated by
  `CouponGenerator::generate()`'s new optional `campaignId` param from the `context['campaign_id']`
  `CampaignDispatcher::runActionsFrom()` already stamps. `RevenueAnalytics`'s report gains a
  separate `coupon_campaign_name` column joined through it.
- ~~**Cohort-by-acquisition-campaign grouping.**~~ — shipped, see
  `docs/adr/0029-cohort-by-acquisition-campaign.md`. An optional `acquisition_campaign_id` filter on
  `ordo/cohortretention/index` narrows the whole pipeline (not a post-hoc row filter) to customers
  whose first order is attributed to that campaign via `ordo_campaign_attribution`, picking the
  highest-`attributed_revenue` touch per order (MySQL `ROW_NUMBER()`) to keep retention-rate math
  single-assignment.
- ~~**Per-customer recency trigger primitive.**~~ — shipped, see
  `docs/adr/0030-customer-inactive-campaign-trigger.md`. A new `customer_inactive` trigger
  (`Cron\DispatchCustomerInactiveCampaigns`, nightly) with its own per-trigger `days` field gives
  the Flow builder the composable, per-customer entry point the campaign template library item
  above found missing — a store can now build several differently-shaped win-back campaigns at
  different inactivity windows, independent of `Cron\TagInactiveCustomers`/`SendWinBackEmails`'s
  existing single, fixed-threshold email.
- ~~**Purchase funnel / drop-off report.**~~ — shipped, see `docs/adr/0031-purchase-funnel-report.md`.
  Twice declined before in the product performance/revenue-channel reports' own per-SKU/per-order
  shape (no `visitor_id` on `sales_order`) - closed with a differently-scoped funnel instead,
  joining `ordo_visitor_event` to orders via `customer_id`, scoped to registered customers
  consistently across every stage (not just the last one, or the drop-off percentages would be
  meaningless). New `checkout_start` event (`window.ordoTrack('checkout_start')`, same
  theme-integration-point pattern `product_view`/`category_view` already use);
  `Cron\CalculateFunnelMetrics` materializes daily counts into `ordo_funnel_daily_stats` before
  `ordo_visitor_event` is pruned, at a 2-day settling lag so a same-day login has time to be
  stitched back onto that day's earlier browsing first.
