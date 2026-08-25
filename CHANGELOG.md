# Changelog

All notable changes to this module are documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/).

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
