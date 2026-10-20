# ADR 0008: Target Adobe Commerce as a Cloud Service via a separate repo, not an in-place rewrite

## Status

Accepted.

## Context

Adobe is steering merchants toward Adobe Commerce as a Cloud Service (ACCS) — a SaaS edition,
distinct from today's Adobe Commerce on Cloud Infrastructure (PaaS) that this module already
targets. ACCS gives merchants no access to the application codebase at all: no custom PHP
modules, no `di.xml` plugins or observers, no custom tables via `db_schema.xml`, no cron inside
the core application, no direct database access. The only supported extensibility path is
out-of-process: an Adobe App Builder app (Adobe I/O Runtime, serverless) reacting to Commerce
Eventing/webhooks, reading/writing Commerce data through its REST/GraphQL APIs, with the Admin UI
SDK (a separate React app rendered in an iframe) for any admin-facing screens, and App
Builder/Adobe I/O scheduled triggers standing in for cron.

`Ordo_Automation`'s architecture is in-process PHP end to end: `ConditionPool`/`ActionPool`
registered via `di.xml`, ~20 cron jobs scanning this module's own `ordo_*` tables with
claim-before-dispatch, `Plugin/Quote/BlockOverLimitCheckout` and other plugins/observers hooking
core Magento flow, a custom SalesRule calculator, and direct ORM/DB access throughout. None of
this runs under ACCS's constraints — not degraded, not partially, not at all.

## Decision

Don't attempt to port or incrementally adapt this module for ACCS. Instead, Ordo for Adobe
Commerce SaaS is a new, separate public repository — `michalper/ordo-saas` — built from scratch
as an out-of-process Adobe App Builder application: its own datastore outside Commerce, triggers
driven by Commerce Eventing, actions executed via Commerce's REST/GraphQL APIs, and an Admin UI
SDK front end for the merchant-facing configuration screens.

The two projects share product concepts — trigger → condition → action, segmentation, reorder
reminders, abandoned cart, lead scoring, etc. — but share no code, no dependency graph, no test
suite, no release cycle, and no versioning scheme. This repo (`ordo`) is unaffected: it keeps
serving Magento Open Source and Adobe Commerce on Cloud Infrastructure (PaaS) merchants exactly as
it does today.

Alternatives rejected:

- **Rewrite this module in place for ACCS.** Not possible — ACCS runs no in-application code at
  all, so there is no "in place" to rewrite into.
- **Partial ACCS support (eventing-reachable features only, e.g. email/SMS campaigns off order
  and customer events).** Rejected for now: still requires a parallel out-of-process codebase with
  its own data layer and eventing wiring, so most of the rewrite cost is paid regardless of how
  much of the feature set is ported; narrowing scope first would only mean redoing the
  App Builder/eventing/Admin-UI-SDK groundwork twice.
- **Don't support ACCS.** Rejected on product strategy grounds: Magento Open Source/PaaS adoption
  is shrinking relative to SaaS commerce platforms, and a merchant evaluating Ordo today
  increasingly expects a SaaS-compatible option.

## Consequences

- A feature built in `ordo` does not automatically exist in `ordo-saas`, and vice versa — each
  feature decision needs an explicit call on which product(s) it targets, with real risk of the
  same-named feature behaving differently between the two over time.
- `ordo-saas` needs its own test environment, CI, docs (`AGENTS.md`/`CLAUDE.md` equivalents), and
  Adobe Developer Console project/org setup — none of that is inherited from this repo's
  `magento-ordo-test/` Docker sandbox or tooling.
- This repo's own scope, `AGENTS.md`, and `CLAUDE.md` don't change because of this decision.
