# Ordo Automation for Magento 2

B2B-aware marketing automation that runs on **stock Magento Open Source** — no Adobe Commerce B2B license, no third-party SaaS integration. Every trigger is computed from data Magento already has (orders, quotes, customers).

## Why

General-purpose marketing automation platforms (Klaviyo, iPresso, SalesManago, ActiveCampaign...) are strong on channels and campaign UI, but they only see what a store exports to them through a generic connector — order totals, cart events, product views. They don't compute purchase-cycle patterns, and they don't offer proactive B2B-flavored triggers out of the box.

Ordo Automation runs *inside* Magento, computes triggers from the store's own transactional data, and sends the resulting emails without any external dependency.

## Features (v0.1)

- **Reorder reminders** — detects a recurring purchase pattern per customer/SKU from order history (`ordo_reorder_cycle`) and emails a reminder a configurable number of days before the predicted next order date.
- **Abandoned cart recovery** — finds inactive carts above a configurable subtotal threshold and sends a recovery email, capped at a configurable number of reminders per cart.

Both are configurable under **Stores → Configuration → Ordo Automation**, and both ship their own cron jobs (see `etc/crontab.xml`).

## Architecture

```
etc/
  module.xml, crontab.xml, db_schema.xml, email_templates.xml, acl.xml
  adminhtml/system.xml        — store configuration
Cron/
  CalculateReorderCycle.php   — nightly: order history → predicted next-order date
  SendReorderReminders.php    — daily: due cycles → email
  SendAbandonedCartReminders.php — every 30 min: inactive carts → email
Model/, Model/ResourceModel/  — ordo_reorder_cycle read/write
Helper/Config.php             — typed access to system.xml values
view/frontend/email/          — email templates
```

## Install

```bash
composer require ordo/module-automation
bin/magento module:enable Ordo_Automation
bin/magento setup:upgrade
bin/magento cache:flush
```

## Roadmap

### Phase 2 — triggers that need a small custom data model first

Stock Magento Open Source has no B2B quote/company/credit-limit entities (those are Adobe Commerce B2B only), so these need a lightweight model of their own before the automation on top of them makes sense:

- **Expiring quote/offer reminders** — a small custom quote/offer entity (status, expiry date, linked customer) plus a cron that reminds the customer before it lapses. Every native/enterprise B2B system checked (Adobe Commerce, OroCommerce) only sends *reactive* notifications after a status change — a *proactive* "expires in 2 days" reminder is a genuine gap across the market, not just on Open Source.
- **Credit limit alerts** — a customer credit-limit attribute + a cron comparing paid vs. open invoices, warning at a configurable threshold (e.g. 80%) before the account gets blocked, instead of just blocking at 100% like most systems do.
- **Order approval workflow reminders** — needs a minimal company/sub-account hierarchy (one admin, N buyers, a spend limit) with an approve/reject email flow; on top of that, an escalation reminder if nobody acts within N days. Several established systems (Adobe Commerce, OroCommerce, Medusa 2.0 B2B) already have the base approval workflow — the escalation reminder on top of it is the differentiator, not the workflow itself.
- **Sales-rep-signed emails** — an "assigned rep" customer attribute plus a shared email view-model so every automated email (reorder, offer, credit) is signed by a real person instead of a generic sender address, and reps get a periodic digest of customers needing attention.

### Phase 3 — Promotion Builder (adjacent product area, not a trigger)

A friendlier admin layer over Magento's native `SalesRule` engine — the raw native form (tabs, dropdowns, a condition tree written like code) is the same everywhere, and no store owner enjoys using it.

- **Buy X Get Y free** — already 100% native Magento (`SalesRule` "Buy X Get Y" discount action); this is just a human-friendly config screen with a live calculator, not new backend logic.
- **Cheapest item in a bundle free** — genuinely missing from native Magento: no built-in rule action picks "the cheapest item in a qualifying set" as the free one. Needs a custom `SalesRule` action class.
- **Free gift above a cart threshold** — also missing natively (Magento can discount, but can't natively add a *different*, specific product for free); needs a custom rule action too.
- **Coupon generated after checkout** ("10% off your next order") — bridges into the trigger list above: same `sales_order_place_after` event, but the action is "generate a coupon," not "send an email."
- **Coupon for cart recovery** — same idea applied to the abandoned-cart trigger already in v0.1: instead of just a reminder email, optionally attach a discount code.

### Phase 4 — one dashboard instead of scattered config screens

Right now every trigger lives in its own `system.xml` section with its own cron and its own silent log table. The natural next step, once there are enough triggers to make it worthwhile, is a single "Automation" admin menu: one grid listing every trigger with an on/off switch and basic stats (sent / response rate / estimated recovered revenue), and one config form per trigger instead of digging through Stores → Configuration.

## License

OSL-3.0 (same as Magento core).
