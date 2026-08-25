# Ordo Automation for Magento 2

Marketing automation that runs *inside* **stock Magento Open Source** — no Adobe Commerce B2B license, no external MA subscription (Klaviyo, iPresso, SalesManago...). Every trigger is computed from data Magento already has (orders, quotes, customers, carts), or from a small first-party data model added alongside it.

The goal is for this module to be a genuine substitute for a general-purpose MA platform on a Magento store — not just a handful of B2B-flavored add-ons — covering both classic B2C lifecycle automation and the B2B triggers most external MA tools structurally can't see.

## Why not just install Klaviyo/iPresso/SalesManago?

They're strong on channels (email/SMS/push) and campaign UI, but they only see what a store exports through a generic connector — order totals, cart events, product views. They don't compute purchase-cycle patterns, don't know about quote expiry, credit limits, or company hierarchies, and their B2C behavioral tracking runs through a JS snippet + cookie that's completely decoupled from server-side order data. Ordo Automation runs inside Magento itself, so triggers can combine both without an integration layer.

## Features (v0.3)

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

All of it is configurable under **Stores → Configuration → Ordo Automation**, each feature with its own on/off switch and its own cron job (see `etc/crontab.xml`).

## Architecture

```
etc/
  module.xml, di.xml, crontab.xml, db_schema.xml, events.xml, email_templates.xml, acl.xml
  adminhtml/system.xml          — store configuration
  frontend/routes.xml           — /ordo/approval/* (token-based, no login)
Api/, Api/Data/                 — service contracts (OfferInterface, OfferRepositoryInterface)
Cron/
  CalculateReorderCycle.php, SendReorderReminders.php
  SendAbandonedCartReminders.php
  SendOfferExpiryReminders.php, ExpireOverdueOffers.php
  SendCreditLimitAlerts.php
  TagInactiveCustomers.php, SendWinBackEmails.php
  EscalateStalePendingApprovals.php
Observer/
  SendWelcomeEmail.php           — customer_register_success
  HoldOrderForApproval.php       — sales_order_place_after
Controller/Approval/            — Approve.php, Reject.php (token-based frontend actions)
Model/, Model/ResourceModel/     — ordo_reorder_cycle, ordo_offer, ordo_customer_tag, ordo_order_approval
Model/CreditLimitCalculator.php  — used-credit derived from open sales_order.total_due
Model/CustomerTagManager.php     — add/remove/check/list-by-tag
Setup/Patch/Data/                — customer attributes (credit limit, spend limit, approval admin email), Pending Approval order status
Helper/Config.php                — typed access to system.xml values
view/frontend/email/             — email templates
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

- **Sales-rep-signed emails** — an "assigned rep" customer attribute plus a shared email view-model so every automated email (reorder, offer, credit) is signed by a real person instead of a generic sender address, and reps get a periodic digest of customers needing attention.

### Phase 3 — Promotion Builder (adjacent product area, not a trigger)

A friendlier admin layer over Magento's native `SalesRule` engine — the raw native form (tabs, dropdowns, a condition tree written like code) is the same everywhere, and no store owner enjoys using it.

- **Buy X Get Y free** — already 100% native Magento (`SalesRule` "Buy X Get Y" discount action); this is just a human-friendly config screen with a live calculator, not new backend logic.
- **Cheapest item in a bundle free** — genuinely missing from native Magento: no built-in rule action picks "the cheapest item in a qualifying set" as the free one. Needs a custom `SalesRule` action class.
- **Free gift above a cart threshold** — also missing natively; needs a custom rule action too.
- **Coupon generated after checkout** ("10% off your next order") — bridges into the trigger list above: same `sales_order_place_after` event, but the action is "generate a coupon," not "send an email."
- **Coupon for cart recovery** — same idea applied to the abandoned-cart trigger: instead of just a reminder email, optionally attach a discount code.

### Phase 5 — on-site behavior tracking (the missing half of "like iPresso")

Everything in v0.2 reacts to server-side data (orders, carts, registration). A real MA platform also tracks anonymous on-site behavior before someone ever converts:

- **First-party visitor cookie** — issued on first visit (works as a native Magento frontend plugin, or as a small JS snippet that can be dropped into any site, Magento or not), giving every anonymous visitor a stable ID before they're a customer.
- **JS tracking snippet** — records page views / product views / cart actions client-side and posts them to a Magento REST endpoint as events, the same architecture SalesManago/iPresso use for their "External Events."
- **Identity stitching** — once the visitor logs in or checks out, their anonymous event history gets linked to the real `customer_id`, so behavioral data collected before conversion still feeds tags/segments/triggers afterward.
- Once this exists, tags stop being purely order-derived (`inactive`, `new_customer`) and can include on-site behavior (`viewed_category_x_3_times`, `abandoned_checkout_step_shipping`), which is where this starts to genuinely replace a general-purpose MA platform instead of just covering its blind spots.

### Phase 6 — closing the test coverage & localization gap

The standards in "Quality & testing standards" above apply from now on; this phase is the backlog of bringing everything written before that rule existed up to the same bar:

- **Unit tests** for every `Cron/`, `Observer/`, `Controller/Approval/` class beyond the `SalesRepEmailContext` seed — `CreditLimitCalculator`, `CustomerTagManager`, and each cron's `execute()` logic with mocked collections/repositories.
- **MFTF test suite** (`Test/Mftf/` — directory scaffolded, tests not yet written): admin sets a customer's credit limit and spend limit → storefront checkout flows exercise both; the full order-approval email round-trip; offer self-extension from the storefront.
- **API functional tests** for `OfferRepositoryInterface` (`save`/`getById`/`getList`/`delete`), following Magento's own `dev/tests/api-functional` conventions.
- **PHPStan clean run at `level: max`** across the whole module — `phpstan.neon` exists; hasn't been run against every file added since Phase 2-3 yet.
- **Coverage report wired into CI** (whatever CI this repo ends up on) so "100% target" becomes a number in a build badge, not just a stated goal.
- **More `i18n/*.csv` locales** beyond `en_US`/`pl_PL` — driven by whichever languages an actual install needs, not translated speculatively ahead of demand.

### Phase 4 — one dashboard instead of scattered config screens

Right now every trigger lives in its own `system.xml` section with its own cron and its own silent log table. Once there are enough triggers to make it worthwhile: a single "Automation" admin menu, one grid listing every trigger with an on/off switch and basic stats (sent / response rate / estimated recovered revenue), one config form per trigger instead of digging through Stores → Configuration.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 (same as Magento core).
