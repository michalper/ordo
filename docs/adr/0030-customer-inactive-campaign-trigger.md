# ADR 0030: A composable "customer inactive for N days" campaign trigger

## Status

Accepted.

## Context

`ROADMAP.md` flagged that a real, composable win-back template cannot be built in the Flow
builder today: `scheduled_at`/`recurring_schedule` triggers have no `customer_id` in their own
context (they fire campaign-wide, on a clock, not per customer), and every other existing trigger
fires from a real Magento event (an order, a registration, a tag) rather than from "time has
simply passed since this customer last did something." The module's only existing win-back
capability (`Cron\TagInactiveCustomers` + `Cron\SendWinBackEmails`) is a single, fixed-threshold,
non-composable email - not a trigger a campaign's own condition/action chain can build on, and
every store gets exactly one inactivity window, set once in `Stores → Configuration`.

## Decision

- **A new, real trigger_event** (`customer_inactive`, `CampaignTriggerInterface::
  TRIGGER_CUSTOMER_INACTIVE`) with its own dedicated Flow-canvas field (`days`), following
  `scheduled_at`'s own precedent (a trigger with a dedicated param, registered in
  `Flow::getFieldsConfig()` and `CampaignSaveProcessor::TRIGGER_FIELDS_BY_TYPE`) rather than a
  single module-wide setting. A store can now build several independently-shaped win-back-style
  campaigns at different inactivity windows (e.g. a 30-day nudge and a separate 90-day "we miss
  you" with a bigger incentive), each with its own condition/action chain - something
  `TagInactiveCustomers`/`SendWinBackEmails`'s fixed, singular shape could never offer. That
  existing pair is left entirely alone (not reused, not deprecated) - different cadence, no
  per-campaign dimension, and real merchants may already depend on its exact current behavior.
- **Cron-driven, not an observer** - same reasoning as `ScheduledTriggerScanner`'s own choice:
  there is no real Magento event fired when "N days have passed with no order," so a periodic
  scan (`Cron\DispatchCustomerInactiveCampaigns`, nightly) is the only way to detect it, matching
  this module's own established "everything is a cron-driven scan against `*_at` claim columns"
  shape (see `CLAUDE.md`'s own architecture notes - `TagInactiveCustomers` itself already does
  exactly this).
- **Dispatches per specific campaign_id** (`CampaignDispatcher::dispatchScheduledTrigger()`), not
  the generic `dispatch($triggerEvent, $context)` that fans out to every campaign listening for
  an event name - that generic method has no way to apply a *different* `days` threshold per
  campaign before deciding whether to fire, since `days` is a trigger param, not something a
  downstream condition re-checks. The scan itself, one `customer_inactive` trigger row at a time,
  is what actually enforces each campaign's own threshold.
- **A plain append-only claim log** (`ordo_customer_inactive_trigger_claim`: `customer_id`,
  `campaign_id`, `fired_at`, no uniqueness constraint), not a one-dispatch-per-pair-forever
  claim like `ordo_review_request_log`'s unique-on-`order_id` shape - a customer can leave
  inactivity (by ordering again) and re-enter it later, and should be able to trigger the same
  campaign again after `Helper\Config::getCustomerInactiveRefireCooldownDays()` has passed,
  checked the same cooldown-window way `Cron\SendCreditLimitAlerts` already checks its own
  cooldown (`ReminderLogStore::claim()` with a `fired_at >= cutoff` match condition).
- **Its own enable toggle** (`Stores → Configuration → Ordo Automation → Customer Inactive
  Trigger`), gating only whether the scan itself runs at all - matching every other cron-driven
  trigger feature in this module (`review_request`, `offer`, `lifecycle`, ...), per `CLAUDE.md`'s
  "everything is config-gated per feature" convention. The refire cooldown is the only other
  config value; the inactivity threshold itself is NOT a global setting, it is each trigger's own
  `days` param.

## Consequences

- A store can now compose true win-back campaigns (condition/action chains, A/B splits, delayed
  follow-ups, consent-gated sends, coupon generation, ...) around customer inactivity, closing
  the exact gap `ROADMAP.md` described.
- Inactivity is re-derived directly from `sales_order` per trigger (same query shape
  `TagInactiveCustomers` already uses), not read from that cron's own "inactive" tag - the two
  features are completely independent; a store can run one, the other, or both without either
  affecting the other's results.
- A campaign with several `customer_inactive`-triggered actions at genuinely different windows
  costs one additional full customer-table scan per distinct trigger row per night - acceptable
  at the cadence (nightly) and granularity (date-only cutoffs) this already runs at, matching
  `TagInactiveCustomers`' own existing cost profile.
