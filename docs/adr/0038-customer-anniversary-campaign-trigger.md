# ADR 0038: A "customer birthday / registration anniversary" campaign trigger

## Status

Accepted.

## Context

`ROADMAP.md` flagged a real gap found during a 2026-11-08 feature-gap brainstorm: no trigger in
this module reads `customer_dob` (a standard but optional Magento customer attribute) at all, so
a store can't build a birthday campaign. The closest existing shape,
`Cron\DispatchCustomerInactiveCampaigns` (ADR 0030), scans for a *rolling* threshold ("N days
since X") - a birthday or account-signup anniversary is a *fixed calendar date* that recurs
exactly once a year, a different enough shape that reusing that cron's cooldown-days claim
pattern unchanged would be wrong: a cooldown window answers "has it been at least N days," not
"has this already fired for this exact calendar year."

## Decision

- **A new, real trigger_event** (`customer_anniversary`, `CampaignTriggerInterface::
  TRIGGER_CUSTOMER_ANNIVERSARY`) with its own dedicated Flow-canvas field (`date_source`, a select
  of `dob`/`registration`), following `customer_inactive`'s own precedent (a trigger with a
  dedicated param, registered in `Flow::getFieldsConfig()` and `CampaignSaveProcessor::
  TRIGGER_FIELDS_BY_TYPE`) rather than two separate trigger types. A store picks, per campaign,
  whether the relevant date is the customer's own birth date or their account's signup date -
  both are "once a year, matching today's month+day" in exactly the same way, so one trigger type
  with a param covers both without duplicating the whole dispatch/claim machinery twice.
- **Cron-driven, not an observer** - same reasoning as `DispatchCustomerInactiveCampaigns`'s own
  choice: there is no real Magento event fired when "today is this customer's birthday," so a
  daily scan (`Cron\DispatchCustomerAnniversaryCampaigns`) is the only way to detect it.
- **Dispatches per specific campaign_id** (`CampaignDispatcher::dispatchScheduledTrigger()`), same
  reasoning as ADR 0030 - the scan itself, one `customer_anniversary` trigger row at a time, is
  what enforces each campaign's own `date_source` choice.
- **A claim table keyed by (`customer_id`, `campaign_id`, `fired_year`)**
  (`ordo_customer_anniversary_trigger_claim`), not `customer_inactive`'s cooldown-window shape -
  a birthday/anniversary can only genuinely recur once per calendar year, so "already fired this
  year" is the whole rule, with no cooldown-days config to tune. `fired_year` is its own stored
  column (not derived via `YEAR(fired_at)` at query time) so `ReminderLogStore::claim()`'s plain
  equality match conditions still work unchanged - that shared helper has no way to express a
  function-of-column match condition.
- **February 29 is a deliberately accepted edge case.** A customer whose `dob`/`created_at` is
  February 29 only ever matches on an actual leap year - the same behavior any system that stores
  a plain calendar date without its own leap-year remapping has, and not worth a special case for
  what is, in practice, a single-digit number of real customers.
- **Its own enable toggle** (`Stores → Configuration → Ordo Automation → Customer Birthday /
  Anniversary Trigger`), gating only whether the scan runs at all - matching every other
  cron-driven trigger feature in this module, per `CLAUDE.md`'s "everything is config-gated per
  feature" convention. No cooldown setting exists, unlike `customer_inactive` - there is nothing
  to tune once the re-fire rule is simply "once per year."
- **`ordo_customer_anniversary_trigger_claim` added to `Model\Gdpr\CustomerDataTableProvider`
  immediately**, in the same commit that creates the table - that provider's own docblock
  documents three separate past incidents where a new `customer_id`-keyed table was added without
  updating it; this one is added proactively rather than becoming a fourth.

## Consequences

- A store can build birthday and/or signup-anniversary campaigns (a discount code, a personal
  thank-you, a loyalty nudge) composed the same way any other trigger-based campaign is - full
  condition/action chains, not a fixed, non-composable email.
- `customer_dob` is an optional Magento attribute; a customer with no value on file simply never
  matches the "Birth Date" `date_source` option (the query's own `IS NOT NULL` guard) - no error,
  no special-cased messaging, same as any other optional field this module reads.
- One additional full `customer_entity` scan per distinct `customer_anniversary` trigger row per
  day - acceptable at this cadence and table size, matching `DispatchCustomerInactiveCampaigns`'s
  own existing cost profile (ADR 0030).
