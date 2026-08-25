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

The features below need custom data models that don't exist on stock Magento Open Source — they're the next milestones, not v0.1:

- **Expiring quote/offer reminders** — Magento Open Source has no B2B quote entity; needs a small custom quote/offer module first.
- **Credit limit alerts** — needs a customer credit-limit attribute and a payment-tracking mechanism; Adobe Commerce B2B has this natively, Open Source doesn't.
- **Order approval workflow reminders** — needs a company/sub-account hierarchy, which Open Source also doesn't have.
- **Sales-rep-signed emails** — needs an "assigned rep" customer attribute plus a shared email view-model.
- **A "Promotion Builder" UI** — a friendlier admin layer over Magento's native `SalesRule` (Buy X Get Y is already native and free; "cheapest item in a bundle for free" needs a custom rule action).
- **A unified admin dashboard** — one screen listing every trigger, its status, and send/response stats, instead of scattered system.xml sections.

## License

OSL-3.0 (same as Magento core).
