# Ordo Automation for Magento 2

*[Czytaj po polsku](README.pl.md)*

Marketing automation that runs *inside* **stock Magento Open Source** — no Adobe Commerce B2B license, no external MA subscription (Klaviyo, iPresso, SalesManago...). Every trigger is computed from data Magento already has (orders, quotes, customers, carts), or from a small first-party data model added alongside it.

The goal is for this module to be a genuine substitute for a general-purpose MA platform on a Magento store — not just a handful of B2B-flavored add-ons — covering both classic B2C lifecycle automation and the B2B triggers most external MA tools structurally can't see.

## Why not just install Klaviyo/iPresso/SalesManago?

They're strong on channels (email/SMS/push) and campaign UI, but they only see what a store exports through a generic connector — order totals, cart events, product views. They don't compute purchase-cycle patterns, don't know about quote expiry, credit limits, or company hierarchies, and their B2C behavioral tracking runs through a JS snippet + cookie that's completely decoupled from server-side order data. Ordo Automation runs inside Magento itself, so triggers can combine both without an integration layer.

## Features (v0.8)

**B2B**
- **Reorder reminders** — detects a recurring purchase pattern per customer/SKU from order history and emails a reminder before the predicted next order date.
- **Offer/quote expiry reminders** — a first-party quote entity (`ordo_offer`) with a *proactive* "expires in N days" reminder — every established B2B platform we checked (Adobe Commerce B2B, OroCommerce) only notifies reactively, after a status change.
- **Credit limit alerts** — a customer credit-limit attribute + a cron warning at a configurable threshold (default 80%) before the account gets blocked, instead of only reacting once it's already over. Status is also readable live via REST (`GET /V1/ordo/credit-limit/mine`), not just pushed by email — a headless storefront can show "how much credit is left" in a customer's own account. See `API.md`.
- **Order approval workflow** — an optional per-customer spend limit + approval-admin email; orders above the limit are held (status `Pending Approval`, same "new" state so inventory reservation is untouched) and the admin gets a token-based approve/reject email link, no login required. Unresolved approvals get escalated (resent, capped) after a configurable number of days.
- **Free gift above a cart threshold** — admin defines a gift pool plus one or more cascading cart-subtotal tiers; every tier the subtotal reaches ADDS a gift slot cumulatively (not a single flat threshold or a fixed count of 1), and the customer picks from the pool via REST. See `API.md`.

**B2C**
- **Abandoned cart recovery** — finds inactive carts above a configurable subtotal threshold and sends a recovery email, capped per cart.
- **Welcome email** — fires on `customer_register_success`, tags the customer `new_customer`.
- **Win-back / re-engagement email** — nightly tagging of customers inactive for N days, followed by a one-time win-back email; tags clear themselves automatically once the customer orders again.

**Shared foundation**
- **Behavioral tagging** (`CustomerTagManager`) — the segmentation primitive every trigger above reads or writes (`new_customer`, `inactive`, `win_back_sent`, ...), the same pattern general MA platforms build campaign targeting on.
- **Sales-rep signature** (`SalesRepEmailContext`) — every automated email above is signed by the customer's assigned rep when one is set, falling back to the store name. A weekly digest also groups inactive customers by rep.
- **Campaign engine** (`ordo_campaign` + `ordo_campaign_condition` + `ordo_campaign_action`) — a genuinely configurable "when X happens and Y is true, do Z" rule engine, not a hardcoded cron per idea. Conditions and actions are plug-ins registered in `di.xml` (`Model\Campaign\ConditionPool` / `ActionPool`) against `Api\Campaign\ConditionInterface` / `ActionInterface` — adding a new condition or action type is a new class + one `di.xml` line, never a change to the dispatcher. Ships with two conditions (`tag`, `order_total_gte`) and three actions (`add_tag`, `send_email`, `generate_coupon`), triggered on `order_placed`, `customer_registered`, and `tag_added` (the last fired as a Magento event by `CustomerTagManager`, not a direct call, to avoid a DI cycle with the `tag` condition). Exposed as a full service contract (`CampaignRepositoryInterface`) with REST endpoints under `/V1/ordo/campaigns`.
- **On-site behavior tracking** — a dependency-free JS snippet (`tracker.js`) issues a first-party visitor cookie and posts `page_view`/`product_view`/`category_view` events; identity is stitched to the customer on login, and repeated views turn into campaign-engine tags (e.g. `viewed_category_view_15`) instead of raw per-click storage.
- **Admin UI** — a dedicated "Ordo Automation" admin menu with a dashboard, a full campaign builder (grid + form with dynamic condition/action rows, type dropdowns always in sync with what's registered in `di.xml`), and a reorder-cycles diagnostic grid. Free-gift offers/tiers/gift-pool are REST-API/database-managed only for now (no admin grid yet — see `ROADMAP.md`).

All of it is configurable under **Stores → Configuration → Ordo Automation** (or, for campaigns and free gifts, via their tables / REST API), each feature with its own on/off switch and its own cron job (see `etc/crontab.xml`).

**A note on scope:** the campaign engine intentionally works on structured, low-frequency events (orders, registrations, tag changes) — a handful of rows per customer, not per click. Raw high-frequency on-site behavior tracking (page views / clicks via the JS snippet above) is a different scale problem and is *not* stored as one row per event in this same schema — see `ROADMAP.md` Phase 5 for the full design and how it feeds into the tag system instead of bypassing it.

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
Model/CreditLimitManagement.php  — REST-facing wrapper (mine / by customer id) over the calculator above
Model/FreeGiftOffer(Tier/Product).php, Model/FreeGiftManagement.php — cascading-tier gift offers + selection
Model/QuoteGiftItem.php          — marker linking a quote_item to the offer it was earned from
Observer/TrimExcessFreeGifts.php — drops gifts that no longer qualify when subtotal falls
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
Test/Unit/                       — PHPUnit tests (see ROADMAP.md Phase 6 for current coverage state)
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

These are binding rules for this repo going forward, not a someday-wishlist — every new class added after this point should meet them, and existing code is being brought up to the same bar incrementally (tracked in `ROADMAP.md` Phase 6):

- **Static analysis: PHPStan at `level: max`**, configured in `phpstan.neon` with the [bitexpert/phpstan-magento](https://github.com/bitexpert/phpstan-magento) extension so Magento's magic (factories, proxies, `__()` translation, EAV magic getters) doesn't produce false positives. Runs as `require-dev` only — never ships to a production install.
- **Unit tests (PHPUnit)** for every class with non-trivial logic — `Model/`, `Cron/`, `Helper/`, `Controller/`. `Test/Unit/Model/SalesRepEmailContextTest.php` is the seed test establishing the mocking pattern (`createMock` on interfaces, no real Magento bootstrap).
- **MFTF (Magento Functional Testing Framework)** end-to-end coverage for every customer- and admin-facing flow: placing an order over the spend limit → email → approve/reject link → order status change; a customer self-extending an expiring offer; etc. Per Adobe's [MFTF getting-started guide](https://developer.adobe.com/commerce/testing/functional-testing-framework/getting-started).
- **API tests** for every service contract in `Api/` — see `API.md` and `Test/Api/README.md`.
- **Target: ~100% code coverage.** See `ROADMAP.md` Phase 6 for the current measured/stale state and `VERIFICATION.md` for what's covered today vs. the handful of genuinely unreachable lines still outstanding.

## Localization

Admin-facing labels (`system.xml`, customer attribute labels) are translatable via standard Magento i18n CSV files in `i18n/`, keyed off `en_US.csv` as the source. Currently shipped: `en_US`, `pl_PL`. Contributions/requests for additional locales are tracked in `ROADMAP.md` Phase 6 — the goal is to cover every language relevant to the store's actual customer base, not just a token second locale.

## Roadmap

Shipped-feature history and everything still open (in-progress phases, known gaps, "not yet built" items) lives in **[ROADMAP.md](ROADMAP.md)**, not here — this README only describes the stable, current state of the module.

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

**What's genuinely still open:** see [ROADMAP.md](ROADMAP.md) — not failures, not attempted, or explicitly deferred.

**Full step-by-step checklist:** [VERIFICATION.md](VERIFICATION.md) — covers install, static analysis, and a manual walkthrough of every feature in this README, organized so a failure at any step points at exactly what to fix next.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 (same as Magento core).
