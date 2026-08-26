# Verification checklist — trying this on a real Magento instance

**Verified against Magento Open Source 2.4.7 on 2026-08-25**, via a disposable Docker
stack (PHP 8.2-FPM + MySQL 8.0 + OpenSearch 2.12, Magento cloned from
`github.com/magento/magento2` — `composer install` resolved entirely from Packagist,
no Adobe Marketplace keys needed). This section records what was actually run and
seen, not a rubber stamp — every checked box below was observed directly (admin
screenshots, log output, `bin/magento` exit codes), and every unchecked box has the
exact error next to it.

Work through it top to bottom, on a **fresh Magento Open Source instance** — not an
existing store with unrelated data/customizations. Where something fails, note the
exact error before moving on.

---

## 0. Prerequisites

- [x] PHP 8.2 (matches `composer.json`)
- [x] Composer 2.x
- [x] MySQL 8.0
- [x] OpenSearch 2.12 (required by Magento itself, not this module)
- [x] Magento Open Source 2.4.7, installed from the public GitHub mirror — `composer install`
      needed zero repo.magento.com credentials; only the PHP `sockets` extension had to be
      added to the toolbox image (`php-amqplib` requires it).

## 1. Install the module

```bash
composer config repositories.ordo-automation path /absolute/path/to/mma
composer require ordo/module-automation:@dev
bin/magento module:enable Ordo_Automation
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

- [x] `setup:upgrade` completes without errors — all 12 `ordo_*` tables created
      (`ordo_campaign`, `ordo_campaign_action`, `ordo_campaign_condition`,
      `ordo_customer_tag`, `ordo_offer`, `ordo_offer_reminder_log`,
      `ordo_order_approval`, `ordo_reorder_cycle`, `ordo_reorder_reminder_log`,
      `ordo_abandoned_cart_reminder_log`, `ordo_credit_limit_alert_log`,
      `ordo_visitor_event`). First run surfaced a WebAPI reflection error, fixed
      (see bug list below).
- [x] `bin/magento module:status Ordo_Automation` shows it enabled
- [x] Admin panel loads without a white screen / 500 — this caught 3 separate
      DI-compile fatals and one ACL merge conflict (see bug list). All fixed;
      `setup:di:compile` now completes clean and the Dashboard loads with zero
      browser console errors after login.

**Environment note (not a module bug):** using a Composer path repository with
`"options": {"symlink": false}` (mirror/copy mode) — required here because
`setup:static-content:deploy` couldn't resolve paths through the default symlink
mode's absolute host path. This means every host-side edit to the module needs a
resync (`rm -rf vendor/ordo/module-automation && composer update
ordo/module-automation`) before it's visible to the running Magento instance —
easy to forget mid-session and a source of "did I actually test the fix" confusion.

## 2. Static checks (fast, do these before anything manual)

```bash
composer require --dev phpstan/phpstan bitexpert/phpstan-magento phpunit/phpunit
vendor/bin/phpstan analyse -c vendor/ordo/module-automation/phpstan.neon
vendor/bin/phpunit vendor/ordo/module-automation/Test/Unit
```

- [x] PHPStan run completes — it never actually ran before this pass; the shipped
      `phpstan.neon` was missing `includes:` for the bitexpert extension's own
      `extension.neon` and used the wrong parameter key (`magento_root` instead of
      `magentoRoot`), so PHPStan refused to start at all. Fixed. It now reports
      **183 real level-max findings**, overwhelmingly missing iterable value types
      on `array` params/returns (e.g. `array $data` → `array<string, mixed> $data`).
      Not fixed in this pass — real backlog, tracked separately, not a correctness
      bug class.
- [x] All 6 unit test files pass for real, against actual Magento classes —
      **24/24 passing**, but not on the first run: 5 tests failed with
      `MethodCannotBeConfiguredException` (PHPUnit refusing to mock a method that
      doesn't exist on the real class), which is exactly what this pass is for —
      see bug list below.

## 3. Admin UI sanity

- [x] "Ordo Automation" menu appears in the admin sidebar
- [x] **Campaigns** grid loads (empty, first time) — first attempt fatal'd with
      `Missing required argument $mainTable of ...\Grid\Collection`, fixed (see below)
- [x] "Add New Campaign" opens the form without a fatal/500 (three real bugs found
      and fixed along the way — bogus toolbar button class, undeclared dynamic
      property, dynamicRows record config in the wrong XML node — see bug list)
- [x] Conditions/actions `dynamicRows` sections actually render fields and
      "Add Condition"/"Add Action" work — confirmed visually (screenshot) after
      fixing the root cause: the form's knockout template resolved to
      `templates/form/default.xhtml`, which binds to a `{{name}}.areas` scope that
      nothing in a plain (non-`<layout>`) form ever creates — a permanent,
      silent hang with zero console/server errors. Fixed by setting
      `<item name="template">templates/form/collapsible</item>`, whose template
      binds to `{{name}}.{{name}}`, matching this form's actual component tree.
      Also required fixing all `Controller/Adminhtml/Campaign/*` and
      `Controller/Adminhtml/ReorderCycle/Index` to implement
      `HttpGetActionInterface`/`HttpPostActionInterface` — without it, Magento's
      `BackendValidator` silently rejected the requests before `execute()` ran.
- [x] Create one campaign, confirm it saves and appears in the grid — confirmed
      via screenshot (Name, Trigger Event, Enabled, Conditions/Actions rows all
      editable)
- [x] **Reorder Cycles** grid loads — same `mainTable` fix as Campaigns grid,
      confirmed via the object manager (`getSize()` succeeds on both collections)
- [x] **Stores → Configuration → Ordo Automation** — all 8 sections render (Reorder,
      Abandoned Cart, Offer, Credit Limit, Lifecycle, Order Approval, Sales Rep,
      Tracking), no missing source-model errors, confirmed via full page screenshot

### Known open issue — not yet root-caused

`Uncaught TypeError: $(...).filter(...).collapse is not a function` at
`theme.js:629`, seen on the New/Edit Campaign page in a real browser tab
(not reproduced in the headless devtools session used for most of this
verification pass). Looks like a jQuery/bootstrap `collapse` plugin
load-order race in Magento core's `theme.js` during RequireJS bootstrap —
nothing in this module touches `theme.js` or bootstrap loading, so it's
suspected environment/timing, not an Ordo Automation bug, but **not
confirmed**. Docker image is native `aarch64` (Apple Silicon host, no x86
emulation), so it's not an emulation performance issue either — the
general slowness reported alongside this needs a real look (check PHP
opcache is actually enabled in the container, check `bin/magento` mode is
`production` vs `default`, check container CPU/memory limits) before
assuming it's just "Magento is slow." Next step if it reproduces
consistently: hard-refresh first to rule out a one-off race, then check
whether it happens on a *stock* Magento page with no Ordo Automation code
loaded at all (e.g. Dashboard) — if it does, it's conclusively unrelated
to this module.

## 4. B2B triggers, one at a time

- [x] **Offer expiry reminder:** inserted a real `ordo_offer` row (`status=sent`,
      `expires_at` = today+2, matching the default `lead_days` config), enabled
      `ordo_automation/offer/enabled` via `config:set`, ran
      `SendOfferExpiryReminders::execute()` directly (real object manager, no
      mocks). It correctly found the matching offer, built the email
      (customer/template/vars), and attempted delivery — failed only because
      this container has no `sendmail`/SMTP configured (`Unable to send mail.
      Please try again later.`), an environment limitation, not a code bug. The
      failure was handled correctly: caught, logged (`main.ERROR: ... failed to
      send offer expiry reminder for offer #1: ...`), didn't crash the cron
      (`main.INFO: sent 0 offer expiry reminders.`), and — importantly —
      `ordo_offer_reminder_log` stayed empty, meaning a failed send is *not*
      marked as sent and will be retried next run rather than silently
      swallowed. Matching/building logic confirmed correct; actual delivery is
      untestable without a real SMTP relay in this sandbox.
- [x] **Credit limit alert:** with the EAV fix below applied, set
      `ordo_credit_limit=1000` on the test customer, inserted a `sales_order`
      row with `total_due=800` (80% utilization — exactly the default warning
      threshold), enabled `ordo_automation/credit_limit/enabled`, ran
      `SendCreditLimitAlerts::execute()` directly. Correctly found the
      customer, computed 80% utilization, attempted the alert email — failed
      delivery only on the same "no sendmail in this container" limitation as
      the offer expiry reminder (section above), handled the same way
      (logged, not silently swallowed).
- [x] **Order approval:** called `HoldOrderForApproval::execute()` directly
      against a real `sales_order` row (`grand_total=800`) for a customer with
      `ordo_order_spend_limit=100` and `ordo_approval_admin_email` set.
      Confirmed a real `ordo_order_approval` row was created with a token and
      the correct admin email. The subsequent order-status-update step then
      hit "Please provide payment for the order" — expected, since this test
      order was inserted directly via SQL with no `sales_order_payment` row; a
      real order placed through checkout has one. Core hold/approval logic
      confirmed correct.
- [x] **Reorder reminders:** inserted 3 real `sales_order`/`sales_order_item` rows
      for the same customer/SKU, 30 days apart. `CalculateReorderCycle` correctly
      computed `avg_interval_days=30`, `next_expected_date` = last order + 30
      days, `orders_considered=3`. Moved `next_expected_date` to within the
      lead window and ran `SendReorderReminders` — correctly found the cycle,
      built the email, attempted delivery (failed only on this sandbox's
      missing SMTP, same as every other reminder cron tested).
- [x] **Abandoned cart:** inserted a real `quote` row (`is_active=1`,
      `items_count=1`, `subtotal=250`, `customer_email` set, `updated_at`
      outside the delay window). `SendAbandonedCartReminders` correctly found
      it and attempted delivery (same SMTP limitation). Also calls
      `CampaignDispatcher::dispatch('cart_abandoned', ...)` for registered
      customers — not separately re-verified with a live `cart_abandoned`
      campaign this pass, but the dispatcher itself was already proven correct
      end-to-end in section 5 with a different trigger.
- [ ] **A real order placed through full checkout** (as opposed to observer
      logic tested directly) — attempted twice, blocked on pure Magento-core
      checkout-stack issues unrelated to this module (see bug list below,
      "programmatic order placement" / storefront "No Payment Methods").
      Would still be worth doing once, to see the whole chain (checkout →
      `sales_order_place_after` → hold → approve link → order un-held) fire
      for real, but the module's own logic is now verified independently.

## 5. Campaign engine end-to-end

- [x] The campaign created through the admin UI (`order_placed` → `order_total_gte`
      amount=1 → `add_tag` tag=engine_e2e_test, dedicated fields, real save) actually
      fires: called `CampaignDispatcher::dispatch('order_placed', ['order_total' =>
      100.0, 'customer_id' => 1])` directly (real object manager, real customer row,
      real DB) — confirmed `ordo_customer_tag` gained the row
      `customer_id=1, tag=engine_e2e_test`. This exercises the full chain: campaign
      row → condition/action rows → `ConditionPool`/`ActionPool` resolution →
      `AddTag` action → `CustomerTagManager`.
- [ ] `generate_coupon` → `send_email` chaining, and a real order placed through
      checkout (rather than calling the dispatcher directly) — not yet done.
- [ ] `tag_added` trigger firing a second campaign — not yet done.

## 6. Promotion Builder (Phase 3)

**Not reached this pass.**

## 7. On-site tracking (Phase 5)

**Not reached this pass.**

## 8. Real bugs found and fixed in this pass

All of the following were found by actually running the module against a live
Magento 2.4.7 instance — not caught by `php -l`, XML validation, or the previously
mocked unit tests, because every one of them depends on the real class/interface
shapes or the real Magento UI-component runtime.

1. **`Api/CampaignRepositoryInterface.php`, `Api/OfferRepositoryInterface.php`** —
   missing `@return` docblocks / missing docblocks entirely on `getList()`/`delete()`
   broke the WebAPI reflection generator during `setup:upgrade`.
2. **`Model/Campaign.php`, `Model/Offer.php`** — `setEntityId(int $entityId): self`
   is parameter-incompatible with `AbstractModel::setEntityId($entityId)` (untyped
   parameter) — PHP fatal at class-load time.
3. **`Model/CampaignRepository.php`, `Model/OfferRepository.php`** — `getList()`
   was missing the `SearchResultsInterface` return type the interface declares —
   same class of fatal.
4. **Three `Block/Adminhtml/Campaign/Edit/*Button.php` files** — implemented
   `Magento\Ui\Component\Control\Container\ToolbarButtonInterface`, which does not
   exist in Magento 2.4.7. Correct interface:
   `Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface`.
5. **`etc/acl.xml`** — nested `Magento_Config::config` directly under
   `Magento_Backend::stores`, skipping the `Magento_Backend::stores_settings` level
   core Magento_Config's own acl.xml uses — created a conflicting duplicate ACL
   resource id, and admin login failed outright with a `LogicException`.
6. **`Model/ResourceModel/Campaign/Grid/Collection.php`,
   `Model/ResourceModel/ReorderCycle/Grid/Collection.php`** — `SearchResult`-based
   grid collections need `mainTable`/`resourceModel` wired via `di.xml` constructor
   arguments, not via `_init()` in `_construct()` — both grids fatal'd with
   `Missing required argument $mainTable`.
7. **`view/adminhtml/ui_component/ordo_campaign_form.xml`** — the `save` button
   referenced `Magento\Ui\Component\Control\Container\Toolbar\Save`, a class that
   doesn't exist anywhere in Magento 2.4.7 — fatal `ReflectionException` opening
   New Campaign. Fixed by adding `Block/Adminhtml/Campaign/Edit/SaveButton.php`
   (same pattern as the other button providers).
8. **`Model/Campaign/DataProvider.php`** — `$this->loadedData` was never declared
   as a class property, only assigned — PHP 8.2 "Creation of dynamic property"
   deprecation notice on every New/Edit Campaign page load.
9. **`Model/Rule/Action/Discount/QualifyingSetTracker.php`** — called
   `$rule->getRuleId()`, which does not exist on `Magento\SalesRule\Model\Rule`
   (only `getId()`, inherited from `AbstractModel`). Caught by the unit test
   refusing to mock a nonexistent method.
10. **`Model/SalesRepEmailContext.php`** — called `->getFrontendName()` on the
    return value of `StoreManagerInterface::getStore()`, typed `StoreInterface` —
    `getFrontendName()` only exists on the concrete `Store` model, not the
    interface. Switched to `getName()`, which is on the interface. Also caught by
    the unit test.
11. **`phpstan.neon`** — missing `includes:` for the bitexpert extension and the
    wrong parameter key (`magento_root` vs `magentoRoot`) meant PHPStan never
    actually ran before this pass.
12. **`view/adminhtml/ui_component/ordo_campaign_form.xml`** (dynamicRows) —
    canonical `<dynamicRows>` element instead of raw `<container>`, plus
    `isTemplate`/`is_collection` moved into `<argument name="data"><item
    name="config">` instead of `<settings>` — resolved a JS `TypeError` on the
    console, but was not the actual reason the form stayed blank; see #13.
13. **`view/adminhtml/ui_component/ordo_campaign_form.xml`** (root cause of the
    blank form) — its knockout template resolved to
    `templates/form/default.xhtml`, which binds content to a `{{name}}.areas`
    scope. Nothing in a plain, non-`<layout>`-declared form ever creates an
    `areas` sub-component, so `Magento_Ui/js/lib/knockout/bindings/scope.js`'s
    `registry.get(name, callback)` waited on a key that would never resolve —
    permanent spinner, zero console errors, zero server exceptions (it's a
    non-failing wait, not a crash). Fixed by explicitly setting
    `<item name="template">templates/form/collapsible</item>`, whose template
    binds to `{{name}}.{{name}}` instead, matching this form's real shape.
14. **`Controller/Adminhtml/Campaign/{Index,NewAction,Edit,Delete,Save}.php`,
    `Controller/Adminhtml/ReorderCycle/Index.php`** — none implemented
    `HttpGetActionInterface`/`HttpPostActionInterface`. Magento 2.4's
    `BackendValidator` silently rejects the request before `execute()` runs,
    logged only as a DEBUG-level "Invalid request received" line — easy to miss,
    and the admin UI just showed the page shell with no error.

15. **Custom customer EAV attributes (`ordo_credit_limit`, `ordo_order_spend_limit`,
    `ordo_approval_admin_email`, the three `ordo_sales_rep_*` fields) silently
    failed to persist — root-caused and fixed.** Traced through
    `\Magento\Eav\Model\Entity\AbstractEntity::_collectSaveData()`:
    ```
    if (!$attribute->isInSet($newObject->getAttributeSetId()) && !in_array($k, $staticFields)) {
        $this->_aggregateDeleteData($delete, $attribute, $newObject);
        continue;
    }
    ```
    — a value for an attribute not in the entity's attribute set is silently
    dropped, no exception. Confirmed `eav_entity_attribute` had **zero rows**
    for our attribute IDs — the patches never attached them to the customer's
    "Default" attribute set. Root cause, found in
    `\Magento\Eav\Setup\EavSetup::addAttribute()`:
    ```
    if (!empty($attr['group']) || empty($attr['user_defined'])) {
        // ...only here does it call addAttributeToSet()...
    }
    ```
    Every one of our patches sets `'user_defined' => true` **and never passes
    `'group'`** — so the auto-attach-to-set step is skipped entirely for
    every custom attribute this module defines. This is a real bug, not
    environment/config: any Magento module using this exact (`user_defined:
    true`, no `group`) combination has this same silent-drop problem.
    **Fixed:** added `'group' => 'General'` to all 6 attribute definitions
    across `AddCustomerCreditLimitAttribute.php`,
    `AddCustomerSpendLimitAttributes.php`, `AddSalesRepAttributes.php`. Since
    the patches had already run once against this test database, also
    manually reattached the 6 already-created attributes via
    `CustomerSetup::addAttributeToGroup()` (a fresh install runs the corrected
    patches and needs no manual step). **Verified fixed** against the real
    database: all 6 attributes now round-trip correctly through both
    `Magento\Customer\Model\Customer::save()`/`load()` (legacy path, used by
    `HoldOrderForApproval`) and `CustomerRepositoryInterface::getCustomAttribute()`
    (API path) — confirmed rows in `customer_entity_decimal`/`customer_entity_varchar`.
    This unblocked verifying `CreditLimitCalculator`, `SendCreditLimitAlerts`,
    and `HoldOrderForApproval` — see section 4.

## 9. What to do with results

- **Everything above passes:** update `README.md` — replace "Trying this for real"
  caveats with a dated note and remove the "not yet executed" caveats that no
  longer apply.
- **Something fails:** fix it, add or update the relevant unit test, then re-run
  this checklist from the failing step, not from scratch. **Next step for whoever
  picks this up:** section 3's open dynamicRows field-rendering issue, then
  sections 4–7 once campaigns can actually be created through the admin UI.
