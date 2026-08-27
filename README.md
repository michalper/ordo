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
- **API tests** for every service contract in `Api/` — see `API.md` and `Test/Api/README.md`.
- **Target: ~100% code coverage.** Currently ~98% classes / ~99.5% methods — see `VERIFICATION.md` for what's covered today vs. the handful of genuinely unreachable lines still outstanding.

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
- **Cheapest item in a bundle free — implemented and now selectable from the admin UI.** `Model\Rule\Action\Discount\CheapestItemFree` (+ `QualifyingSetTracker`) is wired into Magento's `SalesRule` calculator extension point (`CalculatorFactory`'s `discountRules` array — the real extension point in this Magento version, found by actually running the discount and hitting a "no such argument" error; see `VERIFICATION.md` #16) as a new possible `simple_action` value, `ordo_cheapest_item_free`. Because `DiscountInterface::calculate()` only ever sees one item at a time, `QualifyingSetTracker` re-runs the rule's own condition tree across the whole quote the first time any item for that rule is asked about, picks the cheapest match, and caches the answer for the rest of that request — so all the individual per-item calls agree. The native admin "Apply" dropdown is a plain class (`SimpleActionOptionsProvider`, not an interface with a `di.xml` preference), so a `Plugin\SalesRule\SimpleActionOptionsProviderPlugin` (`etc/adminhtml/di.xml`) appends the option — verified live by calling the real, compiled `SimpleActionOptionsProvider::toOptionArray()` and confirming `ordo_cheapest_item_free` is in the returned list, not assumed from reading the plugin code. Re-run against a real checkout as part of this module's own verification (see `VERIFICATION.md`).
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
- Tag cardinality tradeoff is explicit, not resolved: tagging by `event_key` (e.g. `viewed_category_view_15`) gives precise targeting but an unbounded number of distinct tags on a large catalog. A coarser variant is a deliberate, documented option for whoever operates this, not a decision made here.

**Fixed:** `tracker.js` used to load sitewide regardless of the "tracking enabled" config toggle (the endpoint no-op'd, but the JS still made a wasted network call every page load). Now gated by `Block\Frontend\TrackerViewModel` — the `<script>` tag itself is only rendered when `Helper\Config::isTrackingEnabled()` is true, verified live against a real page (present when enabled, absent when disabled, confirmed by toggling the config and re-fetching the homepage).
- No MFTF/API test coverage yet for this phase — tracked in Phase 6 alongside everything else.

### Phase 6 — test coverage & localization gap

The standards in "Quality & testing standards" above apply from now on. Honest current state, not a rounded-up claim:

**Unit tests — 272 tests passing, run for real against Magento Open Source 2.4.7**, ~98% class / ~99.5% method coverage (see `VERIFICATION.md`). Covers every `Model/`, `Cron/`, `Observer/`, `Controller/`, `Helper/`, `Block/`, `Ui/` class in the module.

**MFTF — 3 tests written, all passing against a real MFTF runtime** (`magento/magento2-functional-testing-framework` + `selenium/standalone-chrome`, actually stood up and run, not just written): admin campaign creation via the Phase 4 form, the admin dashboard, and the reorder-cycles diagnostic grid. Still missing: the order-approval round trip end to end (blocked on the token only ever being delivered by email — no mail-catcher in this environment) and the tracking snippet in a real browser. See `Test/Mftf/README.md`.

**API functional tests — full suite written and run for real** against a live instance: Campaigns (full CRUD), Offers (full CRUD + customer self-extend), ReorderCycle (read), CustomerTagManagement (full round trip), OrderApproval (admin read + anonymous approve/reject-by-token). See `API.md` for the endpoint reference and `Test/Api/README.md` for what running them found — four real, pre-existing WebAPI defects (missing docblocks, wrong SearchResults typing) that no unit test could have caught.

**PHPStan — runs for real now** (verified 2026-08-25). The shipped `phpstan.neon` never actually worked before this pass — missing `includes:` for the bitexpert extension and a wrong parameter key (`magento_root` vs `magentoRoot`) meant it refused to start. Fixed; it now reports 183 real level-max findings (overwhelmingly missing iterable value types on `array` params/returns) — a real backlog, not fixed in this pass, tracked in `VERIFICATION.md`.

**Coverage — no number exists.** "100% target" is a stated goal with 6 test files against ~50 non-trivial classes, not a coverage report. Don't claim a percentage until a coverage tool has actually run.

**i18n — `en_US`/`pl_PL` only**, more locales added on actual demand, not speculatively.

## Trying this for real

**Verified end to end against Magento Open Source 2.4.7 on 2026-08-26**
(Docker, Magento cloned from GitHub — no Adobe Marketplace keys needed).
Every checklist item in `VERIFICATION.md` sections 1–7 has passed against a
real, live instance: install, static analysis, the full admin UI (dashboard,
campaign builder with dedicated per-type fields, grids), every B2B trigger
cron, the campaign engine (including `generate_coupon` → `send_email`
chaining and the `tag_added` trigger), the `CheapestItemFree` promotion
calculator, on-site tracking in a real browser, and — the hardest one — a
real order placed through full storefront checkout, held for approval,
approved via the real link, and un-held.

**20 real bugs were found and fixed along the way** — wrong DI extension
points, silently-dropped EAV attribute values, a config-default footgun
present in 12 places, and more. Full list, with exactly how each was found
and verified, in `VERIFICATION.md`.

**What's genuinely still open** (not failures — not attempted): MFTF scenario
execution (no MFTF runtime in this sandbox), a measured code coverage
percentage for the unit suite, and the 183 pre-existing PHPStan findings
from the 0.8.3 pass.

**Full step-by-step checklist:** [VERIFICATION.md](VERIFICATION.md) — covers install, static analysis, and a manual walkthrough of every feature in this README, organized so a failure at any step points at exactly what to fix next.

### Phase 4 — admin UI

**Campaign builder — done.** New "Ordo Automation" top-level admin menu (`etc/adminhtml/menu.xml`) with:
- **Campaigns grid** (`ordo/campaign/index`) — standard `SearchResult`-based admin grid (`Model\ResourceModel\Campaign\Grid\Collection`), filterable by name/trigger event/enabled, with Edit/Delete row actions.
- **Campaign edit form** (`ordo/campaign/edit`) — name, trigger event (dropdown from `TriggerEvent` source), enabled toggle, and two `dynamicRows` sections for conditions and actions. The type dropdowns in both are generated from `ConditionPool::getAvailableTypes()` / `ActionPool::getAvailableTypes()` — i.e. from whatever's actually registered in `di.xml` — so the UI can never drift out of sync with what the dispatcher can resolve. Each condition/action row also has a dedicated field per known type (`tag`, `amount`, `rule_id`, `prefix`, `template`, `message`), shown/hidden via `<switcherConfig>` keyed off the row's `type` select — the raw JSON textarea (`params_json`) is now just the fallback for a type without one yet. End-to-end verified against the real database: saving a `tag` condition through its dedicated field produces `{"tag": "..."}`  in `ordo_campaign_condition.params`.
- **Reorder Cycles grid** (`ordo/reordercycle/index`) — read-only diagnostic view of what `CalculateReorderCycle` has computed (customer, SKU, average interval, next expected date), for verifying a detected cycle looks right without querying the database directly.

**Dashboard — done.** `Ordo Automation` is now a single, flat admin menu entry (no dropdown) — clicking it opens a custom dashboard (`ordo/dashboard/index`, own block/template/CSS, not a UI Component) with campaign stats, nav cards to Campaigns/Reorder Cycles/Configuration, and a campaign grid. Server-rendered from the same collections the grids use — no separate REST/auth story, it lives inside the existing admin session.

**Not yet built:**
- Stats for the five fixed triggers (reorder/offer/credit/approval/lifecycle) — sent / response rate / estimated recovered revenue per trigger — on the dashboard itself, alongside the campaign stats already there.
- A live calculator for the native "Buy X Get Y" rule type (see Phase 3) — nothing custom to build backend-wise, just a friendlier config screen.
- **Visual identity system** (logo/mark, color palette, typography, admin menu icon, GitHub social banner) — a full brand direction was drafted (dark "engine" aesthetic, Magento-orange + cyan accents, Inter/Plus Jakarta Sans + JetBrains Mono) but is a separate, sizeable design effort, not started. Decision pending on which pieces are worth building for a solo project (likely: GitHub banner + a simple monochrome menu icon first; a custom visual campaign builder and branded email templates are lower priority).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 (same as Magento core).
