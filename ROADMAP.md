# Roadmap

Ownership split for how this roadmap is being driven: B2B direction is scoped by the technical/architecture side (this
repo's maintainer); B2C direction is scoped from real hands-on marketing automation experience — so expect the B2C
phases below to grow faster and get more opinionated over time.

This file tracks what's shipped (for the implementation history/context) and what's still open. For the current, stable
feature list, see [README.md](README.md); for the full REST API reference, see [API.md](API.md).

## Phase 2 — remaining B2B triggers

**Closed.** Sales-rep-signed emails and the weekly rep digest shipped in v0.4.0 (`SalesRepEmailContext`,
`SendSalesRepDigest`).

## Phase 3 — Promotion Builder (adjacent product area, not a trigger)

**Closed.** All items below shipped. A friendlier admin layer over Magento's native `SalesRule` engine — the raw native
form (tabs, dropdowns, a condition tree written like code) is the same everywhere, and no store owner enjoys using it.

- **Buy X Get Y free — done, admin config screen only, no new backend logic.** The native `SalesRule` "Buy X Get Y"
  discount action (`Magento\SalesRule\Model\Rule\Action\Discount\BuyXGetY`) already computes the discount correctly from
  the native `discount_step` (X) and `discount_amount` (Y, reused as the free quantity for this action) fields — the gap
  was that neither field's label makes clear what's actually being configured, and there was no way to confirm the
  resulting offer before saving. `view/adminhtml/ui_component/sales_rule_form.xml` extends Magento_SalesRule's own form
  (standard UI Component XML merge by node name, not a fork) to add a read-only live preview field
  (`Ordo_Automation/js/buy-x-get-y-calculator`) next to the native fields: "Buy 3, get 1 free — customers pay for 3 out
  of every 4 (25% off that batch)." — updating as the admin types, with the same guard as the native calculator
  (`if (!$x || $y > $x) return no discount`) reflected as a warning instead of a silently-wrong preview. Verified live:
  loaded a real New Cart Price Rule admin page, confirmed the component and its JS/template assets serve correctly and
  the form still renders without error.
- **Coupon generated after checkout / for cart recovery** — **done, reframed:** ships as ordinary campaigns on the
  engine (`order_placed` / `cart_abandoned` → `generate_coupon` → `send_email`) instead of bespoke code per idea.
- **`SendAbandonedCartReminders` now also dispatches a `cart_abandoned` campaign event** — done. The fixed reminder
  email still always sends (unconditionally, even for guests); a `cart_abandoned` campaign is additionally dispatched
  for quotes tied to a registered customer, so a store can layer a coupon or a tag onto cart recovery without touching
  this cron. Guest quotes only get the fixed email — every current campaign condition/action assumes a real
  `customer_id`.
- **Cheapest item in a bundle free — implemented and now selectable from the admin UI.**
  `Model\Rule\Action\Discount\CheapestItemFree` (+ `QualifyingSetTracker`) is wired into Magento's `SalesRule`
  calculator extension point (`CalculatorFactory`'s `discountRules` array — the real extension point in this Magento
  version, found by actually running the discount and hitting a "no such argument" error; see `VERIFICATION.md` #16) as
  a new possible `simple_action` value, `ordo_cheapest_item_free`. Because `DiscountInterface::calculate()` only ever
  sees one item at a time, `QualifyingSetTracker` re-runs the rule's own condition tree across the whole quote the first
  time any item for that rule is asked about, picks the cheapest match, and caches the answer for the rest of that
  request — so all the individual per-item calls agree. The native admin "Apply" dropdown is a plain class
  (`SimpleActionOptionsProvider`, not an interface with a `di.xml` preference), so a
  `Plugin\SalesRule\SimpleActionOptionsProviderPlugin` (`etc/adminhtml/di.xml`) appends the option — verified live by
  calling the real, compiled `SimpleActionOptionsProvider::toOptionArray()` and confirming `ordo_cheapest_item_free` is
  in the returned list, not assumed from reading the plugin code. Re-run against a real checkout as part of this
  module's own verification (see `VERIFICATION.md`).
- **Free gift above a cart threshold — done, and cascading rather than a single flat threshold.** Correctly a different
  architecture from `CheapestItemFree` above: this *adds a new line item* to the cart (a product that wasn't there), so
  it's quote manipulation (`Quote::addProduct()` + `setCustomPrice(0)`), not a `DiscountInterface` calculator. An offer
  (`FreeGiftOffer`) has a pool of eligible SKUs (`FreeGiftOfferProduct`) and one or more cart-subtotal tiers
  (`FreeGiftOfferTier`) — every tier the subtotal has reached ADDS its `gift_slots` to the total earned, cumulative
  across tiers and across every active offer (e.g. tier @100 → +1, tier @300 → +1 gives 2 slots at a 300 subtotal, not
  just the top tier's 1), so the number of gifts a customer may pick scales with how much they spend instead of being
  capped at one. Selection is customer/guest self-service (`FreeGiftManagementInterface::getEligibility`/`selectGifts`,
  ownership-checked the same way as `OfferManagement::selfExtend`) and replaces the cart's gift selection each call.
  `Observer\TrimExcessFreeGifts` trims the selection back down if the subtotal later drops (e.g. the customer removes a
  paid item) below what earned it. Marker rows (`ordo_quote_gift_item`) — not price alone — identify which quote items
  are gifts, so they can be reliably found and removed. See `API.md` for the full endpoint reference.

## Phase 4 — admin UI

**Campaign builder — done.** New "Ordo Automation" top-level admin menu (`etc/adminhtml/menu.xml`) with:

- **Campaigns grid** (`ordo/campaign/index`) — standard `SearchResult`-based admin grid
  (`Model\ResourceModel\Campaign\Grid\Collection`), filterable by name/trigger event/enabled, with Edit/Delete row
  actions.
- **Campaign edit form** (`ordo/campaign/edit`) — name, trigger event (dropdown from `TriggerEvent` source), enabled
  toggle, and two `dynamicRows` sections for conditions and actions. The type dropdowns in both are generated from
  `ConditionPool::getAvailableTypes()` / `ActionPool::getAvailableTypes()` — i.e. from whatever's actually registered in
  `di.xml` — so the UI can never drift out of sync with what the dispatcher can resolve. Each condition/action row also
  has a dedicated field per known type (`tag`, `amount`, `rule_id`, `prefix`, `template`, `message`), shown/hidden via
  `<switcherConfig>` keyed off the row's `type` select — the raw JSON textarea (`params_json`) is now just the fallback
  for a type without one yet. End-to-end verified against the real database: saving a `tag` condition through its
  dedicated field produces `{"tag": "..."}`  in `ordo_campaign_condition.params`.
- **Reorder Cycles grid** (`ordo/reordercycle/index`) — read-only diagnostic view of what `CalculateReorderCycle` has
  computed (customer, SKU, average interval, next expected date), for verifying a detected cycle looks right without
  querying the database directly.

**Dashboard — done.** `Ordo Automation` is now a single, flat admin menu entry (no dropdown) — clicking it opens a
custom dashboard (`ordo/dashboard/index`, own block/template/CSS, not a UI Component) with campaign stats, nav cards to
Campaigns/Reorder Cycles/Free Gift Offers/Configuration, and a campaign grid. Server-rendered from the same collections
the grids use — no separate REST/auth story, it lives inside the existing admin session.

**Free gift offers admin UI — done.** `ordo/freegiftoffer/index` (grid, same `SearchResult`-based pattern as campaigns)
and `ordo/freegiftoffer/edit` (form: name, enabled toggle, and two `dynamicRows` sections — cascading tiers and
gift-pool SKUs, no switcher needed since neither section has per-type fields). `Save.php` follows the same
delete-then-reinsert child-row pattern as `Controller\Adminhtml\Campaign\Save`. Closes the gap this module had since the
free-gift feature itself shipped (previously REST-API/database-only). End-to-end verified against a real admin session:
created an offer with two tiers and one gift SKU through the actual form POST, confirmed all three rows persisted
correctly, confirmed the edit page pre-populates them, confirmed delete cascades.

**Campaign flow visualization — read-only preview shipped.** `ordo/campaign/edit` now renders
a [Drawflow](https://github.com/jerosoler/Drawflow) (MIT, vendored under `view/adminhtml/web/lib/drawflow/`) canvas
above the existing dynamicRows form: `Block\Adminhtml\Campaign\Edit\Flow` reads the same `CampaignCondition`/
`CampaignAction` rows the form edits and builds a trigger → conditions → actions node graph server-side
(`getFlowDataJson()`), imported into Drawflow in `editor_mode = 'view'`. Deliberately **not** the source of truth — the
dynamicRows form and `Save.php` are unchanged, this is purely an additional visualization hooked onto data that already
exists. Verified live: loaded a real campaign with a condition and an action, confirmed the exact node/connection JSON,
confirmed the JS/CSS assets serve and the page renders without error.

**Full visual editor — done.** The Flow canvas is no longer read-only or click-to-add-only: a palette sidebar
(`view/adminhtml/templates/campaign/flow.phtml`) lists every registered trigger/condition/action type as a draggable
chip; dragging one onto the canvas creates a node of that exact kind and type at the drop point (`toCanvasPosition()` in
`campaign-flow-editor.js` converts the drop's viewport coordinates into Drawflow's internal, zoom/pan-aware canvas
space, same formula as Drawflow's own drag-from-menu example), pre-selected rather than defaulting to whichever type
happens to sort first. Connections are still drawn by dragging between a node's input/output dots (native Drawflow
behavior). "Apply flow to form & Save" writes the whole graph back into the same `triggers[]`/`conditions[]`/`actions[]`
structure `Save.php` already accepts — unchanged from before. Verified live: dragging the `order_total_gte` condition
chip onto the canvas produces a node pre-set to that type with its dedicated "Minimum order total" field rendered, not a
raw JSON textarea.

**Delay / wait step between actions — done (see Phase 7).** Campaign actions can specify `delay_minutes`;
`CampaignDispatcher` pauses the chain and `Cron\RunScheduledCampaignActions` resumes it once `run_at` has passed. Closes
what was the clearest gap between this engine and a real drip-campaign builder.

**Campaign count by trigger event — done.** The dashboard now shows a "Campaigns by trigger" breakdown: one row per
fixed `CampaignTriggerInterface` trigger (`order_placed`, `customer_registered`, `tag_added`, `cart_abandoned`,
`visitor_tag_added`), each showing how many campaigns currently use it — including a row showing 0 for a trigger nothing
uses yet, so it answers "which of the triggers we support are actually adopted", not just totals for the ones already in
use. `DashboardViewModel::getCampaignCountForTrigger()` is a plain filtered count on `ordo_campaign_trigger`, relying on
that table's `UNIQUE(campaign_id, trigger_event)` constraint to make a plain count already correct without an explicit
`DISTINCT`. Verified against the real dev database (8 campaigns on `order_placed`, 4 on `customer_registered`, 1 on
`tag_added`, 0 on the other two).

**Not yet built (a different, bigger thing than the above despite the similar name — don't conflate them):**

- Stats for the five fixed **B2B/lifecycle cron triggers** (reorder reminder / offer expiry / credit limit alert / order
  approval / lifecycle win-back — see Phase 2) — sent / response rate / estimated recovered revenue per trigger. This is
  a genuinely different, bigger feature than the campaign-count breakdown above: those five are cron-driven emails, not
  campaign-engine triggers, and "response rate"/"recovered revenue" needs tracking outcomes (did the customer act after
  the email), which nothing in this module logs today.
- **Visual identity system** (logo/mark, color palette, typography, admin menu icon, GitHub social banner) — a full
  brand direction was drafted (dark "engine" aesthetic, Magento-orange + cyan accents, Inter/Plus Jakarta Sans +
  JetBrains Mono) but is a separate, sizeable design effort, not started. Decision pending on which pieces are worth
  building for a solo project (likely: GitHub banner + a simple monochrome menu icon first; branded email templates are
  lower priority).

## Phase 5 — on-site behavior tracking (the missing half of full MA parity)

**Core shipped.** Everything before this phase reacts to server-side data (orders, carts, registration) only. A real MA
platform also tracks anonymous on-site behavior before someone ever converts:

- **First-party visitor cookie** — `view/frontend/web/js/tracker.js` is a dependency-free (no RequireJS/jQuery) snippet,
  issuing an `ordo_visitor_id` cookie on first visit via plain `document.cookie` — genuinely portable to a non-Magento
  site, not just "works as a Magento plugin."
- **Tracking endpoint** — `POST /ordo/track/event` (`Controller\Track\Event`), unauthenticated and CSRF-exempt by design
  (same trust model as any third-party tracking pixel — an anonymous visitor has no session/form key yet). Accepts
  `page_view` / `product_view` / `category_view` with an optional `event_key` (SKU, category id).
- **Identity stitching** — `StitchVisitorIdentity` observer on `customer_login` backfills the visitor's pre-login
  anonymous events with their `customer_id` and immediately re-runs aggregation, so behavior from before login still
  counts.
- **Aggregation → tags, not raw storage** — `VisitorAggregator` turns "viewed category 15 three times" into the tag
  `viewed_category_view_15` in the same long-lived `ordo_customer_tag` table everything else in this module already
  uses — this is what makes on-site behavior usable by the campaign engine (a `tag_added` campaign fires the moment the
  threshold is crossed) without a new condition/action type.

**Scale design — implemented, not just described:** raw events live in their own table, `ordo_visitor_event`,
structurally separate from `ordo_campaign`/`ordo_customer_tag`, and `PruneVisitorEvents` deletes rows older than a
configurable retention window (default 7 days) nightly. Tags derived from those events are unaffected by pruning — only
the raw evidence is discarded, the conclusion stays.

**Known limitations (documented, not hidden):**

- No automatic page-type detection — firing `product_view`/`category_view` with the right key requires the theme to call
  `window.ordoTrack(eventType, eventKey)` on PDP/PLP templates. Only `page_view` fires automatically.
- Tag cardinality tradeoff is explicit, not resolved: tagging by `event_key` (e.g. `viewed_category_view_15`) gives
  precise targeting but an unbounded number of distinct tags on a large catalog. A coarser variant is a deliberate,
  documented option for whoever operates this, not a decision made here.
- No MFTF/API test coverage yet for this phase — tracked in Phase 6 alongside everything else.

**Fixed:** `tracker.js` used to load sitewide regardless of the "tracking enabled" config toggle (the endpoint no-op'd,
but the JS still made a wasted network call every page load). Now gated by `Block\Frontend\TrackerViewModel` — the
`<script>` tag itself is only rendered when `Helper\Config::isTrackingEnabled()` is true, verified live against a real
page.

### On-site channel (popups/banners) — done, including anonymous visitors

Every action used to end in an email, tag, or coupon — nothing ever rendered back on the page itself for the visitor to
see live. Two additions close that gap:

- **Anonymous visitors are now tagged, not just logged-in customers.** `VisitorAggregator::aggregateForVisitor()`
  (parallel to the existing `aggregateForCustomer()`) runs the same threshold aggregation against `ordo_visitor_event`
  for a visitor who has never logged in, writing to a new `ordo_visitor_tag` table via `VisitorTagManager` — previously,
  anonymous behavior only ever got tagged retroactively at login (`StitchVisitorIdentity`), so a visitor who never
  logged in generated no signal at all. A new trigger, `visitor_tag_added`, and a matching `visitor_tag` condition
  (`VisitorHasTag`) let a campaign react to this the same way `tag_added`/`HasTag` already do for customers.
- **A `popup` action type** queues a banner (headline/body/CTA) in a new `ordo_pending_popup` table, targeted at
  whichever identifier the triggering context has (`customer_id` and/or `visitor_id`). Delivery is polling-based, not a
  websocket/SSE push: `tracker.js` gained its first-ever poll loop, checking a new unauthenticated
  `GET /ordo/track/popup` endpoint at a configurable interval (default 15s) and rendering a plain, dependency-free
  banner if one is waiting. The endpoint claims a row via a conditional `UPDATE ... WHERE delivered_at IS NULL` (same
  pattern as `RunScheduledCampaignActions`) so two near-simultaneous polls can never both receive the same popup.
- Deliberately built on MySQL + polling rather than a push-based transport (Redis pub/sub, websockets) — the poll
  interval, not storage speed, is what bounds how "live" this feels, and a hard dependency on infrastructure not every
  Magento install has (Redis for application data, not just cache/session) was judged not worth it for this. Worth
  revisiting only if a genuine sub-second push requirement shows up later.
- `Cron\PrunePendingPopups` deletes delivered rows after a grace window and undelivered-but-expired rows, same
  enforcement role as `PruneVisitorEvents`.

**Verified, not just unit-tested with mocks.** `Test/Integration/CampaignVisitorPopupScenarioTest.php` proves the whole
anonymous-visitor path against a real database: real `ordo_visitor_event` rows aggregating into a real
`ordo_visitor_tag` without ever logging in, a real `visitor_tag_added` dispatch through a real `visitor_tag` condition
into a real `ordo_pending_popup` row, and — since a mocked SQL builder can't prove this — a real conditional
`UPDATE ... WHERE delivered_at IS NULL` against the real table confirming a second claim attempt on the same row affects
zero rows. 480 total tests (464 unit + 16 integration) passing.

**Aggregation itself moved off the request thread.** `VisitorEventLogger` used to run `VisitorAggregator`'s `GROUP BY`/
`HAVING` query (plus any resulting tag writes) synchronously inline in the `/ordo/track/event` and `customer_login`
requests — the same class of problem Phase 7 fixed for `CampaignDispatcher`. A new `ordo.automation.visitor.aggregate`
topic (`Model\Queue\VisitorAggregationPublisher`/`VisitorAggregationConsumer`, mirroring `CampaignDispatchPublisher`/
`Consumer` exactly) means a tracking or login request never waits on it. Found and fixed a small real bug along the way:
`StitchVisitorIdentity` was calling aggregation twice on login. 469 unit + 16 integration tests passing after this
change.

**Popup display-frequency capping — done.** Closes the first bullet of the "Popup targeting refinements" gap below.
`Model\Campaign\Action\ShowPopup` now checks `Helper\Config::getPopupFrequencyCapHours()`
(`ordo_automation/tracking/popup_frequency_cap_hours`, default 24h, 0 disables it) against `ordo_pending_popup` before
queuing a new row — if the same customer/visitor already received a popup within that window, the action is a silent
no-op (not logged as an error; skipping is the intended behavior).
`Model\ResourceModel\PendingPopup\Collection::targetHasRecentlyReceivedPopup()` implements the
OR-across-customer_id/visitor_id lookup, same identifier-matching approach as `addTargetFilter()`. Verified against a
real database in `Test/Integration/CampaignVisitorPopupScenarioTest.php` — a real, already-delivered row inside the cap
window suppresses a subsequent dispatch's popup action.

**Lead scoring (MVP) — done.** Closes the core of the "Lead scoring" gap below, deliberately built as a small addition
to the existing condition/action pool rather than a separate rule engine — the same primitive relationship tags already
have (`add_tag` writes, `tag` reads) now exists for points: a new `ordo_customer_score` table (one row per customer,
`score` accumulated via `INSERT ... ON DUPLICATE KEY UPDATE score = score + VALUES(score)` so concurrent awards never
race on a read-then-write) behind `Model\CustomerScoreManager`, a new `add_points` action (any campaign action chain can
award — or, with a negative value, deduct — points), and a new `score_at_least` condition (gates on the customer's
current running total). Any existing trigger can drive both, e.g. `order_placed` → `add_points` to award points for a
purchase, or a condition-gated campaign that only fires once a customer's score clears a threshold. Verified against a
real database in `CampaignDispatchScenarioTest.php` — one test proves points genuinely accumulate across repeated
dispatches (not overwritten), another proves `score_at_least` is unsatisfied below the threshold and satisfied once the
real accumulated score crosses it. **Deliberately not built in this pass** (see the narrowed gap below):
demographic-attribute scoring rules and a dedicated "campaign fires automatically the instant a threshold is crossed"
push mechanism (today the threshold is checked when *some* trigger fires and a `score_at_least` condition happens to be
on that campaign, not proactively on every point award — the same tradeoff `tag_added`/`visitor_tag_added` made
deliberate and explicit for tags).

**Saved/reusable segments + RFM (MVP) — done.** A segment is a named, reusable set of AND-ed conditions
(`ordo_segment` + `ordo_segment_condition`, mirroring `ordo_campaign`/`ordo_campaign_condition`) evaluated through the
exact same `ConditionPool` campaigns already use — a segment isn't a parallel condition system, it's a saved, named
grouping of the same condition types. `Model\Segment\SegmentMatcher::isCustomerInSegment()` ANDs a segment's conditions
against a customer, fail-closed on both an unknown condition type and (deliberately) on a segment with zero conditions —
an empty AND is vacuously true in boolean logic, but "matches everyone" is almost certainly not what an admin who forgot
to add conditions intended. A new `in_segment` campaign condition (`{"segment_id": "3"}`) lets any campaign react to
segment membership instead of re-declaring the same conditions inline — this is what makes a segment "reusable across
scenarios" rather than a UI-only convenience. Alongside this, three new RFM conditions — `recency_days_at_most`,
`order_frequency_at_least`, `monetary_total_at_least` (`Model\Rfm\RfmCalculator`, reading straight from `sales_order`
the same way `CreditLimitCalculator` reads used credit, no separate ledger) — are usable both standalone on any campaign
*and* inside a segment, so "build an RFM segment" is just adding these three condition types to one saved segment rather
than a bespoke RFM subsystem. A DI note worth recording: `ConditionPool → InSegment → SegmentMatcher → ConditionPool` is
a genuine constructor cycle (`ConditionPool`'s array argument builds every registered condition eagerly, including
`InSegment`); broken with a `ConditionPool\Proxy` injected into `SegmentMatcher` (`etc/di.xml`) so the real pool only
gets built lazily on first use, by which point construction has already finished — verified live against the real DI
container, not just by reasoning about it. Admin CRUD for segments (`ordo/segment/*`) mirrors the campaign admin
controllers/grid, with one deliberate simplification: segment conditions are entered as raw params JSON only, no
per-type dedicated fields like the campaign form's `switcherConfig` has — narrows the diff for this pass; the same
dedicated-field polish campaigns got could be added later if the JSON entry proves too rough in practice. Verified
against a real database in `Test/Integration/SegmentAndRfmScenarioTest.php` — real orders inserted directly into
`sales_order`, RFM conditions checked against them individually, a segment ANDing two RFM conditions matching a
qualifying customer and rejecting a partially-qualifying one, an empty segment matching no one, and a real campaign
dispatch gated by `in_segment` tagging only the customer who actually qualifies. **Deliberately not built in this pass**
(see the narrowed gap below): bulk actions on a segment's current members, a standalone RFM report, and
percentile/quintile-based RFM scoring.

## Phase 7 — dispatch performance at scale

An audit found the campaign engine worked correctly but would not survive real traffic:
`CampaignDispatcher::dispatch()` ran inline inside the triggering request (checkout, registration), issued a fresh DB
query per matched campaign for its conditions and its actions (N+1), re-queried "which campaigns are enabled for this
trigger" from scratch on every single event with no caching, and the scheduled-action cron loaded every past-due row
into memory at once with no batch limit. All four fixed:

- **Async dispatch via Magento's own message queue.** Trigger observers (`DispatchOrderPlacedCampaigns`/
  `DispatchCustomerRegisteredCampaigns`/`DispatchTagAddedCampaigns`)
  no longer call `CampaignDispatcher::dispatch()` directly — they publish onto topic
  `ordo.automation.campaign.dispatch` (`Model\Queue\CampaignDispatchPublisher`), and
  `Model\Queue\CampaignDispatchConsumer` does the actual dispatch off the request thread.
  `etc/{queue,queue_consumer,queue_publisher,queue_topology,communication}.xml` model the topology on Magento's own
  `Magento_MediaGalleryRenditions` pattern (no RabbitMQ required — the default DB-driver queue works, see `AGENTS.md`
  for the `cron_consumers_runner` config needed to run the consumer automatically).
- **N+1 eliminated.** Conditions and actions for every matched campaign are loaded in one query each
  (`addCampaignIdsFilter`, grouped by campaign_id in PHP) instead of one query per campaign.
- **Trigger→campaign lookup is cached** (`CampaignDispatcher::CACHE_TAG`), invalidated on any campaign/trigger save or
  delete (`CampaignRepository`, `CampaignTriggerRepository`,
  `Controller\Adminhtml\Campaign\Save`/`Delete` — the admin form writes via resource models directly, not the
  repository, so it needed its own invalidation call too).
- **Scheduled-action cron now processes in fixed-size batches** (500/batch, capped at 20 batches per run) instead of
  loading every due row into memory at once; logs a warning rather than silently truncating if the cap is hit.

**A real, pre-existing bug this work surfaced (not introduced by it):** `etc/db_schema.xml` had unescaped apostrophes in
four table comments (`ordo_campaign`, `ordo_offer`,
`ordo_order_approval`, `ordo_campaign_scheduled_action`), breaking the generated
`COMMENT='...'` SQL and silently failing `setup:upgrade`'s schema step on every environment — which in turn meant the
new queue topology above could never actually register in the database. Fixed as part of landing this phase;
`setup:upgrade` completes cleanly now.

**End-to-end verification added, not just unit tests with everything mocked** — see Phase 6 below for the full
breakdown, but specifically for this phase: `Test/Integration/` (real DI, real dev database, no rollback) proves the
dispatch engine itself (every condition/action type, delayed-chain cron resume, multi-trigger, the cache and its
invalidation) AND separately proves the queue wiring end to end — a real Magento event fired through
`EventManagerInterface`, through the real observer, onto the real DB queue, consumed by running `bin/magento
queue:consumers:start` as a real subprocess, resulting in a real database side effect. 13/13 integration tests passing,
run twice to rule out ordering flakiness.

**MFTF was actually run, not left as an unverified guess — here's exactly what that found.**
Getting the two new MFTF tests to run at all surfaced four real, pre-existing environment bugs in `magento-ordo-test`
(none of them Magento application bugs — all in how this local dev environment was wired up), all now fixed and
documented in `AGENTS.md`:

1. The `php` container has no persistent webserver (`command: sleep infinity`) — someone has to start `php -S` manually,
   and it was running **single-threaded**, which silently truncates admin page JS/CSS under Selenium's concurrent
   requests. Fixed by always launching it with
   `PHP_CLI_SERVER_WORKERS=8`.
2. `<magentoCLI>` MFTF steps always 404'd — `MAGENTO_CLI_COMMAND_PATH`/`_PARAMETER` were simply never set in
   `dev/tests/acceptance/.env`.
3. Once pointed at the right env var, `<magentoCLI>` steps 404'd differently — the bridge script
   (`dev/tests/acceptance/utils/command.php`) resolves `bin/magento` via a path *relative to PHP's built-in server's
   CWD, which is `docroot + the URL's directory`, not the script's real file location* — meaning the bridge script's
   exposed URL path has to sit at exactly the right nesting depth under `pub/` for its hardcoded `../../../../` to land
   back on the Magento root.
4. `cataloginventory_stock`/`catalog_product_price`/etc. run "Update by Schedule" in this environment, so a product
   created via MFTF's `createData` isn't salable/addable-to-cart until a reindex runs — not obvious until "Add to Cart"
   button silently doesn't exist on the PDP.

**With all four fixed:** `AdminCreateMultiTriggerCampaignTest` and
`AdminCreateCampaignWithConditionsAndActionsTest` (the two admin-only, no-checkout tests) run **green, repeatably**,
through a real browser — trigger/condition/action rows genuinely round-trip through the database and render on the Flow
canvas exactly as a human clicking through would see. `AdminCampaignScenarioEndToEndTest` (the long one: real storefront
checkout → real async queue consumer → real coupon in the admin grid) got progressively further on each retry — full
admin scenario build, login, checkout navigation, action-type selection — **with zero actual logic failures across a
dozen runs**, but never finished a complete pass: this dev host runs many other, unrelated Docker projects competing for
memory (one single unrelated container alone was observed consuming 66% of Docker's memory ceiling), causing `db`/
`opensearch`
to OOM-kill (exit 137) and the Chrome tab under Selenium to crash at an unpredictable point each run. This is a
host-resource-contention problem, not a defect in the module, the test, or the scenario — confirmed by the two shorter
admin-only MFTF tests passing reliably in the exact same environment. To actually get a green run of the full checkout
scenario: free up host memory (stop unrelated Docker projects while running it) or run it on a dedicated/CI machine.

**Not yet done:**

- A confirmed green run of `AdminCampaignScenarioEndToEndTest.xml` specifically (see above — blocked on host memory, not
  on anything left to fix in the test or the code).
- No load/soak test exists yet to put a number on "how many concurrent dispatches" — the fixes above address the *shape*
  of the bottleneck (N+1, synchronous blocking, unbounded cron), not a measured throughput target.

## Phase 6 — test coverage & localization gap

The standards in README's "Quality & testing standards" apply from now on. Honest current state, not a rounded-up claim:

**Unit tests — 351 tests passing, run for real against Magento Open Source 2.4.7** (see `VERIFICATION.md`). Covers every
`Model/`, `Cron/`, `Observer/`, `Controller/`, `Helper/`, `Block/`, `Ui/` class in the module. Coverage percentage last
measured at ~98% class / ~99.5% method before the free-gift/credit-limit-API work landed — not re-measured since, so
treat that specific figure as stale until the next PCOV pass.

**MFTF — 6 tests written, all passing against a real MFTF runtime** (`magento/magento2-functional-testing-framework` +
`selenium/standalone-chrome`, actually stood up and run, not just written): admin campaign creation via the Phase 4
form, admin multi-trigger campaign creation (round-tripping two triggers on one campaign through the database and the
Flow canvas), the admin dashboard, the reorder-cycles diagnostic grid, admin segment creation (name/enabled/one
condition through the segment form, confirmed in the grid and hand-verified in the database), and the storefront tracker
actually setting a real, stable `ordo_visitor_id` cookie in a real browser. Running the multi-trigger scenario for real
caught a genuine regression the canvas work had introduced — a brand-new campaign had no UI path at all to add its first
trigger/condition/action, since the raw dynamicRows fieldsets were hidden in favor of a canvas that only renders for an
*existing* campaign; fixed (see `Test/Mftf/README.md` for detail). **Still missing:** the order-approval round trip end
to end (blocked on the token only ever being delivered by email — no mail-catcher in this environment), the tracking
snippet actually *posting an event* (as opposed to just issuing the cookie, which is now covered), and the free-gift
selection flow. See `Test/Mftf/README.md`.

**API functional tests — full suite written and run for real** against a live instance: Campaigns (full CRUD), Campaign
conditions/actions (full CRUD), Offers (full CRUD + customer self-extend), ReorderCycle (read), CustomerTagManagement
(full round trip), OrderApproval (admin read + anonymous approve/reject-by-token), Free gift (full CRUD +
eligibility/selection, live-verified), Credit limit (admin by-id lookup, 404 for a nonexistent customer, 401 for both
routes unauthenticated and for a customer token on the admin-only route, and the customer-scoped `mine` endpoint
matching the admin lookup figure-for-figure). See `API.md` for the endpoint reference and `Test/Api/README.md` for what
running them found — four real, pre-existing WebAPI defects (missing docblocks, wrong SearchResults typing) plus one
real WebAPI framework bug (scalar-array body param crashing `ParamsOverrider`, worked around with
`FreeGiftSelectionInterface`) that no unit test could have caught.

**PHPStan — level max, zero errors, backlog tracked not hidden.** 211 pre-existing findings — overwhelmingly safe
Magento idioms PHPStan's strict typing flags anyway — were reviewed one by one, not blanket-suppressed: the two
genuinely real issues found were fixed, the rest captured in `phpstan-baseline.neon`. Going forward, `analyse` genuinely
passes clean, so any *new* violation is caught immediately instead of drowned in 211 pre-existing ones. **Still open:**
the free-gift and credit-limit-API code added since hasn't had a fresh PHPStan pass run against it yet.

**i18n — `en_US`/`pl_PL` only**, more locales added on actual demand, not speculatively.


**[Flags](https://docs.codecov.com/docs/flags) and [Components](https://docs.codecov.com/docs/components) — done**
(see `codecov.yml`'s `flag_management`/`component_management`): a single `unittests` flag today
(MFTF/API tests don't upload coverage yet, so there's only one real flag to define), and 7
components grouped by functional area (campaign engine, free gift, segments, B2B triggers, B2C
lifecycle, tracking, admin UI) filtered from that same upload.

**[Test Analytics / failed test reporting](https://docs.codecov.com/docs/test-analytics#failed-test-reporting) — done.**
`coverage.yml` runs PHPUnit with `--log-junit junit.xml` and a separate `codecov/test-results-action` step uploads it
(runs even if the test step itself failed, via `if: ${{ !cancelled() }}`), so flaky/failing tests are tracked over time,
not just line coverage.

**[GitHub Checks](https://docs.codecov.com/docs/github-checks) — done.** Confirmed via the GitHub API that no
Codecov check-run existed on commits before this — a real gap, not something `codecov-action` already did
automatically. `codecov.yml`'s top-level `github_checks: {annotations: true}` turns on the native check plus inline
"not covered" markers on changed lines in the PR diff; `informational: true` on every status means this only adds
visibility, it can never fail the build on its own.

Still flagged for follow-up, not yet wired in:

- **JS bundle analysis** ([docs](https://docs.codecov.com/docs/javascript-bundle-analysis)) — not
  applicable right now: this module's admin/frontend JS is plain RequireJS/AMD served through
  Magento's own static-content pipeline, no Vite/Webpack/Rollup bundler in the loop for the plugin
  to hook into. Revisit only if that ever changes.

### Other tooling/design ideas raised but not yet actioned

- **Reuse the README hero banner's color palette (Deep Logic Magenta / Blueprint Cyan) inside the
  actual admin UI** — specifically the Flow editor's canvas nodes — so the visual identity is
  consistent from the repo down to the product itself, not just marketing.
- **Split the MFTF GitHub Actions pipeline into a matrix by functional area** (Campaign, Segment,
  Dashboard/ReorderCycle, Tracking) using MFTF's own `<group>` tags, so tests run in parallel and
  it's clearer where to add new ones. Deliberately deferred until the single, unified pipeline has
  had at least one successful real run — no point parallelizing something not yet proven to work.
**PHP-CS-Fixer — done.** `.php-cs-fixer.dist.php` layers `@PSR12` plus a handful of safe rules
(unused-import removal, alphabetical import ordering, single quotes, short array syntax) on top of
— not instead of — the Magento2 standard PHPCS already enforces; the one PSR12 rule that actively
conflicted with this codebase's (and Magento2's own) `<?php` immediately followed by
`declare(strict_types=1);` convention (`blank_line_after_opening_tag`) is explicitly disabled.
Wired into `ci.yml`'s `coding-standard` job as `composer cs-check` (`--dry-run --diff`, so CI only
*detects* unformatted code rather than silently rewriting it) right after PHPCS; `composer cs-fix`
is what a contributor runs locally to actually apply it. First real run found only import-ordering
drift across 6 files — fixed as part of landing this.
- **Psalm and/or Infection (mutation testing)** — suggested as options alongside PHPStan early on,
  never decided either way. Psalm would be a second, differently-opinionated static analyzer
  overlapping heavily with PHPStan; Infection would test the tests themselves (does the suite
  actually fail when the code is mutated), which is a genuinely different signal than line
  coverage — worth considering once coverage itself is no longer the active focus.
- **SonarQube Cloud's agent-centric maintenance loop** — [Hunter
  Agent](https://docs.sonarsource.com/agent-centric-development-cycle/in-your-long-living-branches-the-code-maintenance-loop/hunter-agent)
  (proactively finds issues in long-lived branches) and [Remediation
  Agent](https://docs.sonarsource.com/agent-centric-development-cycle/in-your-long-living-branches-the-code-maintenance-loop/remediation-agent)
  (proposes fixes for what Hunter finds) — see the [overview](https://docs.sonarsource.com/sonarqube-cloud)
  for how these fit into the rest of the Sonar setup already in place (Quality Gate, PR
  decoration). Not yet investigated whether these need a paid tier or additional GitHub App
  permissions beyond what's already configured.
- **More [shields.io](https://shields.io/) badges for the README** — candidates already discussed:
  a static "Magento 2.4.x compatible" badge (no dynamic source exists for this), a dynamic GitHub
  open-issues count, a static Dependabot badge, and a static PHPCS/Magento2-coding-standard badge.
  A dynamic release/version badge only makes sense once this repo actually cuts a tagged release.

## Known gaps / still genuinely open

Not failures — not attempted, or explicitly deferred:

- A measured code coverage percentage for the unit suite since the free-gift/credit-limit work (see Phase 6 above).
- MFTF scenario execution for order approval, tracking, and free gift — see the "Still missing" note under Phase 6's
  MFTF paragraph for why each is genuinely blocked (not just unwritten).
- A confirmed green run of `AdminCampaignScenarioEndToEndTest.xml` specifically (Phase 7 —
  `AdminCreateCampaignWithConditionsAndActionsTest.xml` and `AdminCreateMultiTriggerCampaignTest.xml` both run green
  repeatably; this one is blocked on host memory contention, not the test or the code — see Phase 7 for detail).
- Sent/response-rate/recovered-revenue dashboard stats for the five fixed B2B/lifecycle cron triggers (Phase 4) —
  narrower now: campaign-count-by-trigger-event is done, this is the separate, still-open item, see Phase 4.
- **Repo/package health badges for README.md** (Shields.io download/version/CI badges, Packagist stats if ever published
  there, Codecov/Coveralls coverage badges, SonarCloud/Scrutinizer/Code Climate quality scores, Dependabot/Snyk
  dependency-vulnerability scanning) — genuinely useful once (if) this repo is public and/or published as a Composer
  package on Packagist; not wired up now because doing so means deciding to make the repo public and signing up for
  third-party services, both of which are calls for whoever owns that decision, not something to default into.
- **GitHub Actions CI** (a PHP/Composer build-and-test workflow — GitHub's own suggested templates for a PHP or
  Laravel-style project cover the shape needed: install deps, run the unit suite, run PHPStan) — not wired up yet. This
  module's tests currently need a live Magento install to run against (see `AGENTS.md`), so a real CI workflow means
  either standing up a disposable Magento instance in the runner (slow, heavier than most PHP-package CI) or splitting
  out a fast lane that only needs `vendor/` (PHPStan, and any test that doesn't need a live Magento bootstrap) — a real
  decision to make, not a one-line template drop-in.
- Visual identity system (Phase 4).
- `Test/Api/` coverage for the credit-limit endpoints (Phase 6).
- A measured throughput/load number for the Phase 7 dispatch performance work — the architectural bottlenecks are fixed,
  but nothing has put a concurrent-dispatch number on it yet.
- ~~The segment admin grid/form's HTML was never actually browser-rendered~~ — closed:
  `Test/Mftf/Test/AdminCreateSegmentTest.xml` runs a real Selenium session (which doesn't hit the two-factor-auth wall a
  scripted `curl` login did) and passed first run, with the resulting DB row hand-verified. See Phase 6/
  `Test/Mftf/README.md`.

## Gaps vs. a full-market MA platform

Where this module genuinely falls short of a mature, general-purpose marketing automation platform — not a code review,
a capability comparison against the category as a whole. Each of these is a real, separate stream of work, not something
to bolt onto the current campaign engine incidentally:

- **Product recommendations** — dynamic "recommended for you" blocks in email/on-site based on browsing/purchase
  history. We have none; every email is static content.
- **Lead scoring** — the core points model (accumulate, read, gate a campaign on a threshold) is done, see Phase 5
  above. Still missing: demographic-attribute-based scoring rules (as opposed to a campaign author manually wiring
  `add_points` onto whichever trigger they choose), an admin UI for managing scoring rules declaratively (today it's
  just another campaign action — no dedicated "event → points" rule table/grid), and a trigger that fires the instant a
  threshold is crossed rather than being checked opportunistically on whatever trigger already ran (the same tradeoff
  `tag_added`/`visitor_tag_added` made for tags).
- **Popup targeting refinements** — the on-site popup shipped above (Phase 5) targets a visitor/customer identifier and
  a behavioral tag threshold; display-frequency capping per visitor is done (see Phase 5). Still missing: event-driven
  triggers finer than a tag threshold (e.g. "this specific element was clicked", not just "a page/product/category view
  threshold was crossed").
- **Dynamic content blocks** (reusable text/HTML snippets, RSS-driven auto-newsletters, product feed inside a campaign
  email) — not built; every email template today is static.
- **Saved/reusable segments and RFM segmentation** — done as a combined MVP (see the writeup below); a saved segment can
  be built from any condition type, including the new RFM trio. Still missing: bulk actions on a segment (assign tag /
  add note to every current member in one click — today a segment only ever gets *read* one customer at a time, via the
  `in_segment`
  condition during a dispatch, there's no "run this over everyone in the segment right now"
  action), a standalone RFM *report* (a recency/frequency/monetary breakdown of the whole customer base, as opposed to
  condition types you can test one customer against), and quintile/ percentile-based RFM scoring (today's conditions are
  absolute thresholds an admin picks, e.g.
  "at least 3 orders" — not "top 20% by order count", which needs computing distribution across the whole customer base,
  a meaningfully bigger and slower calculation than a per-customer lookup).
- **Multichannel recovery** (SMS/WhatsApp/push, not just email) — `cart_abandoned`/win-back campaigns only ever send
  email today; no other channel is wired into `ActionPool`.

For what's already shipped and stable, see [README.md](README.md).
