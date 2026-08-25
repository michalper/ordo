# Changelog

All notable changes to this module are documented here. Format loosely follows [Keep a Changelog](https://keepachangelog.com/).

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
