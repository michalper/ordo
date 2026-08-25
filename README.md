# Ordo Automation for Magento 2

Marketing automation that runs *inside* **stock Magento Open Source** — no Adobe Commerce B2B license, no external MA subscription (Klaviyo, iPresso, SalesManago...). Every trigger is computed from data Magento already has (orders, quotes, customers, carts), or from a small first-party data model added alongside it.

The goal is for this module to be a genuine substitute for a general-purpose MA platform on a Magento store — not just a handful of B2B-flavored add-ons — covering both classic B2C lifecycle automation and the B2B triggers most external MA tools structurally can't see.

## Why not just install Klaviyo/iPresso/SalesManago?

They're strong on channels (email/SMS/push) and campaign UI, but they only see what a store exports through a generic connector — order totals, cart events, product views. They don't compute purchase-cycle patterns, don't know about quote expiry, credit limits, or company hierarchies, and their B2C behavioral tracking runs through a JS snippet + cookie that's completely decoupled from server-side order data. Ordo Automation runs inside Magento itself, so triggers can combine both without an integration layer.

## Features (v0.6)

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
Model/CreditLimitCalculator.php  — used-credit derived from open sales_order.total_due
Model/CustomerTagManager.php     — add/remove/check/list-by-tag; fires ordo_customer_tag_added
Model/CouponGenerator.php        — mints a single-use SalesRule coupon code
Model/SalesRepEmailContext.php   — shared email signature block
Setup/Patch/Data/                — customer attributes (credit/spend limit, approval admin email, sales rep), Pending Approval order status
Helper/Config.php                — typed access to system.xml values
view/frontend/email/             — email templates
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

Everything in v0.2 reacts to server-side data (orders, carts, registration). A real MA platform also tracks anonymous on-site behavior before someone ever converts:

- **First-party visitor cookie** — issued on first visit (works as a native Magento frontend plugin, or as a small JS snippet that can be dropped into any site, Magento or not), giving every anonymous visitor a stable ID before they're a customer.
- **JS tracking snippet** — records page views / product views / cart actions client-side and posts them to a Magento REST endpoint as events, the same architecture SalesManago/iPresso use for their "External Events."
- **Identity stitching** — once the visitor logs in or checks out, their anonymous event history gets linked to the real `customer_id`, so behavioral data collected before conversion still feeds tags/segments/triggers afterward.
- Once this exists, tags stop being purely order-derived (`inactive`, `new_customer`) and can include on-site behavior (`viewed_category_x_3_times`, `abandoned_checkout_step_shipping`), which is where this starts to genuinely replace a general-purpose MA platform instead of just covering its blind spots.

**Scale note:** raw per-click/per-pageview events must *not* be persisted 1:1 into Magento's own database the way `ordo_campaign`/`ordo_customer_tag` are — that table would grow by thousands of rows/day even on a mid-size store, which is exactly why real MA platforms keep that in separate infrastructure (event stores, ClickHouse, Kafka), not their core app DB. The intended design: the JS endpoint aggregates in-flight and only writes derived signals (a tag crossing a threshold) into `ordo_customer_tag` — either raw events never touch a persistent Magento table at all, or they land in a separate, aggressively-pruned table (e.g. 7-day retention) that's structurally kept apart from the rest of this module's schema.

### Phase 6 — closing the test coverage & localization gap

The standards in "Quality & testing standards" above apply from now on; this phase is the backlog of bringing everything written before that rule existed up to the same bar:

- **Unit tests** for every `Cron/`, `Observer/`, `Controller/Approval/` class beyond the seed tests (`SalesRepEmailContextTest`, `ConditionPoolTest`, `OrderTotalAtLeastTest`) — `CreditLimitCalculator`, `CustomerTagManager`, `CampaignDispatcher` (with mocked collection factories — the pool tests cover the plug-in registry itself, not yet the dispatch/condition-AND/action-order logic), and each cron's `execute()` logic.
- **MFTF test suite** (`Test/Mftf/` — directory scaffolded, tests not yet written): admin sets a customer's credit limit and spend limit → storefront checkout flows exercise both; the full order-approval email round-trip; offer self-extension from the storefront; a saved campaign (order_placed → generate_coupon → send_email) actually firing on a real checkout.
- **API functional tests** for `OfferRepositoryInterface` and `CampaignRepositoryInterface` (`save`/`getById`/`getList`/`delete`), following Magento's own `dev/tests/api-functional` conventions.
- **PHPStan clean run at `level: max`** across the whole module — `phpstan.neon` exists; hasn't been run against every file added since Phase 2-3 yet.
- **Coverage report wired into CI** (whatever CI this repo ends up on) so "100% target" becomes a number in a build badge, not just a stated goal.
- **More `i18n/*.csv` locales** beyond `en_US`/`pl_PL` — driven by whichever languages an actual install needs, not translated speculatively ahead of demand.

### Phase 4 — one dashboard instead of scattered config screens

Right now every fixed trigger lives in its own `system.xml` section with its own cron and its own silent log table, and the new campaign engine has *no admin UI at all* yet — campaigns can only be created via direct DB rows or the REST API. Once there's enough here to make it worthwhile:
- A single "Automation" admin menu: one grid listing every fixed trigger with an on/off switch and basic stats (sent / response rate / estimated recovered revenue).
- A proper campaign builder screen: list of campaigns, a form to pick a trigger event, add conditions (dropdown populated from `ConditionPool::getAvailableTypes()`) and actions (from `ActionPool::getAvailableTypes()`) with their params — the UI equivalent of what's already fully functional in the data model and dispatcher today.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 (same as Magento core).
