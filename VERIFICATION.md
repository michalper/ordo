# Verification checklist — trying this on a real Magento instance

Everything in this module has been written, lint-checked (`php -l`, XML well-formedness), and where possible logic-verified with a local PHPUnit run — but **none of it has executed inside an actual Magento application yet**. This checklist is the concrete "how" for README → Trying this for real.

Work through it top to bottom, on a **fresh Magento Open Source instance** — not an existing store with unrelated data/customizations. A clean install isolates "is this module actually broken" from "does this conflict with something else already there."

Check things off as you go. Where something fails, note the exact error before moving on — that's the real value of this pass, not a rubber stamp.

---

## 0. Prerequisites

- [ ] PHP 8.1, 8.2, or 8.3 (matches `composer.json`)
- [ ] Composer 2.x
- [ ] MySQL 8.0 / MariaDB 10.6+
- [ ] Elasticsearch or OpenSearch (required by Magento itself, not this module)
- [ ] A Magento Open Source install — latest 2.4.x. Two ways to get one:
  - Adobe's official `composer create-project` flow (requires a free Adobe/Magento Marketplace auth keypair), or
  - A pre-built Docker image (e.g. `markshust/docker-magento`) if you want to skip manual environment setup

## 1. Install the module

Since `ordo/module-automation` isn't published to Packagist, add it as a local path repository:

```bash
composer config repositories.ordo-automation path /absolute/path/to/mma
composer require ordo/module-automation:@dev
bin/magento module:enable Ordo_Automation
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

- [ ] `setup:upgrade` completes without errors — this is the first real test of every `Setup/Patch/Data/*` class and `db_schema.xml`. If it fails here, note which patch/table.
- [ ] `bin/magento module:status Ordo_Automation` shows it enabled
- [ ] Admin panel loads without a white screen / 500 (catches DI wiring mistakes in `di.xml`)

## 2. Static checks (fast, do these before anything manual)

```bash
composer require --dev phpstan/phpstan bitexpert/phpstan-magento phpunit/phpunit
vendor/bin/phpstan analyse -c vendor/ordo/module-automation/phpstan.neon
vendor/bin/phpunit vendor/ordo/module-automation/Test/Unit
```

- [ ] PHPStan run completes (first time it's ever actually executed against this code — expect to fix real issues, not zero)
- [ ] All 6 unit test files pass for real, against actual Magento classes this time (`SalesRepEmailContextTest`, `QualifyingSetTrackerTest`, `ConditionPoolTest`, `OrderTotalAtLeastTest`, `HasTagTest`, `AddTagTest`)

## 3. Admin UI sanity

- [ ] "Ordo Automation" menu appears in the admin sidebar
- [ ] **Campaigns** grid loads (empty, first time)
- [ ] "Add New Campaign" opens the form; conditions/actions `dynamicRows` sections render and "Add Condition"/"Add Action" buttons work
- [ ] Create one campaign (e.g. trigger `order_placed`, condition `order_total_gte` → `{"amount": "1"}`, action `add_tag` → `{"tag": "test_tag"}`), save — confirm it appears in the grid with the right trigger event
- [ ] **Reorder Cycles** grid loads (empty until step 4 below runs)
- [ ] **Stores → Configuration → Ordo Automation** — every section from `system.xml` renders (Reorder, Abandoned Cart, Offer, Credit Limit, Lifecycle, Order Approval, Sales Rep, Tracking) with no missing source-model errors

## 4. B2B triggers, one at a time

For each, enable it in config first, then trigger the underlying condition, then run the matching cron manually (`bin/magento cron:run` runs everything due; to run one job in isolation, call its class's `execute()` via a small script, or wait for the schedule).

- [ ] **Reorder reminders:** place 3+ orders for the same registered customer/SKU a few days apart (or backdate `created_at` directly in `sales_order` for faster testing) → run `CalculateReorderCycle` → confirm a row appears in the Reorder Cycles grid → run `SendReorderReminders` → confirm an email attempt (check `var/log` or a mail catcher like Mailhog)
- [ ] **Abandoned cart:** add items to a cart as a registered customer, don't check out, wait past the configured delay (or lower it to 1 minute for testing) → run `SendAbandonedCartReminders` → confirm the email fires and, separately, that a `cart_abandoned` campaign (if you made one in step 3) actually ran — check `ordo_customer_tag` for the tag it should have added
- [ ] **Offer expiry:** manually insert a row into `ordo_offer` with `status = 'sent'` and `expires_at` = tomorrow → run `SendOfferExpiryReminders` → confirm the email → set `expires_at` to yesterday → run `ExpireOverdueOffers` → confirm `status` flips to `expired`
- [ ] **Credit limit:** set a customer's "Credit Limit" attribute in their admin profile → place orders for them totaling past 80% of it → run `SendCreditLimitAlerts` → confirm the warning email → cross 100% → confirm the "over limit" email variant
- [ ] **Order approval:** set a customer's "Order Spend Limit" and "Order Approval Admin Email" attributes → place an order over the limit as that customer → confirm the order lands in status `Pending Approval` (not the normal default status) and inventory reservation still looks correct → click the approve link from the email (or hit `/ordo/approval/approve/token/<token>` directly) → confirm the order status flips to normal and the `ordo_order_approval` row shows `approved`
- [ ] **Sales rep signature:** set a customer's "Assigned Sales Rep" name/email/phone → trigger any of the above emails for that customer → confirm the signature block shows the rep's details, not the store name fallback

## 5. Campaign engine end-to-end

- [ ] The campaign created in step 3 (`order_placed` → `order_total_gte` ≥ 1 → `add_tag` "test_tag") actually fires: place any order as a registered customer → check `ordo_customer_tag` for the new row
- [ ] Create a second campaign chaining `generate_coupon` → `send_email` on `order_placed`, with a real `rule_id` from an existing cart price rule → place an order → confirm a new row appears in `salesrule_coupon` and the email actually contains `{{var coupon_code}}` filled in, not blank
- [ ] `tag_added` trigger: create a campaign on `tag_added` with a `send_email` action → manually call `CustomerTagManager::addTag()` for a customer (or trigger it via the welcome email flow) → confirm the campaign fires

## 6. Promotion Builder (Phase 3)

- [ ] **Buy X Get Y (native):** create a cart price rule in Sales → Promotions with the native "Buy X Get Y" discount action (no code from this module needed) — confirms this baseline still works, unmodified, alongside the new calculator below
- [ ] **CheapestItemFree:** since there's no admin dropdown option yet (documented limitation), set an existing rule's `simple_action` directly in the database to `ordo_cheapest_item_free`, or via the REST API — add 3+ items from that rule's qualifying category to a cart, check out or view cart totals, confirm exactly the cheapest one is discounted to zero, not the most expensive or a random one

## 7. On-site tracking (Phase 5)

- [ ] Enable tracking in config, visit any storefront page as a guest, check DevTools → Application → Cookies for `ordo_visitor_id`
- [ ] Check `ordo_visitor_event` for a `page_view` row matching that visitor
- [ ] Call `window.ordoTrack('product_view', 'SOME-SKU')` from the browser console on a product page 3+ times (or lower the threshold in config) → confirm a `viewed_product_view_SOME-SKU` tag appears in `ordo_customer_tag` — but only once you're also logged in, or after logging in (identity stitching)
- [ ] Log in as that visitor (with the same browser/cookie) → confirm pre-login events got backfilled with your `customer_id` in `ordo_visitor_event`
- [ ] Manually run `PruneVisitorEvents` with a very short retention window (e.g. 0 days) → confirm old raw rows disappear but the tag from the previous step is untouched

## 8. What to do with results

- **Everything above passes:** update `README.md` — replace "Trying this for real" caveats with a dated note ("Verified against Magento Open Source 2.4.x on [date]") and remove the "not yet executed" caveats that no longer apply.
- **Something fails:** that's the actual point of this pass. Fix it, add or update the relevant unit test to catch the same regression later, then re-run this checklist from the failing step, not from scratch.
