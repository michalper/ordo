# ADR 0008: Don't pursue Adobe Commerce SaaS support — no App Builder license

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
this runs under ACCS's constraints — not degraded, not partially, not at all. The only way to
support ACCS is a from-scratch out-of-process App Builder project with no shared code with this
module.

A start on that project (`michalper/ordo-saas`) got as far as creating an Adobe Developer Console
project and attaching the Commerce Eventing API, before hitting a hard wall: App Builder (the
Adobe I/O Runtime platform everything above depends on) has no self-serve trial and isn't
available to individual Adobe IDs — confirmed directly against Adobe's own App Builder FAQ
("Are trials available? Not at this time." / "Is App Builder available to individuals who are not
part of a company? No.") and against the Adobe Admin Console, where the org behind the Developer
Console project had only Acrobat Services (PDF API), no App Builder pack. Licensing it means
either an existing Adobe Commerce on Cloud / AEM Sites Cloud Service contract (App Builder packs
come bundled), or Adobe's Digital Experience Partner Program at Silver tier ($3,000/year via the
Partner Benefits Center) — not something obtainable by creating a project in the console.

## Decision

Don't build Adobe Commerce SaaS (ACCS) support right now. The exploratory `michalper/ordo-saas`
repository and its Adobe Developer Console project have been deleted — there's no App Builder
license to build against, so there was nothing left to keep. This repo (`ordo`) is unaffected: it
keeps serving Magento Open Source and Adobe Commerce on Cloud Infrastructure (PaaS) merchants
exactly as it does today.

Alternatives rejected:

- **Rewrite this module in place for ACCS.** Not possible regardless of licensing — ACCS runs no
  in-application code at all, so there is no "in place" to rewrite into.
- **Partial ACCS support (eventing-reachable features only).** Moot — building anything at all
  needs an App Builder license, which is the actual blocker, not the scope of the first feature.
- **Pursue the Adobe Technology Partner Program now to unblock this.** Rejected for the moment —
  it's a real $3,000/year commitment via a separate Adobe program, a business decision independent
  of this module's engineering backlog. Revisit this ADR (via a new one) if that changes.

## Consequences

- No `ordo-saas` repository exists. If ACCS support is picked up again later, it starts from
  scratch — this ADR's Context section records what was already learned (the out-of-process
  model: Eventing, Webhooks, Admin UI SDK, no custom tables/plugins/cron) so that work isn't
  repeated blind.
- This repo's own scope, `AGENTS.md`, and `CLAUDE.md` don't change because of this decision.
