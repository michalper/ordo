# Changelog

All notable changes to this module are documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/).

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
