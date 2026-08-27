# Ordo Automation for Magento 2

*[Czytaj po polsku](README.pl.md)*

Marketing automation that runs *inside* **stock Magento Open Source** — no Adobe Commerce B2B license, no external MA subscription (Klaviyo, iPresso, SalesManago...). Every trigger is computed from data Magento already has (orders, quotes, customers, carts), or from a small first-party data model added alongside it.

The goal is for this module to be a genuine substitute for a general-purpose MA platform on a Magento store — not just a handful of B2B-flavored add-ons — covering both classic B2C lifecycle automation and the B2B triggers most external MA tools structurally can't see.

## Features

**B2B**
- **Reorder reminders** — reminds a customer before their predicted next order date, based on their own purchase history.
- **Offer/quote expiry reminders** — proactive "expires in N days" email for the module's own quote entity (`ordo_offer`).
- **Credit limit alerts** — cron warning at a configurable threshold, plus live status over REST (`GET /V1/ordo/credit-limit/mine`).
- **Order approval workflow** — orders above a per-customer spend limit are held for a token-based admin approve/reject email, with escalation for unresolved approvals.
- **Free gift above a cart threshold** — admin-defined gift pool with cascading cart-subtotal tiers; the customer selects via REST.

**B2C**
- **Abandoned cart recovery** — recovery email for inactive carts above a configurable subtotal, capped per cart.
- **Welcome email** — on customer registration.
- **Win-back / re-engagement email** — one-time email after N days of inactivity, self-clearing once the customer orders again.

**Shared foundation**
- **Behavioral tagging** — the segmentation primitive every trigger above reads or writes.
- **Sales-rep signature** — automated emails are signed by the customer's assigned rep; a weekly digest groups inactive customers by rep.
- **Campaign engine** — a configurable "when X happens and Y is true, do Z" rule engine, with conditions/actions as `di.xml`-registered plug-ins and a full REST service contract.
- **On-site behavior tracking** — a dependency-free JS snippet turns page/product/category views into campaign-engine tags.
- **Admin UI** — dashboard, campaign builder (with an editable [Drawflow](https://github.com/jerosoler/Drawflow) trigger(s) → conditions → actions canvas — a campaign can have more than one trigger), free gift offer builder, and reorder-cycles diagnostic grid.

Everything is configurable under **Stores → Configuration → Ordo Automation** (or, for campaigns and free gifts, via their REST API), each with its own on/off switch and cron job. Implementation detail and the "why" behind each design decision live in [CHANGELOG.md](CHANGELOG.md); what's still in progress lives in [ROADMAP.md](ROADMAP.md).

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
view/adminhtml/ui_component/sales_rule_form.xml — extends the native Cart Price Rule form with a live "Buy X Get Y" preview field
view/adminhtml/web/js/buy-x-get-y-calculator.js — the preview's read-only calculator (no new discount logic, mirrors the native one)
Block/Adminhtml/Campaign/Edit/Flow.php — builds the Drawflow trigger/condition/action graph for the campaign edit page
view/adminhtml/web/lib/drawflow/     — vendored Drawflow (MIT) — https://github.com/jerosoler/Drawflow
Controller/Adminhtml/Campaign/, ReorderCycle/, FreeGiftOffer/ — admin grid/form controllers
Block/Adminhtml/Campaign/Edit/, FreeGiftOffer/Edit/ — toolbar button blocks (Back/Delete/Save & Continue)
Ui/Component/Listing/Column/     — CampaignActions, FreeGiftOfferActions (Edit/Delete row links)
view/adminhtml/ui_component/     — ordo_campaign_listing/form, ordo_reorder_cycle_listing, ordo_free_gift_offer_listing/form
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
