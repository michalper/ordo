# Changelog

All notable changes to this module are documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## [0.9.2]

Phase 3 (Promotion Builder) verified for real — two real bugs found and
fixed along the way, neither catchable by the existing mocked unit test.

### Fixed
- **`etc/di.xml` wired `CheapestItemFree` against the wrong extension point.**
  `Magento\SalesRule\Model\Validator`'s `calculators` argument doesn't exist
  in Magento 2.4.x — the real one is `CalculatorFactory`'s `discountRules`
  argument. Found by actually running a rule with this `simple_action`
  against a real quote (`ordo_cheapest_item_free is unknown type`).
- **`QualifyingSetTracker` gave every item in the cart 100% off, not just the
  cheapest one.** `Quote\Address\Item::getItemId()` — the object real discount
  collection actually calls `calculate()` with — is reliably `null`; casting
  that to `(int)` silently produced `0` for every item, making them all match
  each other. The `quote_item_id` fallback didn't help either (also null at
  this point in the request). Switched item identity from item id to SKU.
  Verified against a real 3-item/3-price quote: only the cheapest item
  discounted, grand total correct; re-verified with items reordered and
  qty=2 each — still only one unit of the cheapest item discounted.
- Updated `QualifyingSetTrackerTest` to mock `getSku()` instead of
  `getItemId()` — the old mock would have hidden this exact bug, since it
  never modeled the real `Quote\Address\Item` id quirk.

## [0.9.1]

### Fixed
- **Real bug, root-caused: every custom customer attribute this module defines
  (`ordo_credit_limit`, `ordo_order_spend_limit`, `ordo_approval_admin_email`,
  the three `ordo_sales_rep_*` fields) silently failed to persist.**
  `Magento\Eav\Setup\EavSetup::addAttribute()` only auto-attaches a new
  attribute to the entity's attribute set when you pass a `'group'` key (or
  when `user_defined` is falsy) — every one of this module's setup patches set
  `'user_defined' => true` without ever passing `'group'`, so the attribute was
  created but never attached to any attribute set. `AbstractEntity::_collectSaveData()`
  silently drops any value for an attribute not in the entity's set — no
  exception, `save()` just no-ops on that field. Fixed by adding
  `'group' => 'General'` to all 6 attribute definitions across
  `AddCustomerCreditLimitAttribute.php`, `AddCustomerSpendLimitAttributes.php`,
  `AddSalesRepAttributes.php`. Verified against the real database: all 6 now
  round-trip through both the legacy `Customer::save()`/`load()` path and
  `CustomerRepositoryInterface::getCustomAttribute()`.

### Verified against real data (see `VERIFICATION.md`)
- **Credit limit alert:** real customer at 80% utilization (computed from a
  real `sales_order.total_due` row) — `SendCreditLimitAlerts` correctly found
  it and attempted to send; delivery only failed on this sandbox's missing
  SMTP, handled gracefully.
- **Order approval:** `HoldOrderForApproval` correctly created a real
  `ordo_order_approval` row (token + admin email) for an over-limit order.
- Two attempts at placing a real order through full checkout (both
  programmatic via `QuoteManagement` and through the real storefront UI) hit
  pure Magento-core checkout-stack issues unrelated to this module — documented
  in `VERIFICATION.md` with a recommendation for next time (a fuller devbox,
  or keep testing trigger logic directly against hand-built objects).

## [0.9.0]

Per-type condition/action fields, done and verified end-to-end this time —
two prior attempts were reverted (see 0.8.4/0.8.5 entries) because either
the backend wiring was missing or the front-end switcher silently did
nothing. Root-caused both.

### Added
- Dedicated fields per condition/action type (`tag`, `amount`, `rule_id`,
  `prefix`, `template`, `message`) in `ordo_campaign_form.xml`, shown/hidden
  via `<switcherConfig>` on the row's `type` select. `params_json` remains
  as the fallback for a type without a dedicated field yet.
- `Save.php::normalizeRowParams()` merges whichever dedicated fields are
  filled in into the row's `params` before saving (dedicated fields win
  over a stale/pasted JSON blob on key conflicts).
- `DataProvider::loadChildRows()` spreads saved `params` back into the
  row's dedicated fields on edit, not just `params_json`.

### Fixed
- **Root cause of the previous session's failed switcherConfig attempt:**
  `<switcherConfig>` was placed on the *target* fields (`tag`, `amount`)
  instead of the *controlling* `type` select — a switcher only reacts to
  its own component's value, so it needs to live on the field whose change
  should drive the others' visibility, not on the fields being toggled.
- **`ordo_campaign_form.xml` `<dataSource>` was missing `<submitUrl
  path="ordo/campaign/save"/>`.** Without it, the Save button posted to the
  current page's own URL — visibly broken in this session (`.../new/key/
  ...undefined`), logged only as a DEBUG-level "cannot be accessed with
  POST method" line. Existed before this session; never triggered because
  the New/Edit Campaign page could never even render until 0.8.4.
- **`Save.php` read `$data['conditions']`/`$data['actions']` directly, but
  the dynamicRows' actual posted structure is double-nested —
  `conditions[conditions][0][...]`, not `conditions[0][...]`** (the
  `dynamicRows` component's own `name` matches its `dataScope`). This
  meant every condition/action row silently failed to save — confirmed via
  the raw POST body and an empty `ordo_campaign_condition` table after a
  "successful" save. Fixed by reading `$data['conditions']['conditions']`
  / `$data['actions']['actions']`. **This bug predates this session** —
  conditions/actions have likely never actually persisted through the
  admin form before now.
- Verified end-to-end against the real database: creating a campaign with
  a `tag` condition through its dedicated field produces the row
  `type=tag, params={"tag":"..."}` in `ordo_campaign_condition`, confirming
  the whole chain (switcher → POST → merge → save) actually works, not
  just "looks right in the browser."

## [0.8.5]

### Added
- Custom admin dashboard (`ordo/dashboard/index`) — own controller/block/template/CSS,
  not a UI Component. Shows campaign stats, nav cards to Campaigns/Reorder
  Cycles/Configuration, and the campaign list. Server-rendered from the same
  collections the grids already use.

### Changed
- `Ordo Automation` admin menu is now a single flat entry pointing straight at
  the dashboard, instead of a dropdown with separate Campaigns/Reorder
  Cycles/Configuration items — those are still real, unchanged controllers,
  just linked as cards from the dashboard now.
- An initial attempt at this used a fully standalone static dashboard
  (`dashboard/` — vanilla JS calling the REST API with its own admin-token
  login) before being replaced with the in-admin version above, which needs
  no separate auth/CORS handling. Not shipped in this release.

### Roadmap
- Drafted a full visual identity direction (logo/mark, color palette,
  typography, admin menu icon, GitHub banner) — documented in README, not
  implemented. Decision pending on scope.

## [0.8.4]

Closes the "New/Edit Campaign form fields don't render" issue left open at the
end of 0.8.3.

### Fixed
- `Controller/Adminhtml/Campaign/{Index,NewAction,Edit,Delete}.php`,
  `Controller/Adminhtml/ReorderCycle/Index.php` — none implemented
  `HttpGetActionInterface`; `Save.php` didn't implement `HttpPostActionInterface`.
  Magento 2.4's `BackendValidator` silently rejects admin controller actions
  missing the matching HTTP-method interface — requests never reached
  `execute()`, logged only as a DEBUG-level "Invalid request received" line
  easy to miss.
- `view/adminhtml/ui_component/ordo_campaign_form.xml` — the form's knockout
  template resolved to `templates/form/default.xhtml`, which binds its content
  to a `{{name}}.areas` scope. Nothing in this component tree ever creates an
  `areas` sub-component (that only happens for `<layout>`-declared area
  structures, which this form doesn't use), so the scope binding waited on a
  registry key that would never resolve — permanent spinner, no error, no
  console output, since `registry.get(name, callback)` just never calls back.
  Fixed by explicitly setting `<item name="template">templates/form/collapsible</item>`,
  whose template binds to `{{name}}.{{name}}` instead — which matches this
  form's actual (unremarkable, single-root) component tree shape.

## [0.8.3]

First real run against a live Magento Open Source 2.4.7 instance (Docker, Magento
cloned from GitHub — no Adobe Marketplace keys needed for `composer install`).
Twelve real bugs found and fixed; full detail and current status in
`VERIFICATION.md`.

### Fixed
- `Api/CampaignRepositoryInterface.php`, `Api/OfferRepositoryInterface.php` —
  missing/incomplete `@return` docblocks broke the WebAPI reflection generator.
- `Model/Campaign.php`, `Model/Offer.php` — `setEntityId(int $entityId): self` was
  parameter-incompatible with `AbstractModel::setEntityId($entityId)` — PHP fatal
  at class-load time.
- `Model/CampaignRepository.php`, `Model/OfferRepository.php` — `getList()` was
  missing the `SearchResultsInterface` return type the interface declares.
- Three `Block/Adminhtml/Campaign/Edit/*Button.php` classes implemented a
  nonexistent Magento interface (`Toolbar\ButtonInterface` typo) instead of
  `ButtonProviderInterface`.
- `etc/acl.xml` — missing `Magento_Backend::stores_settings` ancestor level created
  a conflicting duplicate ACL resource; admin login failed outright.
- `Model/ResourceModel/Campaign/Grid/Collection.php`,
  `Model/ResourceModel/ReorderCycle/Grid/Collection.php` — `SearchResult`-based
  grid collections need `mainTable`/`resourceModel` via `di.xml`, not `_init()`.
- `view/adminhtml/ui_component/ordo_campaign_form.xml` — `save` button referenced
  a nonexistent core class; added `Block/Adminhtml/Campaign/Edit/SaveButton.php`.
- `Model/Campaign/DataProvider.php` — undeclared `$loadedData` property, PHP 8.2
  dynamic-property deprecation notice on every campaign form load.
- `Model/Rule/Action/Discount/QualifyingSetTracker.php` — called
  `$rule->getRuleId()`, which doesn't exist on `Magento\SalesRule\Model\Rule`
  (only `getId()`). Found by the unit test refusing to mock a nonexistent method.
- `Model/SalesRepEmailContext.php` — called `->getFrontendName()` on a
  `StoreInterface`-typed value; that method only exists on the concrete `Store`
  model. Switched to `getName()`, which is on the interface.
- `phpstan.neon` — missing `includes:` for the bitexpert extension and wrong
  parameter key meant PHPStan never actually ran before this pass; it now runs
  and reports 183 real (mostly iterable-typing) findings, not fixed in this pass.
- `view/adminhtml/ui_component/ordo_campaign_form.xml` (dynamicRows) — switched to
  the canonical `<dynamicRows>` XSD element and moved `isTemplate`/`is_collection`
  into the correct config node; resolved a JS `TypeError` in the console, but the
  New/Edit Campaign form fields still don't visibly render — **still open**, see
  `VERIFICATION.md` section 3.

### Added
- `Test/Unit/Model/EntityModelSignatureCompatibilityTest.php`,
  `Test/Unit/Model/RepositorySignatureCompatibilityTest.php` — reflection-based
  regression guards for the `AbstractModel`/repository signature-compatibility
  bugs above (mocked unit tests can't catch these; they never load the real class).

## [0.8.2]

### Added
- `VERIFICATION.md` — a step-by-step checklist for actually installing and exercising this module on a fresh Magento Open Source instance: prerequisites, install via a local path repository, running PHPStan/PHPUnit for real, and a manual walkthrough of every feature (B2B triggers, campaign engine, Promotion Builder, on-site tracking) with concrete pass/fail criteria per step. Linked from README's "Trying this for real" section.

## [0.8.1]

### Added
- Two more unit tests: `HasTagTest`, `AddTagTest` (campaign condition/action, both run and passing locally — 14/14 across all four mockable test classes together).
- First MFTF test, `AdminCreateCampaignTest.xml` — admin creates a campaign via the Phase 4 form, confirms it saves and appears in the grid. Written and XML-validated, not run (no MFTF runtime in this dev environment).

### Changed
- README Phase 6 rewritten to state exact current test coverage (6 unit test files, 1 MFTF test, 0 API functional tests, PHPStan configured but never run, no coverage percentage) instead of a generic "still needs work" — and to spell out concretely how to actually try the module on a fresh Magento Open Source instance before trusting any unverified claim in this README.

## [0.8.0]

### Added
- On-site behavior tracking core: dependency-free `tracker.js` snippet (visitor cookie + `page_view`/`product_view`/`category_view` events), a public CSRF-exempt `POST /ordo/track/event` endpoint, `customer_login`-triggered identity stitching, and `VisitorAggregator` turning threshold-crossing raw events into ordinary `ordo_customer_tag` rows — which the campaign engine's existing `tag_added` trigger already fires on, with no new code.
- `ordo_visitor_event` table, deliberately separate from `ordo_campaign`/`ordo_customer_tag`, with a new `PruneVisitorEvents` cron enforcing a configurable retention window (default 7 days) — the concrete implementation of the scale caution flagged in the previous README version, not a deferred promise anymore.
- New `tracking` config group: enabled toggle, view threshold, retention days.

### Known limitations (documented, not hidden)
- No automatic page-type detection; `product_view`/`category_view` require an explicit `window.ordoTrack()` call from the theme.
- `tracker.js` loads sitewide independent of the enabled toggle (the endpoint just no-ops) — a wasted request, not a data leak, but not ideal; needs a config-aware Block to fix properly.
- Tag-per-event-key is an explicit cardinality tradeoff (precision vs. bounded tag count), left as an operating decision, not resolved here.

## [0.7.0]

### Added
- New "Ordo Automation" admin menu with a full campaign builder: grid (`ordo/campaign/index`) and edit form (`ordo/campaign/edit`) with `dynamicRows` sections for conditions and actions, both dropdowns generated live from `ConditionPool`/`ActionPool` so the UI can't drift out of sync with the dispatcher.
- Read-only "Reorder Cycles" admin grid (`ordo/reordercycle/index`) for inspecting what `CalculateReorderCycle` has computed, without querying the database directly.
- Standard Magento admin-grid plumbing added: `Grid\Collection` classes (`SearchResult`-based) for both campaigns and reorder cycles, registered via the `UiComponent\DataProvider\CollectionFactory` di.xml mapping; `CampaignActions` row-action column; toolbar button blocks for the campaign form (Back, Delete, Save & Continue).

### Known limitation (documented, not hidden)
- Condition/action rows in the campaign form use one `type` dropdown + a raw JSON textarea for params, not dedicated per-type fields (e.g. a tag autocomplete for `HasTag`). Deliberate MVP scope — tracked in README → Roadmap → Phase 4.

## [0.6.0]

### Added
- `cart_abandoned` campaign event, dispatched from `SendAbandonedCartReminders` for quotes tied to a registered customer (guests still only get the fixed reminder email) — closes the "migrate abandoned cart onto the campaign engine" Phase 3 item.
- Custom `SalesRule` discount calculator, `Model\Rule\Action\Discount\CheapestItemFree` (+ `QualifyingSetTracker`), giving 100% off the cheapest item in a rule's own qualifying set. Wired via the same `Magento\SalesRule\Model\Validator` calculator extension point Magento's native "Buy X Get Y" uses, as a new `simple_action` value (`ordo_cheapest_item_free`).
- Unit tests for `QualifyingSetTracker` (cheapest-item selection, non-matching items, per-request caching) — syntax/logic-checked, not yet executed against a real Magento install (no `magento/framework` available in this dev environment; see README verification note).

### Known limitations (documented, not hidden)
- `CheapestItemFree` is not selectable through the native admin "Apply" dropdown — that list is hardcoded in a core admin block. Usable today only via direct rule data or the REST API.
- `CheapestItemFree` has not been integration-tested against a real checkout. Tracked as the first MFTF scenario to write in Phase 6.
- "Free gift above a cart threshold" remains unbuilt — flagged in README as architecturally different work (adding a new line item, not discounting an existing one), not just "the same pattern as CheapestItemFree, once more."

## [0.5.0]

### Added
- Configurable campaign engine ("when X happens and Y is true, do Z"): new `ordo_campaign` / `ordo_campaign_condition` / `ordo_campaign_action` tables, `CampaignDispatcher`, and a plug-in registry (`Model\Campaign\ConditionPool` / `ActionPool`) driven entirely by `di.xml` — no hardcoded switch statement to extend. Ships with two conditions (`tag`, `order_total_gte`) and three actions (`add_tag`, `send_email`, `generate_coupon`).
- Three new trigger events wired into the dispatcher: `order_placed`, `customer_registered`, and `tag_added` (the last fired as a Magento event, `ordo_customer_tag_added`, from `CustomerTagManager` — going through the event bus instead of a direct call avoids a DI cycle with the `tag` condition, which itself depends on `CustomerTagManager`).
- `CouponGenerator` service — mints single-use `SalesRule` coupon codes, used by the `generate_coupon` campaign action. Reframes what was previously planned as two bespoke features ("coupon after checkout", "coupon for cart recovery") as ordinary two-action campaigns instead of new code per idea.
- Full service contract for campaigns (`CampaignRepositoryInterface`, `Api\Data\CampaignInterface`) with REST endpoints under `/V1/ordo/campaigns`.
- Seed unit tests for the new plug-in architecture (`ConditionPoolTest`, `OrderTotalAtLeastTest`).

### Changed
- Reframed Phase 3 (Promotion Builder) roadmap: "coupon after checkout" / "coupon for cart recovery" moved from planned to done via the campaign engine; "cheapest item in a bundle free" and "free gift above cart threshold" remain open, now documented with the exact Magento extension points required (`SalesRule` custom discount calculator via `Magento\SalesRule\Model\Validator`) and a known limitation (the native "Apply" admin dropdown needs a core block plugin to show a friendly label for a new discount type).

## [0.4.0]

### Added
- Sales-rep signature on every automated customer email (reorder, offer expiry, credit limit) via a new shared `SalesRepEmailContext` service, falling back to the store name when no rep is assigned to the customer. Closes Phase 2 of the B2B roadmap.
- Weekly sales-rep digest email, grouping customers tagged `inactive` by their assigned rep so each rep gets one summary instead of per-signal spam.
- Formal quality standards adopted for the project going forward: PHPStan at `level: max` (`phpstan.neon`), a unit test per non-trivial class (seed: `SalesRepEmailContextTest`), planned MFTF and API test coverage, and a 100% code coverage target — tracked as Phase 6.
- Localization scaffold: `i18n/en_US.csv` (source) and `i18n/pl_PL.csv`, covering every admin-facing label added so far.

### Fixed
- Two email templates (`credit_limit_warning.html`, and an earlier draft of the signature block) used an invalid `{{depend}}{{else}}` construct that doesn't exist in Magento's email directive syntax — replaced with independent `{{depend}}` blocks on distinct boolean variables.

## [0.3.0]

### Added
- Order approval workflow: optional per-customer spend limit + approval-admin email. Orders above the limit are held under a new `Pending Approval` order status (registered within the native "new" state, so inventory reservation is untouched) and the admin receives a token-based approve/reject email link — no login required.
- Escalation cron for stale pending approvals: resends the approval request (capped at 3 times) if nobody acts within a configurable number of days.

## [0.2.0]

### Added
- B2C lifecycle automation: welcome email on customer registration, nightly inactivity tagging, and a one-time win-back email that self-clears once the customer orders again.
- `CustomerTagManager` — a generic add/remove/check/list-by-tag service, the shared segmentation primitive every trigger (B2B and B2C) reads or writes.
- Repositioned the module's scope from "B2B add-on" to a full B2B + B2C marketing automation platform, aimed at replacing a general-purpose external MA subscription.

## [0.1.1]

### Added
- Proactive credit limit alerts: a customer credit-limit attribute plus a cron warning at a configurable threshold (default 80%) before the account is blocked — most systems only react once the account is already over the limit.

## [0.1.0]

### Added
- First-party B2B offer/quote entity (`ordo_offer`) with a proactive "expires in N days" reminder. Every established B2B platform checked (Adobe Commerce B2B, OroCommerce) only notifies reactively, after a status change.

## [0.0.1] — initial release

### Added
- Reorder reminders: detects a recurring purchase pattern per customer/SKU from order history and emails a reminder before the predicted next order date.
- Abandoned cart recovery: finds inactive carts above a configurable subtotal threshold and sends a recovery email, capped per cart.
- Module skeleton: `composer.json`, `registration.php`, `etc/module.xml`, store configuration under Stores → Configuration → Ordo Automation.
