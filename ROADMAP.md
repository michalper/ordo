# Roadmap

Ownership split for how this roadmap is being driven: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is scoped from real hands-on marketing automation experience (iPresso-style platforms) — so expect the B2C phases below to grow faster and get more opinionated over time.

This file tracks what's shipped (for the implementation history/context) and what's still open. For the current, stable feature list, see [README.md](README.md); for the full REST API reference, see [API.md](API.md).

## Phase 2 — remaining B2B triggers

**Closed.** Sales-rep-signed emails and the weekly rep digest shipped in v0.4.0 (`SalesRepEmailContext`, `SendSalesRepDigest`).

## Phase 3 — Promotion Builder (adjacent product area, not a trigger)

**Closed.** All items below shipped. A friendlier admin layer over Magento's native `SalesRule` engine — the raw native form (tabs, dropdowns, a condition tree written like code) is the same everywhere, and no store owner enjoys using it.

- **Buy X Get Y free — done, admin config screen only, no new backend logic.** The native `SalesRule` "Buy X Get Y" discount action (`Magento\SalesRule\Model\Rule\Action\Discount\BuyXGetY`) already computes the discount correctly from the native `discount_step` (X) and `discount_amount` (Y, reused as the free quantity for this action) fields — the gap was that neither field's label makes clear what's actually being configured, and there was no way to confirm the resulting offer before saving. `view/adminhtml/ui_component/sales_rule_form.xml` extends Magento_SalesRule's own form (standard UI Component XML merge by node name, not a fork) to add a read-only live preview field (`Ordo_Automation/js/buy-x-get-y-calculator`) next to the native fields: "Buy 3, get 1 free — customers pay for 3 out of every 4 (25% off that batch)." — updating as the admin types, with the same guard as the native calculator (`if (!$x || $y > $x) return no discount`) reflected as a warning instead of a silently-wrong preview. Verified live: loaded a real New Cart Price Rule admin page, confirmed the component and its JS/template assets serve correctly and the form still renders without error.
- **Coupon generated after checkout / for cart recovery** — **done, reframed:** ships as ordinary campaigns on the engine (`order_placed` / `cart_abandoned` → `generate_coupon` → `send_email`) instead of bespoke code per idea.
- **`SendAbandonedCartReminders` now also dispatches a `cart_abandoned` campaign event** — done. The fixed reminder email still always sends (unconditionally, even for guests); a `cart_abandoned` campaign is additionally dispatched for quotes tied to a registered customer, so a store can layer a coupon or a tag onto cart recovery without touching this cron. Guest quotes only get the fixed email — every current campaign condition/action assumes a real `customer_id`.
- **Cheapest item in a bundle free — implemented and now selectable from the admin UI.** `Model\Rule\Action\Discount\CheapestItemFree` (+ `QualifyingSetTracker`) is wired into Magento's `SalesRule` calculator extension point (`CalculatorFactory`'s `discountRules` array — the real extension point in this Magento version, found by actually running the discount and hitting a "no such argument" error; see `VERIFICATION.md` #16) as a new possible `simple_action` value, `ordo_cheapest_item_free`. Because `DiscountInterface::calculate()` only ever sees one item at a time, `QualifyingSetTracker` re-runs the rule's own condition tree across the whole quote the first time any item for that rule is asked about, picks the cheapest match, and caches the answer for the rest of that request — so all the individual per-item calls agree. The native admin "Apply" dropdown is a plain class (`SimpleActionOptionsProvider`, not an interface with a `di.xml` preference), so a `Plugin\SalesRule\SimpleActionOptionsProviderPlugin` (`etc/adminhtml/di.xml`) appends the option — verified live by calling the real, compiled `SimpleActionOptionsProvider::toOptionArray()` and confirming `ordo_cheapest_item_free` is in the returned list, not assumed from reading the plugin code. Re-run against a real checkout as part of this module's own verification (see `VERIFICATION.md`).
- **Free gift above a cart threshold — done, and cascading rather than a single flat threshold.** Correctly a different architecture from `CheapestItemFree` above: this *adds a new line item* to the cart (a product that wasn't there), so it's quote manipulation (`Quote::addProduct()` + `setCustomPrice(0)`), not a `DiscountInterface` calculator. An offer (`FreeGiftOffer`) has a pool of eligible SKUs (`FreeGiftOfferProduct`) and one or more cart-subtotal tiers (`FreeGiftOfferTier`) — every tier the subtotal has reached ADDS its `gift_slots` to the total earned, cumulative across tiers and across every active offer (e.g. tier @100 → +1, tier @300 → +1 gives 2 slots at a 300 subtotal, not just the top tier's 1), so the number of gifts a customer may pick scales with how much they spend instead of being capped at one. Selection is customer/guest self-service (`FreeGiftManagementInterface::getEligibility`/`selectGifts`, ownership-checked the same way as `OfferManagement::selfExtend`) and replaces the cart's gift selection each call. `Observer\TrimExcessFreeGifts` trims the selection back down if the subtotal later drops (e.g. the customer removes a paid item) below what earned it. Marker rows (`ordo_quote_gift_item`) — not price alone — identify which quote items are gifts, so they can be reliably found and removed. See `API.md` for the full endpoint reference.

## Phase 4 — admin UI

**Campaign builder — done.** New "Ordo Automation" top-level admin menu (`etc/adminhtml/menu.xml`) with:
- **Campaigns grid** (`ordo/campaign/index`) — standard `SearchResult`-based admin grid (`Model\ResourceModel\Campaign\Grid\Collection`), filterable by name/trigger event/enabled, with Edit/Delete row actions.
- **Campaign edit form** (`ordo/campaign/edit`) — name, trigger event (dropdown from `TriggerEvent` source), enabled toggle, and two `dynamicRows` sections for conditions and actions. The type dropdowns in both are generated from `ConditionPool::getAvailableTypes()` / `ActionPool::getAvailableTypes()` — i.e. from whatever's actually registered in `di.xml` — so the UI can never drift out of sync with what the dispatcher can resolve. Each condition/action row also has a dedicated field per known type (`tag`, `amount`, `rule_id`, `prefix`, `template`, `message`), shown/hidden via `<switcherConfig>` keyed off the row's `type` select — the raw JSON textarea (`params_json`) is now just the fallback for a type without one yet. End-to-end verified against the real database: saving a `tag` condition through its dedicated field produces `{"tag": "..."}`  in `ordo_campaign_condition.params`.
- **Reorder Cycles grid** (`ordo/reordercycle/index`) — read-only diagnostic view of what `CalculateReorderCycle` has computed (customer, SKU, average interval, next expected date), for verifying a detected cycle looks right without querying the database directly.

**Dashboard — done.** `Ordo Automation` is now a single, flat admin menu entry (no dropdown) — clicking it opens a custom dashboard (`ordo/dashboard/index`, own block/template/CSS, not a UI Component) with campaign stats, nav cards to Campaigns/Reorder Cycles/Free Gift Offers/Configuration, and a campaign grid. Server-rendered from the same collections the grids use — no separate REST/auth story, it lives inside the existing admin session.

**Free gift offers admin UI — done.** `ordo/freegiftoffer/index` (grid, same `SearchResult`-based pattern as campaigns) and `ordo/freegiftoffer/edit` (form: name, enabled toggle, and two `dynamicRows` sections — cascading tiers and gift-pool SKUs, no switcher needed since neither section has per-type fields). `Save.php` follows the same delete-then-reinsert child-row pattern as `Controller\Adminhtml\Campaign\Save`. Closes the gap this module had since the free-gift feature itself shipped (previously REST-API/database-only). End-to-end verified against a real admin session: created an offer with two tiers and one gift SKU through the actual form POST, confirmed all three rows persisted correctly, confirmed the edit page pre-populates them, confirmed delete cascades.

**Campaign flow visualization — read-only preview shipped.** `ordo/campaign/edit` now renders a [Drawflow](https://github.com/jerosoler/Drawflow) (MIT, vendored under `view/adminhtml/web/lib/drawflow/`) canvas above the existing dynamicRows form: `Block\Adminhtml\Campaign\Edit\Flow` reads the same `CampaignCondition`/`CampaignAction` rows the form edits and builds a trigger → conditions → actions node graph server-side (`getFlowDataJson()`), imported into Drawflow in `editor_mode = 'view'`. Deliberately **not** the source of truth — the dynamicRows form and `Save.php` are unchanged, this is purely an additional visualization hooked onto data that already exists. Verified live: loaded a real campaign with a condition and an action, confirmed the exact node/connection JSON, confirmed the JS/CSS assets serve and the page renders without error.

**Full visual editor — done.** The Flow canvas is no longer read-only or click-to-add-only: a palette sidebar (`view/adminhtml/templates/campaign/flow.phtml`) lists every registered trigger/condition/action type as a draggable chip; dragging one onto the canvas creates a node of that exact kind and type at the drop point (`toCanvasPosition()` in `campaign-flow-editor.js` converts the drop's viewport coordinates into Drawflow's internal, zoom/pan-aware canvas space, same formula as Drawflow's own drag-from-menu example), pre-selected rather than defaulting to whichever type happens to sort first. Connections are still drawn by dragging between a node's input/output dots (native Drawflow behavior). "Apply flow to form & Save" writes the whole graph back into the same `triggers[]`/`conditions[]`/`actions[]` structure `Save.php` already accepts — unchanged from before. Verified live: dragging the `order_total_gte` condition chip onto the canvas produces a node pre-set to that type with its dedicated "Minimum order total" field rendered, not a raw JSON textarea.

**Not yet built:**
- **Delay / wait step between actions** — the action chain runs immediately, start to finish; there's no way to say "wait 2 days, then send the next email." Every real MA platform's scenario builder has this (iPresso's included — see `funkcjonalnosci/scenariusze-marketing-automation`: action blocks, conditions, and time delays as first-class steps in the sequence). This is the clearest remaining gap between our engine and a real drip-campaign builder. Open question worth checking before designing it: does iPresso's scenario allow more than one trigger per scenario, or exactly one? Their page lists "Triggery" as a category of block but doesn't say whether a single scenario can start from several of them at once — relevant because we just built multi-trigger support (`CampaignTriggerInterface`) as a deliberate choice, and it's worth confirming whether that's ahead of or just different from the market-standard pattern.
- Stats for the five fixed triggers (reorder/offer/credit/approval/lifecycle) — sent / response rate / estimated recovered revenue per trigger — on the dashboard itself, alongside the campaign stats already there.
- **Visual identity system** (logo/mark, color palette, typography, admin menu icon, GitHub social banner) — a full brand direction was drafted (dark "engine" aesthetic, Magento-orange + cyan accents, Inter/Plus Jakarta Sans + JetBrains Mono) but is a separate, sizeable design effort, not started. Decision pending on which pieces are worth building for a solo project (likely: GitHub banner + a simple monochrome menu icon first; branded email templates are lower priority).

## Phase 5 — on-site behavior tracking (the missing half of "like iPresso")

**Core shipped.** Everything before this phase reacts to server-side data (orders, carts, registration) only. A real MA platform also tracks anonymous on-site behavior before someone ever converts:

- **First-party visitor cookie** — `view/frontend/web/js/tracker.js` is a dependency-free (no RequireJS/jQuery) snippet, issuing an `ordo_visitor_id` cookie on first visit via plain `document.cookie` — genuinely portable to a non-Magento site, not just "works as a Magento plugin."
- **Tracking endpoint** — `POST /ordo/track/event` (`Controller\Track\Event`), unauthenticated and CSRF-exempt by design (same trust model as any third-party tracking pixel — an anonymous visitor has no session/form key yet). Accepts `page_view` / `product_view` / `category_view` with an optional `event_key` (SKU, category id).
- **Identity stitching** — `StitchVisitorIdentity` observer on `customer_login` backfills the visitor's pre-login anonymous events with their `customer_id` and immediately re-runs aggregation, so behavior from before login still counts.
- **Aggregation → tags, not raw storage** — `VisitorAggregator` turns "viewed category 15 three times" into the tag `viewed_category_view_15` in the same long-lived `ordo_customer_tag` table everything else in this module already uses — this is what makes on-site behavior usable by the campaign engine (a `tag_added` campaign fires the moment the threshold is crossed) without a new condition/action type.

**Scale design — implemented, not just described:** raw events live in their own table, `ordo_visitor_event`, structurally separate from `ordo_campaign`/`ordo_customer_tag`, and `PruneVisitorEvents` deletes rows older than a configurable retention window (default 7 days) nightly. Tags derived from those events are unaffected by pruning — only the raw evidence is discarded, the conclusion stays.

**Known limitations (documented, not hidden):**
- No automatic page-type detection — firing `product_view`/`category_view` with the right key requires the theme to call `window.ordoTrack(eventType, eventKey)` on PDP/PLP templates. Only `page_view` fires automatically.
- Tag cardinality tradeoff is explicit, not resolved: tagging by `event_key` (e.g. `viewed_category_view_15`) gives precise targeting but an unbounded number of distinct tags on a large catalog. A coarser variant is a deliberate, documented option for whoever operates this, not a decision made here.
- No MFTF/API test coverage yet for this phase — tracked in Phase 6 alongside everything else.
- **On-site channel (popups/banners/push) is missing** — every current action ends in an email, tag, or coupon; there's no action type that renders something back on the page itself for the visitor to see live (iPresso calls this "real-time marketing" / "Satellite" — see `funkcjonalnosci/real-time-marketing`). We already have the real-time detection half (`VisitorAggregator` tags on the fly, a `tag_added` campaign fires the instant a threshold is crossed) — the missing half is a new action type plus a small frontend piece that polls/receives the fired action and renders it (popup/banner) instead of only sending mail. Worth scoping once the delay-step gap above is closed.

**Fixed:** `tracker.js` used to load sitewide regardless of the "tracking enabled" config toggle (the endpoint no-op'd, but the JS still made a wasted network call every page load). Now gated by `Block\Frontend\TrackerViewModel` — the `<script>` tag itself is only rendered when `Helper\Config::isTrackingEnabled()` is true, verified live against a real page.

## Phase 6 — test coverage & localization gap

The standards in README's "Quality & testing standards" apply from now on. Honest current state, not a rounded-up claim:

**Unit tests — 351 tests passing, run for real against Magento Open Source 2.4.7** (see `VERIFICATION.md`). Covers every `Model/`, `Cron/`, `Observer/`, `Controller/`, `Helper/`, `Block/`, `Ui/` class in the module. Coverage percentage last measured at ~98% class / ~99.5% method before the free-gift/credit-limit-API work landed — not re-measured since, so treat that specific figure as stale until the next PCOV pass.

**MFTF — 4 tests written, all passing against a real MFTF runtime** (`magento/magento2-functional-testing-framework` + `selenium/standalone-chrome`, actually stood up and run, not just written): admin campaign creation via the Phase 4 form, admin multi-trigger campaign creation (round-tripping two triggers on one campaign through the database and the Flow canvas), the admin dashboard, and the reorder-cycles diagnostic grid. Running the multi-trigger scenario for real caught a genuine regression the canvas work had introduced — a brand-new campaign had no UI path at all to add its first trigger/condition/action, since the raw dynamicRows fieldsets were hidden in favor of a canvas that only renders for an *existing* campaign; fixed (see `Test/Mftf/README.md` for detail). **Still missing:** the order-approval round trip end to end (blocked on the token only ever being delivered by email — no mail-catcher in this environment), the tracking snippet in a real browser, and the free-gift selection flow. See `Test/Mftf/README.md`.

**API functional tests — full suite written and run for real** against a live instance: Campaigns (full CRUD), Campaign conditions/actions (full CRUD), Offers (full CRUD + customer self-extend), ReorderCycle (read), CustomerTagManagement (full round trip), OrderApproval (admin read + anonymous approve/reject-by-token), Free gift (full CRUD + eligibility/selection, live-verified). **Still missing:** a `Test/Api/` suite for the credit-limit endpoints (only unit-tested so far). See `API.md` for the endpoint reference and `Test/Api/README.md` for what running them found — four real, pre-existing WebAPI defects (missing docblocks, wrong SearchResults typing) plus one real WebAPI framework bug (scalar-array body param crashing `ParamsOverrider`, worked around with `FreeGiftSelectionInterface`) that no unit test could have caught.

**PHPStan — level max, zero errors, backlog tracked not hidden.** 211 pre-existing findings — overwhelmingly safe Magento idioms PHPStan's strict typing flags anyway — were reviewed one by one, not blanket-suppressed: the two genuinely real issues found were fixed, the rest captured in `phpstan-baseline.neon`. Going forward, `analyse` genuinely passes clean, so any *new* violation is caught immediately instead of drowned in 211 pre-existing ones. **Still open:** the free-gift and credit-limit-API code added since hasn't had a fresh PHPStan pass run against it yet.

**i18n — `en_US`/`pl_PL` only**, more locales added on actual demand, not speculatively.

## Known gaps / still genuinely open

Not failures — not attempted, or explicitly deferred:
- A measured code coverage percentage for the unit suite since the free-gift/credit-limit work (see Phase 6 above).
- MFTF scenario execution for order approval, tracking, and free gift (no MFTF runtime currently stood up in this pass).
- Dashboard stats per fixed trigger (Phase 4).
- Visual identity system (Phase 4).
- `Test/Api/` coverage for the credit-limit endpoints (Phase 6).

## Gaps vs. a full-market MA platform (iPresso-class enterprise features)

Checked against iPresso's own feature pages (`funkcjonalnosci/scenariusze-marketing-automation`,
`funkcjonalnosci/real-time-marketing`, `enterprise/e-commerce`, `funkcjonalnosci/content-automation`,
`funkcjonalnosci/scoring`, `funkcjonalnosci/pop-up`, `funkcjonalnosci/segmentacja`) to see where
this module genuinely falls short of a mature, general-purpose MA platform — not a code review, a
market comparison. Each of these is a real, separate stream of work, not something to bolt onto
the current campaign engine incidentally:

- **Product recommendations** — dynamic "recommended for you" blocks in email/on-site based on
  browsing/purchase history. We have none; every email is static content.
- **Lead scoring** — iPresso supports demographic scoring (points for attributes), behavioral
  scoring (points per event — page visit, email link click), and custom scoring plans, with
  automations triggered by crossing a point threshold. We only have binary tags
  (`ordo_customer_tag` — has it or doesn't), no point accumulation or threshold model at all;
  this is a bigger gap than "no scoring" sounds — it's a genuinely different data model
  (`ordo_customer_score` type table, plus rules mapping event → points, and a threshold check in
  the condition pool) than tags are.
- **Popups** — iPresso's popup product is specifically event-driven ("Pop-upy wywoływane
  zdarzeniami to unikat na rynku" — triggered by a specific on-page click, not just page load),
  with display-frequency capping and page-level targeting. This is the same on-site-channel gap
  already tracked in Phase 5's known limitations, called out again here with more shape: it needs
  at minimum a trigger condition ("element clicked") beyond the page/product/category views we
  track today, plus frequency capping per visitor.
- **Dynamic content blocks** (reusable text/HTML snippets, RSS-driven auto-newsletters, product
  feed inside a campaign email) — not built; every email template today is static.
- **Saved/reusable segments** — iPresso lets a segment (built from attributes + behavior) be
  saved once and reused across scenarios/notifications, plus bulk actions on a segment (assign
  tag, add note). We have no standalone segment entity — conditions are inline, per-campaign,
  not a reusable named thing. This overlaps with the RFM gap below: a saved-segment feature would
  be the natural place to add RFM-style segmentation too, rather than two separate builds.
- **RFM segmentation** — a dedicated recency/frequency/monetary report and segment-by-RFM
  workflow. Our "segmentation" today is just campaign conditions (`has_tag`,
  `order_total_gte`), not a standalone RFM engine or report.
- **Multichannel recovery** (SMS/WhatsApp/push, not just email) — `cart_abandoned`/win-back
  campaigns only ever send email today; no other channel is wired into `ActionPool`.
- **Delay/wait step and on-site channel** — tracked above (Phase 4 "Not yet built" and Phase 5
  known limitations respectively); listed here again only for visibility, not duplicated as new
  items.

For what's already shipped and stable, see [README.md](README.md).
