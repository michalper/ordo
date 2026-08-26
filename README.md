# Ordo Automation for Magento 2

Marketing automation that runs *inside* **stock Magento Open Source** — no Adobe Commerce B2B license, no external MA subscription (Klaviyo, iPresso, SalesManago...). Every trigger is computed from data Magento already has (orders, quotes, customers, carts), or from a small first-party data model added alongside it.

The goal is for this module to be a genuine substitute for a general-purpose MA platform on a Magento store — not just a handful of B2B-flavored add-ons — covering both classic B2C lifecycle automation and the B2B triggers most external MA tools structurally can't see.

## Why not just install Klaviyo/iPresso/SalesManago?

They're strong on channels (email/SMS/push) and campaign UI, but they only see what a store exports through a generic connector — order totals, cart events, product views. They don't compute purchase-cycle patterns, don't know about quote expiry, credit limits, or company hierarchies, and their B2C behavioral tracking runs through a JS snippet + cookie that's completely decoupled from server-side order data. Ordo Automation runs inside Magento itself, so triggers can combine both without an integration layer.

## Features (v0.8)

**B2B**
- **Reorder reminders** — detects a recurring purchase pattern per customer/SKU from order history and emails a reminder before the predicted next order date.
- **Offer/quote expiry reminders** — a first-party quote entity (`ordo_offer`) with a *proactive* "expires in N days" reminder — every established B2B platform we checked (Adobe Commerce B2B, OroCommerce) only notifies reactively, after a status change.
- **Credit limit alerts** — a customer credit-limit attribute + a cron warning at a configurable threshold (default 80%) before the account gets blocked, instead of only reacting once it's already over.
- **Order approval workflow** — an optional per-customer spend limit + approval-admin email; orders above the limit are held (status `Pending Approval`, same "new" state so inventory reservation is untouched) and the admin gets a token-based approve/reject email link, no login required. Unresolved approvals get escalated (resent, capped) after a configurable number of days.

**B2C**
- **Abandoned cart recovery** — finds inactive carts above a configurable subtotal threshold and sends a recovery email, capped per cart.
- **Welcome email** — fires on `customer_register_success`, tags the customer `new_customer`.
- **Win-back / re-engagement email** — nightly tagging of customers inactive for N days, followed by a one-time win-back email; tags clear themselves automatically once the customer orders again.

**Shared foundation**
- **Behavioral tagging** (`CustomerTagManager`) — the segmentation primitive every trigger above reads or writes (`new_customer`, `inactive`, `win_back_sent`, ...), the same pattern general MA platforms build campaign targeting on.
- **Sales-rep signature** (`SalesRepEmailContext`) — every automated email above is signed by the customer's assigned rep when one is set, falling back to the store name. A weekly digest also groups inactive customers by rep.
- **Campaign engine** (`ordo_campaign` + `ordo_campaign_condition` + `ordo_campaign_action`) — a genuinely configurable "when X happens and Y is true, do Z" rule engine, not a hardcoded cron per idea. Conditions and actions are plug-ins registered in `di.xml` (`Model\Campaign\ConditionPool` / `ActionPool`) against `Api\Campaign\ConditionInterface` / `ActionInterface` — adding a new condition or action type is a new class + one `di.xml` line, never a change to the dispatcher. Ships with two conditions (`tag`, `order_total_gte`) and three actions (`add_tag`, `send_email`, `generate_coupon`), triggered on `order_placed`, `customer_registered`, and `tag_added` (the last fired as a Magento event by `CustomerTagManager`, not a direct call, to avoid a DI cycle with the `tag` condition). Exposed as a full service contract (`CampaignRepositoryInterface`) with REST endpoints under `/V1/ordo/campaigns`.

All of it is configurable under **Stores → Configuration → Ordo Automation** (or, for campaigns, via the `ordo_campaign*` tables / REST API — no dedicated admin grid yet, see Phase 4), each feature with its own on/off switch and its own cron job (see `etc/crontab.xml`).

**A note on scope:** the campaign engine intentionally works on structured, low-frequency events (orders, registrations, tag changes) — a handful of rows per customer, not per click. Raw high-frequency on-site behavior tracking (Phase 5, page views / clicks via a JS snippet) is a different scale problem and is *not* meant to be stored as one row per event in this same schema — see Phase 5 for why, and how that's meant to feed into the tag system instead of bypassing it.

## Architecture

```
etc/
  module.xml, di.xml, crontab.xml, db_schema.xml, events.xml, email_templates.xml, acl.xml, webapi.xml
  adminhtml/system.xml          — store configuration
  frontend/routes.xml           — /ordo/approval/* (token-based, no login)
Api/, Api/Data/                 — service contracts: Offer*, Campaign*, Campaign/ConditionInterface, Campaign/ActionInterface
Cron/
  CalculateReorderCycle.php, SendReorderReminders.php
  SendAbandonedCartReminders.php
  SendOfferExpiryReminders.php, ExpireOverdueOffers.php
  SendCreditLimitAlerts.php
  TagInactiveCustomers.php, SendWinBackEmails.php
  EscalateStalePendingApprovals.php
  SendSalesRepDigest.php
Observer/
  SendWelcomeEmail.php                        — customer_register_success
  HoldOrderForApproval.php                    — sales_order_place_after
  DispatchOrderPlacedCampaigns.php            — sales_order_place_after
  DispatchCustomerRegisteredCampaigns.php     — customer_register_success
  DispatchTagAddedCampaigns.php               — ordo_customer_tag_added (custom event)
Controller/Approval/            — Approve.php, Reject.php (token-based frontend actions)
Model/, Model/ResourceModel/     — ordo_reorder_cycle, ordo_offer, ordo_customer_tag, ordo_order_approval, ordo_campaign(_condition/_action)
Model/Campaign/                  — ConditionPool, ActionPool, Condition/*, Action/* (the plug-in registry)
Model/CampaignDispatcher.php     — "trigger event + context in, matching campaigns run out"
Model/Rule/Action/Discount/      — CheapestItemFree (custom SalesRule calculator), QualifyingSetTracker
Controller/Adminhtml/Campaign/, ReorderCycle/ — admin grid/form controllers
Block/Adminhtml/Campaign/Edit/   — toolbar button blocks (Back/Delete/Save & Continue)
Ui/Component/Listing/Column/     — CampaignActions (Edit/Delete row links)
view/adminhtml/ui_component/     — ordo_campaign_listing, ordo_campaign_form, ordo_reorder_cycle_listing
Model/CreditLimitCalculator.php  — used-credit derived from open sales_order.total_due
Model/CustomerTagManager.php     — add/remove/check/list-by-tag; fires ordo_customer_tag_added
Model/CouponGenerator.php        — mints a single-use SalesRule coupon code
Model/SalesRepEmailContext.php   — shared email signature block
Setup/Patch/Data/                — customer attributes (credit/spend limit, approval admin email, sales rep), Pending Approval order status
Helper/Config.php                — typed access to system.xml values
view/frontend/email/             — email templates
Controller/Track/Event.php       — public, CSRF-exempt tracking endpoint
Model/VisitorEventLogger.php     — writes ordo_visitor_event, triggers aggregation when identity is known
Model/VisitorAggregator.php      — raw events → ordo_customer_tag threshold-crossing tags
view/frontend/web/js/tracker.js  — dependency-free visitor cookie + event snippet
Test/Unit/                       — PHPUnit tests (seed coverage, see Phase 6)
i18n/                            — translation CSVs (en_US, pl_PL)
```

## Install

```bash
composer require ordo/module-automation
bin/magento module:enable Ordo_Automation
bin/magento setup:upgrade
bin/magento cache:flush
```

## Quality & testing standards (project rule, not aspirational)

These are binding rules for this repo going forward, not a someday-wishlist — every new class added after this point should meet them, and existing code is being brought up to the same bar incrementally (tracked in Roadmap → Phase 6):

- **Static analysis: PHPStan at `level: max`**, configured in `phpstan.neon` with the [bitexpert/phpstan-magento](https://github.com/bitexpert/phpstan-magento) extension so Magento's magic (factories, proxies, `__()` translation, EAV magic getters) doesn't produce false positives. Runs as `require-dev` only — never ships to a production install.
- **Unit tests (PHPUnit)** for every class with non-trivial logic — `Model/`, `Cron/`, `Helper/`, `Controller/`. `Test/Unit/Model/SalesRepEmailContextTest.php` is the seed test establishing the mocking pattern (`createMock` on interfaces, no real Magento bootstrap).
- **MFTF (Magento Functional Testing Framework)** end-to-end coverage for every customer- and admin-facing flow: placing an order over the spend limit → email → approve/reject link → order status change; a customer self-extending an expiring offer; etc. Per Adobe's [MFTF getting-started guide](https://developer.adobe.com/commerce/testing/functional-testing-framework/getting-started).
- **API tests** for every service contract in `Api/` (currently `OfferRepositoryInterface`) — exercised the same way Magento's own `dev/tests/api-functional` suite exercises core APIs.
- **Target: 100% code coverage.** Explicitly a target, not yet a claim — see Phase 6 for what's covered today vs. outstanding. No class should be added without an accompanying test from this point forward, so the gap only shrinks.

## Localization

Admin-facing labels (`system.xml`, customer attribute labels) are translatable via standard Magento i18n CSV files in `i18n/`, keyed off `en_US.csv` as the source. Currently shipped: `en_US`, `pl_PL`. Contributions/requests for additional locales are tracked in Phase 6 — the goal is to cover every language relevant to the store's actual customer base, not just a token second locale.

## Roadmap

Ownership split for how this roadmap is being driven: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is scoped from real hands-on marketing automation experience (iPresso-style platforms) — so expect the B2C phases below to grow faster and get more opinionated over time.

### Phase 2 — remaining B2B triggers

**Closed.** Sales-rep-signed emails and the weekly rep digest shipped in v0.4.0 (`SalesRepEmailContext`, `SendSalesRepDigest`).

### Phase 3 — Promotion Builder (adjacent product area, not a trigger)

A friendlier admin layer over Magento's native `SalesRule` engine — the raw native form (tabs, dropdowns, a condition tree written like code) is the same everywhere, and no store owner enjoys using it.

- **Buy X Get Y free** — already 100% native Magento (`SalesRule` "Buy X Get Y" discount action); needs only a human-friendly config screen with a live calculator (no admin UI built yet — see Phase 4), not new backend logic.
- **Coupon generated after checkout / for cart recovery** — **done, reframed:** ships as ordinary campaigns on the engine (`order_placed` / `cart_abandoned` → `generate_coupon` → `send_email`) instead of bespoke code per idea.
- **`SendAbandonedCartReminders` now also dispatches a `cart_abandoned` campaign event** — done. The fixed reminder email still always sends (unconditionally, even for guests); a `cart_abandoned` campaign is additionally dispatched for quotes tied to a registered customer, so a store can layer a coupon or a tag onto cart recovery without touching this cron. Guest quotes only get the fixed email — every current campaign condition/action assumes a real `customer_id`.
- **Cheapest item in a bundle free — implemented, not yet integration-tested.** `Model\Rule\Action\Discount\CheapestItemFree` (+ `QualifyingSetTracker`) is wired into Magento's own `SalesRule` calculator extension point (`Magento\SalesRule\Model\Validator`'s `calculators` array, the same mechanism "Buy X Get Y" uses internally) as a new possible `simple_action` value, `ordo_cheapest_item_free`. Because `DiscountInterface::calculate()` only ever sees one item at a time, `QualifyingSetTracker` re-runs the rule's own condition tree across the whole quote the first time any item for that rule is asked about, picks the cheapest match, and caches the answer for the rest of that request — so all the individual per-item calls agree. **Known limitation, not a bug:** the native admin "Apply" dropdown is a hardcoded option list in a core block, so this rule type isn't selectable through the UI yet (only via direct rule data / the REST API) — fixing that needs a plugin on that core block, tracked as a Phase 4 admin-UI item. **Verification status:** implemented against the documented, stable `DiscountInterface` contract and a technique used by comparable open-source extensions, but has not been run against a real Magento checkout — first MFTF scenario to write in Phase 6, before anyone should rely on it in production.
- **Free gift above a cart threshold** — still not built. Architecturally different from the item above: it needs to *add a new line item* to the cart (a product that wasn't there), not just discount an existing one — that's quote manipulation (`Magento\Quote\Api\CartItemRepositoryInterface` or an observer on total collection that injects the item), not a `DiscountInterface` calculator. Don't reach for the same pattern as `CheapestItemFree` here; it's the wrong tool for this one.

### Phase 5 — on-site behavior tracking (the missing half of "like iPresso")

**Core shipped.** Everything before this phase reacts to server-side data (orders, carts, registration) only. A real MA platform also tracks anonymous on-site behavior before someone ever converts:

- **First-party visitor cookie** — `view/frontend/web/js/tracker.js` is a dependency-free (no RequireJS/jQuery) snippet, issuing an `ordo_visitor_id` cookie on first visit via plain `document.cookie` — genuinely portable to a non-Magento site, not just "works as a Magento plugin."
- **Tracking endpoint** — `POST /ordo/track/event` (`Controller\Track\Event`), unauthenticated and CSRF-exempt by design (same trust model as any third-party tracking pixel — an anonymous visitor has no session/form key yet). Accepts `page_view` / `product_view` / `category_view` with an optional `event_key` (SKU, category id).
- **Identity stitching** — `StitchVisitorIdentity` observer on `customer_login` backfills the visitor's pre-login anonymous events with their `customer_id` and immediately re-runs aggregation, so behavior from before login still counts.
- **Aggregation → tags, not raw storage** — `VisitorAggregator` turns "viewed category 15 three times" into the tag `viewed_category_view_15` in the same long-lived `ordo_customer_tag` table everything else in this module already uses — this is what makes on-site behavior usable by the campaign engine (a `tag_added` campaign fires the moment the threshold is crossed) without a new condition/action type.

**Scale design — implemented, not just described:** raw events live in their own table, `ordo_visitor_event`, structurally separate from `ordo_campaign`/`ordo_customer_tag`, and `PruneVisitorEvents` deletes rows older than a configurable retention window (default 7 days) nightly. Tags derived from those events are unaffected by pruning — only the raw evidence is discarded, the conclusion stays. This is the concrete implementation of the "scale note" from the previous version of this README, not a promise deferred further.

**Known limitations (documented, not hidden):**
- No automatic page-type detection — firing `product_view`/`category_view` with the right key requires the theme to call `window.ordoTrack(eventType, eventKey)` on PDP/PLP templates. Only `page_view` fires automatically.
- `tracker.js` loads sitewide (`view/frontend/layout/default.xml`) regardless of the "tracking enabled" config toggle — the endpoint no-ops when disabled, but the JS still makes a wasted network call every page load. Fixing this needs a config-aware Block instead of a bare `<script>` tag.
- Tag cardinality tradeoff is explicit, not resolved: tagging by `event_key` (e.g. `viewed_category_view_15`) gives precise targeting but an unbounded number of distinct tags on a large catalog. A coarser variant is a deliberate, documented option for whoever operates this, not a decision made here.
- No MFTF/API test coverage yet for this phase — tracked in Phase 6 alongside everything else.

### Phase 6 — test coverage & localization gap

The standards in "Quality & testing standards" above apply from now on. Honest current state, not a rounded-up claim:

**Unit tests — 6 files, 24/24 passing, run for real against Magento Open Source 2.4.7** (see `VERIFICATION.md`, verified 2026-08-25). This caught two real bugs on the first run — `QualifyingSetTracker` calling a nonexistent `Rule::getRuleId()` and `SalesRepEmailContext` calling `StoreInterface::getFrontendName()` (only on the concrete `Store` class, not the interface) — both fixed, both now covered.
- `SalesRepEmailContextTest`, `ConditionPoolTest`, `OrderTotalAtLeastTest`, `HasTagTest`, `AddTagTest`, `QualifyingSetTrackerTest` — all pass against real `magento/framework`/`magento/module-sales`/`magento/module-quote` classes, not mocks-of-mocks.
- **Still missing:** `CreditLimitCalculator`, `CustomerTagManager`, `CampaignDispatcher` (the dispatch/condition-AND/action-order logic itself — the pool tests only cover the plug-in registry), `VisitorAggregator`, and every `Cron/`/`Observer/`/`Controller/` class's `execute()` logic.

**MFTF — 1 test written (`AdminCreateCampaignTest.xml`), not run.** Covers admin campaign creation via the Phase 4 form — currently blocked by an open dynamicRows rendering bug in that same form, see `VERIFICATION.md` section 3. Still missing: the dispatcher actually firing on a real checkout, offer self-extension, credit-limit checkout behavior, the tracking snippet in a real browser. See `Test/Mftf/README.md`.

**API functional tests — none written yet** for `OfferRepositoryInterface` or `CampaignRepositoryInterface`, following Magento's own `dev/tests/api-functional` conventions.

**PHPStan — runs for real now** (verified 2026-08-25). The shipped `phpstan.neon` never actually worked before this pass — missing `includes:` for the bitexpert extension and a wrong parameter key (`magento_root` vs `magentoRoot`) meant it refused to start. Fixed; it now reports 183 real level-max findings (overwhelmingly missing iterable value types on `array` params/returns) — a real backlog, not fixed in this pass, tracked in `VERIFICATION.md`.

**Coverage — no number exists.** "100% target" is a stated goal with 6 test files against ~50 non-trivial classes, not a coverage report. Don't claim a percentage until a coverage tool has actually run.

**i18n — `en_US`/`pl_PL` only**, more locales added on actual demand, not speculatively.

## Trying this for real

**Verified against Magento Open Source 2.4.7 on 2026-08-25** (Docker, Magento cloned
from GitHub — no Adobe Marketplace keys needed). Install, `setup:upgrade`,
`setup:di:compile`, and the admin panel/login all work; PHPStan and the unit suite
both run for real and pass/report cleanly (183 PHPStan findings still open, see
below). Twelve real bugs were found and fixed along the way — full list in
`VERIFICATION.md`.

**What's still open:** the Campaign admin form's `dynamicRows` sections (conditions/
actions) don't visibly render fields yet — narrowed down to a Knockout
initialization issue, not a PHP-level bug, but not root-caused. This blocks
actually creating a campaign through the UI, which in turn blocks the B2B trigger
walkthrough (section 4), campaign engine end-to-end (section 5), Promotion Builder
(section 6), and on-site tracking (section 7) checklist items — none of those have
been exercised against a live instance yet.

**Full step-by-step checklist:** [VERIFICATION.md](VERIFICATION.md) — covers install, static analysis, and a manual walkthrough of every feature in this README, organized so a failure at any step points at exactly what to fix next.

### Phase 4 — admin UI

**Campaign builder — done.** New "Ordo Automation" top-level admin menu (`etc/adminhtml/menu.xml`) with:
- **Campaigns grid** (`ordo/campaign/index`) — standard `SearchResult`-based admin grid (`Model\ResourceModel\Campaign\Grid\Collection`), filterable by name/trigger event/enabled, with Edit/Delete row actions.
- **Campaign edit form** (`ordo/campaign/edit`) — name, trigger event (dropdown from `TriggerEvent` source), enabled toggle, and two `dynamicRows` sections for conditions and actions. The type dropdowns in both are generated from `ConditionPool::getAvailableTypes()` / `ActionPool::getAvailableTypes()` — i.e. from whatever's actually registered in `di.xml` — so the UI can never drift out of sync with what the dispatcher can resolve. **Deliberate MVP simplification:** each condition/action row has one `type` dropdown and one raw JSON textarea (`params_json`) instead of dedicated, type-specific fields (e.g. a proper "pick a tag" field instead of typing `{"tag": "vip"}` by hand). Building per-type dynamic field sets is real additional UI work — tracked below, not silently skipped.
- **Reorder Cycles grid** (`ordo/reordercycle/index`) — read-only diagnostic view of what `CalculateReorderCycle` has computed (customer, SKU, average interval, next expected date), for verifying a detected cycle looks right without querying the database directly.

**Dashboard — done.** `Ordo Automation` is now a single, flat admin menu entry (no dropdown) — clicking it opens a custom dashboard (`ordo/dashboard/index`, own block/template/CSS, not a UI Component) with campaign stats, nav cards to Campaigns/Reorder Cycles/Configuration, and a campaign grid. Server-rendered from the same collections the grids use — no separate REST/auth story, it lives inside the existing admin session.

**Not yet built:**
- Per-type condition/action fields (replacing the raw JSON textarea) — e.g. `HasTag`'s params should be a tag autocomplete, not free text.
- Stats for the five fixed triggers (reorder/offer/credit/approval/lifecycle) — sent / response rate / estimated recovered revenue per trigger — on the dashboard itself, alongside the campaign stats already there.
- A live calculator for the native "Buy X Get Y" rule type (see Phase 3) — nothing custom to build backend-wise, just a friendlier config screen.
- **Visual identity system** (logo/mark, color palette, typography, admin menu icon, GitHub social banner) — a full brand direction was drafted (dark "engine" aesthetic, Magento-orange + cyan accents, Inter/Plus Jakarta Sans + JetBrains Mono) but is a separate, sizeable design effort, not started. Decision pending on which pieces are worth building for a solo project (likely: GitHub banner + a simple monochrome menu icon first; a custom visual campaign builder and branded email templates are lower priority).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 (same as Magento core).
